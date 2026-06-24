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
 * With no control-plane configured (ZS_FLEET_UE_CONTROL_URL empty) and no
 * public key (ZS_FLEET_UE_PUBKEY empty), the cron path is a no-op: the engine
 * pulls nothing and applies nothing. This module can therefore ride a normal
 * release to the whole fleet and stay dormant until a site is explicitly
 * enrolled (step 3 of the migration). Until then it is reachable only via the
 * local entrypoint (zs_fleet_ue_run_local) for shadow validation on ZERO.
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
	// base64 Ed25519 public key. Empty until step 3 keygen → engine stays inert.
	define( 'ZS_FLEET_UE_PUBKEY', '' );
}
if ( ! defined( 'ZS_FLEET_UE_CONTROL_URL' ) ) {
	// Control-plane base URL (no trailing slash). Empty → no remote pull.
	define( 'ZS_FLEET_UE_CONTROL_URL', '' );
}
if ( ! defined( 'ZS_FLEET_UE_CLOCK_SKEW' ) ) {
	define( 'ZS_FLEET_UE_CLOCK_SKEW', 120 ); // seconds of tolerance on expiry.
}

const ZS_FLEET_UE_HOOK          = 'zs_fleet_ue_pull';
const ZS_FLEET_UE_LOCK          = 'zs_fleet_ue_lock';
const ZS_FLEET_UE_OPT_NONCES    = 'zs_fleet_ue_consumed_nonces'; // replay guard
const ZS_FLEET_UE_OPT_REPORT    = 'zs_fleet_ue_last_report';
const ZS_FLEET_UE_STASH_SUBDIR  = 'zs-fleet-stash';             // under wp-content. NOT under upgrade/ — WP_Upgrader::unpack_package() wipes EVERY child of wp-content/upgrade/ on each run, which would delete our rollback copy mid-flight.
const ZS_FLEET_UE_STASH_KEEP    = 3;                              // retain N stashes/slug

