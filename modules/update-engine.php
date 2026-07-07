<?php
/**
 * Module: Fleet Update Engine (Fleet v2)
 *
 * The on-site half of the pull-based fleet maintenance architecture. Pulls a
 * SIGNED manifest from the control-plane (egress — never an inbound endpoint),
 * verifies its Ed25519 signature, applies the authorized plugin updates with
 * file-level stash/rollback, self-verifies on-disk reality, and reports
 * structured JSON back.
 *
 * Full contract: fleet-toolkit/docs/fleet-v2-architecture.md
 * Decision:      03_AGENCY/Fleet/_decisions.md § Fleet v2 — 2026-06-24
 *
 * ── SAFETY: ships INERT ──────────────────────────────────────────────────
 * Until a site is fully ENROLLED — control URL + public key + per-site token all
 * set (ZS_FLEET_UE_CONTROL_URL / ZS_FLEET_UE_PUBKEY / ZS_FLEET_UE_SITE_TOKEN) —
 * the cron path is a no-op: the engine pulls nothing and applies nothing. This
 * module can therefore ride a normal release to the whole fleet and stay dormant
 * until enrolled (step 3 of the migration). Until then it is reachable only via
 * the local entrypoint (zs_fleet_ue_run_local) / the operator wrapper.
 *
 * ── Why this collapses paths A/B/C ───────────────────────────────────────
 * The engine runs inside WordPress with plugins loaded, in a legit channel
 * (cron/CLI → exempt from the zs-no-admin-mods cap filter). It is Path B's
 * in-process recipe, natively — and because plugins are loaded, premium update
 * servers register on-site (the Path C "invisible to child transient" case).
 *
 * ── Engine state machine (apply mode), per update ────────────────────────
 *   guard(from == on-disk?)  no → drift, skip
 *   pre-snapshot (version, active, http)
 *   STASH (copy live dir → wp-content/upgrade/zs-fleet-stash/) — stash-first:
 *          if the copy fails (disk full), abort BEFORE touching live.
 *   APPLY  Plugin_Upgrader::upgrade(); capture was_active; reactivate.
 *   verify version==to AND active preserved AND http 200 AND fingerprint ok
 *          ok   → applied (retain stash, prune old)
 *          fail → RESTORE stash → rolled_back   (restore-fail → error, scream)
 *
 * Per-site opt-out: add_filter('zs_fleet_update_engine_enabled', '__return_false');
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ── Config (all overridable via wp-config.php constants) ─────────────────── */

if ( ! defined( 'ZS_FLEET_UE_PUBKEY' ) ) {
	// base64 raw Ed25519 public key of the fleet control-plane. Fleet-wide; the
	// matching private key lives only in the Worker (fleet-control). A wrong/empty
	// key just makes every manifest fail verification — fail-safe.
	define( 'ZS_FLEET_UE_PUBKEY', '06rE21riRo7ZqmAQd7dTrg+9zrXPw652OFK1yTEVbUE=' );
}
if ( ! defined( 'ZS_FLEET_UE_CONTROL_URL' ) ) {
	// Control-plane base URL (no trailing slash). Fleet-wide endpoint.
	define( 'ZS_FLEET_UE_CONTROL_URL', 'https://fleet-control.marco-rubiol.workers.dev' );
}
if ( ! defined( 'ZS_FLEET_UE_SITE_TOKEN' ) ) {
	// Per-site check-in token, issued by the control-plane at enrollment. Empty → inert.
	define( 'ZS_FLEET_UE_SITE_TOKEN', '' );
}
if ( ! defined( 'ZS_FLEET_UE_CLOCK_SKEW' ) ) {
	define( 'ZS_FLEET_UE_CLOCK_SKEW', 120 ); // seconds of tolerance on expiry.
}
if ( ! defined( 'ZS_FLEET_UE_HEALTH_URL' ) ) {
	// URL the post-apply loopback probe hits. '' → home_url('/'). Override per-site
	// to hit the ORIGIN directly (e.g. 'https://origin.example/') so a Cloudflare
	// challenge / auth wall / maintenance page on the edge can't make a healthy
	// apply look broken. Must still return a fully-rendered page (fingerprint gate).
	define( 'ZS_FLEET_UE_HEALTH_URL', '' );
}
if ( ! defined( 'ZS_FLEET_UE_HEALTH_EXPECT' ) ) {
	// HTTP status the probe treats as healthy. Default 200; override only if the
	// health URL legitimately answers with a different success code.
	define( 'ZS_FLEET_UE_HEALTH_EXPECT', 200 );
}

/* ── Onboard mode (FlowGuard web-onboarding, Fleet v2) ───────────────────────
 * All FAIL-CLOSED if unset and wp-config-overridable, matching the constants
 * above. The `onboard` manifest mode is a FIXED-VERB ritual: the item carries an
 * `action` from a compiled-in allowlist + a pinned artifact hash — never a slug
 * or a URL — so a compromised control-plane can only ever trigger the ONE ritual
 * the engine already knows, not arbitrary code. Full spec:
 *   03_AGENCY/Fleet/_notes/spec-fleet-flowguard-web-onboard.md
 */
if ( ! defined( 'ZS_FLEET_UE_ONBOARD_ACTIONS' ) ) {
	// Compiled-in allowlist of onboard verbs. The manifest's action MUST be one of
	// these; anything else fails shape validation. Fixed, not manifest-driven.
	define( 'ZS_FLEET_UE_ONBOARD_ACTIONS', array( 'flowguard' ) );
}
if ( ! defined( 'ZS_FLEET_UE_ALLOW_ONBOARD' ) ) {
	// Per-site opt-in (wp-config constant — chosen so a control-plane compromise
	// cannot flip it). Default false → an onboard manifest resolves to 'skipped'.
	define( 'ZS_FLEET_UE_ALLOW_ONBOARD', false );
}
if ( ! defined( 'ZS_FLEET_UE_SEAL_PUBKEY' ) ) {
	// base64 X25519 (crypto_box) PUBLIC key of the OPERATOR — the only party that
	// can unseal a minted app-password. The Worker never holds the private half
	// (blind messenger). Empty → the engine cannot seal → cannot mint → onboard
	// resolves to 'error' ('no seal pubkey'). Set per-site at enrollment.
	define( 'ZS_FLEET_UE_SEAL_PUBKEY', '6NjAm65VSs/MsElywzIxY1OJCVW131Tdv28wmgdULyg=' );
}
if ( ! defined( 'ZS_FLEET_UE_ARTIFACT_MAX' ) ) {
	// Hard cap on the fetched FlowGuard artifact (bytes). 20 MiB. A larger download
	// is rejected before install (disk + hostile-blob defense; hash is the integrity
	// gate, this is the size gate).
	define( 'ZS_FLEET_UE_ARTIFACT_MAX', 20971520 );
}

const ZS_FLEET_UE_HOOK          = 'zs_fleet_ue_pull';
const ZS_FLEET_UE_LOCK          = 'zs_fleet_ue_lock';
const ZS_FLEET_UE_OPT_NONCES    = 'zs_fleet_ue_consumed_nonces'; // replay guard
const ZS_FLEET_UE_OPT_REPORT    = 'zs_fleet_ue_last_report';
const ZS_FLEET_UE_OPT_UNACKED   = 'zs_fleet_ue_unacked_report';  // last report not yet 2xx-acked by the control-plane
const ZS_FLEET_UE_OPT_ONBOARD_UNACKED = 'zs_fleet_ue_onboard_unacked'; // sealed onboard secret not yet 2xx-acked (ciphertext ONLY, never plaintext)
const ZS_FLEET_UE_OPT_INTERVAL  = 'zs_fleet_ue_interval';        // control-plane-driven check-in cadence (seconds)
const ZS_FLEET_UE_SCHEDULE      = 'zs_fleet_ue';                 // custom cron schedule name (interval = the option above)
const ZS_FLEET_UE_INTERVAL_MIN  = 300;                           // 5 min floor
const ZS_FLEET_UE_INTERVAL_MAX  = 86400;                         // 24 h ceiling
const ZS_FLEET_UE_STASH_SUBDIR  = 'zs-fleet-stash';             // under wp-content. NOT under upgrade/ — WP_Upgrader::unpack_package() wipes EVERY child of wp-content/upgrade/ on each run, which would delete our rollback copy mid-flight.
const ZS_FLEET_UE_STASH_KEEP    = 3;                              // retain N stashes/slug

add_action( 'init', 'zs_fleet_ue_schedule' );
add_action( ZS_FLEET_UE_HOOK, 'zs_fleet_ue_cron_run' );
add_filter( 'cron_schedules', 'zs_fleet_ue_cron_schedules' );

/* ──────────────────────────────────────────────────────────────────────────
 * PURE LOGIC — no WordPress calls, unit-testable in isolation.
 * ────────────────────────────────────────────────────────────────────────── */

/**
 * Canonical JSON: recursively sorted object keys, compact separators. This is
 * the exact byte sequence the control-plane signs and the engine verifies.
 */
