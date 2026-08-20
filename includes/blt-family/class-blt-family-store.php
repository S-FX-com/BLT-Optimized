<?php
/**
 * The shared credential store.
 *
 * @package BLT_Family
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the one option every BLT plugin on the site shares.
 *
 * Shape:
 *
 *     array(
 *         'cloudflare' => array( 'account_id' => '…', 'api_token' => 'bf1:…' ),
 *         'r2'         => array( … ),
 *     )
 *
 * Fields marked secret in BLT_Family_Groups are stored through
 * BLT_Family_Crypto; everything else is stored as-is. get() always returns
 * plaintext, so callers never deal with envelopes.
 *
 * The option is registered with autoload = no: credentials are needed on the
 * handful of requests that talk to a third-party service, not on every page
 * load of the site.
 */
class BLT_Family_Store {

	const OPTION = 'blt_family_shared';

	/**
	 * In-request cache of the decoded option.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * The whole store, raw (secrets still enveloped).
	 *
	 * @return array<string,array>
	 */
	public static function all_raw() {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );

			self::$cache = is_array( $stored ) ? $stored : array();
		}

		return self::$cache;
	}

	/**
	 * One field's plaintext value.
	 *
	 * @param string $group   Group key.
	 * @param string $field   Field key.
	 * @param string $default Returned when unset or empty.
	 * @return string
	 */
	public static function get( $group, $field, $default = '' ) {
		$all = self::all_raw();

		if ( ! isset( $all[ $group ][ $field ] ) ) {
			return $default;
		}

		$value = $all[ $group ][ $field ];

		if ( ! is_string( $value ) || '' === $value ) {
			return $default;
		}

		if ( BLT_Family_Groups::is_secret( $group, $field ) ) {
			$value = BLT_Family_Crypto::decrypt( $value );
		}

		return '' === $value ? $default : $value;
	}

	/**
	 * Every field in a group, as plaintext.
	 *
	 * @param string $group Group key.
	 * @return array<string,string>
	 */
	public static function get_group( $group ) {
		$definition = BLT_Family_Groups::get( $group );

		if ( null === $definition ) {
			return array();
		}

		$out = array();

		foreach ( array_keys( $definition['fields'] ) as $field ) {
			$out[ $field ] = self::get( $group, $field );
		}

		return $out;
	}

	/**
	 * Write a group's fields.
	 *
	 * Only keys defined for the group are stored — an unknown key is dropped
	 * rather than persisted, so a renamed field can't silently accumulate
	 * stale copies. A field omitted from $values is left untouched; pass an
	 * explicit '' to clear one.
	 *
	 * @param string               $group  Group key.
	 * @param array<string,string> $values Plaintext values keyed by field.
	 * @return bool Whether the option was written.
	 */
	public static function set_group( $group, array $values ) {
		$definition = BLT_Family_Groups::get( $group );

		if ( null === $definition ) {
			return false;
		}

		$all      = self::all_raw();
		$existing = isset( $all[ $group ] ) && is_array( $all[ $group ] ) ? $all[ $group ] : array();

		foreach ( $values as $field => $value ) {
			if ( ! isset( $definition['fields'][ $field ] ) ) {
				continue;
			}

			$value = is_string( $value ) ? trim( $value ) : '';

			if ( '' === $value ) {
				unset( $existing[ $field ] );
				continue;
			}

			$existing[ $field ] = BLT_Family_Groups::is_secret( $group, $field )
				? BLT_Family_Crypto::encrypt( $value )
				: $value;
		}

		if ( empty( $existing ) ) {
			unset( $all[ $group ] );
		} else {
			$all[ $group ] = $existing;
		}

		self::$cache = $all;

		if ( empty( $all ) ) {
			return delete_option( self::OPTION );
		}

		// Explicit autoload = no: these are needed on the few requests that
		// reach a third-party service, not on every front-end page load.
		$updated = update_option( self::OPTION, $all, false );

		if ( ! $updated ) {
			// update_option() returns false when the value is unchanged, which
			// is not a failure. Distinguish it from a genuine write error by
			// re-reading.
			$updated = ( get_option( self::OPTION ) === $all );
		}

		return $updated;
	}

	/**
	 * Whether a group holds any value at all.
	 *
	 * @param string $group Group key.
	 * @return bool
	 */
	public static function group_configured( $group ) {
		$all = self::all_raw();

		return ! empty( $all[ $group ] ) && is_array( $all[ $group ] );
	}

	/**
	 * Drop the in-request cache. For tests and after an external write.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = null;
	}
}
