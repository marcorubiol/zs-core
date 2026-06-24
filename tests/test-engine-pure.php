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

echo "\n$tests tests, $fails failures\n";
exit( $fails > 0 ? 1 : 0 );
