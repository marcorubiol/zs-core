<?php
/**
 * Pure-logic + signature tests for the Fleet Update Engine.
 *
 * Runs WITHOUT WordPress: stubs the few WP symbols the module references at
 * load time, then exercises the pure functions and the security-critical
 * Ed25519 verification path. The stateful WP ops (stash/apply/restore) are
 * validated separately by shadow-on-ZERO (migration step 1, gated).
 *
 *   docker run --rm -v "$PWD":/app -w /app php:8.2-cli php tests/test-engine-pure.php
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

/* ── canonical json ─────────────────────────────────────────────────────── */
check( zs_fleet_ue_canonical_json( array( 'b' => 1, 'a' => 2 ) ) === '{"a":2,"b":1}', 'canonical sorts object keys' );
check( zs_fleet_ue_canonical_json( array( 3, 1, 2 ) ) === '[3,1,2]', 'canonical preserves list order' );
check( zs_fleet_ue_canonical_json( array( 'z' => array( 'y' => 1, 'x' => 2 ) ) ) === '{"z":{"x":2,"y":1}}', 'canonical recurses' );
check( zs_fleet_ue_canonical_json( array() ) === '[]', 'canonical empty array is []' );
check( zs_fleet_ue_canonical_json( array( 'u' => array() ) ) === '{"u":[]}', 'canonical nested empty list' );

/* ── shape validation ───────────────────────────────────────────────────── */
$good = array(
	'manifest_version' => 1,
	'site'             => 'a.com',
	'expires_at'       => '2026-01-01T00:00:00Z',
	'nonce'            => 'n1',
	'mode'             => 'apply',
	'updates'          => array(
		array(
			'type' => 'plugin',
			'slug' => 'bricks',
			'from' => '2.3.5',
			'to'   => '2.3.6',
		),
	),
);
check( zs_fleet_ue_validate_shape( $good ) === '', 'valid manifest passes shape' );

$t = $good;
$t['updates'][0]['slug'] = '../evil';
check( zs_fleet_ue_validate_shape( $t ) !== '', 'path-traversal slug rejected' );

$t = $good;
$t['updates'][0]['slug'] = 'a/b';
check( zs_fleet_ue_validate_shape( $t ) !== '', 'slug with separator rejected' );

$t = $good;
unset( $t['nonce'] );
check( zs_fleet_ue_validate_shape( $t ) !== '', 'missing nonce rejected' );

$t = $good;
$t['mode'] = 'nuke';
check( zs_fleet_ue_validate_shape( $t ) !== '', 'invalid mode rejected' );

$t = $good;
$t['updates'][0]['type'] = 'core';
check( zs_fleet_ue_validate_shape( $t ) !== '', 'unsupported type (core) rejected' );

$t = $good;
$t['updates'][0]['type'] = 'theme';
$t['updates'][0]['slug'] = 'bricks';
check( zs_fleet_ue_validate_shape( $t ) === '', 'theme type accepted' );

$t = $good;
$t['updates'][0]['type'] = 'theme';
$t['updates'][0]['slug'] = '.';
check( zs_fleet_ue_validate_shape( $t ) !== '', 'theme with unsafe slug rejected' );

$t = $good;
$t['manifest_version'] = 2;
check( zs_fleet_ue_validate_shape( $t ) !== '', 'future manifest_version rejected' );

$t = $good;
$t['updates'][0]['slug'] = '.';
check( zs_fleet_ue_validate_shape( $t ) !== '', 'pure-dot slug rejected (plugins-root wipe guard)' );

/* ── slug safety (the "wipe wp-content/plugins/" guard) ─────────────────── */
check( zs_fleet_ue_safe_slug( 'bricks' ) === true, 'normal slug safe' );
check( zs_fleet_ue_safe_slug( 'bit-form-pro' ) === true, 'hyphen slug safe' );
check( zs_fleet_ue_safe_slug( 'a_b.c-d' ) === true, 'mixed-punct slug safe' );
check( zs_fleet_ue_safe_slug( '.' ) === false, 'pure-dot slug unsafe' );
check( zs_fleet_ue_safe_slug( '..' ) === false, 'double-dot slug unsafe' );
check( zs_fleet_ue_safe_slug( '...' ) === false, 'triple-dot slug unsafe' );
check( zs_fleet_ue_safe_slug( '' ) === false, 'empty slug unsafe' );
check( zs_fleet_ue_safe_slug( 'a/b' ) === false, 'separator slug unsafe' );
check( zs_fleet_ue_safe_slug( '../evil' ) === false, 'traversal slug unsafe' );
check( zs_fleet_ue_safe_slug( '-' ) === false, 'no-alnum slug unsafe' );
check( zs_fleet_ue_safe_slug( '_' ) === false, 'underscore-only slug unsafe' );

