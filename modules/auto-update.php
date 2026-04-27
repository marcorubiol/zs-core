<?php
/**
 * Module: Auto-Update
 *
 * Pulls new releases of zs-fleet from GitHub and applies them
 * atomically. Runs daily via WP_Cron.
 *
 * Why: 21+ sites in the fleet. Manual updates per site doesn't scale.
 * GitHub is the source of truth; each site polls for new tags daily
 * and pulls them down.
 *
 * Source channel: GitHub Releases API. Only `releases/latest` is
 * consulted, which by GitHub's contract excludes drafts and
 * prereleases. So a tag like `v0.2.0-canary` (which we mark
 * prerelease in the release workflow) is invisible here — that's
 * the canary mechanism.
 *
 * Update layout: each release zip contains
 *
 *   zs-fleet/                ← what this module replaces
 *     zs-fleet.php
 *     modules/...
 *   zs-fleet-loader.php      ← NOT touched by self-update
 *
 * The loader stays put forever (1 line of logic, no churn). Any
 * change to the loader requires a manual re-deploy.
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

const ZS_FLEET_AU_REPO        = 'marcorubiol/zs-fleet';
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
		$latest = zs_fleet_au_fetch_latest();
		if ( is_wp_error( $latest ) ) {
			zs_fleet_au_log_error( $latest->get_error_message() );
			return;
		}

		$current = ZS_FLEET_VERSION;
		$remote  = ltrim( $latest['tag_name'], 'v' );

		if ( version_compare( $remote, $current, '<=' ) ) {
			// Already current.
			return;
		}

		$zip_url = '';
		foreach ( (array) ( $latest['assets'] ?? array() ) as $asset ) {
			if ( ! empty( $asset['name'] ) && substr( $asset['name'], -4 ) === '.zip' ) {
				$zip_url = $asset['browser_download_url'];
				break;
			}
		}
		if ( ! $zip_url ) {
			zs_fleet_au_log_error( 'no zip asset on release ' . $latest['tag_name'] );
			return;
		}

		$apply = zs_fleet_au_apply( $zip_url, $remote );
		if ( is_wp_error( $apply ) ) {
			zs_fleet_au_log_error( $apply->get_error_message() );
			return;
		}

		update_option( ZS_FLEET_AU_OPT_SUCCESS, time(), false );
		delete_option( ZS_FLEET_AU_OPT_ERROR );
		error_log( "[zs-fleet] auto-updated v{$current} → v{$remote}" );
	} finally {
		delete_transient( ZS_FLEET_AU_LOCK );
	}
}

function zs_fleet_au_fetch_latest() {
	$resp = wp_remote_get(
		'https://api.github.com/repos/' . ZS_FLEET_AU_REPO . '/releases/latest',
		array(
			'timeout' => 10,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'zs-fleet auto-updater (' . home_url() . ')',
			),
		)
	);
	if ( is_wp_error( $resp ) ) {
		return $resp;
	}
	$code = wp_remote_retrieve_response_code( $resp );
	if ( $code !== 200 ) {
		return new WP_Error( 'github_http', "GitHub returned HTTP {$code}" );
	}
	$body = json_decode( wp_remote_retrieve_body( $resp ), true );
	if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
		return new WP_Error( 'github_parse', 'unexpected GitHub response' );
	}
	return $body;
}

function zs_fleet_au_apply( $zip_url, $version ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';

	$tmp_zip  = wp_tempnam( "zs-fleet-{$version}.zip" );
	$download = wp_remote_get(
		$zip_url,
		array(
			'timeout'  => 60,
			'stream'   => true,
			'filename' => $tmp_zip,
			'headers'  => array( 'User-Agent' => 'zs-fleet auto-updater' ),
		)
	);
	if ( is_wp_error( $download ) ) {
		@unlink( $tmp_zip );
		return $download;
	}
	if ( wp_remote_retrieve_response_code( $download ) !== 200 ) {
		@unlink( $tmp_zip );
		return new WP_Error( 'download_http', 'zip download failed' );
	}

	$tmp_dir = trailingslashit( get_temp_dir() ) . "zs-fleet-extract-{$version}";
	if ( is_dir( $tmp_dir ) ) {
		zs_fleet_au_rrmdir( $tmp_dir );
	}
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
	if ( ! is_dir( $extracted ) ) {
		zs_fleet_au_rrmdir( $tmp_dir );
		return new WP_Error( 'bad_zip', 'zip does not contain zs-fleet/ directory' );
	}
	if ( ! file_exists( $extracted . '/zs-fleet.php' ) ) {
		zs_fleet_au_rrmdir( $tmp_dir );
		return new WP_Error( 'bad_zip', 'extracted zs-fleet/ has no bootstrap' );
	}

	$live  = WPMU_PLUGIN_DIR . '/zs-fleet';
	$stash = WPMU_PLUGIN_DIR . "/zs-fleet.old-{$version}";

	// Atomic swap.
	if ( ! @rename( $live, $stash ) ) {
		zs_fleet_au_rrmdir( $tmp_dir );
		return new WP_Error( 'swap_stash', 'could not stash live zs-fleet directory' );
	}
	if ( ! @rename( $extracted, $live ) ) {
		// Roll back: put the stash back where it was.
		@rename( $stash, $live );
		zs_fleet_au_rrmdir( $tmp_dir );
		return new WP_Error( 'swap_install', 'could not install new zs-fleet, rolled back' );
	}
	zs_fleet_au_rrmdir( $stash );
	zs_fleet_au_rrmdir( $tmp_dir );

	return true;
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
