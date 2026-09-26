<?php
/**
 * Regression test for the engine run lock (zs_fleet_ue_lock_acquire / _release).
 *
 * The lock used to be a transient. With a persistent object cache (LSCWP >= 7.8)
 * transients live ONLY in the cache, and an LSCWP purge-all flushes it, so the lock
 * vanished mid-run and a second run could re-apply the same manifest. This asserts
 * the lock survives an object-cache flush.
 *
 * Runs WITHOUT WordPress: WP_Upgrader is stubbed with core's create_lock semantics
 * (INSERT IGNORE of `<name>.lock` + timestamp, takeover once older than the TTL) over
 * an in-memory "wp_options", and transients are stubbed over a separate in-memory
 * "object cache" — so a lock moved back into transients fails the flush check. It
 * does not exercise MySQL or the real WP_Upgrader.
 *
 *   docker run --rm -v "$PWD":/app -w /app php:8.2-cli php tests/test-run-lock.php
 */

define( 'ABSPATH', __DIR__ . '/' );

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}
function add_action() {}
function add_filter() {}
function apply_filters( $tag, $value ) {
	return $value;
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

$zs_options = array(); // wp_options — survives a cache flush.
$zs_cache   = array(); // persistent object cache — where transients live under LSCWP.

class WP_Upgrader {
	public static function create_lock( $lock_name, $release_timeout = null ) {
		global $zs_options;
		if ( ! $release_timeout ) {
			$release_timeout = 3600;
		}
		$key = $lock_name . '.lock';
		if ( ! isset( $zs_options[ $key ] ) ) { // INSERT IGNORE inserted the row.
			$zs_options[ $key ] = time();
			return true;
		}
		if ( $zs_options[ $key ] > ( time() - $release_timeout ) ) {
			return false;
		}
		self::release_lock( $lock_name );
		return self::create_lock( $lock_name, $release_timeout );
	}
	public static function release_lock( $lock_name ) {
		global $zs_options;
		unset( $zs_options[ $lock_name . '.lock' ] );
		return true;
	}
}
function get_transient( $k ) {
	global $zs_cache;
	return isset( $zs_cache[ $k ] ) ? $zs_cache[ $k ] : false;
}
function set_transient( $k, $v ) {
	global $zs_cache;
	$zs_cache[ $k ] = $v;
	return true;
}
function delete_transient( $k ) {
	global $zs_cache;
	unset( $zs_cache[ $k ] );
	return true;
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

/* ── exclusion ──────────────────────────────────────────────────────────── */
check( zs_fleet_ue_lock_acquire() === true, 'first run takes the lock' );
check( zs_fleet_ue_lock_acquire() === false, 'second run is refused while the first holds it' );

/* ── the regression: an object-cache flush mid-run ──────────────────────── */
$zs_cache = array(); // LSCWP purge-all → Object_Cache::flush().
check( zs_fleet_ue_lock_acquire() === false, 'lock survives an object-cache flush (not a transient)' );

/* ── release ────────────────────────────────────────────────────────────── */
zs_fleet_ue_lock_release();
check( zs_fleet_ue_lock_acquire() === true, 'released lock can be taken again' );

/* ── takeover only after the TTL ────────────────────────────────────────── */
$zs_options[ ZS_FLEET_UE_LOCK . '.lock' ] = time() - 11 * 60;
check( zs_fleet_ue_lock_acquire() === false, 'an 11-min-old lock (a long live run) is NOT taken over' );
$zs_options[ ZS_FLEET_UE_LOCK . '.lock' ] = time() - ZS_FLEET_UE_LOCK_TTL - 1;
check( zs_fleet_ue_lock_acquire() === true, 'a lock older than the TTL (dead holder) is taken over' );
zs_fleet_ue_lock_release();

/* ── the cron entry point uses it, on every exit path ───────────────────── */
$src = file_get_contents( __DIR__ . '/../modules/update-engine.php' );
preg_match( '/function zs_fleet_ue_cron_run\(\) \{.*?\n\}\n/s', $src, $m );
$body = isset( $m[0] ) ? $m[0] : '';
check( $body !== '', 'zs_fleet_ue_cron_run() found in source' );
check( strpos( $body, '_transient(' ) === false, 'cron run touches no transient' );
check( strpos( $body, 'zs_fleet_ue_lock_acquire()' ) !== false, 'cron run takes the DB lock' );
check( substr_count( $body, 'zs_fleet_ue_lock_release()' ) === 2, 'cron run releases in finally AND in the shutdown net' );
check(
	(bool) preg_match( '/if \( \$zs_lock_released \) \{.*?\}\s*zs_fleet_ue_lock_release\(\);\s*\$err = error_get_last\(\);/s', $body ),
	'shutdown net releases before the fatal check (exit() also skips finally)'
);

echo "\n{$tests} tests, {$fails} failures\n";
exit( $fails > 0 ? 1 : 0 );
