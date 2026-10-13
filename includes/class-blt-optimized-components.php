<?php
/**
 * Component attribution — "which plugin/theme owns this file or URL".
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;

/**
 * Maps file paths, asset URLs and backtraces to the component responsible
 * for them (a plugin, mu-plugin, theme, drop-in, WordPress core, or an
 * external host). Every performance finding names who caused it, so the
 * fix lands on the right plugin instead of on "the site".
 *
 * A component is always an array:
 *   array( 'type' => 'plugin|mu-plugin|theme|dropin|core|external|other',
 *          'slug' => 'woocommerce', 'name' => 'WooCommerce' )
 *
 * Pure static helpers with per-request memoisation; safe to call from the
 * profiled front-end request as well as from wp-admin.
 */
class BLT_Optimized_Components {

	/**
	 * Memoised plugin directory slug => human name.
	 *
	 * @var array<string,string>|null
	 */
	private static $plugin_names = null;

	/**
	 * Memoised path roots, longest first.
	 *
	 * @var array|null
	 */
	private static $roots = null;

	/**
	 * Registry sizes the memoised roots were built from.
	 *
	 * @var string
	 */
	private static $roots_signature = '';

	/**
	 * Memoised path => component lookups.
	 *
	 * @var array<string,array>
	 */
	private static $path_cache = array();

	/**
	 * Resolve a filesystem path to its component.
	 *
	 * @param string $file Absolute file path.
	 * @return array Component.
	 */
	public static function from_path( $file ) {
		$file = wp_normalize_path( (string) $file );
		if ( '' === $file ) {
			return self::make( 'other', 'unknown', __( 'Unknown', 'blt-optimized' ) );
		}
		$roots = self::roots(); // First: may invalidate the path cache.
		if ( isset( self::$path_cache[ $file ] ) ) {
			return self::$path_cache[ $file ];
		}

		$component = null;
		foreach ( $roots as $root ) {
			if ( 0 !== strpos( $file, $root['path'] . '/' ) && $file !== $root['path'] ) {
				continue;
			}
			$rest = ltrim( substr( $file, strlen( $root['path'] ) ), '/' );

			switch ( $root['type'] ) {
				case 'plugin-real':
					// A symlinked plugin: PHP reports the resolved path, which
					// lives outside the plugins directory.
					$component = self::make( 'plugin', $root['slug'], self::plugin_name( $root['slug'] ) );
					break;
				case 'plugin':
					$slug      = strtok( $rest, '/' );
					$slug      = ( false === $slug || '' === $slug ) ? $rest : $slug;
					$slug      = preg_replace( '/\.php$/', '', $slug );
					$component = self::make( 'plugin', $slug, self::plugin_name( $slug ) );
					break;
				case 'mu-plugin':
					$slug      = strtok( $rest, '/' );
					$slug      = preg_replace( '/\.php$/', '', (string) $slug );
					$component = self::make( 'mu-plugin', $slug, $slug );
					break;
				case 'theme':
					$slug      = (string) strtok( $rest, '/' );
					$theme     = wp_get_theme( $slug );
					$name      = $theme->exists() ? $theme->get( 'Name' ) : $slug;
					$component = self::make( 'theme', $slug, $name );
					break;
				case 'core':
					$component = self::make( 'core', 'wordpress', __( 'WordPress core', 'blt-optimized' ) );
					break;
				case 'content':
					// A file directly in wp-content: object-cache.php, db.php,
					// advanced-cache.php and friends are drop-ins.
					if ( false === strpos( $rest, '/' ) && preg_match( '/\.php$/', $rest ) ) {
						$component = self::make( 'dropin', $rest, $rest );
					} else {
						$top       = (string) strtok( $rest, '/' );
						$component = self::make( 'other', 'wp-content/' . $top, 'wp-content/' . $top );
					}
					break;
			}
			break;
		}

		if ( null === $component ) {
			$component = self::make( 'other', 'unknown', __( 'Unknown', 'blt-optimized' ) );
		}

		self::$path_cache[ $file ] = $component;
		return $component;
	}