add_action( 'init', 'zs_fleet_ue_schedule' );
add_action( ZS_FLEET_UE_HOOK, 'zs_fleet_ue_cron_run' );

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
			$parts[] = json_encode( (string) $k ) . ':' . zs_fleet_ue_canonical_json( $v );
		}
		return '{' . implode( ',', $parts ) . '}';
	}
	if ( is_bool( $data ) || is_int( $data ) || is_float( $data ) || is_null( $data ) ) {
		return json_encode( $data );
	}
	return json_encode( (string) $data );
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
	if ( ! in_array( $m['mode'], array( 'shadow', 'apply' ), true ) ) {
		return 'invalid mode';
	}
	if ( ! is_array( $m['updates'] ) ) {
		return 'updates not a list';
	}
	foreach ( $m['updates'] as $i => $u ) {
		foreach ( array( 'type', 'slug', 'from', 'to' ) as $k ) {
			if ( ! isset( $u[ $k ] ) || ! is_string( $u[ $k ] ) || $u[ $k ] === '' ) {
				return "update[{$i}] missing/invalid: {$k}";
			}
		}
		// slug must be a bare folder name — no traversal, no separators, never a
		// pure-dot string ('.', '..') that would resolve to the plugins root.
		if ( ! zs_fleet_ue_safe_slug( $u['slug'] ) ) {
			return "update[{$i}] unsafe slug: {$u['slug']}";
		}
		if ( ! in_array( $u['type'], array( 'plugin' ), true ) ) {
			// v2 step 1 handles plugins only. theme/core come later, gated.
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
	$http_ok    = ( (int) $http_after === 200 );
	if ( $version_ok && $active_ok && $http_ok && $fingerprint_ok ) {
		return 'applied';
	}
	return 'verify_fail';
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
	for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
		$url   = add_query_arg( 'zs_fleet_probe', (string) ( time() + $attempt ), home_url( '/' ) );
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
		if ( $code === 200 ) {
			break;
		}
		if ( $attempt < 3 ) {
			usleep( 400000 ); // brief backoff before retrying a non-200.
		}
	}
	return array( $code, $secs, $body );
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
function zs_fleet_ue_live_path( $slug ) {
	if ( ! zs_fleet_ue_safe_slug( $slug ) ) {
		return new WP_Error( 'unsafe_slug', "unsafe slug: {$slug}" );
	}
	$root = realpath( WP_PLUGIN_DIR );
	if ( $root === false ) {
		return new WP_Error( 'no_plugin_dir', 'WP_PLUGIN_DIR not resolvable' );
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
function zs_fleet_ue_stash_plugin( $slug, $version ) {
	$base = zs_fleet_ue_stash_base();
	if ( is_wp_error( $base ) ) {
		return $base;
	}
	$live = zs_fleet_ue_live_path( $slug );
	if ( is_wp_error( $live ) ) {
		return $live;
	}
	if ( ! is_dir( $live ) ) {
		return new WP_Error( 'stash_no_live', "live plugin dir missing: {$live}" );
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
function zs_fleet_ue_restore_plugin( $slug, $stash ) {
	if ( ! is_dir( $stash ) ) {
		return false; // stash missing — caller treats as unrecoverable.
	}
	$live = zs_fleet_ue_live_path( $slug );
	if ( is_wp_error( $live ) ) {
		return false;
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	global $wp_filesystem;

	$tmp = $live . '.zs-new';
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
	$broken = $live . '.zs-broken-' . time();
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

	$healthy = ( $code === 200 ) && $fp_ok
		&& ( (string) $ver_after === (string) $from )
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

	$verdict = zs_fleet_ue_classify( $to, $ver_after, $active_before, $active_after, $code, $fp_ok );

	if ( $verdict === 'applied' ) {
		$row['outcome'] = 'applied';
		zs_fleet_ue_prune_stashes( $slug );
		return $row;
	}

	// ── verify failed → ROLLBACK ──
	// Conservative for unattended autonomy: a persistent non-200 (after the
	// probe's own retries) triggers rollback. A needlessly-rolled-back update
	// is safe-but-stale and the cockpit/VRT re-adjudicates it next cycle; a site
	// left broken is not recoverable without intervention. db-touch has no stash.
	if ( $touches_db ) {
		$row['outcome'] = 'error';
		$row['message'] .= 'verify failed and no rollback available (db-touch).';
		return $row;
	}
	zs_fleet_ue_rollback( $row, $slug, $pf, isset( $stash ) ? $stash : '', $from, $active_before, 'verify failed →' );
	return $row;
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
	$results = array();
	foreach ( $manifest['updates'] as $update ) {
		$results[] = zs_fleet_ue_apply_one( $update, $mode );
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
function zs_fleet_ue_process_envelope( $envelope ) {
	$manifest = zs_fleet_ue_verify_envelope( $envelope, ZS_FLEET_UE_PUBKEY );
	if ( is_wp_error( $manifest ) ) {
		return $manifest;
	}
	$shape = zs_fleet_ue_validate_shape( $manifest );
	if ( $shape !== '' ) {
		return new WP_Error( 'bad_shape', $shape );
	}
	// Site binding: a manifest can only ever run on its named site.
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	if ( strcasecmp( (string) $manifest['site'], (string) $host ) !== 0 ) {
		return new WP_Error( 'wrong_site', "manifest for {$manifest['site']} delivered to {$host}" );
	}
	if ( ! zs_fleet_ue_not_expired( $manifest['expires_at'], time() ) ) {
		return new WP_Error( 'expired', 'manifest expired' );
	}
	// Replay guard: refuse a consumed nonce (apply mode only — shadow is idempotent).
	if ( $manifest['mode'] === 'apply' && zs_fleet_ue_nonce_consumed( $manifest['nonce'] ) ) {
		return new WP_Error( 'replay', 'nonce already consumed' );
	}

	$report = zs_fleet_ue_run( $manifest );

	if ( $manifest['mode'] === 'apply' ) {
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
			'headers' => array( 'User-Agent' => 'zs-fleet-engine/' . ( defined( 'ZS_FLEET_VERSION' ) ? ZS_FLEET_VERSION : '0' ) ),
		)
	);
	if ( is_wp_error( $resp ) ) {
		return $resp;
	}
	if ( (int) wp_remote_retrieve_response_code( $resp ) !== 200 ) {
		return new WP_Error( 'pull_http', 'manifest pull HTTP ' . wp_remote_retrieve_response_code( $resp ) );
	}
	$env = json_decode( wp_remote_retrieve_body( $resp ), true );
	if ( ! is_array( $env ) ) {
		return new WP_Error( 'pull_json', 'manifest pull body not JSON' );
	}
	return $env;
}

/** Report the result back to the control-plane (egress). */
function zs_fleet_ue_report_remote( $report ) {
	if ( ZS_FLEET_UE_CONTROL_URL === '' ) {
		return;
	}
	wp_remote_post(
		trailingslashit( ZS_FLEET_UE_CONTROL_URL ) . 'v1/checkin',
		array(
			'timeout' => 20,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $report ),
		)
	);
}

function zs_fleet_ue_cron_run() {
	if ( ! zs_fleet_ue_enabled() || ZS_FLEET_UE_CONTROL_URL === '' || ZS_FLEET_UE_PUBKEY === '' ) {
		return; // inert until enrolled.
	}
	if ( get_transient( ZS_FLEET_UE_LOCK ) ) {
		return;
	}
	set_transient( ZS_FLEET_UE_LOCK, 1, 10 * MINUTE_IN_SECONDS );
	try {
		$env = zs_fleet_ue_pull_envelope();
		if ( is_wp_error( $env ) ) {
			return;
		}
		$report = zs_fleet_ue_process_envelope( $env );
		if ( is_wp_error( $report ) ) {
			error_log( '[zs-fleet] engine: ' . $report->get_error_message() );
			return;
		}
		zs_fleet_ue_report_remote( $report );
	} finally {
		delete_transient( ZS_FLEET_UE_LOCK );
	}
}

function zs_fleet_ue_schedule() {
	if ( ! zs_fleet_ue_enabled() || ZS_FLEET_UE_CONTROL_URL === '' ) {
		wp_clear_scheduled_hook( ZS_FLEET_UE_HOOK );
		return;
	}
	if ( ! wp_next_scheduled( ZS_FLEET_UE_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', ZS_FLEET_UE_HOOK );
	}
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