/* ── expiry ─────────────────────────────────────────────────────────────── */
check( zs_fleet_ue_not_expired( gmdate( 'c', time() + 300 ), time() ) === true, 'future expiry accepted' );
check( zs_fleet_ue_not_expired( gmdate( 'c', time() - 300 ), time() ) === false, 'past expiry rejected' );
check( zs_fleet_ue_not_expired( 'not-a-date', time() ) === false, 'unparseable expiry rejected' );
check( zs_fleet_ue_not_expired( gmdate( 'c', time() - 60 ), time() ) === true, 'within clock-skew tolerance' );

/* ── outcome classification ─────────────────────────────────────────────── */
check( zs_fleet_ue_classify( '1.1', '1.1', true, true, 200, true ) === 'applied', 'clean apply → applied' );
check( zs_fleet_ue_classify( '1.1', '1.0', true, true, 200, true ) === 'verify_fail', 'version not bumped → fail' );
check( zs_fleet_ue_classify( '1.1', '1.1', true, false, 200, true ) === 'verify_fail', 'silent deactivation → fail' );
check( zs_fleet_ue_classify( '1.1', '1.1', true, true, 500, true ) === 'verify_fail', 'HTTP 500 → fail' );
check( zs_fleet_ue_classify( '1.1', '1.1', true, true, 200, false ) === 'verify_fail', 'fingerprint fail → fail' );
check( zs_fleet_ue_classify( '1.1', '1.1', false, false, 200, true ) === 'applied', 'inactive→inactive is fine' );

/* ── Ed25519 envelope verification (the security-critical path) ──────────── */
$kp     = sodium_crypto_sign_keypair();
$sk     = sodium_crypto_sign_secretkey( $kp );
$pk_b64 = base64_encode( sodium_crypto_sign_publickey( $kp ) );

$payload = zs_fleet_ue_canonical_json( $good );
$sig     = sodium_crypto_sign_detached( $payload, $sk );
$env     = array(
	'payload'   => base64_encode( $payload ),
	'signature' => base64_encode( $sig ),
);
$res = zs_fleet_ue_verify_envelope( $env, $pk_b64 );
check( is_array( $res ) && $res['site'] === 'a.com', 'valid signature → manifest returned' );

$env_tamper            = $env;
$tamper                = $good;
$tamper['updates'][0]['to'] = '9.9.9';
$env_tamper['payload'] = base64_encode( zs_fleet_ue_canonical_json( $tamper ) );
check( zs_fleet_ue_verify_envelope( $env_tamper, $pk_b64 ) instanceof WP_Error, 'tampered payload (orig sig) rejected' );

$kp2    = sodium_crypto_sign_keypair();
$pk2    = base64_encode( sodium_crypto_sign_publickey( $kp2 ) );
check( zs_fleet_ue_verify_envelope( $env, $pk2 ) instanceof WP_Error, 'wrong public key rejected' );

check( zs_fleet_ue_verify_envelope( $env, '' ) instanceof WP_Error, 'empty public key rejected' );

// A payload validly signed but NOT canonical must be rejected (replay-over-mutation defense).
$noncanon = '{"updates":[],"site":"a.com","mode":"apply","nonce":"n1","expires_at":"2026-01-01T00:00:00Z","manifest_version":1}';
$sig3     = sodium_crypto_sign_detached( $noncanon, $sk );
$env3     = array(
	'payload'   => base64_encode( $noncanon ),
	'signature' => base64_encode( $sig3 ),
);
check( zs_fleet_ue_verify_envelope( $env3, $pk_b64 ) instanceof WP_Error, 'non-canonical signed payload rejected' );

$env_bad = array( 'payload' => 'not-base64!!!', 'signature' => base64_encode( $sig ) );
check( zs_fleet_ue_verify_envelope( $env_bad, $pk_b64 ) instanceof WP_Error, 'malformed base64 payload rejected' );

