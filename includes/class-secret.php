<?php
/**
 * Vibe Comments - secret-at-rest encryption (v3.18.2).
 *
 * The audit law (2026-09-01, carried finding overturned): the Google
 * client_secret previously lived in wp_options as plaintext. It is now
 * sealed with an authenticated cipher before storage.
 *
 * Design:
 *   - Key = BLAKE2b of AUTH_KEY + a fixed label - no new secrets to
 *     store; rotating AUTH_KEY rotates the key (a sealed secret then
 *     fails to unseal, the admin re-enters it - honest and safe).
 *   - Ciphertext format: 'enc1:' + base64( nonce || ciphertext || tag )
 *     via sodium_crypto_secretbox (XSalsa20-Poly1305). The prefix makes
 *     migration trivial: values without the prefix are legacy plaintext,
 *     resealed on next save; unseal() accepts both.
 *   - Everything is a no-op when sodium is unavailable (ancient hosts):
 *     seal() stores plaintext with no prefix, unseal() returns it - the
 *     plugin never breaks over a crypto shim.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Vibe_Comments_Secret {

	const PREFIX = 'enc1:';

	/** Derive the sealing key. Never stored. */
	private static function key() {
		$salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'vibe-salt';
		// sodium_crypto_generichash, not hash('blake2b') - the blake2b algo
		// is not in PHP's hash() registry on all builds (live catch: this
		// build throws "must be a valid hashing algorithm"), but generichash
		// ships with the sodium extension that secretbox already needs.
		// 32 raw bytes = the secretbox key length.
		if ( function_exists( 'sodium_crypto_generichash' ) ) {
			return sodium_crypto_generichash( 'vibe-comments-secret-at-rest|' . $salt, '', 32 );
		}
		return hash( 'sha256', 'vibe-comments-secret-at-rest|' . $salt, true );
	}

	/**
	 * Seal plaintext for storage. Returns the sealed string (with the
	 * enc1: prefix) or, if sodium is missing, the plaintext unchanged.
	 */
	public static function seal( $plaintext ) {
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext || ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return $plaintext;
		}
		try {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ct    = sodium_crypto_secretbox( $plaintext, $nonce, self::key() );
			return self::PREFIX . base64_encode( $nonce . $ct );
		} catch ( Throwable $e ) {
			// Crypto failure must never take the settings screen down.
			return $plaintext;
		}
	}

	/**
	 * Unseal a stored value. Accepts sealed (enc1:) and legacy plaintext;
	 * returns '' when a sealed value cannot be opened (rotated AUTH_KEY).
	 */
	public static function unseal( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}
		if ( 0 !== strpos( $stored, self::PREFIX ) ) {
			return $stored; // legacy plaintext - accepted, resealed on save
		}
		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return '';
		}
		try {
			$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
			if ( false === $raw || strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
				return '';
			}
			$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ct    = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$pt    = sodium_crypto_secretbox_open( $ct, $nonce, self::key() );
			return false === $pt ? '' : $pt;
		} catch ( Throwable $e ) {
			return '';
		}
	}
}
