<?php
/**
 * Plugin Name:  Zerø — No Admin Mods
 * Description:  Fleet hardening. (1) Blocks plugin/theme/core modifications from the wp-admin browser UI — whitelisted operator emails, MainWP Child, WP-CLI and WP-Cron stay exempt. (2) Hard-disables XML-RPC (top brute-force / pingback-amplification vector and LSPHP-worker hog). No fleet site uses xmlrpc — MainWP Child talks over its own signed protocol, not xmlrpc.
 * Version:      1.2.0
 * Author:       Zerø Sense
 * Author URI:   https://zerosense.studio
 * License:      GPL-2.0-or-later
 *
 * Drop this file into wp-content/mu-plugins/ on each site. Must-Use plugins
 * are auto-loaded by WordPress with no activation step and cannot be
 * deactivated from the admin UI — which is exactly what we want for a
 * hardening layer.
 *
 * What it blocks (from browser only, for non-whitelisted users)
 * ------------------------------------------------------------
 *   install_plugins, update_plugins, delete_plugins, edit_plugins,
 *   install_themes,  update_themes,  delete_themes,  edit_themes,
 *   update_core.
 *
 * Whitelisted operator emails (see zs_no_admin_mods_exempt_emails below)
 * ---------------------------------------------------------------------
 * A logged-in user whose email matches the whitelist is exempt from the
 * install/update/delete capability block — so the fleet operator keeps a
 * browser-native path for installing ZIPs and managing plugins, while
 * any other admin (client-side admin accounts, future team members) stay
 * blocked. Single-site WP has no formal "super admin" role; the email
 * whitelist is the pragmatic equivalent.
 *
 * What stays blocked even for whitelisted users
 * ---------------------------------------------
 *   - plugin-editor.php / theme-editor.php (DISALLOW_FILE_EDIT set true)
 *     → code edits happen via SFTP/RightPlace, not browser.
 *   - auto_update_plugins / auto_update_themes options (forced to empty)
 *     → MainWP owns the update cadence, never the individual site.
 *
 * What it does NOT block for anyone
 * ---------------------------------
 *   activate_plugins, switch_themes  → needed for debug workflows.
 *   create_users / promote_users     → Marco manages client accounts
 *                                       from browser admin. v2 if an
 *                                       incident justifies it.
 *
 * Coexistence with legit update channels
 * --------------------------------------
 * Blocks apply only in browser context. Updates keep working through:
 *   - MainWP Child via XMLRPC   (defined XMLRPC_REQUEST)
 *   - MainWP Child via REST API (defined REST_REQUEST)
 *   - WP-CLI                    (defined WP_CLI)
 *   - WP-Cron                   (defined DOING_CRON — core auto-updates
 *                                 can still complete there)
 *
 * Residual risk
 * -------------
 * A stolen Application Password reaches the site via REST with admin
 * privileges → bypasses this plugin. Defense for that vector is App
 * Password rotation + 2FA, not capability filtering.
 *
 * A compromise of one of the whitelisted operator accounts also bypasses
 * the filter — so keep 2FA on those accounts and rotate their App
 * Passwords with the same discipline as any production credential.
 *
 * To disable: delete this file from wp-content/mu-plugins/.
 * No other cleanup needed — the filters stop firing immediately.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ── XML-RPC lockdown (v1.2.0) ──────────────────────────────────────────
 * xmlrpc.php is a top brute-force / pingback-amplification vector and a
 * heavy LSPHP-worker consumer on shared hosting — a single saturating
 * neighbour starves every site on the box (this is exactly how Paellas was
 * getting 504s: a neighbour flooded xmlrpc/wp-login and the shared origin
 * thrashed). No fleet site uses xmlrpc for legit traffic: MainWP Child
 * talks over its own signed protocol on `/` (verified 2026-06-05: zero
 * xmlrpc hits), WP-CLI/cron don't need it, and Jetpack / the mobile app
 * aren't in use. So we hard-block it.
 *
 * Fast-exit: when the request is for xmlrpc.php we send 403 and exit before
 * WordPress instantiates wp_xmlrpc_server, so a `system.multicall` brute-
 * force batch never runs and the worker is freed in milliseconds. The
 * filters below are belt-and-braces in case anything re-enables the
 * endpoint or advertises it via the X-Pingback header.
 *
 * Per-site escape hatch: define ZS_ALLOW_XMLRPC truthy in that site's
 * wp-config.php to opt it back in (e.g. if one ever needs Jetpack).
 */