check( zs_fleet_ue_verify_envelope( array( 'payload' => 'x' ), $pk_b64 ) instanceof WP_Error, 'envelope missing signature rejected' );

/* ── detect → pending shaping (pure over a detect array) ─────────────────── */
$detect = array(
	'plugins' => array(
		array( 'slug' => 'foo', 'current' => '1.0', 'active' => true, 'available' => '1.1' ),
		array( 'slug' => 'bar', 'current' => '2.0', 'active' => false ), // no available → not pending
	),
	'themes'  => array(
		array( 'slug' => 'bricks', 'current' => '2.3.7', 'active' => true, 'available' => '2.3.8' ),
	),
);
$pending = zs_fleet_ue_pending( $detect );
check( count( $pending ) === 2, 'pending extracts only updatable items' );
check( $pending[0]['type'] === 'plugin' && $pending[0]['slug'] === 'foo' && $pending[0]['from'] === '1.0' && $pending[0]['to'] === '1.1', 'pending plugin shape (type/slug/from/to)' );
check( $pending[1]['type'] === 'theme' && $pending[1]['slug'] === 'bricks' && $pending[1]['to'] === '2.3.8', 'pending theme shape' );
check( zs_fleet_ue_pending( array() ) === array(), 'pending handles empty detect' );

/* ── schema signature (Vía C empirical DB-touch detection) ───────────────── */
// zs_fleet_ue_schema_sig hashes the STRUCTURAL projection that the impure
// zs_fleet_ue_schema_signature() wrapper pulls from information_schema (ARRAY_N
// rows). The wrapper NEVER selects AUTO_INCREMENT (the counter), TABLE_ROWS,
// DATA_LENGTH or STATISTICS.CARDINALITY, so ordinary data churn cannot even reach
// this function — immunity is BY CONSTRUCTION (the wrapper's column selection),
// validated live on the canary. The structural EXTRA='auto_increment' FLAG IS
// projected, because gaining/losing it is real DDL. No DB or shim needed here:
// these are the exact projection shapes the wrapper produces.
//
// COLUMNS rows: [TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE,
//   IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME, GENERATION_EXPRESSION].
// STATISTICS rows: [TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE,
//   SUB_PART, INDEX_TYPE, NULLABLE, COLLATION].
// TABLES rows: [TABLE_NAME, ENGINE, ROW_FORMAT, TABLE_COLLATION].
$ss_tbls = array(
	array( 'wp_options', 'InnoDB', 'Dynamic', 'utf8mb4_unicode_520_ci' ),
	array( 'wp_posts', 'InnoDB', 'Dynamic', 'utf8mb4_unicode_520_ci' ),
);
$ss_cols = array(
	array( 'wp_options', 'option_id', '1', 'bigint unsigned', 'NO', null, 'auto_increment', null, null ),
	array( 'wp_options', 'option_name', '2', 'varchar(191)', 'NO', '', '', 'utf8mb4_unicode_520_ci', null ),
	array( 'wp_options', 'option_value', '3', 'longtext', 'NO', null, '', 'utf8mb4_unicode_520_ci', null ),
	array( 'wp_posts', 'ID', '1', 'bigint unsigned', 'NO', null, 'auto_increment', null, null ),
	array( 'wp_posts', 'post_title', '2', 'text', 'NO', null, '', 'utf8mb4_unicode_520_ci', null ),
);
$ss_idx = array(
	array( 'wp_options', 'PRIMARY', '1', 'option_id', '0', null, 'BTREE', '', 'A' ),
	array( 'wp_options', 'option_name', '1', 'option_name', '0', null, 'BTREE', '', 'A' ),
	array( 'wp_posts', 'PRIMARY', '1', 'ID', '0', null, 'BTREE', '', 'A' ),
);
$sig_base = zs_fleet_ue_schema_sig( $ss_tbls, $ss_cols, $ss_idx );

check( is_string( $sig_base ) && preg_match( '/^[0-9a-f]{40}$/', $sig_base ) === 1, 'schema sig is a 40-char sha1 hex' );
check( zs_fleet_ue_schema_sig( $ss_tbls, $ss_cols, $ss_idx ) === $sig_base, 'schema sig deterministic for identical projection' );

