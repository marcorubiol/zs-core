<?php
/**
 * Module: Auto-Update
 *
 * Pulls new releases of zs-fleet from GitHub and applies them
 * atomically. Runs daily via WP_Cron.
 *
 * Why: 21+ sites in the fleet. Manual updates per site doesn't scale.
 * GitHub is the source of truth; each site polls for new releases
 * daily and pulls them down.
 *
 * Source channel: the "stable redirect" URL
 *   github.com/<repo>/releases/latest/download/zs-fleet.zip
 * which GitHub redirects to the asset of the latest stable release
 * (prereleases excluded). No GitHub API → no auth → no rate limits.
 *
 * Update layout: each release zip contains
 *
 *   zs-fleet/                ← what this module replaces
 *     zs-fleet.php
 *     modules/...
 *   zs-fleet-loader.php      ← NOT touched by self-update
 *
 * The loader stays put forever. Any change to the loader requires
 * a manual re-deploy.
 *
 * Version comparison: download the zip, extract to a temp dir,
 * parse the `Version:` header from the extracted zs-fleet.php, and
 * compare with ZS_FLEET_VERSION. If equal-or-older, discard the
 * temp dir. If newer, atomically rename it into place. ~11KB
 * download per site per day — bandwidth is irrelevant.
 *
 * Atomic swap: extract to mu-plugins/zs-fleet.new/, rename live
 * away, rename new in place, delete the stash. Brief window where
 * the directory is missing — the loader's file_exists check
 * silently no-ops in that window so WP keeps booting.
 *
 * Per-site opt-out:
 *
 *   add_filter( 'zs_fleet_auto_update_enabled', '__return_false' );
 *
 * Useful to pin a site at a known-good version while debugging or
 * to disable on dev/staging environments.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const ZS_FLEET_AU_ZIP_URL     = 'https://github.com/marcorubiol/zs-core/releases/latest/download/zs-fleet.zip';
// Detached Ed25519 signature of the exact release zip, published as a sibling
// release asset (base64 of the 64-byte signature). See deploy/sign-release.php.
const ZS_FLEET_AU_SIG_URL     = ZS_FLEET_AU_ZIP_URL . '.sig';
const ZS_FLEET_AU_HOOK        = 'zs_fleet_auto_update_check';
const ZS_FLEET_AU_OPT_LAST    = 'zs_fleet_au_last_check';
const ZS_FLEET_AU_OPT_SUCCESS = 'zs_fleet_au_last_success';
const ZS_FLEET_AU_OPT_ERROR   = 'zs_fleet_au_last_error';
const ZS_FLEET_AU_LOCK        = 'zs_fleet_au_lock';

// Optional shared secret for the on-demand trigger (GET /?zs_fleet_check_now=1&zs_fleet_secret=…).
// Override in wp-config.php to let an unauthenticated CI/push script fire the check;
// empty → the trigger requires a logged-in manage_options operator instead.
if ( ! defined( 'ZS_FLEET_AU_TRIGGER_SECRET' ) ) {
	define( 'ZS_FLEET_AU_TRIGGER_SECRET', '' );
}

add_action( 'init', 'zs_fleet_au_schedule' );
add_action( 'init', 'zs_fleet_au_handle_push_trigger', 5 );
add_action( ZS_FLEET_AU_HOOK, 'zs_fleet_au_run' );

/**
 * Public on-demand trigger: GET /?zs_fleet_check_now=1
 *
 * Lets the operator (or a CI script) push a new release to the
 * fleet without waiting up to 24h for the daily WP_Cron to fire.
 * After tagging a new version, hit this URL on each fleet site —
 * within seconds the site downloads, compares, and swaps.
 *
 * Auth: the swap itself is now signature-gated (fail-closed — see
 * zs_fleet_au_verify_zip_signature), so the trigger is not an RCE
 * vector. But an open trigger still lets anyone force the
 * download/compare cycle and hammer GitHub, so we gate it: a
 * logged-in manage_options operator, OR a matching shared secret
 * (?zs_fleet_secret=…, constant-time compared). Anything else → 403.
 * The internal 5-minute transient lock still caps real executions.
 *
 * Output is plain text so a shell script can grep the result:
 *
 *   version_before: 0.1.5
 *   version_after:  0.1.6
 *   updated:        yes
 */
