<?php
/**
 * Plugin Name:  Zerø — No User Enumeration
 * Description:  Fleet hardening. Closes WordPress username disclosure across the three
 *               standard enumeration vectors: (1) author archives — /author/{slug}/ and
 *               ?author=N both return a hard 404 before the canonical redirect can leak
 *               the login slug; (2) the REST users endpoint (/wp/v2/users) is removed for
 *               anonymous callers; (3) the core users sitemap provider is dropped. Logged-in
 *               editing (Bricks/builder REST calls) is untouched.
 * Version:      1.0.0
 * Author:       Zerø Sense
 * Author URI:   https://zerosense.studio
 * License:      GPL-2.0-or-later
 *
 * The vulnerability
 * -----------------
 * By default WordPress maps a numeric user ID to that user's login slug in three
 * publicly reachable ways:
 *
 *   1. Author archives. `/?author=1` triggers a canonical 301 to `/author/{nicename}/`,
 *      and the nicename is almost always the login name. An attacker iterates
 *      ?author=1,2,3… and harvests every username — the front half of a credential.
 *   2. REST. `/wp-json/wp/v2/users` lists every user who has authored a published post,
 *      slug included, with no authentication.
 *   3. Core sitemaps. `/wp-sitemap-users-1.xml` enumerates author archive URLs (same
 *      nicename leak) for crawlers.
 *
 * None of the fleet's brochure sites surface author pages, a public users API, or an
 * author sitemap as legitimate functionality, so all three are closed with no loss.
 * This is the low-severity "username disclosure via author archives" finding in the
 * site audits, generalised to the whole class.
 *
 * Why priority 1 on template_redirect
 * -----------------------------------
 * `redirect_canonical()` runs on `template_redirect` at priority 10. If we 404 later
 * than that, WordPress has already 301'd `?author=1` → `/author/{nicename}/` and the
 * slug is emitted in the Location header BEFORE our 404 — the leak still happens.
 * Hooking at priority 1 fires first: we send the 404 and `exit` before the canonical
 * redirect ever runs, so the nicename never reaches the wire. We render the theme's
 * 404 template directly (short-circuiting template-loader.php) so the response is an
 * ordinary, cacheable-as-404 page with no author data in it.
 *
 * Why the REST block is anonymous-only
 * ------------------------------------
 * Bricks, the block editor, and other logged-in tooling legitimately query
 * `/wp/v2/users` (author pickers, etc.). Removing the route outright would break
 * editing. We keep it for authenticated requests and only strip it for anonymous
 * callers — which is the only context that constitutes enumeration.
 *
 * Per-site opt-out
 * ----------------
 * Every hook is gated on `zs_fleet_no_user_enum_enabled` (default true). To turn the
 * module off at ONE site without disabling it fleet-wide, add to that site's per-site
 * plugin or wp-config.php:
 *
 *     add_filter( 'zs_fleet_no_user_enum_enabled', '__return_false' );
 *
 * Residual risk
 * -------------
 * A third-party SEO plugin (Slim SEO / Rank Math) that generates its OWN sitemap may
 * still emit an author sitemap independently of core — audit the active SEO plugin's
 * settings if author URLs must be fully absent from all sitemaps. Login enumeration
 * via timing on wp-login ("valid vs invalid username" response differences) is a
 * separate vector, not addressed here.
 *
 * To disable: delete this file from modules/ (or use the filter above for one site).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Master switch for the module. Checked at runtime inside every hook so a
 * per-site plugin can register the filter and still have it apply.
 */
function zs_no_user_enum_enabled() {
	return (bool) apply_filters( 'zs_fleet_no_user_enum_enabled', true );
}

/**
 * (1) Author archives + ?author=N → hard 404.
 *
 * Runs at priority 1 so it beats redirect_canonical (priority 10): the 404 is
 * sent and the request terminated before the canonical redirect can leak the
 * login slug in a Location header. Covers both the resolved author archive
 * (is_author()) and the raw numeric ?author=N probe on any permalink structure.
 */
add_action( 'template_redirect', function () {
	if ( ! zs_no_user_enum_enabled() ) {
		return;
	}

	$is_numeric_author_probe = isset( $_GET['author'] ) && is_numeric( $_GET['author'] );

	if ( ! is_author() && ! $is_numeric_author_probe ) {
		return;
	}

	global $wp_query;
	$wp_query->set_404();
	status_header( 404 );
	nocache_headers();

	// Render the theme's 404 template ourselves and stop — short-circuits
	// template-loader.php and, critically, the pending canonical redirect.
	$template = get_404_template();
	if ( $template ) {
		include $template;
	}
	exit;
}, 1 );

/**
 * (2) REST users endpoint — removed for anonymous callers only.
 *
 * Authenticated requests (logged-in editor / Bricks builder) keep the route so
 * author pickers and similar tooling keep working. Anonymous enumeration of
 * /wp/v2/users and /wp/v2/users/<id> is closed.
 */
add_filter( 'rest_endpoints', function ( $endpoints ) {
	if ( ! zs_no_user_enum_enabled() ) {
		return $endpoints;
	}
	if ( is_user_logged_in() ) {
		return $endpoints;
	}
	foreach ( array( '/wp/v2/users', '/wp/v2/users/(?P<id>[\d]+)' ) as $route ) {
		if ( isset( $endpoints[ $route ] ) ) {
			unset( $endpoints[ $route ] );
		}
	}
	return $endpoints;
} );

/**
 * (3) Drop the core "users" sitemap provider so /wp-sitemap-users-*.xml stops
 * enumerating author URLs. No-op if core sitemaps are disabled or replaced by
 * an SEO plugin.
 */
add_filter( 'wp_sitemaps_add_provider', function ( $provider, $name ) {
	if ( ! zs_no_user_enum_enabled() ) {
		return $provider;
	}
	if ( 'users' === $name ) {
		return false;
	}
	return $provider;
}, 10, 2 );
