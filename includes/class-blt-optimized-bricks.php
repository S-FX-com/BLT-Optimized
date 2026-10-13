<?php
/**
 * Bricks Builder support for the Performance Scan.
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;

/**
 * Teaches the Performance Scan what Bricks (the premium WordPress theme and
 * visual builder, parent theme folder `bricks`) puts on a site and where it
 * hides its costs.
 *
 * Bricks keeps pages, headers and footers as serialized element arrays in
 * post meta, its global design data in `bricks_*` options and, when the
 * "External files" CSS method is on, one CSS file per page or template in
 * `uploads/bricks/css`. The generic checks cannot see into any of that, so a
 * page-builder site gets findings that name the exact page or template, the
 * element id and an "Edit with Bricks" link: query loops that load every post
 * or nest, full-size images shown small, unused global classes, orphaned CSS
 * files, Google Fonts and icon fonts, the CSS loading method.
 *
 * Everything here is built from Bricks' official documentation (Academy, the
 * published data-model schema and Bricks' own skills repository). Bricks'
 * code is not available, so every read of Bricks data is defensive: both
 * serialized-array and JSON meta are accepted, unknown shapes degrade to "no
 * finding", and a check never claims more than the data shows (an unused
 * class "has no references in the Bricks content that was scanned", not "is
 * unused").
 *
 * Cost: nothing is registered unless the Bricks parent theme is active (see
 * blt-optimized.php, which attaches the public filter_* callbacks), and the
 * class is only loaded when one of those hooks fires: a scan being compiled in
 * the admin, or a "content contains" asset rule being evaluated on the front
 * end. Ordinary page views, with or without Bricks, never load this file. Findings are computed admin-side during a scan; every loop over
 * postmeta or files is capped and time-boxed, and a partial result is
 * reported as partial rather than guessed.
 *
 * Destructive action: deleting orphaned `post-{ID}.min.css` files is handled
 * by delete_css_files(), which acts only on files the last scan found
 * (allow-list stored in a non-autoloaded option), accepts only the strict
 * file-name pattern, resolves every path inside the Bricks CSS folder,
 * re-checks that the post is still gone right before deleting, and writes an
 * audit-log entry. This plugin never edits Bricks data (classes, templates,
 * settings): those fixes are manual steps in Bricks.
 */
class BLT_Optimized_Bricks {

	/**
	 * Parent theme slug and the template post type.
	 */
	const THEME_SLUG = 'bricks';
	const POST_TYPE  = 'bricks_template';

	/**
	 * Post meta keys (official data model).
	 */
	const META_CONTENT           = '_bricks_page_content_2';
	const META_HEADER            = '_bricks_page_header_2';
	const META_FOOTER            = '_bricks_page_footer_2';
	const META_PAGE_SETTINGS     = '_bricks_page_settings';
	const META_TEMPLATE_TYPE     = '_bricks_template_type';
	const META_TEMPLATE_SETTINGS = '_bricks_template_settings';
	const META_EDITOR_MODE       = '_bricks_editor_mode';

	/**
	 * Options (official data model).
	 */
	const OPT_SETTINGS     = 'bricks_global_settings';
	const OPT_CLASSES      = 'bricks_global_classes';
	const OPT_COMPONENTS   = 'bricks_components';
	const OPT_LAST_CSS_GEN = 'bricks_css_files_last_generated_timestamp';

	/**
	 * Allow-list of orphaned CSS files found by the last scan. Never autoloaded.
	 */
	const ORPHAN_OPTION = 'blt_optimized_bricks_css_orphans';

	/**
	 * Strict pattern for a deletable per-post CSS file.
	 */
	const CSS_FILE_PATTERN = '/^post-([0-9]{1,10})\.min\.css\z/';

	/*
	 * Thresholds. All are filterable as a set through
	 * `blt_optimized_bricks_thresholds` (array keyed by constant name).
	 */

	// Content scan (postmeta is read in small batches, capped and time-boxed).
	const SCAN_TIME_BUDGET     = 6;
	const SCAN_MAX_ROWS        = 4000;
	const SCAN_BATCH           = 20;
	const SCAN_MAX_ROW_BYTES   = 6 * MB_IN_BYTES;
	const SCAN_MAX_TOTAL_BYTES = 96 * MB_IN_BYTES;

	// Query loops.
	const LOOP_LARGE_LIMIT  = 100;
	const LOOP_WARN_ITEMS   = 100;
	const NESTED_MIN_OUTER  = 6;
	const NESTED_WARN_OUTER = 20;

	// Images.
	const IMAGE_MAX_CHECK    = 100;
	const IMAGE_MIN_BYTES    = 150 * KB_IN_BYTES;
	const IMAGE_RATIO        = 3;
	const IMAGE_SMALL_MAX_PX = 800;

	// Options.
	const OPTION_NOTICE_BYTES   = 100 * KB_IN_BYTES;
	const OPTION_WARN_BYTES     = 500 * KB_IN_BYTES;
	const AUTOLOAD_NOTICE_BYTES = 250 * KB_IN_BYTES;
	const AUTOLOAD_WARN_BYTES   = 1 * MB_IN_BYTES;

	// Unused global classes.
	const UNUSED_CLASSES_MIN = 5;

	// CSS loading method review.
	const INLINE_CSS_REVIEW_BYTES = 40 * KB_IN_BYTES;

	// Orphaned CSS files.
	const CSS_LIST_MAX    = 50000;
	const CSS_LIST_BUDGET = 3;
	const CSS_WARN_FILES  = 500;
	const CSS_WARN_BYTES  = 5 * MB_IN_BYTES;
	const CSS_ID_CHUNK    = 400;

	// Other.
	const SEARCH_DATA_MIN_BYTES = 5 * MB_IN_BYTES;
	const QUERY_CACHE_MIN_LOOPS = 2;
	const SITEWIDE_RATIO        = 0.75;

	// Presentation caps.
	const MAX_ROWS          = 50;
	const MAX_POST_FINDINGS = 10;
	const FIX_MAX_ITEMS     = 500;

	/**
	 * Bricks element names that are six characters long and so look like
	 * element ids in a `brxe-xxxxxx` class (`.brxe-button` is a type, not an id).
	 */
	const SIX_CHAR_ELEMENT_NAMES = array( 'button', 'rating', 'search', 'slider', 'toggle' );

	/**
	 * Singleton for callers that only need the stateless helpers.
	 *
	 * @var BLT_Optimized_Bricks|null
	 */
	private static $instance = null;

	/**
	 * Memoised content scan (one per request).
	 *
	 * @var array|null
	 */
	private static $scan = null;

	/**
	 * Memoised CSS folder scan (one per request).
	 *
	 * @var array|null
	 */
	private static $css_scan = null;

	/**
	 * Memoised global classes (live and trashed).
	 *
	 * @var array|null
	 */
	private static $classes = null;

	/**
	 * Memoised thresholds.
	 *
	 * @var array|null
	 */
	private static $thresholds = null;

	/**
	 * Memoised locate_element() results, by lower-case element id.
	 *
	 * @var array
	 */
	private static $located = array();

	/**
	 * Diagnostics from checks that threw.
	 *
	 * @var string[]
	 */
	private static $errors = array();

	/**
	 * Audit log (only needed by the destructive fix).
	 *
	 * @var BLT_Optimized_Audit_Log|null
	 */
	private $audit_log;

	/**
	 * Constructor.
	 *
	 * @param BLT_Optimized_Audit_Log|null $audit_log Audit log, for the destructive fix.
	 */
	public function __construct( $audit_log = null ) {
		$this->audit_log = $audit_log;
	}

	/**
	 * Shared instance.
	 *
	 * @return BLT_Optimized_Bricks
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			$log            = ( class_exists( 'BLT_Optimized' ) && method_exists( 'BLT_Optimized', 'instance' ) ) ? BLT_Optimized::instance()->audit_log : null;
			self::$instance = new self( $log );
		}
		return self::$instance;
	}

	/* ------------------------------------------------------------------ */
	/* Registration and hooks                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Register the Bricks integration. Idempotent, and a no-op without Bricks.
	 *
	 * The main plugin file registers the same callbacks lazily (so this file is
	 * only loaded when one fires); calling this directly is equivalent.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! self::is_active() ) {
			return;
		}
		self::add_hook( 'blt_optimized_perf_findings', 'filter_findings', 10, 2 );
		self::add_hook( 'blt_optimized_asset_markers', 'filter_asset_markers', 10, 1 );
		self::add_hook( 'blt_optimized_rule_meta_keys', 'filter_rule_meta_keys', 10, 1 );
		self::add_hook( 'blt_optimized_rule_haystack', 'filter_rule_haystack', 10, 2 );
	}

	/**
	 * Add a filter once.
	 *
	 * @param string $hook     Hook.
	 * @param string $method   Static method of this class.
	 * @param int    $priority Priority.
	 * @param int    $args     Accepted arguments.
	 * @return void
	 */
	private static function add_hook( $hook, $method, $priority, $args ) {
		$callback = array( __CLASS__, $method );
		if ( false === has_filter( $hook, $callback ) ) {
			add_filter( $hook, $callback, $priority, $args );
		}
	}

	/**
	 * `blt_optimized_perf_findings`: add the Bricks findings while a scan is
	 * compiled (admin side), and attribute the generic findings that name
	 * Bricks' own options.
	 *
	 * @param array $findings Findings.
	 * @param array $state    Scan state (raw step results, `pages` included).
	 * @return array
	 */
	public static function filter_findings( $findings, $state = array() ) {
		$findings = is_array( $findings ) ? $findings : array();
		if ( ! self::is_active() ) {
			return $findings;
		}

		try {
			$pages = ( is_array( $state ) && isset( $state['pages'] ) && is_array( $state['pages'] ) ) ? $state['pages'] : array();
			$mine  = ( new self( null ) )->findings( $pages );
		} catch ( \Throwable $e ) {
			self::$errors[] = 'findings: ' . $e->getMessage();
			return $findings;
		}

		return array_merge( self::attribute_generic_findings( $findings ), $mine );
	}

	/**
	 * `blt_optimized_asset_markers`: teach the page analysis which markup
	 * proves a Bricks library is used, so a library Bricks loads without its
	 * element on the page is reported (and one that is used never is).
	 *
	 * Bricks enqueues these handles only when an element needs them. Tokens
	 * are classes the libraries or Bricks' element root classes
	 * (`brxe-{name}`) put in the markup; libraries with no documented markup
	 * (isotope, photoswipe, animate, tooltips, flatpickr, choices) get no
	 * marker, and `bricks-swiper` needs none: the generic slider marker's
	 * `*swiper*` handle pattern and `swiper` class token already cover it. All
	 * are `verify` markers: they are judged from the markup only.
	 *
	 * @param array $markers Markers.
	 * @return array
	 */
	public static function filter_asset_markers( $markers ) {
		$markers = is_array( $markers ) ? $markers : array();
		if ( ! self::is_active() ) {
			return $markers;
		}

		$defs = array(
			array(
				'id'      => 'bricks-fontawesome',
				'label'   => 'Font Awesome (Bricks)',
				'handles' => array( 'bricks-font-awesome-6', 'bricks-font-awesome-6-brands' ),
				'tokens'  => array( 'fa-', '=fas', '=far', '=fab', '=fa' ),
				'without' => __( 'any Font Awesome icons', 'blt-optimized' ),
			),
			array(
				'id'      => 'bricks-ionicons',
				'label'   => 'Ionicons (Bricks)',
				'handles' => array( 'bricks-ionicons' ),
				'tokens'  => array( 'ion-', '=ion' ),
				'without' => __( 'any Ionicons icons', 'blt-optimized' ),
			),
			array(
				'id'      => 'bricks-themify',
				'label'   => 'Themify icons (Bricks)',
				'handles' => array( 'bricks-themify-icons' ),
				'tokens'  => array( 'ti-', '=ti' ),
				'without' => __( 'any Themify icons', 'blt-optimized' ),
			),
			array(
				'id'      => 'bricks-splide',
				'label'   => 'Splide (Bricks nestable sliders)',
				'handles' => array( 'bricks-splide' ),
				'tokens'  => array( 'splide', '=brxe-slider-nested' ),
				'without' => __( 'a nestable slider element', 'blt-optimized' ),
			),
			array(
				'id'      => 'bricks-leaflet',
				'label'   => 'Leaflet (Bricks maps)',
				'handles' => array( 'bricks-leaflet' ),
				'tokens'  => array( 'leaflet-', '=brxe-map-leaflet' ),
				'without' => __( 'a Leaflet map element', 'blt-optimized' ),
			),
			array(
				'id'      => 'bricks-prettify',
				'label'   => 'Code prettifier (Bricks)',
				'handles' => array( 'bricks-prettify' ),
				'tokens'  => array( 'prettyprint', '=brxe-code' ),
				'without' => __( 'a code element', 'blt-optimized' ),
			),
		);

		foreach ( $defs as $def ) {
			$markers[] = array(
				'id'        => $def['id'],
				'label'     => $def['label'],
				'handles'   => $def['handles'],
				'component' => '',
				'tokens'    => $def['tokens'],
				'ignore'    => array(),
				'contexts'  => array(),
				'without'   => $def['without'],
				'verify'    => true,
				'condition' => array(),
			);
		}

		return $markers;
	}

	/**
	 * `blt_optimized_rule_meta_keys`: page builders keep layouts in meta, and a
	 * Bricks page can carry its own header and footer trees.
	 *
	 * @param array $keys Meta keys.
	 * @return array
	 */
	public static function filter_rule_meta_keys( $keys ) {
		$keys = is_array( $keys ) ? $keys : array();
		foreach ( array( self::META_CONTENT, self::META_HEADER, self::META_FOOTER ) as $key ) {
			if ( ! in_array( $key, $keys, true ) ) {
				$keys[] = $key;
			}
		}
		return $keys;
	}

