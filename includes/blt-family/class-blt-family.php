<?php
/**
 * The BLT family layer: registry, detection, and the shared-value accessor.
 *
 * @package BLT_Family
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What every BLT plugin talks to.
 *
 * Registration happens in bootstrap.php during each plugin's load; boot() runs
 * once on plugins_loaded from whichever copy of the library won the version
 * election.
 *
 * THE OPT-IN RULE. A shared value is never used unless the site owner has
 * explicitly enabled that group for that plugin on the family screen. This is
 * not politeness — it is the only safe default, because these credentials sit
 * underneath is_configured()-style gates that decide whether a module boots,
 * whether cron jobs enqueue, and what Site Health reports. If installing a
 * second BLT plugin silently populated the first one's empty fields, it could
 * start an image bulk run, turn on a payment path, or flip a dormant module on
 * a site whose owner never entered that credential. Every read therefore names
 * the plugin doing the reading, and returns the default unless that plugin has
 * been opted in.
 *
 * Consequences of the same rule, worth knowing before changing it:
 *
 *   - A site where every local field is populated behaves bit-identically with
 *     this library present or absent.
 *   - "Empty" is not "unset": most plugins here merge settings over defaults of
 *     '', so a deliberately-cleared field is indistinguishable from one that
 *     was never filled in. The opt-in is what stops a cleared field from
 *     silently inheriting a live credential.
 *   - Precedence is always: wp-config constant -> the plugin's own option ->
 *     shared store. Nothing in the library writes to a plugin's own options.
 */
class BLT_Family {

	/**
	 * Library version. Keep in sync with the version in bootstrap.php.
	 */
	const VERSION = '1.0.0';

	/**
	 * Option holding the per-plugin, per-group opt-in map.
	 */
	const OPT_IN_OPTION = 'blt_family_opt_in';

	/**
	 * Registered plugins, keyed by slug.
	 *
	 * @var array<string,array>
	 */
	private static $plugins = array();

	/**
	 * Whether boot() has run.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Bring the library up. Called once, from blt_family_boot().
	 *
	 * @param array<string,array> $plugins Registry collected by bootstrap.php.
	 * @return void
	 */
	public static function boot( array $plugins ) {
		if ( self::$booted ) {
			return;
		}

		self::$booted  = true;
		self::$plugins = $plugins;

		// The shared UI exists only where it earns its keep: a site running a
		// single BLT plugin gets no extra menu, no notice and no new screen.
		if ( self::is_multi_plugin() && is_admin() ) {
			BLT_Family_Admin::init();
		}
	}

	/**
	 * Every registered BLT plugin, keyed by slug.
	 *
	 * @return array<string,array>
	 */
	public static function plugins() {
		return self::$plugins;
	}

	/**
	 * How many BLT plugins are active on this site.
	 *
	 * @return int
	 */
	public static function count() {
		return count( self::$plugins );
	}

	/**
	 * Whether more than one BLT plugin is active — the condition the whole
	 * shared layer exists for.
	 *
	 * @return bool
	 */
	public static function is_multi_plugin() {
		return self::count() > 1;
	}

	/**
	 * One registered plugin's registration data, or null.
	 *
	 * @param string $slug Plugin slug.
	 * @return array|null
	 */
	public static function plugin( $slug ) {
		return isset( self::$plugins[ $slug ] ) ? self::$plugins[ $slug ] : null;
	}

	/**
	 * The plugins that declared they consume a group.
	 *
	 * @param string $group Group key.
	 * @return array<string,array>
	 */
	public static function consumers( $group ) {
		$out = array();

		foreach ( self::$plugins as $slug => $plugin ) {
			if ( in_array( $group, $plugin['groups'], true ) ) {
				$out[ $slug ] = $plugin;
			}
		}

		return $out;
	}

	/**
	 * A shared value, for a named plugin.
	 *
	 * Returns $default unless every one of these holds:
	 *   - the plugin declared this group in blt_family_register(),
	 *   - the site owner enabled this group for this plugin (which they can only
	 *     do while two or more BLT plugins are active, since that is when the
	 *     shared screen exists),
	 *   - the group actually holds a value for the field.
	 *
	 * Call this only where the plugin's own resolution has already failed:
	 *
	 *     $token = $this->settings['api_token'];
	 *     if ( '' === $token && class_exists( 'BLT_Family' ) ) {
	 *         $token = BLT_Family::get( 'blt-thing', 'cloudflare', 'api_token' );
	 *     }
	 *
	 * @param string $plugin_slug The reading plugin's slug.
	 * @param string $group       Group key.
	 * @param string $field       Field key.
	 * @param string $default     Returned when the value is unavailable.
	 * @return string
	 */
	public static function get( $plugin_slug, $group, $field, $default = '' ) {
		if ( ! self::enabled( $plugin_slug, $group ) ) {
			return $default;
		}

		return BLT_Family_Store::get( $group, $field, $default );
	}

	/**
	 * Whether a plugin may read a group's shared values.
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @param string $group       Group key.
	 * @return bool
	 */
	public static function enabled( $plugin_slug, $group ) {
		// Deliberately NOT gated on is_multi_plugin(). Two-or-more is what makes
		// the shared UI appear, and an opt-in can only be granted while two or
		// more are active — but once the site owner has granted it, deactivating
		// an unrelated BLT plugin must not silently pull a credential out from
		// under the one still running. That would break WAF deployment or media
		// offloading at the moment an admin deactivates something else entirely,
		// with nothing in the UI to explain it.
		$plugin = self::plugin( $plugin_slug );

		if ( null === $plugin || ! in_array( $group, $plugin['groups'], true ) ) {
			return false;
		}

		if ( null === BLT_Family_Groups::get( $group ) ) {
			return false;
		}

		return in_array( $group, self::opted_in( $plugin_slug ), true );
	}

	/**
	 * The groups a plugin has been opted in to.
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @return string[]
	 */
	public static function opted_in( $plugin_slug ) {
		$map = get_option( self::OPT_IN_OPTION, array() );

		if ( ! is_array( $map ) || ! isset( $map[ $plugin_slug ] ) || ! is_array( $map[ $plugin_slug ] ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'strval', $map[ $plugin_slug ] ) ) );
	}

	/**
	 * Replace the groups a plugin is opted in to.
	 *
	 * @param string   $plugin_slug Plugin slug.
	 * @param string[] $groups      Group keys to enable.
	 * @return void
	 */
	public static function set_opted_in( $plugin_slug, array $groups ) {
		$map = get_option( self::OPT_IN_OPTION, array() );
		$map = is_array( $map ) ? $map : array();

		$valid  = array();
		$plugin = self::plugin( $plugin_slug );

		foreach ( $groups as $group ) {
			$group = (string) $group;

			// Only a group the plugin declared, and that actually exists.
			if ( null === BLT_Family_Groups::get( $group ) ) {
				continue;
			}

			if ( null !== $plugin && ! in_array( $group, $plugin['groups'], true ) ) {
				continue;
			}

			$valid[] = $group;
		}

		if ( empty( $valid ) ) {
			unset( $map[ $plugin_slug ] );
		} else {
			$map[ $plugin_slug ] = array_values( array_unique( $valid ) );
		}

		if ( empty( $map ) ) {
			delete_option( self::OPT_IN_OPTION );
			return;
		}

		update_option( self::OPT_IN_OPTION, $map, false );
	}
}