function zs_fleet_ue_canonical_json( $data ) {
	if ( is_array( $data ) ) {
		if ( count( $data ) === 0 ) {
			// PHP cannot tell [] from {}; both sides canonicalize empty as a
			// list, matching json_encode( array() ) === '[]'.
			return '[]';
		}
		$is_list = array_keys( $data ) === range( 0, count( $data ) - 1 );
		if ( $is_list ) {
			$parts = array_map( 'zs_fleet_ue_canonical_json', $data );
			return '[' . implode( ',', $parts ) . ']';
		}
		ksort( $data );
		$parts = array();
		foreach ( $data as $k => $v ) {
			$parts[] = json_encode( (string) $k, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ':' . zs_fleet_ue_canonical_json( $v );
		}
		return '{' . implode( ',', $parts ) . '}';
	}
	if ( is_bool( $data ) || is_int( $data ) || is_float( $data ) || is_null( $data ) ) {
		return json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
	// UNESCAPED slashes + unicode so the canonical bytes match a JS
	// JSON.stringify-based serializer (the control-plane Worker, which signs).
	return json_encode( (string) $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

/**
 * Structural validation of a decoded manifest. Returns '' if valid, else a
 * human-readable reason. Does NOT check signature/expiry/site (separate gates).
 */
function zs_fleet_ue_validate_shape( $m ) {
	if ( ! is_array( $m ) ) {
		return 'manifest not an object';
	}
	foreach ( array( 'manifest_version', 'site', 'expires_at', 'nonce', 'mode', 'updates' ) as $k ) {
		if ( ! array_key_exists( $k, $m ) ) {
			return "missing field: {$k}";
		}
	}
	if ( (int) $m['manifest_version'] !== 1 ) {
		return 'unsupported manifest_version';
	}
	if ( ! in_array( $m['mode'], array( 'shadow', 'apply', 'rollback', 'onboard' ), true ) ) {
		return 'invalid mode';
	}
	if ( ! is_array( $m['updates'] ) ) {
		return 'updates not a list';
	}

	// ── ONBOARD: a FIXED-VERB ritual, validated in isolation and RETURNED here so it
	// never falls through to the plugin|theme loop below. Item = { action ∈ compiled
	// allowlist, artifact_version, artifact_sha256 } — NO slug, NO type, NO URL. The
	// signed artifact_sha256 is what the engine pins the download to; the action is
	// the only lever, and it is bounded to what the engine already knows how to do.
	if ( $m['mode'] === 'onboard' ) {
		if ( count( $m['updates'] ) === 0 ) {
			return 'onboard: empty updates';
		}
		$allow = defined( 'ZS_FLEET_UE_ONBOARD_ACTIONS' ) ? ZS_FLEET_UE_ONBOARD_ACTIONS : array();
		if ( ! is_array( $allow ) ) {
			$allow = array();
		}
		foreach ( $m['updates'] as $i => $u ) {
			foreach ( array( 'action', 'artifact_version', 'artifact_sha256' ) as $k ) {
				if ( ! isset( $u[ $k ] ) || ! is_string( $u[ $k ] ) || $u[ $k ] === '' ) {
					return "onboard update[{$i}] missing/invalid: {$k}";
				}
			}
			if ( ! in_array( $u['action'], $allow, true ) ) {
				return "onboard update[{$i}] action not in allowlist: {$u['action']}";
			}
			if ( preg_match( '/^[a-f0-9]{64}$/', $u['artifact_sha256'] ) !== 1 ) {
				return "onboard update[{$i}] artifact_sha256 not 64-hex";
			}
		}
		return '';
	}

	// A rollback item is minimal — { type, slug } only; the engine restores the
	// most recent stash and ignores from/to. Apply/shadow still require from/to.
	$required = ( $m['mode'] === 'rollback' )
		? array( 'type', 'slug' )
		: array( 'type', 'slug', 'from', 'to' );
	foreach ( $m['updates'] as $i => $u ) {
		foreach ( $required as $k ) {
			if ( ! isset( $u[ $k ] ) || ! is_string( $u[ $k ] ) || $u[ $k ] === '' ) {
				return "update[{$i}] missing/invalid: {$k}";
			}
		}
		// slug must be a bare folder name — no traversal, no separators, never a
		// pure-dot string ('.', '..') that would resolve to the plugins root.
		if ( ! zs_fleet_ue_safe_slug( $u['slug'] ) ) {
			return "update[{$i}] unsafe slug: {$u['slug']}";
		}
		if ( ! in_array( $u['type'], array( 'plugin', 'theme' ), true ) ) {
			// plugins + themes supported. core comes later, gated.
			return "update[{$i}] unsupported type: {$u['type']}";
		}
	}
	return '';
}

/** Expiry check (pure). $expires_at ISO-8601, $now unix seconds. */
function zs_fleet_ue_not_expired( $expires_at, $now ) {
	$exp = strtotime( (string) $expires_at );
	if ( $exp === false ) {
		return false;
	}
	return $now <= ( $exp + ZS_FLEET_UE_CLOCK_SKEW );
}

/**
 * Classify the outcome of an apply attempt (pure). The engine reports on-disk
 * reality; this maps the observed facts to an outcome string.
 *
 * @return string applied|verify_fail
 */
function zs_fleet_ue_classify( $to, $ver_after, $active_before, $active_after, $http_after, $fingerprint_ok ) {
	$version_ok = ( (string) $ver_after === (string) $to );
	$active_ok  = ( ! $active_before ) || ( $active_before && $active_after );
	$http_ok    = ( (int) $http_after === (int) ( defined( 'ZS_FLEET_UE_HEALTH_EXPECT' ) ? ZS_FLEET_UE_HEALTH_EXPECT : 200 ) );
	if ( $version_ok && $active_ok && $http_ok && $fingerprint_ok ) {
		return 'applied';
	}
	return 'verify_fail';
}

/**
 * Schema signature (pure): a deterministic sha1 over the STRUCTURAL projection of
 * the database — table list, columns, indexes — that zs_fleet_ue_schema_signature()
 * pulls from information_schema. Used strictly as a SAME-SERVER before/after delta
 * around a single apply (Vía C — empirical DB-touch detection): if the signature
 * flips, the update changed schema and a file-only rollback is unsafe (the DB ends
 * up ahead of the restored files).
 *
 * Reuses zs_fleet_ue_canonical_json (NOT wp_json_encode) so it loads under the pure
 * test stub set and so table/row ordering can never cause a spurious diff. Immunity
 * to data churn is BY CONSTRUCTION, not by normalisation: the wrapper never selects
 * AUTO_INCREMENT (the counter), TABLE_ROWS, DATA_LENGTH or STATISTICS.CARDINALITY —
 * the only fields that move on ordinary INSERT/ANALYZE traffic — so there is nothing
 * volatile in the projection to strip. The structural EXTRA='auto_increment' FLAG is
 * kept (gaining/losing it IS real DDL); only the counter VALUE is excluded.
 *
 * The signature is meaningful only as a delta on ONE server (prefix/charset/MySQL
 * version make raw hashes incomparable across sites); the engine reports a per-site
 * boolean, never the hash for cross-site comparison.
 *
 * @param array $tbls table-level rows (engine/row_format/collation).
 * @param array $cols column rows (the bulk of the DDL surface).
 * @param array $idx  index rows (structural only — no cardinality).
 * @return string sha1 of the canonical projection.
 */
function zs_fleet_ue_schema_sig( $tbls, $cols, $idx ) {
	return sha1( zs_fleet_ue_canonical_json( array( $tbls, $cols, $idx ) ) );
}

/* ──────────────────────────────────────────────────────────────────────────
 * SIGNATURE
 * ────────────────────────────────────────────────────────────────────────── */

/**
 * Verify the Ed25519 signature envelope { payload: <b64 canonical>, signature: <b64> }.
 * Returns the decoded manifest array on success, or WP_Error.
 */
function zs_fleet_ue_verify_envelope( $envelope, $pubkey_b64 ) {
	if ( $pubkey_b64 === '' ) {
		return new WP_Error( 'no_pubkey', 'no public key configured' );
	}
	if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
		return new WP_Error( 'no_sodium', 'libsodium not available' );
	}
	if ( ! is_array( $envelope ) || ! isset( $envelope['payload'], $envelope['signature'] ) ) {
		return new WP_Error( 'bad_envelope', 'envelope missing payload/signature' );
	}
	$payload = base64_decode( $envelope['payload'], true );
	$sig     = base64_decode( $envelope['signature'], true );
	$pubkey  = base64_decode( $pubkey_b64, true );
	if ( $payload === false || $sig === false || $pubkey === false ) {
		return new WP_Error( 'bad_b64', 'base64 decode failed' );
	}
	if ( strlen( $pubkey ) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen( $sig ) !== SODIUM_CRYPTO_SIGN_BYTES ) {
		return new WP_Error( 'bad_keylen', 'key or signature length wrong' );
	}
	$ok = sodium_crypto_sign_verify_detached( $sig, $payload, $pubkey );
	if ( ! $ok ) {
		return new WP_Error( 'bad_sig', 'signature verification failed' );
	}
	$manifest = json_decode( $payload, true );
	if ( ! is_array( $manifest ) ) {
		return new WP_Error( 'bad_payload_json', 'payload is not valid JSON' );
	}
	// Defense in depth: the signed payload must itself be canonical, so a
	// signature can't be replayed over a re-ordered/mutated equivalent.
	if ( zs_fleet_ue_canonical_json( $manifest ) !== $payload ) {
		return new WP_Error( 'not_canonical', 'signed payload is not canonical JSON' );
	}
	return $manifest;
}

/* ──────────────────────────────────────────────────────────────────────────
 * ENGINE OPS (WordPress)
 * ────────────────────────────────────────────────────────────────────────── */

function zs_fleet_ue_enabled() {
	return apply_filters( 'zs_fleet_update_engine_enabled', true );
}

/** Resolve a folder slug to its plugin_file ("slug/slug.php"). '' if absent. */
function zs_fleet_ue_resolve_plugin_file( $slug ) {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	foreach ( get_plugins() as $file => $data ) {
		// Root single-file plugins (dirname '.') are out of scope for
		// folder-based stash/restore — never let one resolve (a slug of '.'
		// would otherwise map to one and target the plugins root).
		if ( dirname( $file ) === '.' ) {
			continue;
		}
		if ( dirname( $file ) === $slug ) {
			return $file;
		}
	}
	return '';
}

/** Current on-disk version of $plugin_file (reads fresh, bypasses caches). */
function zs_fleet_ue_disk_version( $plugin_file ) {
	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$path = WP_PLUGIN_DIR . '/' . $plugin_file;
	if ( ! file_exists( $path ) ) {
		return '';
	}
	$data = get_plugin_data( $path, false, false );
	return isset( $data['Version'] ) ? (string) $data['Version'] : '';
}

/**
 * Loopback HTTP probe of the site home. Returns [code, seconds, body].
 *
 * Cache-busts so a stale page-cache (LSCache) entry can't mask a runtime break,
 * and retries up to 3× so a transient edge blip (a Cloudflare challenge, a brief
 * 5xx, a redirect chain) does not by itself look like a broken site. The verify
 * logic still treats a *persistent* non-200 as a failure — conservative for an
 * unattended updater (a needlessly-rolled-back update is safe-but-stale; a
 * site left broken is not).
 */
function zs_fleet_ue_http_self() {
	$code = 0;
	$secs = 0;
	$body = '';
	$base = ( defined( 'ZS_FLEET_UE_HEALTH_URL' ) && ZS_FLEET_UE_HEALTH_URL !== '' )
		? ZS_FLEET_UE_HEALTH_URL
		: home_url( '/' );
	$expect = (int) ( defined( 'ZS_FLEET_UE_HEALTH_EXPECT' ) ? ZS_FLEET_UE_HEALTH_EXPECT : 200 );
	for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
		$url   = add_query_arg( 'zs_fleet_probe', (string) ( time() + $attempt ), $base );
		$start = microtime( true );
		$resp  = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 5,
				'sslverify'   => false,
				'headers'     => array(
					'User-Agent'    => 'zs-fleet-engine self-check',
					'Cache-Control' => 'no-cache',
					'Pragma'        => 'no-cache',
				),
			)
		);
		$secs = round( microtime( true ) - $start, 3 );
		if ( is_wp_error( $resp ) ) {
			$code = 0;
			$body = '';
		} else {
			$code = (int) wp_remote_retrieve_response_code( $resp );
			$body = (string) wp_remote_retrieve_body( $resp );
		}
		if ( $code === $expect ) {
			break;
		}
		if ( $attempt < 3 ) {
			usleep( 400000 ); // brief backoff before retrying a non-expected code.
		}
	}
	return array( $code, $secs, $body );
}

/**
 * Distinguish "cannot verify" from "broken" for a post-apply probe result.
 *
 * A persistent challenge / auth / rate-limit response (401, 403, 429, or a
 * Cloudflare "Just a moment"/JS-challenge 503 body) means the edge, not the
 * apply, produced the non-200 — we CANNOT conclude the update broke the site.
 * Callers must escalate ('health_probe_blocked'), never auto-rollback (which
 * would loop apply→rollback forever on a good update behind a challenge) and
 * never falsely report 'applied'. 5xx / connection error (code 0) / timeout are
 * deliberately OUTSIDE this set → they stay "broken" and the rollback safety net
 * fires as before. Overriding ZS_FLEET_UE_HEALTH_URL to hit origin is the real
 * fix; this classifier is the fail-loud backstop when the edge is unavoidable.
 */
function zs_fleet_ue_health_blocked( $code, $body ) {
	$code = (int) $code;
	if ( $code === 401 || $code === 403 || $code === 429 ) {
		return true;
	}
	if ( $code === 503 && $body !== '' && (
		stripos( $body, 'Just a moment' ) !== false
		|| stripos( $body, 'cf-browser-verification' ) !== false
		|| stripos( $body, 'Checking your browser' ) !== false
		|| stripos( $body, '__cf_chl' ) !== false
	) ) {
		return true;
	}
	return false;
}

/**
 * Raise time/memory ceilings before a real apply. Plugin_Upgrader can be slow on
 * a large premium zip; the default 30s/128M can trip a fatal mid-swap (which then
 * skips try/finally — see the cron shutdown net). No-op-safe on hosts that disable
 * set_time_limit.
 */
function zs_fleet_ue_raise_limits() {
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 0 );
	}
	if ( function_exists( 'wp_raise_memory_limit' ) ) {
		wp_raise_memory_limit( 'admin' );
	}
}

/** Cheap content fingerprint (Nivel 1): no PHP fatal, has a closing </html>. */
function zs_fleet_ue_fingerprint_ok( $body ) {
	if ( $body === '' ) {
		return false;
	}
	$needles = array(
		'There has been a critical error',
		'Fatal error',
		'Parse error:',
		'Error establishing a database connection',
	);
	foreach ( $needles as $n ) {
		if ( stripos( $body, $n ) !== false ) {
			return false;
		}
	}
	// Minimal structural sanity — a rendered WP page closes the document.
	return stripos( $body, '</html>' ) !== false;
}

/**
 * Slug safety (pure): a bare folder name — never '.'/'..'/empty, always with at
 * least one alphanumeric. A pure-dot slug would resolve to the plugins ROOT and
 * let a rollback recursively wipe wp-content/plugins/. This is the primary guard.
 */
function zs_fleet_ue_safe_slug( $slug ) {
	return is_string( $slug )
		&& $slug !== ''
		&& preg_match( '/^[A-Za-z0-9._-]+$/', $slug ) === 1
		&& strpos( $slug, '..' ) === false
		&& preg_match( '/[A-Za-z0-9]/', $slug ) === 1
		&& trim( $slug, '.' ) !== '';
}

/**
 * Resolve + hard-validate a plugin's live dir path. Returns the absolute path
 * only if it is a STRICT subdirectory of WP_PLUGIN_DIR (never the root itself).
 * Defense-in-depth backstop behind zs_fleet_ue_safe_slug, so even a bad slug
 * that somehow slipped shape validation can never operate on the plugins root.
 */
function zs_fleet_ue_live_path( $slug, $base = null ) {
	if ( ! zs_fleet_ue_safe_slug( $slug ) ) {
		return new WP_Error( 'unsafe_slug', "unsafe slug: {$slug}" );
	}
	if ( $base === null ) {
		$base = WP_PLUGIN_DIR;
	}
	$root = realpath( $base );
	if ( $root === false ) {
		return new WP_Error( 'no_base_dir', 'base dir not resolvable: ' . $base );
	}
	$live  = $root . DIRECTORY_SEPARATOR . $slug;
	$rlive = realpath( $live ); // may be false if the target does not exist yet (restore).
	if ( $rlive !== false && ( $rlive === $root || strpos( $rlive, $root . DIRECTORY_SEPARATOR ) !== 0 ) ) {
		return new WP_Error( 'unsafe_live', "refusing op on non-subdir path: {$live}" );
	}
	return $live;
}

/** Absolute path to the stash base dir, created if missing. WP_Error on fail. */
function zs_fleet_ue_stash_base() {
	$base = trailingslashit( WP_CONTENT_DIR ) . ZS_FLEET_UE_STASH_SUBDIR;
	if ( ! is_dir( $base ) && ! wp_mkdir_p( $base ) ) {
		return new WP_Error( 'stash_mkdir', "cannot create stash dir {$base}" );
	}
	return $base;
}

/**
 * Stash-first: copy the live plugin dir to the stash BEFORE any apply. If the
 * copy fails (disk full, perms) we abort before touching the live dir, so the
 * site is never left half-updated without a rollback copy.
 *
 * @return string|WP_Error absolute stash path of the copied dir.
 */
function zs_fleet_ue_stash_plugin( $slug, $version, $source_base = null ) {
	$base = zs_fleet_ue_stash_base();
	if ( is_wp_error( $base ) ) {
		return $base;
	}
	$live = zs_fleet_ue_live_path( $slug, $source_base );
	if ( is_wp_error( $live ) ) {
		return $live;
	}
	if ( ! is_dir( $live ) ) {
		return new WP_Error( 'stash_no_live', "live dir missing: {$live}" );
	}
	$safe_ver = preg_replace( '/[^A-Za-z0-9._-]/', '_', (string) $version );
	$stash    = trailingslashit( $base ) . $slug . '-' . $safe_ver . '-' . time();

	require_once ABSPATH . 'wp-admin/includes/file.php';
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		return new WP_Error( 'stash_fs', 'WP_Filesystem unavailable' );
	}
	WP_Filesystem();
	if ( ! wp_mkdir_p( $stash ) ) {
		return new WP_Error( 'stash_mkdir2', "cannot create stash target {$stash}" );
	}
	$copied = copy_dir( $live, $stash );
	if ( is_wp_error( $copied ) ) {
		// Clean up a partial copy so a later restore can never read garbage.
		zs_fleet_ue_rrmdir( $stash );
		return new WP_Error( 'stash_copy', 'stash copy failed: ' . $copied->get_error_message() );
	}
	return $stash;
}

/**
 * Restore a stashed plugin dir over the (broken/new) live dir. Returns true on
 * success. A restore failure is the single unrecoverable state — caller MUST
 * escalate loudly.
 *
 * ATOMIC-ISH: copy the stash into a fresh sibling FIRST (live untouched), then
 * swap by rename only once the copy is whole. A copy that fails mid-way never
 * leaves the live dir half-written (the brick path the old delete-then-copy had).
 * The stash itself is never consumed — it survives as a record; the pruner cleans
 * it up later.
 */
