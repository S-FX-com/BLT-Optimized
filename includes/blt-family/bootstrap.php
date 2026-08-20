<?php
/**
 * BLT Family — drop-in bootstrap.
 *
 * Every BLT plugin ships an identical copy of includes/blt-family/ and
 * requires THIS file during its own load, then calls blt_family_register().
 * Only one copy ever boots per request: each copy nominates itself here, and
 * the highest version present on the site wins on plugins_loaded.
 *
 * Two of the library's classes load eagerly from here rather than waiting for
 * the election: BLT_Family_Brand and BLT_Family_Updates. Both are stateless
 * utilities, and both are needed while a plugin is still loading — a plugin
 * builds its update checker at the top of its main file, long before
 * plugins_loaded — so deferring them would make BLT_Family_Updates::apply()
 * unavailable exactly where it is called. Everything that touches stored
 * state (BLT_Family, the store, the groups, the admin screen) stays behind the
 * election, because that is where a version difference actually matters.
 *
 * Beyond those two requires and the registration bookkeeping below, this file
 * must stay side-effect-free: on a site with several BLT plugins it runs once
 * per plugin.
 *
 * The three functions declared here are the library's stable ABI: an older
 * copy may be the one that declares them while a newer copy supplies the
 * classes, so their signatures must never change incompatibly. Anything that
 * needs to evolve belongs in the classes, not here.
 *
 * @package BLT_Family
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nominate this copy of the library.
 *
 * Keep in sync with BLT_Family::VERSION in class-blt-family.php.
 */
$GLOBALS['blt_family_lib'] = isset( $GLOBALS['blt_family_lib'] ) && is_array( $GLOBALS['blt_family_lib'] )
	? $GLOBALS['blt_family_lib']
	: array(
		'candidates' => array(),
		'plugins'    => array(),
		'booted'     => false,
	);

$GLOBALS['blt_family_lib']['candidates'][] = array(
	'version' => '1.0.0',
	'loader'  => __DIR__ . '/loader.php',
);

// Load-time utilities. Guarded because every BLT plugin on the site runs this
// file, and redeclaring a class is fatal.
if ( ! class_exists( 'BLT_Family_Brand' ) ) {
	require_once __DIR__ . '/class-blt-family-brand.php';
}

if ( ! class_exists( 'BLT_Family_Updates' ) ) {
	require_once __DIR__ . '/class-blt-family-updates.php';
}

if ( ! function_exists( 'blt_family_register' ) ) {

	/**
	 * Register a BLT plugin with the family layer.
	 *
	 * Call this during the plugin's own load, immediately after requiring this
	 * file — before plugins_loaded, so the registry is complete by the time
	 * the library boots.
	 *
	 * @param string $plugin_file The plugin's main file (__FILE__ of the bootstrap).
	 * @param array  $args {
	 *     @type string   $name   Human-readable plugin name, e.g. 'BLT Secure'.
	 *     @type string   $slug   Plugin slug, e.g. 'blt-secure'.
	 *     @type string[] $groups Shared credential groups this plugin consumes.
	 *     @type string   $menu   Optional admin page slug to link to from the
	 *                            family screen.
	 *     @type string   $version Optional plugin version, for the overview.
	 *     @type string   $update_slug Optional. The slug the plugin's
	 *                            plugin-update-checker instance was built with,
	 *                            which is what its manual-check link keys on.
	 *                            Defaults to $slug. Pass
	 *                            dirname( plugin_basename( __FILE__ ) ) where
	 *                            the checker was built with an empty slug and
	 *                            therefore derives one from the install
	 *                            directory.
	 * }
	 * @return void
	 */
	function blt_family_register( $plugin_file, array $args = array() ) {
		$slug = isset( $args['slug'] ) ? (string) $args['slug'] : '';

		if ( '' === $slug ) {
			$slug = basename( (string) $plugin_file, '.php' );
		}

		// Re-registration (a plugin loaded twice, or a mu-plugin shim) must not
		// duplicate the row — the overview counts these to decide whether the
		// family UI appears at all.
		$GLOBALS['blt_family_lib']['plugins'][ $slug ] = array(
			'file'    => (string) $plugin_file,
			'name'    => isset( $args['name'] ) ? (string) $args['name'] : $slug,
			'slug'    => $slug,
			'groups'  => isset( $args['groups'] ) && is_array( $args['groups'] ) ? array_values( $args['groups'] ) : array(),
			'menu'    => isset( $args['menu'] ) ? (string) $args['menu'] : '',
			'version' => isset( $args['version'] ) ? (string) $args['version'] : '',
			'update_slug' => isset( $args['update_slug'] ) && '' !== $args['update_slug']
				? (string) $args['update_slug']
				: $slug,
		);
	}

	/**
	 * Elect the newest copy of the library and load it. Idempotent.
	 *
	 * @return void
	 */
	function blt_family_boot() {
		if ( ! empty( $GLOBALS['blt_family_lib']['booted'] ) ) {
			return;
		}

		$candidates = $GLOBALS['blt_family_lib']['candidates'];

		if ( empty( $candidates ) ) {
			return;
		}

		$winner = null;

		foreach ( $candidates as $candidate ) {
			if ( ! is_readable( $candidate['loader'] ) ) {
				continue;
			}

			if ( null === $winner || version_compare( $candidate['version'], $winner['version'], '>' ) ) {
				$winner = $candidate;
			}
		}

		if ( null === $winner ) {
			return;
		}

		// Set before requiring: the loader may run code that reaches back into
		// the library, and a re-entrant call must not elect a second time.
		$GLOBALS['blt_family_lib']['booted'] = true;

		require_once $winner['loader'];

		if ( class_exists( 'BLT_Family' ) ) {
			BLT_Family::boot( $GLOBALS['blt_family_lib']['plugins'] );
		}
	}

	/**
	 * Registered BLT plugins, keyed by slug. Available before boot.
	 *
	 * @return array<string,array>
	 */
	function blt_family_plugins() {
		return isset( $GLOBALS['blt_family_lib']['plugins'] ) ? $GLOBALS['blt_family_lib']['plugins'] : array();
	}

	add_action( 'plugins_loaded', 'blt_family_boot', 0 );
}