function zs_fleet_au_handle_push_trigger() {
	if ( ! isset( $_GET['zs_fleet_check_now'] ) ) {
		return;
	}
	if ( ! zs_fleet_au_trigger_authorized() ) {
		status_header( 403 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo "forbidden\n";
		exit;
	}
	if ( ! apply_filters( 'zs_fleet_auto_update_enabled', true ) ) {
		status_header( 403 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo "auto-update disabled at this site\n";
		exit;
	}

	nocache_headers();
	header( 'Content-Type: text/plain; charset=utf-8' );

	$before = ZS_FLEET_VERSION;
	zs_fleet_au_run();

	// Re-read the on-disk version. If the swap just happened, this
	// will reflect the newly installed bootstrap.
	$after_path = WPMU_PLUGIN_DIR . '/zs-fleet/zs-fleet.php';
	$after      = '';
	if ( file_exists( $after_path ) ) {
		$contents = @file_get_contents( $after_path, false, null, 0, 2048 );
		if ( $contents && preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $contents, $m ) ) {
			$after = $m[1];
		}
	}

	echo "version_before: {$before}\n";
	echo 'version_after:  ' . ( $after ?: '(unknown)' ) . "\n";
	echo 'updated:        ' . ( $after && $after !== $before ? 'yes' : 'no' ) . "\n";

	$err = get_option( ZS_FLEET_AU_OPT_ERROR );
	if ( is_array( $err ) && ! empty( $err['msg'] ) ) {
		echo "last_error:     {$err['msg']}\n";
	}
	exit;
}

/**
 * Authorize the on-demand trigger: a logged-in manage_options operator, or a
 * matching shared secret (constant-time compared). No secret configured → only
 * logged-in operators. Never leaks timing about the secret via hash_equals.
 */
function zs_fleet_au_trigger_authorized() {
	if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
		return true;
	}
	$secret = defined( 'ZS_FLEET_AU_TRIGGER_SECRET' ) ? (string) ZS_FLEET_AU_TRIGGER_SECRET : '';
	if ( $secret === '' ) {
		return false;
	}
	$given = isset( $_GET['zs_fleet_secret'] ) ? (string) wp_unslash( $_GET['zs_fleet_secret'] ) : '';
	if ( $given === '' ) {
		return false;
	}
	return hash_equals( $secret, $given );
}

