<?php
/**
 * At-rest encryption for shared BLT credentials.
 *
 * @package BLT_Family
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AEAD envelope encryption for the shared credential store.
 *
 * Deliberately the same construction every BLT plugin already uses for its own
 * secrets: libsodium secretbox (XSalsa20-Poly1305, bundled with PHP core since
 * 7.2 so it is dependable on restricted shared hosting), falling back to
 * OpenSSL AES-256-GCM, and last of all to a clearly-tagged base64 obfuscation
 * so the site keeps working — is_strong() drives an admin warning in that case.
 *
 * The key is derived on demand from the WordPress salts and never stored. It is
 * therefore identical for every BLT plugin on the site, which is the whole
 * point: any of them can read what another wrote. It is also site-specific, so
 * a copied database is useless without the copied wp-config.php salts.
 *
 * The key context string is versioned and MUST NOT change — changing it makes
 * every stored secret on every site undecryptable. Migrate by adding a new
 * envelope prefix instead.
 */
class BLT_Family_Crypto {

	const ENVELOPE_SODIUM  = 'bf1:';
	const ENVELOPE_OPENSSL = 'bf2:';
	const ENVELOPE_PLAIN   = 'b64:';

	/**
	 * Whether an authenticated-encryption backend is available.
	 *
	 * @return bool
	 */
	public static function is_strong() {
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			return true;
		}

		return function_exists( 'openssl_encrypt' )
			&& in_array( 'aes-256-gcm', array_map( 'strtolower', openssl_get_cipher_methods() ), true );
	}

	/**
	 * Encrypt a plaintext secret into a versioned envelope.
	 *
	 * @param string $plaintext Value to protect.
	 * @return string Envelope string ('' for empty input).
	 */
	public static function encrypt( $plaintext ) {
		$plaintext = (string) $plaintext;

		if ( '' === $plaintext ) {
			return '';
		}

		$key = self::key();

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			return self::ENVELOPE_SODIUM . base64_encode( $nonce . $cipher );
		}

		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv     = random_bytes( 12 );
			$tag    = '';
			$cipher = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );

			if ( false !== $cipher ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				return self::ENVELOPE_OPENSSL . base64_encode( $iv . $tag . $cipher );
			}
		}

		// Last-resort obfuscation (clearly tagged; not real encryption).
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return self::ENVELOPE_PLAIN . base64_encode( $plaintext );
	}

	/**
	 * Decrypt a stored envelope produced by encrypt().
	 *
	 * @param string $stored Stored value.
	 * @return string Plaintext ('' on failure/empty).
	 */
	public static function decrypt( $stored ) {
		if ( ! is_string( $stored ) || '' === $stored ) {
			return '';
		}

		$key = self::key();

		if ( 0 === strpos( $stored, self::ENVELOPE_SODIUM ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$raw = base64_decode( substr( $stored, strlen( self::ENVELOPE_SODIUM ) ), true );

			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}

			$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );

			return false === $plain ? '' : $plain;
		}

		if ( 0 === strpos( $stored, self::ENVELOPE_OPENSSL ) && function_exists( 'openssl_decrypt' ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$raw = base64_decode( substr( $stored, strlen( self::ENVELOPE_OPENSSL ) ), true );

			if ( false === $raw || strlen( $raw ) < 28 ) { // 12 iv + 16 tag.
				return '';
			}

			$iv     = substr( $raw, 0, 12 );
			$tag    = substr( $raw, 12, 16 );
			$cipher = substr( $raw, 28 );
			$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );

			return false === $plain ? '' : $plain;
		}

		if ( 0 === strpos( $stored, self::ENVELOPE_PLAIN ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$plain = base64_decode( substr( $stored, strlen( self::ENVELOPE_PLAIN ) ), true );
			return false === $plain ? '' : $plain;
		}

		// Unrecognized / legacy plaintext.
		return $stored;
	}

	/**
	 * Derive the 32-byte key from WordPress salts (never persisted).
	 *
	 * @return string
	 */
	private static function key() {
		return hash( 'sha256', 'blt-family-v1|' . wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ), true );
	}
}