function zs_fleet_ue_restore_plugin( $slug, $stash, $source_base = null ) {
	if ( ! is_dir( $stash ) ) {
		return false; // stash missing — caller treats as unrecoverable.
	}
	$live = zs_fleet_ue_live_path( $slug, $source_base );
	if ( is_wp_error( $live ) ) {
		return false;
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	global $wp_filesystem;

	// Dot-prefix the scratch dirs so WP enumeration ignores them: a theme restore
	// puts these in the themes root, and search_theme_directories() registers any
	// non-dot subdir with a style.css as a phantom theme. '.bricks.zs-new' is skipped.
	$scratch = dirname( $live ) . '/.' . basename( $live );
	$tmp     = $scratch . '.zs-new';
	if ( is_dir( $tmp ) ) {
		$wp_filesystem->delete( $tmp, true );
	}
	if ( ! wp_mkdir_p( $tmp ) ) {
		return false;
	}
	$copied = copy_dir( $stash, $tmp );
	if ( is_wp_error( $copied ) ) {
		// Live is still the broken-new version — but NOT half-written. Caller's
		// post-restore health check will catch that it's still degraded.
		zs_fleet_ue_rrmdir( $tmp );
		return false;
	}

	// Swap by rename. Move the (broken) live aside first so we can put it back
	// if the second rename fails — live is never left missing.
	$broken = $scratch . '.zs-broken-' . time();
	if ( is_dir( $live ) && ! $wp_filesystem->move( $live, $broken, true ) ) {
		zs_fleet_ue_rrmdir( $tmp );
		return false;
	}
	if ( ! $wp_filesystem->move( $tmp, $live, true ) ) {
		if ( is_dir( $broken ) ) {
			$wp_filesystem->move( $broken, $live, true ); // put the original back.
		}
		zs_fleet_ue_rrmdir( $tmp );
		return false;
	}
	if ( is_dir( $broken ) ) {
		$wp_filesystem->delete( $broken, true );
	}
	return true;
}

/**
 * Unified rollback: restore the stash, reactivate, then RE-VERIFY the site is
 * actually healthy, and only then report 'rolled_back'. If the stash is missing,
 * the restore fails, or the post-restore site is still degraded → outcome
 * 'error', a durable breadcrumb, and a loud error_log. 'rolled_back' must mean
 * "verified back to a good state", never just "files copied".
 */
function zs_fleet_ue_rollback( &$row, $slug, $pf, $stash, $from, $active_before, $reason ) {
	if ( ! is_string( $stash ) || $stash === '' || ! is_dir( $stash ) ) {
		$row['outcome']  = 'error';
		$row['message'] .= $reason . ' CRITICAL: no rollback (stash missing).';
		zs_fleet_ue_breadcrumb( $slug, 'stash_missing', $row );
		error_log( '[zs-fleet] CRITICAL no_stash for ' . $slug . ' at ' . home_url() );
		return;
	}
	if ( ! zs_fleet_ue_restore_plugin( $slug, $stash ) ) {
		$row['outcome']  = 'error';
		$row['message'] .= $reason . ' CRITICAL: restore failed — site may be degraded.';
		zs_fleet_ue_breadcrumb( $slug, 'restore_failed', $row );
		error_log( '[zs-fleet] CRITICAL restore_failed for ' . $slug . ' at ' . home_url() );
		return;
	}
	if ( $active_before && ! is_plugin_active( $pf ) ) {
		activate_plugin( $pf, '', false, true );
	}
	wp_clean_plugins_cache( false );
	$ver_after                  = zs_fleet_ue_disk_version( $pf );
	$active_after               = is_plugin_active( $pf );
	list( $code, $secs, $body ) = zs_fleet_ue_http_self();
	$fp_ok                      = zs_fleet_ue_fingerprint_ok( $body );

	$row['version_after']  = $ver_after;
	$row['active_after']   = $active_after;
	$row['http_after']     = $code;
	$row['http_time_s']    = $secs;
	$row['fingerprint_ok'] = $fp_ok;

	// $from may be '' in rollback mode (the prior version isn't pinned — the
	// stash IS the source of truth); only enforce the version match when known.
	$healthy = ( $code === (int) ZS_FLEET_UE_HEALTH_EXPECT ) && $fp_ok
		&& ( $from === '' || (string) $ver_after === (string) $from )
		&& ( ! $active_before || $active_after );

	if ( $healthy ) {
		$row['outcome']  = 'rolled_back';
		$row['message'] .= $reason . ' restored to ' . $ver_after . ' (verified healthy).';
		return;
	}
	$row['outcome']  = 'error';
	$row['message'] .= $reason . ' CRITICAL: restore ran but site still degraded (http ' . $code . ', ver ' . $ver_after . ').';
	zs_fleet_ue_breadcrumb( $slug, 'restore_left_degraded', $row );
	error_log( '[zs-fleet] CRITICAL restore_left_degraded for ' . $slug . ' at ' . home_url() );
}

/** Durable breadcrumb so a failure survives even if a later step fatals before the report is persisted. */
function zs_fleet_ue_breadcrumb( $slug, $kind, $row ) {
	update_option(
		'zs_fleet_ue_restore_pending',
		array(
			'slug'  => $slug,
			'kind'  => $kind,
			'stash' => isset( $row['stash'] ) ? $row['stash'] : '',
			'at'    => gmdate( 'c' ),
		),
		false
	);
}

/** Keep only the newest ZS_FLEET_UE_STASH_KEEP stashes per slug. */
function zs_fleet_ue_prune_stashes( $slug ) {
	$base = trailingslashit( WP_CONTENT_DIR ) . ZS_FLEET_UE_STASH_SUBDIR;
	if ( ! is_dir( $base ) ) {
		return;
	}
	$dirs = glob( $base . '/' . $slug . '-*', GLOB_ONLYDIR );
	if ( ! $dirs || count( $dirs ) <= ZS_FLEET_UE_STASH_KEEP ) {
		return;
	}
	// Sort by trailing -<timestamp> ascending; delete the oldest beyond keep.
	usort(
		$dirs,
		function ( $a, $b ) {
			return (int) substr( strrchr( $a, '-' ), 1 ) <=> (int) substr( strrchr( $b, '-' ), 1 );
		}
	);
	$drop = array_slice( $dirs, 0, count( $dirs ) - ZS_FLEET_UE_STASH_KEEP );
	foreach ( $drop as $d ) {
		zs_fleet_ue_rrmdir( $d );
	}
}

/**
 * Apply a single update through the full state machine. Pure-ish orchestration
 * over the WP ops above. Returns a result record (the report row).
 */
function zs_fleet_ue_apply_one( $update, $mode ) {
	$slug = $update['slug'];
	$from = (string) $update['from'];
	$to   = (string) $update['to'];

	$row = array(
		'type'           => $update['type'],
		'slug'           => $slug,
		'from'           => $from,
		'to'             => $to,
		'outcome'        => 'error',
		'version_before' => '',
		'version_after'  => '',
		'active_before'  => null,
		'active_after'   => null,
		'http_after'     => null,
		'http_time_s'    => null,
		'fingerprint_ok' => null,
		'schema_changed' => null, // Vía C: true = measured DB-schema delta; false = measured none; null = not measured.
		'schema_probe'   => null, // 'ok' = both reads succeeded; 'unavailable' = a read failed; null = not attempted.
		'stash'          => '',
		'message'        => '',
	);

	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	$pf = zs_fleet_ue_resolve_plugin_file( $slug );
	if ( $pf === '' ) {
		$row['outcome'] = 'skipped';
		$row['message'] = 'plugin not installed';
		return $row;
	}

	$ver_before              = zs_fleet_ue_disk_version( $pf );
	$row['version_before']   = $ver_before;
	$row['version_after']    = $ver_before;
	$active_before           = is_plugin_active( $pf );
	$row['active_before']    = $active_before;

	// Guard: on-disk current must match the manifest's expectation.
	if ( $ver_before === $to ) {
		$row['outcome'] = 'noop';
		$row['message'] = 'already at target version';
		return $row;
	}
	if ( $from !== '' && $ver_before !== $from ) {
		$row['outcome'] = 'drift';
		$row['message'] = "on-disk {$ver_before} != manifest from {$from}";
		return $row;
	}

	// gated: DB-touching updates carry no rollback promise (decision 7).
	$touches_db = ! empty( $update['touches_db'] );

	// Operator force-override (manual /v1/issue only): accept a version-STRING
	// mismatch when every HEALTH gate still passes. For premium updaters whose
	// on-disk version header lies in cron/CLI context (the wp-compress class), the
	// version check is a false-negative; force lets the operator vouch for the
	// result. It NEVER relaxes the health gates (active/HTTP/fingerprint) and NEVER
	// applies to db-touch / schema-moving updates — a file-only swap the operator
	// has confirmed is the only thing it waves through.
	$force = ! empty( $update['force'] );

	// Refresh the update transient WITH plugins loaded so premium update servers
	// register (the Path C "invisible" case). One plugin per resolve — no
	// multi-slug transient invalidation.
	delete_site_transient( 'update_plugins' );
	wp_update_plugins();
	$t = get_site_transient( 'update_plugins' );
	if ( ! isset( $t->response[ $pf ] ) ) {
		$row['outcome'] = 'skipped';
		$row['message'] = 'no pending update visible on-site (premium-detection gate?)';
		return $row;
	}

	// ── SHADOW: report the plan, apply nothing. ──
	if ( $mode === 'shadow' ) {
		$row['outcome'] = 'applied'; // i.e. "would apply" — distinguished by mode in the report envelope
		$row['message'] = 'shadow: would apply ' . $from . ' → ' . $to . ( $touches_db ? ' (db-touch, no rollback)' : '' );
		return $row;
	}

	// ── APPLY ──
	zs_fleet_ue_raise_limits();
	if ( ! $touches_db ) {
		$stash = zs_fleet_ue_stash_plugin( $slug, $ver_before );
		if ( is_wp_error( $stash ) ) {
			// Stash-first invariant: no stash → do not touch live.
			$row['outcome'] = 'error';
			$row['message'] = 'stash failed, not applied: ' . $stash->get_error_message();
			return $row;
		}
		$row['stash'] = str_replace( trailingslashit( WP_CONTENT_DIR ), '', $stash );
	} else {
		$row['message'] = 'db-touch: applied without engine rollback (host snapshot expected). ';
	}

	// Vía C — schema signature BEFORE the upgrade. Taken AFTER the stash so a
	// failed-stash early-return wastes no read and the stash-first abort invariant
	// is preserved (stash is a filesystem op that never touches the DB). One call
	// covers both the file-only (stashed) and touches_db (no-stash) paths — they
	// converge here. Best-effort: '' on a DB read failure → schema_changed stays null.
	$schema_before = zs_fleet_ue_schema_signature();

	WP_Filesystem();
	$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
	$result   = $upgrader->upgrade( $pf );

	if ( is_wp_error( $result ) ) {
		$row['message'] .= 'upgrade error: ' . $result->get_error_message() . '. ';
		if ( $touches_db ) {
			$row['outcome'] = 'error';
			return $row;
		}
		zs_fleet_ue_rollback( $row, $slug, $pf, isset( $stash ) ? $stash : '', $from, $active_before, 'upgrade error →' );
		return $row;
	}
	if ( $result === false ) {
		$row['message'] .= 'upgrade returned false. ';
		if ( $touches_db ) {
			$row['outcome'] = 'error';
			return $row;
		}
		zs_fleet_ue_rollback( $row, $slug, $pf, isset( $stash ) ? $stash : '', $from, $active_before, 'upgrade false →' );
		return $row;
	}

	// Reactivate if the upgrader silently deactivated (the v1 2026-05-13 fix).
	if ( $active_before && ! is_plugin_active( $pf ) ) {
		$act = activate_plugin( $pf, '', false, true );
		if ( is_wp_error( $act ) ) {
			$row['message'] .= 'reactivate failed: ' . $act->get_error_message() . '. ';
		}
	}

	// Re-read on-disk reality (Nivel 0) + http + fingerprint (Nivel 1).
	wp_clean_plugins_cache( false );
	$ver_after            = zs_fleet_ue_disk_version( $pf );
	$active_after         = is_plugin_active( $pf );
	list( $code, $secs, $body ) = zs_fleet_ue_http_self();
	$fp_ok                = zs_fleet_ue_fingerprint_ok( $body );

	$row['version_after']  = $ver_after;
	$row['active_after']   = $active_after;
	$row['http_after']     = $code;
	$row['http_time_s']    = $secs;
	$row['fingerprint_ok'] = $fp_ok;

	// Vía C — schema signature AFTER the loopback self-probe (above): the front-end
	// hit is what fires lazy on-load (init/template_redirect) migrations. schema_changed
	// is true ONLY on a MEASURED delta (both reads ok, before != after); a failed read
	// → schema_probe 'unavailable' → schema_changed null (abstain — never learn from
	// an unknown). This extends the read-disk-not-message principle to the DB.
	$schema_after          = zs_fleet_ue_schema_signature();
	$row['schema_probe']   = ( $schema_before === '' || $schema_after === '' ) ? 'unavailable' : 'ok';
	$row['schema_changed'] = ( $row['schema_probe'] === 'ok' ) ? ( $schema_before !== $schema_after ) : null;

	// Health probe blocked by the edge (challenge / auth / rate-limit) → we CANNOT
	// verify runtime health. Leave the applied files in place (do NOT roll back a
	// possibly-good update into an apply→rollback loop) and do NOT claim 'applied'.
	// Report 'error' + a distinct reason and escalate loudly so the operator checks.
	if ( zs_fleet_ue_health_blocked( $code, $body ) ) {
		$row['outcome']  = 'error';
		$row['reason']   = 'health_probe_blocked';
		$row['message'] .= 'CANNOT VERIFY: health probe blocked (HTTP ' . (int) $code . ' — challenge/auth/rate-limit); left applied, no rollback, operator must verify. ';
		zs_fleet_ue_breadcrumb( $slug, 'health_probe_blocked', $row );
		error_log( '[zs-fleet] CRITICAL health_probe_blocked for ' . $slug . ' at ' . home_url() . ' (HTTP ' . (int) $code . ')' );
		return $row;
	}

	$verdict = zs_fleet_ue_classify( $to, $ver_after, $active_before, $active_after, $code, $fp_ok );

	if ( $verdict === 'applied' ) {
		$row['outcome'] = 'applied';
		zs_fleet_ue_prune_stashes( $slug );
		return $row;
	}

	// ── force-override: version mismatch ONLY, health gates all green ──
	// Guarded against db-touch / measured schema move (those keep the no-trusted-
	// rollback treatment below). Recorded explicitly so the report/timeline never
	// shows a forced apply as a clean match.
	if ( $force && ! $touches_db && $row['schema_changed'] !== true ) {
		$active_ok = ( ! $active_before ) || $active_after;
		if ( ( (string) $ver_after !== (string) $to ) && $active_ok && (int) $code === (int) ZS_FLEET_UE_HEALTH_EXPECT && $fp_ok ) {
			$row['outcome']                   = 'applied';
			$row['forced']                    = true;
			$row['version_mismatch_accepted'] = true;
			$row['message']                  .= 'forced: on-disk ' . $ver_after . ' != target ' . $to . ', accepted by operator (health gates passed). ';
			zs_fleet_ue_prune_stashes( $slug );
			return $row;
		}
	}

	// ── verify failed → ROLLBACK ──
	// Conservative for unattended autonomy: a persistent non-200 (after the
	// probe's own retries) triggers rollback. A needlessly-rolled-back update
	// is safe-but-stale and the cockpit/VRT re-adjudicates it next cycle; a site
	// left broken is not recoverable without intervention. db-touch has no stash.
	//
	// Vía C — an update the manifest thought was file-only but that EMPIRICALLY moved
	// the schema (schema_changed === true) inherits the SAME no-trusted-rollback
	// treatment: a file restore would leave the DB ahead of the files = silently
	// broken. The stash (if one was taken) is left in place for a coordinated manual
	// file+DB recovery; the engine just never claims a clean 'rolled_back'.
	// schema_changed === false means no schema move was OBSERVED in the front-end
	// probe window — NOT proof of none: admin-only/deferred migrations (run from
	// wp-admin or on a later request) are invisible here, which is why db_touch_slugs
	// remains the operator backstop. false (unobserved) and === null (unmeasured →
	// abstain) both fall through to the normal file rollback below, unchanged.
	if ( $touches_db || $row['schema_changed'] === true ) {
		$row['outcome'] = 'error';
		$row['message'] .= 'verify failed and no rollback available ('
			. ( $touches_db ? 'db-touch' : 'schema_changed → DB ahead of files, host snapshot needed' ) . ').';
		return $row;
	}
	zs_fleet_ue_rollback( $row, $slug, $pf, isset( $stash ) ? $stash : '', $from, $active_before, 'verify failed →' );
	return $row;
}

/* ──────────────────────────────────────────────────────────────────────────
 * THEMES — parallel path. An active theme cannot be cleanly "deactivated" like
 * a plugin, so the file-level stash/restore (not deactivation) IS the only
 * safety net — which is why restore correctness matters even more here. Reuses
 * the generalized stash/restore (base = themes dir), http/fingerprint, prune.
 * ────────────────────────────────────────────────────────────────────────── */

/** Apply a single THEME update. Mirrors apply_one but via Theme_Upgrader + themes dir. */
function zs_fleet_ue_apply_one_theme( $update, $mode ) {
	$slug = $update['slug'];
	$from = (string) $update['from'];
	$to   = (string) $update['to'];

	$row = array(
		'type'           => 'theme',
		'slug'           => $slug,
		'from'           => $from,
		'to'             => $to,
		'outcome'        => 'error',
		'version_before' => '',
		'version_after'  => '',
		'active_before'  => null,
		'active_after'   => null,
		'http_after'     => null,
		'http_time_s'    => null,
		'fingerprint_ok' => null,
		'schema_changed' => null, // Vía C: themes have no touches_db concept — this is their ONLY DB-migration guard.
		'schema_probe'   => null,
		'stash'          => '',
		'message'        => '',
	);

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	require_once ABSPATH . 'wp-admin/includes/theme.php';

	$theme = wp_get_theme( $slug );
	if ( ! $theme->exists() ) {
		$row['outcome'] = 'skipped';
		$row['message'] = 'theme not installed';
		return $row;
	}
	$themes_dir = get_theme_root( $slug );

	$force = ! empty( $update['force'] ); // operator version-mismatch override (see apply_one).

	$ver_before            = (string) $theme->get( 'Version' );
	$row['version_before'] = $ver_before;
	$row['version_after']  = $ver_before;
	// "active" for a theme = it is the active stylesheet OR the parent template.
	$active_before         = ( get_stylesheet() === $slug || get_template() === $slug );
	$row['active_before']  = $active_before;

	if ( $ver_before === $to ) {
		$row['outcome'] = 'noop';
		$row['message'] = 'already at target version';
		return $row;
	}
	if ( $from !== '' && $ver_before !== $from ) {
		$row['outcome'] = 'drift';
		$row['message'] = "on-disk {$ver_before} != manifest from {$from}";
		return $row;
	}

	// Refresh the theme transient WITH themes loaded (no --skip-themes) so premium
	// theme updaters (Bricks/Etch) register on-site — the Path-C-for-themes case.
	delete_site_transient( 'update_themes' );
	wp_update_themes();
	$t = get_site_transient( 'update_themes' );
	if ( empty( $t->response[ $slug ] ) ) {
		$row['outcome'] = 'skipped';
		$row['message'] = 'no pending theme update visible on-site (premium-detection gate?)';
		return $row;
	}

	if ( $mode === 'shadow' ) {
		$row['outcome'] = 'applied';
		$row['message'] = 'shadow: would apply theme ' . $from . ' → ' . $to;
		return $row;
	}

	// ── APPLY (stash-first, like plugins; base = themes dir) ──
	zs_fleet_ue_raise_limits();
	$stash = zs_fleet_ue_stash_plugin( $slug, $ver_before, $themes_dir );
	if ( is_wp_error( $stash ) ) {
		$row['outcome'] = 'error';
		$row['message'] = 'stash failed, not applied: ' . $stash->get_error_message();
		return $row;
	}
	$row['stash'] = str_replace( trailingslashit( WP_CONTENT_DIR ), '', $stash );

	// Vía C — schema signature BEFORE the theme upgrade (after the stash). Themes
	// carry no touches_db concept, so this before/after delta is their ONLY guard
	// against a theme that runs a DB migration. Best-effort: '' on a read failure.
	$schema_before = zs_fleet_ue_schema_signature();

	WP_Filesystem();
	$upgrader = new Theme_Upgrader( new Automatic_Upgrader_Skin() );
	$result   = $upgrader->upgrade( $slug );

	if ( is_wp_error( $result ) || $result === false ) {
		$row['message'] .= 'theme upgrade ' . ( is_wp_error( $result ) ? 'error: ' . $result->get_error_message() : 'returned false' ) . '. ';
		zs_fleet_ue_rollback_theme( $row, $slug, $stash, $from, $themes_dir, 'theme upgrade fail →' );
		return $row;
	}

	wp_clean_themes_cache( false );
	$ver_after                  = (string) wp_get_theme( $slug )->get( 'Version' );
	list( $code, $secs, $body ) = zs_fleet_ue_http_self();
	$fp_ok                      = zs_fleet_ue_fingerprint_ok( $body );
	$row['version_after']  = $ver_after;
	$row['active_after']   = ( get_stylesheet() === $slug || get_template() === $slug );
	$row['http_after']     = $code;
	$row['http_time_s']    = $secs;
	$row['fingerprint_ok'] = $fp_ok;

	// Vía C — schema signature AFTER the loopback self-probe (above), mirroring the
	// plugin path. schema_changed true only on a measured delta; '' read → null/abstain.
	$schema_after          = zs_fleet_ue_schema_signature();
	$row['schema_probe']   = ( $schema_before === '' || $schema_after === '' ) ? 'unavailable' : 'ok';
	$row['schema_changed'] = ( $row['schema_probe'] === 'ok' ) ? ( $schema_before !== $schema_after ) : null;

	// Health probe blocked by the edge → cannot verify. Mirror the plugin path:
	// leave the applied theme in place, no rollback loop, no false 'applied', escalate.
	if ( zs_fleet_ue_health_blocked( $code, $body ) ) {
		$row['outcome']  = 'error';
		$row['reason']   = 'health_probe_blocked';
		$row['message'] .= 'CANNOT VERIFY: health probe blocked (HTTP ' . (int) $code . ' — challenge/auth/rate-limit); left applied, no rollback, operator must verify. ';
		zs_fleet_ue_breadcrumb( $slug, 'theme_health_probe_blocked', $row );
		error_log( '[zs-fleet] CRITICAL theme health_probe_blocked for ' . $slug . ' at ' . home_url() . ' (HTTP ' . (int) $code . ')' );
		return $row;
	}

	if ( $ver_after === $to && $code === (int) ZS_FLEET_UE_HEALTH_EXPECT && $fp_ok ) {
		$row['outcome'] = 'applied';
		zs_fleet_ue_prune_stashes( $slug );
		return $row;
	}
	// force-override: version mismatch ONLY, health green, no schema move (see apply_one).
	if ( $force && $row['schema_changed'] !== true && (string) $ver_after !== (string) $to && $code === (int) ZS_FLEET_UE_HEALTH_EXPECT && $fp_ok ) {
		$row['outcome']                   = 'applied';
		$row['forced']                    = true;
		$row['version_mismatch_accepted'] = true;
		$row['message']                  .= 'forced: theme on-disk ' . $ver_after . ' != target ' . $to . ', accepted by operator (health gates passed). ';
		zs_fleet_ue_prune_stashes( $slug );
		return $row;
	}
	// Vía C — a theme that empirically moved the schema has no safe file rollback
	// (file restore would leave the DB ahead of the files). Themes have no touches_db
	// concept, so this is their ONLY DB-migration guard — mirror the plugin no-rollback
	// branch: outcome=error, leave the stash for coordinated manual recovery, never
	// claim a clean 'rolled_back'. schema_changed false/null fall through to the normal
	// theme file rollback below.
	if ( $row['schema_changed'] === true ) {
		$row['outcome']  = 'error';
		$row['message'] .= 'theme verify failed and no safe rollback (schema_changed → DB ahead of files, host snapshot needed).';
		return $row;
	}
	zs_fleet_ue_rollback_theme( $row, $slug, $stash, $from, $themes_dir, 'theme verify failed →' );
	return $row;
}

/** Theme rollback: restore stash → re-verify health → rolled_back only if confirmed. No reactivation. */
function zs_fleet_ue_rollback_theme( &$row, $slug, $stash, $from, $themes_dir, $reason ) {
	if ( ! is_string( $stash ) || $stash === '' || ! is_dir( $stash ) ) {
		$row['outcome']  = 'error';
		$row['message'] .= $reason . ' CRITICAL: no rollback (stash missing).';
		zs_fleet_ue_breadcrumb( $slug, 'theme_stash_missing', $row );
		error_log( '[zs-fleet] CRITICAL theme no_stash for ' . $slug . ' at ' . home_url() );
		return;
	}
	if ( ! zs_fleet_ue_restore_plugin( $slug, $stash, $themes_dir ) ) {
		$row['outcome']  = 'error';
		$row['message'] .= $reason . ' CRITICAL: theme restore failed — site may be degraded.';
		zs_fleet_ue_breadcrumb( $slug, 'theme_restore_failed', $row );
		error_log( '[zs-fleet] CRITICAL theme restore_failed for ' . $slug . ' at ' . home_url() );
		return;
	}
	wp_clean_themes_cache( false );
	$ver_after                  = (string) wp_get_theme( $slug )->get( 'Version' );
	list( $code, $secs, $body ) = zs_fleet_ue_http_self();
	$fp_ok                      = zs_fleet_ue_fingerprint_ok( $body );
	$row['version_after']  = $ver_after;
	$row['active_after']   = ( get_stylesheet() === $slug || get_template() === $slug );
	$row['http_after']     = $code;
	$row['http_time_s']    = $secs;
	$row['fingerprint_ok'] = $fp_ok;

	// $from may be '' in rollback mode (prior version not pinned — stash is truth).
	$healthy = ( $code === (int) ZS_FLEET_UE_HEALTH_EXPECT ) && $fp_ok && ( $from === '' || $ver_after === (string) $from );
	if ( $healthy ) {
		$row['outcome']  = 'rolled_back';
		$row['message'] .= $reason . ' restored to ' . $ver_after . ' (verified healthy).';
		return;
	}
	$row['outcome']  = 'error';
	$row['message'] .= $reason . ' CRITICAL: theme restore ran but site still degraded (http ' . $code . ', ver ' . $ver_after . ').';
	zs_fleet_ue_breadcrumb( $slug, 'theme_restore_left_degraded', $row );
	error_log( '[zs-fleet] CRITICAL theme restore_left_degraded for ' . $slug . ' at ' . home_url() );
}

/* ──────────────────────────────────────────────────────────────────────────
 * ROLLBACK MODE — restore the most recent stash for a slug, no upgrader, no
 * network package fetch. Reuses the same restore + re-verify path as a failed
 * apply (zs_fleet_ue_rollback / _theme). A rollback item is minimal { type, slug }.
 * ────────────────────────────────────────────────────────────────────────── */

/** Newest stash dir for $slug (by trailing -<timestamp>), or '' if none. */
function zs_fleet_ue_latest_stash( $slug ) {
	if ( ! zs_fleet_ue_safe_slug( $slug ) ) {
		return '';
	}
	$base = trailingslashit( WP_CONTENT_DIR ) . ZS_FLEET_UE_STASH_SUBDIR;
	if ( ! is_dir( $base ) ) {
		return '';
	}
	$dirs = glob( $base . '/' . $slug . '-*', GLOB_ONLYDIR );
	if ( ! $dirs ) {
		return '';
	}
	// Stash dirs are named '<slug>-<safever>-<timestamp>'. Newest = highest
	// trailing timestamp (matches zs_fleet_ue_prune_stashes' ordering).
	usort(
		$dirs,
		function ( $a, $b ) {
			return (int) substr( strrchr( $a, '-' ), 1 ) <=> (int) substr( strrchr( $b, '-' ), 1 );
		}
	);
	return end( $dirs );
}

/**
 * Roll a single PLUGIN back to its most recent engine stash. No upgrader, no
 * network. Reuses zs_fleet_ue_rollback for the restore + health re-verify.
 * Outcome: 'rolled_back' on a verified-healthy restore, 'skipped' when there is
 * no stash, 'error' on a failed/degraded restore.
 */
function zs_fleet_ue_rollback_one( $update ) {
	$slug = $update['slug'];

	$row = array(
		'type'           => 'plugin',
		'slug'           => $slug,
		'from'           => '',
		'to'             => '',
		'outcome'        => 'error',
		'version_before' => '',
		'version_after'  => '',
		'active_before'  => null,
		'active_after'   => null,
		'http_after'     => null,
		'http_time_s'    => null,
		'fingerprint_ok' => null,
		'stash'          => '',
		'message'        => '',
	);

	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';

	$pf = zs_fleet_ue_resolve_plugin_file( $slug );
	if ( $pf === '' ) {
		$row['outcome'] = 'skipped';
		$row['message'] = 'plugin not installed';
		return $row;
	}

	$ver_before            = zs_fleet_ue_disk_version( $pf );
	$row['version_before'] = $ver_before;
	$row['version_after']  = $ver_before;
	$active_before         = is_plugin_active( $pf );
	$row['active_before']  = $active_before;

	$stash = zs_fleet_ue_latest_stash( $slug );
	if ( $stash === '' ) {
		$row['outcome'] = 'skipped';
		$row['message'] = 'no stash to restore';
		return $row;
	}
	$row['stash'] = str_replace( trailingslashit( WP_CONTENT_DIR ), '', $stash );

	// Restore + re-verify. The stash carries the prior version; $from is left
	// empty so the rollback's health check does not pin a specific version.
	zs_fleet_ue_rollback( $row, $slug, $pf, $stash, '', $active_before, 'rollback mode →' );
	return $row;
}

/**
 * Roll a single THEME back to its most recent engine stash. Mirrors
 * zs_fleet_ue_rollback_one via the theme restore path (themes dir).
 */
function zs_fleet_ue_rollback_one_theme( $update ) {
	$slug = $update['slug'];

	$row = array(
		'type'           => 'theme',
		'slug'           => $slug,
		'from'           => '',
		'to'             => '',
		'outcome'        => 'error',
		'version_before' => '',
		'version_after'  => '',
		'active_before'  => null,
		'active_after'   => null,
		'http_after'     => null,
		'http_time_s'    => null,
		'fingerprint_ok' => null,
		'stash'          => '',
		'message'        => '',
	);

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/theme.php';

	$theme = wp_get_theme( $slug );
	if ( ! $theme->exists() ) {
		$row['outcome'] = 'skipped';
		$row['message'] = 'theme not installed';
		return $row;
	}
	$themes_dir = get_theme_root( $slug );

	$ver_before            = (string) $theme->get( 'Version' );
	$row['version_before'] = $ver_before;
	$row['version_after']  = $ver_before;
	$active_before         = ( get_stylesheet() === $slug || get_template() === $slug );
	$row['active_before']  = $active_before;

	$stash = zs_fleet_ue_latest_stash( $slug );
	if ( $stash === '' ) {
		$row['outcome'] = 'skipped';
		$row['message'] = 'no stash to restore';
		return $row;
	}
	$row['stash'] = str_replace( trailingslashit( WP_CONTENT_DIR ), '', $stash );

	zs_fleet_ue_rollback_theme( $row, $slug, $stash, '', $themes_dir, 'rollback mode →' );
	return $row;
}

/* ──────────────────────────────────────────────────────────────────────────
 * ONBOARD MODE — engine-driven web-onboarding of FlowGuard (Fleet v2).
 *
 * A FIXED-VERB ritual: fetch a hash-pinned artifact, install + activate via
 * WP-core (running FlowGuard's Activator behind the engine's health gate),
 * configure it (auto-run OFF so the engine keeps sole ownership of rollback,
 * seed the "Fleet smoke" baseline flow), mint a WP Application Password, SEAL it
 * to the operator's X25519 public key (crypto_box_seal — the Worker is a blind
 * messenger), and deliver the sealed blob on a dedicated channel with durable
 * retry. Plaintext credentials NEVER touch a report/inventory/option/log.
 *
 * Full spec + adversarial must-fixes (B1/M1..M5, m1/m2):
 *   03_AGENCY/Fleet/_notes/spec-fleet-flowguard-web-onboard.md
 * ────────────────────────────────────────────────────────────────────────── */

/**
 * Byte-exact sealed-secret plaintext (PURE). MUST match the operator's PyNaCl
 * split — `SealedBox(...).decrypt(...).split("\n") -> [user, uuid, app_password]`.
 * The app-password's display spaces are stripped (WP accepts the compact form).
 */
function zs_fleet_ue_onboard_plaintext( $user, $uuid, $app_password ) {
	return (string) $user . "\n" . (string) $uuid . "\n" . str_replace( ' ', '', (string) $app_password );
}

/**
 * Zip-slip / layout guard (returns '' if OK, else WP_Error): the artifact must be
 * exactly one top dir '<slug>/' containing '<slug>/<slug>.php', with no entry that
 * escapes the prefix or traverses (../, absolute, backslash).
 */
function zs_fleet_ue_onboard_zip_layout_ok( $zip_path, $slug ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'no_zip', 'ZipArchive unavailable' );
	}
	$za = new ZipArchive();
	if ( $za->open( $zip_path ) !== true ) {
		return new WP_Error( 'zip_open', 'cannot open artifact zip' );
	}
	$prefix   = $slug . '/';
	$has_main = false;
	for ( $i = 0; $i < $za->numFiles; $i++ ) {
		$name = $za->getNameIndex( $i );
		if ( ! is_string( $name ) || $name === '' ) {
			$za->close();
			return new WP_Error( 'zip_entry', 'unreadable zip entry' );
		}
		$norm = str_replace( '\\', '/', $name );
		if ( $norm[0] === '/' || strpos( $norm, '../' ) !== false || strpos( $name, '..\\' ) !== false ) {
			$za->close();
			return new WP_Error( 'zip_slip', 'zip entry escapes: ' . $name );
		}
		if ( strpos( $norm, $prefix ) !== 0 ) {
			$za->close();
			return new WP_Error( 'zip_layout', 'zip entry outside ' . $prefix . ': ' . $name );
		}
		if ( $norm === $prefix . $slug . '.php' ) {
			$has_main = true;
		}
	}
	$za->close();
	if ( ! $has_main ) {
		return new WP_Error( 'zip_no_main', 'artifact missing ' . $prefix . $slug . '.php' );
	}
	return '';
}

