<?php
/**
 * Speed tweaks and per-handle asset rules.
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;

/**
 * The one-click speed settings the Performance Scan recommends, their
 * front-end runtime behaviour, and per-handle asset rules ("load this
 * script/style everywhere except where it is actually used").
 *
 * Everything lives in a single, small, autoloaded option because it is read on
 * every request — keep it that way: no per-page data, no large blobs. The
 * constructor runs on every request (plugins_loaded), so it only reads that one
 * cached option and registers the hooks for tweaks that are actually switched
 * on; with nothing enabled it registers nothing. Each callback re-checks
 * bypassed() lazily so the Performance Scan's "tweaks off" baseline request and
 * the admin-only `?blt_optimized_safe=1` escape hatch can switch every tweak
 * and rule off for one request without touching the saved settings.
 *
 * Asset rules only ever dequeue — they never deregister — so another plugin
 * that lists the handle as a dependency keeps it loading, which is the safe
 * outcome rather than a broken page.
 */
class BLT_Optimized_Tweaks {

	/**
	 * Option holding every tweak and the asset rules (autoloaded, keep small).
	 */
	const OPTION = 'blt_optimized_tweaks';

	/**
	 * Query argument that switches all tweaks off for one admin request.
	 */
	const SAFE_ARG = 'blt_optimized_safe';

	/**
	 * Maximum number of stored asset rules.
	 */
	const MAX_RULES = 100;

	/**
	 * Valid script/style handle (also what rules and the exclude list accept).
	 */
	const HANDLE_PATTERN = '/^[A-Za-z0-9_\-.\/]+$/';

	/**
	 * Settings snapshot taken when the runtime hooks were registered.
	 *
	 * @var array
	 */
	private $opts = array();

	/**
	 * Per-request memo of condition results.
	 *
	 * @var array<string,bool>
	 */
	private $condition_cache = array();

