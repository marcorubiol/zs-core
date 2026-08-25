<?php
/**
 * Channel tests for the hardening module (modules/zs-no-admin-mods.php).
 *
 * The thing under test is which request channels get a free pass on the blocked
 * capabilities. It is decided by CONSTANTS, and PHP cannot undefine one, so each
 * case has to run in its own process: this file is both the runner and the worker.
 *
 * Why it exists: until 2026-08-25 REST_REQUEST was exempt "for MainWP Child",
 * which never used REST. Since an Application Password can only authenticate on
 * REST or XML-RPC (wp-includes/user.php), that exemption handed every app
 * password on the fleet a free pass past this module. Nothing caught it because
 * nothing tested it.
 *
 *   docker run --rm -v "$PWD":/app -w /app php:8.2-cli php tests/test-no-admin-mods.php
 */

define( 'ABSPATH', __DIR__ . '/' );

/* ── worker mode: `php this.php <CONST>` prints the gate's verdict ───────── */
if ( isset( $argv[1] ) ) {
	$c = $argv[1];
	if ( 'NONE' !== $c ) {
		define( $c, true );
	}
	// Minimal WordPress surface the module touches at include time.
	foreach ( array( 'add_action', 'add_filter' ) as $fn ) {
		if ( ! function_exists( $fn ) ) {
			eval( "function $fn() { return true; }" );
		}
	}
	if ( ! function_exists( '__return_false' ) ) {
		function __return_false() { return false; }
	}
	if ( ! function_exists( '__return_true' ) ) {
		function __return_true() { return true; }
	}
	require __DIR__ . '/../modules/zs-no-admin-mods.php';
	echo zs_no_admin_mods_is_legit_channel() ? 'EXEMPT' : 'BLOCKED';
	exit( 0 );
}

/* ── runner mode ─────────────────────────────────────────────────────────── */
$tests = 0;
$fails = 0;
function check( $cond, $msg ) {
	global $tests, $fails;
	$tests++;
	if ( ! $cond ) {
		$fails++;
		echo "FAIL: $msg\n";
	} else {
		echo "ok:   $msg\n";
	}
}

/** Run the gate under one defined constant, in a clean process. */
function channel( $const ) {
	$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $const );
	return trim( (string) shell_exec( $cmd . ' 2>/dev/null' ) );
}

/* ── the two channels that must stay exempt: server-side, unreachable with a
 *    stolen credential. The engine and the self-updater ride these. ───────── */
check( channel( 'WP_CLI' ) === 'EXEMPT', 'WP-CLI stays exempt (engine.sh, au-push, zs-maintenance)' );
check( channel( 'DOING_CRON' ) === 'EXEMPT', 'cron stays exempt (engine pull, self-update, MainWP update paths)' );

/* ── the regression this file exists for ─────────────────────────────────── */
check( channel( 'REST_REQUEST' ) === 'BLOCKED', 'REST is NOT exempt — it is the only channel an app password can use' );

/* ── plain browser context, the original point of the module ─────────────── */
check( channel( 'NONE' ) === 'BLOCKED', 'a bare request (browser) is blocked' );

/* ── XML-RPC: the module hard-403s and exits before the gate is ever consulted.
 *    The worker therefore prints the module's own exit message and never
 *    reaches 'EXEMPT'/'BLOCKED' — which is the assertion. This is why removing
 *    the XMLRPC_REQUEST exemption changed nothing: it was unreachable. ────── */
$xmlrpc = channel( 'XMLRPC_REQUEST' );
check( $xmlrpc === 'XML-RPC disabled.', 'xmlrpc.php is 403-exited with its own message [' . $xmlrpc . ']' );
check( 'EXEMPT' !== $xmlrpc, 'xmlrpc never reaches the channel gate at all' );

/* ── the blocked set itself: a silent edit here is a silent hole ─────────── */
$blocked = zs_no_admin_mods_blocked_caps_probe();
check( in_array( 'install_plugins', $blocked, true ), 'install_plugins is blocked (POST /wp/v2/plugins)' );
check( in_array( 'update_plugins', $blocked, true ), 'update_plugins is blocked' );
check( in_array( 'delete_plugins', $blocked, true ), 'delete_plugins is blocked' );
check( in_array( 'edit_plugins', $blocked, true ), 'edit_plugins is blocked (file editor)' );
check( in_array( 'update_core', $blocked, true ), 'update_core is blocked' );
check( count( $blocked ) === 9, 'exactly 9 capabilities are blocked (' . count( $blocked ) . ' found)' );

/** Read the blocked list without loading the module into THIS process. */
function zs_no_admin_mods_blocked_caps_probe() {
	$src = file_get_contents( __DIR__ . '/../modules/zs-no-admin-mods.php' );
	if ( ! preg_match( '/function zs_no_admin_mods_blocked_caps\(\).*?return array\((.*?)\);/s', $src, $m ) ) {
		return array();
	}
	preg_match_all( "/'([a-z_]+)'/", $m[1], $caps );
	return $caps[1];
}

echo "\n$tests tests, $fails failures\n";
exit( $fails > 0 ? 1 : 0 );
