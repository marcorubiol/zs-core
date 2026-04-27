<?php
/**
 * Plugin Name: Zerø Fleet Loader
 * Description: Bridges WP's flat MU-plugin discovery to the zs-fleet/ subdirectory. Place this file in wp-content/mu-plugins/ alongside the zs-fleet/ directory.
 * Version:     0.1.0
 * Author:      Zerø System
 * License:     GPL-2.0-or-later
 *
 * WordPress only auto-loads top-level *.php files in wp-content/mu-plugins/,
 * not files inside subdirectories. This 1-purpose loader sits at the
 * top level and require_once's the actual zs-fleet bootstrap from
 * wp-content/mu-plugins/zs-fleet/zs-fleet.php.
 *
 * Failure mode: if zs-fleet/ is not present (e.g. mid-deploy), this
 * file is a silent no-op. WP keeps booting normally.
 *
 * Deployment layout on each fleet site:
 *
 *   wp-content/mu-plugins/
 *   ├── zs-fleet-loader.php     ← this file
 *   └── zs-fleet/
 *       ├── zs-fleet.php
 *       └── modules/...
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$zs_fleet_bootstrap = __DIR__ . '/zs-fleet/zs-fleet.php';
if ( file_exists( $zs_fleet_bootstrap ) ) {
	require_once $zs_fleet_bootstrap;
}
unset( $zs_fleet_bootstrap );
