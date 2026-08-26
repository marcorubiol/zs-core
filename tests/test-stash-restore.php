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

/* ── 7. the stash base is unreachable over HTTP (incident 2026-08-23) ── */
$base = WP_CONTENT_DIR . '/zs-fleet-stash';
clearstatcache();
check( ( fileperms( $base ) & 0777 ) === 0700, 'stash base is 0700 after a stash (the load-bearing guard)' );
check( file_exists( "$base/.htaccess" ), '.htaccess guard written' );
check( file_exists( "$base/index.php" ), 'index.php guard written' );
$ht = file_get_contents( "$base/.htaccess" );
check(
	strpos( $ht, 'Require all denied' ) !== false && strpos( $ht, 'Deny from all' ) !== false,
	'.htaccess covers both mod_authz_core and the legacy Order/Deny syntax'
);
check( count( glob( "$base/*", GLOB_ONLYDIR ) ) === 1, 'guards are FILES — invisible to the GLOB_ONLYDIR stash scans' );

/* ── 8. hardening is idempotent (it runs on every stash AND every check-in) ── */
check( zs_fleet_ue_stash_harden( $base ) === true, 'harden() returns true on an already-hardened base' );
check( file_get_contents( "$base/.htaccess" ) === $ht, 'second harden() does not rewrite or duplicate the guard' );
check(
	count( array_diff( scandir( $base ), array( '.', '..' ) ) ) === 3,
	'still exactly one stash dir + two guard files (nothing appended, nothing duplicated)'
);

/* ── 9. self-heal: a base that drifted back to 0755 is re-locked ── */
@chmod( $base, 0755 );
clearstatcache();
check( zs_fleet_ue_stash_summary()['protected'] === false, 'summary reports a drifted base honestly (not protected)' );
check( zs_fleet_ue_stash_harden( $base ) === true, 'harden() re-locks the drifted base' );
clearstatcache();
check( ( fileperms( $base ) & 0777 ) === 0700, 'base is 0700 again after the per-cycle self-heal' );

/* ── 10. summary reads the dir NAMES: hyphenated slugs, dedup, malformed skipped ── */
$now = time();
@mkdir( "$base/mainwp-child-reports-1.2.3-" . ( $now - 3600 ), 0777, true ); // slug with hyphens
@mkdir( "$base/mainwp-child-reports-1.2.3-" . ( $now - 60 ), 0777, true );   // same pair, newer → dedup
@mkdir( "$base/not-a-stash", 0777, true );                                    // unparseable → skipped
$sum   = zs_fleet_ue_stash_summary();
$slugs = array_column( $sum['items'], 'slug' );
check( $sum['count'] === 3, 'summary counts the 3 parseable stashes and not the malformed dir' );
check( count( $sum['items'] ) === 2, 'summary deduplicates {slug, version} pairs' );
check( in_array( 'mainwp-child-reports', $slugs, true ), 'hyphenated slug parsed whole (split from the RIGHT)' );
check( ! in_array( 'not', $slugs, true ) && ! in_array( 'not-a', $slugs, true ), 'malformed dir name skipped, never guessed' );
check( $sum['oldest_age'] >= 3600, 'oldest_age comes from the trailing unixtime of the oldest dir' );
check( $sum['protected'] === true, 'summary reports the re-locked base as protected' );

/* ── 11. retention: ONE per slug, and the one kept is the reachable one ── */
// The whole argument for KEEP=1 is that nothing can read an older copy: rollback mode
// resolves through zs_fleet_ue_latest_stash(), which returns end($dirs). If someone
// raises KEEP again, the extra copies are unreachable residue that a filesystem scanner
// will flag forever — which is exactly how this surfaced (Virusdie, 2026-08-26).
check( ZS_FLEET_UE_STASH_KEEP === 1, 'retention is ONE stash per slug (' . ZS_FLEET_UE_STASH_KEEP . ')' );

$now = time();
foreach ( array( 300, 200, 100 ) as $ago ) {
	@mkdir( "$base/keeptest-1.0-" . ( $now - $ago ), 0777, true );
}
check( count( glob( "$base/keeptest-*", GLOB_ONLYDIR ) ) === 3, 'three stashes staged for the pruner' );

// latest_stash must pick the NEWEST, which is the one the pruner keeps — same ordering.
$latest = zs_fleet_ue_latest_stash( 'keeptest' );
check( basename( $latest ) === 'keeptest-1.0-' . ( $now - 100 ), 'latest_stash resolves the NEWEST copy' );

zs_fleet_ue_prune_stashes( 'keeptest' );
$left = array_map( 'basename', glob( "$base/keeptest-*", GLOB_ONLYDIR ) );
check( count( $left ) === 1, 'pruner leaves exactly one (' . count( $left ) . ')' );
check( $left[0] === basename( $latest ), 'the survivor is the one latest_stash returns — no capability lost' );
check( zs_fleet_ue_latest_stash( 'keeptest' ) === $latest, 'rollback still resolves after pruning' );

echo "\n$tests tests, $fails failures\n";
exit( $fails > 0 ? 1 : 0 );