function zs_fleet_au_schedule() {
	if ( ! apply_filters( 'zs_fleet_auto_update_enabled', true ) ) {
		// Disabled at this site — clear any existing schedule so we
		// stop polling. Re-enabling re-schedules on next pageload.
		wp_clear_scheduled_hook( ZS_FLEET_AU_HOOK );
		return;
	}
	if ( ! wp_next_scheduled( ZS_FLEET_AU_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', ZS_FLEET_AU_HOOK );
	}
}

function zs_fleet_au_run() {
	if ( ! apply_filters( 'zs_fleet_auto_update_enabled', true ) ) {
		return;
	}

	// Single-run lock — if a previous cron is still in flight (slow
	// download, big zip), don't start a second one on top of it.
	if ( get_transient( ZS_FLEET_AU_LOCK ) ) {
		return;
	}
	set_transient( ZS_FLEET_AU_LOCK, 1, 5 * MINUTE_IN_SECONDS );

	update_option( ZS_FLEET_AU_OPT_LAST, time(), false );

	try {
		$result = zs_fleet_au_check_and_apply();
		if ( is_wp_error( $result ) ) {
			zs_fleet_au_log_error( $result->get_error_message() );
			return;
		}
		if ( $result === false ) {
			// Already current — not an error, not a successful update. Clear any stale
			// error: a site that has REACHED the current version has, by definition, no
			// outstanding auto-update failure. Without this, delete_option only ran on the
			// success path below, so a single transient blip (a release whose .sig lands
			// minutes after its zip, a network hiccup) stuck in zs_fleet_au_last_error
			// forever on an otherwise-healthy site — and resurfaced in the read-back of
			// every subsequent au-push, where it had to be cleared by hand.
			delete_option( ZS_FLEET_AU_OPT_ERROR );
			return;
		}
		// $result is the version string we just installed.
		update_option( ZS_FLEET_AU_OPT_SUCCESS, time(), false );
		delete_option( ZS_FLEET_AU_OPT_ERROR );
		error_log( '[zs-fleet] auto-updated v' . ZS_FLEET_VERSION . " → v{$result}" );
	} finally {
		delete_transient( ZS_FLEET_AU_LOCK );
	}
}

function zs_fleet_au_check_and_apply() {
	require_once ABSPATH . 'wp-admin/includes/file.php';

	zs_fleet_au_sweep_stale_swaps();

	$tmp_zip = wp_tempnam( 'zs-fleet-check.zip' );
	$dl      = wp_remote_get(
		ZS_FLEET_AU_ZIP_URL,
		array(
			'timeout'     => 60,
			'stream'      => true,
			'filename'    => $tmp_zip,
			'redirection' => 5,
			'headers'     => array( 'User-Agent' => 'zs-fleet auto-updater' ),
		)
	);
	if ( is_wp_error( $dl ) ) {
		@unlink( $tmp_zip );
		return $dl;
	}
	$code = wp_remote_retrieve_response_code( $dl );
	if ( $code !== 200 ) {
		@unlink( $tmp_zip );
		return new WP_Error( 'download_http', "zip download HTTP {$code}" );
	}

	// ── Release signature gate (fail-closed) ──────────────────────────────────
	// Verify a detached Ed25519 signature over the EXACT downloaded zip bytes
	// BEFORE we ever unzip / extract / swap. This is what turns a blind
	// "fetch code from a URL and run it" into a verified self-update. Reuses the
	// manifest keypair (ZS_FLEET_UE_PUBKEY, defined in update-engine.php): the
	// private key lives only in the control-plane Worker; releases are signed
	// locally at release time (deploy/sign-release.php) and the .sig published as
	// a release asset. Domain-separated ("ZS-FLEET-RELEASE"\0) so a manifest
	// signature can never be replayed as a release signature or vice-versa.
	//
	// Backward-compat reality: the CURRENTLY-live engine performs the download for
	// the NEXT update, so this gate only protects updates issued AFTER a
	// sig-checking build (v0.3.x) is live. Once the fleet runs this version, EVERY
	// release MUST ship a valid .sig or the fleet stops self-updating — fails
	// closed, by design.
	$sig_check = zs_fleet_au_verify_zip_signature( $tmp_zip );
	if ( is_wp_error( $sig_check ) ) {
		@unlink( $tmp_zip );
		error_log( '[zs-fleet] CRITICAL: release signature verification FAILED - refusing self-update (' . $sig_check->get_error_message() . ')' );
		return $sig_check;
	}

	// Stage extraction on the SAME filesystem as the live mu-plugin dir so the install
	// is an atomic same-fs rename. get_temp_dir() is often a different mount (e.g. on
	// shared hosts like Hostinger) → the install rename() fails with EXDEV and the swap
	// aborts + rolls back, so the site never self-updates. A dot-prefixed staging dir
	// under WPMU_PLUGIN_DIR is not scanned by WordPress (top-level *.php only) and is
	// removed on every path below. Mode 0700, not 0755: that dot prefix hides it from
	// WordPress, not from the web server — mu-plugins/ is inside the docroot
	// (incident 2026-08-23).
	$tmp_dir = trailingslashit( WPMU_PLUGIN_DIR ) . '.zs-fleet-stage-' . uniqid();
	if ( ! mkdir( $tmp_dir, 0700, true ) ) {
		@unlink( $tmp_zip );
		return new WP_Error( 'mkdir_tmp', 'could not create extraction dir' );
	}

	WP_Filesystem();
	$unzip = unzip_file( $tmp_zip, $tmp_dir );
	@unlink( $tmp_zip );
	if ( is_wp_error( $unzip ) ) {
		zs_fleet_au_rrmdir( $tmp_dir );
		return $unzip;
	}

	$extracted = $tmp_dir . '/zs-fleet';
	if ( ! is_dir( $extracted ) || ! file_exists( $extracted . '/zs-fleet.php' ) ) {
		zs_fleet_au_rrmdir( $tmp_dir );
		return new WP_Error( 'bad_zip', 'extracted zip is malformed' );
	}

	$remote_version = zs_fleet_au_read_version( $extracted . '/zs-fleet.php' );
	if ( ! $remote_version ) {
		zs_fleet_au_rrmdir( $tmp_dir );
		return new WP_Error( 'bad_zip', 'no Version header in extracted bootstrap' );
	}

	if ( version_compare( $remote_version, ZS_FLEET_VERSION, '<=' ) ) {
		// Already current — discard the temp dir, no swap.
		zs_fleet_au_rrmdir( $tmp_dir );
		return false;
	}

	$live = WPMU_PLUGIN_DIR . '/zs-fleet';
	// Dot-prefixed AND locked down the moment it exists. This is a full copy of the
	// PREVIOUS agency plugin, and mu-plugins/ is inside the docroot: the happy path
	// deletes it three lines down, but a fatal or a timeout in between used to leave
	// it publicly readable forever (incident 2026-08-23 — the engine-stash exposure,
	// same class). Stays inside WPMU_PLUGIN_DIR so the rename is same-fs (the
	// Hostinger EXDEV constraint documented above the staging dir).
	$stash = WPMU_PLUGIN_DIR . "/.zs-fleet.old-{$remote_version}";
	$mode  = @fileperms( $live );
	$mode  = ( $mode === false ) ? 0755 : ( $mode & 0777 );

	if ( ! @rename( $live, $stash ) ) {
		zs_fleet_au_rrmdir( $tmp_dir );
		return new WP_Error( 'swap_stash', 'could not stash live zs-fleet directory' );
	}
	@chmod( $stash, 0700 );
	if ( ! @rename( $extracted, $live ) ) {
		// Roll back: restore the original mode first — we just locked the dir down and
		// it is about to be the LIVE plugin again.
		@chmod( $stash, $mode );
		@rename( $stash, $live );
		zs_fleet_au_rrmdir( $tmp_dir );
		return new WP_Error( 'swap_install', 'could not install new zs-fleet, rolled back' );
	}
	zs_fleet_au_rrmdir( $stash );
	zs_fleet_au_rrmdir( $tmp_dir );

	return $remote_version;
}

/**
 * Verify the detached Ed25519 signature of a downloaded release zip. FAIL-CLOSED:
 * returns WP_Error (never swaps) on ANY doubt — sodium missing, no/empty pubkey,
 * malformed key, sig download != 200, malformed sig, or a verify miss.
 *
 * Message is domain-separated over the raw zip bytes:
 *   "ZS-FLEET-RELEASE" . chr(0) . sha256(zip_bytes, raw)
 * matching deploy/sign-release.php and the control-plane signer.
 *
 * @param string $zip_path absolute path to the downloaded zip on disk.
 * @return true|WP_Error
 */
function zs_fleet_au_verify_zip_signature( $zip_path ) {
	if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
		return new WP_Error( 'sig_verify', 'libsodium unavailable — refusing unsigned self-update' );
	}
	// Reuse the engine's release/manifest public key. Do NOT hardcode a second copy.
	if ( ! defined( 'ZS_FLEET_UE_PUBKEY' ) || ZS_FLEET_UE_PUBKEY === '' ) {
		return new WP_Error( 'sig_verify', 'no release public key configured (ZS_FLEET_UE_PUBKEY)' );
	}
	$pubkey = base64_decode( ZS_FLEET_UE_PUBKEY, true );
	if ( $pubkey === false || strlen( $pubkey ) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ) {
		return new WP_Error( 'sig_verify', 'release public key malformed' );
	}

	$sig_resp = wp_remote_get(
		ZS_FLEET_AU_SIG_URL,
		array(
			'timeout'     => 30,
			'redirection' => 5,
			'headers'     => array( 'User-Agent' => 'zs-fleet auto-updater (sig)' ),
		)
	);
	if ( is_wp_error( $sig_resp ) ) {
		return new WP_Error( 'sig_verify', 'signature download error: ' . $sig_resp->get_error_message() );
	}
	if ( (int) wp_remote_retrieve_response_code( $sig_resp ) !== 200 ) {
		return new WP_Error( 'sig_verify', 'signature download HTTP ' . wp_remote_retrieve_response_code( $sig_resp ) );
	}
	$sig = base64_decode( trim( (string) wp_remote_retrieve_body( $sig_resp ) ), true );
	if ( $sig === false || strlen( $sig ) !== SODIUM_CRYPTO_SIGN_BYTES ) {
		return new WP_Error( 'sig_verify', 'signature malformed' );
	}

	$zip_bytes = @file_get_contents( $zip_path );
	if ( $zip_bytes === false || $zip_bytes === '' ) {
		return new WP_Error( 'sig_verify', 'could not read downloaded zip for hashing' );
	}
	$message = 'ZS-FLEET-RELEASE' . chr( 0 ) . hash( 'sha256', $zip_bytes, true );

	if ( sodium_crypto_sign_verify_detached( $sig, $message, $pubkey ) !== true ) {
		return new WP_Error( 'sig_verify', 'signature does not match downloaded zip' );
	}
	return true;
}