// DATA CHURN (the AUTO_INCREMENT trap): an INSERT bumps the auto_increment counter,
// TABLE_ROWS and index CARDINALITY — none of which the wrapper projects. The
// before/after projection is therefore byte-identical; rebuilt below as fresh
// literals (not a `= $ss_*` copy) to make that explicit. Must NOT flip the sig.
$churn_tbls = array(
	array( 'wp_options', 'InnoDB', 'Dynamic', 'utf8mb4_unicode_520_ci' ),
	array( 'wp_posts', 'InnoDB', 'Dynamic', 'utf8mb4_unicode_520_ci' ),
);
$churn_cols = array(
	array( 'wp_options', 'option_id', '1', 'bigint unsigned', 'NO', null, 'auto_increment', null, null ),
	array( 'wp_options', 'option_name', '2', 'varchar(191)', 'NO', '', '', 'utf8mb4_unicode_520_ci', null ),
	array( 'wp_options', 'option_value', '3', 'longtext', 'NO', null, '', 'utf8mb4_unicode_520_ci', null ),
	array( 'wp_posts', 'ID', '1', 'bigint unsigned', 'NO', null, 'auto_increment', null, null ),
	array( 'wp_posts', 'post_title', '2', 'text', 'NO', null, '', 'utf8mb4_unicode_520_ci', null ),
);
$churn_idx = array(
	array( 'wp_options', 'PRIMARY', '1', 'option_id', '0', null, 'BTREE', '', 'A' ),
	array( 'wp_options', 'option_name', '1', 'option_name', '0', null, 'BTREE', '', 'A' ),
	array( 'wp_posts', 'PRIMARY', '1', 'ID', '0', null, 'BTREE', '', 'A' ),
);
check( zs_fleet_ue_schema_sig( $churn_tbls, $churn_cols, $churn_idx ) === $sig_base, 'data churn (AUTO_INCREMENT/rows/cardinality not projected) → schema sig UNCHANGED' );

// ── DDL changes must FLIP the signature (this is the empirical detection) ──
$cols_addcol   = $ss_cols;
$cols_addcol[] = array( 'wp_options', 'autoload', '4', 'varchar(20)', 'NO', 'yes', '', 'utf8mb4_unicode_520_ci', null );
check( zs_fleet_ue_schema_sig( $ss_tbls, $cols_addcol, $ss_idx ) !== $sig_base, 'added column → schema sig FLIPS' );

$idx_addidx   = $ss_idx;
$idx_addidx[] = array( 'wp_posts', 'post_title', '1', 'post_title', '1', '191', 'BTREE', '', 'A' );
check( zs_fleet_ue_schema_sig( $ss_tbls, $ss_cols, $idx_addidx ) !== $sig_base, 'added index → schema sig FLIPS' );

$tbls_addtbl   = $ss_tbls;
$tbls_addtbl[] = array( 'wp_woocommerce_sessions', 'InnoDB', 'Dynamic', 'utf8mb4_unicode_520_ci' );
check( zs_fleet_ue_schema_sig( $tbls_addtbl, $ss_cols, $ss_idx ) !== $sig_base, 'added table → schema sig FLIPS' );

$cols_type       = $ss_cols;
$cols_type[0][3] = 'int unsigned'; // bigint → int
check( zs_fleet_ue_schema_sig( $ss_tbls, $cols_type, $ss_idx ) !== $sig_base, 'changed column type → schema sig FLIPS' );

$cols_coll       = $ss_cols;
$cols_coll[1][7] = 'utf8mb4_general_ci';
check( zs_fleet_ue_schema_sig( $ss_tbls, $cols_coll, $ss_idx ) !== $sig_base, 'changed collation → schema sig FLIPS' );

$cols_null       = $ss_cols;
$cols_null[1][4] = 'YES';
check( zs_fleet_ue_schema_sig( $ss_tbls, $cols_null, $ss_idx ) !== $sig_base, 'changed nullability → schema sig FLIPS' );

$cols_def       = $ss_cols;
$cols_def[3][5] = '0'; // COLUMN_DEFAULT null → '0'
check( zs_fleet_ue_schema_sig( $ss_tbls, $cols_def, $ss_idx ) !== $sig_base, 'changed default → schema sig FLIPS' );

