<?php
/**
 * The BLT mark, wherever WordPress will render it.
 *
 * @package BLT_Family
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves the bundled BLT mark as an admin menu icon, an inline SVG for page
 * headers, and the icon set WordPress shows on plugin/update cards.
 *
 * Every method takes the calling plugin's own directory/URL, so one shared
 * class works for all of them without knowing which plugin it is serving.
 */
class BLT_Family_Brand {

	/**
	 * The grey WordPress paints its own admin menu dashicons in.
	 */
	const MENU_ICON_COLOR = '#a7aaad';

	/**
	 * Cache of file contents, keyed by absolute path.
	 *
	 * @var array<string,string>
	 */
	private static $files = array();

	/**
	 * An add_menu_page() $icon_url for the BLT mark.
	 *
	 * WordPress paints an SVG icon_url as a CSS background image and never
	 * recolours it, so the menu's own icon grey is baked into the data URI here
	 * rather than left to currentColor (which would resolve to black against
	 * the dark menu bar). print_menu_icon_style() handles the lit state.
	 *
	 * Uses the pixel-hinted 20-unit variant: the full-detail mark's hairline
	 * channels anti-alias into mud at the 20x20 size menu icons render at.
	 *
	 * @param string $plugin_dir      Plugin directory (trailing slash), i.e. BLT_X_DIR.
	 * @param string $fallback_icon   Dashicon to use if the file is missing.
	 * @return string
	 */
	public static function menu_icon( $plugin_dir, $fallback_icon = 'dashicons-shield' ) {
		$svg = self::read( $plugin_dir . 'assets/img/blt-mark-menu.svg' );

		if ( '' === $svg ) {
			return $fallback_icon;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return 'data:image/svg+xml;base64,' . base64_encode(
			str_replace( 'currentColor', self::MENU_ICON_COLOR, $svg )
		);
	}

	/**
	 * The full-detail mark as inline SVG, for a page header or a card.
	 *
	 * Inherits currentColor, so it takes the colour of whatever it sits in.
	 * Returns '' when the file is missing so callers can print it unguarded.
	 *
	 * @param string $plugin_dir Plugin directory (trailing slash).
	 * @param string $class      CSS class for the <svg> element.
	 * @return string Sanitized SVG markup, safe to echo.
	 */
	public static function inline_mark( $plugin_dir, $class = 'blt-brand-mark' ) {
		$svg = self::read( $plugin_dir . 'assets/img/blt-mark.svg' );

		if ( '' === $svg ) {
			return '';
		}

		// The bundled file is ours, but it still goes through KSES with an
		// explicitly allowed SVG subset — the same treatment any other
		// pre-built markup gets before being echoed into an admin page.
		$svg = str_replace(
			'<svg ',
			'<svg class="' . esc_attr( $class ) . '" ',
			$svg
		);

		return wp_kses(
			$svg,
			array(
				'svg'   => array(
					'xmlns'             => true,
					'viewbox'           => true,
					'class'             => true,
					'fill'              => true,
					'role'              => true,
					'aria-hidden'       => true,
					'aria-labelledby'   => true,
					'focusable'         => true,
				),
				'title' => array( 'id' => true ),
				'path'  => array(
					'd'         => true,
					'fill'      => true,
					'fill-rule' => true,
				),
			)
		);
	}

	/**
	 * Light the menu icon up on hover and while the section is open.
	 *
	 * Core's dashicon menu items switch from grey to white in those states; a
	 * background-image icon can't, so brighten it with a filter to match.
	 * Hook this on admin_head wherever menu_icon() was used.
	 *
	 * @param string $menu_slug The top-level menu slug passed to add_menu_page().
	 * @return void
	 */
	public static function print_menu_icon_style( $menu_slug ) {
		self::print_menu_icon_style_for_id( 'toplevel_page_' . $menu_slug );
	}

	/**
	 * The same rule, for a menu whose element id WordPress builds itself.
	 *
	 * A plugin whose top-level menu is a custom post type's own does not get a
	 * `toplevel_page_<slug>` id — WordPress builds `menu-posts-<post_type>` for
	 * those. Same brightening rule, different id, so the CPT case does not have
	 * to hand-copy the CSS.
	 *
	 * @param string $element_id The menu item's DOM id, without the leading '#'.
	 * @return void
	 */
	public static function print_menu_icon_style_for_id( $element_id ) {
		$element_id = (string) $element_id;

		if ( '' === $element_id ) {
			return;
		}

		$handle = 'blt-family-menu-icon-' . sanitize_html_class( $element_id );
		?>
		<style id="<?php echo esc_attr( $handle ); ?>">
			#adminmenu #<?php echo esc_attr( $element_id ); ?> div.wp-menu-image.svg { background-size: 20px auto; }
			#adminmenu #<?php echo esc_attr( $element_id ); ?>:hover div.wp-menu-image.svg,
			#adminmenu #<?php echo esc_attr( $element_id ); ?>.wp-has-current-submenu div.wp-menu-image.svg,
			#adminmenu #<?php echo esc_attr( $element_id ); ?>.current div.wp-menu-image.svg { filter: brightness(1.6); }
		</style>
		<?php
	}

	/**
	 * The icon set WordPress renders on plugin and update cards.
	 *
	 * Icons normally come from a plugin's wordpress.org asset directory, which
	 * a GitHub-hosted plugin doesn't have, so WordPress falls back to a generic
	 * placeholder. Point it at the bundled mark instead.
	 *
	 * @param string $assets_url URL of the plugin's assets/img/ directory (trailing slash).
	 * @return array<string,string>
	 */
	public static function icon_set( $assets_url ) {
		return array(
			'1x'      => $assets_url . 'icon-128x128.png',
			'2x'      => $assets_url . 'icon-256x256.png',
			'svg'     => $assets_url . 'blt-mark.svg',
			'default' => $assets_url . 'icon-256x256.png',
		);
	}

	/**
	 * Read a bundled asset once per request.
	 *
	 * @param string $path Absolute path.
	 * @return string File contents, or '' when unreadable.
	 */
	private static function read( $path ) {
		if ( ! isset( self::$files[ $path ] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			self::$files[ $path ] = is_readable( $path ) ? (string) file_get_contents( $path ) : '';
		}

		return self::$files[ $path ];
	}
}
