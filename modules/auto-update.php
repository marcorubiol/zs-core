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

const ZS_FLEET_AU_ZIP_URL     = 'https://github.com/marcorubiol/zs-fleet/releases/latest/download/zs-fleet.zip';
const ZS_FLEET_AU_HOOK        = 'zs_fleet_auto_update_check';
const ZS_FLEET_AU_OPT_LAST    = 'zs_fleet_au_last_check';
const ZS_FLEET_AU_OPT_SUCCESS = 'zs_fleet_au_last_success';
const ZS_FLEET_AU_OPT_ERROR   = 'zs_fleet_au_last_error';
const ZS_FLEET_AU_LOCK        = 'zs_fleet_au_lock';

add_action( 'init', 'zs_fleet_au_schedule' );
add_action( ZS_FLEET_AU_HOOK, 'zs_fleet_au_run' );

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
			// Already current — not an error, not a successful update.
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

	$tmp_dir = trailingslashit( get_temp_dir() ) . 'zs-fleet-check-' . uniqid();
	if ( ! mkdir( $tmp_dir, 0755, true ) ) {
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

	$live  = WPMU_PLUGIN_DIR . '/zs-fleet';
	$stash = WPMU_PLUGIN_DIR . "/zs-fleet.old-{$remote_version}";

	if ( ! @rename( $live, $stash ) ) {
		zs_fleet_au_rrmdir( $tmp_dir );
		return new WP_Error( 'swap_stash', 'could not stash live zs-fleet directory' );
	}
	if ( ! @rename( $extracted, $live ) ) {
		// Roll back: put the stash back.
		@rename( $stash, $live );
		zs_fleet_au_rrmdir( $tmp_dir );
		return new WP_Error( 'swap_install', 'could not install new zs-fleet, rolled back' );
	}
	zs_fleet_au_rrmdir( $stash );
	zs_fleet_au_rrmdir( $tmp_dir );

	return $remote_version;
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