// Structural auto_increment FLAG gained — proves the FLAG is in the signature even
// though the counter VALUE never is (the core of the AUTO_INCREMENT-immunity claim).
$cols_flag       = $ss_cols;
$cols_flag[1][6] = 'auto_increment';
check( zs_fleet_ue_schema_sig( $ss_tbls, $cols_flag, $ss_idx ) !== $sig_base, 'gained auto_increment FLAG → schema sig FLIPS (flag structural; counter excluded)' );

// Empty schema (brand-new / locked-down DB): stable and distinct from a populated one.
check( zs_fleet_ue_schema_sig( array(), array(), array() ) === zs_fleet_ue_schema_sig( array(), array(), array() ), 'empty schema sig stable' );
check( zs_fleet_ue_schema_sig( array(), array(), array() ) !== $sig_base, 'empty schema differs from populated' );

/* ── schema_signature SELECT invariant (regression guard, Vía C) ──────────── */
// The "immune to data churn BY CONSTRUCTION" claim lives in the impure wrapper's
// COLUMN SELECTION, not in any normalisation step — so it can only be enforced by
// inspecting the actual SQL the wrapper issues. This guard reads the wrapper source
// (ReflectionFunction → exact line slice) and asserts every SELECT projects the
// structural columns and NONE of the volatile ones. A future edit that adds
// AUTO_INCREMENT, TABLE_ROWS, DATA_LENGTH or CARDINALITY to a SELECT then fails CI
// here, before it can spuriously flip the signature on ordinary INSERT/ANALYZE
// traffic. Kept in this suite (no DB needed) so release.yml needs no change.
//
// The volatile names legitimately appear in the function's CODE COMMENTS ("…
// AUTO_INCREMENT … excluded"), so a naive scan of the raw source would false-FAIL.
// We therefore extract ONLY the string-literal tokens (the SQL itself) via
// token_get_all and ignore comments — testing the SELECT strings, not the prose.
$ue_ref   = new ReflectionFunction( 'zs_fleet_ue_schema_signature' );
$ue_lines = array_slice(
	file( $ue_ref->getFileName() ),
	$ue_ref->getStartLine() - 1,
	$ue_ref->getEndLine() - $ue_ref->getStartLine() + 1
);
$ue_sql = '';
foreach ( token_get_all( "<?php\n" . implode( '', $ue_lines ) ) as $tok ) {
	if ( is_array( $tok ) && in_array( $tok[0], array( T_ENCAPSED_AND_WHITESPACE, T_CONSTANT_ENCAPSED_STRING ), true ) ) {
		$ue_sql .= ' ' . $tok[1];
	}
}
$ue_sql = strtoupper( $ue_sql );

// MUST project every structural column the detection relies on.
foreach ( array(
	'TABLE_NAME', 'COLUMN_NAME', 'ORDINAL_POSITION', 'COLUMN_TYPE', 'IS_NULLABLE',
	'COLUMN_DEFAULT', 'EXTRA', 'COLLATION_NAME', 'GENERATION_EXPRESSION',
	'INDEX_NAME', 'SEQ_IN_INDEX', 'NON_UNIQUE', 'SUB_PART', 'INDEX_TYPE',
	'ENGINE', 'ROW_FORMAT', 'TABLE_COLLATION',
) as $must ) {
	check( strpos( $ue_sql, $must ) !== false, "schema_signature SELECT projects structural column $must" );
}

// MUST NOT project any volatile column — these move on ordinary INSERT/ANALYZE and
// would make the signature flip on data churn (the false-positive trap Vía C avoids).
foreach ( array( 'AUTO_INCREMENT', 'TABLE_ROWS', 'DATA_LENGTH', 'CARDINALITY' ) as $banned ) {
	check( strpos( $ue_sql, $banned ) === false, "schema_signature SELECT excludes volatile column $banned" );
}

/* ── ONBOARD mode: shape validation (fixed-verb ritual) ──────────────────── */
$onboard = array(
	'manifest_version' => 1,
	'site'             => 'a.com',
	'expires_at'       => '2026-01-01T00:00:00Z',
	'nonce'            => 'n-onb',
	'mode'             => 'onboard',
	'updates'          => array(
		array(
			'action'           => 'flowguard',
			'artifact_version' => '1.2.0',
			'artifact_sha256'  => str_repeat( 'a', 64 ),
		),
	),
);
check( zs_fleet_ue_validate_shape( $onboard ) === '', 'onboard manifest passes shape (action + artifact pin)' );

