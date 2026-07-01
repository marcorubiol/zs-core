<?php
/**
 * Plugin Name: ZS Core
 * Description: Self-bootstrapping loader. On first request, if zs-fleet/ is missing, fetches the latest release from GitHub and installs it. Thereafter acts as the flat-to-subdirectory bridge.
 * Version:     0.2.2
 * Author:      Zerø Sense
 * Author URI:  https://zerosense.studio
 * License:     GPL-2.0-or-later
 *
 * WordPress only auto-loads top-level *.php files in wp-content/mu-plugins/,
 * not files inside subdirectories. This 1-purpose loader sits at the
 * top level and require_once's the actual zs-fleet bootstrap from
 * wp-content/mu-plugins/zs-fleet/zs-fleet.php.
 *
 * Self-bootstrap:
 *   If zs-fleet/zs-fleet.php is missing, this file schedules a
 *   wp_loaded callback that fetches the latest GitHub release zip,
 *   extracts it, and drops zs-fleet/ in place. After that, every
 *   subsequent request sees zs-fleet/ present and behaves as before.
 *
 *   The fetch URL is the asset-redirect form
 *     github.com/<repo>/releases/latest/download/zs-fleet.zip
 *   which GitHub redirects to the actual asset of the latest stable
 *   release (prereleases excluded). No GitHub API call → no auth →
 *   no rate limits.
 *
 *   Failure modes (network down, GitHub 5xx, zip corrupt) are
 *   logged to error_log and retried on the next request — there is
 *   no permanent fail state. A flock prevents concurrent installs
 *   on busy sites from racing each other.
 *
 * Fleet rollout: drop ONLY this file in mu-plugins/. Visit the site
 * once. Done — zs-fleet installs itself, then auto-update keeps it
 * current daily.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const ZS_FLEET_LOADER_ZIP_URL = 'https://github.com/marcorubiol/zs-core/releases/latest/download/zs-fleet.zip';
// Detached Ed25519 signature of the release zip (base64 of 64 raw bytes),
// published as a sibling release asset. See deploy/sign-release.php.
const ZS_FLEET_LOADER_SIG_URL = ZS_FLEET_LOADER_ZIP_URL . '.sig';

$zs_fleet_bootstrap = __DIR__ . '/zs-fleet/zs-fleet.php';
if ( file_exists( $zs_fleet_bootstrap ) ) {
	require_once $zs_fleet_bootstrap;
	unset( $zs_fleet_bootstrap );
} else {
	unset( $zs_fleet_bootstrap );
	add_action( 'wp_loaded', 'zs_fleet_loader_first_install' );
}

function zs_fleet_loader_first_install() {
	$mu_dir    = WPMU_PLUGIN_DIR;
	$bootstrap = $mu_dir . '/zs-fleet/zs-fleet.php';
	if ( file_exists( $bootstrap ) ) {
		// Another request beat us to it between init and wp_loaded.
		return;
	}

	$lock_path = $mu_dir . '/.zs-fleet-install.lock';
	$lock_fp   = @fopen( $lock_path, 'c' );
	if ( ! $lock_fp || ! @flock( $lock_fp, LOCK_EX | LOCK_NB ) ) {
		if ( $lock_fp ) {
			fclose( $lock_fp );
		}
		return;
	}

	try {
		zs_fleet_loader_do_install( $mu_dir );
	} finally {
		@flock( $lock_fp, LOCK_UN );
		fclose( $lock_fp );
		@unlink( $lock_path );
	}
}

function zs_fleet_loader_do_install( $mu_dir ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';

	$tmp_zip = wp_tempnam( 'zs-fleet-install.zip' );
	$dl      = wp_remote_get(
		ZS_FLEET_LOADER_ZIP_URL,
		array(
			'timeout'     => 60,
			'stream'      => true,
			'filename'    => $tmp_zip,
			'redirection' => 5,
			'headers'     => array( 'User-Agent' => 'zs-fleet loader bootstrap (' . home_url() . ')' ),
		)
	);
	if ( is_wp_error( $dl ) ) {
		@unlink( $tmp_zip );
		error_log( '[zs-fleet] bootstrap: download error: ' . $dl->get_error_message() );
		return;
	}
	$code = wp_remote_retrieve_response_code( $dl );
	if ( $code !== 200 ) {
		@unlink( $tmp_zip );
		error_log( "[zs-fleet] bootstrap: zip download HTTP {$code}" );
		return;
	}

	// Release signature gate (fail-closed) — same scheme as the auto-update module:
	// verify a detached Ed25519 signature over the EXACT zip bytes BEFORE unzip/swap,
	// so a first-install never runs unverified code fetched over the network. During
	// a first-install the engine (which normally defines ZS_FLEET_UE_PUBKEY) is not
	// yet on disk, so the ONLY key source is a wp-config.php override — no override
	// means we cannot verify and refuse to install (deploy the files directly, e.g.
	// via au-push / scp, which is the primary fleet path anyway).
	$sig_ok = zs_fleet_loader_verify_zip_signature( $tmp_zip );
	if ( is_wp_error( $sig_ok ) ) {
		@unlink( $tmp_zip );
		error_log( '[zs-fleet] CRITICAL: bootstrap release signature verification FAILED - refusing self-install (' . $sig_ok->get_error_message() . ')' );
		return;
	}

	$extract_dir = trailingslashit( get_temp_dir() ) . 'zs-fleet-bootstrap-' . uniqid();
	if ( ! mkdir( $extract_dir, 0755, true ) ) {
		@unlink( $tmp_zip );
		error_log( '[zs-fleet] bootstrap: tmp mkdir failed' );
		return;
	}

	WP_Filesystem();
	$unzip = unzip_file( $tmp_zip, $extract_dir );
	@unlink( $tmp_zip );
	if ( is_wp_error( $unzip ) ) {
		error_log( '[zs-fleet] bootstrap: unzip failed: ' . $unzip->get_error_message() );
		return;
	}

	$staged = $extract_dir . '/zs-fleet';
	if ( ! is_dir( $staged ) || ! file_exists( $staged . '/zs-fleet.php' ) ) {
		error_log( '[zs-fleet] bootstrap: extracted zip is malformed' );
		return;
	}

	if ( ! @rename( $staged, $mu_dir . '/zs-fleet' ) ) {
		error_log( '[zs-fleet] bootstrap: rename into mu-plugins/ failed' );
		return;
	}

	error_log( '[zs-fleet] bootstrap: installed from GitHub release' );
}

/**
 * Verify the detached Ed25519 signature of the downloaded release zip. FAIL-CLOSED
 * on any doubt. Standalone copy of the auto-update module's check because the
 * engine module is not loaded during a first-install. Uses ZS_FLEET_UE_PUBKEY,
 * which on a fresh site can only come from a wp-config.php override (see caller).
 *
 * Message: "ZS-FLEET-RELEASE" . chr(0) . sha256(zip_bytes, raw) — matches
 * deploy/sign-release.php and modules/auto-update.php.
 *
 * @param string $zip_path absolute path to the downloaded zip.
 * @return true|WP_Error
 */
function zs_fleet_loader_verify_zip_signature( $zip_path ) {
	if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
		return new WP_Error( 'sig_verify', 'libsodium unavailable' );
	}
	if ( ! defined( 'ZS_FLEET_UE_PUBKEY' ) || ZS_FLEET_UE_PUBKEY === '' ) {
		return new WP_Error( 'sig_verify', 'no release public key reachable (define ZS_FLEET_UE_PUBKEY in wp-config.php, or deploy files directly)' );
	}
	$pubkey = base64_decode( ZS_FLEET_UE_PUBKEY, true );
	if ( $pubkey === false || strlen( $pubkey ) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ) {
		return new WP_Error( 'sig_verify', 'release public key malformed' );
	}

	$sig_resp = wp_remote_get(
		ZS_FLEET_LOADER_SIG_URL,
		array(
			'timeout'     => 30,
			'redirection' => 5,
			'headers'     => array( 'User-Agent' => 'zs-fleet loader bootstrap (sig)' ),
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