	/**
	 * Resolve an asset URL to its component. Local URLs are mapped back to a
	 * file path; anything else is attributed to its external host.
	 *
	 * @param string $url Absolute, protocol-relative or root-relative URL.
	 * @return array Component.
	 */
	public static function from_url( $url ) {
		$path = self::url_to_path( $url );
		if ( '' !== $path ) {
			return self::from_path( $path );
		}

		$host = self::url_host( $url );
		if ( '' !== $host && ! self::is_local_host( $host ) ) {
			return self::make( 'external', $host, $host );
		}

		// Local but not a file we could map (a REST route, a rewrite, a
		// dynamically generated stylesheet). Fall back to the URL path's
		// plugin/theme segment when there is one.
		$url_path = (string) wp_parse_url( self::absolutize( $url ), PHP_URL_PATH );
		if ( preg_match( '#/plugins/([^/]+)/#', $url_path, $m ) ) {
			return self::make( 'plugin', $m[1], self::plugin_name( $m[1] ) );
		}
		if ( preg_match( '#/themes/([^/]+)/#', $url_path, $m ) ) {
			return self::make( 'theme', $m[1], $m[1] );
		}
		if ( preg_match( '#/wp-(includes|admin)/#', $url_path ) ) {
			return self::make( 'core', 'wordpress', __( 'WordPress core', 'blt-optimized' ) );
		}
		return self::make( 'other', 'local', __( 'This site (dynamic)', 'blt-optimized' ) );
	}

	/**
	 * Map a local asset URL to an existing absolute file path under ABSPATH
	 * or WP_CONTENT_DIR. Query strings and fragments are ignored; scheme and
	 * protocol-relative differences are tolerated. Returns '' when the URL is
	 * external or the file does not exist.
	 *
	 * @param string $url URL.
	 * @return string Absolute normalized path, or ''.
	 */
	public static function url_to_path( $url ) {
		$url = self::absolutize( (string) $url );
		if ( '' === $url ) {
			return '';
		}

		$host = self::url_host( $url );
		if ( '' === $host || ! self::is_local_host( $host ) ) {
			return '';
		}

		$url_path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '' === $url_path ) {
			return '';
		}
		$url_path = rawurldecode( $url_path );

		// Most specific base first: content dir may live outside ABSPATH.
		$bases = array(
			array( (string) wp_parse_url( content_url(), PHP_URL_PATH ), WP_CONTENT_DIR ),
			array( (string) wp_parse_url( site_url(), PHP_URL_PATH ), ABSPATH ),
			array( (string) wp_parse_url( home_url(), PHP_URL_PATH ), ABSPATH ),
		);

