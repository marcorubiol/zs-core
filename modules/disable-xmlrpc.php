<?php
/**
 * Module: Disable XML-RPC
 *
 * Cuts XML-RPC at the WordPress layer across the fleet.
 *
 * Trigger: brute-force incident on paellasencasa.com, 2026-04-27. Three
 * IPs (35.202.136.63, 91.224.92.99, 45.91.23.10) sustained a coordinated
 * POST flood at //xmlrpc.php and pushed netcup-main load to 7.15. The
 * site never authenticated anything (response size constant at 238
 * bytes = "method not found"), but every request still spawned an
 * lsphp worker that loaded WordPress, which is what saturated CPU.
 * Ad-hoc fix at the time was ASE Pro's "Disable XML-RPC" toggle on
 * paellas only — this module replaces that ad-hoc fix with a
 * fleet-wide default.
 *
 * Why we kill the request instead of just filtering methods
 * ---------------------------------------------------------
 * The naïve approach is `xmlrpc_enabled` + `xmlrpc_methods → []`. It
 * does NOT work. `xmlrpc_enabled` only blocks methods that require
 * authentication. The IXR_Server core ships with three system methods
 * (system.listMethods, system.multicall, system.getCapabilities) that
 * are hardcoded after the `xmlrpc_methods` filter runs, and none of
 * them require auth — so a `system.listMethods` POST still gets a
 * 200 OK with the three system methods listed, which means the bot
 * still gets a useful endpoint.
 *
 * To actually disable XML-RPC we have to short-circuit the request
 * before IXR_Server is constructed. The XMLRPC_REQUEST constant is
 * defined by xmlrpc.php at the very top, before wp-load.php. By the
 * time MU-plugins fire on `plugins_loaded`, WP is loaded but the
 * server hasn't been instantiated yet — perfect window to die.
 *
 * What it does
 * ------------
 *   1. On plugins_loaded, if XMLRPC_REQUEST is set: 403 + exit.
 *   2. Belt-and-braces: xmlrpc_enabled false + empty xmlrpc_methods.
 *   3. Removes the RSD link from <head> (no advertising of endpoint).
 *   4. Strips the X-Pingback HTTP header (same reason).
 *
 * Compatibility
 * -------------
 *   - MainWP Child uses REST in current versions, NOT XML-RPC, so
 *     this does not break fleet management.
 *   - Jetpack would be affected (depends on XML-RPC). Not in agency
 *     stack as of 2026-04. If ever added, that site needs an
 *     exception module.
 *   - Mobile WordPress app uses XML-RPC and will stop working. Not
 *     used by the agency operator.
 *
 * To carve out an exception per-site (opt-out)
 * --------------------------------------------
 * In wp-config.php (above the "stop editing" line) or in the site's
 * zs_<slug> plugin, return false from this filter:
 *
 *   add_filter( 'zs_fleet_disable_xmlrpc_enabled', '__return_false' );
 *
 * The filter is checked at module-load time (mu-plugins fire before
 * wp-config-loaded? no — see comment in zs-fleet.php). Practically:
 * MU-plugins load AFTER wp-config.php is parsed but BEFORE regular
 * plugins. So a wp-config-defined filter callback works; a
 * regular-plugin-defined one is too late for module-level checks
 * but fine for action/filter hooks defined inside modules. We use
 * action/filter hooks here, so a zs_<slug> regular-plugin filter
 * works correctly.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hard kill of any /xmlrpc.php request. Runs at priority 1 on
 * plugins_loaded — early enough to fire before wp_xmlrpc_server is
 * instantiated by xmlrpc.php (which only happens after all plugins
 * are loaded), late enough that WP is bootstrapped enough for
 * status_header() and wp_die() to work cleanly.
 *
 * Per-site opt-out: filter `zs_fleet_disable_xmlrpc_enabled` to false.
 */
function zs_disable_xmlrpc_kill() {
	if ( ! apply_filters( 'zs_fleet_disable_xmlrpc_enabled', true ) ) {
		return;
	}
	if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
		status_header( 403 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo "XML-RPC services are disabled on this site.\n";
		exit;
	}
}
add_action( 'plugins_loaded', 'zs_disable_xmlrpc_kill', 1 );

// Belt-and-braces below — should never matter once kill above fires,
// but cheap defense-in-depth in case of future WP changes. Each one
// also respects the opt-out filter so a Jetpack site stays consistent.

add_filter( 'xmlrpc_enabled', function ( $enabled ) {
	if ( ! apply_filters( 'zs_fleet_disable_xmlrpc_enabled', true ) ) {
		return $enabled;
	}
	return false;
} );

add_filter( 'xmlrpc_methods', function ( $methods ) {
	if ( ! apply_filters( 'zs_fleet_disable_xmlrpc_enabled', true ) ) {
		return $methods;
	}
	return array();
}, 999 );

add_action( 'init', function () {
	if ( ! apply_filters( 'zs_fleet_disable_xmlrpc_enabled', true ) ) {
		return;
	}
	remove_action( 'wp_head', 'rsd_link' );
} );

add_filter( 'wp_headers', function ( $headers ) {
	if ( ! apply_filters( 'zs_fleet_disable_xmlrpc_enabled', true ) ) {
		return $headers;
	}
	if ( isset( $headers['X-Pingback'] ) ) {
		unset( $headers['X-Pingback'] );
	}
	return $headers;
} );
