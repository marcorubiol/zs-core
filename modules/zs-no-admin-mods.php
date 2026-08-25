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
 * Updates keep working through:
 *   - WP-CLI                    (defined WP_CLI)
 *   - WP-Cron                   (defined DOING_CRON — core auto-updates
 *                                 can still complete there, and MainWP
 *                                 Child self-defines DOING_CRON before
 *                                 its own update routines)
 *
 * REST and XML-RPC USED to be exempt here too, "for MainWP Child". They were
 * removed 2026-08-25 because that rationale was never true (audited against
 * mainwp-child 6.1.1 on two sites):
 *   - MainWP Child registers ZERO REST routes. Its transport is a signed
 *     $_POST['function'] parsed on init:9999 — it never used wp-json.
 *   - It never checks any capability in the blocked list below, and its update
 *     paths call MainWP_Helper::maybe_set_doing_cron() first, so they are
 *     exempt through DOING_CRON regardless.
 *   - The XMLRPC branch was unreachable anyway: this same module 403-exits on
 *     xmlrpc.php before the filter is ever registered.
 * So the exemption cost real protection and bought nothing. What it cost: an
 * Application Password authenticates ONLY when REST_REQUEST or XMLRPC_REQUEST
 * is defined (wp-includes/user.php), so REST was the ENTIRE reachable surface
 * of every app password on the fleet — and every one of them walked straight
 * past this filter. On a client site that meant a phone holding a WooCommerce
 * app password could POST /wp/v2/plugins.
 *
 * Residual risk
 * -------------
 * The operator allowlist is checked INDEPENDENTLY of channel, so an Application
 * Password belonging to an allowlisted operator account still carries every
 * blocked capability over REST. That is the vector this module does not close,
 * and on 2026-08-25 it was the live shape of things: the operator account holds
 * app passwords named for FlowGuard, Novamira, SEO Utils and editor bridges —
 * service credentials wearing the operator's identity. Closing it means giving
 * those services their OWN non-operator user with only the capabilities they
 * need, not more capability filtering. Until then: 2FA on those accounts and
 * rotate their app passwords like any production credential.
 *
 * A compromise of a whitelisted operator account bypasses the filter entirely,
 * by design — that account is the escape hatch.
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
 * Return true if the current request comes through a channel we consider legit
 * for updates: WP-CLI and cron. Both are server-side and unreachable with a
 * stolen credential, which is the whole test a channel has to pass to be here.
 *
 * We do NOT treat AJAX alone as legit — some WP admin screens trigger plugin
 * installs via admin-ajax.php, and we want those blocked too. Nor REST, which
 * carries every Application Password on the fleet (removed 2026-08-25).
 */
function zs_no_admin_mods_is_legit_channel() {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return true;
	}
	if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
		return true;
	}
	// REST and XML-RPC are deliberately NOT here — see the header. They were
	// exempted for MainWP Child, which uses neither, and REST is the only channel
	// an Application Password can ever authenticate on.
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
 * One-time seed of the operator user-ID binding.
 *
 * The email whitelist ALONE is mintable: a client admin who can create users
 * (create_users/edit_users stay allowed by design) could add an account with an
 * operator email and inherit the exemption. Binding the exemption to the stable
 * per-site user IDs that ALREADY held an operator email at seed time closes that
 * — a freshly-minted user with the same email gets a different ID and is not
 * exempt.
 *
 * Stored as an autoloaded option (+ a 'seeded' sentinel) so we scan the user
 * table exactly once, not on every request. An empty result is valid (no operator
 * user on this site) — we still record the sentinel and grant NO browser exemption
 * (fail-closed; WP-CLI / cron / MainWP remain the legit channels).
 *
 * Residual seed-timing window: an attacker who mints the operator email on a
 * brand-new site BEFORE this first seed runs would be captured as "the" operator.
 * In practice the operator provisions the site (and this MU-plugin) before any
 * client account exists, so the first pageload seeds the real IDs. Still strictly
 * better than the pure-email check it replaces.
 */
function zs_no_admin_mods_seed_operator_ids() {
	if ( get_option( 'zs_nam_operator_ids_seeded' ) ) {
		return;
	}
	if ( ! function_exists( 'get_user_by' ) ) {
		return; // pluggable not ready yet — try again next request.
	}
	$ids = array();
	foreach ( zs_no_admin_mods_exempt_emails() as $email ) {
		$u = get_user_by( 'email', $email );
		if ( $u && ! empty( $u->ID ) ) {
			$ids[] = (int) $u->ID;
		}
	}
	update_option( 'zs_nam_operator_ids', $ids, true );
	update_option( 'zs_nam_operator_ids_seeded', 1, true );
}
add_action( 'init', 'zs_no_admin_mods_seed_operator_ids' );

