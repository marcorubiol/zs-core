<?php
/**
 * deploy/sign-release.php — sign a built release zip for the fleet self-updater.
 *
 * The fleet self-update path (modules/auto-update.php) and the first-install
 * loader (deploy/zs-fleet-loader.php) now REFUSE to swap a downloaded release zip
 * unless a valid detached Ed25519 signature verifies over the exact zip bytes.
 * This script produces that signature. It is a GATED, MANUAL release step — run
 * locally at release time, never in CI (the private key must not touch CI).
 *
 * Scheme (must match the verifiers byte-for-byte):
 *   message   = "ZS-FLEET-RELEASE" . chr(0) . sha256(<raw zip bytes>, binary)
 *   signature = Ed25519_sign(message, secret_key)
 *   output    = base64(signature)  ->  <zip>.sig
 *
 * The public key that verifies this signature is ZS_FLEET_UE_PUBKEY, baked into
 * modules/update-engine.php. This script prints the public key DERIVED from the
 * signing key so you can confirm it equals ZS_FLEET_UE_PUBKEY before publishing.
 *
 * Usage:
 *   ZS_RELEASE_SIGNING_SK="<base64 secret key OR 32-byte seed>" \
 *     php deploy/sign-release.php path/to/zs-fleet.zip
 *
 * IMPORTANT — sign the EXACT release asset. CI builds the zip; the bytes the fleet
 * downloads are the CI-built asset, not a locally re-zipped copy (zip metadata
 * differs). Download the published zs-fleet.zip from the GitHub release, sign THAT
 * file, then upload the resulting zs-fleet.zip.sig as an additional asset.
 *
 * ZS_RELEASE_SIGNING_SK accepts either:
 *   - a 64-byte crypto_sign secret key (base64), or
 *   - a 32-byte seed (base64) — the keypair is derived from it.
 * The private key is NEVER printed and is zeroed from memory after signing.
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "sign-release.php: CLI only\n" );
	exit( 1 );
}

if ( ! function_exists( 'sodium_crypto_sign_detached' ) ) {
	fwrite( STDERR, "sign-release.php: libsodium not available in this PHP build\n" );
	exit( 1 );
}

$zip = isset( $argv[1] ) ? $argv[1] : '';
if ( $zip === '' || ! is_file( $zip ) ) {
	fwrite( STDERR, "Usage: ZS_RELEASE_SIGNING_SK=<base64> php deploy/sign-release.php <path-to-zip>\n" );
	exit( 1 );
}

$sk_b64 = getenv( 'ZS_RELEASE_SIGNING_SK' );
if ( ! is_string( $sk_b64 ) || $sk_b64 === '' ) {
	fwrite( STDERR, "sign-release.php: ZS_RELEASE_SIGNING_SK env var not set\n" );
	exit( 1 );
}

$sk_raw = base64_decode( trim( $sk_b64 ), true );
if ( $sk_raw === false ) {
	fwrite( STDERR, "sign-release.php: ZS_RELEASE_SIGNING_SK is not valid base64\n" );
	exit( 1 );
}

if ( strlen( $sk_raw ) === SODIUM_CRYPTO_SIGN_SEEDBYTES ) {
	$kp = sodium_crypto_sign_seed_keypair( $sk_raw );
	$sk = sodium_crypto_sign_secretkey( $kp );
	$pk = sodium_crypto_sign_publickey( $kp );
	sodium_memzero( $kp );
} elseif ( strlen( $sk_raw ) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES ) {
	$sk = $sk_raw;
	$pk = sodium_crypto_sign_publickey_from_secretkey( $sk );
} else {
	fwrite( STDERR, "sign-release.php: signing key must be a 32-byte seed or 64-byte secret key (got " . strlen( $sk_raw ) . " bytes)\n" );
	sodium_memzero( $sk_raw );
	exit( 1 );
}

$zip_bytes = file_get_contents( $zip );
if ( $zip_bytes === false ) {
	fwrite( STDERR, "sign-release.php: could not read zip: {$zip}\n" );
	exit( 1 );
}

$message = 'ZS-FLEET-RELEASE' . chr( 0 ) . hash( 'sha256', $zip_bytes, true );
$sig     = sodium_crypto_sign_detached( $message, $sk );
$sig_b64 = base64_encode( $sig );

$out = $zip . '.sig';
if ( file_put_contents( $out, $sig_b64 ) === false ) {
	fwrite( STDERR, "sign-release.php: could not write {$out}\n" );
	sodium_memzero( $sk );
	sodium_memzero( $sk_raw );
	exit( 1 );
}

// Wipe the private key material from memory.
sodium_memzero( $sk );
sodium_memzero( $sk_raw );

echo "Wrote {$out}\n";
echo "Derived public key (must equal ZS_FLEET_UE_PUBKEY): " . base64_encode( $pk ) . "\n";
exit( 0 );