function zs_fleet_au_read_version( $bootstrap_path ) {
	$contents = @file_get_contents( $bootstrap_path, false, null, 0, 2048 );
	if ( ! $contents ) {
		return '';
	}
	if ( preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $contents, $m ) ) {
		return $m[1];
	}
	return '';
}

function zs_fleet_au_log_error( $msg ) {
	update_option(
		ZS_FLEET_AU_OPT_ERROR,
		array(
			'time' => time(),
			'msg'  => $msg,
		),
		false
	);
	error_log( '[zs-fleet] auto-update error: ' . $msg );

	// Escalation: warn loudly if we haven't successfully updated in
	// over a week. Quietly failing for months would mean the fleet
	// drifts behind without anyone noticing.
	$last = (int) get_option( ZS_FLEET_AU_OPT_SUCCESS, 0 );
	if ( $last && ( time() - $last > 7 * DAY_IN_SECONDS ) ) {
		error_log(
			'[zs-fleet] CRITICAL: no successful auto-update in >7 days at ' . home_url()
		);
	}
}

/**
 * Remove leftovers of a crashed self-update: the aside-renamed copy of the previous
 * zs-fleet. The swap removes its own scratch on every RETURN path, but a PHP fatal
 * or a timeout between the rename and the rrmdir leaves the copy behind for good.
 *
 * Called at the TOP of every check, so the ordinary already-current daily run
 * sweeps too — a leftover must not have to wait for the next real update.
 * Both namings are swept: the un-prefixed one is what the fleet is carrying today.
 */
function zs_fleet_au_sweep_stale_swaps() {
	$patterns = array(
		WPMU_PLUGIN_DIR . '/.zs-fleet.old-*',
		WPMU_PLUGIN_DIR . '/zs-fleet.old-*', // pre-2026-08-23 naming, still on disk out there.
	);
	foreach ( $patterns as $pattern ) {
		$stale = glob( $pattern, GLOB_ONLYDIR );
		if ( ! $stale ) {
			continue;
		}
		foreach ( $stale as $dir ) {
			zs_fleet_au_rrmdir( $dir );
		}
	}
}

function zs_fleet_au_rrmdir( $path ) {
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
			zs_fleet_au_rrmdir( $path . '/' . $f );
		}
	}
	@rmdir( $path );
}
