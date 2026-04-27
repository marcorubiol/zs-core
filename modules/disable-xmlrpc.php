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
 * What it does
 * ------------
 *   1. xmlrpc_enabled  → false        (disables the endpoint entirely)
 *   2. xmlrpc_methods  → empty array  (belt-and-braces; if anything
 *                                       slips past #1, every method is
 *                                       a no-op)
 *   3. removes the RSD link from <head> (no advertising of the endpoint)
 *   4. strips the X-Pingback HTTP header (same reason)
 *
 * What this does NOT do
 * ---------------------
 *   - It does NOT block the request at the web-server layer. lsphp
 *     still spawns and WordPress still boots before responding "disabled".
 *     That is enough at current attack cadence (1-2 req/s observed)
 *     but a future-proof defense would be a Cloudflare WAF rule or
 *     an .htaccess deny — neither of those belongs in a WP plugin.
 *
 * Compatibility
 * -------------
 *   - MainWP Child uses REST in current versions, NOT XML-RPC, so this
 *     does not break fleet management.
 *   - Jetpack would be affected if installed (it depends on XML-RPC).
 *     Not in the agency stack as of 2026-04, so not a concern. If
 *     Jetpack ever gets added to a site, that site needs an exception.
 *   - Mobile WordPress app uses XML-RPC and will stop working. Not used
 *     by the agency operator.
 *
 * To carve out an exception per-site
 * ----------------------------------
 * Drop a sibling module (e.g. modules/zz-allow-xmlrpc-jetpack.php) that
 * runs late and adds add_filter('xmlrpc_enabled', '__return_true', 999);
 * The "zz-" prefix is a deliberate alphabetical hack to ensure it loads
 * after this one.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'xmlrpc_enabled', '__return_false' );

add_filter( 'xmlrpc_methods', function ( $methods ) {
	return array();
}, 999 );

remove_action( 'wp_head', 'rsd_link' );

add_filter( 'wp_headers', function ( $headers ) {
	if ( isset( $headers['X-Pingback'] ) ) {
		unset( $headers['X-Pingback'] );
	}
	return $headers;
} );
