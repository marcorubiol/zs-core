<?php
/**
 * Integration test for the engine's stateful core: stash → restore, against REAL
 * directories. The risky code is the atomic restore (copy-to-sibling → rename
 * swap) and stash-first fidelity — not Plugin_Upgrader (WP core). This provides a
 * faithful, minimal WP-filesystem shim and exercises the actual engine functions.
 *
 *   docker run --rm -v "$PWD":/app -w /app php:8.2-cli php tests/test-stash-restore.php
 */

$ROOT = sys_get_temp_dir() . '/zs-srtest-' . getmypid();
@mkdir( $ROOT, 0777, true );
register_shutdown_function( function () use ( $ROOT ) { zs_rrm( $ROOT ); } );

// ── temp WP layout ──
define( 'ABSPATH', $ROOT . '/wp/' );
define( 'WP_CONTENT_DIR', $ROOT . '/wp/wp-content' );
define( 'WP_PLUGIN_DIR', $ROOT . '/wp/wp-content/plugins' );
@mkdir( ABSPATH . 'wp-admin/includes', 0777, true );
file_put_contents( ABSPATH . 'wp-admin/includes/file.php', "<?php // stub\n" );
@mkdir( WP_PLUGIN_DIR, 0777, true );

// ── minimal but faithful WP shim (only what stash/restore call) ──
class WP_Error {
	public $code, $message;
	public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
function add_action() {}
function add_filter() {}
function apply_filters( $t, $v ) { return $v; }
function home_url( $p = '' ) { return 'http://test.local' . $p; }
function wp_mkdir_p( $dir ) { return is_dir( $dir ) || mkdir( $dir, 0777, true ); }
function WP_Filesystem() { global $wp_filesystem; if ( ! $wp_filesystem ) $wp_filesystem = new ZS_FS_Direct(); return true; }
class ZS_FS_Direct {
	public function delete( $path, $recursive = false ) { return zs_rrm( $path ); }
	public function move( $from, $to, $overwrite = false ) {
		if ( $overwrite && file_exists( $to ) ) zs_rrm( $to );
		return @rename( $from, $to );
	}
}
// Faithful copy_dir: copies CONTENTS of $from into existing $to (recursive). WP_Error on a failed op.
function copy_dir( $from, $to ) {
	if ( ! is_dir( $to ) && ! mkdir( $to, 0777, true ) ) return new WP_Error( 'mkdir', "cannot mkdir $to" );
	foreach ( scandir( $from ) as $e ) {
		if ( $e === '.' || $e === '..' ) continue;
		$src = "$from/$e"; $dst = "$to/$e";
		if ( is_dir( $src ) ) { $r = copy_dir( $src, $dst ); if ( is_wp_error( $r ) ) return $r; }
		else { if ( ! @copy( $src, $dst ) ) return new WP_Error( 'copy', "cannot copy $src" ); }
	}
	return true;
}
function zs_rrm( $p ) {
	if ( ! file_exists( $p ) && ! is_link( $p ) ) return true;
	if ( is_file( $p ) || is_link( $p ) ) return @unlink( $p );
	foreach ( scandir( $p ) as $e ) if ( $e !== '.' && $e !== '..' ) zs_rrm( "$p/$e" );
	return @rmdir( $p );
}

require __DIR__ . '/../modules/update-engine.php';

// ── test harness ──
$tests = 0; $fails = 0;
function check( $cond, $msg ) { global $tests, $fails; $tests++; echo ( $cond ? 'ok:   ' : ( ++$GLOBALS['fails'] && 'FAIL: ' ) ) . $msg . "\n"; }
// Recursive fingerprint of a dir: relative-path => sha1(content).
function fp( $dir ) {
	$out = array();
	$walk = function ( $d, $base ) use ( &$walk, &$out ) {
		foreach ( scandir( $d ) as $e ) {
			if ( $e === '.' || $e === '..' ) continue;
			$p = "$d/$e"; $rel = ltrim( "$base/$e", '/' );
			if ( is_dir( $p ) ) $walk( $p, $rel );
			else $out[ $rel ] = sha1_file( $p );
		}
	};
	$walk( $dir, '' );
	ksort( $out );
	return $out;
}
function make_plugin( $dir ) {
	@mkdir( "$dir/includes", 0777, true );
	@mkdir( "$dir/vendor/lib", 0777, true );
	file_put_contents( "$dir/plugin.php", "<?php /* Version: 1.0 */\n" );
	file_put_contents( "$dir/includes/core.php", "core-v1\n" );
	file_put_contents( "$dir/vendor/lib/dep.php", str_repeat( "x", 5000 ) );
	file_put_contents( "$dir/readme.txt", "readme\n" );
}

/* ── 1. stash is a byte-identical copy ── */
$live = WP_PLUGIN_DIR . '/acme';
make_plugin( $live );
$orig_fp = fp( $live );
$stash = zs_fleet_ue_stash_plugin( 'acme', '1.0' );
check( ! is_wp_error( $stash ) && is_dir( $stash ), 'stash created' );
check( fp( $stash ) === $orig_fp, 'stash is byte-identical to live (nested + vendor)' );

/* ── 2. restore over a MUTATED live → byte-identical to original ── */
file_put_contents( "$live/includes/core.php", "core-v2-BROKEN\n" ); // changed
file_put_contents( "$live/includes/new.php", "added-by-bad-update\n" ); // added
@unlink( "$live/readme.txt" ); // removed
check( fp( $live ) !== $orig_fp, 'live mutated (simulating a bad apply)' );
$ok = zs_fleet_ue_restore_plugin( 'acme', $stash );
check( $ok === true, 'restore returned true' );
check( fp( $live ) === $orig_fp, 'live restored BYTE-IDENTICAL (changed reverted, added gone, removed back)' );

/* ── 3. no scratch dirs left behind (the .zs-new / .zs-broken cleanup) ── */
$leftovers = glob( WP_PLUGIN_DIR . '/.acme*' ) ?: array();
check( count( $leftovers ) === 0, 'no .zs-new / .zs-broken scratch dirs left behind' );

/* ── 4. stash missing → restore returns false (unrecoverable, caller escalates) ── */
check( zs_fleet_ue_restore_plugin( 'acme', WP_CONTENT_DIR . '/zs-fleet-stash/does-not-exist' ) === false, 'restore with missing stash returns false' );

/* ── 5. unsafe slug never operates (defense in depth) ── */
check( is_wp_error( zs_fleet_ue_stash_plugin( '.', '1.0' ) ), 'stash refuses unsafe slug "."' );
check( zs_fleet_ue_restore_plugin( '.', $stash ) === false, 'restore refuses unsafe slug "."' );

/* ── 6. live still intact + active-plugin-shaped after the whole dance ── */
check( file_get_contents( "$live/includes/core.php" ) === "core-v1\n", 'live content is the original version' );

echo "\n$tests tests, $fails failures\n";
exit( $fails > 0 ? 1 : 0 );
