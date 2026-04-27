<?php
/**
 * Module: No Admin Mods
 *
 * Blocks plugin/theme/core modifications from the wp-admin browser UI.
 * Whitelisted operator emails, MainWP Child, WP-CLI and WP-Cron continue
 * to work.
 *
 * Migrated from the standalone MU-plugin zs-no-admin-mods.php (v1.1.0,
 * deployed 2026-04-24 to 16 sites). Behaviour identical — only structural
 * change: this is now a module under zs-fleet, no longer a top-level
 * MU-plugin.
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
 * install/update/delete capability block. Single-site WP has no formal
 * "super admin" role; the email whitelist is the pragmatic equivalent.
 *
 * What stays blocked even for whitelisted users
 * ---------------------------------------------
 *   - plugin-editor.php / theme-editor.php (DISALLOW_FILE_EDIT)
 *   - auto_update_plugins / auto_update_themes (forced empty)
 *
 * Coexistence with legit channels
 * -------------------------------
 *   - MainWP Child via XMLRPC   (defined XMLRPC_REQUEST)
 *   - MainWP Child via REST API (defined REST_REQUEST)
 *   - WP-CLI                    (defined WP_CLI)
 *   - WP-Cron                   (defined DOING_CRON)
 *
 * Residual risk: stolen Application Password reaching REST with admin
 * privileges bypasses this. Defense for that vector is App Password
 * rotation + 2FA, not capability filtering.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

function zs_no_admin_mods_exempt_emails() {
	return array(
		'marcorubiol@gmail.com',
		'marco@zerosense.studio',
	);
}

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

if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
	define( 'DISALLOW_FILE_EDIT', true );
}

add_filter( 'pre_option_auto_update_plugins', function () {
	return array();
} );

add_filter( 'pre_option_auto_update_themes', function () {
	return array();
} );

add_filter( 'automatic_updater_disabled', '__return_true' );
