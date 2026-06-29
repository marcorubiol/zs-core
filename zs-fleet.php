<?php
/**
 * Plugin Name: ZS Core
 * Description: Modular MU-plugin that bundles fleet-wide hardening and policy modules. Each file in modules/ is auto-loaded.
 * Version:     0.3.0
 * Author:      Zerø Sense
 * Author URI:  https://zerosense.studio
 * License:     GPL-2.0-or-later
 *
 * This file is the bootstrap. It does NOT live directly in
 * wp-content/mu-plugins/ — WordPress only scans top-level *.php files
 * there, not subdirectories. A 1-line loader file in mu-plugins/
 * (deploy/zs-fleet-loader.php in this repo) bridges that.
 *
 * Modules are plain PHP files in modules/ that register their hooks at
 * include time. They must NOT carry plugin headers — only this file does.
 *
 * To add a module:
 *   1. Drop a *.php file in modules/
 *   2. Push to GitHub, tag a release
 *   3. Pull/extract on each fleet site
 *
 * Module order is alphabetical (glob order). If two modules ever need
 * an ordering guarantee, prefix with a number (e.g. 10-foo.php, 20-bar.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'ZS_FLEET_VERSION' ) ) {
	define( 'ZS_FLEET_VERSION', '0.3.0' );   // MUST stay in sync with the Version: header above (auto-update compares header vs this constant)
}

if ( ! defined( 'ZS_FLEET_DIR' ) ) {
	define( 'ZS_FLEET_DIR', __DIR__ );
}

foreach ( glob( ZS_FLEET_DIR . '/modules/*.php' ) as $zs_fleet_module ) {
	require_once $zs_fleet_module;
}
unset( $zs_fleet_module );