/**
 * Return true if the current user is a bound operator: their stable per-site ID
 * was captured at seed time AND their email is still whitelisted. The ID binding
 * is the real gate (defeats a minted-email account); the email is a second factor.
 * Empty binding → NO exemption (fail-closed).
 */
function zs_no_admin_mods_is_exempt_user() {
	if ( ! function_exists( 'wp_get_current_user' ) ) {
		return false;
	}
	$user = wp_get_current_user();
	if ( ! $user || empty( $user->ID ) ) {
		return false;
	}
	$operator_ids = get_option( 'zs_nam_operator_ids', array() );
	if ( ! is_array( $operator_ids ) || $operator_ids === array() ) {
		return false; // not seeded yet, or no operator user existed → fail-closed.
	}
	if ( ! in_array( (int) $user->ID, array_map( 'intval', $operator_ids ), true ) ) {
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
 * Force plugin/theme auto-updates OFF at the option layer. Returning a
 * hardcoded empty array makes the "enable auto-updates" UI toggle inert.
 * The v2 pull engine owns those two classes, with its health gate and
 * rollback. (Said "MainWP owns the update cadence" until 2026-07-29 —
 * MainWP has been demoted to observation since Fleet v2.)
 */
add_filter( 'pre_option_auto_update_plugins', function () {
	return array();
} );

add_filter( 'pre_option_auto_update_themes', function () {
	return array();
} );

/**
 * Core auto-updates: RESTORE WordPress's native MINOR/security background
 * updater; keep MAJOR disabled (operator/engine-gated).
 *
 * Previously this filtered `automatic_updater_disabled => __return_true`, which
 * is NOT scoped to core — is_disabled() short-circuits WP_Automatic_Updater::run()
 * before the plugin, theme, translation AND core loops, so it killed every
 * background update class at once. That was fine while MainWP owned the cadence,
 * but with MainWP demoted to observation (Fleet v2) and the pull-engine not
 * handling core (update-engine.php rejects type != plugin|theme), NOTHING patched
 * core — the fleet sat on 6.9.4 / 7.0.0 through CVE-2026-63030 (pre-auth RCE).
 *
 * These two filters run LAST in Core_Upgrader::should_update_to_version() (after
 * the WP_AUTO_UPDATE_CORE constant and the auto_update_core_* options), so they
 * deterministically pin minor/security ON, major OFF regardless of host config.
 * Plugin/theme auto-updates stay OFF via the forced-empty options above — the v2
 * engine owns those with its health-gate + rollback. Minor core is the one class
 * safe for the native updater: security-only, schema-stable, with WP's own
 * filesystem rollback on checksum/verify failure.
 */
add_filter( 'allow_minor_auto_core_updates', '__return_true' );
add_filter( 'allow_major_auto_core_updates', '__return_false' );

/**
 * …and make sure the thing that READS those filters actually runs.
 *
 * The filters above are policy. `wp_maybe_auto_update` is the cron event that calls
 * WP_Automatic_Updater::run(), which is the ONLY caller that consults them. Verified on
 * 2026-07-29: the event was missing on **15 of 15** fleet sites, so since the v0.3.7 pass
 * restored minor-core policy, nothing has ever asked for it. Same for translations, whose
 * `auto_update_translation` filter defaults to true and equally never gets consulted —
 * which is how "New translations are available" piled up in wp-admin unnoticed.
 *
 * Why it goes missing: mainwp-child registers its own wp_version_check handler (hence the
 * duplicated event) that does `add_filter('automatic_updater_disabled','__return_true')`
 * plus `remove_action('wp_maybe_auto_update','wp_maybe_auto_update')` — correct when MainWP
 * owned the cadence, wrong now that it is demoted to observation. Re-scheduling from here
 * is safe against that: its remove_action only applies inside its own request, never the
 * one where the cron event fires.
 *
 * Scope of what this re-enables is exactly the two classes we want and nothing else, because
 * the policy is already pinned above: plugins/themes stay off (forced-empty options — the v2
 * engine owns those, with health gate and rollback), major core stays off, minor core and
 * translations on. Translations are the safest class that exists: .mo/.po data, no executable
 * code, no schema, nothing to roll back.
 */
add_action( 'init', function () {
	if ( wp_installing() ) {
		return;
	}
	if ( ! wp_next_scheduled( 'wp_maybe_auto_update' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', 'wp_maybe_auto_update' );
	}
} );
