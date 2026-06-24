<?php
/**
 * Plugin Name: ZS Core
 * Description: Self-bootstrapping loader. On first request, if zs-fleet/ is missing, fetches the latest release from GitHub and installs it. Thereafter acts as the flat-to-subdirectory bridge.
 * Version:     0.2.1
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