$t = $onboard;
$t['updates'][0]['action'] = 'rm-rf'; // not in the compiled allowlist
check( zs_fleet_ue_validate_shape( $t ) !== '', 'onboard action outside allowlist rejected' );

$t = $onboard;
$t['updates'][0]['artifact_sha256'] = 'deadbeef'; // not 64-hex
check( zs_fleet_ue_validate_shape( $t ) !== '', 'onboard short/bad sha256 rejected' );

$t = $onboard;
$t['updates'][0]['artifact_sha256'] = str_repeat( 'A', 64 ); // uppercase — must be lowercase hex
check( zs_fleet_ue_validate_shape( $t ) !== '', 'onboard non-lowercase sha256 rejected' );

$t = $onboard;
unset( $t['updates'][0]['artifact_version'] );
check( zs_fleet_ue_validate_shape( $t ) !== '', 'onboard missing artifact_version rejected' );

$t = $onboard;
$t['updates'] = array();
check( zs_fleet_ue_validate_shape( $t ) !== '', 'onboard empty updates rejected' );

// An onboard item MUST NOT be forced through the plugin/theme (type+slug) loop: a
// URL/slug in the item is simply ignored, and a bare fixed-verb item is valid.
$t = $onboard;
$t['updates'][0]['slug'] = '../evil'; // would fail the plugin loop; onboard ignores it
check( zs_fleet_ue_validate_shape( $t ) === '', 'onboard does not fall through to plugin|theme slug validation' );

// onboard must not require type/slug/from/to (proves the early-return branch).
check( ! isset( $onboard['updates'][0]['type'] ) && zs_fleet_ue_validate_shape( $onboard ) === '', 'onboard item needs no type/slug/from/to' );

/* ── ONBOARD sealed-secret format (byte-exact PyNaCl contract) ────────────── */
check(
	zs_fleet_ue_onboard_plaintext( 'admin', 'uuid-1', 'ab cd ef gh' ) === "admin\nuuid-1\nabcdefgh",
	'sealed plaintext = user\\nuuid\\npassword with spaces stripped'
);

// Round-trip through the ACTUAL sealed-box primitive the operator's PyNaCl SealedBox
// implements — seal to a box public key, open with the keypair, split on "\n".
$bkp    = sodium_crypto_box_keypair();
$bpk    = sodium_crypto_box_publickey( $bkp );
$pt     = zs_fleet_ue_onboard_plaintext( 'admin', 'uuid-9', 'AAAA BBBB CCCC' );
$sealed = base64_encode( sodium_crypto_box_seal( $pt, $bpk ) );
$opened = sodium_crypto_box_seal_open( base64_decode( $sealed, true ), $bkp );
check( is_string( $opened ) && $opened === $pt, 'sealed box round-trips to identical plaintext' );
$parts = explode( "\n", (string) $opened );
check(
	count( $parts ) === 3 && $parts[0] === 'admin' && $parts[1] === 'uuid-9' && $parts[2] === 'AAAABBBBCCCC',
	'unsealed splits into [user, uuid, password]'
);

/* ── ONBOARD constants: fail-closed defaults ─────────────────────────────── */
check( ZS_FLEET_UE_ALLOW_ONBOARD === false, 'onboard opt-in defaults to false (fail-closed)' );
check( ZS_FLEET_UE_SEAL_PUBKEY !== '' && strlen( (string) base64_decode( ZS_FLEET_UE_SEAL_PUBKEY, true ) ) === 32, 'seal pubkey baked as a valid 32-byte X25519 key (fail-closed on empty lives in the handler)' );
check( is_array( ZS_FLEET_UE_ONBOARD_ACTIONS ) && ZS_FLEET_UE_ONBOARD_ACTIONS === array( 'flowguard' ), 'onboard action allowlist = [flowguard]' );
check( ZS_FLEET_UE_ARTIFACT_MAX === 20971520, 'artifact size cap = 20 MiB' );

echo "\n$tests tests, $fails failures\n";
exit( $fails > 0 ? 1 : 0 );