	/**
	 * Per-request memo of post content (including referenced reusable blocks).
	 *
	 * @var array<int,string>
	 */
	private $haystack_cache = array();

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'disable_emojis'         => 0,
			'disable_embeds'         => 0,
			'remove_jquery_migrate'  => 0,
			'dashicons_logged_out'   => 0,
			'defer_scripts'          => 0,
			'defer_exclude'          => '', // Handles, one per line.
			'heartbeat_frontend'     => 'default', // default|disable.
			'heartbeat_interval'     => 0, // 0 = WordPress default, else seconds.
			'revisions_limit'        => -1, // -1 = WordPress default, 0 = off.
			'disable_self_pingbacks' => 0,
			'preconnect_origins'     => '', // Origins, one per line.
			'wc_cart_fragments'      => 'default', // default|woo_pages_only.
			'rules'                  => array(),
		);
	}

	/**
	 * Human labels for every setting, for the UI and fix messages.
	 *
	 * @return array<string,string>
	 */
	public static function labels() {
		return array(
			'disable_emojis'         => __( 'Disable the emoji script and styles', 'blt-optimized' ),
			'disable_embeds'         => __( 'Disable the WordPress embed script and discovery links', 'blt-optimized' ),
			'remove_jquery_migrate'  => __( 'Remove jQuery Migrate from the front end', 'blt-optimized' ),
			'dashicons_logged_out'   => __( 'Remove Dashicons for logged-out visitors', 'blt-optimized' ),
			'defer_scripts'          => __( 'Defer JavaScript', 'blt-optimized' ),
			'defer_exclude'          => __( 'Scripts never to defer', 'blt-optimized' ),
			'heartbeat_frontend'     => __( 'Front-end Heartbeat', 'blt-optimized' ),
			'heartbeat_interval'     => __( 'Admin Heartbeat interval', 'blt-optimized' ),
			'revisions_limit'        => __( 'Post revisions kept per post', 'blt-optimized' ),
			'disable_self_pingbacks' => __( 'Disable self-pingbacks', 'blt-optimized' ),
			'preconnect_origins'     => __( 'Preconnect origins', 'blt-optimized' ),
			'wc_cart_fragments'      => __( 'WooCommerce cart fragments', 'blt-optimized' ),
			'rules'                  => __( 'Asset rules', 'blt-optimized' ),
		);
	}

	/**
	 * Settings that are checkboxes in the settings form.
	 *
	 * @return string[]
	 */
	private static function flag_keys() {
		return array(
			'disable_emojis',
			'disable_embeds',
			'remove_jquery_migrate',
			'dashicons_logged_out',
			'defer_scripts',
			'disable_self_pingbacks',
		);
	}

	/**
	 * Condition types an asset rule may use.
	 *
	 * @return string[]
	 */
	public static function condition_types() {
		return array( 'front_page', 'post_type', 'post_ids', 'woocommerce', 'content_contains', 'url_contains' );
	}

	/* ------------------------------------------------------------------ */
	/* Storage                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Current settings merged over the defaults.
	 *
	 * Every value is normalized on the way out, so a hand-edited or corrupted
	 * option falls back to the default for that setting instead of, say,
	 * turning revisions off. Rules are returned as stored; rules() and the
	 * runtime validate them.
	 *
	 * @return array
	 */
	public static function get() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$out    = array();

		foreach ( self::defaults() as $key => $default ) {
			if ( 'rules' === $key ) {
				$out[ $key ] = ( isset( $stored[ $key ] ) && is_array( $stored[ $key ] ) ) ? $stored[ $key ] : array();
			} elseif ( ! array_key_exists( $key, $stored ) ) {
				$out[ $key ] = $default;
			} else {
				$value       = self::normalize( $key, $stored[ $key ], false );
				$out[ $key ] = ( null === $value ) ? $default : $value;
			}
		}
		return $out;
	}

	/**
	 * Merge changes into the saved settings, sanitize and save.
	 *
	 * Unknown keys are ignored. The `rules` key may be passed to replace the
	 * whole rule list; use add_rule() and friends to change a single rule.
	 *
	 * @param array $changes Setting => value.
	 * @return array The sanitized settings now stored.
	 */
	public static function update( array $changes ) {
		$input = array_merge( self::get(), array_intersect_key( $changes, self::defaults() ) );
		$new   = self::sanitize( $input );

		self::persist( $new );
		return $new;
	}

	/**
	 * Save a settings array. Autoloaded: it is read on every request.
	 *
	 * @param array $options Already-sanitized settings.
	 * @return void
	 */
	private static function persist( array $options ) {
		update_option( self::OPTION, $options, true );
	}

	/**
	 * Sanitize a settings array. Also the register_setting() callback for the
	 * settings form.
	 *
	 * - Keys missing from the input keep their current value, so a form that
	 *   does not submit `rules` never wipes them.
	 * - Checkboxes are only treated as "off when absent" when the input comes
	 *   from the full form, which marks itself with a hidden `_form` = 1.
	 * - Invalid values keep the current value instead of silently resetting it.
	 * - Idempotent: running it on its own output changes nothing.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$current = self::get();
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$from_form = ! empty( $input['_form'] );
		$out       = array();

		foreach ( array_keys( self::defaults() ) as $key ) {
			if ( 'rules' === $key ) {
				$out[ $key ] = array_key_exists( 'rules', $input )
					? self::sanitize_rules( $input['rules'] )
					: self::sanitize_rules( $current['rules'] );
				continue;
			}

			if ( array_key_exists( $key, $input ) ) {
				$value       = self::normalize( $key, $input[ $key ], false );
				$out[ $key ] = ( null === $value ) ? $current[ $key ] : $value;
			} elseif ( $from_form && in_array( $key, self::flag_keys(), true ) ) {
				$out[ $key ] = 0;
			} else {
				$out[ $key ] = $current[ $key ];
			}
		}

		return $out;
	}

	/**
	 * Strictly validate one setting value (for programmatic callers such as the
	 * fixes dispatcher, which must reject bad input rather than coerce it).
	 *
	 * @param string $key   Setting key (not `rules`).
	 * @param mixed  $value Proposed value.
	 * @return mixed|WP_Error The normalized value, or an error.
	 */
	public static function validate( $key, $value ) {
		if ( ! is_string( $key ) || 'rules' === $key || ! array_key_exists( $key, self::defaults() ) ) {
			return new WP_Error( 'blt_invalid_tweak', __( 'Unknown speed tweak.', 'blt-optimized' ) );
		}

		$normalized = self::normalize( $key, $value, true );
		if ( null === $normalized ) {
			return new WP_Error(
				'blt_invalid_tweak_value',
				sprintf(
					/* translators: %s: setting label. */
					__( 'Invalid value for "%s".', 'blt-optimized' ),
					self::labels()[ $key ]
				)
			);
		}
		return $normalized;
	}

	/**
	 * Normalize one setting value.
	 *
	 * @param string $key    Setting key.
	 * @param mixed  $value  Raw value.
	 * @param bool   $strict When true, a list containing any invalid entry is
	 *                       rejected instead of having the entry dropped.
	 * @return mixed|null Normalized value, or null when invalid.
	 */
	private static function normalize( $key, $value, $strict = false ) {
		switch ( $key ) {
			case 'defer_exclude':
				if ( ! is_string( $value ) && ! is_array( $value ) ) {
					return null;
				}
				list( $items, $invalid ) = self::parse_handles( $value, true );
				return ( $strict && $invalid ) ? null : implode( "\n", $items );

			case 'preconnect_origins':
				if ( ! is_string( $value ) && ! is_array( $value ) ) {
					return null;
				}
				list( $items, $invalid ) = self::parse_origins( $value );
				return ( $strict && $invalid ) ? null : implode( "\n", $items );

			case 'heartbeat_frontend':
				return ( is_string( $value ) && in_array( $value, array( 'default', 'disable' ), true ) ) ? $value : null;

			case 'wc_cart_fragments':
				return ( is_string( $value ) && in_array( $value, array( 'default', 'woo_pages_only' ), true ) ) ? $value : null;

			case 'heartbeat_interval':
				$int = self::to_int( $value );
				return ( null !== $int && in_array( $int, array( 0, 15, 30, 60, 120 ), true ) ) ? $int : null;

			case 'revisions_limit':
				$int = self::to_int( $value );
				return ( null !== $int && $int >= -1 && $int <= 1000 ) ? $int : null;

			case 'rules':
				return null;

			default:
				return self::normalize_flag( $value );
		}
	}

	/**
	 * Normalize a checkbox value to 1 or 0.
	 *
	 * @param mixed $value Raw value.
	 * @return int|null
	 */
	private static function normalize_flag( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 1 : 0;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			if ( 1.0 === (float) $value ) {
				return 1;
			}
			return ( 0.0 === (float) $value ) ? 0 : null;
		}
		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );
			if ( in_array( $value, array( '1', 'on', 'yes', 'true' ), true ) ) {
				return 1;
			}
			if ( in_array( $value, array( '0', 'off', 'no', 'false' ), true ) ) {
				return 0;
			}
		}
		return null;
	}

	/**
	 * Strictly convert a whole-number value to int.
	 *
	 * @param mixed $value Raw value.
	 * @return int|null
	 */
	private static function to_int( $value ) {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( ( is_string( $value ) || is_float( $value ) ) && is_numeric( $value ) && (float) $value === (float) (int) $value ) {
			return (int) $value;
		}
		return null;
	}

	/**
	 * Split a handle list (newline/comma/space separated, or an array).
	 *
	 * @param string|array $value          Raw list.
	 * @param bool         $allow_wildcard Whether `*` wildcards are allowed.
	 * @return array { 0: string[] unique valid handles, 1: int dropped entries }
	 */
	private static function parse_handles( $value, $allow_wildcard = false ) {
		$tokens  = self::tokens( $value );
		$pattern = $allow_wildcard ? '/^[A-Za-z0-9_\-.\/*]+$/' : self::HANDLE_PATTERN;
		$items   = array();
		$invalid = 0;

		foreach ( $tokens as $token ) {
			if ( strlen( $token ) > 150 || ! preg_match( $pattern, $token ) ) {
				++$invalid;
				continue;
			}
			$items[ $token ] = $token;
		}
		if ( count( $items ) > 200 ) {
			$invalid += count( $items ) - 200;
			$items    = array_slice( $items, 0, 200, true );
		}
		return array( array_values( $items ), $invalid );
	}

	/**
	 * Split an origin list and reduce each entry to scheme://host[:port].
	 *
	 * @param string|array $value Raw list.
	 * @return array { 0: string[] unique origins, 1: int dropped entries }
	 */
	private static function parse_origins( $value ) {
		$items   = array();
		$invalid = 0;

		foreach ( self::tokens( $value ) as $token ) {
			$origin = self::parse_origin( $token );
			if ( false === $origin ) {
				++$invalid;
				continue;
			}
			$items[ $origin ] = $origin;
		}
		if ( count( $items ) > 30 ) {
			$invalid += count( $items ) - 30;
			$items    = array_slice( $items, 0, 30, true );
		}
		return array( array_values( $items ), $invalid );
	}

	/**
	 * Reduce a URL or bare host to a lowercase origin.
	 *
	 * @param string $token URL or host.
	 * @return string|false
	 */
	private static function parse_origin( $token ) {
		$token = trim( (string) $token );
		if ( '' === $token || strlen( $token ) > 300 ) {
			return false;
		}
		if ( 0 === strpos( $token, '//' ) ) {
			$token = 'https:' . $token;
		} elseif ( false === strpos( $token, '://' ) ) {
			$token = 'https://' . $token;
		}

		$parts = wp_parse_url( $token );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return false;
		}
		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || ! preg_match( '/^[a-z0-9]([a-z0-9.\-]*[a-z0-9])?$/', $host ) ) {
			return false;
		}

		$origin = $scheme . '://' . $host;
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}
		return $origin;
	}

	/**
	 * Tokenize a list value.
	 *
	 * @param string|array $value Raw list.
	 * @return string[]
	 */
	private static function tokens( $value ) {
		if ( is_array( $value ) ) {
			$tokens = array();
			foreach ( $value as $entry ) {
				if ( is_scalar( $entry ) ) {
					$tokens[] = trim( (string) $entry );
				}
			}
			return array_values( array_filter( $tokens, 'strlen' ) );
		}
		return preg_split( '/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY );
	}

	/* ------------------------------------------------------------------ */
	/* Asset rules                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * All stored asset rules.
	 *
	 * @return array[]
	 */
	public static function rules() {
		return self::sanitize_rules( self::get()['rules'] );
	}

	/**
	 * Add an asset rule. A rule for the same type + handle replaces the
	 * existing one (keeping its id).
	 *
	 * @param array $rule { type, handle, mode, conditions[], enabled?, note?, id?, created? }.
	 * @return array|WP_Error The stored rule.
	 */
	public static function add_rule( array $rule ) {
		$built = self::build_rule( $rule );
		if ( is_wp_error( $built ) ) {
			return $built;
		}

		$options  = self::get();
		$rules    = self::sanitize_rules( $options['rules'] );
		$replaced = false;

		foreach ( $rules as $index => $existing ) {
			if ( $existing['type'] === $built['type'] && $existing['handle'] === $built['handle'] ) {
				$built['id']      = $existing['id'];
				$rules[ $index ] = $built;
				$replaced        = true;
				break;
			}
		}

		if ( ! $replaced ) {
			if ( count( $rules ) >= self::MAX_RULES ) {
				return new WP_Error(
					'blt_too_many_rules',
					sprintf(
						/* translators: %d: maximum number of rules. */
						__( 'You can have at most %d asset rules. Remove one first.', 'blt-optimized' ),
						self::MAX_RULES
					)
				);
			}

			$taken = array_column( $rules, 'id' );
			while ( in_array( $built['id'], $taken, true ) ) {
				$built['id'] = self::new_rule_id();
			}
			$rules[] = $built;
		}

		$options['rules'] = array_values( $rules );
		self::persist( $options );
		return $built;
	}

	/**
	 * Remove an asset rule.
	 *
	 * @param string $id Rule id.
	 * @return bool True when a rule was removed.
	 */
	public static function remove_rule( $id ) {
		$options = self::get();
		$rules   = self::sanitize_rules( $options['rules'] );
		$kept    = array();

		foreach ( $rules as $rule ) {
			if ( $rule['id'] !== (string) $id ) {
				$kept[] = $rule;
			}
		}
		if ( count( $kept ) === count( $rules ) ) {
			return false;
		}

		$options['rules'] = $kept;
		self::persist( $options );
		return true;
	}

	/**
	 * Enable or disable an asset rule without deleting it.
	 *
	 * @param string $id      Rule id.
	 * @param bool   $enabled Whether the rule should apply.
	 * @return bool True when the rule exists.
	 */
	public static function set_rule_enabled( $id, $enabled ) {
		$options = self::get();
		$rules   = self::sanitize_rules( $options['rules'] );
		$found   = false;

		foreach ( $rules as $index => $rule ) {
			if ( $rule['id'] === (string) $id ) {
				$rules[ $index ]['enabled'] = (bool) $enabled;
				$found                      = true;
				break;
			}
		}
		if ( ! $found ) {
			return false;
		}

		$options['rules'] = $rules;
		self::persist( $options );
		return true;
	}

	/**
	 * Generate a rule id: `r_` plus 8 alphanumerics.
	 *
	 * @return string
	 */
	private static function new_rule_id() {
		// wp_generate_password() is pluggable and may not be loaded yet.
		$random = function_exists( 'wp_generate_password' )
			? wp_generate_password( 8, false, false )
			: substr( md5( uniqid( '', true ) ), 0, 8 );
		return 'r_' . $random;
	}

	/**
	 * Sanitize a stored/submitted rule list: drop invalid rules, dedupe by
	 * type + handle and by id, cap the total.
	 *
	 * @param mixed $rules Raw rules.
	 * @return array[]
	 */
	private static function sanitize_rules( $rules ) {
		if ( ! is_array( $rules ) ) {
			return array();
		}

		$out  = array();
		$ids  = array();
		$seen = array();

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$built = self::build_rule( $rule );
			if ( is_wp_error( $built ) ) {
				continue;
			}

			$pair = $built['type'] . ':' . $built['handle'];
			if ( isset( $seen[ $pair ] ) ) {
				continue;
			}
			$seen[ $pair ] = true;

			while ( isset( $ids[ $built['id'] ] ) ) {
				$built['id'] = self::new_rule_id();
			}
			$ids[ $built['id'] ] = true;

			$out[] = $built;
			if ( count( $out ) >= self::MAX_RULES ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Validate and normalize one rule. Strict: any invalid condition rejects
	 * the whole rule, because dropping a condition from an "except" rule would
	 * make it dequeue the asset on more pages than intended.
	 *
	 * @param array $rule Raw rule.
	 * @return array|WP_Error
	 */
	private static function build_rule( array $rule ) {
		$type = ( isset( $rule['type'] ) && is_string( $rule['type'] ) ) ? $rule['type'] : '';
		if ( ! in_array( $type, array( 'script', 'style' ), true ) ) {
			return new WP_Error( 'blt_invalid_rule', __( 'An asset rule type must be "script" or "style".', 'blt-optimized' ) );
		}

		$handle = ( isset( $rule['handle'] ) && is_string( $rule['handle'] ) ) ? trim( $rule['handle'] ) : '';
		if ( '' === $handle || strlen( $handle ) > 150 || ! preg_match( self::HANDLE_PATTERN, $handle ) ) {
			return new WP_Error( 'blt_invalid_rule', __( 'That is not a valid script or style handle.', 'blt-optimized' ) );
		}

		$mode = ( isset( $rule['mode'] ) && is_string( $rule['mode'] ) ) ? $rule['mode'] : '';
		if ( ! in_array( $mode, array( 'everywhere', 'except' ), true ) ) {
			return new WP_Error( 'blt_invalid_rule', __( 'An asset rule mode must be "everywhere" or "except".', 'blt-optimized' ) );
		}

		$conditions = array();
		if ( 'except' === $mode ) {
			$raw = ( isset( $rule['conditions'] ) && is_array( $rule['conditions'] ) ) ? $rule['conditions'] : array();
			foreach ( $raw as $condition ) {
				$built = self::build_condition( $condition );
				if ( is_wp_error( $built ) ) {
					return $built;
				}
				$conditions[ $built['type'] . '|' . $built['value'] ] = $built;
			}
			if ( empty( $conditions ) ) {
				return new WP_Error( 'blt_invalid_rule', __( 'An "except" rule needs at least one condition; without one it would remove the asset everywhere.', 'blt-optimized' ) );
			}
			if ( count( $conditions ) > 20 ) {
				return new WP_Error( 'blt_invalid_rule', __( 'An asset rule can have at most 20 conditions.', 'blt-optimized' ) );
			}
		}

		$id = ( isset( $rule['id'] ) && is_string( $rule['id'] ) && preg_match( '/^r_[A-Za-z0-9]{8}$/', $rule['id'] ) ) ? $rule['id'] : self::new_rule_id();

		$enabled = true;
		if ( array_key_exists( 'enabled', $rule ) ) {
			$flag    = self::normalize_flag( $rule['enabled'] );
			$enabled = ( null === $flag ) ? true : (bool) $flag;
		}

		$note = ( isset( $rule['note'] ) && is_scalar( $rule['note'] ) ) ? mb_substr( sanitize_text_field( (string) $rule['note'] ), 0, 200 ) : '';

		$created = ( isset( $rule['created'] ) && is_numeric( $rule['created'] ) && (int) $rule['created'] > 0 ) ? (int) $rule['created'] : time();

		return array(
			'id'         => $id,
			'type'       => $type,
			'handle'     => $handle,
			'mode'       => $mode,
			'conditions' => array_values( $conditions ),
			'enabled'    => $enabled,
			'note'       => $note,
			'created'    => $created,
		);
	}

	/**
	 * Validate and normalize one rule condition.
	 *
	 * @param mixed $condition Raw condition.
	 * @return array|WP_Error { type, value }
	 */
	private static function build_condition( $condition ) {
		$type = ( is_array( $condition ) && isset( $condition['type'] ) && is_string( $condition['type'] ) ) ? $condition['type'] : '';
		if ( ! in_array( $type, self::condition_types(), true ) ) {
			return new WP_Error( 'blt_invalid_rule', __( 'Unknown asset rule condition type.', 'blt-optimized' ) );
		}

		$value = ( isset( $condition['value'] ) && is_scalar( $condition['value'] ) ) ? (string) $condition['value'] : '';
		$value = trim( preg_replace( '/[\x00-\x1F\x7F]/', '', wp_check_invalid_utf8( $value ) ) );

		switch ( $type ) {
			case 'front_page':
			case 'woocommerce':
				$value = '';
				break;

			case 'post_type':
				$types = array();
				foreach ( preg_split( '/[\s,]+/', strtolower( $value ), -1, PREG_SPLIT_NO_EMPTY ) as $post_type ) {
					if ( sanitize_key( $post_type ) !== $post_type || strlen( $post_type ) > 20 ) {
						return new WP_Error( 'blt_invalid_rule', __( 'That is not a valid post type name.', 'blt-optimized' ) );
					}
					$types[ $post_type ] = $post_type;
				}
				if ( empty( $types ) ) {
					return new WP_Error( 'blt_invalid_rule', __( 'Enter at least one post type.', 'blt-optimized' ) );
				}
				$value = implode( ',', $types );
				break;

			case 'post_ids':
				$ids = array();
				foreach ( preg_split( '/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY ) as $post_id ) {
					if ( ! ctype_digit( $post_id ) || (int) $post_id < 1 ) {
						return new WP_Error( 'blt_invalid_rule', __( 'Post IDs must be positive whole numbers separated by commas.', 'blt-optimized' ) );
					}
					$ids[ (int) $post_id ] = (int) $post_id;
				}
				if ( empty( $ids ) || count( $ids ) > 100 ) {
					return new WP_Error( 'blt_invalid_rule', __( 'Enter between 1 and 100 post IDs.', 'blt-optimized' ) );
				}
				$value = implode( ',', $ids );
				break;

			default: // content_contains, url_contains.
				if ( '' === $value || strlen( $value ) > 200 ) {
					return new WP_Error( 'blt_invalid_rule', __( 'The match text must be between 1 and 200 characters.', 'blt-optimized' ) );
				}
		}

		return array(
			'type'  => $type,
			'value' => $value,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Bypass                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Whether every tweak and rule must be skipped for this request.
	 *
	 * True during a Performance Scan "tweaks off" baseline profile, and when an
	 * administrator adds `?blt_optimized_safe=1` to any URL — the escape hatch
	 * for a front-end that a tweak has broken. The capability is checked
	 * lazily (pluggable functions are not available on every setup at
	 * plugins_loaded) and never for anonymous visitors, so the argument cannot
	 * be used to defeat the tweaks for other people.
	 *
	 * @return bool
	 */
	public static function bypassed() {
		if ( class_exists( 'BLT_Optimized_Profiler', false )
			&& method_exists( 'BLT_Optimized_Profiler', 'tweaks_disabled' )
			&& BLT_Optimized_Profiler::tweaks_disabled()
		) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::SAFE_ARG ] ) || ! is_scalar( $_GET[ self::SAFE_ARG ] ) || '0' === (string) $_GET[ self::SAFE_ARG ] ) {
			return false;
		}

		if ( ! did_action( 'init' ) || ! function_exists( 'wp_get_current_user' ) || ! function_exists( 'current_user_can' ) ) {
			return false;
		}
		return current_user_can( 'manage_options' );
	}

	/* ------------------------------------------------------------------ */
	/* Runtime                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Register runtime hooks for the tweaks that are switched on.
	 *
	 * Front-end hooks are skipped entirely in wp-admin. Revisions, self
	 * pingbacks and the Heartbeat interval are registered everywhere because
	 * posts are saved (and Heartbeat runs) in wp-admin and over REST.
	 */
	public function __construct() {
		$o = self::get();

		// Runtime code relies on a well-formed rule list, so validate it once here.
		$o['rules'] = empty( $o['rules'] ) ? array() : self::sanitize_rules( $o['rules'] );

		$this->opts = $o;
		$front      = ! is_admin();

		if ( ! empty( $o['disable_emojis'] ) ) {
			add_action( 'init', array( $this, 'apply_emojis' ), 20 );
			if ( ! $front ) {
				// wp-admin registers its emoji hooks after init (admin-filters.php).
				add_action( 'admin_init', array( $this, 'apply_emojis' ), 20 );
			}
		}

		if ( $front && ! empty( $o['disable_embeds'] ) ) {
			add_action( 'init', array( $this, 'apply_embeds' ), 20 );
		}

		if ( (int) $o['revisions_limit'] > -1 ) {
			add_filter( 'wp_revisions_to_keep', array( $this, 'revisions_to_keep' ), 20, 2 );
		}

		if ( ! empty( $o['disable_self_pingbacks'] ) ) {
			add_action( 'pre_ping', array( $this, 'strip_self_pings' ) );
		}

		if ( (int) $o['heartbeat_interval'] > 0 ) {
			add_filter( 'heartbeat_settings', array( $this, 'heartbeat_settings' ), 20 );
		}

		if ( ! $front ) {
			return;
		}

		if ( ! empty( $o['remove_jquery_migrate'] ) ) {
			// After core registers jquery (priority 10) and any plugin re-registers it.
			add_action( 'wp_default_scripts', array( $this, 'drop_jquery_migrate' ), 100 );
		}

		$has_rule = false;
		foreach ( $o['rules'] as $rule ) {
			if ( $rule['enabled'] ) {
				$has_rule = true;
				break;
			}
		}

		if ( $has_rule
			|| ! empty( $o['disable_embeds'] )
			|| ! empty( $o['dashicons_logged_out'] )
			|| 'woo_pages_only' === $o['wc_cart_fragments']
		) {
			// Late enough to catch handles enqueued by shortcodes and blocks.
			add_action( 'wp_enqueue_scripts', array( $this, 'run_dequeues' ), PHP_INT_MAX );
			add_action( 'wp_print_styles', array( $this, 'run_dequeues' ), 0 );
			add_action( 'wp_print_scripts', array( $this, 'run_dequeues' ), 0 );
			add_action( 'wp_print_footer_scripts', array( $this, 'run_dequeues' ), 0 );
		}

		if ( 'disable' === $o['heartbeat_frontend'] ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'disable_heartbeat' ), PHP_INT_MAX );
			add_action( 'wp_print_scripts', array( $this, 'disable_heartbeat' ), 0 );
			add_action( 'wp_print_footer_scripts', array( $this, 'disable_heartbeat' ), 0 );
		}

		if ( ! empty( $o['defer_scripts'] ) && self::supports_strategy() ) {
			add_action( 'wp_print_scripts', array( $this, 'defer_scripts' ), 0 );
			add_action( 'wp_print_footer_scripts', array( $this, 'defer_scripts' ), 0 );
		}

		if ( '' !== trim( (string) $o['preconnect_origins'] ) ) {
			add_filter( 'wp_resource_hints', array( $this, 'resource_hints' ), 20, 2 );
		}
	}

	/**
	 * Whether this WordPress has the script loading-strategy API (6.3+).
	 *
	 * @return bool
	 */
	private static function supports_strategy() {
		return class_exists( 'WP_Scripts' ) && property_exists( 'WP_Scripts', 'delayed_strategies' );
	}

	/**
	 * Whether this is a real front-end page view that tweaks should reshape:
	 * the main query ran, and it is not the Customizer preview, an embed or a
	 * known page-builder editor.
	 *
	 * @return bool
	 */
	private static function is_page_view() {
		if ( ! did_action( 'wp' ) ) {
			return false;
		}
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return false;
		}
		if ( function_exists( 'is_embed' ) && is_embed() ) {
			return false;
		}

		$builder_args = array( 'elementor-preview', 'et_fb', 'fl_builder', 'ct_builder', 'bricks', 'vc_editable', 'brizy-edit', 'tve' );
		foreach ( $builder_args as $arg ) {
			if ( isset( $_GET[ $arg ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return false;
			}
		}

		/**
		 * Filters whether the current request is an editing/preview context in
		 * which script deferral and Heartbeat changes must be skipped.
		 *
		 * @param bool $skip Whether to skip.
		 */
		return ! apply_filters( 'blt_optimized_skip_page_tweaks', false );
	}

	/**
	 * Remove WordPress's emoji detection script, styles and conversions.
	 *
	 * @return void
	 */
	public function apply_emojis() {
		if ( self::bypassed() ) {
			return;
		}

		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'embed_head', 'print_emoji_detection_script' );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
		remove_action( 'admin_enqueue_scripts', 'wp_enqueue_emoji_styles' );
		remove_action( 'enqueue_embed_scripts', 'wp_enqueue_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );

		// Also drops the s.w.org dns-prefetch hint.
		add_filter( 'emoji_svg_url', '__return_false' );
		add_filter( 'tiny_mce_plugins', array( $this, 'tinymce_plugins' ) );
	}

	/**
	 * Drop the TinyMCE emoji plugin.
	 *
	 * @param mixed $plugins TinyMCE plugin list.
	 * @return mixed
	 */
	public function tinymce_plugins( $plugins ) {
		return is_array( $plugins ) ? array_values( array_diff( $plugins, array( 'wpemoji' ) ) ) : $plugins;
	}

	/**
	 * Stop printing the oEmbed discovery links and the wp-embed host script.
	 *
	 * The wp-embed handle is dequeued as well by run_dequeues(). Rewrite rules
	 * and the /embed/ endpoint are left alone.
	 *
	 * @return void
	 */
	public function apply_embeds() {
		if ( self::bypassed() ) {
			return;
		}

		// Hooked twice by core (the second is unhooked on first run).
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links', 4 );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		// wp_maybe_enqueue_oembed_host_js() only enqueues wp-embed while this hook exists.
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
	}

	/**
	 * Remove jQuery Migrate from jquery's dependencies, keeping jquery-core.
	 *
	 * Only the dependency edge is removed: the jquery-migrate handle stays
	 * registered, so a plugin that explicitly depends on it still gets it.
	 *
	 * @param WP_Scripts $scripts Script registry.
	 * @return void
	 */
	public function drop_jquery_migrate( $scripts ) {
		if ( ! $scripts instanceof WP_Scripts || self::bypassed() ) {
			return;
		}
		if ( isset( $scripts->registered['jquery'] ) && ! empty( $scripts->registered['jquery']->deps ) ) {
			$scripts->registered['jquery']->deps = array_values( array_diff( $scripts->registered['jquery']->deps, array( 'jquery-migrate' ) ) );
		}
	}

	/**
	 * Dequeue everything the tweaks and enabled asset rules say to remove.
	 *
	 * Runs at several hooks (see __construct) so assets enqueued late — in
	 * shortcodes or blocks — are caught too. Dequeue is idempotent, never
	 * deregisters, and cannot remove a handle another enqueued handle depends
	 * on; that is the safe outcome.
	 *
	 * @return void
	 */
	public function run_dequeues() {
		if ( ! did_action( 'wp' ) || self::bypassed() ) {
			return;
		}

		$o       = $this->opts;
		$scripts = array();
		$styles  = array();

		if ( ! empty( $o['disable_embeds'] ) ) {
			$scripts[] = 'wp-embed';
		}

		if ( ! empty( $o['dashicons_logged_out'] ) && ! is_user_logged_in() && ! is_admin_bar_showing() ) {
			$styles[] = 'dashicons';
		}

		if ( 'woo_pages_only' === $o['wc_cart_fragments'] && function_exists( 'is_woocommerce' ) ) {
			$on_woo = is_woocommerce()
				|| ( function_exists( 'is_cart' ) && is_cart() )
				|| ( function_exists( 'is_checkout' ) && is_checkout() )
				|| ( function_exists( 'is_account_page' ) && is_account_page() );
			if ( ! $on_woo ) {
				$scripts[] = 'wc-cart-fragments';
			}
		}

		foreach ( $o['rules'] as $rule ) {
			if ( ! $this->rule_removes( $rule ) ) {
				continue;
			}
			if ( 'style' === $rule['type'] ) {
				$styles[] = $rule['handle'];
			} else {
				$scripts[] = $rule['handle'];
			}
		}

		foreach ( array_unique( $scripts ) as $handle ) {
			wp_dequeue_script( $handle );
		}
		foreach ( array_unique( $styles ) as $handle ) {
			wp_dequeue_style( $handle );
		}
	}

	/**
	 * Whether a rule removes its handle on this request.
	 *
	 * @param array $rule Sanitized rule.
	 * @return bool
	 */
	private function rule_removes( array $rule ) {
		if ( ! $rule['enabled'] ) {
			return false;
		}
		if ( 'everywhere' === $rule['mode'] ) {
			return true;
		}

		foreach ( $rule['conditions'] as $condition ) {
			if ( $this->condition_matches( $condition ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether one rule condition matches the current request.
	 *
	 * @param array $condition { type, value }.
	 * @return bool
	 */
	private function condition_matches( array $condition ) {
		$type  = isset( $condition['type'] ) ? (string) $condition['type'] : '';
		$value = isset( $condition['value'] ) ? (string) $condition['value'] : '';
		$key   = $type . '|' . $value;

		if ( isset( $this->condition_cache[ $key ] ) ) {
			return $this->condition_cache[ $key ];
		}

		$match = false;
		switch ( $type ) {
			case 'front_page':
				$match = is_front_page();
				break;

			case 'post_type':
				foreach ( explode( ',', $value ) as $post_type ) {
					if ( '' === $post_type ) {
						continue;
					}
					if ( is_singular( $post_type ) || is_post_type_archive( $post_type ) || ( 'post' === $post_type && is_home() ) ) {
						$match = true;
						break;
					}
				}
				break;

			case 'post_ids':
				if ( is_singular() || is_home() ) {
					$queried = (int) get_queried_object_id();
					$match   = $queried > 0 && in_array( $queried, array_map( 'intval', explode( ',', $value ) ), true );
				}
				break;

			case 'woocommerce':
				$match = ( function_exists( 'is_woocommerce' ) && is_woocommerce() )
					|| ( function_exists( 'is_cart' ) && is_cart() )
					|| ( function_exists( 'is_checkout' ) && is_checkout() )
					|| ( function_exists( 'is_account_page' ) && is_account_page() );
				break;

			case 'content_contains':
				$post = get_queried_object();
				if ( $post instanceof WP_Post && '' !== $value ) {
					$match = false !== stripos( $this->post_haystack( $post ), $value );
				}
				break;

			case 'url_contains':
				$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$match = '' !== $value && false !== stripos( $uri, $value );
				break;
		}

		$this->condition_cache[ $key ] = (bool) $match;
		return (bool) $match;
	}

	/**
	 * A post's content plus the content of the reusable blocks it embeds, so a
	 * form placed in a synced pattern still counts as "on this page".
	 *
	 * @param WP_Post $post Queried post.
	 * @return string
	 */
	private function post_haystack( WP_Post $post ) {
		$id = (int) $post->ID;
		if ( isset( $this->haystack_cache[ $id ] ) ) {
			return $this->haystack_cache[ $id ];
		}

		$haystack = (string) $post->post_content;
		$pending  = array( $haystack );
		$seen     = array( $id => true );
		$budget   = 20;

		while ( $pending && $budget > 0 ) {
			$chunk = array_shift( $pending );
			if ( false === strpos( $chunk, 'wp:block' ) ) {
				continue;
			}
			if ( ! preg_match_all( '/<!--\s+wp:block\s+\{[^}]*?"ref":(\d+)/', $chunk, $matches ) ) {
				continue;
			}
			foreach ( array_unique( $matches[1] ) as $ref ) {
				$ref = (int) $ref;
				if ( $budget < 1 ) {
					break;
				}
				if ( isset( $seen[ $ref ] ) ) {
					continue;
				}
				$seen[ $ref ] = true;
				--$budget;

				$block = get_post( $ref );
				if ( $block instanceof WP_Post && 'wp_block' === $block->post_type ) {
					$haystack .= "\n" . $block->post_content;
					$pending[] = (string) $block->post_content;
				}
			}
		}

		$this->haystack_cache[ $id ] = $haystack;
		return $haystack;
	}

	/**
	 * Deregister the Heartbeat script on the front end.
	 *
	 * @return void
	 */
	public function disable_heartbeat() {
		if ( self::bypassed() || ! self::is_page_view() ) {
			return;
		}
		wp_deregister_script( 'heartbeat' );
	}

	/**
	 * Heartbeat interval (seconds) for wp-admin and any front-end Heartbeat.
	 *
	 * @param array $settings Heartbeat settings.
	 * @return array
	 */
	public function heartbeat_settings( $settings ) {
		$interval = (int) $this->opts['heartbeat_interval'];
		if ( $interval < 1 || self::bypassed() ) {
			return $settings;
		}
		$settings             = is_array( $settings ) ? $settings : array();
		$settings['interval'] = $interval;
		return $settings;
	}

	/**
	 * Cap the revisions kept per post. 0 turns revisions off.
	 *
	 * Post types that do not support revisions are left alone.
	 *
	 * @param int          $num  Revisions WordPress would keep.
	 * @param WP_Post|null $post Post.
	 * @return int
	 */
	public function revisions_to_keep( $num, $post = null ) {
		$limit = (int) $this->opts['revisions_limit'];
		if ( $limit < 0 || self::bypassed() ) {
			return $num;
		}
		if ( $post instanceof WP_Post && ! post_type_supports( $post->post_type, 'revisions' ) ) {
			return $num;
		}
		return $limit;
	}

	/**
	 * Remove links to this site from the list of URLs to ping.
	 *
	 * @param array $links Links found in the post (passed by reference by core).
	 * @return void
	 */
	public function strip_self_pings( &$links ) {
		if ( ! is_array( $links ) || self::bypassed() ) {
			return;
		}

		$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $home ) {
			return;
		}

		foreach ( $links as $key => $link ) {
			$host = strtolower( (string) wp_parse_url( (string) $link, PHP_URL_HOST ) );
			if ( $host === $home ) {
				unset( $links[ $key ] );
			}
		}
	}

	/**
	 * Add preconnect resource hints for the configured origins.
	 *
	 * @param array  $urls          Hint URLs (strings or attribute arrays).
	 * @param string $relation_type Hint type.
	 * @return array
	 */
	public function resource_hints( $urls, $relation_type ) {
		if ( 'preconnect' !== $relation_type || ! is_array( $urls ) || self::bypassed() ) {
			return $urls;
		}

		list( $origins ) = self::parse_origins( (string) $this->opts['preconnect_origins'] );

		/**
		 * Filters hosts whose preconnect hint needs the crossorigin attribute
		 * (font and other CORS-fetched origins).
		 *
		 * @param string[] $hosts Lowercase hostnames.
		 */
		$cors_hosts = (array) apply_filters( 'blt_optimized_preconnect_crossorigin_hosts', array( 'fonts.gstatic.com' ) );

		foreach ( $origins as $origin ) {
			$host       = strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) );
			$needs_cors = in_array( $host, $cors_hosts, true );
			$present    = false;

			foreach ( $urls as $index => $existing ) {
				$href = is_array( $existing ) ? ( isset( $existing['href'] ) ? (string) $existing['href'] : '' ) : (string) $existing;
				if ( '' === $href || self::parse_origin( $href ) !== $origin ) {
					continue;
				}
				$present = true;

				// WordPress keeps only the first hint per origin, so an existing
				// hint without crossorigin must be upgraded rather than skipped.
				if ( $needs_cors && ( ! is_array( $existing ) || ( ! isset( $existing['crossorigin'] ) && ! in_array( 'crossorigin', $existing, true ) ) ) ) {
					$entry                = is_array( $existing ) ? $existing : array( 'href' => $href );
					$entry['crossorigin'] = 'anonymous';
					$urls[ $index ]       = $entry;
				}
				break;
			}

			if ( ! $present ) {
				$entry = array( 'href' => $origin );
				if ( $needs_cors ) {
					$entry['crossorigin'] = 'anonymous';
				}
				$urls[] = $entry;
			}
		}

		return $urls;
	}

	/**
	 * Mark enqueued front-end scripts as deferred, using the WordPress 6.3+
	 * script loading-strategy API.
	 *
	 * WordPress's own eligibility logic then decides per handle: a script with
	 * an inline "after" script, or one a blocking script depends on, silently
	 * stays blocking. We only set the *intended* strategy, and never touch
	 * jQuery, handles with a strategy already, scripts without a src
	 * (aliases/inline-only) or handles on the exclude list.
	 *
	 * @return void
	 */
	public function defer_scripts() {
		if ( self::bypassed() || ! self::supports_strategy() || ! self::is_page_view() ) {
			return;
		}

		$scripts = wp_scripts();
		if ( empty( $scripts->queue ) ) {
			return;
		}

		$never   = array( 'jquery', 'jquery-core', 'jquery-migrate' );
		$exclude = array_merge( $never, self::parse_handles( (string) $this->opts['defer_exclude'], true )[0] );
		$done    = array_flip( (array) $scripts->done );

		foreach ( $this->with_dependencies( $scripts, $scripts->queue ) as $handle ) {
			if ( isset( $done[ $handle ] ) || empty( $scripts->registered[ $handle ]->src ) ) {
				continue;
			}
			if ( $this->handle_excluded( $handle, $exclude ) || $scripts->get_data( $handle, 'strategy' ) ) {
				continue;
			}
			wp_script_add_data( $handle, 'strategy', 'defer' );
		}
	}

	/**
	 * Queued handles plus everything they depend on (registered handles only).
	 *
	 * @param WP_Scripts $scripts Script registry.
	 * @param string[]   $handles Starting handles.
	 * @return string[]
	 */
	private function with_dependencies( $scripts, array $handles ) {
		$all   = array();
		$stack = array_values( $handles );

		while ( $stack ) {
			$handle = array_pop( $stack );
			if ( isset( $all[ $handle ] ) || ! isset( $scripts->registered[ $handle ] ) ) {
				continue;
			}
			$all[ $handle ] = true;
			foreach ( (array) $scripts->registered[ $handle ]->deps as $dep ) {
				$stack[] = $dep;
			}
		}
		return array_keys( $all );
	}

	/**
	 * Whether a handle matches the exclude list (exact name or `*` pattern).
	 *
	 * @param string   $handle  Script handle.
	 * @param string[] $exclude Exclusions.
	 * @return bool
	 */
	private function handle_excluded( $handle, array $exclude ) {
		foreach ( $exclude as $pattern ) {
			if ( $pattern === $handle || ( false !== strpos( $pattern, '*' ) && fnmatch( $pattern, $handle ) ) ) {
				return true;
			}
		}
		return false;
	}
}