if ( ! ( defined( 'ZS_ALLOW_XMLRPC' ) && ZS_ALLOW_XMLRPC ) ) {

	$zs_is_xmlrpc =
		( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
		|| ( isset( $_SERVER['SCRIPT_FILENAME'] ) && 'xmlrpc.php' === basename( (string) $_SERVER['SCRIPT_FILENAME'] ) );

	if ( $zs_is_xmlrpc ) {
		if ( ! headers_sent() ) {
			http_response_code( 403 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Robots-Tag: noindex' );
		}
		exit( 'XML-RPC disabled.' );
	}

	add_filter( 'xmlrpc_enabled', '__return_false' );

	add_filter( 'wp_headers', function ( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	} );

	add_filter( 'xmlrpc_methods', function ( $methods ) {
		unset(
			$methods['pingback.ping'],
			$methods['pingback.extensions.getPingbacks']
		);
		return $methods;
	} );
}

/**
 * Capabilities denied from browser-admin context.
 *
 * update_core is intentionally listed with the plugin/theme caps even
 * though it's a "meta-cap" — WP maps it through map_meta_cap too, and
 * filtering it here prevents `update-core.php` from running a core
 * update from the browser.
 */
function zs_no_admin_mods_blocked_caps() {
	return array(
		'install_plugins',
		'update_plugins',
		'delete_plugins',
		'edit_plugins',
		'install_themes',
		'update_themes',
		'delete_themes',
		'edit_themes',
		'update_core',
	);
}

/**
 * Return true if the current request comes through a channel we
 * consider legit for updates (MainWP, WP-CLI, cron).
 *
 * We do NOT treat AJAX alone as legit — some WP admin screens trigger
 * plugin installs via admin-ajax.php, and we want those blocked too.
 */
function zs_no_admin_mods_is_legit_channel() {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return true;
	}
	if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
		return true;
	}
	if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
		return true;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return true;
	}
	return false;
}

/**
 * Email whitelist — the operator accounts that keep browser-native
 * privileges over install/update/delete. Single-site WordPress has no
 * "super admin" role, so this is the explicit equivalent. Matching is
 * case-insensitive on email.
 */
function zs_no_admin_mods_exempt_emails() {
	return array(
		'marcorubiol@gmail.com',
		'marco@zerosense.studio',
	);
}

/**
 * Return true if the currently logged-in user is one of the operator
 * accounts in the email whitelist. When true, the capability filter
 * lets the user through; any other admin still hits do_not_allow.
 */
function zs_no_admin_mods_is_exempt_user() {
	if ( ! function_exists( 'wp_get_current_user' ) ) {
		return false;
	}
	$user = wp_get_current_user();
	if ( ! $user || empty( $user->ID ) ) {
		return false;
	}
	$email = strtolower( (string) $user->user_email );
	if ( $email === '' ) {
		return false;
	}
	foreach ( zs_no_admin_mods_exempt_emails() as $allowed ) {
		if ( strtolower( $allowed ) === $email ) {
			return true;
		}
	}
	return false;
}

/**
 * Strip blocked caps from the resolved capability list for the current
 * user when the request comes from the browser.
 *
 * `map_meta_cap` runs late in the permission pipeline and returns the
 * list of primitive caps WP will test against the user's role. By
 * inserting 'do_not_allow' we guarantee the check fails regardless of
 * the user's actual role — including super-admin on multisite.
 */
add_filter( 'map_meta_cap', function ( $caps, $cap ) {
	if ( zs_no_admin_mods_is_legit_channel() ) {
		return $caps;
	}
	if ( zs_no_admin_mods_is_exempt_user() ) {
		return $caps;
	}
	if ( in_array( $cap, zs_no_admin_mods_blocked_caps(), true ) ) {
		return array( 'do_not_allow' );
	}
	return $caps;
}, 10, 2 );

/**
 * Belt-and-braces: even if something bypasses map_meta_cap, hard-disable
 * the plugin and theme editors. DISALLOW_FILE_EDIT is checked directly by
 * WP before any capability logic runs.
 */
if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
	define( 'DISALLOW_FILE_EDIT', true );
}

/**
 * Force auto-updates OFF at the option layer. Returning a hardcoded
 * empty array makes the "enable auto-updates" UI toggle inert — MainWP
 * owns the update cadence.
 */
add_filter( 'pre_option_auto_update_plugins', function () {
	return array();
} );

add_filter( 'pre_option_auto_update_themes', function () {
	return array();
} );

/**
 * Kill the core auto-update background process. WP normally runs
 * minor-core auto-updates via wp-cron; MainWP's Phase 5 handles core
 * with explicit OK, so having the background updater run is a race.
 */
add_filter( 'automatic_updater_disabled', '__return_true' );
