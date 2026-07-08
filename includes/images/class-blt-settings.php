<?php
/**
 * Settings storage & retrieval.
 *
 * @package BltImageOptimizer
 */

namespace BltImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the blt_optimizer_settings option, including at-rest encryption
 * of the Worker shared secret.
 */
class Settings {

	/**
	 * Option key in wp_options.
	 */
	const OPTION = 'blt_optimizer_settings';

	/**
	 * Default settings values.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'worker_url'              => '',
			'worker_secret'          => '',
			'webp_quality'           => 82,
			'max_width'              => 2400,
			'keep_originals'         => true,
			'auto_optimize'          => true,
			'optimize_existing_sizes' => true,
			'convert_gifs'           => false,
			'rewrite_content'        => false,
			'batch_size'             => 10,
		);
	}

	/**
	 * Seed defaults on activation without clobbering existing values.
	 *
	 * @return void
	 */
	public static function seed_defaults() {
		$existing = get_option( self::OPTION, array() );

		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$merged = wp_parse_args( $existing, self::defaults() );
		update_option( self::OPTION, $merged );
	}

	/**
	 * Retrieve all settings, merged over defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when unset.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		if ( 'worker_secret' === $key ) {
			return self::decrypt( $all['worker_secret'] );
		}

		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}

		return $default;
	}

	/**
	 * Persist a sanitized settings array.
	 *
	 * The worker_secret is encrypted at rest. An empty submitted secret
	 * preserves the previously stored value (so the field can render masked).
	 *
	 * @param array<string,mixed> $input Raw input (already unslashed).
	 * @return array<string,mixed> The stored settings.
	 */
	public static function save( array $input ) {
		$current = self::all();
		$clean   = array();

		$clean['worker_url'] = isset( $input['worker_url'] )
			? esc_url_raw( trim( $input['worker_url'] ) )
			: '';

		// Preserve existing secret when the field is left blank.
		if ( isset( $input['worker_secret'] ) && '' !== trim( $input['worker_secret'] ) ) {
			$clean['worker_secret'] = self::encrypt( trim( $input['worker_secret'] ) );
		} else {
			$clean['worker_secret'] = $current['worker_secret'];
		}

		$clean['webp_quality'] = isset( $input['webp_quality'] )
			? max( 1, min( 100, absint( $input['webp_quality'] ) ) )
			: 82;

		$clean['max_width'] = isset( $input['max_width'] )
			? absint( $input['max_width'] )
			: 2400;

		$clean['batch_size'] = isset( $input['batch_size'] )
			? max( 1, min( 100, absint( $input['batch_size'] ) ) )
			: 10;

		$clean['keep_originals']          = ! empty( $input['keep_originals'] );
		$clean['auto_optimize']           = ! empty( $input['auto_optimize'] );
		$clean['optimize_existing_sizes'] = ! empty( $input['optimize_existing_sizes'] );
		$clean['convert_gifs']            = ! empty( $input['convert_gifs'] );
		$clean['rewrite_content']         = ! empty( $input['rewrite_content'] );

		update_option( self::OPTION, $clean );

		return $clean;
	}

	/**
	 * Whether the Worker connection is configured.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== self::get( 'worker_url' ) && '' !== self::get( 'worker_secret' );
	}

	/**
	 * Derive a 32-byte encryption key from WP salts.
	 *
	 * @return string
	 */
	private static function encryption_key() {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' )
			. ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' )
			. ( defined( 'LOGGED_IN_KEY' ) ? LOGGED_IN_KEY : '' );

		// Fallback so encryption still functions on misconfigured installs.
		if ( '' === $material ) {
			$material = wp_salt( 'auth' );
		}

		return hash( 'sha256', 'blt-optimizer|' . $material, true );
	}

	/**
	 * Encrypt a secret for at-rest storage.
	 *
	 * Uses AES-256-GCM when sodium/openssl available; falls back to a
	 * tagged base64 representation if neither is present.
	 *
	 * @param string $plaintext Secret to encrypt.
	 * @return string
	 */
	public static function encrypt( $plaintext ) {
		if ( '' === $plaintext ) {
			return '';
		}

		if ( function_exists( 'openssl_encrypt' ) ) {
			$key    = self::encryption_key();
			$iv     = random_bytes( 12 );
			$tag    = '';
			$cipher = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );

			if ( false !== $cipher ) {
				return 'gcm:' . base64_encode( $iv . $tag . $cipher );
			}
		}

		// Last-resort obfuscation (clearly tagged, not strong encryption).
		return 'b64:' . base64_encode( $plaintext );
	}

	/**
	 * Decrypt a stored secret.
	 *
	 * @param string $stored Stored value produced by encrypt().
	 * @return string
	 */
	public static function decrypt( $stored ) {
		if ( ! is_string( $stored ) || '' === $stored ) {
			return '';
		}

		if ( 0 === strpos( $stored, 'gcm:' ) && function_exists( 'openssl_decrypt' ) ) {
			$raw = base64_decode( substr( $stored, 4 ), true );

			if ( false === $raw || strlen( $raw ) < 28 ) {
				return '';
			}

			$iv     = substr( $raw, 0, 12 );
			$tag    = substr( $raw, 12, 16 );
			$cipher = substr( $raw, 28 );
			$key    = self::encryption_key();

			$plain = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );

			return false === $plain ? '' : $plain;
		}

		if ( 0 === strpos( $stored, 'b64:' ) ) {
			$plain = base64_decode( substr( $stored, 4 ), true );
			return false === $plain ? '' : $plain;
		}

		// Legacy / plaintext value.
		return $stored;
	}
}
