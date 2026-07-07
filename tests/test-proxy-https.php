<?php
/**
 * Pure-logic tests for the proxy-HTTPS shim (modules/proxy-https.php).
 *
 * Runs WITHOUT WordPress: defines ABSPATH, then exercises the two pure helpers
 * and the load-time application of the shim to $_SERVER.
 *
 *   docker run --rm -v "$PWD":/app -w /app php:8.2-cli php tests/test-proxy-https.php
 */

define( 'ABSPATH', __DIR__ . '/' );

// The module runs top-level code against $_SERVER at include time. Seed a request
// that a trusted proxy marked as HTTPS so we can assert the shim applied it.
$_SERVER = array( 'HTTP_CF_VISITOR' => '{"scheme":"https"}' );

require __DIR__ . '/../modules/proxy-https.php';

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

/* ── load-time application ──────────────────────────────────────────────── */
check( isset( $_SERVER['HTTPS'] ) && 'on' === $_SERVER['HTTPS'], 'CF-Visitor https sets $_SERVER[HTTPS]=on at load' );

/* ── zs_fleet_proxy_says_https: positive cases ──────────────────────────── */
check( zs_fleet_proxy_says_https( array( 'HTTP_CF_VISITOR' => '{"scheme":"https"}' ) ), 'CF-Visitor https → true' );
check( zs_fleet_proxy_says_https( array( 'HTTP_CF_VISITOR' => '{ "scheme": "https" }' ) ), 'CF-Visitor https with spaces → true' );
check( zs_fleet_proxy_says_https( array( 'HTTP_X_FORWARDED_PROTO' => 'https' ) ), 'X-Forwarded-Proto https → true' );
check( zs_fleet_proxy_says_https( array( 'HTTP_X_FORWARDED_PROTO' => 'https, http' ) ), 'XFP list takes first value (https) → true' );
check( zs_fleet_proxy_says_https( array( 'HTTP_X_FORWARDED_PROTO' => 'HTTPS' ) ), 'XFP case-insensitive → true' );

/* ── zs_fleet_proxy_says_https: negative cases ──────────────────────────── */
check( ! zs_fleet_proxy_says_https( array() ), 'no headers → false (inert on true HTTP-only origin)' );
check( ! zs_fleet_proxy_says_https( array( 'HTTP_CF_VISITOR' => '{"scheme":"http"}' ) ), 'CF-Visitor http → false' );
check( ! zs_fleet_proxy_says_https( array( 'HTTP_X_FORWARDED_PROTO' => 'http' ) ), 'X-Forwarded-Proto http → false' );
check( ! zs_fleet_proxy_says_https( array( 'HTTP_X_FORWARDED_PROTO' => 'http, https' ) ), 'XFP list first value http → false (do not trust downstream)' );

/* ── zs_fleet_server_already_https ──────────────────────────────────────── */
check( zs_fleet_server_already_https( array( 'HTTPS' => 'on' ) ), 'HTTPS=on → already secure' );
check( zs_fleet_server_already_https( array( 'HTTPS' => '1' ) ), 'HTTPS=1 → already secure' );
check( zs_fleet_server_already_https( array( 'HTTPS' => 'ON' ) ), 'HTTPS=ON (case) → already secure' );
check( ! zs_fleet_server_already_https( array( 'HTTPS' => 'off' ) ), 'HTTPS=off → not secure' );
check( ! zs_fleet_server_already_https( array( 'HTTPS' => '' ) ), 'HTTPS empty → not secure' );
check( ! zs_fleet_server_already_https( array() ), 'HTTPS unset → not secure' );

echo "\n$tests tests, $fails failures\n";
exit( $fails === 0 ? 0 : 1 );