/**
 * Fetch the artifact from /v1/artifact/<action>, stream to a tempfile, cap its
 * size, hash-verify (constant-time) against the manifest's pin, and layout-guard
 * it. On ANY failure the tempfile is removed and a WP_Error returned — INSTALL
 * NOTHING. Returns the absolute tempfile path on success (caller unlinks).
 */
function zs_fleet_ue_onboard_fetch_artifact( $action, $expected_sha, $slug ) {
	if ( ZS_FLEET_UE_CONTROL_URL === '' ) {
		return new WP_Error( 'no_control', 'no control-plane configured' );
	}
	$max = defined( 'ZS_FLEET_UE_ARTIFACT_MAX' ) ? (int) ZS_FLEET_UE_ARTIFACT_MAX : 20971520;

	require_once ABSPATH . 'wp-admin/includes/file.php';
	$tmp = wp_tempnam( 'zs-fleet-artifact-' . $action );
	if ( ! $tmp ) {
		return new WP_Error( 'no_tmp', 'cannot create tempfile' );
	}

	$url  = trailingslashit( ZS_FLEET_UE_CONTROL_URL ) . 'v1/artifact/' . rawurlencode( (string) $action );
	$resp = wp_remote_get(
		$url,
		array(
			'timeout'   => 60,
			'stream'    => true,
			'filename'  => $tmp,
			'sslverify' => true, // TLS verified — the hash is the integrity gate, but never trust an unverified transport.
			'headers'   => array(
				'User-Agent'      => 'zs-fleet-engine/' . ( defined( 'ZS_FLEET_VERSION' ) ? ZS_FLEET_VERSION : '0' ),
				'X-ZS-Site-Token' => ZS_FLEET_UE_SITE_TOKEN,
			),
		)
	);
	if ( is_wp_error( $resp ) ) {
		@unlink( $tmp );
		return $resp;
	}
	$code = (int) wp_remote_retrieve_response_code( $resp );
	if ( $code !== 200 ) {
		@unlink( $tmp );
		return new WP_Error( 'artifact_http', 'artifact HTTP ' . $code );
	}
	// Size gate. The WP HTTP API does not expose a hard mid-stream byte cap for a
	// streamed download, so the file lands first and we reject it before any install
	// touches it (bounded by this cap; the hash below is the real integrity gate).
	clearstatcache( true, $tmp );
	$size = @filesize( $tmp );
	if ( $size === false || $size <= 0 || $size > $max ) {
		@unlink( $tmp );
		return new WP_Error( 'artifact_size', 'artifact size out of bounds: ' . var_export( $size, true ) );
	}
	// Hash-verify: constant-time compare against the SIGNED manifest's pin. A
	// mismatch installs NOTHING.
	$got = hash_file( 'sha256', $tmp );
	if ( ! is_string( $got ) || ! hash_equals( strtolower( (string) $expected_sha ), strtolower( $got ) ) ) {
		@unlink( $tmp );
		return new WP_Error( 'artifact_hash', 'artifact sha256 mismatch — install nothing' );
	}
	$layout = zs_fleet_ue_onboard_zip_layout_ok( $tmp, $slug );
	if ( is_wp_error( $layout ) ) {
		@unlink( $tmp );
		return $layout;
	}
	return $tmp;
}