	/**
	 * `blt_optimized_rule_haystack`: what a "content contains" asset rule
	 * searches for the queried post. On a Bricks site a shortcode or form is
	 * often in the header or footer template, the active Single template, a
	 * template inserted with the Template element, or a component rather than
	 * in the post itself.
	 *
	 * Runs on the front end, but only when a "content contains" rule is being
	 * evaluated (Tweaks calls the filter then). It reads the request's active
	 * header/content/footer/popup templates from Bricks' internal
	 * `\Bricks\Database::$active_templates` (the documented `bricks/active_templates`
	 * filter fills it) and degrades to the post alone when that is unavailable.
	 *
	 * @param string       $haystack Text searched.
	 * @param WP_Post|null $post     Queried post.
	 * @return string
	 */
	public static function filter_rule_haystack( $haystack, $post = null ) {
		$haystack = (string) $haystack;
		if ( ! self::is_active() ) {
			return $haystack;
		}

		try {
			$post_id = ( $post instanceof WP_Post ) ? (int) $post->ID : 0;
			$ids     = self::active_template_ids();
			$seen    = array();
			$extra   = '';
			$budget  = 24;

			if ( $post_id > 0 ) {
				$seen[ $post_id ] = true;
			}

			$pending = $ids;
			while ( $pending && $budget > 0 ) {
				$id = (int) array_shift( $pending );
				if ( $id < 1 || isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
				--$budget;

				foreach ( array( self::META_CONTENT, self::META_HEADER, self::META_FOOTER ) as $key ) {
					$text = self::meta_text( $id, $key );
					if ( '' !== $text ) {
						$extra .= "\n" . $text;
					}
				}
			}

			// Templates inserted with the Template element, one level deep, and
			// component definitions an instance in the content points to.
			$source = $haystack . $extra;
			if ( false !== strpos( $source, 'template' ) ) {
				foreach ( self::referenced_template_ids( $source ) as $ref ) {
					if ( $budget < 1 ) {
						break;
					}
					if ( isset( $seen[ $ref ] ) ) {
						continue;
					}
					$seen[ $ref ] = true;
					--$budget;
					$extra .= "\n" . self::meta_text( $ref, self::META_CONTENT );
				}
			}
			if ( false !== strpos( $source, 'cid' ) ) {
				$components = get_option( self::OPT_COMPONENTS, array() );
				if ( is_array( $components ) && $components ) {
					$extra .= "\n" . wp_json_encode( $components );
				}
			}

			return $haystack . $extra;
		} catch ( \Throwable $e ) {
			return $haystack;
		}
	}

	/**
	 * Ids of the templates Bricks resolved for this request.
	 *
	 * @return int[]
	 */
	private static function active_template_ids() {
		$ids = array();
		if ( ! class_exists( '\Bricks\Database' ) || ! property_exists( '\Bricks\Database', 'active_templates' ) ) {
			return $ids;
		}
		$active = \Bricks\Database::$active_templates;
		if ( ! is_array( $active ) ) {
			return $ids;
		}
		foreach ( array( 'header', 'content', 'footer' ) as $area ) {
			if ( isset( $active[ $area ] ) && is_numeric( $active[ $area ] ) ) {
				$ids[] = (int) $active[ $area ];
			}
		}
		if ( isset( $active['popup'] ) ) {
			foreach ( (array) $active['popup'] as $popup ) {
				if ( is_numeric( $popup ) ) {
					$ids[] = (int) $popup;
				}
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Post meta as searchable text (JSON for arrays).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return string
	 */
	private static function meta_text( $post_id, $key ) {
		$value = get_post_meta( $post_id, $key, true );
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			$json = wp_json_encode( $value );
			return is_string( $json ) ? $json : '';
		}
		return '';
	}

	/**
	 * Template ids a block of Bricks data embeds through the Template element
	 * (`"template":"123"` in JSON, `s:8:"template";s:3:"123"` serialized).
	 *
	 * @param string $text Searchable text.
	 * @return int[]
	 */
	private static function referenced_template_ids( $text ) {
		$ids = array();
		if ( preg_match_all( '/"template"\s*:\s*"?(\d{1,10})"?|"template";s:\d+:"(\d{1,10})"/', $text, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$value = ( isset( $match[1] ) && '' !== $match[1] ) ? $match[1] : ( isset( $match[2] ) ? $match[2] : '' );
				if ( '' !== $value && count( $ids ) < 20 ) {
					$ids[ (int) $value ] = (int) $value;
				}
			}
		}
		return array_values( $ids );
	}

	/* ------------------------------------------------------------------ */
	/* Detection                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Whether Bricks (or a Bricks child theme) is the active theme. Safe to
	 * call at plugin load: the theme slug is read from the `template` option,
	 * because `BRICKS_VERSION` is only defined when the theme's functions.php
	 * runs, later than `plugins_loaded`.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		if ( self::THEME_SLUG === get_template() ) {
			return true;
		}
		return defined( 'BRICKS_VERSION' ) || function_exists( 'bricks_is_builder' );
	}

	/**
	 * Installed Bricks version, or '' when unknown.
	 *
	 * @return string
	 */
	public static function version(): string {
		if ( defined( 'BRICKS_VERSION' ) ) {
			return (string) BRICKS_VERSION;
		}
		$theme = wp_get_theme( self::THEME_SLUG );
		if ( $theme->exists() ) {
			$version = $theme->get( 'Version' );
			return is_string( $version ) ? $version : '';
		}
		return '';
	}

	/**
	 * The Bricks theme as a component, for attribution.
	 *
	 * @return array
	 */
	private static function component() {
		return BLT_Optimized_Components::make( 'theme', self::THEME_SLUG, 'Bricks' );
	}

	/**
	 * Diagnostics from checks that threw during the last findings() call.
	 *
	 * @return string[]
	 */
	public static function last_errors() {
		return self::$errors;
	}

	/**
	 * Forget memoised state (tests, long-running CLI).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$scan       = null;
		self::$css_scan   = null;
		self::$classes    = null;
		self::$thresholds = null;
		self::$located    = array();
		self::$errors     = array();
	}

	/**
	 * Threshold by constant name, filterable.
	 *
	 * @param string $name Constant name.
	 * @return mixed
	 */
	private static function t( $name ) {
		if ( null === self::$thresholds ) {
			$defaults = array();
			foreach ( ( new ReflectionClass( __CLASS__ ) )->getConstants() as $key => $value ) {
				if ( is_int( $value ) || is_float( $value ) ) {
					$defaults[ $key ] = $value;
				}
			}
			/**
			 * Filters the Bricks check thresholds (constant name => value).
			 *
			 * @param array $thresholds Thresholds.
			 */
			$filtered         = apply_filters( 'blt_optimized_bricks_thresholds', $defaults );
			self::$thresholds = is_array( $filtered ) ? array_merge( $defaults, $filtered ) : $defaults;
		}
		return isset( self::$thresholds[ $name ] ) ? self::$thresholds[ $name ] : constant( __CLASS__ . '::' . $name );
	}

	/* ------------------------------------------------------------------ */
	/* Element lookup (used by lab-test attribution)                       */
	/* ------------------------------------------------------------------ */

	/**
	 * The Bricks element id in a CSS selector, an HTML id, a class list or a
	 * bare id: `#brxe-abc123`, `.brxe-abc123.brxe-heading`, `brxe-abc123`,
	 * `abc123`. Bricks ids are six characters; `brxe-button` is an element
	 * type, not an id. Returns '' when there is none.
	 *
	 * @param string $selector_or_id Selector, id attribute, class list or id.
	 * @return string Lower-case id, or ''.
	 */
	public static function element_id_from_selector( string $selector_or_id ): string {
		$value = trim( $selector_or_id );
		if ( '' === $value || strlen( $value ) > 2000 ) {
			return '';
		}

		if ( preg_match_all( '/(#)?(?<![A-Za-z0-9_-])brxe-([A-Za-z0-9]+)(?![A-Za-z0-9_-])/', $value, $matches, PREG_SET_ORDER ) ) {
			$fallback = '';
			foreach ( $matches as $match ) {
				$candidate = strtolower( $match[2] );
				if ( 6 !== strlen( $candidate ) || in_array( $candidate, self::SIX_CHAR_ELEMENT_NAMES, true ) ) {
					continue;
				}
				if ( '#' === $match[1] ) {
					return $candidate;
				}
				if ( '' === $fallback ) {
					$fallback = $candidate;
				}
			}
			if ( '' !== $fallback ) {
				return $fallback;
			}
		}

		if ( preg_match( '/^#?([A-Za-z0-9]{6})$/', $value, $bare ) ) {
			$candidate = strtolower( $bare[1] );
			if ( ! in_array( $candidate, self::SIX_CHAR_ELEMENT_NAMES, true ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Find the post or template whose Bricks data contains an element id.
	 *
	 * A prepared LIKE on the three content meta keys narrows the candidates,
	 * then the decoded element list confirms it (the id also appears in other
	 * elements' `parent`/`children` and in class references) and gives the
	 * element's name. Falls back to the component definitions in
	 * `bricks_components`. Memoised per request.
	 *
	 * @param string $element_id Bricks element id (or anything element_id_from_selector() accepts).
	 * @return array|null { post_id, post_title, post_type, template_type, area (content|header|footer|component),
	 *                      element_name, edit_url, label, others (post ids), in_component }
	 */
	public static function locate_element( string $element_id ): ?array {
		global $wpdb;

		$id = self::element_id_from_selector( $element_id );
		if ( '' === $id ) {
			$bare = strtolower( trim( $element_id ) );
			$id   = preg_match( '/^[a-z0-9]{4,20}$/', $bare ) ? $bare : '';
		}
		if ( '' === $id || ! self::is_active() ) {
			return null;
		}
		if ( array_key_exists( $id, self::$located ) ) {
			return self::$located[ $id ];
		}
		self::$located[ $id ] = null;

		$keys         = array( self::META_CONTENT, self::META_HEADER, self::META_FOOTER );
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$like         = '%' . $wpdb->esc_like( '"' . $id . '"' ) . '%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.post_id, m.meta_key FROM {$wpdb->postmeta} m
				 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				 WHERE m.meta_key IN ($placeholders) AND m.meta_value LIKE %s AND p.post_type NOT IN ('revision','auto-draft')
				 ORDER BY ( p.post_status = 'publish' ) DESC, m.meta_id ASC LIMIT 25",
				array_merge( $keys, array( $like ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		$hit    = null;
		$others = array();
		foreach ( (array) $rows as $row ) {
			$post_id = (int) $row['post_id'];
			$element = self::find_element_in( self::decode( get_post_meta( $post_id, $row['meta_key'], true ) ), $id );
			if ( null === $element ) {
				continue;
			}
			if ( null === $hit ) {
				$hit = array(
					'post_id' => $post_id,
					'key'     => (string) $row['meta_key'],
					'element' => $element,
				);
			} elseif ( $post_id !== $hit['post_id'] && count( $others ) < 5 ) {
				$others[ $post_id ] = $post_id;
			}
		}

		if ( null !== $hit ) {
			$post = get_post( $hit['post_id'] );
			if ( $post instanceof WP_Post ) {
				$areas = array(
					self::META_HEADER => 'header',
					self::META_FOOTER => 'footer',
				);
				$title = trim( wp_strip_all_tags( (string) $post->post_title ) );

				self::$located[ $id ] = array(
					'post_id'       => (int) $post->ID,
					'post_title'    => '' !== $title ? $title : sprintf( '#%d', $post->ID ),
					'post_type'     => (string) $post->post_type,
					'template_type' => self::POST_TYPE === $post->post_type ? (string) get_post_meta( $post->ID, self::META_TEMPLATE_TYPE, true ) : '',
					'area'          => isset( $areas[ $hit['key'] ] ) ? $areas[ $hit['key'] ] : 'content',
					'element_name'  => isset( $hit['element']['name'] ) ? (string) $hit['element']['name'] : '',
					'edit_url'      => self::edit_url( $post->ID ),
					'label'         => isset( $hit['element']['label'] ) && is_string( $hit['element']['label'] ) ? $hit['element']['label'] : '',
					'others'        => array_values( $others ),
					'in_component'  => false,
				);
				return self::$located[ $id ];
			}
		}

		// Elements inside a component are defined in the bricks_components option.
		$components = get_option( self::OPT_COMPONENTS, array() );
		if ( is_array( $components ) ) {
			foreach ( $components as $component ) {
				if ( ! is_array( $component ) || empty( $component['elements'] ) || ! is_array( $component['elements'] ) ) {
					continue;
				}
				$element = self::find_element_in( $component['elements'], $id );
				if ( null === $element ) {
					continue;
				}
				$root  = reset( $component['elements'] );
				$label = ( is_array( $root ) && isset( $root['label'] ) && is_string( $root['label'] ) && '' !== $root['label'] ) ? $root['label'] : ( isset( $component['id'] ) ? (string) $component['id'] : '' );

				self::$located[ $id ] = array(
					'post_id'       => 0,
					'post_title'    => $label,
					'post_type'     => 'bricks_component',
					'template_type' => '',
					'area'          => 'component',
					'element_name'  => isset( $element['name'] ) ? (string) $element['name'] : '',
					'edit_url'      => '',
					'label'         => isset( $element['label'] ) && is_string( $element['label'] ) ? $element['label'] : '',
					'others'        => array(),
					'in_component'  => true,
				);
				return self::$located[ $id ];
			}
		}

		return null;
	}

	/**
	 * The "Edit with Bricks" URL for a post or template: its permalink with
	 * `?bricks=run` (how Bricks builds builder URLs).
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function edit_url( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id < 1 ) {
			return '';
		}
		$permalink = get_permalink( $post_id );
		$url       = is_string( $permalink ) && '' !== $permalink
			? add_query_arg( 'bricks', 'run', $permalink )
			: add_query_arg(
				array(
					'p'      => $post_id,
					'bricks' => 'run',
				),
				home_url( '/' )
			);

		/**
		 * Filters the "Edit with Bricks" link used in scan findings.
		 *
		 * @param string $url     URL.
		 * @param int    $post_id Post or template ID.
		 */
		$filtered = apply_filters( 'blt_optimized_bricks_edit_url', $url, $post_id );
		return is_string( $filtered ) ? $filtered : $url;
	}

	/**
	 * The element with an id in a decoded element list.
	 *
	 * @param mixed  $elements Decoded elements.
	 * @param string $id       Lower-case element id.
	 * @return array|null
	 */
	private static function find_element_in( $elements, $id ) {
		if ( ! is_array( $elements ) ) {
			return null;
		}
		foreach ( $elements as $element ) {
			if ( is_array( $element ) && isset( $element['id'] ) && is_scalar( $element['id'] ) && 0 === strcasecmp( (string) $element['id'], $id ) ) {
				return $element;
			}
		}
		return null;
	}

	/**
	 * Decode Bricks data: a PHP array (WordPress already unserialized it), a
	 * serialized string (also double-serialized), or JSON. Objects are never
	 * instantiated.
	 *
	 * @param mixed $raw Value from post meta or the database.
	 * @return array|null
	 */
	private static function decode( $raw ) {
		for ( $i = 0; $i < 3; $i++ ) {
			if ( is_array( $raw ) ) {
				return $raw;
			}
			if ( ! is_string( $raw ) ) {
				return null;
			}
			$raw = trim( $raw );
			if ( '' === $raw ) {
				return null;
			}
			if ( is_serialized( $raw ) ) {
				$value = @unserialize( $raw, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- never instantiates objects.
				if ( false === $value && 'b:0;' !== $raw ) {
					return null;
				}
				$raw = $value;
				continue;
			}
			if ( '[' === $raw[0] || '{' === $raw[0] ) {
				$value = json_decode( $raw, true );
				return is_array( $value ) ? $value : null;
			}
			return null;
		}
		return is_array( $raw ) ? $raw : null;
	}

	/* ------------------------------------------------------------------ */
	/* Findings                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * All Bricks findings.
	 *
	 * @param array[] $pages Optional page reports from the scan (profile + HTML analysis); the
	 *                       checks that need page evidence (CSS loading, fonts, icon fonts) are
	 *                       skipped without them.
	 * @return array[] Findings (BLT_Optimized_Findings shape).
	 */
	public function findings( array $pages = array() ): array {
		if ( ! self::is_active() ) {
			return array();
		}

		self::$errors = array();
		$out          = array();
		$checks       = array(
			'check_css_loading',
			'check_options',
			'check_orphan_css',
			'check_content',
			'check_google_fonts',
			'check_icon_fonts',
			'check_query_cache',
			'check_search_data',
			'check_lazy_load',
		);

		foreach ( $checks as $check ) {
			try {
				$result = $this->$check( $pages );
				if ( is_array( $result ) ) {
					$out = array_merge( $out, $result );
				}
			} catch ( \Throwable $e ) {
				self::$errors[] = $check . ': ' . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')';
			}
		}

		return array_values( $out );
	}

	/**
	 * Build a finding with the Bricks defaults.
	 *
	 * @param array $args Finding arguments.
	 * @return array
	 */
	private function finding( array $args ) {
		$args += array(
			'source'    => 'bricks',
			'component' => self::component(),
		);
		return BLT_Optimized_Findings::make( $args );
	}

	/**
	 * Give the generic findings that name Bricks' own options an owner.
	 *
	 * @param array $findings Findings.
	 * @return array
	 */
	private static function attribute_generic_findings( array $findings ) {
		foreach ( $findings as $index => $finding ) {
			if ( ! is_array( $finding ) || ! isset( $finding['key'] ) || ! is_string( $finding['key'] ) ) {
				continue;
			}
			if ( 0 === strpos( $finding['key'], 'db.autoload_option:bricks_' ) && empty( $finding['component'] ) ) {
				$findings[ $index ]['component'] = self::component();
			}
		}
		return $findings;
	}

	/**
	 * Bricks settings screen URL (Performance tab).
	 *
	 * @return string
	 */
	private static function settings_url() {
		return admin_url( 'admin.php?page=bricks-settings#tab-performance' );
	}

	/**
	 * The `bricks_global_settings` option.
	 *
	 * @return array
	 */
	private function settings() {
		$settings = get_option( self::OPT_SETTINGS, array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Whether a checkbox setting is switched on (stored only when ticked).
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	private function setting_on( $key ) {
		$settings = $this->settings();
		if ( ! array_key_exists( $key, $settings ) ) {
			return false;
		}
		$value = $settings[ $key ];
		return ! ( false === $value || null === $value || '' === $value || 0 === $value || '0' === $value || 'off' === $value || 'false' === $value );
	}

	/**
	 * CSS loading method: 'inline' (default), 'file' (external files), '' unknown.
	 *
	 * @return string
	 */
	private function css_mode() {
		$settings = $this->settings();
		$value    = isset( $settings['cssLoading'] ) ? $settings['cssLoading'] : '';
		if ( '' === $value || null === $value || false === $value ) {
			return 'inline';
		}
		return 'file' === $value ? 'file' : '';
	}

	/**
	 * Page reports that carry usable evidence.
	 *
	 * @param array[] $pages Page reports.
	 * @return array[]
	 */
	private function usable_pages( array $pages ) {
		$out = array();
		foreach ( $pages as $page ) {
			if ( is_array( $page ) && ! empty( $page['url'] ) && ( ! empty( $page['profile'] ) || ! empty( $page['html'] ) ) ) {
				$out[] = $page;
			}
		}
		return $out;
	}

	/**
	 * Path of a page URL, for evidence rows.
	 *
	 * @param array $page Page report.
	 * @return string
	 */
	private function page_ref( array $page ) {
		$path = (string) wp_parse_url( (string) $page['url'], PHP_URL_PATH );
		$path = '' === $path ? '/' : $path;
		return isset( $page['label'] ) && '' !== $page['label'] ? sprintf( '%s (%s)', $path, $page['label'] ) : $path;
	}

	/**
	 * Plain-text label of a post or template: `Page “Home” (ID 5)`,
	 * `Header template “Main” (ID 12)`.
	 *
	 * @param array $info Document info (title, type, template_type).
	 * @param int   $id   Post ID.
	 * @return string
	 */
	private function doc_label( array $info, $id ) {
		$title = trim( wp_strip_all_tags( isset( $info['title'] ) ? (string) $info['title'] : '' ) );
		$title = '' === $title ? __( '(no title)', 'blt-optimized' ) : $title;
		if ( function_exists( 'mb_strimwidth' ) ) {
			$title = mb_strimwidth( $title, 0, 60, '…' );
		}

		if ( isset( $info['type'] ) && self::POST_TYPE === $info['type'] ) {
			$kind = $this->template_type_label( isset( $info['template_type'] ) ? (string) $info['template_type'] : '' );
		} else {
			$type_object = isset( $info['type'] ) ? get_post_type_object( (string) $info['type'] ) : null;
			$kind        = ( $type_object && ! empty( $type_object->labels->singular_name ) ) ? (string) $type_object->labels->singular_name : ( isset( $info['type'] ) ? (string) $info['type'] : __( 'Post', 'blt-optimized' ) );
		}

		/* translators: 1: kind ("Page", "Header template"), 2: title, 3: post ID. */
		return sprintf( __( '%1$s “%2$s” (ID %3$d)', 'blt-optimized' ), $kind, $title, (int) $id );
	}

	/**
	 * Label of a template type.
	 *
	 * @param string $type Template type.
	 * @return string
	 */
	private function template_type_label( $type ) {
		$labels = array(
			'header'              => __( 'Header template', 'blt-optimized' ),
			'footer'              => __( 'Footer template', 'blt-optimized' ),
			'content'             => __( 'Single template', 'blt-optimized' ),
			'section'             => __( 'Section template', 'blt-optimized' ),
			'popup'               => __( 'Popup template', 'blt-optimized' ),
			'archive'             => __( 'Archive template', 'blt-optimized' ),
			'search'              => __( 'Search results template', 'blt-optimized' ),
			'error'               => __( 'Error page template', 'blt-optimized' ),
			'password_protection' => __( 'Password protection template', 'blt-optimized' ),
		);
		if ( isset( $labels[ $type ] ) ) {
			return $labels[ $type ];
		}
		return '' !== $type
			/* translators: %s: Bricks template type slug. */
			? sprintf( __( 'Template (%s)', 'blt-optimized' ), $type )
			: __( 'Template', 'blt-optimized' );
	}

	/**
	 * Manual-fix helper.
	 *
	 * @param string $label Button label.
	 * @param string $steps Steps.
	 * @return array
	 */
	private function fix_manual( $label, $steps ) {
		return array(
			'type'  => 'manual',
			'label' => $label,
			'steps' => $steps,
		);
	}

	/**
	 * Edit-link fix.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	private function fix_edit( $post_id ) {
		$url = self::edit_url( $post_id );
		return '' === $url ? null : array(
			'type'  => 'link',
			'label' => __( 'Edit with Bricks', 'blt-optimized' ),
			'url'   => $url,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Check: CSS loading method                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Review of the CSS loading method (Bricks > Settings > Performance).
	 *
	 * Neither method is always better, so this never claims a saving: it flags
	 * the one broken state (External files on, no generated files) and, for
	 * the default Inline method, a page whose inline Bricks CSS is large
	 * enough that caching it is worth testing.
	 *
	 * @param array[] $pages Page reports.
	 * @return array[]
	 */
	private function check_css_loading( array $pages ) {
		$mode = $this->css_mode();
		if ( '' === $mode ) {
			return array();
		}

		$css      = $this->css_scan();
		$findings = array();

		if ( 'file' === $mode ) {
			$docs = $this->bricks_document_count();
			if ( $docs > 0 && 0 === (int) $css['post_files'] ) {
				$findings[] = $this->finding(
					array(
						'id'       => 'bricks.css_loading',
						'key'      => 'bricks.css_loading:missing_files',
						'category' => 'assets',
						'severity' => 'warning',
						'title'    => __( 'Bricks is set to load CSS from external files, but no generated CSS files exist', 'blt-optimized' ),
						'summary'  => sprintf(
							/* translators: 1: folder path, 2: number of Bricks pages and templates. */
							__( 'The CSS loading method is “External files”, but %1$s holds no per-page CSS files for the %2$s pages and templates built with Bricks. Bricks documents that “Regenerate CSS files” must be clicked after switching to External files so it can create the folder and the files; without them pages can miss their styles.', 'blt-optimized' ),
							'wp-content/uploads/bricks/css',
							number_format_i18n( $docs )
						),
						'impact'   => array(
							'type'  => 'count',
							'value' => $docs,
							'label' => '',
						),
						'where'    => array( self::settings_url(), 'wp-content/uploads/bricks/css' ),
						'details'  => array(
							'columns' => array( __( 'Item', 'blt-optimized' ), __( 'Value', 'blt-optimized' ) ),
							'rows'    => array(
								array( __( 'CSS loading method', 'blt-optimized' ), __( 'External files', 'blt-optimized' ) ),
								array( __( 'Folder exists', 'blt-optimized' ), $css['exists'] ? __( 'Yes', 'blt-optimized' ) : __( 'No', 'blt-optimized' ) ),
								array( __( 'Per-page CSS files found', 'blt-optimized' ), 0 ),
								array( __( 'Pages and templates with Bricks content', 'blt-optimized' ), $docs ),
							),
						),
						'fix'      => $this->fix_manual(
							__( 'Regenerate the CSS files', 'blt-optimized' ),
							__( "1. In the WordPress admin open Bricks → Settings → Performance.\n2. Under “CSS loading method” click “Regenerate CSS files”. (With WP-CLI: wp bricks regenerate_assets.)\n3. If the files cannot be written, make wp-content/uploads writable by the web server.\n4. If External files conflicts with your caching plugin or outputs wrong or missing styles, switch back to “Inline styles”, as Bricks recommends.", 'blt-optimized' )
						),
					)
				);
			} elseif ( $css['post_files'] > 0 ) {
				$findings[] = $this->finding(
					array(
						'id'       => 'bricks.css_loading',
						'key'      => 'bricks.css_loading:external_ok',
						'category' => 'assets',
						'severity' => 'pass',
						'title'    => __( 'Bricks serves its page CSS as external files the browser can cache', 'blt-optimized' ),
						'summary'  => sprintf(
							/* translators: 1: number of files, 2: size. */
							__( 'The CSS loading method is “External files” and %1$s generated page CSS files (%2$s) are in place.', 'blt-optimized' ),
							number_format_i18n( $css['post_files'] ),
							size_format( $css['post_bytes'], 1 )
						) . $this->last_regeneration_note(),
					)
				);
			}
			return $findings;
		}

		// Inline (default): review when a profiled page carries a lot of Bricks CSS in its HTML.
		$rows  = array();
		$worst = 0;
		foreach ( $this->usable_pages( $pages ) as $page ) {
			$inline = $this->bricks_inline_css( $page );
			if ( $inline['bytes'] <= 0 ) {
				continue;
			}
			$worst  = max( $worst, $inline['bytes'] );
			$rows[] = array( $this->page_ref( $page ), $inline['bytes'], $inline['source'] );
		}
		if ( $worst < (int) self::t( 'INLINE_CSS_REVIEW_BYTES' ) ) {
			return $findings;
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return $b[1] <=> $a[1];
			}
		);
		foreach ( $rows as $i => $row ) {
			$rows[ $i ][1] = size_format( $row[1], 1 );
		}

		$findings[] = $this->finding(
			array(
				'id'       => 'bricks.css_loading',
				'key'      => 'bricks.css_loading:inline_review',
				'category' => 'assets',
				'severity' => 'notice',
				'title'    => sprintf(
					/* translators: %s: size of the inline CSS on the heaviest scanned page. */
					__( 'Bricks prints up to %s of CSS inside every page’s HTML (CSS loading method: Inline styles)', 'blt-optimized' ),
					size_format( $worst, 1 )
				),
				'summary'  => __( 'With the default Inline styles method Bricks writes its generated CSS into <style> tags in each page. That keeps the CSS always current and saves a request, but it is sent again with every page view and cannot be cached by the browser or a CDN. The External files method writes the CSS to wp-content/uploads/bricks/css so it can be cached. Which is faster depends on the site (first visits versus repeat visits, and your caching plugin), so measure both before keeping the change.', 'blt-optimized' ),
				'impact'   => array(
					'type'  => 'bytes',
					'value' => $worst,
					'label' => '',
				),
				'where'    => array( self::settings_url() ),
				'details'  => array(
					'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Bricks CSS in the HTML', 'blt-optimized' ), __( 'Measured from', 'blt-optimized' ) ),
					'rows'    => array_slice( $rows, 0, self::MAX_ROWS ),
				),
				'fix'      => $this->fix_manual(
					__( 'Try External files', 'blt-optimized' ),
					__( "1. Open Bricks → Settings → Performance and set “CSS loading method” to “External files”.\n2. Click “Regenerate CSS files” (or run wp bricks regenerate_assets). Bricks needs the folder and files to exist.\n3. Compare a first visit and a repeat visit to a heavy page with both methods.\n4. If External files conflicts with your caching plugin or outputs missing styles, switch back to “Inline styles”.", 'blt-optimized' )
				),
			)
		);

		return $findings;
	}

	/**
	 * " Bricks last regenerated them on …" when Bricks recorded it (optional:
	 * the option name is only known from third-party tools).
	 *
	 * @return string
	 */
	private function last_regeneration_note() {
		$time = (int) get_option( self::OPT_LAST_CSS_GEN, 0 );
		if ( $time < 1 ) {
			return '';
		}
		return ' ' . sprintf(
			/* translators: %s: date of the last "Regenerate CSS files". */
			__( 'Bricks last regenerated them on %s.', 'blt-optimized' ),
			wp_date( get_option( 'date_format' ), $time )
		);
	}

	/**
	 * Bricks' inline CSS on one scanned page.
	 *
	 * @param array $page Page report.
	 * @return array { bytes: int, source: string }
	 */
	private function bricks_inline_css( array $page ) {
		$bytes = 0;
		if ( isset( $page['profile']['assets']['styles'] ) && is_array( $page['profile']['assets']['styles'] ) ) {
			foreach ( $page['profile']['assets']['styles'] as $style ) {
				if ( is_array( $style ) && isset( $style['handle'], $style['inline_bytes'] ) && 0 === strpos( (string) $style['handle'], 'bricks' ) ) {
					$bytes += (int) $style['inline_bytes'];
				}
			}
			if ( $bytes > 0 ) {
				return array(
					'bytes'  => $bytes,
					'source' => __( 'enqueued styles (bricks-*)', 'blt-optimized' ),
				);
			}
		}

		$largest = isset( $page['html']['inline_styles']['largest'] ) && is_array( $page['html']['inline_styles']['largest'] ) ? $page['html']['inline_styles']['largest'] : array();
		if ( ! empty( $largest['id'] ) && false !== stripos( (string) $largest['id'], 'bricks' ) ) {
			return array(
				'bytes'  => (int) $largest['bytes'],
				'source' => sprintf(
					/* translators: %s: style element id. */
					__( '<style id="%s">', 'blt-optimized' ),
					$largest['id']
				),
			);
		}
		return array(
			'bytes'  => 0,
			'source' => '',
		);
	}

	/* ------------------------------------------------------------------ */
	/* Check: bricks_* options                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Oversized or autoloaded `bricks_*` options: each one named, with its
	 * size, autoload flag and what is inside.
	 *
	 * @param array[] $pages Unused.
	 * @return array[]
	 */
	private function check_options( array $pages ) {
		global $wpdb;
		unset( $pages );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, autoload, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY bytes DESC LIMIT 60",
				$wpdb->esc_like( 'bricks_' ) . '%'
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( empty( $rows ) ) {
			return array();
		}

		$notice_at    = (int) self::t( 'OPTION_NOTICE_BYTES' );
		$warn_at      = (int) self::t( 'OPTION_WARN_BYTES' );
		$autoload_sum = 0;
		$total        = 0;
		$worst_loaded = 0;
		$table        = array();
		$flag         = false;
		foreach ( $rows as $row ) {
			$bytes  = (int) $row['bytes'];
			$loaded = self::autoload_is_on( (string) $row['autoload'] );
			$total += $bytes;
			if ( $loaded ) {
				$autoload_sum += $bytes;
				$worst_loaded  = max( $worst_loaded, $bytes );
			}
			if ( $bytes >= $notice_at ) {
				$flag = true;
			}
			if ( count( $table ) < 12 ) {
				$table[] = array(
					(string) $row['option_name'],
					$this->option_label( (string) $row['option_name'] ),
					size_format( $bytes, 1 ),
					$loaded ? __( 'Yes', 'blt-optimized' ) : __( 'No', 'blt-optimized' ),
					$this->option_contents( (string) $row['option_name'] ),
				);
			}
		}

		if ( ! $flag && $autoload_sum < (int) self::t( 'AUTOLOAD_NOTICE_BYTES' ) ) {
			return array();
		}

		$heavy = ( $worst_loaded >= $warn_at || $autoload_sum >= (int) self::t( 'AUTOLOAD_WARN_BYTES' ) );
		$names = array();
		foreach ( array_slice( $rows, 0, 3 ) as $row ) {
			if ( (int) $row['bytes'] >= $notice_at ) {
				$names[] = $row['option_name'];
			}
		}

		return array(
			$this->finding(
				array(
					'id'       => 'bricks.options',
					'key'      => 'bricks.options',
					'category' => 'database',
					'severity' => $heavy ? 'warning' : 'notice',
					'title'    => $names
						? sprintf(
							/* translators: 1: option names, 2: total size. */
							__( 'Bricks stores %2$s in its options table rows (largest: %1$s)', 'blt-optimized' ),
							implode( ', ', $names ),
							size_format( $total, 1 )
						)
						: sprintf(
							/* translators: %s: size of Bricks options that load on every request. */
							__( 'Bricks options that load on every request add up to %s', 'blt-optimized' ),
							size_format( $autoload_sum, 1 )
						),
					'summary'  => sprintf(
						/* translators: 1: autoloaded size, 2: total size. */
						__( 'Bricks keeps global classes, variables, theme styles, components and settings in bricks_* options. %1$s of that is flagged to load on every request (%2$s in total). The size comes from data that is no longer needed — unused or trashed global classes, unused components and variables, large custom CSS pasted into a class — so cleaning that data is safer than changing the autoload flag, because Bricks reads these options itself.', 'blt-optimized' ),
						size_format( $autoload_sum, 1 ),
						size_format( $total, 1 )
					),
					'impact'   => array(
						'type'  => 'bytes',
						'value' => $autoload_sum > 0 ? $autoload_sum : $total,
						'label' => '',
					),
					'where'    => array_map(
						static function ( $row ) {
							return (string) $row['option_name'];
						},
						array_slice( $rows, 0, 8 )
					),
					'details'  => array(
						'columns' => array( __( 'Option', 'blt-optimized' ), __( 'Holds', 'blt-optimized' ), __( 'Size', 'blt-optimized' ), __( 'Loads on every request', 'blt-optimized' ), __( 'Inside', 'blt-optimized' ) ),
						'rows'    => $table,
					),
					'fix'      => $this->fix_manual(
						__( 'Trim Bricks data', 'blt-optimized' ),
						__( "1. Back up the database (or export the classes from the Class Manager).\n2. In the builder open Style Manager → Classes. Review the unused classes (see the unused-classes finding), move them to the trash, then permanently delete them from the trash — trashed classes are still stored.\n3. Open the Components panel and review components filtered to “Unused”; delete the ones you do not need. Do the same for unused variables (Variable Manager, Trash folder).\n4. Move large custom CSS blocks from classes into a stylesheet in your child theme.", 'blt-optimized' )
					),
				)
			),
		);
	}

	/**
	 * Autoload flag value meaning "loads on every request".
	 *
	 * @param string $value Stored autoload value.
	 * @return bool
	 */
	private static function autoload_is_on( $value ) {
		return in_array( $value, array( 'yes', 'on', 'auto', 'auto-on' ), true );
	}

	/**
	 * What a known Bricks option holds.
	 *
	 * @param string $name Option name.
	 * @return string
	 */
	private function option_label( $name ) {
		$labels = array(
			'bricks_global_classes'   => __( 'Global classes', 'blt-optimized' ),
			'bricks_global_variables' => __( 'Global variables', 'blt-optimized' ),
			'bricks_theme_styles'     => __( 'Theme styles', 'blt-optimized' ),
			'bricks_color_palette'    => __( 'Color palettes', 'blt-optimized' ),
			'bricks_components'       => __( 'Components', 'blt-optimized' ),
			'bricks_breakpoints'      => __( 'Breakpoints', 'blt-optimized' ),
			'bricks_pseudo_classes'   => __( 'Pseudo-classes', 'blt-optimized' ),
			'bricks_global_settings'  => __( 'Settings', 'blt-optimized' ),
		);
		return isset( $labels[ $name ] ) ? $labels[ $name ] : __( 'Bricks data', 'blt-optimized' );
	}

	/**
	 * A short description of what is inside a known option (item counts).
	 *
	 * @param string $name Option name.
	 * @return string
	 */
	private function option_contents( $name ) {
		$known = array( 'bricks_global_classes', 'bricks_global_variables', 'bricks_theme_styles', 'bricks_color_palette', 'bricks_components' );
		if ( ! in_array( $name, $known, true ) ) {
			return '';
		}
		$value = get_option( $name, array() );
		if ( ! is_array( $value ) ) {
			return '';
		}

		if ( 'bricks_global_classes' === $name ) {
			$classes = $this->global_classes();
			$all     = $classes['live'] + $classes['trashed'];
			uasort(
				$all,
				static function ( $a, $b ) {
					return $b['bytes'] <=> $a['bytes'];
				}
			);
			$biggest = array();
			foreach ( array_slice( $all, 0, 2, true ) as $class ) {
				$biggest[] = sprintf( '%s (%s)', $class['name'], size_format( $class['bytes'], 1 ) );
			}
			return sprintf(
				/* translators: 1: live classes, 2: trashed classes, 3: the largest classes. */
				__( '%1$s classes, %2$s in the trash; largest: %3$s', 'blt-optimized' ),
				number_format_i18n( count( $classes['live'] ) ),
				number_format_i18n( count( $classes['trashed'] ) ),
				$biggest ? implode( ', ', $biggest ) : '-'
			);
		}

		/* translators: %s: number of items. */
		return sprintf( _n( '%s item', '%s items', count( $value ), 'blt-optimized' ), number_format_i18n( count( $value ) ) );
	}

	/**
	 * Global classes, split into live and trashed. Entries without an `id`
	 * and a `name` are skipped (not classes).
	 *
	 * @return array { live: id => { name, category, bytes }, trashed: id => { name, bytes } }
	 */
	private function global_classes() {
		if ( null !== self::$classes ) {
			return self::$classes;
		}

		$raw = get_option( self::OPT_CLASSES, array() );
		$out = array(
			'live'    => array(),
			'trashed' => array(),
		);
		if ( ! is_array( $raw ) ) {
			self::$classes = $out;
			return $out;
		}

		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['id'], $item['name'] ) || ! is_scalar( $item['id'] ) || ! is_scalar( $item['name'] ) ) {
				continue;
			}
			$id   = (string) $item['id'];
			$name = (string) $item['name'];
			if ( '' === $id || '' === $name ) {
				continue;
			}
			$entry = array(
				'name'     => $name,
				'category' => isset( $item['category'] ) && is_scalar( $item['category'] ) ? (string) $item['category'] : '',
				'bytes'    => strlen( serialize( $item ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- measuring stored size.
			);
			if ( ! empty( $item['deletedAt'] ) ) {
				$out['trashed'][ $id ] = $entry;
			} else {
				$out['live'][ $id ] = $entry;
			}
		}
		self::$classes = $out;
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Check: orphaned CSS files                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Orphaned external CSS files. Stores the exact list as the allow-list
	 * the delete fix acts on.
	 *
	 * @param array[] $pages Unused.
	 * @return array[]
	 */
	private function check_orphan_css( array $pages ) {
		unset( $pages );
		$css = $this->css_scan();

		$this->store_orphans( $css );

		if ( empty( $css['orphans'] ) ) {
			return array();
		}

		$orphans = $css['orphans'];
		$count   = count( $orphans );
		$bytes   = (int) $css['orphan_bytes'];
		$reasons = array(
			'deleted'   => __( 'Post no longer exists', 'blt-optimized' ),
			'no_bricks' => __( 'Post no longer uses Bricks', 'blt-optimized' ),
		);

		$rows = array();
		foreach ( array_slice( $orphans, 0, self::MAX_ROWS ) as $file ) {
			$rows[] = array(
				$file['name'],
				(int) $file['post_id'],
				isset( $reasons[ $file['reason'] ] ) ? $reasons[ $file['reason'] ] : $file['reason'],
				size_format( (int) $file['bytes'], 1 ),
				$file['mtime'] ? wp_date( 'Y-m-d', (int) $file['mtime'] ) : '',
			);
		}

		$names = wp_list_pluck( array_slice( $orphans, 0, self::FIX_MAX_ITEMS ), 'name' );
		$more  = $count > self::FIX_MAX_ITEMS;

		return array(
			$this->finding(
				array(
					'id'       => 'bricks.orphan_css',
					'key'      => 'bricks.orphan_css',
					'category' => 'storage',
					'severity' => ( $count >= (int) self::t( 'CSS_WARN_FILES' ) || $bytes >= (int) self::t( 'CSS_WARN_BYTES' ) ) ? 'warning' : 'notice',
					'title'    => sprintf(
						/* translators: 1: number of files, 2: size, 3: folder. */
						_n( '%1$s orphaned Bricks CSS file (%2$s) in %3$s', '%1$s orphaned Bricks CSS files (%2$s) in %3$s', $count, 'blt-optimized' ),
						number_format_i18n( $count ),
						size_format( $bytes, 1 ),
						'uploads/bricks/css'
					),
					'summary'  => __( 'With the External files CSS method Bricks writes one post-{ID}.min.css file per page or template, and nothing removes the file when that post is deleted or stops using Bricks. These files belong to posts that no longer exist or no longer have any Bricks data, so no page can load them. They are small, but they pile up on sites that build and delete many pages, and they bloat backups and file listings. Deleting them is safe: Bricks regenerates a file whenever a post that needs one is saved or when you click “Regenerate CSS files”.', 'blt-optimized' ) . ( $more ? ' ' . sprintf(
						/* translators: %d: files not covered by one click. */
						__( 'One click deletes the first %d; run the scan again for the rest.', 'blt-optimized' ),
						self::FIX_MAX_ITEMS
					) : '' ),
					'impact'   => array(
						'type'  => 'bytes',
						'value' => $bytes,
						'label' => '',
					),
					'where'    => array( 'wp-content/uploads/bricks/css' ),
					'details'  => array(
						'columns' => array( __( 'File', 'blt-optimized' ), __( 'Post ID', 'blt-optimized' ), __( 'Why it is orphaned', 'blt-optimized' ), __( 'Size', 'blt-optimized' ), __( 'Last written', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => array(
						'type'        => 'action',
						'label'       => __( 'Delete orphaned CSS files', 'blt-optimized' ),
						'action'      => 'bricks_delete_css_files',
						'args'        => array( 'files' => array_values( $names ) ),
						'destructive' => true,
						'confirm'     => sprintf(
							/* translators: 1: number of files, 2: size. */
							_n( 'Delete %1$s orphaned Bricks CSS file (%2$s)? Only post-{ID}.min.css files whose post is gone or has no Bricks data are removed, and each is checked again just before deletion.', 'Delete %1$s orphaned Bricks CSS files (%2$s)? Only post-{ID}.min.css files whose post is gone or has no Bricks data are removed, and each is checked again just before deletion.', min( $count, self::FIX_MAX_ITEMS ), 'blt-optimized' ),
							number_format_i18n( min( $count, self::FIX_MAX_ITEMS ) ),
							size_format( $bytes, 1 )
						),
					),
				)
			),
		);
	}

	/**
	 * The Bricks CSS folder, verified: inside the uploads directory, neither
	 * it nor its parent a symlink.
	 *
	 * @return string|false Normalised absolute path, or false.
	 */
	private static function css_dir() {
		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return false;
		}
		$base = realpath( $uploads['basedir'] );
		if ( false === $base ) {
			return false;
		}
		$base = wp_normalize_path( $base );
		$raw  = $uploads['basedir'] . '/bricks/css';
		if ( is_link( $uploads['basedir'] . '/bricks' ) || is_link( $raw ) ) {
			return false;
		}
		$dir = realpath( $raw );
		if ( false === $dir || ! is_dir( $dir ) ) {
			return false;
		}
		$dir = wp_normalize_path( $dir );
		if ( 0 !== strpos( $dir, rtrim( $base, '/' ) . '/' ) ) {
			return false;
		}
		return $dir;
	}

	/**
	 * Scan the Bricks CSS folder once per request: count what is there and
	 * find the per-post files whose post is gone or has no Bricks data.
	 *
	 * Only `post-{ID}.min.css` is ever a candidate; every other file
	 * (frontend/global/theme-style files and anything unknown) is counted and
	 * left alone.
	 *
	 * @return array { exists, total_files, other_files, post_files, post_bytes, orphans[], orphan_bytes, partial, checked_at }
	 */
	private function css_scan() {
		if ( null !== self::$css_scan ) {
			return self::$css_scan;
		}

		$result = array(
			'exists'       => false,
			'total_files'  => 0,
			'other_files'  => 0,
			'post_files'   => 0,
			'post_bytes'   => 0,
			'orphans'      => array(),
			'orphan_bytes' => 0,
			'partial'      => false,
			'checked_at'   => time(),
		);

		$dir = self::css_dir();
		if ( false === $dir ) {
			self::$css_scan = $result;
			return $result;
		}
		$result['exists'] = true;

		$handle = @opendir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $handle ) {
			self::$css_scan = $result;
			return $result;
		}

		$deadline   = microtime( true ) + (float) self::t( 'CSS_LIST_BUDGET' );
		$max        = (int) self::t( 'CSS_LIST_MAX' );
		$candidates = array(); // post id => file info[].
		$seen       = 0;
		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- standard readdir loop.
		while ( false !== ( $entry = readdir( $handle ) ) ) {
			if ( '.' === $entry || '..' === $entry || '.' === $entry[0] ) {
				continue;
			}
			if ( ++$seen > $max || ( 0 === $seen % 500 && microtime( true ) > $deadline ) ) {
				$result['partial'] = true;
				break;
			}
			++$result['total_files'];
			if ( ! preg_match( self::CSS_FILE_PATTERN, $entry, $match ) ) {
				++$result['other_files'];
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_link( $path ) || ! is_file( $path ) ) {
				++$result['other_files'];
				continue;
			}
			$size = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$time = (int) @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			++$result['post_files'];
			$result['post_bytes']           += $size;
			$candidates[ (int) $match[1] ][] = array(
				'name'  => $entry,
				'bytes' => $size,
				'mtime' => $time,
			);
		}
		closedir( $handle );

		if ( $candidates ) {
			$status = $this->css_owner_status( array_keys( $candidates ) );
			foreach ( $candidates as $post_id => $files ) {
				$state = isset( $status[ $post_id ] ) ? $status[ $post_id ] : 'deleted';
				if ( 'deleted' !== $state && 'no_bricks' !== $state ) {
					continue;
				}
				foreach ( $files as $file ) {
					$result['orphans'][]     = array(
						'name'    => $file['name'],
						'post_id' => (int) $post_id,
						'bytes'   => (int) $file['bytes'],
						'mtime'   => (int) $file['mtime'],
						'reason'  => $state,
					);
					$result['orphan_bytes'] += (int) $file['bytes'];
				}
			}
			usort(
				$result['orphans'],
				static function ( $a, $b ) {
					$by_size = $b['bytes'] <=> $a['bytes'];
					return 0 !== $by_size ? $by_size : strcmp( $a['name'], $b['name'] );
				}
			);
		}

		self::$css_scan = $result;
		return $result;
	}

	/**
	 * What became of the post behind a CSS file: 'deleted' (no such post),
	 * 'no_bricks' (exists, no Bricks data), or 'keep' (uses Bricks, is in the
	 * trash, or is a revision).
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return array post id => state.
	 */
	private function css_owner_status( array $post_ids ) {
		global $wpdb;

		$status = array();
		$keys   = array( self::META_CONTENT, self::META_HEADER, self::META_FOOTER, self::META_PAGE_SETTINGS, self::META_TEMPLATE_TYPE, self::META_TEMPLATE_SETTINGS );
		$key_in = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

		foreach ( array_chunk( array_map( 'intval', $post_ids ), (int) self::t( 'CSS_ID_CHUNK' ) ) as $chunk ) {
			$id_in = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$posts     = $wpdb->get_results(
				$wpdb->prepare( "SELECT ID, post_type, post_status FROM {$wpdb->posts} WHERE ID IN ($id_in)", $chunk ),
				ARRAY_A
			);
			$with_data = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE post_id IN ($id_in) AND meta_key IN ($key_in) AND meta_value <> '' AND meta_value <> 'a:0:{}' AND meta_value <> '[]'",
					array_merge( $chunk, $keys )
				)
			);
			// phpcs:enable

			$exists = array();
			foreach ( (array) $posts as $row ) {
				$exists[ (int) $row['ID'] ] = $row;
			}
			$has = array_flip( array_map( 'intval', (array) $with_data ) );

			foreach ( $chunk as $post_id ) {
				if ( ! isset( $exists[ $post_id ] ) ) {
					$status[ $post_id ] = 'deleted';
				} elseif ( 'trash' === $exists[ $post_id ]['post_status'] || in_array( $exists[ $post_id ]['post_type'], array( 'revision', 'auto-draft' ), true ) ) {
					$status[ $post_id ] = 'keep';
				} elseif ( isset( $has[ $post_id ] ) ) {
					$status[ $post_id ] = 'keep';
				} else {
					$status[ $post_id ] = 'no_bricks';
				}
			}
		}

		return $status;
	}

	/**
	 * Store the orphan list the delete fix may act on (non-autoloaded; replaced
	 * on every scan, so an old list never lingers).
	 *
	 * @param array $css CSS folder scan.
	 * @return void
	 */
	private function store_orphans( array $css ) {
		$stored = array(
			'checked_at' => time(),
			'files'      => array(),
		);
		foreach ( array_slice( (array) $css['orphans'], 0, 5000 ) as $file ) {
			$stored['files'][] = array(
				'name'    => (string) $file['name'],
				'post_id' => (int) $file['post_id'],
				'bytes'   => (int) $file['bytes'],
				'reason'  => (string) $file['reason'],
			);
		}
		update_option( self::ORPHAN_OPTION, $stored, false );
	}

	/**
	 * Delete orphaned Bricks CSS files.
	 *
	 * Safety: acts only on file names the last scan stored (allow-list), accepts
	 * only `post-{ID}.min.css`, resolves each path with realpath() and requires
	 * it to sit directly inside the verified Bricks CSS folder (no symlinks, no
	 * traversal), re-checks right before deleting that the post is still gone or
	 * has no Bricks data, and logs the deletion to the audit log.
	 *
	 * @param string[] $names File names (as listed in the finding).
	 * @return array|WP_Error { files: int, bytes: int, skipped: int, remaining: int }
	 */
	public function delete_css_files( array $names ) {
		$stored = get_option( self::ORPHAN_OPTION, null );
		if ( ! is_array( $stored ) || empty( $stored['files'] ) || ! is_array( $stored['files'] ) ) {
			return new WP_Error( 'blt_bricks_no_results', __( 'Run the performance scan first: there is no stored list of orphaned Bricks CSS files to act on.', 'blt-optimized' ) );
		}

		$requested = array();
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) || strlen( $name ) > 64 || ! preg_match( self::CSS_FILE_PATTERN, $name ) ) {
				return new WP_Error( 'blt_bricks_invalid_file', __( 'One of the requested files is not a Bricks per-post CSS file name.', 'blt-optimized' ) );
			}
			$requested[ $name ] = true;
		}
		$requested = array_keys( $requested );
		if ( empty( $requested ) ) {
			return new WP_Error( 'blt_bricks_nothing_selected', __( 'No files were selected.', 'blt-optimized' ) );
		}
		if ( count( $requested ) > self::FIX_MAX_ITEMS ) {
			return new WP_Error( 'blt_bricks_too_many', __( 'Too many files in one request. Delete them in smaller batches.', 'blt-optimized' ) );
		}

		$allowed = array();
		foreach ( $stored['files'] as $file ) {
			if ( is_array( $file ) && isset( $file['name'] ) ) {
				$allowed[ (string) $file['name'] ] = true;
			}
		}
		foreach ( $requested as $name ) {
			if ( ! isset( $allowed[ $name ] ) ) {
				return new WP_Error( 'blt_bricks_unknown_item', __( 'One or more files are not in the latest scan results, so nothing was deleted. Run the scan again.', 'blt-optimized' ) );
			}
		}

		$dir = self::css_dir();
		if ( false === $dir ) {
			return new WP_Error( 'blt_bricks_no_folder', __( 'The Bricks CSS folder could not be verified (it is missing, or it is or sits inside a symbolic link), so nothing was deleted.', 'blt-optimized' ) );
		}

		$files   = 0;
		$bytes   = 0;
		$skipped = 0;
		$handled = array();
		$deleted = array();

		foreach ( $requested as $name ) {
			$path = $dir . '/' . $name;

			if ( is_link( $path ) ) {
				++$skipped;
				continue;
			}
			if ( ! file_exists( $path ) ) {
				$handled[ $name ] = true; // Already gone.
				continue;
			}
			$real = realpath( $path );
			if ( false === $real ) {
				++$skipped;
				continue;
			}
			$real = wp_normalize_path( $real );
			if ( ! is_file( $real ) || $real !== $dir . '/' . $name ) {
				++$skipped;
				continue;
			}
			if ( ! preg_match( self::CSS_FILE_PATTERN, basename( $real ), $match ) ) {
				++$skipped;
				continue;
			}

			// Re-check right before deleting: the post may have come back.
			$status = $this->css_owner_status( array( (int) $match[1] ) );
			$state  = isset( $status[ (int) $match[1] ] ) ? $status[ (int) $match[1] ] : 'keep';
			if ( 'deleted' !== $state && 'no_bricks' !== $state ) {
				$handled[ $name ] = true;
				++$skipped;
				continue;
			}

			$size = (int) @filesize( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			wp_delete_file( $real );
			clearstatcache( true, $real );
			if ( file_exists( $real ) ) {
				++$skipped;
				continue;
			}

			++$files;
			$bytes           += $size;
			$handled[ $name ] = true;
			$deleted[]        = $name;
		}

		// Drop what was handled from the stored list.
		$remaining = array();
		foreach ( $stored['files'] as $file ) {
			if ( is_array( $file ) && isset( $file['name'] ) && ! isset( $handled[ (string) $file['name'] ] ) ) {
				$remaining[] = $file;
			}
		}
		$stored['files'] = $remaining;
		update_option( self::ORPHAN_OPTION, $stored, false );
		self::$css_scan = null;

		$log = $this->audit_log;
		if ( ! $log && class_exists( 'BLT_Optimized' ) && method_exists( 'BLT_Optimized', 'instance' ) ) {
			$log = BLT_Optimized::instance()->audit_log;
		}
		if ( $files > 0 && $log ) {
			$shown = array_slice( $deleted, 0, 20 );
			$log->log(
				'bricks_delete_css_files',
				sprintf(
					/* translators: 1: number of files, 2: list of file names. */
					_n( 'Deleted %1$d orphaned Bricks CSS file: %2$s', 'Deleted %1$d orphaned Bricks CSS files: %2$s', $files, 'blt-optimized' ),
					$files,
					implode( ', ', $shown ) . ( count( $deleted ) > 20 ? ' ' . sprintf(
						/* translators: %d: number of additional files. */
						__( '… and %d more', 'blt-optimized' ),
						count( $deleted ) - 20
					) : '' )
				),
				$bytes,
				$files
			);
		}

		return array(
			'files'     => $files,
			'bytes'     => $bytes,
			'skipped'   => $skipped,
			'remaining' => count( $remaining ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Content scan (shared by the class, loop, image and template checks) */
	/* ------------------------------------------------------------------ */

	/**
	 * Number of Bricks documents (pages and templates with content, header or
	 * footer data), counted in SQL.
	 *
	 * @return int
	 */
	private function bricks_document_count() {
		$scan = $this->scan_content();
		return (int) $scan['totals']['docs'];
	}

	/**
	 * Read the Bricks content once per request and collect what every check
	 * needs: which global classes and templates are referenced, which Image
	 * elements use the full size, which query loops are unlimited or nested,
	 * and which icon libraries are used.
	 *
	 * Postmeta is read in small batches, capped by rows, bytes and time; when a
	 * cap is hit the result is marked partial and the checks that would claim
	 * "unused" stay silent.
	 *
	 * @return array
	 */
	private function scan_content() {
		global $wpdb;

		if ( null !== self::$scan ) {
			return self::$scan;
		}

		$scan = array(
			'totals'         => array(
				'docs'  => 0,
				'bytes' => 0,
			),
			'docs_read'      => 0,
			'elements'       => 0,
			'bytes_read'     => 0,
			'partial'        => false,
			'partial_reason' => '',
			'skipped_large'  => 0,
			'used_ids'       => array(),
			'used_names'     => array(),
			'template_refs'  => array(),
			'popup_triggers' => array(),
			'icons'          => array(),
			'loops'          => array(),
			'loops_total'    => 0,
			'images'         => array(),
			'docs'           => array(),
		);

		$keys               = array( self::META_CONTENT, self::META_HEADER, self::META_FOOTER );
		$placeholders       = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$classes            = $this->global_classes();
		$scan['_class_ids'] = array_fill_keys( array_map( 'strval', array_keys( $classes['live'] ) ), true );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$totals = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(m.meta_value)),0) AS bytes FROM {$wpdb->postmeta} m
				 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				 WHERE m.meta_key IN ($placeholders) AND p.post_type NOT IN ('revision','auto-draft') AND m.meta_value <> '' AND m.meta_value <> 'a:0:{}'",
				$keys
			),
			ARRAY_A
		);
		// phpcs:enable
		if ( is_array( $totals ) ) {
			$scan['totals']['docs']  = (int) $totals['n'];
			$scan['totals']['bytes'] = (int) $totals['bytes'];
		}

		$start     = microtime( true );
		$budget    = (float) self::t( 'SCAN_TIME_BUDGET' );
		$max_rows  = (int) self::t( 'SCAN_MAX_ROWS' );
		$max_row   = (int) self::t( 'SCAN_MAX_ROW_BYTES' );
		$max_total = (int) self::t( 'SCAN_MAX_TOTAL_BYTES' );
		$batch     = max( 1, (int) self::t( 'SCAN_BATCH' ) );
		$last_id   = 0;

		while ( $scan['totals']['docs'] > 0 ) {
			if ( $scan['docs_read'] + $scan['skipped_large'] >= $max_rows ) {
				$scan['partial']        = true;
				$scan['partial_reason'] = 'rows';
				break;
			}
			if ( microtime( true ) - $start > $budget ) {
				$scan['partial']        = true;
				$scan['partial_reason'] = 'time';
				break;
			}
			if ( $scan['bytes_read'] > $max_total ) {
				$scan['partial']        = true;
				$scan['partial_reason'] = 'bytes';
				break;
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT m.meta_id, m.post_id, m.meta_key, LENGTH(m.meta_value) AS len, p.post_type, p.post_status, p.post_title
					 FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
					 WHERE m.meta_key IN ($placeholders) AND m.meta_id > %d AND p.post_type NOT IN ('revision','auto-draft') AND m.meta_value <> '' AND m.meta_value <> 'a:0:{}'
					 ORDER BY m.meta_id ASC LIMIT %d",
					array_merge( $keys, array( $last_id, $batch ) )
				),
				ARRAY_A
			);
			// phpcs:enable
			if ( empty( $rows ) ) {
				break;
			}

			$extra = $this->document_meta( wp_list_pluck( $rows, 'post_id' ) );

			foreach ( $rows as $row ) {
				$last_id = (int) $row['meta_id'];
				$post_id = (int) $row['post_id'];
				$length  = (int) $row['len'];

				if ( $length > $max_row ) {
					++$scan['skipped_large'];
					$scan['partial']        = true;
					$scan['partial_reason'] = 'large';
					continue;
				}

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$raw = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id = %d", $last_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				// phpcs:enable
				$elements = self::decode( $raw );
				unset( $raw );
				$scan['bytes_read'] += $length;
				if ( null === $elements ) {
					continue;
				}

				$area         = self::META_HEADER === $row['meta_key'] ? 'header' : ( self::META_FOOTER === $row['meta_key'] ? 'footer' : 'content' );
				$info         = array(
					'title'         => (string) $row['post_title'],
					'type'          => (string) $row['post_type'],
					'status'        => (string) $row['post_status'],
					'template_type' => isset( $extra['type'][ $post_id ] ) ? $extra['type'][ $post_id ] : '',
					'wordpress'     => isset( $extra['mode'][ $post_id ] ) && 'wordpress' === strtolower( $extra['mode'][ $post_id ] ),
				);
				$info['live'] = ( 'trash' !== $info['status'] ) && ( self::POST_TYPE === $info['type'] || ! $info['wordpress'] );

				++$scan['docs_read'];
				$scan['docs'][ $post_id ] = $info;
				$this->scan_document( $post_id, $area, $elements, $info, $scan );
				unset( $elements );
			}

			if ( count( $rows ) < $batch ) {
				break;
			}
		}

		// Classes can also be referenced from component definitions.
		$components = get_option( self::OPT_COMPONENTS, array() );
		if ( is_array( $components ) && $components ) {
			self::visit_value( $components, '', $scan, 0 );
		}

		// A template can be the maintenance page.
		$settings = $this->settings();
		if ( isset( $settings['maintenanceTemplate'] ) && is_numeric( $settings['maintenanceTemplate'] ) ) {
			$scan['template_refs'][ (int) $settings['maintenanceTemplate'] ] = true;
		}

		unset( $scan['_class_ids'] );
		self::$scan = $scan;
		return $scan;
	}

	/**
	 * Template type and editor mode for a batch of posts.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return array { type: id => string, mode: id => string }
	 */
	private function document_meta( array $post_ids ) {
		global $wpdb;

		$out = array(
			'type' => array(),
			'mode' => array(),
		);
		$ids = array_values( array_unique( array_map( 'intval', $post_ids ) ) );
		if ( ! $ids ) {
			return $out;
		}
		$id_in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($id_in) AND meta_key IN (%s,%s)",
				array_merge( $ids, array( self::META_TEMPLATE_TYPE, self::META_EDITOR_MODE ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		foreach ( (array) $rows as $row ) {
			$value = is_string( $row['meta_value'] ) ? $row['meta_value'] : '';
			if ( self::META_TEMPLATE_TYPE === $row['meta_key'] ) {
				$out['type'][ (int) $row['post_id'] ] = $value;
			} else {
				$out['mode'][ (int) $row['post_id'] ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Collect from one decoded element list.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $area     content|header|footer.
	 * @param array  $elements Decoded elements.
	 * @param array  $info     Document info.
	 * @param array  $scan     Scan accumulator.
	 * @return void
	 */
	private function scan_document( $post_id, $area, array $elements, array $info, array &$scan ) {
		$by_id = array();
		foreach ( $elements as $element ) {
			if ( is_array( $element ) && isset( $element['id'] ) && is_scalar( $element['id'] ) ) {
				$by_id[ (string) $element['id'] ] = $element;
			}
		}

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			++$scan['elements'];

			$id       = isset( $element['id'] ) && is_scalar( $element['id'] ) ? (string) $element['id'] : '';
			$name     = isset( $element['name'] ) && is_string( $element['name'] ) ? $element['name'] : '';
			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

			// Every string anywhere in the element: global class ids, class names, template references, icon libraries.
			$body = $element;
			unset( $body['id'], $body['parent'], $body['children'], $body['name'] );
			$scan['_icons_el'] = array();
			self::visit_value( $body, '', $scan, 0 );
			foreach ( array_unique( $scan['_icons_el'] ) as $library ) {
				if ( ! isset( $scan['icons'][ $library ] ) ) {
					$scan['icons'][ $library ] = array(
						'count' => 0,
						'docs'  => array(),
					);
				}
				++$scan['icons'][ $library ]['count'];
				$scan['icons'][ $library ]['docs'][ $post_id ] = ( isset( $scan['icons'][ $library ]['docs'][ $post_id ] ) ? $scan['icons'][ $library ]['docs'][ $post_id ] : 0 ) + 1;
			}
			unset( $scan['_icons_el'] );

			if ( empty( $info['live'] ) ) {
				continue;
			}

			// Popups opened by an interaction (only content that is rendered counts).
			if ( ! empty( $settings['_interactions'] ) && is_array( $settings['_interactions'] ) ) {
				foreach ( $settings['_interactions'] as $interaction ) {
					if ( is_array( $interaction ) && isset( $interaction['target'], $interaction['templateId'] ) && 'popup' === $interaction['target'] && is_numeric( $interaction['templateId'] ) ) {
						$template                            = (int) $interaction['templateId'];
						$scan['popup_triggers'][ $template ] = ( isset( $scan['popup_triggers'][ $template ] ) ? $scan['popup_triggers'][ $template ] : 0 ) + 1;
					}
				}
			}

			// Query loops.
			$loop = self::loop_info( $name, $settings );
			if ( null !== $loop ) {
				++$scan['loops_total'];

				$nested = null;
				if ( in_array( $loop['object_type'], array( 'post', 'term', 'user' ), true ) ) {
					$parent = isset( $element['parent'] ) && is_scalar( $element['parent'] ) ? (string) $element['parent'] : '';
					$hops   = 0;
					while ( '' !== $parent && '0' !== $parent && isset( $by_id[ $parent ] ) && $hops++ < 50 ) {
						$ancestor      = $by_id[ $parent ];
						$ancestor_name = isset( $ancestor['name'] ) && is_string( $ancestor['name'] ) ? $ancestor['name'] : '';
						$ancestor_set  = isset( $ancestor['settings'] ) && is_array( $ancestor['settings'] ) ? $ancestor['settings'] : array();
						$outer         = self::loop_info( $ancestor_name, $ancestor_set );
						if ( null !== $outer ) {
							$nested = array(
								'id'          => $parent,
								'name'        => $ancestor_name,
								'object_type' => $outer['object_type'],
								'per_page'    => $outer['per_page'],
								'unlimited'   => $outer['unlimited'],
								'post_types'  => $outer['post_types'],
							);
							break;
						}
						$parent = isset( $ancestor['parent'] ) && is_scalar( $ancestor['parent'] ) ? (string) $ancestor['parent'] : '';
					}
				}

				if ( ( $loop['unlimited'] || ( null !== $loop['per_page'] && $loop['per_page'] > (int) self::t( 'LOOP_LARGE_LIMIT' ) ) || null !== $nested ) && count( $scan['loops'] ) < 400 ) {
					$scan['loops'][] = array(
						'doc'    => $post_id,
						'area'   => $area,
						'id'     => $id,
						'name'   => $name,
						'label'  => isset( $element['label'] ) && is_string( $element['label'] ) ? $element['label'] : '',
						'loop'   => $loop,
						'nested' => $nested,
					);
				}
			}

			// Image elements set to the full size with a small explicit width.
			if ( 'image' === $name && isset( $settings['image'] ) && is_array( $settings['image'] ) && count( $scan['images'] ) < 300 ) {
				$image = $settings['image'];
				$size  = isset( $image['size'] ) && is_string( $image['size'] ) ? strtolower( $image['size'] ) : '';
				$aid   = isset( $image['id'] ) && is_numeric( $image['id'] ) ? (int) $image['id'] : 0;
				if ( 'full' === $size && $aid > 0 ) {
					$widths = array_filter(
						array(
							self::css_px( isset( $settings['_width'] ) ? $settings['_width'] : null ),
							self::css_px( isset( $settings['_widthMax'] ) ? $settings['_widthMax'] : null ),
						)
					);
					if ( $widths ) {
						$scan['images'][] = array(
							'doc'     => $post_id,
							'area'    => $area,
							'id'      => $id,
							'attach'  => $aid,
							'display' => (int) min( $widths ),
						);
					}
				}
			}
		}
	}

	/**
	 * Visit every value in a structure: global class ids, class names in
	 * `class="…"` attributes and `_cssClasses`, template references, icon
	 * libraries.
	 *
	 * @param mixed  $value Value.
	 * @param string $key   Nearest array key above it.
	 * @param array  $scan  Scan accumulator.
	 * @param int    $depth Depth.
	 * @return void
	 */
	private static function visit_value( $value, $key, array &$scan, $depth ) {
		if ( is_array( $value ) ) {
			if ( $depth >= 8 ) {
				return;
			}
			if ( isset( $value['library'] ) && is_string( $value['library'] ) && ( isset( $value['icon'] ) || isset( $value['svg'] ) ) && isset( $scan['_icons_el'] ) ) {
				$scan['_icons_el'][] = strtolower( $value['library'] );
			}
			foreach ( $value as $k => $v ) {
				self::visit_value( $v, is_string( $k ) ? $k : $key, $scan, $depth + 1 );
			}
			return;
		}

		if ( is_int( $value ) ) {
			if ( $value > 0 && self::is_template_ref_key( $key ) ) {
				$scan['template_refs'][ $value ] = true;
			}
			return;
		}

		if ( ! is_string( $value ) || '' === $value ) {
			return;
		}

		$length = strlen( $value );
		if ( $length <= 64 && isset( $scan['_class_ids'][ $value ] ) ) {
			$scan['used_ids'][ $value ] = true;
		}

		if ( '_cssClasses' === $key ) {
			foreach ( preg_split( '/\s+/', trim( $value ), -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
				if ( strlen( $token ) <= 100 ) {
					$scan['used_names'][ $token ] = true;
				}
			}
		} elseif ( $length > 8 && $length < 50000 && false !== stripos( $value, 'class' ) && preg_match_all( '/class\s*=\s*(["\'])(.*?)\1/is', $value, $matches ) ) {
			foreach ( $matches[2] as $attribute ) {
				foreach ( preg_split( '/\s+/', trim( $attribute ), -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
					if ( strlen( $token ) <= 100 ) {
						$scan['used_names'][ $token ] = true;
					}
				}
			}
		}

		if ( $length <= 12 && ctype_digit( $value ) && self::is_template_ref_key( $key ) ) {
			$scan['template_refs'][ (int) $value ] = true;
		}
	}

	/**
	 * Whether a settings key holds a template id that embeds the template: the
	 * Template element's `template`, a map's `infoBoxTemplateId`, and similar.
	 * An interaction's `templateId` is excluded on purpose: it only opens a
	 * popup that has to be rendered by its own conditions first.
	 *
	 * @param string $key Settings key.
	 * @return bool
	 */
	private static function is_template_ref_key( $key ) {
		return is_string( $key ) && 'templateId' !== $key && false !== stripos( $key, 'template' );
	}

	/**
	 * Loop details of an element, or null when it does not run a query.
	 *
	 * The Posts element always runs its query; Carousel does when its type is
	 * "posts"; layout and classic accordion/slider elements run one when
	 * `hasLoop` is on. Only an explicit "all" counts as unlimited: Bricks falls
	 * back to Settings → Reading when Posts Per Page is empty.
	 *
	 * @param string $name     Element name.
	 * @param array  $settings Element settings.
	 * @return array|null { object_type, per_page: int|null, unlimited: bool, post_types: string[] }
	 */
	private static function loop_info( $name, array $settings ) {
		if ( empty( $settings['query'] ) || ! is_array( $settings['query'] ) ) {
			return null;
		}
		$has_loop = ! empty( $settings['hasLoop'] )
			|| 'posts' === $name
			|| ( 'carousel' === $name && isset( $settings['type'] ) && 'posts' === $settings['type'] );
		if ( ! $has_loop ) {
			return null;
		}

		$query = $settings['query'];
		$type  = ( isset( $query['objectType'] ) && is_string( $query['objectType'] ) && '' !== $query['objectType'] ) ? $query['objectType'] : 'post';
		$info  = array(
			'object_type' => $type,
			'per_page'    => null,
			'unlimited'   => false,
			'post_types'  => array(),
		);

		if ( 'post' === $type ) {
			if ( isset( $query['posts_per_page'] ) && is_numeric( $query['posts_per_page'] ) ) {
				$info['per_page']  = (int) $query['posts_per_page'];
				$info['unlimited'] = $info['per_page'] < 0;
			}
			$types = isset( $query['post_type'] ) ? (array) $query['post_type'] : array( 'post' );
			foreach ( $types as $post_type ) {
				if ( is_string( $post_type ) && '' !== $post_type ) {
					$info['post_types'][] = sanitize_key( $post_type );
				}
			}
			if ( ! $info['post_types'] ) {
				$info['post_types'] = array( 'post' );
			}
		} elseif ( 'term' === $type ) {
			if ( isset( $query['number'] ) && is_numeric( $query['number'] ) ) {
				$info['per_page']  = (int) $query['number'];
				$info['unlimited'] = 0 === $info['per_page'];
			}
		} elseif ( 'user' === $type ) {
			if ( isset( $query['number'] ) && is_numeric( $query['number'] ) ) {
				$info['per_page']  = (int) $query['number'];
				$info['unlimited'] = $info['per_page'] < 0;
			}
		}

		return $info;
	}

	/**
	 * A CSS width in pixels, or null (percentages, viewport units, calc() and
	 * variables are not "small").
	 *
	 * @param mixed $value Control value (`300`, `"300px"`, `"50%"`).
	 * @return int|null
	 */
	private static function css_px( $value ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value > 0 ? (int) round( $value ) : null;
		}
		if ( is_string( $value ) && preg_match( '/^\s*(\d+(?:\.\d+)?)\s*(?:px)?\s*$/i', $value, $match ) ) {
			$px = (int) round( (float) $match[1] );
			return $px > 0 ? $px : null;
		}
		return null;
	}

	/**
	 * Published posts a loop over these post types can return.
	 *
	 * @param string[] $post_types Post types.
	 * @return int|null Null when the types are unknown.
	 */
	private function matching_posts( array $post_types ) {
		static $counts = array();
		$total         = 0;
		$known         = false;
		foreach ( $post_types as $post_type ) {
			if ( 'any' === $post_type ) {
				$post_type = 'post';
			}
			if ( ! post_type_exists( $post_type ) ) {
				continue;
			}
			if ( ! isset( $counts[ $post_type ] ) ) {
				$count                = wp_count_posts( $post_type );
				$counts[ $post_type ] = ( 'attachment' === $post_type )
					? ( isset( $count->inherit ) ? (int) $count->inherit : 0 )
					: ( isset( $count->publish ) ? (int) $count->publish : 0 );
			}
			$total += $counts[ $post_type ];
			$known  = true;
		}
		return $known ? $total : null;
	}

	/* ------------------------------------------------------------------ */
	/* Check: content (loops, images, unused classes, unused templates)    */
	/* ------------------------------------------------------------------ */

	/**
	 * Everything that needs the Bricks content scan.
	 *
	 * @param array[] $pages Unused.
	 * @return array[]
	 */
	private function check_content( array $pages ) {
		unset( $pages );
		$scan = $this->scan_content();
		if ( $scan['totals']['docs'] < 1 ) {
			return array();
		}

		$findings = array_merge(
			$this->findings_loops( $scan ),
			$this->findings_images( $scan )
		);

		if ( $scan['partial'] ) {
			$findings[] = $this->finding_partial( $scan );
			return $findings;
		}

		return array_merge(
			$findings,
			$this->findings_unused_classes( $scan ),
			$this->findings_unreferenced_templates( $scan )
		);
	}

	/**
	 * The "scan was partial" notice.
	 *
	 * @param array $scan Content scan.
	 * @return array
	 */
	private function finding_partial( array $scan ) {
		$reasons = array(
			'time'  => __( 'the time limit for the check was reached', 'blt-optimized' ),
			'rows'  => __( 'the row limit for the check was reached', 'blt-optimized' ),
			'bytes' => __( 'the data limit for the check was reached', 'blt-optimized' ),
			'large' => __( 'some pages are too large to read safely', 'blt-optimized' ),
		);
		$reason  = isset( $reasons[ $scan['partial_reason'] ] ) ? $reasons[ $scan['partial_reason'] ] : '';

		return $this->finding(
			array(
				'id'       => 'bricks.scan_partial',
				'key'      => 'bricks.scan_partial',
				'category' => 'database',
				'severity' => 'notice',
				'title'    => sprintf(
					/* translators: 1: documents read, 2: documents total. */
					__( 'Bricks content was checked in part (%1$s of %2$s pages and templates)', 'blt-optimized' ),
					number_format_i18n( (int) $scan['docs_read'] ),
					number_format_i18n( (int) $scan['totals']['docs'] )
				),
				'summary'  => sprintf(
					/* translators: %s: why the scan stopped. */
					__( 'The scan reads Bricks data in capped, time-boxed batches so it cannot slow the site, and it stopped early because %s. Loops and images in the part that was read are reported; the checks that would say a class or template is unused are skipped, because they need every page.', 'blt-optimized' ),
					'' !== $reason ? $reason : __( 'a limit was reached', 'blt-optimized' )
				),
				'impact'   => array(
					'type'  => 'count',
					'value' => (int) $scan['totals']['docs'] - (int) $scan['docs_read'],
					'label' => '',
				),
				'details'  => array(
					'columns' => array( __( 'Item', 'blt-optimized' ), __( 'Value', 'blt-optimized' ) ),
					'rows'    => array(
						array( __( 'Pages and templates with Bricks data', 'blt-optimized' ), (int) $scan['totals']['docs'] ),
						array( __( 'Read', 'blt-optimized' ), (int) $scan['docs_read'] ),
						array( __( 'Too large to read', 'blt-optimized' ), (int) $scan['skipped_large'] ),
						array( __( 'Bricks data in postmeta', 'blt-optimized' ), size_format( (int) $scan['totals']['bytes'], 1 ) ),
					),
				),
				'fix'      => $this->fix_manual(
					__( 'Nothing to fix', 'blt-optimized' ),
					__( 'This is not a problem on the site. To check more of it, raise the limits with the blt_optimized_bricks_thresholds filter (SCAN_TIME_BUDGET, SCAN_MAX_ROWS, SCAN_MAX_ROW_BYTES).', 'blt-optimized' )
				),
			)
		);
	}

	/**
	 * Query loops that load unlimited posts, a very large number of posts, or
	 * nest. One finding per page or template (capped), each naming the element.
	 *
	 * @param array $scan Content scan.
	 * @return array[]
	 */
	private function findings_loops( array $scan ) {
		if ( empty( $scan['loops'] ) ) {
			return array();
		}

		$by_doc = array();
		foreach ( $scan['loops'] as $loop ) {
			if ( $this->loop_is_reportable( $loop ) ) {
				$by_doc[ $loop['doc'] ][] = $loop;
			}
		}
		if ( ! $by_doc ) {
			return array();
		}

		$findings = array();
		$overflow = array();
		foreach ( $by_doc as $post_id => $loops ) {
			if ( count( $findings ) >= self::MAX_POST_FINDINGS ) {
				$overflow[ $post_id ] = $loops;
				continue;
			}
			$finding = $this->finding_loops_for_doc( (int) $post_id, $loops, $scan );
			if ( $finding ) {
				$findings[] = $finding;
			}
		}

		if ( $overflow ) {
			$rows  = array();
			$items = 0;
			foreach ( $overflow as $post_id => $loops ) {
				foreach ( $loops as $loop ) {
					$rows[] = $this->loop_row( (int) $post_id, $loop, $scan );
				}
				$items += count( $loops );
			}
			$findings[] = $this->finding(
				array(
					'id'       => 'bricks.query_loop',
					'key'      => 'bricks.query_loop:more',
					'category' => 'content',
					'severity' => 'notice',
					'title'    => sprintf(
						/* translators: 1: loops, 2: pages and templates. */
						_n( '%1$s more Bricks query loop with no limit or nesting, in %2$s other pages and templates', '%1$s more Bricks query loops with no limit or nesting, in %2$s other pages and templates', $items, 'blt-optimized' ),
						number_format_i18n( $items ),
						number_format_i18n( count( $overflow ) )
					),
					'summary'  => __( 'These loops load every post (or a very large number) or run a query inside another loop. The ten heaviest pages and templates are listed as findings of their own; the rest are listed here. Give each loop a Posts Per Page limit, and bound the outer loop of a nested pair.', 'blt-optimized' ),
					'impact'   => array(
						'type'  => 'count',
						'value' => $items,
						'label' => '',
					),
					'details'  => array(
						'columns' => $this->loop_columns(),
						'rows'    => $rows,
					),
				)
			);
		}

		return $findings;
	}

	/**
	 * Whether a loop is worth reporting. An unlimited loop always is (it grows
	 * with the content); a large explicit limit only when the posts it can
	 * return reach that size; a nested loop unless its outer loop is known to
	 * be small.
	 *
	 * @param array $loop Loop record.
	 * @return bool
	 */
	private function loop_is_reportable( array $loop ) {
		$detail = $this->loop_detail( $loop );
		if ( 'unlimited' === $detail['kind'] ) {
			return true;
		}
		if ( 'large' === $detail['kind'] ) {
			return null === $detail['items'] || $detail['items'] >= (int) self::t( 'LOOP_LARGE_LIMIT' );
		}
		return null === $detail['queries'] || $detail['queries'] >= (int) self::t( 'NESTED_MIN_OUTER' );
	}

	/**
	 * Evidence columns for loop rows.
	 *
	 * @return string[]
	 */
	private function loop_columns() {
		return array( __( 'Page / template', 'blt-optimized' ), __( 'Element', 'blt-optimized' ), __( 'Problem', 'blt-optimized' ), __( 'Loads', 'blt-optimized' ), __( 'Edit with Bricks', 'blt-optimized' ) );
	}

	/**
	 * One evidence row for a loop.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $loop    Loop record.
	 * @param array $scan    Content scan.
	 * @return array
	 */
	private function loop_row( $post_id, array $loop, array $scan ) {
		$info   = isset( $scan['docs'][ $post_id ] ) ? $scan['docs'][ $post_id ] : array();
		$detail = $this->loop_detail( $loop );

		return array(
			$this->doc_label( $info, $post_id ),
			sprintf( '%s (%s)', $loop['id'], $loop['name'] ),
			$detail['problem'],
			$detail['loads'],
			self::edit_url( $post_id ),
		);
	}

	/**
	 * Describe a loop: what is wrong and how much it loads.
	 *
	 * @param array $loop Loop record.
	 * @return array { problem, loads, items: int|null, queries: int|null, kind }
	 */
	private function loop_detail( array $loop ) {
		$inner = $loop['loop'];
		$items = $this->loop_items( $inner );

		if ( ! empty( $loop['nested'] ) ) {
			$outer       = $loop['nested'];
			$outer_items = $this->loop_items( $outer );
			return array(
				'problem' => sprintf(
					/* translators: 1: outer element id, 2: inner query type. */
					__( 'Nested inside the loop %1$s (a %2$s query runs once per outer item)', 'blt-optimized' ),
					$outer['id'],
					$inner['object_type']
				),
				'loads'   => null !== $outer_items
					/* translators: %s: number of queries. */
					? sprintf( _n( 'about %s extra query per view', 'about %s extra queries per view', $outer_items, 'blt-optimized' ), number_format_i18n( $outer_items ) )
					: __( 'one query per outer item', 'blt-optimized' ),
				'items'   => $items,
				'queries' => $outer_items,
				'kind'    => 'nested',
			);
		}

		if ( $inner['unlimited'] ) {
			return array(
				'problem' => __( 'Posts Per Page is -1 (all posts)', 'blt-optimized' ),
				'loads'   => null !== $items
					/* translators: %s: number of items. */
					? sprintf( __( 'up to %s items', 'blt-optimized' ), number_format_i18n( $items ) )
					: __( 'every matching item', 'blt-optimized' ),
				'items'   => $items,
				'queries' => null,
				'kind'    => 'unlimited',
			);
		}

		return array(
			/* translators: %s: the configured limit. */
			'problem' => sprintf( __( 'Very large limit (%s per page)', 'blt-optimized' ), number_format_i18n( (int) $inner['per_page'] ) ),
			'loads'   => sprintf(
				/* translators: %s: number of items. */
				__( 'up to %s items', 'blt-optimized' ),
				number_format_i18n( null !== $items ? $items : (int) $inner['per_page'] )
			),
			'items'   => null !== $items ? $items : (int) $inner['per_page'],
			'queries' => null,
			'kind'    => 'large',
		);
	}

	/**
	 * How many items a loop returns per page view, as far as it can be known.
	 *
	 * @param array $loop Loop info (object_type, per_page, unlimited, post_types).
	 * @return int|null
	 */
	private function loop_items( array $loop ) {
		$type = isset( $loop['object_type'] ) ? $loop['object_type'] : 'post';
		if ( 'post' === $type ) {
			$matching = $this->matching_posts( isset( $loop['post_types'] ) ? (array) $loop['post_types'] : array( 'post' ) );
			if ( ! empty( $loop['unlimited'] ) ) {
				return $matching;
			}
			if ( null !== $loop['per_page'] && $loop['per_page'] > 0 ) {
				return null !== $matching ? min( $matching, (int) $loop['per_page'] ) : (int) $loop['per_page'];
			}
			return null;
		}
		if ( null !== $loop['per_page'] && $loop['per_page'] > 0 ) {
			return (int) $loop['per_page'];
		}
		return null;
	}

	/**
	 * The finding for one page or template's loops.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $loops   Loop records.
	 * @param array $scan    Content scan.
	 * @return array|null
	 */
	private function finding_loops_for_doc( $post_id, array $loops, array $scan ) {
		$info     = isset( $scan['docs'][ $post_id ] ) ? $scan['docs'][ $post_id ] : array();
		$label    = $this->doc_label( $info, $post_id );
		$rows     = array();
		$worst    = 0;
		$warn     = false;
		$kinds    = array();
		$sitewide = isset( $info['template_type'] ) && in_array( $info['template_type'], array( 'header', 'footer' ), true );

		foreach ( $loops as $loop ) {
			$detail                   = $this->loop_detail( $loop );
			$rows[]                   = array(
				sprintf( '%s (%s)', $loop['id'], '' !== $loop['label'] ? $loop['label'] : $loop['name'] ),
				ucfirst( $loop['area'] ),
				$detail['problem'],
				$detail['loads'],
			);
			$kinds[ $detail['kind'] ] = true;

			if ( 'nested' === $detail['kind'] ) {
				$outer = (int) $detail['queries'];
				$worst = max( $worst, $outer );
				if ( $outer >= (int) self::t( 'NESTED_WARN_OUTER' ) ) {
					$warn = true;
				}
			} else {
				$items = (int) $detail['items'];
				$worst = max( $worst, $items );
				if ( $items >= (int) self::t( 'LOOP_WARN_ITEMS' ) ) {
					$warn = true;
				}
			}
		}

		$first = $loops[0];
		$one   = count( $loops ) === 1;
		if ( $one ) {
			$detail = $this->loop_detail( $first );
			if ( 'nested' === $detail['kind'] ) {
				/* translators: 1: page/template label, 2: element id. */
				$title = sprintf( __( '%1$s runs a query loop inside another loop (element %2$s)', 'blt-optimized' ), $label, $first['id'] );
			} elseif ( 'unlimited' === $detail['kind'] ) {
				/* translators: 1: page/template label, 2: element id. */
				$title = sprintf( __( '%1$s has a Bricks query loop that loads every post (element %2$s)', 'blt-optimized' ), $label, $first['id'] );
			} else {
				/* translators: 1: page/template label, 2: element id. */
				$title = sprintf( __( '%1$s has a Bricks query loop with a very large limit (element %2$s)', 'blt-optimized' ), $label, $first['id'] );
			}
		} else {
			$title = sprintf(
				/* translators: 1: page/template label, 2: number of loops. */
				__( '%1$s has %2$s heavy Bricks query loops (no limit, very large limit or nested)', 'blt-optimized' ),
				$label,
				number_format_i18n( count( $loops ) )
			);
		}

		$summary = __( 'A Bricks query loop renders its inner design once per result, so a loop with no Posts Per Page limit builds a card for every matching post on every view of the page, adding database work, HTML and images; a loop inside another loop runs one more query for each outer item. Bricks recommends bounding result counts and measuring the repeated query cost.', 'blt-optimized' );
		if ( $sitewide ) {
			$summary .= ' ' . __( 'This is in a header or footer template, which renders on every page that uses it.', 'blt-optimized' );
		}
		$summary .= ' ' . __( 'Set Posts Per Page to the number you actually show and add pagination or a “Load more”, enable “No found rows” and turn off the meta and term cache options when the loop needs neither.', 'blt-optimized' );

		return $this->finding(
			array(
				'id'       => 'bricks.query_loop',
				'key'      => 'bricks.query_loop:' . $post_id,
				'category' => 'content',
				'severity' => $warn ? 'warning' : 'notice',
				'title'    => $title,
				'summary'  => $summary,
				'impact'   => array(
					'type'  => 'count',
					'value' => $worst,
					'label' => $worst > 0
						/* translators: %s: number of items or queries. */
						? sprintf( __( 'up to %s', 'blt-optimized' ), number_format_i18n( $worst ) )
						: '',
				),
				'where'    => array( self::edit_url( $post_id ) ),
				'details'  => array(
					'columns' => array( __( 'Element', 'blt-optimized' ), __( 'Area', 'blt-optimized' ), __( 'Problem', 'blt-optimized' ), __( 'Loads', 'blt-optimized' ) ),
					'rows'    => $rows,
				),
				'fix'      => $this->fix_edit( $post_id ),
			)
		);
	}

	/**
	 * Image elements set to the full size and shown small.
	 *
	 * @param array $scan Content scan.
	 * @return array[]
	 */
	private function findings_images( array $scan ) {
		if ( empty( $scan['images'] ) ) {
			return array();
		}

		$ratio   = (int) self::t( 'IMAGE_RATIO' );
		$min     = (int) self::t( 'IMAGE_MIN_BYTES' );
		$small   = (int) self::t( 'IMAGE_SMALL_MAX_PX' );
		$by_doc  = array();
		$checked = 0;

		foreach ( $scan['images'] as $image ) {
			if ( $checked >= (int) self::t( 'IMAGE_MAX_CHECK' ) ) {
				break;
			}
			if ( $image['display'] > $small ) {
				continue;
			}
			++$checked;

			$meta = wp_get_attachment_metadata( $image['attach'] );
			$file = get_attached_file( $image['attach'] );
			if ( ! is_array( $meta ) || empty( $meta['width'] ) || ! is_string( $file ) || ! is_readable( $file ) ) {
				continue;
			}
			$bytes = (int) @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$width = (int) $meta['width'];
			if ( $width < $ratio * $image['display'] || $bytes < $min ) {
				continue;
			}

			$by_doc[ $image['doc'] ][] = $image + array(
				'bytes'  => $bytes,
				'width'  => $width,
				'height' => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
				'file'   => basename( $file ),
			);
		}

		if ( ! $by_doc ) {
			return array();
		}

		$findings = array();
		$overflow = array();
		foreach ( $by_doc as $post_id => $images ) {
			if ( count( $findings ) >= self::MAX_POST_FINDINGS ) {
				$overflow[ $post_id ] = $images;
				continue;
			}
			$info  = isset( $scan['docs'][ $post_id ] ) ? $scan['docs'][ $post_id ] : array();
			$label = $this->doc_label( $info, (int) $post_id );
			$total = array_sum( wp_list_pluck( $images, 'bytes' ) );

			$rows = array();
			foreach ( $images as $image ) {
				$rows[] = array(
					$image['id'],
					$image['file'],
					sprintf( '%d × %d', $image['width'], $image['height'] ),
					size_format( $image['bytes'], 1 ),
					sprintf( '%d px', $image['display'] ),
				);
			}

			$findings[] = $this->finding(
				array(
					'id'       => 'bricks.full_size_image',
					'key'      => 'bricks.full_size_image:' . $post_id,
					'category' => 'content',
					'severity' => $total >= 1 * MB_IN_BYTES ? 'warning' : 'notice',
					'title'    => 1 === count( $images )
						/* translators: 1: page/template label, 2: element id, 3: file size. */
						? sprintf( __( '%1$s shows a full-size %3$s image in a small Bricks Image element (element %2$s)', 'blt-optimized' ), $label, $images[0]['id'], size_format( $images[0]['bytes'], 1 ) )
						/* translators: 1: page/template label, 2: number of images. */
						: sprintf( __( '%1$s shows %2$s full-size images in small Bricks Image elements', 'blt-optimized' ), $label, number_format_i18n( count( $images ) ) ),
					'summary'  => __( 'These Image elements use the “full” image size but are given a width of 800 px or less. WordPress builds the srcset from the file’s sizes and the browser has no hint that the image is displayed small, so it can download the original. Choosing a smaller size in the element’s image settings (for example “medium_large” or “large”) gives the browser a file that fits. Bricks itself advises regenerating thumbnails so its image sizes exist.', 'blt-optimized' ),
					'impact'   => array(
						'type'  => 'bytes',
						'value' => $total,
						'label' => sprintf(
							/* translators: %s: total size of the full-size files. */
							__( 'up to %s', 'blt-optimized' ),
							size_format( $total, 1 )
						),
					),
					'where'    => array( self::edit_url( (int) $post_id ) ),
					'details'  => array(
						'columns' => array( __( 'Element', 'blt-optimized' ), __( 'File', 'blt-optimized' ), __( 'Pixels', 'blt-optimized' ), __( 'File size', 'blt-optimized' ), __( 'Width in the element', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => $this->fix_edit( (int) $post_id ),
				)
			);
		}

		if ( $overflow ) {
			$rows  = array();
			$total = 0;
			foreach ( $overflow as $post_id => $images ) {
				foreach ( $images as $image ) {
					$info   = isset( $scan['docs'][ $post_id ] ) ? $scan['docs'][ $post_id ] : array();
					$rows[] = array( $this->doc_label( $info, (int) $post_id ), $image['id'], $image['file'], size_format( $image['bytes'], 1 ), self::edit_url( (int) $post_id ) );
					$total += $image['bytes'];
				}
			}
			$findings[] = $this->finding(
				array(
					'id'       => 'bricks.full_size_image',
					'key'      => 'bricks.full_size_image:more',
					'category' => 'content',
					'severity' => 'notice',
					'title'    => sprintf(
						/* translators: %s: number of pages and templates. */
						__( 'Full-size images in small Bricks Image elements on %s more pages and templates', 'blt-optimized' ),
						number_format_i18n( count( $overflow ) )
					),
					'summary'  => __( 'Same problem as above on further pages and templates: Image elements set to the full size but displayed at 800 px or less. Pick a smaller image size in each element.', 'blt-optimized' ),
					'impact'   => array(
						'type'  => 'bytes',
						'value' => $total,
						'label' => '',
					),
					'details'  => array(
						'columns' => array( __( 'Page / template', 'blt-optimized' ), __( 'Element', 'blt-optimized' ), __( 'File', 'blt-optimized' ), __( 'File size', 'blt-optimized' ), __( 'Edit with Bricks', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
				)
			);
		}

		return $findings;
	}

	/**
	 * Global classes with no reference in any scanned Bricks content.
	 *
	 * Bricks' own audit calls an unused class informational: a class may be
	 * staged for later, or used by name in custom code, so the finding is a
	 * notice with the evidence and exact Class Manager steps. It never edits
	 * Bricks data.
	 *
	 * @param array $scan Complete content scan.
	 * @return array[]
	 */
	private function findings_unused_classes( array $scan ) {
		$classes = $this->global_classes();
		if ( ! $classes['live'] || $scan['docs_read'] < 1 ) {
			return array();
		}

		$unused = array();
		foreach ( $classes['live'] as $id => $class ) {
			$id = (string) $id;
			if ( isset( $scan['used_ids'][ $id ] ) || isset( $scan['used_names'][ $class['name'] ] ) || isset( $scan['used_names'][ ltrim( $class['name'], '.' ) ] ) ) {
				continue;
			}
			$unused[ $id ] = $class;
		}

		if ( count( $unused ) < (int) self::t( 'UNUSED_CLASSES_MIN' ) ) {
			return array();
		}

		uasort(
			$unused,
			static function ( $a, $b ) {
				$by_size = $b['bytes'] <=> $a['bytes'];
				return 0 !== $by_size ? $by_size : strcmp( $a['name'], $b['name'] );
			}
		);

		$bytes = array_sum( wp_list_pluck( $unused, 'bytes' ) );
		$rows  = array();
		foreach ( array_slice( $unused, 0, self::MAX_ROWS, true ) as $id => $class ) {
			$rows[] = array( $class['name'], (string) $id, $class['category'], size_format( $class['bytes'], 1 ) );
		}

		$count   = count( $unused );
		$trashed = count( $classes['trashed'] );
		$live    = count( $classes['live'] );
		$summary = sprintf(
			/* translators: 1: unused classes, 2: classes in total, 3: Bricks documents scanned, 4: size of the unused classes. */
			__( '%1$s of %2$s global classes are not assigned to any element in the %3$s Bricks pages, templates and components that were scanned (%4$s of stored class data). Unused classes do not add CSS to the front end — Bricks only outputs CSS for classes that are used — but they enlarge the bricks_global_classes option that Bricks loads, and they clutter the Class Manager. Bricks’ own audit treats them as informational: a class can be kept on purpose, or used by name in custom code, a Code element or an external stylesheet, which this scan cannot see.', 'blt-optimized' ),
			number_format_i18n( $count ),
			number_format_i18n( $live ),
			number_format_i18n( (int) $scan['docs_read'] ),
			size_format( $bytes, 1 )
		);
		if ( $trashed > 0 ) {
			$summary .= ' ' . sprintf(
				/* translators: %d: number of classes in the Class Manager trash. */
				_n( '%d more class is in the Class Manager trash and is still stored until it is permanently deleted.', '%d more classes are in the Class Manager trash and are still stored until they are permanently deleted.', $trashed, 'blt-optimized' ),
				$trashed
			);
		}

		return array(
			$this->finding(
				array(
					'id'       => 'bricks.unused_classes',
					'key'      => 'bricks.unused_classes',
					'category' => 'database',
					'severity' => 'notice',
					'title'    => sprintf(
						/* translators: 1: number of unused classes, 2: option name. */
						_n( '%1$s Bricks global class has no references (stored in %2$s)', '%1$s Bricks global classes have no references (stored in %2$s)', $count, 'blt-optimized' ),
						number_format_i18n( $count ),
						self::OPT_CLASSES
					),
					'summary'  => $summary,
					'impact'   => array(
						'type'  => 'bytes',
						'value' => $bytes,
						'label' => '',
					),
					'where'    => array( self::OPT_CLASSES ),
					'details'  => array(
						'columns' => array( __( 'Class name', 'blt-optimized' ), __( 'Class ID', 'blt-optimized' ), __( 'Category', 'blt-optimized' ), __( 'Stored size', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => $this->fix_manual(
						__( 'Review in the Class Manager', 'blt-optimized' ),
						__( "This plugin does not edit Bricks data. To clean up:\n1. Back up the site, and in the Class Manager click “Export all” so the classes can be restored.\n2. Open any page with Edit with Bricks, then Style Manager (toolbar) → Classes tab.\n3. Use the filter “Unused on this site” and compare the list with the class names in this finding’s table.\n4. Check each name is not used in custom code, a Code element or your child theme’s CSS; lock any class you want to keep.\n5. Select the classes to remove and move them to the trash, then open the trash and delete them permanently (trashed classes are still stored).", 'blt-optimized' )
					),
				)
			),
		);
	}

	/**
	 * Published popup and section templates with no display conditions and no
	 * reference from any scanned Bricks content (flag only).
	 *
	 * A popup with no conditions is never rendered into a page, and a section
	 * template only renders through the Template element, so both can be dead
	 * weight. "No conditions" alone does not make a template unused (Bricks'
	 * own audit says so), which is why references are checked first.
	 *
	 * @param array $scan Complete content scan.
	 * @return array[]
	 */
	private function findings_unreferenced_templates( array $scan ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, t.meta_value AS template_type FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = %s
				 WHERE p.post_type = %s AND p.post_status = 'publish' AND t.meta_value IN ('popup','section')
				 ORDER BY p.ID ASC LIMIT 300",
				self::META_TEMPLATE_TYPE,
				self::POST_TYPE
			),
			ARRAY_A
		);
		// phpcs:enable
		if ( empty( $rows ) ) {
			return array();
		}

		$table = array();
		foreach ( $rows as $row ) {
			$id = (int) $row['ID'];
			if ( $this->template_has_conditions( $id ) ) {
				continue;
			}
			if ( isset( $scan['template_refs'][ $id ] ) ) {
				continue;
			}

			$triggers = isset( $scan['popup_triggers'][ $id ] ) ? (int) $scan['popup_triggers'][ $id ] : 0;
			$is_popup = 'popup' === $row['template_type'];
			if ( $is_popup && $triggers > 0 ) {
				$reason = sprintf(
					/* translators: %d: number of elements with an interaction that opens the popup. */
					_n( '%d element opens it, but with no conditions Bricks does not render the popup, so it cannot open', '%d elements open it, but with no conditions Bricks does not render the popup, so it cannot open', $triggers, 'blt-optimized' ),
					$triggers
				);
			} elseif ( $is_popup ) {
				$reason = __( 'No conditions, so it is never rendered, and nothing embeds or opens it', 'blt-optimized' );
			} else {
				$reason = __( 'No conditions, and no Template element inserts it', 'blt-optimized' );
			}

			$table[] = array(
				$this->doc_label(
					array(
						'title'         => (string) $row['post_title'],
						'type'          => self::POST_TYPE,
						'template_type' => (string) $row['template_type'],
					),
					$id
				),
				$is_popup ? __( 'Popup', 'blt-optimized' ) : __( 'Section', 'blt-optimized' ),
				$reason,
				self::edit_url( $id ),
			);
		}

		if ( ! $table ) {
			return array();
		}

		return array(
			$this->finding(
				array(
					'id'       => 'bricks.templates_unreferenced',
					'key'      => 'bricks.templates_unreferenced',
					'category' => 'database',
					'severity' => 'notice',
					'title'    => sprintf(
						/* translators: %s: number of templates. */
						_n( '%s published Bricks popup or section template has no conditions and no references', '%s published Bricks popup and section templates have no conditions and no references', count( $table ), 'blt-optimized' ),
						number_format_i18n( count( $table ) )
					),
					'summary'  => __( 'A popup template is only rendered where its conditions match, and a section template only appears through the Template element, so a published one with neither may never show anywhere. It costs nothing on the front end but is clutter, and a popup that looks live may not be. This is only a flag: no conditions does not always mean unused (a template can be reached from a menu, a hook or code), so check each before changing anything.', 'blt-optimized' ),
					'impact'   => array(
						'type'  => 'count',
						'value' => count( $table ),
						'label' => '',
					),
					'details'  => array(
						'columns' => array( __( 'Template', 'blt-optimized' ), __( 'Type', 'blt-optimized' ), __( 'Why it is flagged', 'blt-optimized' ), __( 'Edit with Bricks', 'blt-optimized' ) ),
						'rows'    => array_slice( $table, 0, self::MAX_ROWS ),
					),
					'fix'      => $this->fix_manual(
						__( 'Check the template', 'blt-optimized' ),
						__( "1. Open Bricks → Templates (Templates in the WordPress admin, post type bricks_template) and open the template from this finding’s table.\n2. For a popup: add display conditions (Template Settings → Conditions, for example “Entire website”) if it should open, otherwise move it to the trash.\n3. For a section: check it is inserted with the Template element or a hook condition; if not, delete or archive it.", 'blt-optimized' )
					),
				)
			),
		);
	}

	/**
	 * Whether a template has at least one display condition.
	 *
	 * @param int $template_id Template post ID.
	 * @return bool
	 */
	private function template_has_conditions( $template_id ) {
		$settings = self::decode( get_post_meta( $template_id, self::META_TEMPLATE_SETTINGS, true ) );
		if ( ! is_array( $settings ) || empty( $settings['templateConditions'] ) || ! is_array( $settings['templateConditions'] ) ) {
			return false;
		}
		foreach ( $settings['templateConditions'] as $condition ) {
			if ( is_array( $condition ) && ! empty( $condition['main'] ) ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ */
	/* Check: fonts, icon fonts and settings that need page evidence       */
	/* ------------------------------------------------------------------ */

	/**
	 * Google Fonts requested from Google on a Bricks site whose "Disable
	 * Google Fonts" setting is off.
	 *
	 * @param array[] $pages Page reports.
	 * @return array[]
	 */
	private function check_google_fonts( array $pages ) {
		if ( $this->setting_on( 'disableGoogleFonts' ) ) {
			return array();
		}

		$pages   = $this->usable_pages( $pages );
		$rows    = array();
		$hosts   = array();
		$handles = array();
		$bricks  = false;
		foreach ( $pages as $page ) {
			$found = array();
			$names = array();

			// What the profiler saw enqueued: the handle tells who asked for the font.
			$styles = isset( $page['profile']['assets']['styles'] ) && is_array( $page['profile']['assets']['styles'] ) ? $page['profile']['assets']['styles'] : array();
			foreach ( $styles as $style ) {
				$host = is_array( $style ) && ! empty( $style['src'] ) ? strtolower( (string) wp_parse_url( (string) $style['src'], PHP_URL_HOST ) ) : '';
				if ( 'fonts.googleapis.com' === $host || 'fonts.gstatic.com' === $host ) {
					$handle             = isset( $style['handle'] ) ? (string) $style['handle'] : '';
					$names[]            = $handle;
					$handles[ $handle ] = true;
					$hosts[ $host ]     = true;
					$found[]            = (string) $style['src'];
					if ( 0 === strpos( $handle, 'bricks' ) ) {
						$bricks = true;
					}
				}
			}

			if ( ! $found ) {
				$sheets = isset( $page['html']['stylesheets'] ) && is_array( $page['html']['stylesheets'] ) ? $page['html']['stylesheets'] : array();
				foreach ( $sheets as $sheet ) {
					$host = isset( $sheet['host'] ) ? strtolower( (string) $sheet['host'] ) : '';
					if ( 'fonts.googleapis.com' === $host || 'fonts.gstatic.com' === $host ) {
						$found[]        = isset( $sheet['href'] ) ? (string) $sheet['href'] : $host;
						$hosts[ $host ] = true;
						if ( ! empty( $sheet['id'] ) && 0 === strpos( (string) $sheet['id'], 'bricks' ) ) {
							$bricks = true;
						}
					}
				}
			}
			if ( ! $found && ! empty( $page['html']['fonts']['google_fonts'] ) ) {
				$found[] = 'fonts.googleapis.com';
			}
			if ( $found ) {
				$rows[] = array( $this->page_ref( $page ), $this->shorten( implode( ' ', array_slice( $found, 0, 2 ) ), 160 ), implode( ', ', array_filter( array_unique( $names ) ) ) );
			}
		}

		if ( ! $rows ) {
			return array();
		}

		$mode           = $this->settings();
		$webfont_loader = isset( $mode['webfontLoading'] ) && '' !== $mode['webfontLoading'];

		$summary = sprintf(
			/* translators: 1: pages with Google Fonts, 2: pages scanned. */
			__( 'Google Fonts are requested from Google’s servers on %1$s of %2$s scanned pages. That is an extra connection to another origin (DNS, TLS and the font files) before text can be styled, and it sends visitors’ requests to Google. Bricks can serve the same fonts from this site: in the Font Manager (Google tab) download each font — a downloaded font becomes a custom font with font-display: swap — and Bricks does not load a Google font from Google when a custom font with the same family name exists. “Disable Google Fonts” then switches the Google requests off completely.', 'blt-optimized' ),
			number_format_i18n( count( $rows ) ),
			number_format_i18n( count( $pages ) )
		);
		if ( $webfont_loader ) {
			$summary .= ' ' . __( 'The webfont loading method is set to the Webfont Loader, which hides page content until the fonts have loaded.', 'blt-optimized' );
		}
		if ( ! $bricks && $handles ) {
			$summary .= ' ' . sprintf(
				/* translators: %s: stylesheet handles. */
				__( 'Caution: the font stylesheet is enqueued as %s, not under a Bricks handle, so it may come from a plugin or your child theme rather than from Bricks; check where it is added before relying on the Bricks setting.', 'blt-optimized' ),
				implode( ', ', array_filter( array_keys( $handles ) ) )
			);
		}

		return array(
			$this->finding(
				array(
					'id'       => 'bricks.google_fonts',
					'key'      => 'bricks.google_fonts',
					'category' => 'assets',
					'severity' => 'notice',
					'title'    => sprintf(
						/* translators: 1: pages with Google Fonts, 2: pages scanned. */
						__( 'Bricks pages load Google Fonts from Google on %1$s of %2$s scanned pages', 'blt-optimized' ),
						number_format_i18n( count( $rows ) ),
						number_format_i18n( count( $pages ) )
					),
					'summary'  => $summary,
					'impact'   => array(
						'type'  => 'count',
						'value' => count( $hosts ) > 0 ? count( $hosts ) : 1,
						'label' => count( $hosts ) > 0
							/* translators: %s: number of third-party origins. */
							? sprintf( _n( '%s extra origin', '%s extra origins', count( $hosts ), 'blt-optimized' ), number_format_i18n( count( $hosts ) ) )
							: '',
					),
					'where'    => array_merge( array( self::settings_url() ), array_keys( $hosts ) ),
					'details'  => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Font request', 'blt-optimized' ), __( 'Enqueued as', 'blt-optimized' ) ),
						'rows'    => array_slice( $rows, 0, self::MAX_ROWS ),
					),
					'fix'      => $this->fix_manual(
						__( 'Host the fonts yourself', 'blt-optimized' ),
						__( "1. In the builder open Style Manager or any typography control → Font Manager → Google tab.\n2. Find each font family the site uses and click the download icon; it is added to the Custom fonts.\n3. Check the typography settings still use that family name (Bricks then stops loading it from Google).\n4. When no Google font is needed any more, open Bricks → Settings → Performance and tick “Disable Google Fonts”.\n5. Reload a page and confirm there are no requests to fonts.googleapis.com or fonts.gstatic.com.", 'blt-optimized' )
					),
				)
			),
		);
	}

	/**
	 * Icon font libraries Bricks loads on (nearly) every scanned page.
	 *
	 * Bricks loads an icon font only when an icon of that library is on the
	 * page, so one icon in the header or footer loads the whole font on every
	 * page. The finding names where the icons are.
	 *
	 * @param array[] $pages Page reports.
	 * @return array[]
	 */
	private function check_icon_fonts( array $pages ) {
		$pages = $this->usable_pages( $pages );
		if ( count( $pages ) < 2 ) {
			return array();
		}

		$libraries = array(
			'fontawesome' => array(
				'label'   => 'Font Awesome',
				'handles' => array( 'bricks-font-awesome-6', 'bricks-font-awesome-6-brands' ),
			),
			'ionicons'    => array(
				'label'   => 'Ionicons',
				'handles' => array( 'bricks-ionicons' ),
			),
			'themify'     => array(
				'label'   => 'Themify icons',
				'handles' => array( 'bricks-themify-icons' ),
			),
		);

		$loaded = array(); // library => { pages: int, bytes: int }.
		foreach ( $pages as $page ) {
			$styles = isset( $page['profile']['assets']['styles'] ) && is_array( $page['profile']['assets']['styles'] ) ? $page['profile']['assets']['styles'] : array();
			foreach ( $libraries as $key => $library ) {
				$bytes = 0;
				$found = false;
				foreach ( $styles as $style ) {
					if ( is_array( $style ) && isset( $style['handle'] ) && in_array( $style['handle'], $library['handles'], true ) ) {
						$found  = true;
						$bytes += isset( $style['bytes'] ) ? (int) $style['bytes'] : 0;
					}
				}
				if ( $found ) {
					if ( ! isset( $loaded[ $key ] ) ) {
						$loaded[ $key ] = array(
							'pages' => 0,
							'bytes' => 0,
						);
					}
					++$loaded[ $key ]['pages'];
					$loaded[ $key ]['bytes'] = max( $loaded[ $key ]['bytes'], $bytes );
				}
			}
		}

		$sitewide = array();
		foreach ( $loaded as $key => $data ) {
			if ( $data['pages'] / count( $pages ) >= (float) self::t( 'SITEWIDE_RATIO' ) ) {
				$sitewide[ $key ] = $data;
			}
		}
		if ( ! $sitewide ) {
			return array();
		}

		$scan  = $this->scan_content();
		$rows  = array();
		$bytes = 0;
		$names = array();
		$most  = 0;
		foreach ( $sitewide as $key => $data ) {
			$most  = max( $most, (int) $data['pages'] );
			$icons = isset( $scan['icons'][ $key ] ) ? $scan['icons'][ $key ] : array(
				'count' => 0,
				'docs'  => array(),
			);
			$where = array();
			arsort( $icons['docs'] );
			foreach ( array_slice( $icons['docs'], 0, 4, true ) as $post_id => $n ) {
				$info    = isset( $scan['docs'][ $post_id ] ) ? $scan['docs'][ $post_id ] : array(
					'title' => (string) get_the_title( $post_id ),
					'type'  => (string) get_post_type( $post_id ),
				);
				$where[] = sprintf( '%s × %d', $this->doc_label( $info, (int) $post_id ), $n );
			}
			$rows[]  = array(
				$libraries[ $key ]['label'],
				sprintf( '%d / %d', $data['pages'], count( $pages ) ),
				$data['bytes'] > 0 ? size_format( $data['bytes'], 1 ) : '',
				$icons['count'] > 0 ? number_format_i18n( $icons['count'] ) : '',
				$where ? implode( '; ', $where ) : __( '(not found in the scanned Bricks content)', 'blt-optimized' ),
			);
			$bytes  += $data['bytes'];
			$names[] = $libraries[ $key ]['label'];
		}

		return array(
			$this->finding(
				array(
					'id'       => 'bricks.icon_fonts',
					'key'      => 'bricks.icon_fonts',
					'category' => 'assets',
					'severity' => 'notice',
					'title'    => 1 === count( $names )
						? sprintf(
							/* translators: 1: icon font name, 2: pages loading it, 3: pages scanned. */
							__( 'Bricks loads the %1$s icon font on %2$s of %3$s scanned pages', 'blt-optimized' ),
							$names[0],
							number_format_i18n( $most ),
							number_format_i18n( count( $pages ) )
						)
						: sprintf(
							/* translators: 1: number of icon fonts, 2: icon font names. */
							__( 'Bricks loads %1$s icon fonts (%2$s) on most scanned pages', 'blt-optimized' ),
							number_format_i18n( count( $names ) ),
							implode( ', ', $names )
						),
					'summary'  => __( 'Bricks loads an icon font only when an icon from that library is on the page. A single icon in a header, footer or other shared template therefore loads the whole icon font (a stylesheet plus font files) on every page, even when only a handful of icons are used. Replacing those icons with SVG icons — Bricks’ Icon Manager supports custom SVG icon sets — lets Bricks stop loading the font once no element on a page uses it.', 'blt-optimized' ),
					'impact'   => $bytes > 0
						? array(
							'type'  => 'bytes',
							'value' => $bytes,
							'label' => '',
						)
						: array(
							'type'  => 'count',
							'value' => count( $sitewide ),
							'label' => '',
						),
					'where'    => array(),
					'details'  => array(
						'columns' => array( __( 'Icon library', 'blt-optimized' ), __( 'Pages loading it', 'blt-optimized' ), __( 'Stylesheet', 'blt-optimized' ), __( 'Icons in the content', 'blt-optimized' ), __( 'Where the icons are', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => $this->fix_manual(
						__( 'Replace the icons with SVG', 'blt-optimized' ),
						__( "1. Open the pages and templates listed under “Where the icons are” with Edit with Bricks.\n2. For each Icon element (and any element with an icon setting), choose an SVG icon instead of the font icon: upload an SVG icon set in Bricks → Icon Manager (SVG uploads must be enabled under Bricks → Settings), or use the SVG element.\n3. Once no element uses the font library, Bricks stops loading it. Reload the page and check the Network tab.", 'blt-optimized' )
					),
				)
			),
		);
	}

	/**
	 * "Cache query loops" is off although a persistent object cache could make
	 * it useful.
	 *
	 * @param array[] $pages Unused.
	 * @return array[]
	 */
	private function check_query_cache( array $pages ) {
		unset( $pages );
		if ( $this->setting_on( 'cacheQueryLoops' ) || ! wp_using_ext_object_cache() ) {
			return array();
		}
		$scan = $this->scan_content();
		if ( $scan['loops_total'] < (int) self::t( 'QUERY_CACHE_MIN_LOOPS' ) ) {
			return array();
		}

		return array(
			$this->finding(
				array(
					'id'       => 'bricks.query_cache',
					'key'      => 'bricks.query_cache',
					'category' => 'server',
					'severity' => 'notice',
					'title'    => sprintf(
						/* translators: %s: number of query loops. */
						__( 'Bricks “Cache query loops” is off although this site has a persistent object cache (%s query loops found)', 'blt-optimized' ),
						number_format_i18n( (int) $scan['loops_total'] )
					),
					'summary'  => __( 'Cache query loops stores query loop results in the WordPress object cache, keyed by element, query and parent loop. It only helps across page views when a persistent object cache (Redis or Memcached) is installed — which this site has. Bricks keeps the results for a short time (about a minute), so content updates appear quickly; turn it off again if loops show stale or unexpected results.', 'blt-optimized' ),
					'impact'   => array(
						'type'  => 'count',
						'value' => (int) $scan['loops_total'],
						'label' => '',
					),
					'where'    => array( self::settings_url() ),
					'fix'      => $this->fix_manual(
						__( 'Turn on Cache query loops', 'blt-optimized' ),
						__( "1. Open Bricks → Settings → Performance.\n2. Tick “Cache query loops” and save.\n3. Visit a page with a query loop twice and check the loop output is still current. If results look stale, untick it.", 'blt-optimized' )
					),
				)
			),
		);
	}

	/**
	 * "Query Bricks data in search results" is on and the Bricks data is large.
	 *
	 * @param array[] $pages Unused.
	 * @return array[]
	 */
	private function check_search_data( array $pages ) {
		unset( $pages );
		if ( ! $this->setting_on( 'searchResultsQueryBricksData' ) ) {
			return array();
		}
		$scan  = $this->scan_content();
		$bytes = (int) $scan['totals']['bytes'];
		if ( $bytes < (int) self::t( 'SEARCH_DATA_MIN_BYTES' ) ) {
			return array();
		}

		return array(
			$this->finding(
				array(
					'id'       => 'bricks.search_data',
					'key'      => 'bricks.search_data',
					'category' => 'database',
					'severity' => 'notice',
					'title'    => sprintf(
						/* translators: %s: size of the Bricks data. */
						__( 'Every site search also scans %s of Bricks data in postmeta', 'blt-optimized' ),
						size_format( $bytes, 1 )
					),
					'summary'  => sprintf(
						/* translators: %s: number of documents. */
						__( '“Query Bricks data in search results” extends WordPress search to the post meta tables where Bricks stores page content, so text inside Bricks elements becomes searchable. Each search then has to match against that meta data too (%s pages and templates), which can make search slow on a large site. Keep it only if visitors need to find text that exists only inside Bricks elements.', 'blt-optimized' ),
						number_format_i18n( (int) $scan['totals']['docs'] )
					),
					'impact'   => array(
						'type'  => 'bytes',
						'value' => $bytes,
						'label' => '',
					),
					'where'    => array( self::settings_url() ),
					'fix'      => $this->fix_manual(
						__( 'Switch it off', 'blt-optimized' ),
						__( "1. Open Bricks → Settings → General → Miscellaneous.\n2. Untick “Query Bricks data in search results” and save.\n3. Run a few site searches and confirm the results are still what visitors need.", 'blt-optimized' )
					),
				)
			),
		);
	}

	/**
	 * Lazy loading switched off for the whole site.
	 *
	 * @param array[] $pages Unused.
	 * @return array[]
	 */
	private function check_lazy_load( array $pages ) {
		unset( $pages );
		if ( ! $this->setting_on( 'disableLazyLoad' ) ) {
			return array();
		}

		return array(
			$this->finding(
				array(
					'id'       => 'bricks.lazy_load_off',
					'key'      => 'bricks.lazy_load_off',
					'category' => 'content',
					'severity' => 'notice',
					'title'    => __( 'Bricks lazy loading is switched off for the whole site', 'blt-optimized' ),
					'summary'  => __( 'Bricks applies native lazy loading to its images by default. With “Disable lazy loading” ticked, every image loads immediately, including images far below the fold, which makes the first load heavier. Bricks suggests turning it off only if lazy loading causes display problems; a single page can also opt out in its Page Settings, and an above-the-fold image should be set to load eagerly on its own.', 'blt-optimized' ),
					'impact'   => array(
						'type'  => 'none',
						'value' => 0,
						'label' => '',
					),
					'where'    => array( self::settings_url() ),
					'fix'      => $this->fix_manual(
						__( 'Re-enable lazy loading', 'blt-optimized' ),
						__( "1. Open Bricks → Settings → Performance.\n2. Untick “Disable lazy loading” and save.\n3. If one page needs it off, use that page’s Settings → Page Settings → “Disable lazy load” instead.", 'blt-optimized' )
					),
				)
			),
		);
	}

	/**
	 * Shorten text for evidence cells.
	 *
	 * @param string $text Text.
	 * @param int    $max  Maximum length.
	 * @return string
	 */
	private function shorten( $text, $max ) {
		$text = (string) $text;
		if ( strlen( $text ) <= $max ) {
			return $text;
		}
		return substr( $text, 0, $max - 1 ) . '…';
	}
}