		foreach ( $bases as $base ) {
			$prefix = untrailingslashit( $base[0] );
			if ( '' !== $prefix && 0 !== strpos( $url_path, $prefix . '/' ) ) {
				continue;
			}
			$relative = ltrim( substr( $url_path, strlen( $prefix ) ), '/' );
			if ( '' === $relative || false !== strpos( $relative, '..' ) ) {
				continue;
			}
			$candidate = wp_normalize_path( trailingslashit( $base[1] ) . $relative );
			if ( @is_file( $candidate ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir hosts warn.
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Attribute a debug_backtrace() to the first frame that is not WordPress
	 * core plumbing (wpdb, hooks, includes) and not this plugin's profiler.
	 * Falls back to core when every frame is core.
	 *
	 * @param array $trace debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) output.
	 * @return array { component: array, caller: string } caller is "function()"
	 *               or "Class->method()" of the attributed frame, plus file:line.
	 */
	public static function from_backtrace( array $trace ) {
		$self_dir = wp_normalize_path( BLT_OPTIMIZED_DIR );
		$fallback = null;

		foreach ( $trace as $i => $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}
			$file = wp_normalize_path( $frame['file'] );

			// Skip this plugin's own instrumentation frames (the profiler gate
			// and its collector share the prefix).
			if ( 0 === strpos( $file, $self_dir . 'includes/class-blt-optimized-profiler' )
				|| 0 === strpos( $file, $self_dir . 'includes/class-blt-optimized-components.php' ) ) {
				continue;
			}

			$component = self::from_path( $file );

			// The function *called from* this frame's file:line lives in the
			// next frame up; that's the most useful "caller" label.
			$caller_frame = isset( $trace[ $i + 1 ] ) ? $trace[ $i + 1 ] : $frame;
			$caller       = self::frame_label( $caller_frame ) . ' ' . self::short_path( $file ) . ':' . ( isset( $frame['line'] ) ? (int) $frame['line'] : 0 );

			if ( 'core' !== $component['type'] ) {
				return array(
					'component' => $component,
					'caller'    => $caller,
				);
			}
			if ( null === $fallback ) {
				$fallback = array(
					'component' => $component,
					'caller'    => $caller,
				);
			}
		}

		return $fallback ? $fallback : array(
			'component' => self::make( 'core', 'wordpress', __( 'WordPress core', 'blt-optimized' ) ),
			'caller'    => '',
		);
	}

	/**
	 * Stable string key for grouping: "plugin:woocommerce".
	 *
	 * @param array $component Component.
	 * @return string
	 */
	public static function key( $component ) {
		$component = is_array( $component ) ? $component : array();
		return ( isset( $component['type'] ) ? $component['type'] : 'other' ) . ':' . ( isset( $component['slug'] ) ? $component['slug'] : 'unknown' );
	}

	/**
	 * Human label: "WooCommerce (plugin)".
	 *
	 * @param array $component Component.
	 * @return string
	 */
	public static function label( $component ) {
		$component = is_array( $component ) ? $component : array();
		$name      = isset( $component['name'] ) && '' !== $component['name'] ? $component['name'] : ( isset( $component['slug'] ) ? $component['slug'] : '?' );
		$type      = isset( $component['type'] ) ? $component['type'] : 'other';
		$types     = array(
			'plugin'    => __( 'plugin', 'blt-optimized' ),
			'mu-plugin' => __( 'must-use plugin', 'blt-optimized' ),
			'theme'     => __( 'theme', 'blt-optimized' ),
			'dropin'    => __( 'drop-in', 'blt-optimized' ),
			'core'      => '',
			'external'  => __( 'third party', 'blt-optimized' ),
			'other'     => '',
		);
		$suffix = isset( $types[ $type ] ) ? $types[ $type ] : '';
		return '' === $suffix ? $name : sprintf( '%1$s (%2$s)', $name, $suffix );
	}

	/**
	 * Path relative to ABSPATH (or WP_CONTENT_DIR's parent) for display.
	 *
	 * @param string $file Absolute path.
	 * @return string
	 */
	public static function short_path( $file ) {
		$file = wp_normalize_path( (string) $file );
		foreach ( array( dirname( wp_normalize_path( WP_CONTENT_DIR ) ), untrailingslashit( wp_normalize_path( ABSPATH ) ) ) as $root ) {
			if ( '' !== $root && 0 === strpos( $file, $root . '/' ) ) {
				return substr( $file, strlen( $root ) + 1 );
			}
		}
		return $file;
	}

	/**
	 * Whether a host is this site (home or site URL host, or localhost aliases).
	 *
	 * @param string $host Hostname.
	 * @return bool
	 */
	public static function is_local_host( $host ) {
		$host = strtolower( (string) $host );
		if ( '' === $host ) {
			return true;
		}
		$local = array(
			strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			strtolower( (string) wp_parse_url( site_url(), PHP_URL_HOST ) ),
			strtolower( (string) wp_parse_url( content_url(), PHP_URL_HOST ) ),
		);
		return in_array( $host, $local, true );
	}

	/**
	 * Host part of a URL ('' for root-relative URLs).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function url_host( $url ) {
		return strtolower( (string) wp_parse_url( self::absolutize( $url ), PHP_URL_HOST ) );
	}

	/**
	 * Make protocol-relative and root-relative URLs absolute against home_url().
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function absolutize( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( 0 === strpos( $url, '//' ) ) {
			return ( is_ssl() ? 'https:' : 'http:' ) . $url;
		}
		if ( '/' === $url[0] ) {
			$home = wp_parse_url( home_url() );
			$base = ( isset( $home['scheme'] ) ? $home['scheme'] : 'http' ) . '://' . ( isset( $home['host'] ) ? $home['host'] : '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
			return $base . $url;
		}
		return $url;
	}

	/**
	 * Build a component array.
	 *
	 * @param string $type Type.
	 * @param string $slug Slug.
	 * @param string $name Human name.
	 * @return array
	 */
	public static function make( $type, $slug, $name ) {
		return array(
			'type' => (string) $type,
			'slug' => (string) $slug,
			'name' => (string) $name,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Internals                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Path roots, most specific first, so a plugins dir nested inside
	 * wp-content wins over the generic wp-content root.
	 *
	 * @return array[] Each { path, type }.
	 */
	private static function roots() {
		// Symlinked plugins and theme directories register while plugins
		// load — after the profiler's first lookups — so rebuild (and drop
		// cached attributions) whenever either registry has grown.
		$signature = count( isset( $GLOBALS['wp_plugin_paths'] ) ? (array) $GLOBALS['wp_plugin_paths'] : array() )
			. ':' . count( isset( $GLOBALS['wp_theme_directories'] ) ? (array) $GLOBALS['wp_theme_directories'] : array() );
		if ( null !== self::$roots && $signature === self::$roots_signature ) {
			return self::$roots;
		}
		self::$roots_signature = $signature;
		self::$path_cache      = array();

		$roots = array(
			array(
				'path' => wp_normalize_path( WP_PLUGIN_DIR ),
				'type' => 'plugin',
			),
			array(
				'path' => wp_normalize_path( WPMU_PLUGIN_DIR ),
				'type' => 'mu-plugin',
			),
		);

		// Symlinked plugins, as registered by wp_register_plugin_realpath():
		// symlink dir => resolved dir.
		$plugin_dir = wp_normalize_path( WP_PLUGIN_DIR );
		foreach ( isset( $GLOBALS['wp_plugin_paths'] ) ? (array) $GLOBALS['wp_plugin_paths'] : array() as $link => $real ) {
			$link = wp_normalize_path( $link );
			$real = wp_normalize_path( $real );
			if ( $link === $real || 0 !== strpos( $link, $plugin_dir . '/' ) ) {
				continue;
			}
			$roots[] = array(
				'path' => $real,
				'type' => 'plugin-real',
				'slug' => basename( $link ),
			);
		}

		$theme_dirs = isset( $GLOBALS['wp_theme_directories'] ) ? (array) $GLOBALS['wp_theme_directories'] : array();
		if ( empty( $theme_dirs ) && function_exists( 'get_theme_root' ) ) {
			$theme_dirs = array( get_theme_root() );
		}
		foreach ( $theme_dirs as $dir ) {
			$roots[] = array(
				'path' => wp_normalize_path( $dir ),
				'type' => 'theme',
			);
		}

		$roots[] = array(
			'path' => wp_normalize_path( WP_CONTENT_DIR ),
			'type' => 'content',
		);
		$roots[] = array(
			'path' => untrailingslashit( wp_normalize_path( ABSPATH ) ) . '/' . WPINC,
			'type' => 'core',
		);
		$roots[] = array(
			'path' => untrailingslashit( wp_normalize_path( ABSPATH ) ) . '/wp-admin',
			'type' => 'core',
		);
		// Root-level core files (wp-settings.php, wp-blog-header.php, index.php).
		$roots[] = array(
			'path' => untrailingslashit( wp_normalize_path( ABSPATH ) ),
			'type' => 'core',
		);

		foreach ( $roots as &$root ) {
			$root['path'] = untrailingslashit( $root['path'] );
		}
		unset( $root );

		usort(
			$roots,
			static function ( $a, $b ) {
				return strlen( $b['path'] ) - strlen( $a['path'] );
			}
		);

		self::$roots = $roots;
		return $roots;
	}

	/**
	 * Human plugin name for a plugin directory slug (falls back to the slug).
	 *
	 * @param string $slug Directory slug, or single-file plugin basename.
	 * @return string
	 */
	public static function plugin_name( $slug ) {
		if ( null === self::$plugin_names ) {
			self::$plugin_names = array();
			if ( ! function_exists( 'get_plugins' ) && is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			if ( function_exists( 'get_plugins' ) ) {
				foreach ( get_plugins() as $file => $data ) {
					$dir = dirname( $file );
					$key = ( '.' === $dir ) ? preg_replace( '/\.php$/', '', $file ) : $dir;
					if ( ! isset( self::$plugin_names[ $key ] ) ) {
						self::$plugin_names[ $key ] = isset( $data['Name'] ) && '' !== $data['Name'] ? $data['Name'] : $key;
					}
				}
			}
		}
		return isset( self::$plugin_names[ $slug ] ) ? self::$plugin_names[ $slug ] : $slug;
	}

	/**
	 * Label for a backtrace frame: Class->method() or function().
	 *
	 * @param array $frame Frame.
	 * @return string
	 */
	private static function frame_label( $frame ) {
		if ( empty( $frame['function'] ) ) {
			return '';
		}
		if ( ! empty( $frame['class'] ) ) {
			return $frame['class'] . ( isset( $frame['type'] ) ? $frame['type'] : '->' ) . $frame['function'] . '()';
		}
		return $frame['function'] . '()';
	}
}