/**
 * Seed the "Fleet smoke" baseline flow. Dedupe by post_title (m2): UPDATE an
 * existing flow rather than inserting a duplicate. `_flowguard_steps` is stored as
 * a PHP ARRAY (M2) — byte-identical to onboard.py's `wp post meta update
 * --format=json` result, NOT a JSON string. Returns the flow post ID (0 on fail).
 */
function zs_fleet_ue_onboard_seed_flow( $steps ) {
	$title = 'Fleet smoke';
	$existing = get_posts(
		array(
			'post_type'        => 'flowguard_flow',
			'post_status'      => 'any',
			'title'            => $title,
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
		)
	);
	if ( ! empty( $existing ) ) {
		$fid = (int) $existing[0];
	} else {
		$fid = wp_insert_post(
			array(
				'post_type'   => 'flowguard_flow',
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);
		if ( is_wp_error( $fid ) || ! $fid ) {
			return 0;
		}
		$fid = (int) $fid;
	}
	// ARRAY, not a JSON string — matches onboard.py's proven shape (M2). We do NOT
	// set _flowguard_status (onboard.py never did; the proven shape is the contract).
	update_post_meta( $fid, '_flowguard_steps', $steps );
	return $fid;
}

/** Lowest-ID administrator as a WP_User, or null if the site has none. */
function zs_fleet_ue_onboard_lowest_admin() {
	$ids = get_users(
		array(
			'role'    => 'administrator',
			'orderby' => 'ID',
			'order'   => 'ASC',
			'number'  => 1,
			'fields'  => 'ID',
		)
	);
	if ( empty( $ids ) ) {
		return null;
	}
	$u = get_user_by( 'id', (int) $ids[0] );
	return ( $u instanceof WP_User ) ? $u : null;
}

/** Delete every application-password named $name (rotate-in-place cleanup). */
function zs_fleet_ue_onboard_strip_app_passwords( $user_id, $name ) {
	if ( ! class_exists( 'WP_Application_Passwords' ) ) {
		return;
	}
	$pws = WP_Application_Passwords::get_user_application_passwords( (int) $user_id );
	if ( ! is_array( $pws ) ) {
		return;
	}
	foreach ( $pws as $pw ) {
		if ( isset( $pw['name'], $pw['uuid'] ) && $pw['name'] === $name ) {
			WP_Application_Passwords::delete_application_password( (int) $user_id, $pw['uuid'] );
		}
	}
}

/**
 * POST the sealed onboard secret to the dedicated channel. Body carries ONLY the
 * non-plaintext context { site, app_password_uuid, app_password_user, sealed }.
 * Returns true on a 2xx, false otherwise (caller persists for durable retry).
 */
function zs_fleet_ue_onboard_deliver( $sealed_b64, $uuid, $user ) {
	if ( ZS_FLEET_UE_CONTROL_URL === '' || ZS_FLEET_UE_SITE_TOKEN === '' ) {
		return false;
	}
	$resp = wp_remote_post(
		trailingslashit( ZS_FLEET_UE_CONTROL_URL ) . 'v1/onboard-secret',
		array(
			'timeout' => 30,
			'headers' => array(
				'Content-Type'    => 'application/json',
				'X-ZS-Site-Token' => ZS_FLEET_UE_SITE_TOKEN,
			),
			'body'    => wp_json_encode(
				array(
					'site'              => wp_parse_url( home_url(), PHP_URL_HOST ),
					'app_password_uuid' => (string) $uuid,
					'app_password_user' => (string) $user,
					'sealed'            => (string) $sealed_b64,
				)
			),
		)
	);
	if ( is_wp_error( $resp ) ) {
		return false;
	}
	$code = (int) wp_remote_retrieve_response_code( $resp );
	return ( $code >= 200 && $code < 300 );
}

/**
 * Durable retry of a pending sealed onboard secret (the delivery net, decoupled
 * from the manifest that minted it — M1). Runs every enrolled cron cycle until the
 * control-plane 2xx-acks it; the stored blob holds ciphertext ONLY, never plaintext.
 */
function zs_fleet_ue_onboard_retry_delivery() {
	$blob = get_option( ZS_FLEET_UE_OPT_ONBOARD_UNACKED, null );
	if ( ! is_array( $blob ) || empty( $blob['sealed'] ) || empty( $blob['user'] ) ) {
		return;
	}
	$ok = zs_fleet_ue_onboard_deliver(
		(string) $blob['sealed'],
		isset( $blob['uuid'] ) ? (string) $blob['uuid'] : '',
		(string) $blob['user']
	);
	if ( $ok ) {
		delete_option( ZS_FLEET_UE_OPT_ONBOARD_UNACKED );
	}
}

/**
 * The onboard ritual for FlowGuard. Strict order of operations (see spec):
 *   opt-in → seal-key precondition → fetch+hash artifact → install+activate →
 *   health-gate → configure (autorun-off read-after-write M3 · seed smoke flow M2 ·
 *   is_ssl M5) → consume nonce (M1) → mint (rotate-in-place, M1 re-mint guard) →
 *   seal → deliver (durable retry).
 *
 * $nonce is threaded from the manifest so it can be consumed at the M1 point —
 * after install+health, BEFORE mint — so a crash during mint/deliver can never
 * re-run this manifest and blindly rotate a possibly-delivered credential.
 *
 * The returned row carries ONLY non-secret fields. Plaintext is held for the
 * shortest possible window (mint → seal) and memzero'd immediately after sealing.
 */
function zs_fleet_ue_onboard_flowguard( $update, $nonce = '' ) {
	$action  = isset( $update['action'] ) ? (string) $update['action'] : '';
	$version = isset( $update['artifact_version'] ) ? (string) $update['artifact_version'] : '';
	$sha256  = isset( $update['artifact_sha256'] ) ? strtolower( (string) $update['artifact_sha256'] ) : '';

	$row = array(
		'type'              => 'onboard',
		'action'            => $action,
		'artifact_version'  => $version,
		'outcome'           => 'error',
		'installed_version' => '',
		'autorun_off'       => null,
		'flows_seeded'      => null,
		'license'           => 'pending',
		'uuid'              => '',
		'user'              => '',
		'http_after'        => null,
		'fingerprint_ok'    => null,
		'message'           => '',
	);

	// ── 1. Per-site opt-in. ──
	if ( ! ( defined( 'ZS_FLEET_UE_ALLOW_ONBOARD' ) && ZS_FLEET_UE_ALLOW_ONBOARD ) ) {
		$row['outcome'] = 'skipped';
		$row['message'] = 'onboard not enabled on this site (ZS_FLEET_UE_ALLOW_ONBOARD false)';
		return $row;
	}

	// ── Seal-key precondition: no operator public key ⇒ we could mint but never
	// deliver ⇒ fail fast BEFORE any install; never mint a credential we cannot seal.
	$seal_pub_b64 = defined( 'ZS_FLEET_UE_SEAL_PUBKEY' ) ? (string) ZS_FLEET_UE_SEAL_PUBKEY : '';
	if ( $seal_pub_b64 === '' ) {
		$row['outcome'] = 'error';
		$row['message'] = 'no seal pubkey';
		return $row;
	}
	if ( ! function_exists( 'sodium_crypto_box_seal' ) ) {
		$row['outcome'] = 'error';
		$row['message'] = 'libsodium sealed-box unavailable';
		return $row;
	}
	$seal_pub = base64_decode( $seal_pub_b64, true );
	if ( $seal_pub === false || strlen( $seal_pub ) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES ) {
		$row['outcome'] = 'error';
		$row['message'] = 'seal pubkey malformed (expect base64 32-byte X25519)';
		return $row;
	}

	// Defense in depth: action must be in the compiled allowlist (shape already gated).
	$allow = defined( 'ZS_FLEET_UE_ONBOARD_ACTIONS' ) ? ZS_FLEET_UE_ONBOARD_ACTIONS : array();
	if ( ! is_array( $allow ) || ! in_array( $action, $allow, true ) ) {
		$row['outcome'] = 'error';
		$row['message'] = 'action not in allowlist: ' . $action;
		return $row;
	}

	$slug = 'flowguard'; // the ONE ritual's fixed plugin slug — never from the manifest.

	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	// ── 2. Fetch + hash-verify the artifact. ──
	zs_fleet_ue_raise_limits();
	$fetched = zs_fleet_ue_onboard_fetch_artifact( $action, $sha256, $slug );
	if ( is_wp_error( $fetched ) ) {
		$row['outcome'] = 'error';
		$row['message'] = 'artifact: ' . $fetched->get_error_message();
		return $row;
	}
	$zip_path = $fetched;

	// ── 3. Install (or reuse) + activate via WP-core (runs FlowGuard's Activator). ──
	WP_Filesystem();
	$already = zs_fleet_ue_resolve_plugin_file( $slug );
	$pf      = $already;
	if ( $already === '' ) {
		$upgrader  = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$installed = $upgrader->install( $zip_path );
		if ( is_wp_error( $installed ) || $installed !== true ) {
			@unlink( $zip_path );
			$row['outcome']  = 'error';
			$row['message'] .= 'install failed: ' . ( is_wp_error( $installed ) ? $installed->get_error_message() : 'upgrader returned non-true' ) . '. ';
			return $row;
		}
		wp_clean_plugins_cache( false );
		$pf = zs_fleet_ue_resolve_plugin_file( $slug );
	} else {
		$row['message'] .= 'flowguard already installed; activating existing. ';
	}
	@unlink( $zip_path ); // verified artifact no longer needed on disk.
	if ( $pf === '' ) {
		$row['outcome']  = 'error';
		$row['message'] .= 'flowguard not resolvable after install. ';
		return $row;
	}

	// Fresh install MUST run FlowGuard's Activator (custom tables / cron / roles),
	// so activate with hooks ($silent=false); a reuse re-activation must NOT re-run
	// it. $silent = already-installed.
	$act = activate_plugin( $pf, '', false, $already !== '' );
	if ( is_wp_error( $act ) ) {
		$row['outcome']  = 'error';
		$row['message'] .= 'activate failed: ' . $act->get_error_message() . '. ';
		if ( $already === '' ) {
			zs_fleet_ue_onboard_revert( $slug, $pf );
		}
		return $row;
	}
	$row['installed_version'] = zs_fleet_ue_disk_version( $pf );

	// ── 4. Health gate: the site must still render after activation. ──
	list( $code, $secs, $body ) = zs_fleet_ue_http_self();
	$fp_ok                      = zs_fleet_ue_fingerprint_ok( $body );
	$row['http_after']          = $code;
	$row['fingerprint_ok']      = $fp_ok;

	if ( zs_fleet_ue_health_blocked( $code, $body ) ) {
		// Edge challenge/auth/rate-limit → cannot verify. Leave files, escalate, NO mint.
		$row['outcome']  = 'error';
		$row['reason']   = 'health_probe_blocked';
		$row['message'] .= 'CANNOT VERIFY onboard: health probe blocked (HTTP ' . (int) $code . '); left installed, no mint, operator must verify. ';
		zs_fleet_ue_breadcrumb( $slug, 'onboard_health_probe_blocked', $row );
		error_log( '[zs-fleet] CRITICAL onboard health_probe_blocked for ' . $slug . ' at ' . home_url() . ' (HTTP ' . (int) $code . ')' );
		return $row;
	}
	if ( (int) $code !== (int) ZS_FLEET_UE_HEALTH_EXPECT || ! $fp_ok ) {
		// Genuine breakage → revert the install (deactivate + remove files), no mint.
		$row['outcome']  = 'error';
		$row['message'] .= 'onboard health gate failed (HTTP ' . (int) $code . ', fingerprint ' . ( $fp_ok ? 'ok' : 'bad' ) . '); reverting. ';
		deactivate_plugins( $pf, true );
		if ( $already === '' ) {
			zs_fleet_ue_onboard_revert( $slug, $pf );
		}
		return $row;
	}

	// ── 5a. Disable FlowGuard auto-run/rollback (M3): read-modify-write the WHOLE
	// flowguard_settings option, then READ-AFTER-WRITE verify BOTH enabled flags are
	// false. This is a SECURITY requirement (otherwise two owners of rollback); if it
	// cannot be confirmed → 'degraded', do NOT report the site onboarded. ──
	$s = get_option( 'flowguard_settings', array() );
	if ( ! is_array( $s ) ) {
		$s = array();
	}
	if ( ! isset( $s['auto_run'] ) || ! is_array( $s['auto_run'] ) ) {
		$s['auto_run'] = array();
	}
	foreach ( array( 'plugin_updates', 'theme_updates' ) as $grp ) {
		if ( ! isset( $s['auto_run'][ $grp ] ) || ! is_array( $s['auto_run'][ $grp ] ) ) {
			$s['auto_run'][ $grp ] = array();
		}
		$s['auto_run'][ $grp ]['enabled']       = false;
		$s['auto_run'][ $grp ]['auto_rollback'] = false;
	}
	update_option( 'flowguard_settings', $s );

	// Bust both the individual-option and the alloptions bulk cache so the re-read is
	// persisted truth (covers autoloaded + non-autoloaded + external object caches).
	wp_cache_delete( 'flowguard_settings', 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	$chk         = get_option( 'flowguard_settings', array() );
	$autorun_off = is_array( $chk )
		&& isset( $chk['auto_run']['plugin_updates']['enabled'], $chk['auto_run']['theme_updates']['enabled'] )
		&& $chk['auto_run']['plugin_updates']['enabled'] === false
		&& $chk['auto_run']['theme_updates']['enabled'] === false;
	$row['autorun_off'] = $autorun_off;
	if ( ! $autorun_off ) {
		$row['outcome']  = 'degraded';
		$row['message'] .= 'M3: could not confirm FlowGuard auto_run disabled (read-after-write) — NOT onboarding. ';
		return $row;
	}

	// ── 5b. Seed the "Fleet smoke" baseline flow (M2 shape, m2 dedupe). ──
	$home  = home_url( '/' );
	$steps = array(
		array(
			'id'          => 'fleet_home_nav',
			'type'        => 'navigate',
			'name'        => '',
			'selector'    => '',
			'value'       => $home,
			'description' => 'Fleet smoke: navigate home',
			'options'     => array(),
		),
		array(
			'id'          => 'fleet_home_visual',
			'type'        => 'visualCheck',
			'name'        => '',
			'selector'    => '',
			'value'       => '',
			'description' => 'Fleet smoke: full-page visual check',
			'options'     => array( 'fullPage' => true ),
		),
	);
	$fid                 = zs_fleet_ue_onboard_seed_flow( $steps );
	$row['flows_seeded'] = ( $fid > 0 );

	// ── 5c. HTTPS visibility (M5): a WP Application Password is refused by Basic Auth
	// when is_ssl() is false. Behind Cloudflare (TLS terminated at the edge) is_ssl()
	// is false at origin unless wp-config honors HTTP_X_FORWARDED_PROTO. Verify what WP
	// ITSELF will check at auth time BEFORE minting; if it can't see HTTPS, flag it and
	// do NOT mint (a mint-unusable credential surfaced now, not weeks later in VRT). The
	// nonce is deliberately NOT consumed here — a fixed wp-config lets the next cron
	// finish, and re-running install (already-present) + configure is idempotent. ──
	if ( ! is_ssl() ) {
		$row['outcome']  = 'degraded';
		$row['flag']     = 'app_password_unusable_no_https';
		$row['message'] .= 'M5: WP does not see HTTPS (is_ssl() false) — an app-password would be refused; force HTTP_X_FORWARDED_PROTO in wp-config. Not minting. ';
		return $row;
	}

	// ── 6. Consume the manifest nonce NOW (M1): install + health + configure are all
	// done and verified. Burning the nonce here means a crash during mint/deliver can
	// NEVER re-run this manifest and blindly rotate a delivered credential; delivery
	// durability is handled separately by the unacked-blob retry. ──
	if ( is_string( $nonce ) && $nonce !== '' ) {
		zs_fleet_ue_consume_nonce( $nonce );
	}

	// ── 7. Mint. M1 guard: if a sealed blob is still pending delivery, the durable
	// retry path owns it — do not rotate. Otherwise mint fresh (rotating any stale
	// same-name password), because a fresh, non-replayed onboard manifest means the
	// operator needs a deliverable credential and none is currently pending. ──
	if ( ! class_exists( 'WP_Application_Passwords' ) ) {
		$row['outcome']  = 'error';
		$row['message'] .= 'WP_Application_Passwords unavailable (WP < 5.6?). ';
		return $row;
	}
	$admin = zs_fleet_ue_onboard_lowest_admin();
	if ( ! $admin ) {
		$row['outcome']  = 'error';
		$row['message'] .= 'no administrator to mint an app-password on. ';
		return $row;
	}

	$app_name = 'fleet-flowguard';
	$unacked  = get_option( ZS_FLEET_UE_OPT_ONBOARD_UNACKED, null );
	if ( is_array( $unacked ) ) {
		// A sealed blob is already minted and awaiting delivery — the durable-retry
		// path (every cron) is finishing it. Do NOT rotate: that would strand the
		// pending credential. Report the site configured; delivery completes async.
		$row['outcome']  = 'onboarded';
		$row['user']     = $admin->user_login;
		$row['delivery'] = 'pending';
		$row['message'] .= 'M1: sealed app-password minted, delivery still pending — not rotated. ';
		return $row;
	}

	// Fresh mint. Rotate-in-place: strip any stale same-name password first. If a
	// prior mint's delivery silently failed and left no unacked blob (e.g. a crash
	// after mint, before persist), this is how the site recovers — the stale,
	// never-confirmed credential is replaced by a fresh, deliverable one.
	zs_fleet_ue_onboard_strip_app_passwords( $admin->ID, $app_name );
	$created = WP_Application_Passwords::create_new_application_password(
		$admin->ID,
		array( 'name' => $app_name )
	);
	if ( is_wp_error( $created ) || ! is_array( $created ) || empty( $created[0] ) ) {
		$row['outcome']  = 'error';
		$row['message'] .= 'app-password mint failed: ' . ( is_wp_error( $created ) ? $created->get_error_message() : 'unexpected return' ) . '. ';
		return $row;
	}
	$plain_pw = (string) $created[0]; // the ONLY moment plaintext exists on-site.
	$pw_item  = ( isset( $created[1] ) && is_array( $created[1] ) ) ? $created[1] : array();
	$uuid     = isset( $pw_item['uuid'] ) ? (string) $pw_item['uuid'] : '';

	// ── 8. Seal (byte-identical to the operator's PyNaCl unseal). ──
	$plaintext  = zs_fleet_ue_onboard_plaintext( $admin->user_login, $uuid, $plain_pw );
	$sealed_raw = sodium_crypto_box_seal( $plaintext, $seal_pub );
	if ( ! is_string( $sealed_raw ) || $sealed_raw === '' ) {
		$row['outcome']  = 'error';
		$row['message'] .= 'sealing failed. ';
		return $row;
	}
	$sealed_b64 = base64_encode( $sealed_raw );

	// Persist the sealed blob (ciphertext ONLY, never plaintext) BEFORE we wipe or
	// deliver. If anything after this point dies — a memzero fatal on a sodium_compat
	// host, a delivery timeout, an OOM — the durable-retry path (every cron) still
	// finishes delivery, so a minted credential is never stranded undelivered.
	update_option(
		ZS_FLEET_UE_OPT_ONBOARD_UNACKED,
		array(
			'sealed' => $sealed_b64,
			'uuid'   => $uuid,
			'user'   => $admin->user_login,
			'at'     => gmdate( 'c' ),
		),
		false
	);

	// Wipe plaintext now that it is sealed. sodium_memzero() is native-only: WordPress's
	// sodium_compat polyfill DEFINES it but THROWS ("cannot securely wipe memory from
	// PHP"), so function_exists() is not enough — require the native extension and fall
	// back to a plain overwrite (best effort) otherwise. try/catch belt-and-suspenders.
	if ( extension_loaded( 'sodium' ) && function_exists( 'sodium_memzero' ) ) {
		try {
			sodium_memzero( $plain_pw );
			sodium_memzero( $plaintext );
		} catch ( \Throwable $e ) {
			$plain_pw  = '';
			$plaintext = '';
		}
	} else {
		$plain_pw  = '';
		$plaintext = '';
	}

	$row['user'] = $admin->user_login;
	$row['uuid'] = $uuid;

	// ── 9. Deliver on the dedicated channel. The unacked blob persisted above is the
	// durable retry net; clear it ONLY on a 2xx ack. ──
	if ( zs_fleet_ue_onboard_deliver( $sealed_b64, $uuid, $admin->user_login ) ) {
		delete_option( ZS_FLEET_UE_OPT_ONBOARD_UNACKED );
		$row['outcome']   = 'onboarded';
		$row['delivery']  = 'delivered';
		$row['message']  .= 'onboarded: sealed secret delivered. ';
	} else {
		$row['outcome']  = 'onboarded'; // credential IS minted + sealed; delivery will retry next cron.
		$row['delivery'] = 'pending';
		$row['message'] .= 'onboarded: sealed secret stored for durable retry (delivery non-2xx). ';
	}
	return $row;
}

/** Revert an onboard install we performed: deactivate + remove the plugin dir. */
function zs_fleet_ue_onboard_revert( $slug, $pf ) {
	if ( function_exists( 'deactivate_plugins' ) && $pf !== '' ) {
		deactivate_plugins( $pf, true );
	}
	$live = zs_fleet_ue_live_path( $slug );
	if ( ! is_wp_error( $live ) && is_dir( $live ) ) {
		zs_fleet_ue_rrmdir( $live );
	}
	wp_clean_plugins_cache( false );
}

/* ──────────────────────────────────────────────────────────────────────────
 * RUN
 * ────────────────────────────────────────────────────────────────────────── */

/**
 * Run a verified manifest. Iterates updates, builds the report. Honors mode.
 * Assumes the caller has already verified signature, expiry, site, nonce.
 */
function zs_fleet_ue_run( $manifest ) {
	$mode    = $manifest['mode'];
	$nonce   = isset( $manifest['nonce'] ) ? (string) $manifest['nonce'] : '';
	$results = array();
	foreach ( $manifest['updates'] as $update ) {
		if ( $mode === 'onboard' ) {
			// Fixed-verb ritual — no type/slug branching. Thread the nonce so the
			// handler can consume it at the M1 point (after health, before mint).
			$results[] = zs_fleet_ue_onboard_flowguard( $update, $nonce );
			continue;
		}
		$is_theme = ( isset( $update['type'] ) && $update['type'] === 'theme' );
		if ( $mode === 'rollback' ) {
			$results[] = $is_theme
				? zs_fleet_ue_rollback_one_theme( $update )
				: zs_fleet_ue_rollback_one( $update );
			continue;
		}
		$results[] = $is_theme
			? zs_fleet_ue_apply_one_theme( $update, $mode )
			: zs_fleet_ue_apply_one( $update, $mode );
	}
	list( $code, $secs ) = zs_fleet_ue_http_self();
	return array(
		'site'           => wp_parse_url( home_url(), PHP_URL_HOST ),
		'engine_version' => defined( 'ZS_FLEET_VERSION' ) ? ZS_FLEET_VERSION : 'unknown',
		'manifest_nonce' => isset( $manifest['nonce'] ) ? $manifest['nonce'] : '',
		'ran_at'         => gmdate( 'c' ),
		'mode'           => $mode,
		'site_http'      => $code,
		'site_http_time' => $secs,
		'results'        => $results,
	);
}

/**
 * Full gated entry: verify a signed envelope end-to-end, then run.
 * Returns the report array, or WP_Error if any gate fails.
 */
/**
 * Canonical site key — MUST mirror the control-plane's normalizeSite() so a manifest
 * issued for the normalized domain matches this site's host regardless of www/scheme/
 * slash/case/port. Conservative: only a leading www. is stripped (never other
 * subdomains), so a manifest can still only ever run on the SAME registrable host.
 */
function zs_fleet_ue_normalize_site( $s ) {
	$h = strtolower( trim( (string) $s ) );
	if ( $h === '' ) {
		return '';
	}
	$h = preg_replace( '#^https?://#', '', $h );
	$h = preg_replace( '#^(?:www\.)+#', '', $h );
	$h = explode( '/', $h )[0];
	$h = explode( ':', $h )[0];
	return trim( $h );
}

function zs_fleet_ue_process_envelope( $envelope ) {
	$manifest = zs_fleet_ue_verify_envelope( $envelope, ZS_FLEET_UE_PUBKEY );
	if ( is_wp_error( $manifest ) ) {
		return $manifest;
	}
	$shape = zs_fleet_ue_validate_shape( $manifest );
	if ( $shape !== '' ) {
		return new WP_Error( 'bad_shape', $shape );
	}
	// Site binding: a manifest can only ever run on its named site. Both sides are
	// normalized (www/scheme/slash/case/port) so the control-plane's normalized
	// manifest.site matches a www.-fronted home_url — while still binding to the
	// same registrable host (never a different subdomain).
	$host = zs_fleet_ue_normalize_site( wp_parse_url( home_url(), PHP_URL_HOST ) );
	if ( strcasecmp( zs_fleet_ue_normalize_site( (string) $manifest['site'] ), $host ) !== 0 ) {
		return new WP_Error( 'wrong_site', "manifest for {$manifest['site']} delivered to {$host}" );
	}
	if ( ! zs_fleet_ue_not_expired( $manifest['expires_at'], time() ) ) {
		return new WP_Error( 'expired', 'manifest expired' );
	}
	// Replay guard: refuse a consumed nonce on state-mutating modes (apply +
	// rollback + onboard); shadow is idempotent and exempt.
	$mutating = in_array( $manifest['mode'], array( 'apply', 'rollback', 'onboard' ), true );
	if ( $mutating && zs_fleet_ue_nonce_consumed( $manifest['nonce'] ) ) {
		return new WP_Error( 'replay', 'nonce already consumed' );
	}

	$report = zs_fleet_ue_run( $manifest );

	// apply/rollback consume the nonce here (after the whole run). onboard consumes
	// its OWN nonce INSIDE the handler — after install+health, BEFORE mint (M1) — so a
	// crash during mint/deliver cannot re-run and re-rotate; do NOT double-consume it.
	if ( $mutating && $manifest['mode'] !== 'onboard' ) {
		zs_fleet_ue_consume_nonce( $manifest['nonce'] );
	}
	update_option( ZS_FLEET_UE_OPT_REPORT, $report, false );
	return $report;
}

/* ── Nonce replay store (bounded) ───────────────────────────────────────── */

function zs_fleet_ue_nonce_consumed( $nonce ) {
	$list = get_option( ZS_FLEET_UE_OPT_NONCES, array() );
	return is_array( $list ) && in_array( $nonce, $list, true );
}
function zs_fleet_ue_consume_nonce( $nonce ) {
	$list   = get_option( ZS_FLEET_UE_OPT_NONCES, array() );
	$list   = is_array( $list ) ? $list : array();
	$list[] = $nonce;
	if ( count( $list ) > 200 ) {
		$list = array_slice( $list, -200 );
	}
	update_option( ZS_FLEET_UE_OPT_NONCES, $list, false );
}

/* ──────────────────────────────────────────────────────────────────────────
 * DETECTION / INVENTORY — the check-in payload the control-plane consumes.
 *
 * Reports installed plugins + themes (current version, active state, and the
 * available version when an update is visible), plus an honest `admin_context`
 * flag. Some plugins register their custom updater ONLY when is_admin() was true
 * AT LOAD time, so a check run outside admin context undercounts them (validated
 * 2026-06-24: must-have-cookie invisible in plain cli, visible with WP_ADMIN
 * defined before boot). The CALLER provides admin context — e.g.
 *   wp --exec='if(!defined("WP_ADMIN"))define("WP_ADMIN",true);' eval 'echo json_encode(zs_fleet_ue_detect());'
 * detect() cannot retroactively change is_admin() for already-loaded plugins; it
 * surfaces the flag so the control-plane knows whether the report is complete.
 * ────────────────────────────────────────────────────────────────────────── */

function zs_fleet_ue_detect() {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/theme.php';
	require_once ABSPATH . 'wp-admin/includes/update.php';

	delete_site_transient( 'update_plugins' );
	wp_update_plugins();
	delete_site_transient( 'update_themes' );
	wp_update_themes();
	$tp = get_site_transient( 'update_plugins' );
	$tt = get_site_transient( 'update_themes' );

	$plugins = array();
	foreach ( get_plugins() as $file => $data ) {
		$slug = dirname( $file );
		if ( $slug === '.' ) {
			continue; // root single-file plugins are out of scope for the engine.
		}
		$row = array(
			'slug'    => $slug,
			'current' => isset( $data['Version'] ) ? (string) $data['Version'] : '',
			'active'  => is_plugin_active( $file ),
		);
		if ( isset( $tp->response[ $file ]->new_version ) ) {
			$row['available'] = (string) $tp->response[ $file ]->new_version;
		}
		$plugins[] = $row;
	}

	$themes = array();
	foreach ( wp_get_themes() as $slug => $theme ) {
		$row = array(
			'slug'    => (string) $slug,
			'current' => (string) $theme->get( 'Version' ),
			'active'  => ( get_stylesheet() === $slug || get_template() === $slug ),
		);
		if ( isset( $tt->response[ $slug ]['new_version'] ) ) {
			$row['available'] = (string) $tt->response[ $slug ]['new_version'];
		}
		$themes[] = $row;
	}

	return array(
		'site'           => wp_parse_url( home_url(), PHP_URL_HOST ),
		'engine_version' => defined( 'ZS_FLEET_VERSION' ) ? ZS_FLEET_VERSION : 'unknown',
		'detected_at'    => gmdate( 'c' ),
		'admin_context'  => is_admin(), // false → may undercount is_admin()-gated updaters.
		'wp_version'     => get_bloginfo( 'version' ),
		'php_version'    => PHP_VERSION,
		'plugins'        => $plugins,
		'themes'         => $themes,
	);
}

/**
 * Capture a deterministic schema signature from information_schema (impure — the
 * single $wpdb boundary; the hashing is done by the pure zs_fleet_ue_schema_sig).
 * Vía C: a same-server before/after delta around an apply. Exactly 3 reads, flat
 * regardless of table count (a few ms, dwarfed by the file stash + http probe).
 *
 * BEST-EFFORT, like the http self-probe: any read failure (locked-down host that
 * revokes information_schema, a timeout, a column unsupported on an ancient MySQL)
 * returns '' — never fatals an apply. '' makes the caller ABSTAIN (schema_changed
 * stays null): it must gate/abstain, never silently green-light a file rollback.
 *
 * Scope = this install's own database (TABLE_SCHEMA = DATABASE()) so a plugin that
 * names tables outside the wp_ prefix is still covered — minimising the DANGEROUS
 * false-negative. Every selected column is STRUCTURAL and data-invariant; the
 * volatile counters/row-counts/cardinality are deliberately never read (see the
 * pure helper's contract), so INSERT/ANALYZE traffic cannot move the signature.
 *
 * KNOWN FALSE-NEGATIVE BLIND SPOTS — schema moves this projection cannot see, so a
 * migration confined to any of them flips no signature here: VIEWS, TRIGGERS, stored
 * ROUTINES (procedures/functions), FOREIGN KEY / referential constraints, CHECK
 * constraints, and in-place MySQL-8 changes to a functional index's EXPRESSION or to
 * index VISIBILITY. db_touch_slugs (the curated ∪ learned operator backstop) is what
 * covers these; Vía C narrows that list, it never replaces it.
 *
 * @return string sha1 signature, or '' if any read failed.
 */
function zs_fleet_ue_schema_signature() {
	global $wpdb;
	if ( empty( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
		return '';
	}
	try {
		$scope = 'TABLE_SCHEMA = DATABASE()';

		// COLUMNS: the bulk of the DDL surface. EXTRA carries only the structural
		// 'auto_increment' FLAG, never a counter — invariant to inserts.
		$cols = $wpdb->get_results(
			"SELECT c.TABLE_NAME, c.COLUMN_NAME, c.ORDINAL_POSITION, c.COLUMN_TYPE,
			        c.IS_NULLABLE, c.COLUMN_DEFAULT, c.EXTRA, c.COLLATION_NAME,
			        c.GENERATION_EXPRESSION
			   FROM information_schema.COLUMNS c
			   JOIN information_schema.TABLES t
			     ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
			  WHERE c.{$scope} AND t.TABLE_TYPE = 'BASE TABLE'
			  ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION",
			ARRAY_N
		);
		if ( $cols === null ) {
			return '';
		}

		// STATISTICS (indexes): structural only. CARDINALITY is deliberately NOT
		// selected — it tracks data distribution and moves on INSERT + ANALYZE.
		$idx = $wpdb->get_results(
			"SELECT TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME,
			        NON_UNIQUE, SUB_PART, INDEX_TYPE, NULLABLE, COLLATION
			   FROM information_schema.STATISTICS
			  WHERE {$scope}
			  ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX",
			ARRAY_N
		);
		if ( $idx === null ) {
			return '';
		}

		// TABLES (table-level): engine / row_format / collation only. AUTO_INCREMENT,
		// TABLE_ROWS, DATA_LENGTH, CREATE_TIME, UPDATE_TIME, CHECKSUM all excluded.
		$tbls = $wpdb->get_results(
			"SELECT TABLE_NAME, ENGINE, ROW_FORMAT, TABLE_COLLATION
			   FROM information_schema.TABLES
			  WHERE {$scope} AND TABLE_TYPE = 'BASE TABLE'
			  ORDER BY TABLE_NAME",
			ARRAY_N
		);
		if ( $tbls === null ) {
			return '';
		}

		return zs_fleet_ue_schema_sig( $tbls, $cols, $idx );
	} catch ( \Throwable $e ) {
		return '';
	}
}

/**
 * Convenience (pure over a detect array): just the updatable items, shaped like
 * manifest update entries (type/slug/from/to) so the orchestrator can turn the
 * report straight into a manifest.
 */
function zs_fleet_ue_pending( $detect ) {
	$out = array();
	foreach ( array( 'plugins' => 'plugin', 'themes' => 'theme' ) as $key => $type ) {
		if ( empty( $detect[ $key ] ) || ! is_array( $detect[ $key ] ) ) {
			continue;
		}
		foreach ( $detect[ $key ] as $row ) {
			if ( isset( $row['available'] ) ) {
				$out[] = array(
					'type'   => $type,
					'slug'   => $row['slug'],
					'from'   => $row['current'],
					'to'     => $row['available'],
					'active' => ! empty( $row['active'] ),
				);
			}
		}
	}
	return $out;
}

/* ── Remote pull (egress) + cron ────────────────────────────────────────── */

/** Pull the current signed manifest envelope from the control-plane. */
function zs_fleet_ue_pull_envelope() {
	if ( ZS_FLEET_UE_CONTROL_URL === '' ) {
		return new WP_Error( 'no_control', 'no control-plane configured' );
	}
	$host = rawurlencode( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$url  = trailingslashit( ZS_FLEET_UE_CONTROL_URL ) . 'v1/manifest/' . $host;
	$resp = wp_remote_get(
		$url,
		array(
			'timeout' => 20,
			'headers' => array(
				'User-Agent'      => 'zs-fleet-engine/' . ( defined( 'ZS_FLEET_VERSION' ) ? ZS_FLEET_VERSION : '0' ),
				'X-ZS-Site-Token' => ZS_FLEET_UE_SITE_TOKEN,
			),
		)
	);
	if ( is_wp_error( $resp ) ) {
		return $resp;
	}
	$code = (int) wp_remote_retrieve_response_code( $resp );
	if ( $code !== 200 ) {
		return new WP_Error( 'pull_http', 'manifest pull HTTP ' . $code );
	}
	$env = json_decode( wp_remote_retrieve_body( $resp ), true );
	if ( ! is_array( $env ) ) {
		return new WP_Error( 'pull_json', 'manifest pull body not JSON' );
	}
	// Either a signed envelope {payload,signature} or the {mode:"none"} sentinel.
	return $env;
}

/**
 * Check in with the control-plane (egress): POST the current inventory (detect())
 * plus the last run's report. $report may be null (nothing applied this cycle).
 * The detect() here runs in cron context (admin_context likely false → flagged in
 * the payload); the operator wrapper provides the complete admin-context inventory.
 */
function zs_fleet_ue_checkin( $report = null ) {
	if ( ZS_FLEET_UE_CONTROL_URL === '' ) {
		return;
	}

	// Durable report delivery: a rolled_back / error report is what drives the
	// control-plane's auto-freeze, so it must survive a failed POST. Persist the
	// newest report as 'unacked' and clear it ONLY on a 2xx checkin ack. When this
	// cycle produced no new report, retry any still-unacked one from a prior cycle
	// (with the fresh inventory). Bounded to the single most-recent report — a new
	// report overwrites the old one, no unbounded queue.
	if ( $report !== null ) {
		update_option( ZS_FLEET_UE_OPT_UNACKED, $report, false );
	} else {
		$pending = get_option( ZS_FLEET_UE_OPT_UNACKED, null );
		if ( is_array( $pending ) ) {
			$report = $pending;
		}
	}

	$payload = zs_fleet_ue_detect();
	if ( $report !== null ) {
		$payload['report'] = $report;
	}
	$resp = wp_remote_post(
		trailingslashit( ZS_FLEET_UE_CONTROL_URL ) . 'v1/checkin',
		array(
			'timeout' => 20,
			'headers' => array(
				'Content-Type'    => 'application/json',
				'X-ZS-Site-Token' => ZS_FLEET_UE_SITE_TOKEN,
			),
			'body'    => wp_json_encode( $payload ),
		)
	);
	if ( is_wp_error( $resp ) ) {
		return; // transport failure — unacked report stays for the next cycle.
	}

	$code = (int) wp_remote_retrieve_response_code( $resp );
	if ( $code >= 200 && $code < 300 ) {
		// Acked — the control-plane has the report; safe to drop the retry copy.
		delete_option( ZS_FLEET_UE_OPT_UNACKED );
	}

	// Cadence control: honor a `next_checkin_seconds` the control-plane returns,
	// clamped to [MIN, MAX]. Persist only on a real change so init's reschedule
	// stays idempotent. The schedule itself is re-applied by zs_fleet_ue_schedule().
	$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
	if ( is_array( $body ) && isset( $body['next_checkin_seconds'] ) && is_int( $body['next_checkin_seconds'] ) ) {
		$secs = max( ZS_FLEET_UE_INTERVAL_MIN, min( ZS_FLEET_UE_INTERVAL_MAX, $body['next_checkin_seconds'] ) );
		if ( $secs !== zs_fleet_ue_desired_interval() ) {
			update_option( ZS_FLEET_UE_OPT_INTERVAL, $secs, false );
			// Apply immediately so the next pull lands on the new cadence rather
			// than waiting for the following init.
			zs_fleet_ue_schedule();
		}
	}
}

/** Desired check-in interval (seconds): stored option clamped, default 1 h. */
function zs_fleet_ue_desired_interval() {
	$secs = (int) get_option( ZS_FLEET_UE_OPT_INTERVAL, 0 );
	if ( $secs <= 0 ) {
		return HOUR_IN_SECONDS;
	}
	return max( ZS_FLEET_UE_INTERVAL_MIN, min( ZS_FLEET_UE_INTERVAL_MAX, $secs ) );
}

/** Register the custom 'zs_fleet_ue' cron schedule with the desired interval. */
function zs_fleet_ue_cron_schedules( $schedules ) {
	$secs = zs_fleet_ue_desired_interval();
	$schedules[ ZS_FLEET_UE_SCHEDULE ] = array(
		'interval' => $secs,
		'display'  => 'ZS Fleet check-in (' . $secs . 's)',
	);
	return $schedules;
}

function zs_fleet_ue_cron_run() {
	if ( ! zs_fleet_ue_enabled() || ! zs_fleet_ue_enrolled() ) {
		return; // inert until fully enrolled (control URL + pubkey + site token).
	}
	if ( get_transient( ZS_FLEET_UE_LOCK ) ) {
		return;
	}
	set_transient( ZS_FLEET_UE_LOCK, 1, 10 * MINUTE_IN_SECONDS );

	// Fatal-safety net: a PHP fatal inside Plugin_Upgrader (OOM, timeout, a plugin's
	// own load error) does NOT run the try/finally below — but it DOES fire shutdown
	// functions. Without this, the lock would sit until its 10-min TTL and strand the
	// next cron cycle. Release the lock and drop a durable breadcrumb so the failure
	// is visible and the fleet keeps moving. No-ops on the normal path via the flag.
	$zs_lock_released = false;
	register_shutdown_function(
		function () use ( &$zs_lock_released ) {
			if ( $zs_lock_released ) {
				return; // normal completion already cleaned up in finally.
			}
			$err = error_get_last();
			$is_fatal = is_array( $err ) && in_array(
				$err['type'],
				array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ),
				true
			);
			if ( ! $is_fatal ) {
				return;
			}
			delete_transient( ZS_FLEET_UE_LOCK );
			update_option(
				'zs_fleet_ue_restore_pending',
				array(
					'slug'  => '(unknown)',
					'kind'  => 'fatal_during_cron',
					'stash' => '',
					'at'    => gmdate( 'c' ),
					'error' => isset( $err['message'] ) ? substr( (string) $err['message'], 0, 500 ) : '',
				),
				false
			);
			error_log( '[zs-fleet] CRITICAL fatal during engine cron at ' . home_url() . ': ' . ( isset( $err['message'] ) ? $err['message'] : 'unknown' ) );
		}
	);

	try {
		// Durable retry of a pending sealed onboard secret (delivery net, independent
		// of the manifest that minted it — M1). Runs every enrolled cycle until 2xx-acked.
		zs_fleet_ue_onboard_retry_delivery();

		$env = zs_fleet_ue_pull_envelope();
		if ( is_wp_error( $env ) ) {
			error_log( '[zs-fleet] engine pull: ' . $env->get_error_message() );
			zs_fleet_ue_checkin( null ); // still report inventory.
			return;
		}
		// Nothing-to-do sentinel ({mode:"none"}) — no payload to verify.
		if ( ! isset( $env['payload'], $env['signature'] ) ) {
			zs_fleet_ue_checkin( null );
			return;
		}
		$report = zs_fleet_ue_process_envelope( $env );
		if ( is_wp_error( $report ) ) {
			error_log( '[zs-fleet] engine verify: ' . $report->get_error_message() );
			zs_fleet_ue_checkin( null );
			return;
		}
		zs_fleet_ue_checkin( $report );
	} finally {
		delete_transient( ZS_FLEET_UE_LOCK );
		$zs_lock_released = true; // tell the shutdown net the normal path handled cleanup.
	}
}

/** True only when the site is fully enrolled with the control-plane. */
function zs_fleet_ue_enrolled() {
	return ZS_FLEET_UE_CONTROL_URL !== '' && ZS_FLEET_UE_PUBKEY !== '' && ZS_FLEET_UE_SITE_TOKEN !== '';
}

function zs_fleet_ue_schedule() {
	if ( ! zs_fleet_ue_enabled() || ! zs_fleet_ue_enrolled() ) {
		wp_clear_scheduled_hook( ZS_FLEET_UE_HOOK );
		return;
	}

	$secs = zs_fleet_ue_desired_interval();

	// Default / unset → keep the original 'hourly' behavior (no custom schedule).
	if ( $secs === HOUR_IN_SECONDS ) {
		$current = wp_get_schedule( ZS_FLEET_UE_HOOK );
		if ( $current === false ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', ZS_FLEET_UE_HOOK );
		} elseif ( $current !== 'hourly' ) {
			// A previously custom cadence reverted to default — switch back to hourly.
			wp_clear_scheduled_hook( ZS_FLEET_UE_HOOK );
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', ZS_FLEET_UE_HOOK );
		}
		return;
	}

	// Non-default interval → use the custom 'zs_fleet_ue' schedule. Reschedule only
	// when the cadence actually changed (hook not yet on the custom schedule, OR the
	// interval baked into the existing event differs from the desired one), so init
	// doesn't thrash on every load. WP captures the interval into the event at
	// schedule time; the cron_schedules filter reads it live, so we compare against
	// the recorded recurrence to know whether a refresh is needed.
	$current = wp_get_schedule( ZS_FLEET_UE_HOOK );
	$event   = function_exists( 'wp_get_scheduled_event' ) ? wp_get_scheduled_event( ZS_FLEET_UE_HOOK ) : false;
	$baked   = ( $event && isset( $event->interval ) ) ? (int) $event->interval : 0;
	if ( $current === ZS_FLEET_UE_SCHEDULE && $baked === $secs ) {
		return; // already on the custom schedule at the right interval.
	}
	wp_clear_scheduled_hook( ZS_FLEET_UE_HOOK );
	wp_schedule_event( time() + $secs, ZS_FLEET_UE_SCHEDULE, ZS_FLEET_UE_HOOK );
}

/**
 * Local entrypoint for step-1 shadow validation WITHOUT a control-plane.
 * Feed a raw (unsigned) manifest array and a mode; bypasses the signature gate
 * but keeps every other gate (shape, site, expiry-less). For operator use via
 * `wp eval` on ZERO sites only. Never wired to a request.
 *
 *   wp eval 'echo json_encode( zs_fleet_ue_run_local( $m, "shadow" ) );'
 */
function zs_fleet_ue_run_local( $manifest, $mode = 'shadow' ) {
	$manifest['mode']             = $mode;
	$manifest['manifest_version'] = isset( $manifest['manifest_version'] ) ? $manifest['manifest_version'] : 1;
	$manifest['site']             = isset( $manifest['site'] ) ? $manifest['site'] : wp_parse_url( home_url(), PHP_URL_HOST );
	$manifest['nonce']            = isset( $manifest['nonce'] ) ? $manifest['nonce'] : 'local-' . time();
	$manifest['expires_at']       = isset( $manifest['expires_at'] ) ? $manifest['expires_at'] : gmdate( 'c', time() + 600 );
	$shape                        = zs_fleet_ue_validate_shape( $manifest );
	if ( $shape !== '' ) {
		return new WP_Error( 'bad_shape', $shape );
	}
	$report = zs_fleet_ue_run( $manifest );
	update_option( ZS_FLEET_UE_OPT_REPORT, $report, false );
	return $report;
}

/* ── Recursive rmdir (mirrors auto-update.php's helper) ──────────────────── */
function zs_fleet_ue_rrmdir( $path ) {
	if ( ! file_exists( $path ) && ! is_link( $path ) ) {
		return;
	}
	if ( is_file( $path ) || is_link( $path ) ) {
		@unlink( $path );
		return;
	}
	$entries = scandir( $path );
	if ( $entries ) {
		foreach ( $entries as $f ) {
			if ( $f === '.' || $f === '..' ) {
				continue;
			}
			zs_fleet_ue_rrmdir( $path . '/' . $f );
		}
	}
	@rmdir( $path );
}
