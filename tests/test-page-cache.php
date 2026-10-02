<?php
/**
 * Regression test for the page-cache purge + clean probe (incident 2026-10-01).
 *
 * A request rendered mid-update produced the core critical-error page (500) and
 * LiteSpeed cached it under /. The cache-busting probe never saw it (its query string
 * is another cache key) and the engine reported healthy for ~13 minutes.
 *
 * Runs WITHOUT WordPress. wp_remote_get() is a small LiteSpeed simulation: responses
 * are cached per full URL (500s included, as on 2026-10-01), a HIT never reaches PHP,
 * and do_action('litespeed_purge_all') behaves as LSCWP 7.9.1 does in cron — it only
 * QUEUES the purge, which the next request that reaches PHP delivers.
 *
 *   docker run --rm -v "$PWD":/app -w /app php:8.2-cli php tests/test-page-cache.php
 */

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public $code, $message;
	public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; }
	public function get_error_message() { return $this->message; }
}
function add_action() {}
function add_filter() {}
function apply_filters( $t, $v ) { return $v; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function home_url( $p = '' ) { return 'https://site.test' . $p; }
function add_query_arg( $k, $v, $base ) { return $base . '?' . $k . '=' . $v; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function update_option() { return true; }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }

const PAGE_OK  = '<!doctype html><html><body class="home">Paellas</body></html>';
const PAGE_500 = '<!DOCTYPE html><html dir="ltr" lang="es-ES"><head></head><body id="error-page"><div class="wp-die-message"><p>Ha habido un error crítico en esta web.</p></div></body></html>';

$sim = array();
function sim_reset( $php, $lscwp = true ) {
	global $sim;
	$sim = array( 'cache' => array(), 'queue' => false, 'php' => $php, 'lscwp' => $lscwp, 'reqs' => array(), 'purges' => 0 );
}
function has_action( $tag ) {
	global $sim;
	return $tag === 'litespeed_purge_all' && $sim['lscwp'];
}
function do_action( $tag ) {
	global $sim;
	if ( $tag === 'litespeed_purge_all' && $sim['lscwp'] ) {
		$sim['queue'] = true; // cron: X-LiteSpeed-Purge stored in litespeed.purge.queue.
	}
}
function wp_remote_get( $url, $args ) {
	global $sim;
	$ua            = $args['headers']['User-Agent'];
	$sim['reqs'][] = array( $url, $ua );
	if ( isset( $sim['cache'][ $url ] ) ) {
		return $sim['cache'][ $url ]; // HIT: PHP never runs, a queued purge stays queued.
	}
	if ( $sim['queue'] ) {          // MISS: PHP runs; LSCWP Core emits the queued purge.
		$sim['cache'] = array();
		$sim['queue'] = false;
		$sim['purges']++;
	}
	list( $code, $body ) = call_user_func( $sim['php'], $url, $ua );
	$resp                = array( 'code' => $code, 'body' => $body );
	$sim['cache'][ $url ] = $resp;
	return $resp;
}

require __DIR__ . '/../modules/update-engine.php';

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
function healthy_php() {
	return function () { return array( 200, PAGE_OK ); };
}
function new_row() {
	return array( 'message' => '' );
}

/* ── fingerprint: the translated critical-error page ────────────────────── */
check( zs_fleet_ue_fingerprint_reason( PAGE_500 ) === 'php_fatal', 'ES critical-error page → php_fatal' );
check( zs_fleet_ue_fingerprint_reason( '<html><body id="error-page">Algo</body></html>' ) === 'php_fatal', 'wp_die body id="error-page" (any language) → php_fatal' );
check( zs_fleet_ue_fingerprint_reason( PAGE_OK ) === '', 'normal page passes' );

/* ── run_touched_files (pure) ───────────────────────────────────────────── */
$o = function ( $outcome ) { return array( 'outcome' => $outcome ); };
check( zs_fleet_ue_run_touched_files( 'shadow', array( $o( 'applied' ) ) ) === false, 'shadow never touches files' );
check( zs_fleet_ue_run_touched_files( 'apply', array() ) === false, 'empty run touches nothing' );
check( zs_fleet_ue_run_touched_files( 'apply', array( $o( 'skipped' ), $o( 'noop' ), $o( 'drift' ) ) ) === false, 'skipped/noop/drift touch nothing' );
check( zs_fleet_ue_run_touched_files( 'apply', array( $o( 'noop' ), $o( 'applied' ) ) ) === true, 'one applied → touched' );
check( zs_fleet_ue_run_touched_files( 'apply', array( $o( 'error' ) ) ) === true, 'error (mid-swap window possible) → touched' );
check( zs_fleet_ue_run_touched_files( 'rollback', array( $o( 'rolled_back' ) ) ) === true, 'rolled_back → touched' );

/* ── cache-busting probe is unique per request ──────────────────────────── */
sim_reset( healthy_php() );
zs_fleet_ue_http_self();
zs_fleet_ue_http_self();
check( count( $sim['reqs'] ) === 2 && $sim['reqs'][0][0] !== $sim['reqs'][1][0], 'two probes in the same second use different URLs (no cache HIT)' );
check( strpos( $sim['reqs'][0][0], '?zs_fleet_probe=' ) !== false && $sim['reqs'][0][1] === 'zs-fleet-engine self-check', 'busted probe unchanged otherwise' );

/* ── the incident: 500 cached under /, PHP healthy ──────────────────────── */
sim_reset( healthy_php() );
$sim['cache']['https://site.test/'] = array( 'code' => 500, 'body' => PAGE_500 );
list( $code, $secs, $body ) = zs_fleet_ue_http_self();
check( $code === 200, 'reproduces the blind spot: busted probe reads 200 over a cached 500 at /' );
$row = new_row();
zs_fleet_ue_clean_gate( $row, 'woocommerce', $code, $secs, $body );
check( $code === 200 && zs_fleet_ue_fingerprint_ok( $body ), 'stale cache → purge+recheck → item stays healthy (no rollback)' );
check( $row['http_clean'] === 200, 'http_clean recorded' );
check( strpos( $row['message'], 'stale page cache cleared' ) !== false, 'message says the cache was the problem' );
check( $sim['purges'] === 1 && $sim['queue'] === false, 'queued purge was DELIVERED by the busted probe' );
check( $sim['cache']['https://site.test/']['code'] === 200, '/ is cached healthy afterwards' );
$clean_reqs = array_values( array_filter( $sim['reqs'], function ( $r ) { return $r[0] === 'https://site.test/'; } ) );
check( count( $clean_reqs ) === 2 && $clean_reqs[0][1] === ZS_FLEET_UE_BROWSER_UA, 'clean probe: bare /, browser UA, one recheck' );

/* ── same, but no LSCWP to purge (another cache holds the 500) → gate fails ─ */
sim_reset( healthy_php(), false );
$sim['cache']['https://site.test/'] = array( 'code' => 500, 'body' => PAGE_500 );
list( $code, $secs, $body ) = zs_fleet_ue_http_self();
$row = new_row();
zs_fleet_ue_clean_gate( $row, 'woocommerce', $code, $secs, $body );
check( $code === 500 && zs_fleet_ue_fingerprint_reason( $body ) === 'php_fatal', 'unpurgeable bad / → clean result replaces the busted one' );
check( zs_fleet_ue_classify( '2', '2', true, true, $code, zs_fleet_ue_fingerprint_ok( $body ) ) === 'verify_fail', '… so the existing verdict fails and the rollback path runs' );
check( strpos( $row['message'], 'unhealthy after purge+recheck' ) !== false, 'message names the clean probe' );

/* ── a fatal only real visitors hit (browser UA) → gate fails ───────────── */
sim_reset(
	function ( $url, $ua ) {
		return $ua === ZS_FLEET_UE_BROWSER_UA ? array( 500, PAGE_500 ) : array( 200, PAGE_OK );
	}
);
list( $code, $secs, $body ) = zs_fleet_ue_http_self();
$row = new_row();
zs_fleet_ue_clean_gate( $row, 'wpcodebox2', $code, $secs, $body );
check( $code === 500, 'UA-dependent fatal survives the purge → gate fails' );

/* ── healthy 200 that is really the error page (served stale with 200) ──── */
sim_reset( healthy_php(), false );
$sim['cache']['https://site.test/'] = array( 'code' => 200, 'body' => PAGE_500 );
list( $code, $secs, $body ) = zs_fleet_ue_http_self();
$row = new_row();
zs_fleet_ue_clean_gate( $row, 'x', $code, $secs, $body );
check( ! zs_fleet_ue_fingerprint_ok( $body ), 'error-page body with a 200 still fails (fingerprint gate)' );

/* ── blocked clean probe → noted, never gated ───────────────────────────── */
sim_reset(
	function ( $url, $ua ) {
		return $ua === ZS_FLEET_UE_BROWSER_UA ? array( 403, 'Forbidden' ) : array( 200, PAGE_OK );
	}
);
list( $code, $secs, $body ) = zs_fleet_ue_http_self();
$row = new_row();
zs_fleet_ue_clean_gate( $row, 'x', $code, $secs, $body );
check( $code === 200 && $row['http_clean'] === 403, 'blocked clean probe leaves the busted verdict' );
check( strpos( $row['message'], 'blocked' ) !== false && $sim['purges'] === 0, 'blocked: noted, no purge, no recheck' );

/* ── cheap when healthy; silent when the busted probe already failed ────── */
sim_reset( healthy_php() );
list( $code, $secs, $body ) = zs_fleet_ue_http_self();
$row = new_row();
zs_fleet_ue_clean_gate( $row, 'x', $code, $secs, $body );
check( count( $sim['reqs'] ) === 2 && $sim['purges'] === 0 && $row['message'] === '', 'healthy: exactly ONE extra request, no purge' );
sim_reset( healthy_php() );
$code = 500;
$body = PAGE_500;
$row  = new_row();
zs_fleet_ue_clean_gate( $row, 'x', $code, $secs, $body );
check( count( $sim['reqs'] ) === 0 && ! isset( $row['http_clean'] ), 'busted probe already failing → no clean probe' );

/* ── run level ──────────────────────────────────────────────────────────── */
sim_reset( healthy_php() );
$report = zs_fleet_ue_run( array( 'mode' => 'shadow', 'nonce' => 'n', 'updates' => array() ) );
check( $report['cache_purged'] === null && $report['site_http_clean'] === null && count( $sim['reqs'] ) === 1, 'run that touched nothing: no purge, no clean probe' );

$src = file_get_contents( __DIR__ . '/../modules/update-engine.php' );
preg_match( '/function zs_fleet_ue_run\( \$manifest \) \{.*?\n\}\n/s', $src, $m );
$run = isset( $m[0] ) ? $m[0] : '';
$p   = strpos( $run, 'zs_fleet_ue_purge_page_cache(' );
$h   = strpos( $run, 'zs_fleet_ue_http_self()' );
$c   = strpos( $run, 'zs_fleet_ue_clean_check()' );
check( $p !== false && $h !== false && $c !== false && $p < $h && $h < $c, 'run end: purge → busted probe (delivers it) → clean probe, after the loop' );
check( $p > strpos( $run, 'foreach ( $manifest' ), 'purge is after every item, not inside the loop' );
foreach ( array( 'zs_fleet_ue_apply_one', 'zs_fleet_ue_apply_one_theme' ) as $fn ) {
	preg_match( '/function ' . $fn . '\( \$update, \$mode \) \{.*?\n\}\n/s', $src, $m );
	check( isset( $m[0] ) && (bool) preg_match( '/zs_fleet_ue_http_self\(\);\s*zs_fleet_ue_clean_gate\( \$row, \$slug, \$code, \$secs, \$body \);\s*\$fp_ok\s*= zs_fleet_ue_fingerprint_ok\( \$body \);/', $m[0] ), "$fn gates on the clean probe before computing fingerprint" );
}

echo "\n{$tests} tests, {$fails} failures\n";
exit( $fails > 0 ? 1 : 0 );
