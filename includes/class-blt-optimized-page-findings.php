<?php
/**
 * Page findings — raw per-page measurements in, pointed findings out.
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;

/**
 * The brain behind the front-end half of the performance scan.
 *
 * The page auditor measures each sampled URL (profiler data from inside the
 * request plus an HTML analysis of what the visitor receives) and hands over
 * one report per page. Showing those reports page by page would bury the
 * answer, so this class reads them all together and produces the kind of
 * output Query Monitor and PageSpeed Insights are loved for: pointed,
 * attributed and prioritised. Every finding names the culprit (a plugin, a
 * query, a script handle, an image, a post) and the scope ("on 5 pages"), says
 * in plain language why it costs speed and what fixing it gains, and — where a
 * fix is safe — carries a one-click fix.
 *
 * Aggregation rule: ONE finding per culprit, never one per page per issue. The
 * pages it affects go in `where` and each page gets an evidence row.
 *
 * Impact rule: `impact` is in ms for time and bytes for weight, so findings
 * stay comparable. Unless a finding says otherwise, the impact is the WORST
 * single page (what one unlucky visitor pays), not a sum across pages — a
 * plugin that is slow on three pages is not "three times as slow". Values that
 * are estimates rather than measurements carry an "(est.)" label.
 *
 * Robustness rule: the page reports come from modules built separately, so
 * every key is read through the null-safe accessor v(), pages without a
 * profile (profile null) still yield the HTML-based findings, and one broken
 * check can never take down the rest (see last_errors()).
 *
 * Asset usage is judged against a marker registry (asset_markers()): known
 * libraries (Contact Form 7, WooCommerce, Elementor …) declare which class/id
 * tokens prove an asset is used on a page; anything else owned by a plugin
 * falls back to a conservative "may be unused — verify" notice.
 *
 * Output: findings built with BLT_Optimized_Findings::make(), source `profiler`.
 */
class BLT_Optimized_Page_Findings {

	/*
	 * Thresholds. All are filterable as a set through
	 * `blt_optimized_page_findings_thresholds` (array keyed by constant name).
	 */

	// Server.
	const GENERATION_WARN_MS       = 600;
	const GENERATION_CRIT_MS       = 1500;
	const PAGE_CACHE_NOTICE_BELOW_MS = 200;
	const COMPRESSION_MIN_BYTES    = 10240;
	const COMPRESSION_SAVING_RATIO = 0.7;
	const QUERY_COUNT_WARN         = 100;
	const QUERY_COUNT_CRIT         = 300;
	const QUERY_TIME_WARN_MS       = 300;
	const SLOW_QUERY_MS            = 50;
	const SLOW_QUERY_WARN_TOTAL_MS = 100;
	const SLOW_QUERY_CRIT_TOTAL_MS = 500;
	const DUPLICATE_QUERY_MIN      = 5;
	const DUPLICATE_WARN_WASTED_MS = 50;
	const PLUGIN_LOAD_WARN_MS      = 50;
	const PLUGIN_LOAD_CRIT_MS      = 150;
	const HTTP_CRIT_MS             = 1000;
	const ERRORS_WARN_PER_PAGE     = 20;
	const MEMORY_WARN_RATIO        = 0.8;

	// Assets.
	const JS_WEIGHT_WARN_BYTES     = 500 * KB_IN_BYTES;
	const CSS_WEIGHT_WARN_BYTES    = 250 * KB_IN_BYTES;
	const UNUSED_WARN_BYTES        = 4 * KB_IN_BYTES;
	const GENERIC_UNUSED_MIN_PAGES = 2;
	const THIRD_PARTY_MAX          = 8;

	// Content.
	const OVERSIZED_BYTES          = 300 * KB_IN_BYTES;
	const OVERSIZED_RATIO          = 2;
	const OVERSIZED_NATURAL_W      = 2000;
	const LEGACY_FORMAT_BYTES      = 100 * KB_IN_BYTES;
	const WEBP_SAVING_RATIO        = 0.3;
	const HERO_MAX_INDEX           = 1;
	const HERO_MIN_WIDTH           = 200;
	const EMBED_MULTI              = 2;
	const DOM_WARN                 = 1500;
	const DOM_CRIT                 = 3000;
	const HTML_WARN_BYTES          = 300 * KB_IN_BYTES;
	const INLINE_CSS_WARN_BYTES    = 50 * KB_IN_BYTES;
	const INLINE_JS_WARN_BYTES     = 100 * KB_IN_BYTES;

	// Presentation caps.
	const MAX_IMAGE_FINDINGS       = 8;
	const SQL_TRUNCATE             = 300;

	/**
	 * Rough weight of one embed's player scripts, by kind (bytes). Estimates
	 * (the usual Lighthouse "facade" figures), always labelled as such.
	 */
	const EMBED_WEIGHTS = array(
		'youtube' => 550 * KB_IN_BYTES,
		'vimeo'   => 350 * KB_IN_BYTES,
		'maps'    => 450 * KB_IN_BYTES,
		'other'   => 200 * KB_IN_BYTES,
	);

	/**
	 * Resolved (filtered) thresholds for the current run.
	 *
	 * @var array|null
	 */
	private static $thresholds = null;

	/**
	 * Marker registry for the current run.
	 *
	 * @var array|null
	 */
	private static $markers = null;

	/**
	 * Memoised tweaks (saved speed-tweak settings) for the current run.
	 *
	 * @var array|null
	 */
	private static $tweaks = null;

	/**
	 * Memoised post_content lookups, post id => string.
	 *
	 * @var array
	 */
	private static $post_content = array();

	/**
	 * Diagnostics from checks that threw during the last analyze().
	 *
	 * @var string[]
	 */
	private static $errors = array();

	/**
	 * Number of image/embed problems found so far this run (decides whether the
	 * "images look healthy" pass finding may be shown).
	 *
	 * @var int
	 */
	private static $image_issues = 0;

	/* ------------------------------------------------------------------ */
	/* Public API                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Findings from all page reports together.
	 *
	 * @param array[] $pages Page reports (Module B shape).
	 * @return array[] Findings, sorted by severity then impact.
	 */
	public static function analyze( array $pages ) {
		self::reset();

		$all = self::prepare( $pages );
		if ( empty( $all ) ) {
			return array();
		}

		// Pages that errored are reported once, then left out of every other
		// check: analysing an error page's HTML would only produce noise.
		$findings = self::guard( 'check_page_errors', $all );
		$pages    = array();
		foreach ( $all as $page ) {
			if ( ! self::page_failed( $page ) ) {
				$pages[] = $page;
			}
		}
		if ( empty( $pages ) ) {
			return BLT_Optimized_Findings::sort( $findings );
		}

		$checks = array(
			// Server.
			'check_profile_unavailable',
			'check_generation_time',
			'check_page_cache',
			'check_compression',
			'check_static_cache',
			'check_query_volume',
			'check_slow_queries',
			'check_duplicate_queries',
			'check_plugin_load',
			'check_external_http',
			'check_php_errors',
			'check_transient_writes',
			'check_memory',
			// Assets.
			'check_asset_weight',
			'check_render_blocking',
			'check_unused_assets',
			'check_jquery_migrate',
			'check_emoji',
			'check_dashicons',
			'check_cart_fragments',
			'check_duplicate_libraries',
			'check_third_party',
			'check_font_preconnect',
			// Content.
			'check_missing_dimensions',
			'check_oversized_images',
			'check_lazy_hero',
			'check_legacy_formats',
			'check_heavy_embeds',
			'check_dom_size',
			'check_html_size',
			'check_inline_assets',
			'check_images_ok',
		);
		foreach ( $checks as $check ) {
			$findings = array_merge( $findings, self::guard( $check, $pages ) );
		}

		/**
		 * Filters the page findings before they are returned.
		 *
		 * @param array[] $findings Findings.
		 * @param array[] $pages    Prepared page reports.
		 */
		$findings = apply_filters( 'blt_optimized_page_findings', $findings, $pages );

		return BLT_Optimized_Findings::sort( is_array( $findings ) ? $findings : array() );
	}

	/**
	 * "Who's slow" rollup per component, sorted by total milliseconds desc.
	 *
	 * load_ms / query_ms / http_ms and query_count are averaged per profiled
	 * page (a component absent from a page counts as 0 there); js_bytes,
	 * css_bytes and errors are the maximum seen on any single page; pages is
	 * the number of pages on which the component showed up at all. total_ms
	 * (load + query + http) is added for sorting.
	 *
	 * @param array[] $pages Page reports.
	 * @return array[] Each { component, load_ms, query_ms, query_count, http_ms, js_bytes, css_bytes, errors, pages, total_ms }.
	 */
	public static function component_summary( array $pages ) {
		self::reset();
		$pages    = self::prepare( $pages );
		$profiled = 0;
		$rollup   = array();

		foreach ( $pages as $page ) {
			$profile = self::profile( $page );
			if ( ! $profile ) {
				continue;
			}
			++$profiled;

			$seen = array();
			foreach ( self::component_costs( $profile ) as $key => $cost ) {
				$seen[ $key ] = true;
				$rollup[ $key ] = self::rollup_row( $rollup, $key, $cost['component'] );
				$rollup[ $key ]['load_ms']     += $cost['load_ms'];
				$rollup[ $key ]['query_ms']    += $cost['query_ms'];
				$rollup[ $key ]['query_count'] += $cost['query_count'];
				$rollup[ $key ]['http_ms']     += $cost['http_ms'];
			}

			$js  = array();
			$css = array();
			foreach ( array( 'script' => 'js', 'style' => 'css' ) as $type => $bucket ) {
				foreach ( self::page_assets( $page ) as $asset ) {
					if ( $asset['type'] !== $type ) {
						continue;
					}
					$key         = BLT_Optimized_Components::key( $asset['component'] );
					$seen[ $key ] = true;
					$rollup[ $key ] = self::rollup_row( $rollup, $key, $asset['component'] );
					if ( 'js' === $bucket ) {
						$js[ $key ] = ( isset( $js[ $key ] ) ? $js[ $key ] : 0 ) + $asset['bytes'];
					} else {
						$css[ $key ] = ( isset( $css[ $key ] ) ? $css[ $key ] : 0 ) + $asset['bytes'];
					}
				}
			}
			foreach ( $js as $key => $bytes ) {
				$rollup[ $key ]['js_bytes'] = max( $rollup[ $key ]['js_bytes'], $bytes );
			}
			foreach ( $css as $key => $bytes ) {
				$rollup[ $key ]['css_bytes'] = max( $rollup[ $key ]['css_bytes'], $bytes );
			}

			$errors = array();
			foreach ( (array) self::v( $profile, 'errors', array() ) as $error ) {
				if ( ! is_array( $error ) ) {
					continue;
				}
				$key           = BLT_Optimized_Components::key( self::comp( isset( $error['component'] ) ? $error['component'] : null ) );
				$seen[ $key ]  = true;
				$rollup[ $key ] = self::rollup_row( $rollup, $key, self::comp( isset( $error['component'] ) ? $error['component'] : null ) );
				$errors[ $key ] = ( isset( $errors[ $key ] ) ? $errors[ $key ] : 0 ) + max( 1, (int) ( isset( $error['count'] ) ? $error['count'] : 1 ) );
			}
			foreach ( $errors as $key => $count ) {
				$rollup[ $key ]['errors'] = max( $rollup[ $key ]['errors'], $count );
			}

			foreach ( array_keys( $seen ) as $key ) {
				++$rollup[ $key ]['pages'];
			}
		}

		$divisor = max( 1, $profiled );
		$out     = array();
		foreach ( $rollup as $row ) {
			$row['load_ms']     = round( $row['load_ms'] / $divisor, 1 );
			$row['query_ms']    = round( $row['query_ms'] / $divisor, 1 );
			$row['http_ms']     = round( $row['http_ms'] / $divisor, 1 );
			$row['query_count'] = (int) round( $row['query_count'] / $divisor );
			$row['total_ms']    = round( $row['load_ms'] + $row['query_ms'] + $row['http_ms'], 1 );
			if ( $row['total_ms'] <= 0 && 0 === $row['js_bytes'] && 0 === $row['css_bytes'] && 0 === $row['errors'] ) {
				continue;
			}
			$out[] = $row;
		}

		usort(
			$out,
			static function ( $a, $b ) {
				if ( $a['total_ms'] === $b['total_ms'] ) {
					return ( $b['js_bytes'] + $b['css_bytes'] ) <=> ( $a['js_bytes'] + $a['css_bytes'] );
				}
				return $b['total_ms'] <=> $a['total_ms'];
			}
		);

		return $out;
	}

	/**
	 * Registry of known asset families: which handles belong to them and which
	 * class/id tokens on a page prove they are actually used there.
	 *
	 * Marker fields:
	 *  - id         string    Stable id (used in finding keys).
	 *  - label      string    Product name ("Contact Form 7").
	 *  - handles    string[]  Exact handles or fnmatch patterns (case-insensitive).
	 *  - component  string    Owning plugin slug, '' = any owner. A local asset only
	 *                         matches when its owner is that slug (or `slug-…`);
	 *                         external assets (reCAPTCHA) match on handle alone.
	 *  - tokens     string[]  Class/id prefixes proving use; a leading `=` means
	 *                         exact match (short tokens such as `=fas`).
	 *  - ignore     string[]  fnmatch patterns of tokens that never count as proof
	 *                         (sitewide body classes).
	 *  - contexts   string[]  Page contexts that also prove use ('woocommerce').
	 *  - without    string    "…on 5 pages without <this>" phrase for titles.
	 *  - condition  array[]   Asset-rule conditions to propose (list of
	 *                         { type, value }); empty = no safe automatic rule.
	 *
	 * @return array[]
	 */
	public static function asset_markers() {
		$markers = array(
			array(
				'id'        => 'contact-form-7',
				'label'     => 'Contact Form 7',
				'handles'   => array( 'contact-form-7', 'swv', 'wpcf7-*', 'google-recaptcha', 'wpcf7-recaptcha' ),
				'component' => 'contact-form-7',
				'tokens'    => array( 'wpcf7' ),
				'ignore'    => array(),
				'contexts'  => array(),
				'without'   => __( 'a form', 'blt-optimized' ),
				'condition' => array(
					array(
						'type'  => 'content_contains',
						'value' => '[contact-form-7',
					),
					array(
						'type'  => 'content_contains',
						'value' => 'wp:contact-form-7',
					),
				),
			),
			array(
				'id'        => 'wpforms',
				'label'     => 'WPForms',
				'handles'   => array( 'wpforms*' ),
				'component' => '',
				'tokens'    => array( 'wpforms' ),
				'ignore'    => array(),
				'contexts'  => array(),
				'without'   => __( 'a form', 'blt-optimized' ),
				'condition' => array(
					array(
						'type'  => 'content_contains',
						'value' => '[wpforms',
					),
					array(
						'type'  => 'content_contains',
						'value' => 'wp:wpforms',
					),
				),
			),
			array(
				'id'        => 'gravity-forms',
				'label'     => 'Gravity Forms',
				'handles'   => array( 'gform_*', 'gforms_*', 'gform-*' ),
				'component' => '',
				'tokens'    => array( 'gform_wrapper', 'gform_', 'gform-' ),
				'ignore'    => array(),
				'contexts'  => array(),
				'without'   => __( 'a form', 'blt-optimized' ),
				'condition' => array(
					array(
						'type'  => 'content_contains',
						'value' => '[gravityform',
					),
					array(
						'type'  => 'content_contains',
						'value' => 'wp:gravityforms',
					),
				),
			),
			array(
				'id'        => 'ninja-forms',
				'label'     => 'Ninja Forms',
				'handles'   => array( 'nf-*', 'ninja-forms*' ),
				'component' => 'ninja-forms',
				'tokens'    => array( 'nf-form', 'nf-field' ),
				'ignore'    => array(),
				'contexts'  => array(),
				'without'   => __( 'a form', 'blt-optimized' ),
				'condition' => array(
					array(
						'type'  => 'content_contains',
						'value' => '[ninja_form',
					),
					array(
						'type'  => 'content_contains',
						'value' => 'wp:ninja-forms',
					),
				),
			),
			array(
				'id'        => 'mailchimp-for-wp',
				'label'     => 'Mailchimp for WordPress',
				'handles'   => array( 'mc4wp-*' ),
				'component' => 'mailchimp-for-wp',
				'tokens'    => array( 'mc4wp' ),
				'ignore'    => array(),
				'contexts'  => array(),
				'without'   => __( 'a sign-up form', 'blt-optimized' ),
				'condition' => array(
					array(
						'type'  => 'content_contains',
						'value' => '[mc4wp_form',
					),
					array(
						'type'  => 'content_contains',
						'value' => 'wp:mailchimp-for-wp',
					),
				),
			),
			array(
				'id'        => 'tablepress',
				'label'     => 'TablePress',
				'handles'   => array( 'tablepress*' ),
				'component' => 'tablepress',
				'tokens'    => array( 'tablepress' ),
				'ignore'    => array(),
				'contexts'  => array(),
				'without'   => __( 'a table', 'blt-optimized' ),
				'condition' => array(
					array(
						'type'  => 'content_contains',
						'value' => '[table ',
					),
					array(
						'type'  => 'content_contains',
						'value' => 'wp:tablepress',
					),
				),
			),
			array(
				'id'        => 'woocommerce',
				'label'     => 'WooCommerce',
				'handles'   => array( 'woocommerce*', 'wc-*', 'select2', 'selectWoo', 'jquery-blockui', 'js-cookie', 'sourcebuster-js', 'photoswipe*', 'zoom', 'flexslider', 'prettyPhoto*', 'jquery-payment', 'accounting' ),
				'component' => 'woocommerce',
				'tokens'    => array( 'woocommerce-', '=woocommerce', 'wc-block-', 'widget_shopping_cart', 'add_to_cart', '=products' ),
				'ignore'    => array( 'woocommerce-no-js', 'woocommerce-js', 'woocommerce-demo-store', 'woocommerce-active', 'woocommerce-store-notice*', 'woocommerce-block-theme-has-button-styles', 'woocommerce-uses-block-theme' ),
				'contexts'  => array( 'woocommerce' ),
				'without'   => __( 'shop content', 'blt-optimized' ),
				'condition' => array(
					array(
						'type'  => 'woocommerce',
						'value' => '1',
					),
					array(
						'type'  => 'content_contains',
						'value' => '[woocommerce_',
					),
					array(
						'type'  => 'content_contains',
						'value' => '[product',
					),
					array(
						'type'  => 'content_contains',
						'value' => '[add_to_cart',
					),
					array(
						'type'  => 'content_contains',
						'value' => '[sale_products',
					),
					array(
						'type'  => 'content_contains',
						'value' => '[recent_products',
					),
					array(
						'type'  => 'content_contains',
						'value' => '[featured_products',
					),
					array(
						'type'  => 'content_contains',
						'value' => '[best_selling_products',
					),
					array(
						'type'  => 'content_contains',
						'value' => '[top_rated_products',
					),
					array(
						'type'  => 'content_contains',
						'value' => 'wp:woocommerce',
					),
				),
			),
			array(
				'id'        => 'elementor',
				'label'     => 'Elementor',
				'handles'   => array( 'elementor-*', 'e-*', 'pro-*' ),
				'component' => 'elementor',
				'tokens'    => array( 'elementor' ),
				'ignore'    => array( 'elementor-default', 'elementor-kit-*' ),
				'contexts'  => array(),
				'without'   => __( 'Elementor content', 'blt-optimized' ),
				// No safe universal rule: Elementor pages are not recognisable from post_content.
				'condition' => array(),
			),
			array(
				'id'        => 'block-library',
				'label'     => __( 'Block editor styles', 'blt-optimized' ),
				'handles'   => array( 'wp-block-library', 'wp-block-library-theme', 'classic-theme-styles' ),
				'component' => '',
				'tokens'    => array( 'wp-block-', 'wp-site-blocks', 'has-global-padding' ),
				'ignore'    => array(),
				'contexts'  => array(),
				'without'   => __( 'any blocks', 'blt-optimized' ),
				'condition' => array(
					array(
						'type'  => 'content_contains',
						'value' => '<!-- wp:',
					),
				),
			),
			array(
				'id'        => 'mediaelement',
				'label'     => 'MediaElement',
				'handles'   => array( 'mediaelement*', 'wp-mediaelement', 'mejs*' ),
				'component' => '',
				'tokens'    => array( 'wp-video', 'wp-audio', 'mejs', 'wp-playlist' ),
				'ignore'    => array(),
				'contexts'  => array(),
				'without'   => __( 'audio or video', 'blt-optimized' ),
				'condition' => array(
					array(
						'type'  => 'content_contains',
						'value' => '[video',
					),
					array(
						'type'  => 'content_contains',
						'value' => '[audio',
					),
					array(
						'type'  => 'content_contains',
						'value' => '[playlist',
					),
					array(
						'type'  => 'content_contains',
						'value' => 'wp:audio',
					),
					array(
						'type'  => 'content_contains',
						'value' => 'wp:video',
					),
				),
			),
			array(
				'id'        => 'revolution-slider',
				'label'     => 'Slider Revolution',
				'handles'   => array( 'revmin', 'rs-plugin-settings', 'tp-tools', 'rs-*', 'revslider*' ),
				'component' => '',
				'tokens'    => array( 'rev_slider', 'rs-module', 'rev-slider', 'rs-' ),
				'ignore'    => array(),
				'contexts'  => array(),
				'without'   => __( 'a slider', 'blt-optimized' ),
				'condition' => array(
					array(
						'type'  => 'content_contains',
						'value' => '[rev_slider',
					),
					array(
						'type'  => 'content_contains',
						'value' => 'wp:revslider',
					),
				),
			),
			array(
				'id'        => 'sliders',
				'label'     => __( 'Slider library', 'blt-optimized' ),
				'handles'   => array( '*slick*', '*swiper*', '*owl-carousel*', '*owl.carousel*', '*owlcarousel*', 'owl' ),
				'component' => '',
				'tokens'    => array( 'slick', 'swiper', 'owl-carousel', 'owl-', '=owl' ),
				'ignore'    => array(),
				'contexts'  => array(),
				'without'   => __( 'a slider', 'blt-optimized' ),
				// Shortcode names differ per plugin, so no automatic rule.
				'condition' => array(),
			),
			array(
				'id'        => 'comment-reply',
				'label'     => __( 'Comment reply script', 'blt-optimized' ),
				'handles'   => array( 'comment-reply' ),
				'component' => '',
				'tokens'    => array( 'comment-respond', 'comment-form', '=respond', '=commentform', 'wp-block-post-comments', 'wp-block-comments' ),
				'ignore'    => array(),
				'contexts'  => array(),
				'without'   => __( 'a comment form', 'blt-optimized' ),
				'condition' => array(),
			),
			array(
				'id'        => 'font-awesome',
				'label'     => 'Font Awesome',
				'handles'   => array( 'font-awesome*', 'fontawesome*', 'fa-*' ),
				'component' => '',
				'tokens'    => array( 'fa-', '=fa', '=fas', '=far', '=fab', '=fal', '=fad' ),
				'ignore'    => array(),
				'contexts'  => array(),
				'without'   => __( 'any icons', 'blt-optimized' ),
				// Icons are used in templates, menus and widgets: no safe content rule.
				'condition' => array(),
			),
		);

		/**
		 * Filters the asset marker registry. Add entries to teach the scan about
		 * another plugin's assets (see asset_markers() for the fields).
		 *
		 * @param array[] $markers Markers.
		 */
		$markers = apply_filters( 'blt_optimized_asset_markers', $markers );

		return self::normalize_markers( is_array( $markers ) ? $markers : array() );
	}

	/**
	 * Diagnostics from checks that threw during the last analyze() call.
	 *
	 * @return string[]
	 */
	public static function last_errors() {
		return self::$errors;
	}

	/* ------------------------------------------------------------------ */
	/* Run plumbing                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Forget per-run memoised state.
	 *
	 * @return void
	 */
	private static function reset() {
		self::$thresholds   = null;
		self::$markers      = null;
		self::$tweaks       = null;
		self::$post_content = array();
		self::$errors       = array();
		self::$image_issues = 0;
	}

	/**
	 * Run one check, containing any failure.
	 *
	 * @param string  $check Method name.
	 * @param array[] $pages Prepared pages.
	 * @return array[] Findings.
	 */
	private static function guard( $check, array $pages ) {
		try {
			$result = self::$check( $pages );
			return is_array( $result ) ? $result : array();
		} catch ( \Throwable $e ) {
			self::$errors[] = $check . ': ' . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')';
			return array();
		}
	}

	/**
	 * Threshold by constant name, filterable.
	 *
	 * @param string $name Constant name.
	 * @return mixed
	 */
	private static function t( $name ) {
		if ( null === self::$thresholds ) {
			$all = array();
			foreach ( ( new ReflectionClass( __CLASS__ ) )->getConstants() as $key => $value ) {
				$all[ $key ] = $value;
			}

			/**
			 * Filters the thresholds used by the page findings.
			 *
			 * @param array $thresholds Constant name => value.
			 */
			$filtered         = apply_filters( 'blt_optimized_page_findings_thresholds', $all );
			self::$thresholds = is_array( $filtered ) ? $filtered : $all;
		}
		return isset( self::$thresholds[ $name ] ) ? self::$thresholds[ $name ] : constant( __CLASS__ . '::' . $name );
	}

	/**
	 * Normalise pages and attach per-page helper data under `_f`.
	 *
	 * @param array[] $pages Raw page reports.
	 * @return array[] List of prepared pages.
	 */
	private static function prepare( array $pages ) {
		$out = array();
		foreach ( array_values( $pages ) as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$url         = isset( $page['url'] ) ? (string) $page['url'] : '';
			$page['url'] = $url;

			$tokens = array();
			$html   = ( isset( $page['html'] ) && is_array( $page['html'] ) ) ? $page['html'] : array();
			foreach ( array( 'class_tokens', 'id_tokens' ) as $key ) {
				foreach ( ( isset( $html[ $key ] ) && is_array( $html[ $key ] ) ) ? $html[ $key ] : array() as $token ) {
					$token = strtolower( trim( (string) $token ) );
					if ( '' !== $token ) {
						$tokens[ $token ] = true;
					}
				}
			}

			$page['_f']           = array(
				'ref'    => self::page_ref( $url ),
				'tokens' => array_keys( $tokens ),
			);
			$page['_f']['assets'] = self::compute_assets( $page );
			$out[]                = $page;
		}
		return $out;
	}

	/**
	 * Whether the page itself failed to load (HTTP error / no response).
	 *
	 * @param array $page Page.
	 * @return bool
	 */
	private static function page_failed( $page ) {
		$status = self::v( $page, array( 'http', 'status' ) );
		if ( null === $status ) {
			return false;
		}
		$status = (int) $status;
		return $status >= 400 || ( 0 === $status && '' !== (string) self::v( $page, array( 'http', 'error' ), '' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Small helpers                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Null-safe nested read.
	 *
	 * @param mixed        $data    Array to read from.
	 * @param string|array $path    Key or key path.
	 * @param mixed        $default Default when any step is missing or null.
	 * @return mixed
	 */
	private static function v( $data, $path, $default = null ) {
		foreach ( (array) $path as $key ) {
			if ( ! is_array( $data ) || ! isset( $data[ $key ] ) ) {
				return $default;
			}
			$data = $data[ $key ];
		}
		return $data;
	}

	/**
	 * The page's profiler result, or null.
	 *
	 * @param array $page Page.
	 * @return array|null
	 */
	private static function profile( $page ) {
		return ( isset( $page['profile'] ) && is_array( $page['profile'] ) && ! empty( $page['profile'] ) ) ? $page['profile'] : null;
	}

	/**
	 * The page's HTML analysis (possibly empty).
	 *
	 * @param array $page Page.
	 * @return array
	 */
	private static function html( $page ) {
		return ( isset( $page['html'] ) && is_array( $page['html'] ) ) ? $page['html'] : array();
	}

	/**
	 * Short, readable reference for a page: its path (and query).
	 *
	 * @param string $url Page URL.
	 * @return string
	 */
	private static function page_ref( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ( ! isset( $parts['path'] ) && ! isset( $parts['host'] ) ) ) {
			return '' === $url ? '/' : $url;
		}
		$path  = isset( $parts['path'] ) ? $parts['path'] : '/';
		$query = isset( $parts['query'] ) ? $parts['query'] : '';
		if ( '' !== $query ) {
			parse_str( $query, $args );
			unset( $args['blt_optimized_profile'], $args['blt_nc'] );
			$query = http_build_query( $args );
		}
		return $path . ( '' !== $query ? '?' . $query : '' );
	}

	/**
	 * Reference of page index $i.
	 *
	 * @param array[] $pages Prepared pages.
	 * @param int     $i     Index.
	 * @return string
	 */
	private static function ref( array $pages, $i ) {
		return isset( $pages[ $i ]['_f']['ref'] ) ? $pages[ $i ]['_f']['ref'] : '';
	}

	/**
	 * URLs of the given page indexes (for a finding's `where`).
	 *
	 * @param array[] $pages   Prepared pages.
	 * @param int[]   $indexes Page indexes.
	 * @return string[]
	 */
	private static function urls( array $pages, array $indexes ) {
		$urls = array();
		foreach ( $indexes as $i ) {
			if ( isset( $pages[ $i ]['url'] ) && '' !== $pages[ $i ]['url'] ) {
				$urls[] = $pages[ $i ]['url'];
			}
		}
		return array_values( array_unique( $urls ) );
	}

	/**
	 * "5 pages".
	 *
	 * @param int $n Count.
	 * @return string
	 */
	private static function n_pages( $n ) {
		/* translators: %s: number of pages. */
		return sprintf( _n( '%s page', '%s pages', (int) $n, 'blt-optimized' ), number_format_i18n( (int) $n ) );
	}

	/**
	 * "840 ms" / "1.8 s".
	 *
	 * @param float $ms Milliseconds.
	 * @return string
	 */
	private static function fmt_ms( $ms ) {
		$ms = (float) $ms;
		if ( $ms >= 1000 ) {
			/* translators: %s: seconds. */
			return sprintf( __( '%s s', 'blt-optimized' ), number_format_i18n( $ms / 1000, 1 ) );
		}
		/* translators: %s: milliseconds. */
		return sprintf( __( '%s ms', 'blt-optimized' ), number_format_i18n( $ms ) );
	}

	/**
	 * Human file size.
	 *
	 * @param int|float $bytes Bytes.
	 * @return string
	 */
	private static function fmt_bytes( $bytes ) {
		$text = size_format( max( 0, (int) $bytes ), 1 );
		return false === $text ? '0 B' : $text;
	}

	/**
	 * Impact block for an estimated quantity (label gets a "~" … "(est.)").
	 *
	 * @param string    $type  ms|bytes|count.
	 * @param int|float $value Value.
	 * @return array
	 */
	private static function impact_est( $type, $value ) {
		return array(
			'type'  => $type,
			'value' => $value,
			/* translators: %s: an estimated amount such as "120 KB". */
			'label' => sprintf( __( '~%s (est.)', 'blt-optimized' ), 'bytes' === $type ? self::fmt_bytes( $value ) : self::fmt_ms( $value ) ),
		);
	}

	/**
	 * Collapse whitespace and truncate.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max characters.
	 * @return string
	 */
	private static function trunc( $text, $max = 300 ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max - 1 ) . '…' : $text;
		}
		return strlen( $text ) > $max ? substr( $text, 0, $max - 1 ) . '…' : $text;
	}

	/**
	 * Localised "a, b and c" list, capped.
	 *
	 * @param string[] $names Names.
	 * @param int      $max   Max names before "and N more".
	 * @return string
	 */
	private static function name_list( array $names, $max = 3 ) {
		$names = array_values( array_unique( array_filter( array_map( 'strval', $names ), 'strlen' ) ) );
		if ( count( $names ) > $max ) {
			$extra   = count( $names ) - $max;
			$names   = array_slice( $names, 0, $max );
			/* translators: %s: number of further items in a list. */
			$names[] = sprintf( _n( '%s more', '%s more', $extra, 'blt-optimized' ), number_format_i18n( $extra ) );
		}
		return wp_sprintf( '%l', $names );
	}

	/**
	 * Build a finding (source defaults to `profiler`).
	 *
	 * @param array $args Finding args.
	 * @return array
	 */
	private static function finding( array $args ) {
		if ( ! isset( $args['source'] ) ) {
			$args['source'] = 'profiler';
		}
		return BLT_Optimized_Findings::make( $args );
	}

	/**
	 * Manual fix block.
	 *
	 * @param string $label Button label.
	 * @param string $steps Plain-text steps.
	 * @return array
	 */
	private static function fix_manual( $label, $steps ) {
		return array(
			'type'  => 'manual',
			'label' => $label,
			'steps' => $steps,
		);
	}

	/**
	 * Link fix block.
	 *
	 * @param string $label Label.
	 * @param string $url   URL.
	 * @return array
	 */
	private static function fix_link( $label, $url ) {
		return array(
			'type'  => 'link',
			'label' => $label,
			'url'   => $url,
		);
	}

	/**
	 * One-click speed-tweak fix block.
	 *
	 * @param string $label   Label.
	 * @param string $key     Tweak key.
	 * @param mixed  $value   Tweak value.
	 * @param string $confirm Optional confirmation text.
	 * @return array
	 */
	private static function fix_tweak( $label, $key, $value, $confirm = '' ) {
		return array(
			'type'    => 'action',
			'label'   => $label,
			'action'  => 'tweak',
			'args'    => array(
				'key'   => $key,
				'value' => $value,
			),
			'confirm' => $confirm,
		);
	}

	/**
	 * Saved speed tweaks (empty array when the tweaks module is unavailable).
	 *
	 * @return array
	 */
	private static function tweaks() {
		if ( null === self::$tweaks ) {
			self::$tweaks = array();
			if ( class_exists( 'BLT_Optimized_Tweaks' ) && method_exists( 'BLT_Optimized_Tweaks', 'get' ) ) {
				try {
					$saved = BLT_Optimized_Tweaks::get();
					if ( is_array( $saved ) ) {
						self::$tweaks = $saved;
					}
				} catch ( \Throwable $e ) {
					self::$tweaks = array();
				}
			}
		}
		return self::$tweaks;
	}

	/**
	 * Whether a speed tweak is already saved with this value.
	 *
	 * @param string $key   Tweak key.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	private static function tweak_is( $key, $value ) {
		$tweaks = self::tweaks();
		return isset( $tweaks[ $key ] ) && (string) $tweaks[ $key ] === (string) $value;
	}

	/**
	 * Decide how a tweak-based finding should proceed.
	 *
	 * The tweak is already saved and every affected page was audited with
	 * tweaks switched off (a baseline run): the finding describes something
	 * already fixed, so it is dropped (returns false). The tweak is saved and
	 * the page was audited WITH tweaks, yet the problem is still there: the
	 * one-click fix would be a no-op, so the caller gets a manual fix instead
	 * (returns true = "already on, still happening"). Otherwise null: offer
	 * the tweak.
	 *
	 * @param string  $key     Tweak key.
	 * @param mixed   $value   Value that fixes the issue.
	 * @param array[] $pages   Prepared pages.
	 * @param int[]   $indexes Affected page indexes.
	 * @return bool|null
	 */
	private static function tweak_state( $key, $value, array $pages, array $indexes ) {
		if ( ! self::tweak_is( $key, $value ) ) {
			return null;
		}
		foreach ( $indexes as $i ) {
			if ( empty( $pages[ $i ]['tweaks_off'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether WordPress supports the script loading-strategy API (6.3+).
	 *
	 * @return bool
	 */
	private static function supports_defer() {
		return version_compare( get_bloginfo( 'version' ), '6.3', '>=' );
	}

	/**
	 * Whether the optional image-optimization module is enabled.
	 *
	 * @return bool
	 */
	private static function images_enabled() {
		if ( ! class_exists( 'BLT_Optimized' ) || ! method_exists( 'BLT_Optimized', 'get_settings' ) ) {
			return false;
		}
		$settings = BLT_Optimized::get_settings();
		return ! empty( $settings['enable_images'] );
	}

	/* ------------------------------------------------------------------ */
	/* Component helpers                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * A well-formed component array from whatever the report contained.
	 *
	 * @param mixed $component Component or null.
	 * @return array
	 */
	private static function comp( $component ) {
		if ( ! is_array( $component ) || ( empty( $component['slug'] ) && empty( $component['name'] ) ) ) {
			return BLT_Optimized_Components::make( 'other', 'unknown', __( 'Unknown', 'blt-optimized' ) );
		}
		return BLT_Optimized_Components::make(
			isset( $component['type'] ) ? $component['type'] : 'other',
			isset( $component['slug'] ) ? $component['slug'] : '',
			isset( $component['name'] ) && '' !== $component['name'] ? $component['name'] : ( isset( $component['slug'] ) ? $component['slug'] : '' )
		);
	}

	/**
	 * Plain name of a component.
	 *
	 * @param array $component Component.
	 * @return string
	 */
	private static function comp_name( $component ) {
		$component = self::comp( $component );
		return '' !== $component['name'] ? $component['name'] : $component['slug'];
	}

	/**
	 * Component as the subject of a sentence: "WooCommerce", "The Twenty
	 * Twenty-Four theme", "WordPress core".
	 *
	 * @param array $component Component.
	 * @return string
	 */
	private static function comp_subject( $component ) {
		$component = self::comp( $component );
		$name      = self::comp_name( $component );
		switch ( $component['type'] ) {
			case 'theme':
				/* translators: %s: theme name. */
				return sprintf( __( 'The %s theme', 'blt-optimized' ), $name );
			case 'mu-plugin':
				/* translators: %s: must-use plugin name. */
				return sprintf( __( 'The %s must-use plugin', 'blt-optimized' ), $name );
			case 'dropin':
				/* translators: %s: drop-in file name. */
				return sprintf( __( 'The %s drop-in', 'blt-optimized' ), $name );
		}
		return $name;
	}

	/**
	 * Whether the component is WordPress core.
	 *
	 * @param array $component Component.
	 * @return bool
	 */
	private static function is_core( $component ) {
		return is_array( $component ) && isset( $component['type'] ) && 'core' === $component['type'];
	}

	/**
	 * Whether the component is this plugin (excluded from blame: its own
	 * instrumentation inflates the numbers it would be blamed for).
	 *
	 * @param array $component Component.
	 * @return bool
	 */
	private static function is_self( $component ) {
		return is_array( $component ) && isset( $component['slug'] ) && in_array( $component['slug'], array( 'blt-optimized', 'blt-optimizer' ), true );
	}

	/**
	 * Per-component cost on one profiled page: plugin load, query time/count
	 * and blocking HTTP time. Keyed by component key.
	 *
	 * @param array $profile Profiler result.
	 * @return array[] key => { component, load_ms, load_mem, query_ms, query_count, http_ms, total }.
	 */
	private static function component_costs( array $profile ) {
		$costs = array();
		$add   = static function ( &$costs, $component ) {
			$component = self::comp( $component );
			$key       = BLT_Optimized_Components::key( $component );
			if ( ! isset( $costs[ $key ] ) ) {
				$costs[ $key ] = array(
					'component'   => $component,
					'load_ms'     => 0.0,
					'load_mem'    => 0,
					'query_ms'    => 0.0,
					'query_count' => 0,
					'http_ms'     => 0.0,
					'total'       => 0.0,
				);
			}
			return $key;
		};

		foreach ( (array) self::v( $profile, 'components_load', array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key                        = $add( $costs, isset( $row['component'] ) ? $row['component'] : null );
			$costs[ $key ]['load_ms']  += (float) ( isset( $row['ms'] ) ? $row['ms'] : 0 );
			$costs[ $key ]['load_mem'] += (int) ( isset( $row['memory'] ) ? $row['memory'] : 0 );
		}
		foreach ( (array) self::v( $profile, array( 'queries', 'by_component' ), array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key                           = $add( $costs, isset( $row['component'] ) ? $row['component'] : null );
			$costs[ $key ]['query_ms']    += (float) ( isset( $row['time_ms'] ) ? $row['time_ms'] : 0 );
			$costs[ $key ]['query_count'] += (int) ( isset( $row['count'] ) ? $row['count'] : 0 );
		}
		foreach ( (array) self::v( $profile, 'http', array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key                       = $add( $costs, isset( $row['component'] ) ? $row['component'] : null );
			$costs[ $key ]['http_ms'] += (float) ( isset( $row['ms'] ) ? $row['ms'] : 0 );
		}
		foreach ( $costs as $key => $cost ) {
			$costs[ $key ]['total'] = $cost['load_ms'] + $cost['query_ms'] + $cost['http_ms'];
		}
		return $costs;
	}

	/**
	 * The component that costs the most on a profiled page (WordPress core only
	 * when nothing else registers).
	 *
	 * @param array $profile Profiler result.
	 * @return array|null Cost row or null.
	 */
	private static function top_cost( array $profile ) {
		$best     = null;
		$core     = null;
		foreach ( self::component_costs( $profile ) as $cost ) {
			if ( self::is_self( $cost['component'] ) || $cost['total'] <= 0 ) {
				continue;
			}
			if ( self::is_core( $cost['component'] ) ) {
				if ( null === $core || $cost['total'] > $core['total'] ) {
					$core = $cost;
				}
				continue;
			}
			if ( null === $best || $cost['total'] > $best['total'] ) {
				$best = $cost;
			}
		}
		return null !== $best ? $best : $core;
	}

	/**
	 * Initial rollup row (or the existing one) for component_summary().
	 *
	 * @param array  $rollup    Rollup so far.
	 * @param string $key       Component key.
	 * @param array  $component Component.
	 * @return array
	 */
	private static function rollup_row( array $rollup, $key, $component ) {
		if ( isset( $rollup[ $key ] ) ) {
			return $rollup[ $key ];
		}
		return array(
			'component'   => self::comp( $component ),
			'load_ms'     => 0.0,
			'query_ms'    => 0.0,
			'query_count' => 0,
			'http_ms'     => 0.0,
			'js_bytes'    => 0,
			'css_bytes'   => 0,
			'errors'      => 0,
			'pages'       => 0,
		);
	}

	/**
	 * Label of a post for sentence use: Post “Summer sale”.
	 *
	 * @param array $post Page report `post` block.
	 * @return string
	 */
	private static function post_label( $post ) {
		$title = trim( wp_strip_all_tags( (string) self::v( $post, 'title', '' ) ) );
		if ( '' === $title ) {
			$title = __( '(no title)', 'blt-optimized' );
		}
		$title = self::trunc( $title, 60 );
		$type  = (string) self::v( $post, 'type', 'post' );
		$noun  = ucfirst( $type );
		$obj   = get_post_type_object( $type );
		if ( $obj && ! empty( $obj->labels->singular_name ) ) {
			$noun = $obj->labels->singular_name;
		}
		/* translators: 1: content type (Post, Page, Product), 2: its title. */
		return sprintf( __( '%1$s “%2$s”', 'blt-optimized' ), $noun, $title );
	}

	/**
	 * The theme/template that rendered a page, for "template X" attribution.
	 * Block themes render through core's template-canvas.php, which says nothing
	 * useful, so those return ''.
	 *
	 * @param array $page Page.
	 * @return string File name or ''.
	 */
	private static function template_name( $page ) {
		$template = (string) self::v( $page, array( 'profile', 'context', 'template' ), '' );
		if ( '' === $template || false !== strpos( $template, 'template-canvas.php' ) ) {
			return '';
		}
		return basename( $template );
	}

	/**
	 * Raw post_content for a page's queried post (memoised), '' when unknown.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	private static function content_of( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || ! function_exists( 'get_post_field' ) ) {
			return '';
		}
		if ( ! isset( self::$post_content[ $post_id ] ) ) {
			$content = get_post_field( 'post_content', $post_id, 'raw' );
			self::$post_content[ $post_id ] = is_string( $content ) ? $content : '';
		}
		return self::$post_content[ $post_id ];
	}

	/**
	 * Post id a page was rendered for (0 for archives and the like).
	 *
	 * @param array $page Page.
	 * @return int
	 */
	private static function post_id_of( $page ) {
		$id = (int) self::v( $page, array( 'post', 'id' ), 0 );
		if ( $id <= 0 && self::v( $page, array( 'profile', 'context', 'conditionals', 'is_singular' ), false ) ) {
			$id = (int) self::v( $page, array( 'profile', 'context', 'object_id' ), 0 );
		}
		return $id;
	}

	/**
	 * Whether a page is a WooCommerce page: true/false, or null when unknown
	 * (no profile and a sample type that says nothing).
	 *
	 * @param array $page Page.
	 * @return bool|null
	 */
	private static function is_woo_page( $page ) {
		$conditionals = self::v( $page, array( 'profile', 'context', 'conditionals' ) );
		if ( is_array( $conditionals ) ) {
			foreach ( array( 'is_woocommerce', 'is_cart', 'is_checkout', 'is_account_page' ) as $key ) {
				if ( ! empty( $conditionals[ $key ] ) ) {
					return true;
				}
			}
			return false;
		}
		$type = (string) self::v( $page, 'type', '' );
		if ( in_array( $type, array( 'shop', 'product' ), true ) ) {
			return true;
		}
		return null;
	}

	/* ------------------------------------------------------------------ */
	/* Server checks                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Pages that returned an HTTP error or no response at all.
	 *
	 * @param array[] $pages Prepared pages (including failed ones).
	 * @return array[]
	 */
	private static function check_page_errors( array $pages ) {
		$bad   = array();
		$rows  = array();
		$worst = 0;
		foreach ( $pages as $i => $page ) {
			if ( ! self::page_failed( $page ) ) {
				continue;
			}
			$status  = (int) self::v( $page, array( 'http', 'status' ), 0 );
			$error   = (string) self::v( $page, array( 'http', 'error' ), '' );
			$worst   = max( $worst, $status );
			$bad[]   = $i;
			$rows[]  = array(
				self::ref( $pages, $i ),
				$status > 0 ? (string) $status : __( 'No response', 'blt-optimized' ),
				'' !== $error ? self::trunc( $error, 160 ) : '',
			);
		}
		if ( ! $bad ) {
			return array();
		}

		$count = count( $bad );
		if ( 1 === $count ) {
			/* translators: 1: page path, 2: HTTP status or "no response". */
			$title = sprintf( __( '%1$s fails to load (%2$s)', 'blt-optimized' ), self::ref( $pages, $bad[0] ), $worst > 0 ? 'HTTP ' . $worst : __( 'no response', 'blt-optimized' ) );
		} else {
			/* translators: 1: number of pages, 2: worst HTTP status or "no response". */
			$title = sprintf( __( '%1$s fail to load (worst: %2$s)', 'blt-optimized' ), self::n_pages( $count ), $worst > 0 ? 'HTTP ' . $worst : __( 'no response', 'blt-optimized' ) );
		}

		return array(
			self::finding(
				array(
					'id'       => 'server.page_error',
					'key'      => 'server.page_error',
					'category' => 'server',
					'severity' => ( $worst >= 500 || 0 === $worst ) ? 'critical' : 'warning',
					'title'    => $title,
					'summary'  => __( 'The scan asked for these pages and the server answered with an error, so visitors and search engines hit the same failure. They are left out of the other findings because their markup says nothing about the real page.', 'blt-optimized' ),
					'impact'   => array(
						'type'  => 'count',
						'value' => $count,
						'label' => '',
					),
					'where'    => self::urls( $pages, $bad ),
					'details'  => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Status', 'blt-optimized' ), __( 'Error', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => self::fix_manual(
						__( 'How to fix', 'blt-optimized' ),
						__( "1. Open the page in a private browser window and confirm it fails for visitors too.\n2. Turn on WP_DEBUG_LOG in wp-config.php and read wp-content/debug.log for the PHP error behind it.\n3. If it only fails during the scan, a security plugin, firewall or password protection may be blocking the scan's own request.", 'blt-optimized' )
					),
				)
			),
		);
	}

	/**
	 * Pages whose server-side profile could not be read (explains missing
	 * query/plugin findings instead of leaving the user wondering).
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_profile_unavailable( array $pages ) {
		$reasons = array(
			'page_cache'   => __( 'Answered by a page cache, so PHP never ran and nothing could be profiled', 'blt-optimized' ),
			'not_received' => __( 'The profiler did not report back (a firewall, security plugin or timeout may have blocked it)', 'blt-optimized' ),
			'http_error'   => __( 'The page returned an HTTP error', 'blt-optimized' ),
		);
		$idx     = array();
		$rows    = array();
		foreach ( $pages as $i => $page ) {
			$error = (string) self::v( $page, 'profile_error', '' );
			if ( self::profile( $page ) || '' === $error ) {
				continue;
			}
			$idx[]  = $i;
			$rows[] = array( self::ref( $pages, $i ), isset( $reasons[ $error ] ) ? $reasons[ $error ] : $error );
		}
		if ( ! $idx ) {
			return array();
		}

		/* translators: %s: "3 pages". */
		$title = sprintf( __( 'Server-side profiling was not possible on %s, so plugin, query and HTTP findings are missing for them', 'blt-optimized' ), self::n_pages( count( $idx ) ) );
		return array(
			self::finding(
				array(
					'id'       => 'server.profile_unavailable',
					'key'      => 'server.profile_unavailable',
					'category' => 'server',
					'severity' => 'notice',
					'title'    => $title,
					'summary'  => __( 'The HTML of these pages was still analysed, but the scan could not look inside the PHP request to see which plugin is slow or which queries run. Fixing the cause gives you the full Query-Monitor-style breakdown.', 'blt-optimized' ),
					'impact'   => array(
						'type'  => 'count',
						'value' => count( $idx ),
						'label' => '',
					),
					'where'    => self::urls( $pages, $idx ),
					'details'  => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Why', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => self::fix_manual(
						__( 'How to fix', 'blt-optimized' ),
						__( "If a page cache answered: exclude URLs containing the query argument blt_optimized_profile from caching (or purge/disable the cache while you scan).\nIf the profiler never reported back: check that your firewall or security plugin allows this site to request its own pages (loopback requests), then scan again.", 'blt-optimized' )
					),
				)
			),
		);
	}

	/**
	 * PHP generation time per page (the profiler's total_ms).
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_generation_time( array $pages ) {
		$warn     = (float) self::t( 'GENERATION_WARN_MS' );
		$crit     = (float) self::t( 'GENERATION_CRIT_MS' );
		$measured = array();
		$slow     = array();
		foreach ( $pages as $i => $page ) {
			$profile = self::profile( $page );
			if ( ! $profile || ! isset( $profile['timing']['total_ms'] ) ) {
				continue;
			}
			$ms             = (float) $profile['timing']['total_ms'];
			$measured[ $i ] = $ms;
			if ( $ms > $warn ) {
				$slow[ $i ] = $ms;
			}
		}
		if ( ! $measured ) {
			return array();
		}

		if ( ! $slow ) {
			$average = array_sum( $measured ) / count( $measured );
			return array(
				self::finding(
					array(
						'id'       => 'server.response_time',
						'key'      => 'server.response_time',
						'category' => 'server',
						'severity' => 'pass',
						/* translators: 1: average milliseconds, 2: number of pages. */
						'title'    => sprintf( __( 'Pages are generated quickly (about %1$s on average across %2$s)', 'blt-optimized' ), self::fmt_ms( $average ), self::n_pages( count( $measured ) ) ),
						'summary'  => __( 'PHP builds each sampled page in well under the 600 ms target, so the server is not holding visitors back.', 'blt-optimized' ),
						'impact'   => array(
							'type'  => 'ms',
							'value' => round( $average ),
							'label' => '',
						),
					)
				),
			);
		}

		arsort( $slow );
		$worst_i  = (int) key( $slow );
		$worst_ms = (float) reset( $slow );
		$rows     = array();
		$blame    = array();
		foreach ( $slow as $i => $ms ) {
			$profile = self::profile( $pages[ $i ] );
			$queries = self::v( $profile, array( 'queries', 'count' ), 0 );
			$qtime   = self::v( $profile, array( 'queries', 'time_ms' ), null );
			$top     = self::top_cost( $profile );
			if ( $top ) {
				$key           = BLT_Optimized_Components::key( $top['component'] );
				$blame[ $key ] = isset( $blame[ $key ] ) ? $blame[ $key ] : array( 'name' => self::comp_name( $top['component'] ), 'ms' => 0 );
				$blame[ $key ]['ms'] += $top['total'];
			}
			$rows[] = array(
				self::ref( $pages, $i ),
				self::fmt_ms( $ms ),
				self::fmt_ms( (float) self::v( $profile, array( 'timing', 'wp_loaded_ms' ), 0 ) ),
				self::fmt_ms( (float) self::v( $profile, array( 'timing', 'render_ms' ), 0 ) ),
				null !== $qtime ? sprintf( '%s / %s', number_format_i18n( (int) $queries ), self::fmt_ms( (float) $qtime ) ) : number_format_i18n( (int) $queries ),
				$top ? sprintf( '%1$s — %2$s', self::comp_name( $top['component'] ), self::fmt_ms( $top['total'] ) ) : '',
			);
		}
		uasort(
			$blame,
			static function ( $a, $b ) {
				return $b['ms'] <=> $a['ms'];
			}
		);
		$blame_names = array_slice( wp_list_pluck( $blame, 'name' ), 0, 3 );

		$worst_ref = self::ref( $pages, $worst_i );
		if ( 1 === count( $slow ) ) {
			/* translators: 1: page path, 2: time such as "1.8 s". */
			$title = sprintf( __( '%1$s takes %2$s to generate on the server', 'blt-optimized' ), $worst_ref, self::fmt_ms( $worst_ms ) );
		} else {
			/* translators: 1: number of pages, 2: threshold, 3: slowest page path, 4: its time. */
			$title = sprintf( __( '%1$s take over %2$s to generate on the server (slowest: %3$s at %4$s)', 'blt-optimized' ), self::n_pages( count( $slow ) ), self::fmt_ms( $warn ), $worst_ref, self::fmt_ms( $worst_ms ) );
		}

		$top_worst = self::top_cost( self::profile( $pages[ $worst_i ] ) );
		/* translators: %s: time such as "1.8 s". */
		$summary = sprintf( __( 'PHP needs %s to build the slowest page before the browser receives a single byte, so every visitor waits at least that long.', 'blt-optimized' ), self::fmt_ms( $worst_ms ) );
		if ( $top_worst ) {
			/* translators: 1: component name, 2: time. */
			$summary .= ' ' . sprintf( __( 'The biggest contributor on that page is %1$s (about %2$s of plugin load, queries and remote calls).', 'blt-optimized' ), self::comp_name( $top_worst['component'] ), self::fmt_ms( $top_worst['total'] ) );
		}
		$summary .= ' ' . __( 'Page caching hides this cost for repeat visits; fixing the slow components below removes it for good.', 'blt-optimized' );
		if ( self::v( self::profile( $pages[ $worst_i ] ), array( 'env', 'savequeries_forced' ), false ) ) {
			$summary .= ' ' . __( '(Measured with query logging switched on, so real-world times are a little lower.)', 'blt-optimized' );
		}

		$steps = __( "1. Turn on page caching so most visitors never wait for PHP (see the page cache finding if one is listed).\n2. Fix the components named in the table, starting with the biggest contributor — their own findings below list the slow queries, load times and remote calls.\n3. Make sure OPcache and a persistent object cache (Redis/Memcached) are enabled in the server checks.\n4. Scan again to confirm the time drops.", 'blt-optimized' );
		if ( $blame_names ) {
			/* translators: %s: list of plugin names. */
			$steps = sprintf( __( "Start with: %s.\n", 'blt-optimized' ), self::name_list( $blame_names, 3 ) ) . $steps;
		}

		return array(
			self::finding(
				array(
					'id'       => 'server.high_ttfb',
					'key'      => 'server.high_ttfb',
					'category' => 'server',
					'severity' => $worst_ms > $crit ? 'critical' : 'warning',
					'title'    => $title,
					'summary'  => $summary,
					'impact'   => array(
						'type'  => 'ms',
						'value' => (int) round( $worst_ms ),
						'label' => '',
					),
					'component' => $top_worst ? $top_worst['component'] : null,
					'where'    => self::urls( $pages, array_keys( $slow ) ),
					'details'  => array(
						'columns' => array(
							__( 'Page', 'blt-optimized' ),
							__( 'PHP time', 'blt-optimized' ),
							__( 'Until WordPress loaded', 'blt-optimized' ),
							__( 'Rendering the page', 'blt-optimized' ),
							__( 'Queries / time', 'blt-optimized' ),
							__( 'Biggest contributor', 'blt-optimized' ),
						),
						'rows'    => $rows,
					),
					'fix'      => self::fix_manual( __( 'How to speed this up', 'blt-optimized' ), $steps ),
				)
			),
		);
	}

	/**
	 * Is a page cache serving the front page?
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_page_cache( array $pages ) {
		$front = null;
		foreach ( $pages as $i => $page ) {
			if ( 'front_page' === self::v( $page, 'type', '' ) ) {
				$front = $i;
				break;
			}
		}
		if ( null === $front ) {
			foreach ( $pages as $i => $page ) {
				if ( '/' === self::ref( $pages, $i ) ) {
					$front = $i;
					break;
				}
			}
		}
		if ( null === $front ) {
			return array();
		}
		$info = self::v( $pages[ $front ], array( 'http', 'page_cache' ) );
		if ( ! is_array( $info ) || ! array_key_exists( 'detected', $info ) ) {
			return array();
		}

		if ( ! empty( $info['detected'] ) ) {
			$evidence = (string) self::v( $info, 'evidence', '' );
			return array(
				self::finding(
					array(
						'id'       => 'server.page_cache',
						'key'      => 'server.page_cache',
						'category' => 'server',
						'severity' => 'pass',
						'title'    => '' !== $evidence
							/* translators: %s: evidence such as "x-cache: HIT". */
							? sprintf( __( 'Page caching is active (%s)', 'blt-optimized' ), self::trunc( $evidence, 80 ) )
							: __( 'Page caching is active', 'blt-optimized' ),
						'summary'  => __( 'Repeat visitors are served a saved copy of the front page instead of waiting for PHP and the database.', 'blt-optimized' ),
					)
				),
			);
		}

		$ttfb = self::v( $pages[ $front ], array( 'http', 'ttfb_ms' ) );
		if ( null === $ttfb ) {
			$ttfb = self::v( $pages[ $front ], array( 'http', 'total_ms' ), 0 );
		}
		$ttfb = (float) $ttfb;

		$rows = array();
		$idx  = array();
		foreach ( $pages as $i => $page ) {
			if ( ! self::is_cacheable_page( $page ) ) {
				continue;
			}
			$pc = self::v( $page, array( 'http', 'page_cache' ) );
			if ( ! is_array( $pc ) || ! empty( $pc['detected'] ) ) {
				continue;
			}
			$idx[]  = $i;
			$cached = self::v( $page, array( 'http', 'cached_ttfb_ms' ) );
			$rows[] = array(
				self::ref( $pages, $i ),
				__( 'Not detected', 'blt-optimized' ),
				self::fmt_ms( (float) ( null !== self::v( $page, array( 'http', 'ttfb_ms' ) ) ? self::v( $page, array( 'http', 'ttfb_ms' ) ) : self::v( $page, array( 'http', 'total_ms' ), 0 ) ) ),
				null !== $cached ? self::fmt_ms( (float) $cached ) : '',
			);
		}

		/* translators: %s: time to first byte such as "780 ms". */
		$title = sprintf( __( 'No page cache detected: the front page is rebuilt from scratch on every visit (server response %s)', 'blt-optimized' ), self::fmt_ms( $ttfb ) );

		return array(
			self::finding(
				array(
					'id'       => 'server.page_cache',
					'key'      => 'server.page_cache',
					'category' => 'server',
					'severity' => $ttfb >= (float) self::t( 'PAGE_CACHE_NOTICE_BELOW_MS' ) ? 'warning' : 'notice',
					'title'    => $title,
					'summary'  => __( 'Without a page cache every visit runs PHP and queries the database to rebuild a page that rarely changes. A page cache serves a saved copy instead, typically cutting the server response to well under 100 ms and letting a small server cope with far more visitors.', 'blt-optimized' ),
					'impact'   => array(
						'type'  => 'ms',
						'value' => (int) round( $ttfb ),
						'label' => '',
					),
					'where'    => self::urls( $pages, $idx ? $idx : array( $front ) ),
					'details'  => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Page cache', 'blt-optimized' ), __( 'Server response', 'blt-optimized' ), __( 'Second request', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => self::fix_manual(
						__( 'Enable page caching', 'blt-optimized' ),
						__( "1. Check whether your host already offers a page cache (Kinsta, WP Engine, SiteGround, Cloudways and most managed hosts do) and switch it on in the host's dashboard.\n2. Otherwise install one caching plugin (for example WP Super Cache, W3 Total Cache, LiteSpeed Cache or WP Rocket) and enable page caching — only one, never two.\n3. Scan again: the response time of the front page should drop to well under 200 ms.", 'blt-optimized' )
					),
				)
			),
		);
	}

	/**
	 * Pages that should be served from a page cache (not search, cart, etc.).
	 *
	 * @param array $page Page.
	 * @return bool
	 */
	private static function is_cacheable_page( $page ) {
		if ( in_array( (string) self::v( $page, 'type', '' ), array( 'search', 'cart', 'checkout', 'account' ), true ) ) {
			return false;
		}
		$conditionals = self::v( $page, array( 'profile', 'context', 'conditionals' ), array() );
		foreach ( array( 'is_search', 'is_404', 'is_cart', 'is_checkout', 'is_account_page' ) as $key ) {
			if ( ! empty( $conditionals[ $key ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Responses sent without gzip/brotli.
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_compression( array $pages ) {
		$min  = (int) self::t( 'COMPRESSION_MIN_BYTES' );
		$idx  = array();
		$rows = array();
		$ok   = array();
		$worst = 0;
		foreach ( $pages as $i => $page ) {
			$bytes = (int) self::v( $page, array( 'http', 'bytes' ), 0 );
			if ( $bytes <= $min ) {
				continue;
			}
			$encoding = strtolower( trim( (string) self::v( $page, array( 'http', 'encoding' ), '' ) ) );
			$transfer = self::v( $page, array( 'http', 'transfer_bytes' ) );
			// The transfer size is the cross-check: far below the body size means something compressed it.
			$compressed = ( '' !== $encoding && 'identity' !== $encoding ) || ( null !== $transfer && (int) $transfer > 0 && (int) $transfer < $bytes * 0.85 );
			if ( $compressed ) {
				$ok[ $i ] = '' !== $encoding ? $encoding : 'compressed';
				continue;
			}
			$idx[]  = $i;
			$worst  = max( $worst, $bytes );
			$rows[] = array(
				self::ref( $pages, $i ),
				self::fmt_bytes( $bytes ),
				self::fmt_bytes( (int) round( $bytes * (float) self::t( 'COMPRESSION_SAVING_RATIO' ) ) ),
			);
		}

		if ( ! $idx ) {
			if ( ! $ok ) {
				return array();
			}
			$encodings = array_values( array_unique( array_values( $ok ) ) );
			return array(
				self::finding(
					array(
						'id'       => 'server.compression',
						'key'      => 'server.compression',
						'category' => 'server',
						'severity' => 'pass',
						/* translators: %s: compression method such as "gzip". */
						'title'    => sprintf( __( 'Responses are compressed (%s)', 'blt-optimized' ), implode( ', ', $encodings ) ),
						'summary'  => __( 'The server shrinks HTML before sending it, so pages download several times faster on slow connections.', 'blt-optimized' ),
					)
				),
			);
		}

		$saving = (int) round( $worst * (float) self::t( 'COMPRESSION_SAVING_RATIO' ) );
		/* translators: 1: number of pages, 2: size of the largest page. */
		$title = sprintf( __( 'The server sends %1$s uncompressed (largest %2$s of HTML)', 'blt-optimized' ), self::n_pages( count( $idx ) ), self::fmt_bytes( $worst ) );

		return array(
			self::finding(
				array(
					'id'       => 'server.compression',
					'key'      => 'server.compression',
					'category' => 'server',
					'severity' => 'warning',
					'title'    => $title,
					'summary'  => __( 'Text such as HTML, CSS and JavaScript compresses by about 70%, but this server sends it as-is, so every visitor downloads roughly three times more than necessary — the difference is most visible on mobile connections.', 'blt-optimized' ),
					'impact'   => self::impact_est( 'bytes', $saving ),
					'where'    => self::urls( $pages, $idx ),
					'details'  => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'HTML size', 'blt-optimized' ), __( 'Estimated saving', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => self::fix_manual(
						__( 'Enable gzip/brotli', 'blt-optimized' ),
						__( "Ask your host to enable gzip or brotli compression, or add it yourself:\n\nApache (.htaccess):\n<IfModule mod_deflate.c>\nAddOutputFilterByType DEFLATE text/html text/css application/javascript application/json image/svg+xml\n</IfModule>\n\nNginx (server block):\ngzip on;\ngzip_types text/css application/javascript application/json image/svg+xml;\n\nIf you use Cloudflare or another CDN, compression is usually on by default — check it is enabled for the origin response too.", 'blt-optimized' )
					),
				)
			),
		);
	}

	/**
	 * Static files (CSS/JS) served without long browser-cache headers.
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_static_cache( array $pages ) {
		$files = array();
		$any   = false;
		foreach ( $pages as $i => $page ) {
			foreach ( (array) self::v( $page, array( 'http', 'static_cache' ), array() ) as $result ) {
				if ( ! is_array( $result ) || empty( $result['url'] ) || 200 !== (int) self::v( $result, 'status', 0 ) ) {
					continue;
				}
				$any = true;
				$key = strtok( (string) $result['url'], '?' );
				if ( ! isset( $files[ $key ] ) ) {
					$files[ $key ] = array(
						'result' => $result,
						'pages'  => array(),
					);
				}
				$files[ $key ]['pages'][ $i ] = true;
			}
		}
		if ( ! $any ) {
			return array();
		}

		$bad = array();
		foreach ( $files as $key => $file ) {
			if ( empty( $file['result']['ok'] ) ) {
				$bad[ $key ] = $file;
			}
		}

		if ( ! $bad ) {
			return array(
				self::finding(
					array(
						'id'       => 'server.static_cache',
						'key'      => 'server.static_cache',
						'category' => 'server',
						'severity' => 'pass',
						'title'    => __( 'Static files are cached by browsers for a week or more', 'blt-optimized' ),
						'summary'  => __( 'Returning visitors reuse the CSS and JavaScript they already downloaded instead of fetching it again.', 'blt-optimized' ),
					)
				),
			);
		}

		$sizes = array();
		foreach ( $pages as $page ) {
			foreach ( self::page_assets( $page ) as $asset ) {
				if ( '' !== $asset['src'] && $asset['bytes'] > 0 ) {
					$sizes[ strtok( $asset['src'], '?' ) ] = $asset['bytes'];
				}
			}
		}

		$rows  = array();
		$bytes = 0;
		$idx   = array();
		$comps = array();
		foreach ( $bad as $key => $file ) {
			$result  = $file['result'];
			$max_age = self::v( $result, 'max_age' );
			$comp    = BLT_Optimized_Components::from_url( $key );
			$size    = isset( $sizes[ $key ] ) ? $sizes[ $key ] : 0;
			$bytes  += $size;
			$idx     = array_merge( $idx, array_keys( $file['pages'] ) );
			$comps[] = self::comp_name( $comp );
			$rows[]  = array(
				basename( (string) wp_parse_url( $key, PHP_URL_PATH ) ),
				self::comp_name( $comp ),
				'' !== (string) self::v( $result, 'cache_control', '' ) ? self::trunc( (string) $result['cache_control'], 60 ) : __( '(none)', 'blt-optimized' ),
				null !== $max_age && (int) $max_age > 0 ? human_time_diff( 0, (int) $max_age ) : __( 'none', 'blt-optimized' ),
				$size > 0 ? self::fmt_bytes( $size ) : '',
			);
		}

		$count = count( $bad );
		$first = basename( (string) wp_parse_url( (string) key( $bad ), PHP_URL_PATH ) );
		$title = 1 === $count
			/* translators: %s: file name. */
			? sprintf( __( '%s is served without a long browser-cache lifetime', 'blt-optimized' ), $first )
			/* translators: 1: number of files, 2: example file name. */
			: sprintf( __( '%1$s static files are served without a long browser-cache lifetime (for example %2$s)', 'blt-optimized' ), number_format_i18n( $count ), $first );

		return array(
			self::finding(
				array(
					'id'       => 'server.static_cache',
					'key'      => 'server.static_cache',
					'category' => 'server',
					'severity' => 'warning',
					'title'    => $title,
					'summary'  => __( 'Without a far-future Cache-Control or Expires header, browsers re-check or re-download CSS and JavaScript on every visit. Long-lived caching makes repeat page views noticeably faster and lighter on the server.', 'blt-optimized' ),
					'impact'   => $bytes > 0
						? array(
							'type'  => 'bytes',
							'value' => $bytes,
							'label' => '',
						)
						: array(
							'type'  => 'count',
							'value' => $count,
							'label' => '',
						),
					'where'    => self::urls( $pages, array_unique( $idx ) ),
					'details'  => array(
						'columns' => array( __( 'File', 'blt-optimized' ), __( 'Owner', 'blt-optimized' ), __( 'Cache-Control', 'blt-optimized' ), __( 'Cached for', 'blt-optimized' ), __( 'Size', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => self::fix_manual(
						__( 'Set long cache headers', 'blt-optimized' ),
						__( "Tell the web server to cache static files for a year (WordPress adds ?ver= so updates still reach visitors).\n\nApache (.htaccess):\n<IfModule mod_expires.c>\nExpiresActive On\nExpiresByType text/css \"access plus 1 year\"\nExpiresByType application/javascript \"access plus 1 year\"\nExpiresByType image/webp \"access plus 1 year\"\nExpiresByType font/woff2 \"access plus 1 year\"\n</IfModule>\n\nNginx:\nlocation ~* \\.(css|js|png|jpe?g|gif|webp|avif|svg|woff2?)$ { expires 1y; add_header Cache-Control \"public, immutable\"; }\n\nManaged hosts and CDNs usually expose this as a \"browser cache TTL\" setting.", 'blt-optimized' )
					),
				)
			),
		);
	}

	/**
	 * Query volume and total query time per page.
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_query_volume( array $pages ) {
		$findings  = array();
		$warn      = (int) self::t( 'QUERY_COUNT_WARN' );
		$crit      = (int) self::t( 'QUERY_COUNT_CRIT' );
		$time_warn = (float) self::t( 'QUERY_TIME_WARN_MS' );
		$many      = array();
		$slow      = array();

		foreach ( $pages as $i => $page ) {
			$profile = self::profile( $page );
			if ( ! $profile ) {
				continue;
			}
			$count = (int) self::v( $profile, array( 'queries', 'count' ), 0 );
			if ( $count > $warn ) {
				$many[ $i ] = $count;
			}
			if ( false !== self::v( $profile, array( 'queries', 'tracked' ), true ) ) {
				$time = (float) self::v( $profile, array( 'queries', 'time_ms' ), 0 );
				if ( $time > $time_warn ) {
					$slow[ $i ] = $time;
				}
			}
		}

		if ( $many ) {
			arsort( $many );
			$worst_i = (int) key( $many );
			$worst   = (int) reset( $many );
			$rows    = array();
			foreach ( $many as $i => $count ) {
				$profile = self::profile( $pages[ $i ] );
				$types   = array();
				foreach ( (array) self::v( $profile, array( 'queries', 'by_type' ), array() ) as $type => $n ) {
					if ( (int) $n > 0 ) {
						$types[] = $type . ' ' . number_format_i18n( (int) $n );
					}
				}
				$top    = self::top_query_component( $profile );
				$rows[] = array(
					self::ref( $pages, $i ),
					number_format_i18n( $count ),
					null !== self::v( $profile, array( 'queries', 'time_ms' ) ) ? self::fmt_ms( (float) $profile['queries']['time_ms'] ) : '',
					implode( ', ', $types ),
					$top ? sprintf( '%1$s (%2$s)', self::comp_name( $top['component'] ), number_format_i18n( (int) $top['count'] ) ) : '',
				);
			}
			$top_worst = self::top_query_component( self::profile( $pages[ $worst_i ] ) );
			$worst_ref = self::ref( $pages, $worst_i );

			/* translators: 1: query count, 2: page path, 3: number of pages over the limit. */
			$title = sprintf( __( 'Pages run up to %1$s database queries (%2$s); %3$s over %4$s', 'blt-optimized' ), number_format_i18n( $worst ), $worst_ref, self::n_pages( count( $many ) ), number_format_i18n( $warn ) );

			$summary = __( 'Every query is a round trip to the database, and plugins that query in loops add up fast.', 'blt-optimized' );
			if ( $top_worst ) {
				/* translators: 1: component name, 2: its query count, 3: total queries, 4: page path. */
				$summary .= ' ' . sprintf( __( '%1$s alone accounts for %2$s of the %3$s queries on %4$s.', 'blt-optimized' ), self::comp_name( $top_worst['component'] ), number_format_i18n( (int) $top_worst['count'] ), number_format_i18n( $worst ), $worst_ref );
			}
			$summary .= ' ' . __( 'A persistent object cache removes most repeated lookups; trimming the heaviest component removes the rest.', 'blt-optimized' );

			$steps = __( "1. Enable a persistent object cache (Redis or Memcached — ask your host) so repeated lookups are served from memory.\n2. Open the findings for the top component below to see its slow and duplicate queries.\n3. Deactivate features of that plugin you don't use, or replace it with a lighter alternative.\n4. Scan again to confirm the count drops.", 'blt-optimized' );

			$findings[] = self::finding(
				array(
					'id'        => 'server.query_count',
					'key'       => 'server.query_count',
					'category'  => 'server',
					'severity'  => $worst > $crit ? 'critical' : 'warning',
					'title'     => $title,
					'summary'   => $summary,
					'impact'    => array(
						'type'  => 'count',
						'value' => $worst,
						'label' => sprintf(
							/* translators: %s: number of queries. */
							_n( '%s query', '%s queries', $worst, 'blt-optimized' ),
							number_format_i18n( $worst )
						),
					),
					'component' => $top_worst ? $top_worst['component'] : null,
					'where'     => self::urls( $pages, array_keys( $many ) ),
					'details'   => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Queries', 'blt-optimized' ), __( 'Query time', 'blt-optimized' ), __( 'By type', 'blt-optimized' ), __( 'Most queries from', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'       => self::fix_manual( __( 'Reduce database queries', 'blt-optimized' ), $steps ),
				)
			);
		}

		if ( $slow ) {
			arsort( $slow );
			$worst_i  = (int) key( $slow );
			$worst_ms = (float) reset( $slow );
			$rows     = array();
			foreach ( $slow as $i => $time ) {
				$profile = self::profile( $pages[ $i ] );
				$top     = self::top_cost_query( $profile );
				$rows[]  = array(
					self::ref( $pages, $i ),
					self::fmt_ms( $time ),
					number_format_i18n( (int) self::v( $profile, array( 'queries', 'count' ), 0 ) ),
					$top ? sprintf( '%1$s (%2$s)', self::comp_name( $top['component'] ), self::fmt_ms( $top['time_ms'] ) ) : '',
				);
			}
			$top_worst = self::top_cost_query( self::profile( $pages[ $worst_i ] ) );

			/* translators: 1: time, 2: page path, 3: number of pages over the limit. */
			$title = sprintf( __( 'Database queries take up to %1$s per page (%2$s); %3$s over %4$s', 'blt-optimized' ), self::fmt_ms( $worst_ms ), self::ref( $pages, $worst_i ), self::n_pages( count( $slow ) ), self::fmt_ms( $time_warn ) );

			$summary = __( 'Time spent waiting for MySQL is time the visitor waits too — nothing can be sent to the browser while a query is running.', 'blt-optimized' );
			if ( $top_worst ) {
				/* translators: 1: component name, 2: its query time. */
				$summary .= ' ' . sprintf( __( '%1$s is responsible for the most (%2$s) on the slowest page.', 'blt-optimized' ), self::comp_name( $top_worst['component'] ), self::fmt_ms( $top_worst['time_ms'] ) );
			}
			$summary .= ' ' . __( 'See the slow-query findings for the exact statements and who ran them.', 'blt-optimized' );

			$findings[] = self::finding(
				array(
					'id'        => 'server.query_time',
					'key'       => 'server.query_time',
					'category'  => 'server',
					'severity'  => 'warning',
					'title'     => $title,
					'summary'   => $summary,
					'impact'    => array(
						'type'  => 'ms',
						'value' => (int) round( $worst_ms ),
						'label' => '',
					),
					'component' => $top_worst ? $top_worst['component'] : null,
					'where'     => self::urls( $pages, array_keys( $slow ) ),
					'details'   => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Query time', 'blt-optimized' ), __( 'Queries', 'blt-optimized' ), __( 'Slowest component', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'       => self::fix_manual(
						__( 'Cut database time', 'blt-optimized' ),
						__( "1. Enable a persistent object cache (Redis or Memcached) so repeated reads skip MySQL.\n2. Open the slow-query findings for the component named above: they show the SQL and the file that ran it.\n3. Check the Database tab for oversized autoloaded options, bloated postmeta and tables that need optimizing.", 'blt-optimized' )
					),
				)
			);
		}

		return $findings;
	}

	/**
	 * The component running the most queries on a page (non-core preferred).
	 *
	 * @param array|null $profile Profile.
	 * @return array|null { component, count, time_ms }
	 */
	private static function top_query_component( $profile ) {
		return self::pick_query_component( $profile, 'count' );
	}

	/**
	 * The component spending the most time in queries on a page (non-core preferred).
	 *
	 * @param array|null $profile Profile.
	 * @return array|null { component, count, time_ms }
	 */
	private static function top_cost_query( $profile ) {
		return self::pick_query_component( $profile, 'time_ms' );
	}

	/**
	 * Shared picker for the two helpers above.
	 *
	 * @param array|null $profile Profile.
	 * @param string     $by      count|time_ms.
	 * @return array|null
	 */
	private static function pick_query_component( $profile, $by ) {
		if ( ! is_array( $profile ) ) {
			return null;
		}
		$best = null;
		$core = null;
		foreach ( (array) self::v( $profile, array( 'queries', 'by_component' ), array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$row = array(
				'component' => self::comp( isset( $row['component'] ) ? $row['component'] : null ),
				'count'     => (int) ( isset( $row['count'] ) ? $row['count'] : 0 ),
				'time_ms'   => (float) ( isset( $row['time_ms'] ) ? $row['time_ms'] : 0 ),
			);
			if ( self::is_self( $row['component'] ) ) {
				continue;
			}
			if ( self::is_core( $row['component'] ) ) {
				if ( null === $core || $row[ $by ] > $core[ $by ] ) {
					$core = $row;
				}
				continue;
			}
			if ( null === $best || $row[ $by ] > $best[ $by ] ) {
				$best = $row;
			}
		}
		return null !== $best ? $best : $core;
	}

	/**
	 * Slow queries (> SLOW_QUERY_MS), one finding per component.
	 *
	 * Impact = the worst single page's total slow-query time for that
	 * component (what one visitor pays).
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_slow_queries( array $pages ) {
		$limit  = (float) self::t( 'SLOW_QUERY_MS' );
		$groups = array();

		foreach ( $pages as $i => $page ) {
			$profile = self::profile( $page );
			if ( ! $profile || false === self::v( $profile, array( 'queries', 'tracked' ), true ) ) {
				continue;
			}
			foreach ( (array) self::v( $profile, array( 'queries', 'slowest' ), array() ) as $query ) {
				if ( ! is_array( $query ) ) {
					continue;
				}
				$ms = (float) ( isset( $query['ms'] ) ? $query['ms'] : 0 );
				if ( $ms <= $limit ) {
					continue;
				}
				$component = self::comp( isset( $query['component'] ) ? $query['component'] : null );
				if ( self::is_self( $component ) ) {
					continue;
				}
				$key = BLT_Optimized_Components::key( $component );
				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = array(
						'component' => $component,
						'count'     => 0,
						'per_page'  => array(),
						'rows'      => array(),
						'sql'       => array(),
					);
				}
				$sql                                = (string) ( isset( $query['sql'] ) ? $query['sql'] : '' );
				$groups[ $key ]['count']           += 1;
				$groups[ $key ]['per_page'][ $i ]   = ( isset( $groups[ $key ]['per_page'][ $i ] ) ? $groups[ $key ]['per_page'][ $i ] : 0 ) + $ms;
				$groups[ $key ]['sql'][]            = $sql;
				$groups[ $key ]['rows'][]           = array(
					'ms'  => $ms,
					'row' => array(
						self::ref( $pages, $i ),
						self::fmt_ms( $ms ),
						self::trunc( isset( $query['caller'] ) ? (string) $query['caller'] : '', 140 ),
						self::trunc( $sql, (int) self::t( 'SQL_TRUNCATE' ) ),
					),
				);
			}
		}

		$findings = array();
		foreach ( $groups as $key => $group ) {
			arsort( $group['per_page'] );
			$worst_ms = (float) reset( $group['per_page'] );
			$pages_n  = count( $group['per_page'] );
			usort(
				$group['rows'],
				static function ( $a, $b ) {
					return $b['ms'] <=> $a['ms'];
				}
			);
			$rows = wp_list_pluck( $group['rows'], 'row' );

			$subject = self::comp_subject( $group['component'] );
			if ( $worst_ms >= (float) self::t( 'SLOW_QUERY_CRIT_TOTAL_MS' ) ) {
				$severity = 'critical';
			} elseif ( $worst_ms >= (float) self::t( 'SLOW_QUERY_WARN_TOTAL_MS' ) ) {
				$severity = 'warning';
			} else {
				$severity = 'notice';
			}

			$count = (int) $group['count'];
			if ( 1 === $pages_n ) {
				$title = sprintf(
					/* translators: 1: component, 2: number of slow queries, 3: time. */
					_n( '%1$s runs %2$s slow database query (%3$s)', '%1$s runs %2$s slow database queries (%3$s)', $count, 'blt-optimized' ),
					$subject,
					number_format_i18n( $count ),
					self::fmt_ms( $worst_ms )
				);
				/* translators: %s: page. */
				$title .= ' ' . sprintf( __( 'on %s', 'blt-optimized' ), self::n_pages( 1 ) );
			} else {
				$title = sprintf(
					/* translators: 1: component, 2: number of slow queries, 3: number of pages, 4: worst time per page. */
					_n( '%1$s runs %2$s slow database query on %3$s (up to %4$s per page)', '%1$s runs %2$s slow database queries on %3$s (up to %4$s per page)', $count, 'blt-optimized' ),
					$subject,
					number_format_i18n( $count ),
					self::n_pages( $pages_n ),
					self::fmt_ms( $worst_ms )
				);
			}

			$summary = sprintf(
				/* translators: 1: slow-query threshold, 2: component. */
				__( 'Queries slower than %1$s hold the whole page back while MySQL works, and %2$s runs them on visitor page loads. The table lists each statement and the code that issued it; fixing or caching them cuts server time directly.', 'blt-optimized' ),
				self::fmt_ms( $limit ),
				self::comp_name( $group['component'] )
			);
			$hints = self::sql_hints( $group['sql'] );
			if ( $hints ) {
				$summary .= ' ' . __( 'What stands out:', 'blt-optimized' ) . ' ' . implode( ' ', $hints );
			}

			$steps = sprintf(
				/* translators: %s: component name. */
				__( "1. Look at the Caller column: it names the function and file in %s that issues each query.\n2. Update the plugin, and check its settings for features that query on every page (related items, live counters, search-as-you-type, statistics).\n3. If the query filters on a custom-field value (meta_value) or uses LIKE '%%…', ask the developer for an indexed alternative or a cached result.\n4. A persistent object cache (Redis or Memcached) caches many of these automatically — ask your host.", 'blt-optimized' ),
				self::comp_name( $group['component'] )
			);

			$findings[] = self::finding(
				array(
					'id'        => 'server.slow_queries',
					'key'       => 'server.slow_queries:' . $key,
					'category'  => 'server',
					'severity'  => $severity,
					'title'     => $title,
					'summary'   => $summary,
					'impact'    => array(
						'type'  => 'ms',
						'value' => (int) round( $worst_ms ),
						'label' => '',
					),
					'component' => $group['component'],
					'where'     => self::urls( $pages, array_keys( $group['per_page'] ) ),
					'details'   => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Time', 'blt-optimized' ), __( 'Caller', 'blt-optimized' ), __( 'Query', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'       => self::fix_manual( __( 'How to fix', 'blt-optimized' ), $steps ),
				)
			);
		}

		return $findings;
	}

	/**
	 * Plain-language observations about slow SQL statements.
	 *
	 * @param string[] $statements SQL statements.
	 * @return string[]
	 */
	private static function sql_hints( array $statements ) {
		$sql    = implode( "\n", $statements );
		$hints  = array();
		if ( preg_match( '/ORDER\s+BY\s+RAND\s*\(/i', $sql ) ) {
			$hints[] = __( 'ORDER BY RAND() makes MySQL sort every matching row on each page load.', 'blt-optimized' );
		}
		if ( preg_match( '/SQL_CALC_FOUND_ROWS/i', $sql ) ) {
			$hints[] = __( 'SQL_CALC_FOUND_ROWS counts every matching row, not just the page being shown.', 'blt-optimized' );
		}
		if ( preg_match( '/meta_value\s*(?:=|<|>|!=|<>|LIKE|IN\b|REGEXP|BETWEEN)|CAST\s*\(\s*[\w.`]*meta_value/i', $sql ) ) {
			$hints[] = __( 'It filters on the meta_value column of a custom-field table, which has no index, so MySQL scans many rows.', 'blt-optimized' );
		}
		if ( preg_match( "/LIKE\s+'%[^']/i", $sql ) ) {
			$hints[] = __( "A LIKE '%…' search with a leading wildcard cannot use an index and scans the whole table.", 'blt-optimized' );
		}
		if ( preg_match_all( '/(?:FROM|JOIN)\s+`?(\w+)`?/i', $sql, $matches ) ) {
			$tables = array_slice( array_values( array_unique( $matches[1] ) ), 0, 4 );
			if ( $tables ) {
				/* translators: %s: list of database tables. */
				$hints[] = sprintf( __( 'Tables involved: %s.', 'blt-optimized' ), implode( ', ', $tables ) );
			}
		}
		return $hints;
	}

	/**
	 * Identical queries repeated 5+ times on one page, per component.
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_duplicate_queries( array $pages ) {
		$min    = (int) self::t( 'DUPLICATE_QUERY_MIN' );
		$groups = array();

		foreach ( $pages as $i => $page ) {
			$profile = self::profile( $page );
			if ( ! $profile ) {
				continue;
			}
			foreach ( (array) self::v( $profile, array( 'queries', 'duplicates' ), array() ) as $dup ) {
				if ( ! is_array( $dup ) ) {
					continue;
				}
				$count = (int) ( isset( $dup['count'] ) ? $dup['count'] : 0 );
				if ( $count < $min ) {
					continue;
				}
				$component = null;
				foreach ( (array) ( isset( $dup['components'] ) ? $dup['components'] : array() ) as $candidate ) {
					$candidate = self::comp( $candidate );
					if ( self::is_self( $candidate ) ) {
						continue;
					}
					if ( null === $component || ( self::is_core( $component ) && ! self::is_core( $candidate ) ) ) {
						$component = $candidate;
					}
				}
				if ( null === $component ) {
					$component = self::comp( isset( $dup['component'] ) ? $dup['component'] : null );
				}
				$key    = BLT_Optimized_Components::key( $component );
				$time   = (float) ( isset( $dup['time_ms'] ) ? $dup['time_ms'] : 0 );
				$wasted = $count > 0 ? $time * ( $count - 1 ) / $count : 0;
				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = array(
						'component' => $component,
						'repeats'   => 0,
						'max_runs'  => 0,
						'per_page'  => array(),
						'rows'      => array(),
					);
				}
				$groups[ $key ]['repeats']         += $count - 1;
				$groups[ $key ]['max_runs']         = max( $groups[ $key ]['max_runs'], $count );
				$groups[ $key ]['per_page'][ $i ]   = ( isset( $groups[ $key ]['per_page'][ $i ] ) ? $groups[ $key ]['per_page'][ $i ] : 0 ) + $wasted;
				$groups[ $key ]['rows'][]           = array(
					'runs' => $count,
					'row'  => array(
						self::ref( $pages, $i ),
						number_format_i18n( $count ) . '×',
						self::fmt_ms( $time ),
						self::trunc( isset( $dup['caller'] ) ? (string) $dup['caller'] : '', 140 ),
						self::trunc( isset( $dup['sql'] ) ? (string) $dup['sql'] : '', (int) self::t( 'SQL_TRUNCATE' ) ),
					),
				);
			}
		}

		$findings = array();
		foreach ( $groups as $key => $group ) {
			arsort( $group['per_page'] );
			$worst_wasted = (float) reset( $group['per_page'] );
			$pages_n      = count( $group['per_page'] );
			usort(
				$group['rows'],
				static function ( $a, $b ) {
					return $b['runs'] <=> $a['runs'];
				}
			);

			$subject  = self::comp_subject( $group['component'] );
			$repeats  = (int) $group['repeats'];
			$severity = ( $worst_wasted >= (float) self::t( 'DUPLICATE_WARN_WASTED_MS' ) || $group['max_runs'] >= 50 ) ? 'warning' : 'notice';

			$title = sprintf(
				/* translators: 1: component, 2: number of repeated queries, 3: number of pages. */
				_n( '%1$s repeats identical database queries %2$s time on %3$s', '%1$s repeats identical database queries %2$s times on %3$s', $repeats, 'blt-optimized' ),
				$subject,
				number_format_i18n( $repeats ),
				self::n_pages( $pages_n )
			);

			$summary = sprintf(
				/* translators: 1: maximum number of runs of one query, 2: component. */
				__( 'The same statement is sent to the database up to %1$s times while building a single page, which means %2$s asks for the same data over and over instead of remembering the answer. Caching the result (or a persistent object cache) removes the repeats.', 'blt-optimized' ),
				number_format_i18n( (int) $group['max_runs'] ),
				self::comp_name( $group['component'] )
			);

			$findings[] = self::finding(
				array(
					'id'        => 'server.duplicate_queries',
					'key'       => 'server.duplicate_queries:' . $key,
					'category'  => 'server',
					'severity'  => $severity,
					'title'     => $title,
					'summary'   => $summary,
					'impact'    => array(
						'type'  => 'ms',
						'value' => (int) round( $worst_wasted ),
						'label' => ( $worst_wasted < 1 ) ? __( '< 1 ms', 'blt-optimized' ) : '',
					),
					'component' => $group['component'],
					'where'     => self::urls( $pages, array_keys( $group['per_page'] ) ),
					'details'   => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Runs', 'blt-optimized' ), __( 'Total time', 'blt-optimized' ), __( 'Caller', 'blt-optimized' ), __( 'Query', 'blt-optimized' ) ),
						'rows'    => wp_list_pluck( $group['rows'], 'row' ),
					),
					'fix'       => self::fix_manual(
						__( 'How to fix', 'blt-optimized' ),
						sprintf(
							/* translators: %s: component name. */
							__( "1. Enable a persistent object cache (Redis or Memcached): WordPress then answers repeated lookups from memory instead of MySQL.\n2. The Caller column shows where %s issues each query — if it is inside a loop, ask the developer to fetch the data once and reuse it.\n3. Update the plugin: repeated-query bugs are often fixed in newer versions.", 'blt-optimized' ),
							self::comp_name( $group['component'] )
						)
					),
				)
			);
		}

		return $findings;
	}

	/**
	 * Plugin/theme load cost (components_load), one finding per component.
	 *
	 * Impact and severity use the AVERAGE across pages: loading code costs the
	 * same on every request, and averaging smooths cold-cache spikes.
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_plugin_load( array $pages ) {
		$groups   = array();
		$opcache  = true;
		foreach ( $pages as $i => $page ) {
			$profile = self::profile( $page );
			if ( ! $profile ) {
				continue;
			}
			if ( false === self::v( $profile, array( 'env', 'opcache' ) ) ) {
				$opcache = false;
			}
			foreach ( (array) self::v( $profile, 'components_load', array() ) as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$component = self::comp( isset( $row['component'] ) ? $row['component'] : null );
				if ( self::is_self( $component ) ) {
					continue;
				}
				$key = BLT_Optimized_Components::key( $component );
				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = array(
						'component' => $component,
						'ms'        => array(),
						'memory'    => array(),
					);
				}
				$groups[ $key ]['ms'][ $i ]     = ( isset( $groups[ $key ]['ms'][ $i ] ) ? $groups[ $key ]['ms'][ $i ] : 0 ) + (float) ( isset( $row['ms'] ) ? $row['ms'] : 0 );
				$groups[ $key ]['memory'][ $i ] = ( isset( $groups[ $key ]['memory'][ $i ] ) ? $groups[ $key ]['memory'][ $i ] : 0 ) + (int) ( isset( $row['memory'] ) ? $row['memory'] : 0 );
			}
		}

		$findings = array();
		foreach ( $groups as $key => $group ) {
			$average = array_sum( $group['ms'] ) / max( 1, count( $group['ms'] ) );
			if ( $average <= (float) self::t( 'PLUGIN_LOAD_WARN_MS' ) ) {
				continue;
			}
			arsort( $group['ms'] );
			$peak_i   = (int) key( $group['ms'] );
			$peak     = (float) reset( $group['ms'] );
			$memory   = max( $group['memory'] );
			$rows     = array();
			foreach ( $group['ms'] as $i => $ms ) {
				$rows[] = array(
					self::ref( $pages, $i ),
					self::fmt_ms( $ms ),
					isset( $group['memory'][ $i ] ) && $group['memory'][ $i ] > 0 ? self::fmt_bytes( $group['memory'][ $i ] ) : '',
				);
			}

			$subject = self::comp_subject( $group['component'] );
			/* translators: 1: component, 2: time. */
			$title = sprintf( __( '%1$s adds about %2$s to every request just by loading', 'blt-optimized' ), $subject, self::fmt_ms( $average ) );

			/* translators: 1: component, 2: average time. */
			$summary = sprintf( __( 'Before a page is even built, WordPress has to read and run all of %1$s\'s code — %2$s on average here', 'blt-optimized' ), self::comp_name( $group['component'] ), self::fmt_ms( $average ) );
			if ( count( $group['ms'] ) > 1 ) {
				/* translators: 1: peak time, 2: page path. */
				$summary .= ' ' . sprintf( __( '(peak %1$s on %2$s)', 'blt-optimized' ), self::fmt_ms( $peak ), self::ref( $pages, $peak_i ) );
			}
			if ( $memory > 0 ) {
				/* translators: %s: memory size. */
				$summary .= ', ' . sprintf( __( 'and about %s of memory', 'blt-optimized' ), self::fmt_bytes( $memory ) );
			}
			$summary .= '. ' . __( 'That cost is paid on every uncached page view, admin screen and AJAX call, so trimming it speeds up everything.', 'blt-optimized' );
			if ( ! $opcache ) {
				$summary .= ' ' . __( 'PHP OPcache is off on this server, which inflates every plugin\'s load time — enabling it is the first thing to try.', 'blt-optimized' );
			}

			$severity = $average >= (float) self::t( 'PLUGIN_LOAD_CRIT_MS' ) ? 'critical' : 'warning';

			$findings[] = self::finding(
				array(
					'id'        => 'server.slow_plugin_load',
					'key'       => 'server.slow_plugin_load:' . $key,
					'category'  => 'server',
					'severity'  => $severity,
					'title'     => $title,
					'summary'   => $summary,
					'impact'    => array(
						'type'  => 'ms',
						'value' => (int) round( $average ),
						'label' => '',
					),
					'component' => $group['component'],
					'where'     => self::urls( $pages, array_keys( $group['ms'] ) ),
					'details'   => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Load time', 'blt-optimized' ), __( 'Memory', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'       => self::fix_manual(
						__( 'How to fix', 'blt-optimized' ),
						sprintf(
							/* translators: %s: component name. */
							__( "1. Ask whether you still need %s — deactivating an unused plugin removes this cost entirely.\n2. If you do need it, update it and look in its settings for modules or integrations you can switch off.\n3. Make sure OPcache is enabled (server checks) — plugin load time drops sharply with it.\n4. If it stays heavy, consider a lighter alternative.", 'blt-optimized' ),
							self::comp_name( $group['component'] )
						)
					),
				)
			);
		}

		return $findings;
	}

	/**
	 * Remote HTTP calls made while a page renders, per component.
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_external_http( array $pages ) {
		$groups = array();

		foreach ( $pages as $i => $page ) {
			$profile = self::profile( $page );
			if ( ! $profile ) {
				continue;
			}
			foreach ( (array) self::v( $profile, 'http', array() ) as $call ) {
				if ( ! is_array( $call ) || empty( $call['url'] ) ) {
					continue;
				}
				$url  = (string) $call['url'];
				$ms   = (float) ( isset( $call['ms'] ) ? $call['ms'] : 0 );
				$host = BLT_Optimized_Components::url_host( $url );
				// Loopbacks and WP-Cron pings are not "remote servers"; keep only slow ones.
				if ( BLT_Optimized_Components::is_local_host( $host ) && ( false !== strpos( $url, 'wp-cron.php' ) || $ms < 100 ) ) {
					continue;
				}
				$component = self::comp( isset( $call['component'] ) ? $call['component'] : null );
				if ( self::is_self( $component ) ) {
					continue;
				}
				$key = BLT_Optimized_Components::key( $component );
				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = array(
						'component' => $component,
						'per_page'  => array(),
						'hosts'     => array(),
						'calls'     => 0,
						'failed'    => 0,
						'rows'      => array(),
					);
				}
				$status = (int) ( isset( $call['status'] ) ? $call['status'] : 0 );
				$error  = (string) ( isset( $call['error'] ) ? $call['error'] : '' );
				$groups[ $key ]['per_page'][ $i ] = ( isset( $groups[ $key ]['per_page'][ $i ] ) ? $groups[ $key ]['per_page'][ $i ] : 0 ) + $ms;
				$groups[ $key ]['hosts'][]        = '' !== $host ? $host : $url;
				++$groups[ $key ]['calls'];
				if ( '' !== $error || $status >= 400 || 0 === $status ) {
					++$groups[ $key ]['failed'];
				}
				$groups[ $key ]['rows'][] = array(
					'ms'  => $ms,
					'row' => array(
						self::ref( $pages, $i ),
						self::trunc( $url, 140 ),
						self::fmt_ms( $ms ),
						'' !== $error ? self::trunc( $error, 80 ) : ( $status > 0 ? (string) $status : '' ),
						self::trunc( isset( $call['caller'] ) ? (string) $call['caller'] : '', 140 ),
					),
				);
			}
		}

		$findings = array();
		foreach ( $groups as $key => $group ) {
			arsort( $group['per_page'] );
			$worst_ms = (float) reset( $group['per_page'] );
			usort(
				$group['rows'],
				static function ( $a, $b ) {
					return $b['ms'] <=> $a['ms'];
				}
			);

			$subject = self::comp_subject( $group['component'] );
			$hosts   = array_values( array_unique( $group['hosts'] ) );
			$title   = sprintf(
				/* translators: 1: component, 2: list of remote hosts, 3: number of pages, 4: worst time per page. */
				__( '%1$s calls %2$s while building %3$s (up to %4$s per page)', 'blt-optimized' ),
				$subject,
				self::name_list( $hosts, 2 ),
				self::n_pages( count( $group['per_page'] ) ),
				self::fmt_ms( $worst_ms )
			);

			$summary = __( 'The page cannot finish until the remote server answers, so if that server is slow, rate-limited or down, every visitor waits for it (a timeout can hold a page for 5 seconds or more).', 'blt-optimized' );
			if ( $group['failed'] > 0 ) {
				/* translators: %s: number of failed requests. */
				$summary .= ' ' . sprintf( _n( '%s of these requests failed outright.', '%s of these requests failed outright.', $group['failed'], 'blt-optimized' ), number_format_i18n( $group['failed'] ) );
			}
			$summary .= ' ' . __( 'Remote data belongs in a background job whose result is cached, not in the visitor\'s request.', 'blt-optimized' );

			$findings[] = self::finding(
				array(
					'id'        => 'server.external_http',
					'key'       => 'server.external_http:' . $key,
					'category'  => 'server',
					'severity'  => $worst_ms >= (float) self::t( 'HTTP_CRIT_MS' ) ? 'critical' : 'warning',
					'title'     => $title,
					'summary'   => $summary,
					'impact'    => array(
						'type'  => 'ms',
						'value' => (int) round( $worst_ms ),
						'label' => '',
					),
					'component' => $group['component'],
					'where'     => self::urls( $pages, array_keys( $group['per_page'] ) ),
					'details'   => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Remote URL', 'blt-optimized' ), __( 'Time', 'blt-optimized' ), __( 'Result', 'blt-optimized' ), __( 'Caller', 'blt-optimized' ) ),
						'rows'    => wp_list_pluck( $group['rows'], 'row' ),
					),
					'fix'       => self::fix_manual(
						__( 'How to fix', 'blt-optimized' ),
						sprintf(
							/* translators: %s: component name. */
							__( "1. Check %s's settings for the feature that calls this service (licence checks, update checks, feeds, social counters, geolocation, fonts) and switch it off if you don't need it.\n2. If you do need it, ask the developer to fetch the data from WP-Cron and cache it in a transient instead of during page views.\n3. A persistent object cache keeps cached remote responses from being refetched.", 'blt-optimized' ),
							self::comp_name( $group['component'] )
						)
					),
				)
			);
		}

		return $findings;
	}

	/**
	 * PHP warnings, notices and deprecations, per component.
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_php_errors( array $pages ) {
		$groups = array();

		foreach ( $pages as $i => $page ) {
			$profile = self::profile( $page );
			if ( ! $profile ) {
				continue;
			}
			foreach ( (array) self::v( $profile, 'errors', array() ) as $error ) {
				if ( ! is_array( $error ) ) {
					continue;
				}
				$component = self::comp( isset( $error['component'] ) ? $error['component'] : null );
				if ( self::is_self( $component ) ) {
					continue;
				}
				$key   = BLT_Optimized_Components::key( $component );
				$count = max( 1, (int) ( isset( $error['count'] ) ? $error['count'] : 1 ) );
				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = array(
						'component' => $component,
						'total'     => 0,
						'per_page'  => array(),
						'levels'    => array(),
						'rows'      => array(),
					);
				}
				$level                                   = (string) ( isset( $error['level'] ) ? $error['level'] : 'other' );
				$groups[ $key ]['total']                += $count;
				$groups[ $key ]['per_page'][ $i ]        = ( isset( $groups[ $key ]['per_page'][ $i ] ) ? $groups[ $key ]['per_page'][ $i ] : 0 ) + $count;
				$groups[ $key ]['levels'][ $level ]      = ( isset( $groups[ $key ]['levels'][ $level ] ) ? $groups[ $key ]['levels'][ $level ] : 0 ) + $count;
				$groups[ $key ]['rows'][]                = array(
					'count' => $count,
					'row'   => array(
						self::ref( $pages, $i ),
						$level,
						self::trunc( isset( $error['message'] ) ? (string) $error['message'] : '', 220 ),
						( isset( $error['file'] ) ? (string) $error['file'] : '' ) . ( isset( $error['line'] ) ? ':' . (int) $error['line'] : '' ),
						number_format_i18n( $count ),
					),
				);
			}
		}

		$findings = array();
		foreach ( $groups as $key => $group ) {
			arsort( $group['per_page'] );
			$worst = (int) reset( $group['per_page'] );
			usort(
				$group['rows'],
				static function ( $a, $b ) {
					return $b['count'] <=> $a['count'];
				}
			);

			$subject = self::comp_subject( $group['component'] );
			$total   = (int) $group['total'];
			$title   = sprintf(
				/* translators: 1: component, 2: number of messages, 3: number of pages. */
				_n( '%1$s raises %2$s PHP warning or notice on %3$s', '%1$s raises %2$s PHP warnings and notices on %3$s', $total, 'blt-optimized' ),
				$subject,
				number_format_i18n( $total ),
				self::n_pages( count( $group['per_page'] ) )
			);

			$summary = __( 'PHP reports these problems while building the page. Each one costs a little time (and log space) on every request, and they usually mean outdated or buggy code that can break outright after a PHP upgrade.', 'blt-optimized' );
			if ( isset( $group['levels']['deprecated'] ) ) {
				$summary .= ' ' . __( '"Deprecated" means the code uses features that will stop working in a future PHP version.', 'blt-optimized' );
			}

			$findings[] = self::finding(
				array(
					'id'        => 'server.php_errors',
					'key'       => 'server.php_errors:' . $key,
					'category'  => 'server',
					'severity'  => $worst > (int) self::t( 'ERRORS_WARN_PER_PAGE' ) ? 'warning' : 'notice',
					'title'     => $title,
					'summary'   => $summary,
					'impact'    => array(
						'type'  => 'count',
						'value' => $worst,
						'label' => '',
					),
					'component' => $group['component'],
					'where'     => self::urls( $pages, array_keys( $group['per_page'] ) ),
					'details'   => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Level', 'blt-optimized' ), __( 'Message', 'blt-optimized' ), __( 'Location', 'blt-optimized' ), __( 'Times', 'blt-optimized' ) ),
						'rows'    => wp_list_pluck( $group['rows'], 'row' ),
					),
					'fix'       => self::fix_manual(
						__( 'How to fix', 'blt-optimized' ),
						sprintf(
							/* translators: %s: component name. */
							__( "1. Update %s (and PHP-compatibility-sensitive plugins around it) to the latest version.\n2. If the messages persist, send the Message and Location columns to its developer, or replace it.\n3. Keep WP_DEBUG_DISPLAY off on live sites so messages never reach visitors.", 'blt-optimized' ),
							self::comp_name( $group['component'] )
						)
					),
				)
			);
		}

		return $findings;
	}

	/**
	 * Transients written during a logged-out page view, per component.
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_transient_writes( array $pages ) {
		$ignore = array( 'doing_cron', 'wp_theme_files_patterns-*', 'feed_*', 'wp_remote_block_patterns*' );
		$groups = array();

		foreach ( $pages as $i => $page ) {
			$profile = self::profile( $page );
			// With a persistent object cache a transient never touches the database.
			if ( ! $profile || true === self::v( $profile, array( 'cache', 'persistent' ), false ) || true === self::v( $profile, array( 'context', 'logged_in' ), false ) ) {
				continue;
			}
			foreach ( (array) self::v( $profile, 'transients_set', array() ) as $row ) {
				if ( ! is_array( $row ) || empty( $row['name'] ) ) {
					continue;
				}
				$name      = (string) $row['name'];
				$component = self::comp( isset( $row['component'] ) ? $row['component'] : null );
				if ( self::is_core( $component ) || self::is_self( $component ) || self::matches_any( $name, $ignore ) ) {
					continue;
				}
				$key = BLT_Optimized_Components::key( $component );
				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = array(
						'component' => $component,
						'pages'     => array(),
						'names'     => array(),
						'writes'    => 0,
						'rows'      => array(),
					);
				}
				$count                          = max( 1, (int) ( isset( $row['count'] ) ? $row['count'] : 1 ) );
				$expiration                     = (int) ( isset( $row['expiration'] ) ? $row['expiration'] : 0 );
				$groups[ $key ]['pages'][ $i ]  = true;
				$groups[ $key ]['names'][ $name ] = true;
				$groups[ $key ]['writes']      += $count;
				$groups[ $key ]['rows'][]       = array(
					self::ref( $pages, $i ),
					self::trunc( $name, 80 ),
					$expiration > 0 ? human_time_diff( 0, $expiration ) : __( 'never', 'blt-optimized' ),
					number_format_i18n( $count ),
				);
			}
		}

		$findings = array();
		foreach ( $groups as $key => $group ) {
			$names   = count( $group['names'] );
			$subject = self::comp_subject( $group['component'] );
			$title   = sprintf(
				/* translators: 1: component, 2: number of transients, 3: number of pages. */
				_n( '%1$s writes %2$s transient to the database while rendering %3$s', '%1$s writes %2$s transients to the database while rendering %3$s', $names, 'blt-optimized' ),
				$subject,
				number_format_i18n( $names ),
				self::n_pages( count( $group['pages'] ) )
			);

			$findings[] = self::finding(
				array(
					'id'        => 'server.transient_writes',
					'key'       => 'server.transient_writes:' . $key,
					'category'  => 'server',
					'severity'  => 'notice',
					'title'     => $title,
					'summary'   => __( 'A visitor\'s page view is writing to the database (without a persistent object cache, transients live in wp_options). Refreshing a cache while someone waits adds an INSERT or UPDATE to the request and grows the options table; the refresh belongs in a scheduled background job.', 'blt-optimized' ),
					'impact'    => array(
						'type'  => 'count',
						'value' => $names,
						'label' => '',
					),
					'component' => $group['component'],
					'where'     => self::urls( $pages, array_keys( $group['pages'] ) ),
					'details'   => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Transient', 'blt-optimized' ), __( 'Expires in', 'blt-optimized' ), __( 'Writes', 'blt-optimized' ) ),
						'rows'    => $group['rows'],
					),
					'fix'       => self::fix_manual(
						__( 'How to fix', 'blt-optimized' ),
						sprintf(
							/* translators: %s: component name. */
							__( "1. A persistent object cache (Redis or Memcached) keeps these writes out of the database.\n2. If the same transient is written on every scan, %s is failing to reuse it — report that to its developer.\n3. Use the Cleanup page to remove expired transients so wp_options stays small.", 'blt-optimized' ),
							self::comp_name( $group['component'] )
						)
					),
				)
			);
		}

		return $findings;
	}

	/**
	 * Whether a string matches any fnmatch pattern.
	 *
	 * @param string   $text     Text.
	 * @param string[] $patterns Patterns.
	 * @return bool
	 */
	private static function matches_any( $text, array $patterns ) {
		$text = strtolower( (string) $text );
		foreach ( $patterns as $pattern ) {
			if ( fnmatch( strtolower( $pattern ), $text ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Memory peak close to the PHP limit.
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_memory( array $pages ) {
		$ratio = (float) self::t( 'MEMORY_WARN_RATIO' );
		$over  = array();
		foreach ( $pages as $i => $page ) {
			$profile = self::profile( $page );
			$peak    = (int) self::v( $profile, array( 'memory', 'peak' ), 0 );
			$limit   = (int) self::v( $profile, array( 'memory', 'limit' ), 0 );
			if ( $peak > 0 && $limit > 0 && $peak / $limit > $ratio ) {
				$over[ $i ] = $peak / $limit;
			}
		}
		if ( ! $over ) {
			return array();
		}

		arsort( $over );
		$worst_i = (int) key( $over );
		$profile = self::profile( $pages[ $worst_i ] );
		$peak    = (int) $profile['memory']['peak'];
		$limit   = (int) $profile['memory']['limit'];
		$rows    = array();
		foreach ( $over as $i => $fraction ) {
			$p      = self::profile( $pages[ $i ] );
			$rows[] = array(
				self::ref( $pages, $i ),
				self::fmt_bytes( (int) $p['memory']['peak'] ),
				self::fmt_bytes( (int) $p['memory']['limit'] ),
				number_format_i18n( $fraction * 100 ) . '%',
			);
		}

		// Which component allocates the most while loading, as a pointer.
		$heaviest = null;
		foreach ( self::component_costs( $profile ) as $cost ) {
			if ( ! self::is_self( $cost['component'] ) && ( null === $heaviest || $cost['load_mem'] > $heaviest['load_mem'] ) ) {
				$heaviest = $cost;
			}
		}

		/* translators: 1: percentage, 2: page path, 3: peak memory, 4: limit. */
		$title   = sprintf( __( 'Pages use up to %1$s of the PHP memory limit (%2$s peaks at %3$s of %4$s)', 'blt-optimized' ), number_format_i18n( reset( $over ) * 100 ) . '%', self::ref( $pages, $worst_i ), self::fmt_bytes( $peak ), self::fmt_bytes( $limit ) );
		$summary = __( 'Close to the limit, a slightly larger page, a bigger image upload or one more plugin ends in a white screen ("Allowed memory size exhausted"). Heavy memory use also slows PHP down.', 'blt-optimized' );
		if ( $heaviest && $heaviest['load_mem'] > 0 ) {
			/* translators: 1: component, 2: memory. */
			$summary .= ' ' . sprintf( __( 'The plugin or theme allocating the most while loading is %1$s (about %2$s).', 'blt-optimized' ), self::comp_name( $heaviest['component'] ), self::fmt_bytes( $heaviest['load_mem'] ) );
		}

		return array(
			self::finding(
				array(
					'id'        => 'server.memory',
					'key'       => 'server.memory',
					'category'  => 'server',
					'severity'  => 'warning',
					'title'     => $title,
					'summary'   => $summary,
					'impact'    => array(
						'type'  => 'bytes',
						'value' => $peak,
						'label' => '',
					),
					'component' => $heaviest && $heaviest['load_mem'] > 0 ? $heaviest['component'] : null,
					'where'     => self::urls( $pages, array_keys( $over ) ),
					'details'   => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Peak memory', 'blt-optimized' ), __( 'PHP limit', 'blt-optimized' ), __( 'Used', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'       => self::fix_manual(
						__( 'Give PHP more headroom', 'blt-optimized' ),
						__( "1. Raise the limit: add define( 'WP_MEMORY_LIMIT', '256M' ); to wp-config.php (and ask your host if memory_limit in php.ini is lower).\n2. Raising it only buys time: also look for the heaviest plugin (see the plugin load findings) and remove or replace it.", 'blt-optimized' )
					),
				)
			),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Asset helpers                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Normalised enqueued assets of a page (profile first, HTML as fallback).
	 *
	 * @param array $page Page (prepared or raw).
	 * @return array[] Each { type, handle, src, component, bytes, inline_bytes, local, footer, strategy, deps, from }.
	 */
	private static function page_assets( $page ) {
		if ( isset( $page['_f']['assets'] ) ) {
			return $page['_f']['assets'];
		}
		return self::compute_assets( $page );
	}

	/**
	 * Build the normalised asset list for one page.
	 *
	 * With a profile the list is exact (`wp_scripts()->done`). Without one, the
	 * HTML's script/link ids (`handle-js`, `handle-css`) recover handles, so the
	 * unused-asset and duplicate-library checks still work, minus the profiler's
	 * file sizes (read from disk for local files).
	 *
	 * @param array $page Page.
	 * @return array[]
	 */
	private static function compute_assets( $page ) {
		$out     = array();
		$profile = self::profile( $page );

		if ( $profile ) {
			foreach ( array( 'scripts' => 'script', 'styles' => 'style' ) as $bucket => $type ) {
				foreach ( (array) self::v( $profile, array( 'assets', $bucket ), array() ) as $asset ) {
					if ( ! is_array( $asset ) || empty( $asset['handle'] ) ) {
						continue;
					}
					$src       = (string) ( isset( $asset['src'] ) ? $asset['src'] : '' );
					$component = ( isset( $asset['component'] ) && is_array( $asset['component'] ) )
						? self::comp( $asset['component'] )
						: ( '' !== $src ? self::comp( BLT_Optimized_Components::from_url( $src ) ) : self::comp( null ) );
					$out[]     = array(
						'type'         => $type,
						'handle'       => (string) $asset['handle'],
						'src'          => $src,
						'component'    => $component,
						'bytes'        => (int) ( isset( $asset['bytes'] ) ? $asset['bytes'] : 0 ),
						'inline_bytes' => (int) ( isset( $asset['inline_bytes'] ) ? $asset['inline_bytes'] : 0 ),
						'local'        => ! empty( $asset['local'] ),
						'footer'       => isset( $asset['footer'] ) ? (bool) $asset['footer'] : null,
						'strategy'     => (string) ( isset( $asset['strategy'] ) ? $asset['strategy'] : '' ),
						'deps'         => isset( $asset['deps'] ) && is_array( $asset['deps'] ) ? array_map( 'strval', $asset['deps'] ) : array(),
						'from'         => 'profile',
					);
				}
			}
			return $out;
		}

		$html = self::html( $page );
		foreach ( array(
			'scripts'     => array( 'script', 'src', '-js' ),
			'stylesheets' => array( 'style', 'href', '-css' ),
		) as $bucket => $spec ) {
			foreach ( (array) ( isset( $html[ $bucket ] ) ? $html[ $bucket ] : array() ) as $tag ) {
				if ( ! is_array( $tag ) || empty( $tag[ $spec[1] ] ) ) {
					continue;
				}
				$src    = (string) $tag[ $spec[1] ];
				$id     = (string) ( isset( $tag['id'] ) ? $tag['id'] : '' );
				$handle = '';
				if ( '' !== $id && substr( $id, -strlen( $spec[2] ) ) === $spec[2] ) {
					$handle = substr( $id, 0, -strlen( $spec[2] ) );
				}
				$component = self::comp( BLT_Optimized_Components::from_url( $src ) );
				$out[]     = array(
					'type'         => $spec[0],
					'handle'       => $handle,
					'src'          => $src,
					'component'    => $component,
					'bytes'        => self::local_bytes( $src ),
					'inline_bytes' => 0,
					'local'        => BLT_Optimized_Components::is_local_host( BLT_Optimized_Components::url_host( $src ) ),
					'footer'       => ( 'script' === $spec[0] && isset( $tag['in_head'] ) ) ? ! $tag['in_head'] : null,
					'strategy'     => ( 'script' === $spec[0] && ! empty( $tag['defer'] ) ) ? 'defer' : ( ( 'script' === $spec[0] && ! empty( $tag['async'] ) ) ? 'async' : '' ),
					'deps'         => array(),
					'from'         => 'html',
				);
			}
		}
		return $out;
	}

	/**
	 * File size of a local asset URL (0 when external or missing).
	 *
	 * @param string $url Asset URL.
	 * @return int
	 */
	private static function local_bytes( $url ) {
		$path = BLT_Optimized_Components::url_to_path( $url );
		if ( '' === $path ) {
			return 0;
		}
		$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir hosts warn.
		return false === $size ? 0 : (int) $size;
	}

	/**
	 * Stable key for an asset source: no query string, no scheme.
	 *
	 * @param string $src URL.
	 * @return string
	 */
	private static function src_key( $src ) {
		return preg_replace( '#^https?:#i', '', (string) strtok( (string) $src, '?' ) );
	}

	/**
	 * File name of an asset or image URL.
	 *
	 * @param string $src URL.
	 * @return string
	 */
	private static function file_name( $src ) {
		$path = (string) wp_parse_url( (string) $src, PHP_URL_PATH );
		$name = basename( $path );
		return '' !== $name ? rawurldecode( $name ) : (string) $src;
	}

	/**
	 * Handles WordPress itself needs (never flagged as unused).
	 *
	 * @param string $handle Handle.
	 * @return bool
	 */
	private static function is_core_handle( $handle ) {
		return self::matches_any(
			$handle,
			array( 'jquery', 'jquery-*', 'wp-polyfill*', 'regenerator-runtime', 'wp-hooks', 'wp-i18n', 'wp-dom-ready', 'admin-bar', 'wp-a11y', 'wp-api-fetch', 'wp-url', 'wp-element', 'wp-escape-html', 'wp-is-shallow-equal', 'react', 'react-dom', 'lodash', 'underscore', 'wp-embed', 'dashicons', 'wp-emoji*', 'utils', 'common' )
		);
	}

	/**
	 * First asset on a page with one of the handles, or whose source contains a needle.
	 *
	 * @param array    $page    Page.
	 * @param string   $type    script|style.
	 * @param string[] $handles Handle patterns.
	 * @param string[] $needles Substrings of the source URL.
	 * @return array|null
	 */
	private static function find_asset( $page, $type, array $handles, array $needles = array() ) {
		foreach ( self::page_assets( $page ) as $asset ) {
			if ( $asset['type'] !== $type ) {
				continue;
			}
			if ( '' !== $asset['handle'] && self::matches_any( $asset['handle'], $handles ) ) {
				return $asset;
			}
			foreach ( $needles as $needle ) {
				if ( '' !== $asset['src'] && false !== stripos( $asset['src'], $needle ) ) {
					return $asset;
				}
			}
		}
		return null;
	}

	/* ------------------------------------------------------------------ */
	/* Asset checks                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Total JavaScript / CSS weight per page.
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_asset_weight( array $pages ) {
		$findings = array();
		$kinds    = array(
			'script' => array(
				'id'    => 'assets.js_weight',
				'limit' => (int) self::t( 'JS_WEIGHT_WARN_BYTES' ),
			),
			'style'  => array(
				'id'    => 'assets.css_weight',
				'limit' => (int) self::t( 'CSS_WEIGHT_WARN_BYTES' ),
			),
		);

		foreach ( $kinds as $type => $kind ) {
			$over     = array();
			$external = array();
			foreach ( $pages as $i => $page ) {
				if ( ! self::profile( $page ) ) {
					continue;
				}
				$sum = 0;
				$ext = 0;
				foreach ( self::page_assets( $page ) as $asset ) {
					if ( $asset['type'] !== $type ) {
						continue;
					}
					if ( $asset['bytes'] > 0 ) {
						$sum += $asset['bytes'];
					} elseif ( '' !== $asset['src'] && ! $asset['local'] ) {
						++$ext;
					}
				}
				if ( $sum > $kind['limit'] ) {
					$over[ $i ]     = $sum;
					$external[ $i ] = $ext;
				}
			}
			if ( ! $over ) {
				continue;
			}

			arsort( $over );
			$worst_i = (int) key( $over );
			$worst   = (int) reset( $over );

			// Evidence: handles across the affected pages, biggest first.
			$handles = array();
			foreach ( array_keys( $over ) as $i ) {
				foreach ( self::page_assets( $pages[ $i ] ) as $asset ) {
					if ( $asset['type'] !== $type || $asset['bytes'] <= 0 ) {
						continue;
					}
					$key = $asset['handle'] . '|' . self::src_key( $asset['src'] );
					if ( ! isset( $handles[ $key ] ) ) {
						$handles[ $key ] = array(
							'asset' => $asset,
							'pages' => 0,
						);
					}
					$handles[ $key ]['asset']['bytes'] = max( $handles[ $key ]['asset']['bytes'], $asset['bytes'] );
					++$handles[ $key ]['pages'];
				}
			}
			uasort(
				$handles,
				static function ( $a, $b ) {
					return $b['asset']['bytes'] <=> $a['asset']['bytes'];
				}
			);
			$rows = array();
			foreach ( $handles as $entry ) {
				$rows[] = array(
					'' !== $entry['asset']['handle'] ? $entry['asset']['handle'] : self::file_name( $entry['asset']['src'] ),
					self::comp_name( $entry['asset']['component'] ),
					self::fmt_bytes( $entry['asset']['bytes'] ),
					number_format_i18n( $entry['pages'] ),
				);
			}

			// Per-component share on the worst page, to name the biggest contributors.
			$by_comp = array();
			foreach ( self::page_assets( $pages[ $worst_i ] ) as $asset ) {
				if ( $asset['type'] === $type && $asset['bytes'] > 0 ) {
					$key             = BLT_Optimized_Components::key( $asset['component'] );
					$by_comp[ $key ] = isset( $by_comp[ $key ] ) ? $by_comp[ $key ] : array( 'component' => $asset['component'], 'bytes' => 0 );
					$by_comp[ $key ]['bytes'] += $asset['bytes'];
				}
			}
			uasort(
				$by_comp,
				static function ( $a, $b ) {
					return $b['bytes'] <=> $a['bytes'];
				}
			);
			$top_parts = array();
			foreach ( array_slice( $by_comp, 0, 3 ) as $entry ) {
				$top_parts[] = sprintf( '%1$s (%2$s)', self::comp_name( $entry['component'] ), self::fmt_bytes( $entry['bytes'] ) );
			}

			$worst_ref = self::ref( $pages, $worst_i );
			if ( 'script' === $type ) {
				/* translators: 1: total size, 2: page path, 3: number of pages over the limit, 4: limit. */
				$title   = sprintf( __( 'JavaScript totals %1$s on %2$s (%3$s over %4$s)', 'blt-optimized' ), self::fmt_bytes( $worst ), $worst_ref, self::n_pages( count( $over ) ), self::fmt_bytes( $kind['limit'] ) );
				$summary = __( 'Every script has to be downloaded, parsed and run before the page becomes fully interactive, and on a mid-range phone that is the biggest cause of a slow, janky page.', 'blt-optimized' );
				$fixlabel = __( 'Trim JavaScript', 'blt-optimized' );
				$steps    = __( "1. Check the unused-asset findings first: scripts that load on pages that don't need them are the easiest win (one click each).\n2. Remove or replace the heaviest items in the table — each row names the plugin or theme that loads it.\n3. Turn on the Defer scripts tweak so scripts stop blocking rendering.\n4. Use Speed Tweaks → Asset rules to load heavy scripts only on the pages that need them.", 'blt-optimized' );
			} else {
				/* translators: 1: total size, 2: page path, 3: number of pages over the limit, 4: limit. */
				$title   = sprintf( __( 'CSS totals %1$s on %2$s (%3$s over %4$s)', 'blt-optimized' ), self::fmt_bytes( $worst ), $worst_ref, self::n_pages( count( $over ) ), self::fmt_bytes( $kind['limit'] ) );
				$summary = __( 'The browser will not paint anything until it has downloaded and parsed all the stylesheets in the page head, so every extra kilobyte delays the first thing a visitor sees.', 'blt-optimized' );
				$fixlabel = __( 'Trim CSS', 'blt-optimized' );
				$steps    = __( "1. Check the unused-asset findings first: stylesheets from plugins that are not used on a page can be switched off per page (one click each).\n2. Remove or replace the heaviest items in the table — each row names the plugin or theme that loads it.\n3. Prefer one icon/font library to several, and avoid page builders' global CSS where you can.\n4. Use Speed Tweaks → Asset rules to load heavy stylesheets only where needed.", 'blt-optimized' );
			}
			if ( $top_parts ) {
				/* translators: %s: list of components with sizes. */
				$summary .= ' ' . sprintf( __( 'On the heaviest page most of it comes from %s.', 'blt-optimized' ), implode( ', ', $top_parts ) );
			}
			if ( 'script' === $type && $external[ $worst_i ] > 0 ) {
				/* translators: %s: number of external scripts. */
				$summary .= ' ' . sprintf( _n( 'On top of that it loads %s script from other sites.', 'On top of that it loads %s scripts from other sites.', $external[ $worst_i ], 'blt-optimized' ), number_format_i18n( $external[ $worst_i ] ) );
			}
			$summary .= ' ' . __( 'Sizes are uncompressed file sizes.', 'blt-optimized' );

			$findings[] = self::finding(
				array(
					'id'        => $kind['id'],
					'key'       => $kind['id'],
					'category'  => 'assets',
					'severity'  => 'warning',
					'title'     => $title,
					'summary'   => $summary,
					'impact'    => array(
						'type'  => 'bytes',
						'value' => $worst,
						'label' => '',
					),
					'component' => ! empty( $by_comp ) ? reset( $by_comp )['component'] : null,
					'where'     => self::urls( $pages, array_keys( $over ) ),
					'details'   => array(
						'columns' => array( __( 'File', 'blt-optimized' ), __( 'Loaded by', 'blt-optimized' ), __( 'Size', 'blt-optimized' ), __( 'On pages', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'       => self::fix_manual( $fixlabel, $steps ),
				)
			);
		}

		return $findings;
	}

	/**
	 * Scripts in the <head> that block rendering.
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_render_blocking( array $pages ) {
		$scripts = array();
		$idx     = array();
		$worst   = 0;
		$worst_n = 0;
		foreach ( $pages as $i => $page ) {
			$blocking = self::blocking_scripts( $page );
			if ( ! $blocking ) {
				continue;
			}
			$idx[] = $i;
			$sum   = 0;
			foreach ( $blocking as $key => $script ) {
				$sum += $script['bytes'];
				if ( ! isset( $scripts[ $key ] ) ) {
					$scripts[ $key ] = $script + array( 'pages' => 0 );
				}
				$scripts[ $key ]['bytes'] = max( $scripts[ $key ]['bytes'], $script['bytes'] );
				++$scripts[ $key ]['pages'];
			}
			$worst   = max( $worst, $sum );
			$worst_n = max( $worst_n, count( $blocking ) );
		}
		if ( ! $scripts ) {
			return array();
		}

		uasort(
			$scripts,
			static function ( $a, $b ) {
				return array( $b['pages'], $b['bytes'] ) <=> array( $a['pages'], $a['bytes'] );
			}
		);
		$rows  = array();
		$comps = array();
		foreach ( $scripts as $script ) {
			$name  = self::comp_name( $script['component'] );
			$comps[ $name ] = ( isset( $comps[ $name ] ) ? $comps[ $name ] : 0 ) + 1;
			$rows[] = array(
				'' !== $script['handle'] ? $script['handle'] : self::file_name( $script['src'] ),
				$name,
				$script['bytes'] > 0 ? self::fmt_bytes( $script['bytes'] ) : '',
				number_format_i18n( $script['pages'] ),
			);
		}
		arsort( $comps );

		$count = count( $scripts );
		$title = sprintf(
			/* translators: 1: number of scripts, 2: number of pages, 3: list of plugin names. */
			_n( '%1$s script in the page head blocks rendering on %2$s (%3$s)', '%1$s scripts in the page head block rendering on %2$s (%3$s)', $count, 'blt-optimized' ),
			number_format_i18n( $count ),
			self::n_pages( count( $idx ) ),
			self::name_list( array_keys( $comps ), 3 )
		);
		$summary = __( 'A normal script in the <head> stops the browser from drawing anything until it has been downloaded and executed, which delays the first paint and the Largest Contentful Paint. Deferred scripts download in parallel and run after the page has been parsed.', 'blt-optimized' );

		$state = self::tweak_state( 'defer_scripts', 1, $pages, $idx );
		if ( false === $state ) {
			return array();
		}
		if ( null === $state && self::supports_defer() ) {
			$fix      = self::fix_tweak(
				__( 'Defer scripts', 'blt-optimized' ),
				'defer_scripts',
				1,
				__( 'This adds the defer attribute to front-end scripts. WordPress only does it where its own rules say it is safe (jQuery stays as it is), but test your key pages afterwards; you can switch it off again in Speed Tweaks.', 'blt-optimized' )
			);
			$summary .= ' ' . __( 'Turning on the Defer scripts tweak fixes this in one click; WordPress skips any script it cannot safely defer.', 'blt-optimized' );
		} elseif ( true === $state ) {
			$fix      = self::fix_manual(
				__( 'Why they are still blocking', 'blt-optimized' ),
				__( "The Defer scripts tweak is already on, so these scripts are being held back on purpose: WordPress refuses to defer a script when a script that depends on it, or inline code before it, needs it immediately.\n1. Check whether the handles are listed in the tweak's \"never defer\" list.\n2. Ask the plugin or theme named in the table whether a deferred version exists, or load the script in the footer instead.", 'blt-optimized' )
			);
			$summary .= ' ' . __( 'The Defer scripts tweak is already on, so WordPress is refusing to defer these.', 'blt-optimized' );
		} else {
			$fix = self::fix_manual(
				__( 'How to fix', 'blt-optimized' ),
				__( "1. Update WordPress to 6.3 or newer: it can defer scripts safely with a single tweak.\n2. Until then, load each script in the footer (the plugin or theme named in the table usually has a setting), or use a performance plugin that defers scripts.", 'blt-optimized' )
			);
		}

		return array(
			self::finding(
				array(
					'id'        => 'assets.render_blocking',
					'key'       => 'assets.render_blocking',
					'category'  => 'assets',
					'severity'  => 'warning',
					'title'     => $title,
					'summary'   => $summary,
					'impact'    => $worst > 0
						? array(
							'type'  => 'bytes',
							'value' => $worst,
							'label' => '',
						)
						: array(
							'type'  => 'count',
							'value' => $worst_n,
							'label' => sprintf(
								/* translators: %s: number of scripts. */
								_n( '%s script', '%s scripts', $worst_n, 'blt-optimized' ),
								number_format_i18n( $worst_n )
							),
						),
					'where'     => self::urls( $pages, $idx ),
					'details'   => array(
						'columns' => array( __( 'Script', 'blt-optimized' ), __( 'Loaded by', 'blt-optimized' ), __( 'Size', 'blt-optimized' ), __( 'On pages', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'       => $fix,
				)
			),
		);
	}

	/**
	 * Render-blocking head scripts of one page, keyed by source.
	 *
	 * Uses the HTML (what the visitor actually gets) when it lists scripts,
	 * the profiler's enqueue data otherwise. jQuery is left out: the defer
	 * tweak never touches it, so flagging it would promise a fix that cannot
	 * happen.
	 *
	 * @param array $page Page.
	 * @return array<string,array> key => { src, handle, component, bytes }.
	 */
	private static function blocking_scripts( $page ) {
		$out    = array();
		$assets = array();
		foreach ( self::page_assets( $page ) as $asset ) {
			if ( 'script' === $asset['type'] && '' !== $asset['src'] ) {
				$assets[ self::src_key( $asset['src'] ) ] = $asset;
			}
		}

		$html    = self::html( $page );
		$scripts = ( isset( $html['scripts'] ) && is_array( $html['scripts'] ) ) ? $html['scripts'] : array();
		if ( $scripts ) {
			foreach ( $scripts as $script ) {
				if ( ! is_array( $script ) || empty( $script['src'] ) || empty( $script['in_head'] ) || ! empty( $script['async'] ) || ! empty( $script['defer'] ) || ! empty( $script['module'] ) ) {
					continue;
				}
				$key   = self::src_key( $script['src'] );
				$asset = isset( $assets[ $key ] ) ? $assets[ $key ] : null;
				$out[ $key ] = array(
					'src'       => (string) $script['src'],
					'handle'    => $asset ? $asset['handle'] : '',
					'component' => $asset ? $asset['component'] : self::comp( BLT_Optimized_Components::from_url( (string) $script['src'] ) ),
					'bytes'     => $asset ? $asset['bytes'] : self::local_bytes( (string) $script['src'] ),
				);
			}
		} else {
			foreach ( $assets as $key => $asset ) {
				if ( 'profile' === $asset['from'] && false === $asset['footer'] && '' === $asset['strategy'] ) {
					$out[ $key ] = array(
						'src'       => $asset['src'],
						'handle'    => $asset['handle'],
						'component' => $asset['component'],
						'bytes'     => $asset['bytes'],
					);
				}
			}
		}

		foreach ( $out as $key => $script ) {
			if ( in_array( $script['handle'], array( 'jquery', 'jquery-core', 'jquery-migrate' ), true ) || preg_match( '#/jquery(?:-migrate)?(?:\.min)?\.js#i', $script['src'] ) ) {
				unset( $out[ $key ] );
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Unused assets                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Fill in defaults and lowercase tokens/handles for a marker list.
	 *
	 * @param array $markers Raw markers (possibly filtered by third parties).
	 * @return array[]
	 */
	private static function normalize_markers( array $markers ) {
		$out = array();
		foreach ( $markers as $marker ) {
			if ( ! is_array( $marker ) || empty( $marker['handles'] ) ) {
				continue;
			}
			$label     = isset( $marker['label'] ) ? (string) $marker['label'] : '';
			$condition = ( isset( $marker['condition'] ) && is_array( $marker['condition'] ) ) ? $marker['condition'] : array();
			if ( isset( $condition['type'] ) ) {
				$condition = array( $condition );
			}
			$conditions = array();
			foreach ( $condition as $item ) {
				if ( is_array( $item ) && ! empty( $item['type'] ) ) {
					$conditions[] = array(
						'type'  => (string) $item['type'],
						'value' => isset( $item['value'] ) ? (string) $item['value'] : '',
					);
				}
			}
			$handles = array_values( array_filter( array_map( 'strval', (array) $marker['handles'] ), 'strlen' ) );
			$out[]   = array(
				'id'        => isset( $marker['id'] ) && '' !== $marker['id'] ? (string) $marker['id'] : sanitize_title( '' !== $label ? $label : $handles[0] ),
				'label'     => '' !== $label ? $label : $handles[0],
				'handles'   => $handles,
				'component' => isset( $marker['component'] ) ? strtolower( (string) $marker['component'] ) : '',
				'tokens'    => array_values( array_filter( array_map( 'strtolower', array_map( 'strval', isset( $marker['tokens'] ) ? (array) $marker['tokens'] : array() ) ), 'strlen' ) ),
				'ignore'    => array_values( array_filter( array_map( 'strtolower', array_map( 'strval', isset( $marker['ignore'] ) ? (array) $marker['ignore'] : array() ) ), 'strlen' ) ),
				'contexts'  => array_values( array_filter( array_map( 'strval', isset( $marker['contexts'] ) ? (array) $marker['contexts'] : array() ), 'strlen' ) ),
				'without'   => isset( $marker['without'] ) && '' !== $marker['without'] ? (string) $marker['without'] : __( 'anything that uses it', 'blt-optimized' ),
				'condition' => $conditions,
			);
		}
		return $out;
	}

	/**
	 * Index of the first marker an asset belongs to, or null.
	 *
	 * @param array   $asset   Asset.
	 * @param array[] $markers Normalised markers.
	 * @return int|null
	 */
	private static function match_marker( array $asset, array $markers ) {
		foreach ( $markers as $index => $marker ) {
			if ( ! self::matches_any( $asset['handle'], $marker['handles'] ) ) {
				continue;
			}
			// A plugin's own marker only claims that plugin's local assets (and its add-ons);
			// external files such as reCAPTCHA match on handle alone.
			if ( '' !== $marker['component'] && in_array( $asset['component']['type'], array( 'plugin', 'mu-plugin', 'theme' ), true ) ) {
				$slug = strtolower( $asset['component']['slug'] );
				if ( $slug !== $marker['component'] && 0 !== strpos( $slug, $marker['component'] . '-' ) ) {
					continue;
				}
			}
			return $index;
		}
		return null;
	}

	/**
	 * Whether any of the page's class/id tokens proves use of an asset family.
	 *
	 * @param string[] $tokens  Lowercase tokens.
	 * @param string[] $needles Prefixes (a leading "=" means exact).
	 * @param string[] $ignore  fnmatch patterns that never count.
	 * @return bool
	 */
	private static function tokens_match( array $tokens, array $needles, array $ignore ) {
		if ( ! $tokens || ! $needles ) {
			return false;
		}
		foreach ( $tokens as $token ) {
			foreach ( $needles as $needle ) {
				if ( 0 === strpos( $needle, '=' ) ) {
					$hit = substr( $needle, 1 ) === $token;
				} else {
					$hit = 0 === strpos( $token, $needle );
				}
				if ( $hit && ( ! $ignore || ! self::matches_any( $token, $ignore ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Whether a marker's family is used on the page (tokens or context).
	 *
	 * @param array $page   Prepared page.
	 * @param array $marker Normalised marker.
	 * @return bool
	 */
	private static function marker_used( array $page, array $marker ) {
		foreach ( $marker['contexts'] as $context ) {
			if ( self::context_active( $page, $context ) ) {
				return true;
			}
		}
		return self::tokens_match( $page['_f']['tokens'], $marker['tokens'], $marker['ignore'] );
	}

	/**
	 * Whether a page context (e.g. "woocommerce") is active on the page.
	 * Unknown counts as active: with no way to tell, never claim "unused".
	 *
	 * @param array  $page    Page.
	 * @param string $context Context name.
	 * @return bool
	 */
	private static function context_active( array $page, $context ) {
		if ( 'woocommerce' === $context ) {
			$woo = self::is_woo_page( $page );
			return null === $woo ? true : $woo;
		}
		$conditionals = self::v( $page, array( 'profile', 'context', 'conditionals' ) );
		if ( ! is_array( $conditionals ) ) {
			return true;
		}
		return ! empty( $conditionals[ 'is_' . $context ] );
	}

	/**
	 * Search terms that would prove a plugin's asset is used on a page: the
	 * plugin slug, the handle stem and the first distinctive word of the slug.
	 *
	 * @param array $asset Asset.
	 * @return string[]
	 */
	private static function asset_stems( array $asset ) {
		$generic = array( 'wordpress', 'plugin', 'plugins', 'simple', 'easy', 'smart', 'super', 'custom', 'social', 'share', 'sharing', 'form', 'forms', 'contact', 'page', 'pages', 'post', 'posts', 'menu', 'mega', 'popup', 'slider', 'gallery', 'video', 'image', 'images', 'email', 'search', 'cookie', 'cookies', 'cache', 'speed', 'user', 'users', 'login', 'content', 'wpml', 'theme', 'child', 'main', 'public', 'front', 'frontend' );
		$slug    = strtolower( (string) $asset['component']['slug'] );
		$handle  = strtolower( (string) $asset['handle'] );
		for ( $n = 0; $n < 2; $n++ ) {
			$handle = preg_replace( '/[-_.](css|js|style|styles|script|scripts|frontend|front|public|main|min|bundle|vendor|lib)$/', '', $handle );
		}
		$stems = array();
		foreach ( array( $slug, $handle ) as $source ) {
			if ( strlen( $source ) >= 4 ) {
				$stems[] = $source;
				$stems[] = str_replace( '-', '_', $source );
				$stems[] = str_replace( '-', '', $source );
			}
		}
		$first = (string) strtok( $slug, '-_' );
		if ( strlen( $first ) >= 4 && ! in_array( $first, $generic, true ) ) {
			$stems[] = $first;
		}
		return array_values( array_unique( $stems ) );
	}

	/**
	 * Whether any token on the page carries one of the stems.
	 *
	 * @param array    $page  Prepared page.
	 * @param string[] $stems Stems.
	 * @return bool
	 */
	private static function stems_present( array $page, array $stems ) {
		foreach ( $page['_f']['tokens'] as $token ) {
			foreach ( $stems as $stem ) {
				if ( 0 === strpos( $token, $stem ) || false !== strpos( $token, '-' . $stem ) || false !== strpos( $token, '_' . $stem ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Assets that load on pages that do not use them — one finding per asset
	 * family (a known marker) or per plugin (generic "may be unused" fallback),
	 * with one evidence row per page.
	 *
	 * Impact = bytes of the family's unused files on the worst single page.
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_unused_assets( array $pages ) {
		if ( null === self::$markers ) {
			self::$markers = self::asset_markers();
		}
		$markers = self::$markers;
		$groups  = array();
		$generic = array();

		foreach ( $pages as $i => $page ) {
			foreach ( self::page_assets( $page ) as $asset ) {
				$handle = $asset['handle'];
				if ( '' === $handle || self::is_core_handle( $handle ) ) {
					continue;
				}
				// Cart fragments get their own, more specific finding.
				if ( 'script' === $asset['type'] && 'wc-cart-fragments' === $handle ) {
					continue;
				}
				$akey  = $asset['type'] . '|' . $handle;
				$index = self::match_marker( $asset, $markers );

				if ( null !== $index ) {
					$marker = $markers[ $index ];
					$gkey   = $index . ( '' === $marker['component'] ? '|' . BLT_Optimized_Components::key( $asset['component'] ) : '' );
					if ( ! isset( $groups[ $gkey ] ) ) {
						$groups[ $gkey ] = array(
							'marker'      => $index,
							'component'   => $asset['component'],
							'unused'      => array(),
							'used_loaded' => array(),
							'assets'      => array(),
						);
					}
					if ( self::marker_used( $page, $marker ) ) {
						$groups[ $gkey ]['used_loaded'][ $i ] = true;
						continue;
					}
					$groups[ $gkey ]['unused'][ $i ][ $akey ] = $asset;
					if ( ! isset( $groups[ $gkey ]['assets'][ $akey ] ) || $asset['bytes'] > $groups[ $gkey ]['assets'][ $akey ]['bytes'] ) {
						$groups[ $gkey ]['assets'][ $akey ] = $asset;
					}
					continue;
				}

				// Generic fallback: plugin-owned assets only.
				if ( 'plugin' !== $asset['component']['type'] || self::is_self( $asset['component'] ) ) {
					continue;
				}
				$ckey = BLT_Optimized_Components::key( $asset['component'] );
				if ( ! isset( $generic[ $ckey ][ $akey ] ) ) {
					$generic[ $ckey ][ $akey ] = array(
						'asset'    => $asset,
						'loaded'   => array(),
						'evidence' => false,
					);
				}
				$generic[ $ckey ][ $akey ]['loaded'][ $i ] = $asset;
				if ( ! $generic[ $ckey ][ $akey ]['evidence'] && self::stems_present( $page, self::asset_stems( $asset ) ) ) {
					$generic[ $ckey ][ $akey ]['evidence'] = true;
				}
			}
		}

		$findings = array();
		foreach ( $groups as $gkey => $group ) {
			if ( empty( $group['unused'] ) ) {
				continue;
			}
			$findings[] = self::unused_marker_finding( $pages, $markers[ $group['marker'] ], $group );
		}
		foreach ( $generic as $ckey => $assets ) {
			$flagged = array();
			foreach ( $assets as $akey => $entry ) {
				if ( ! $entry['evidence'] && count( $entry['loaded'] ) >= (int) self::t( 'GENERIC_UNUSED_MIN_PAGES' ) ) {
					$flagged[ $akey ] = $entry;
				}
			}
			if ( $flagged ) {
				$findings[] = self::unused_generic_finding( $pages, $ckey, $flagged );
			}
		}
		return $findings;
	}

	/**
	 * Finding for a known asset family loading where it is not used.
	 *
	 * @param array[] $pages  Prepared pages.
	 * @param array   $marker Normalised marker.
	 * @param array   $group  Group data.
	 * @return array
	 */
	private static function unused_marker_finding( array $pages, array $marker, array $group ) {
		$unused_pages = array_keys( $group['unused'] );
		$stats        = self::unused_stats( $pages, $group['unused'] );
		$what         = self::asset_phrase( $group['assets'] );

		$component = '' !== $marker['component']
			? BLT_Optimized_Components::make( 'plugin', $marker['component'], BLT_Optimized_Components::plugin_name( $marker['component'] ) )
			: $group['component'];
		$subject   = '' !== $marker['component'] ? $marker['label'] : self::comp_subject( $group['component'] );
		if ( '' === $marker['component'] && ! self::is_core( $group['component'] ) && $marker['label'] !== self::comp_name( $group['component'] ) ) {
			/* translators: 1: component, 2: asset family, e.g. "The Avada theme" and "Slider library". */
			$subject = sprintf( __( '%1$s (%2$s)', 'blt-optimized' ), self::comp_subject( $group['component'] ), $marker['label'] );
		}

		$title = sprintf(
			/* translators: 1: component, 2: what it loads ("2 scripts and 1 stylesheet"), 3: number of pages, 4: what the pages lack ("a form"). */
			__( '%1$s loads %2$s on %3$s without %4$s', 'blt-optimized' ),
			$subject,
			$what,
			self::n_pages( count( $unused_pages ) ),
			$marker['without']
		);

		$summary = sprintf(
			/* translators: 1: pages count, 2: what they lack, 3: size. */
			__( 'These files are downloaded and run on %1$s that show no sign of %2$s, so every visit to them pays for code it never uses (%3$s per page at most). Loading them only where they are needed makes those pages lighter and faster.', 'blt-optimized' ),
			self::n_pages( count( $unused_pages ) ),
			$marker['without'],
			$stats['worst_bytes'] > 0 ? self::fmt_bytes( $stats['worst_bytes'] ) : __( 'a few KB', 'blt-optimized' )
		);
		$used = array_diff( array_keys( $group['used_loaded'] ), $unused_pages );
		if ( $used ) {
			$refs = array();
			foreach ( $used as $i ) {
				$refs[] = self::ref( $pages, $i );
			}
			$summary .= ' ' . sprintf(
				/* translators: 1: number of pages, 2: list of page paths. */
				_n( 'It is genuinely used on %1$s other sampled page (%2$s); the suggested rule keeps it loading there.', 'It is genuinely used on %1$s other sampled pages (%2$s); the suggested rule keeps it loading there.', count( $used ), 'blt-optimized' ),
				number_format_i18n( count( $used ) ),
				self::name_list( $refs, 3 )
			);
		}

		// Fix.
		$fix = null;
		if ( ! empty( $marker['condition'] ) ) {
			$conditions = self::augment_conditions( $marker['condition'], $pages, $group['used_loaded'] );
			$rules      = array();
			$primary    = null;
			foreach ( $group['assets'] as $asset ) {
				$rule    = array(
					'type'       => $asset['type'],
					'handle'     => $asset['handle'],
					'mode'       => 'except',
					'conditions' => $conditions,
				);
				$rules[] = $rule;
				if ( null === $primary || $asset['bytes'] > $primary['bytes'] ) {
					$primary = array(
						'rule'  => $rule,
						'bytes' => $asset['bytes'],
					);
				}
			}
			$fix = array(
				'type'    => 'action',
				'label'   => __( 'Load only where used', 'blt-optimized' ),
				'action'  => 'asset_rule',
				'args'    => $primary['rule'] + array( 'rules' => $rules ),
				'confirm' => sprintf(
					/* translators: 1: list of handles, 2: description of the pages that keep them. */
					__( "This stops %1\$s loading everywhere except pages where: %2\$s.\n\nIf %3\$s is added by your theme, a widget or a page builder instead of the page content, add that page in Speed Tweaks → Asset rules. You can switch the rule off there at any time.", 'blt-optimized' ),
					self::handle_list( $group['assets'] ),
					self::describe_conditions( $conditions ),
					$marker['label']
				),
			);
		} else {
			$fix = self::fix_manual(
				__( 'Load only where used', 'blt-optimized' ),
				sprintf(
					/* translators: 1: list of handles, 2: label, 3: what the pages lack. */
					__( "Go to Speed Tweaks → Asset rules and add a rule for each of: %1\$s.\nChoose \"Load everywhere except\" and add a condition that matches the pages that really use %2\$s (a post type, specific post IDs, or a shortcode/block in the content). It cannot be detected automatically which pages those are, so check the table below for the pages that don't need it.", 'blt-optimized' ),
					self::handle_list( $group['assets'] ),
					$marker['label']
				)
			);
		}

		$component_out = $component;
		return self::finding(
			array(
				'id'        => 'assets.unused',
				'key'       => 'assets.unused:' . $marker['id'] . ( '' === $marker['component'] ? ':' . BLT_Optimized_Components::key( $group['component'] ) : '' ),
				'category'  => 'assets',
				'severity'  => ( $stats['worst_bytes'] >= (int) self::t( 'UNUSED_WARN_BYTES' ) || count( $group['assets'] ) >= 3 ) ? 'warning' : 'notice',
				'title'     => $title,
				'summary'   => $summary,
				'impact'    => $stats['worst_bytes'] > 0
					? array(
						'type'  => 'bytes',
						'value' => $stats['worst_bytes'],
						'label' => '',
					)
					: array(
						'type'  => 'count',
						'value' => count( $group['assets'] ),
						'label' => '',
					),
				'component' => $component_out,
				'where'     => self::urls( $pages, $unused_pages ),
				'details'   => array(
					'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Loaded but unused', 'blt-optimized' ), __( 'Weight', 'blt-optimized' ), __( 'Template / type', 'blt-optimized' ) ),
					'rows'    => self::unused_rows( $pages, $group['unused'] ),
				),
				'fix'       => $fix,
			)
		);
	}

	/**
	 * Finding for plugin assets with no sign of use on any page they load on.
	 *
	 * @param array[] $pages   Prepared pages.
	 * @param string  $ckey    Component key.
	 * @param array   $flagged akey => { asset, loaded: i => asset }.
	 * @return array
	 */
	private static function unused_generic_finding( array $pages, $ckey, array $flagged ) {
		$unused    = array();
		$assets    = array();
		$component = null;
		foreach ( $flagged as $akey => $entry ) {
			$component = $entry['asset']['component'];
			foreach ( $entry['loaded'] as $i => $asset ) {
				$unused[ $i ][ $akey ] = $asset;
			}
			$assets[ $akey ] = $entry['asset'];
		}
		$stats = self::unused_stats( $pages, $unused );
		$what  = self::asset_phrase( $assets );

		$title = sprintf(
			/* translators: 1: plugin, 2: what it loads, 3: number of pages. */
			__( '%1$s loads %2$s on %3$s with no sign it is used (may be unused — verify)', 'blt-optimized' ),
			self::comp_subject( $component ),
			$what,
			self::n_pages( count( $unused ) )
		);

		return self::finding(
			array(
				'id'        => 'assets.unused',
				'key'       => 'assets.unused:generic:' . $ckey,
				'category'  => 'assets',
				'severity'  => 'notice',
				'title'     => $title,
				'summary'   => sprintf(
					/* translators: %s: plugin name. */
					__( "None of the sampled pages that load these files contain any markup that looks like %s's (no matching class or id), so they may be loading for a feature you don't use. This is a heuristic — some plugins inject their markup with JavaScript — so check before removing anything.", 'blt-optimized' ),
					self::comp_name( $component )
				),
				'impact'    => $stats['worst_bytes'] > 0
					? array(
						'type'  => 'bytes',
						'value' => $stats['worst_bytes'],
						'label' => '',
					)
					: array(
						'type'  => 'count',
						'value' => count( $assets ),
						'label' => '',
					),
				'component' => $component,
				'where'     => self::urls( $pages, array_keys( $unused ) ),
				'details'   => array(
					'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Loaded, no sign of use', 'blt-optimized' ), __( 'Weight', 'blt-optimized' ), __( 'Template / type', 'blt-optimized' ) ),
					'rows'    => self::unused_rows( $pages, $unused ),
				),
				'fix'       => self::fix_manual(
					__( 'Verify, then switch off', 'blt-optimized' ),
					sprintf(
						/* translators: 1: list of handles, 2: plugin name. */
						__( "1. Open a page from the table and check whether %2\$s is supposed to show something there.\n2. If not, look in %2\$s's settings for the feature that loads these files and switch it off — or deactivate the plugin if you no longer use it.\n3. To keep the plugin but stop the files loading on some pages, add Asset rules in Speed Tweaks for: %1\$s.", 'blt-optimized' ),
						self::handle_list( $assets ),
						self::comp_name( $component )
					)
				),
			)
		);
	}

	/**
	 * Per-page unused-weight statistics.
	 *
	 * @param array[] $pages  Prepared pages.
	 * @param array   $unused page index => akey => asset.
	 * @return array { worst_bytes }
	 */
	private static function unused_stats( array $pages, array $unused ) {
		$worst = 0;
		foreach ( $unused as $assets ) {
			$sum = 0;
			foreach ( $assets as $asset ) {
				$sum += $asset['bytes'];
			}
			$worst = max( $worst, $sum );
		}
		return array( 'worst_bytes' => $worst );
	}

	/**
	 * Evidence rows, one per page.
	 *
	 * @param array[] $pages  Prepared pages.
	 * @param array   $unused page index => akey => asset.
	 * @return array[]
	 */
	private static function unused_rows( array $pages, array $unused ) {
		$rows = array();
		foreach ( $unused as $i => $assets ) {
			$parts = array();
			$sum   = 0;
			foreach ( $assets as $asset ) {
				$sum    += $asset['bytes'];
				$parts[] = sprintf(
					'%1$s (%2$s%3$s)',
					$asset['handle'],
					'script' === $asset['type'] ? 'JS' : 'CSS',
					$asset['bytes'] > 0 ? ', ' . self::fmt_bytes( $asset['bytes'] ) : ''
				);
			}
			$template = self::template_name( $pages[ $i ] );
			$type     = (string) self::v( $pages[ $i ], array( 'profile', 'context', 'type' ), (string) self::v( $pages[ $i ], 'type', '' ) );
			$rows[]   = array(
				self::ref( $pages, $i ),
				implode( ', ', $parts ),
				$sum > 0 ? self::fmt_bytes( $sum ) : '',
				trim( ( '' !== $template ? $template . ' · ' : '' ) . $type, ' ·' ),
			);
		}
		return $rows;
	}

	/**
	 * "2 scripts and 1 stylesheet", or the handle when there is only one file.
	 *
	 * @param array[] $assets akey => asset.
	 * @return string
	 */
	private static function asset_phrase( array $assets ) {
		$scripts = 0;
		$styles  = 0;
		$only    = '';
		foreach ( $assets as $asset ) {
			if ( 'script' === $asset['type'] ) {
				++$scripts;
			} else {
				++$styles;
			}
			$only = $asset['handle'];
		}
		if ( 1 === $scripts + $styles ) {
			return $only;
		}
		$parts = array();
		if ( $scripts > 0 ) {
			/* translators: %s: number of scripts. */
			$parts[] = sprintf( _n( '%s script', '%s scripts', $scripts, 'blt-optimized' ), number_format_i18n( $scripts ) );
		}
		if ( $styles > 0 ) {
			/* translators: %s: number of stylesheets. */
			$parts[] = sprintf( _n( '%s stylesheet', '%s stylesheets', $styles, 'blt-optimized' ), number_format_i18n( $styles ) );
		}
		return wp_sprintf( '%l', $parts );
	}

	/**
	 * "script contact-form-7, style contact-form-7" for steps and confirmations.
	 *
	 * @param array[] $assets akey => asset.
	 * @return string
	 */
	private static function handle_list( array $assets ) {
		$parts = array();
		foreach ( $assets as $asset ) {
			$parts[] = ( 'script' === $asset['type'] ? 'script ' : 'style ' ) . $asset['handle'];
		}
		return implode( ', ', array_slice( $parts, 0, 12 ) ) . ( count( $parts ) > 12 ? ', …' : '' );
	}

	/**
	 * Plain-language description of an asset rule's conditions.
	 *
	 * @param array[] $conditions Conditions.
	 * @return string
	 */
	private static function describe_conditions( array $conditions ) {
		$parts   = array();
		$content = array();
		foreach ( $conditions as $condition ) {
			$value = (string) $condition['value'];
			switch ( $condition['type'] ) {
				case 'content_contains':
					$content[] = '“' . $value . '”';
					break;
				case 'woocommerce':
					$parts[] = __( 'it is a WooCommerce page (shop, product, cart, checkout, account)', 'blt-optimized' );
					break;
				case 'front_page':
					$parts[] = __( 'it is the front page', 'blt-optimized' );
					break;
				case 'post_ids':
					/* translators: %s: comma-separated post IDs. */
					$parts[] = sprintf( __( 'the post ID is one of %s', 'blt-optimized' ), $value );
					break;
				case 'post_type':
					/* translators: %s: post type. */
					$parts[] = sprintf( __( 'it is a %s', 'blt-optimized' ), $value );
					break;
				case 'url_contains':
					/* translators: %s: URL fragment. */
					$parts[] = sprintf( __( 'the URL contains %s', 'blt-optimized' ), $value );
					break;
			}
		}
		if ( $content ) {
			/* translators: %s: list of shortcode/block markers. */
			array_unshift( $parts, sprintf( __( 'the content contains %s', 'blt-optimized' ), implode( ' / ', array_slice( $content, 0, 4 ) ) . ( count( $content ) > 4 ? ' …' : '' ) ) );
		}
		return implode( '; ' . __( 'or', 'blt-optimized' ) . ' ', $parts );
	}

	/**
	 * Add conditions that keep the asset on sampled pages that DO use it but
	 * would not match the base conditions (the form lives in a template or
	 * widget rather than in post_content, say).
	 *
	 * @param array[] $conditions Base conditions.
	 * @param array[] $pages      Prepared pages.
	 * @param array   $used       Page indexes (keys) where the family is used.
	 * @return array[]
	 */
	private static function augment_conditions( array $conditions, array $pages, array $used ) {
		$ids   = array();
		$front = false;
		$urls  = array();
		foreach ( array_keys( $used ) as $i ) {
			if ( ! isset( $pages[ $i ] ) || self::conditions_match( $conditions, $pages[ $i ] ) ) {
				continue;
			}
			$post_id = self::post_id_of( $pages[ $i ] );
			if ( $post_id > 0 ) {
				$ids[] = $post_id;
			} elseif ( self::is_front( $pages[ $i ] ) ) {
				$front = true;
			} else {
				$path = (string) strtok( self::ref( $pages, $i ), '?' );
				if ( '' !== $path && '/' !== $path ) {
					$urls[ $path ] = true;
				}
			}
		}
		$out = $conditions;
		if ( $front ) {
			$out[] = array(
				'type'  => 'front_page',
				'value' => '1',
			);
		}
		if ( $ids ) {
			$out[] = array(
				'type'  => 'post_ids',
				'value' => implode( ',', array_slice( array_values( array_unique( $ids ) ), 0, 50 ) ),
			);
		}
		foreach ( array_keys( $urls ) as $path ) {
			$out[] = array(
				'type'  => 'url_contains',
				'value' => $path,
			);
		}
		return $out;
	}

	/**
	 * Whether a page is the front page.
	 *
	 * @param array $page Page.
	 * @return bool
	 */
	private static function is_front( $page ) {
		return 'front_page' === self::v( $page, 'type', '' ) || ! empty( self::v( $page, array( 'profile', 'context', 'conditionals', 'is_front_page' ), false ) );
	}

	/**
	 * Would any of the asset-rule conditions keep the asset on this page?
	 * (Mirrors the semantics of BLT_Optimized_Tweaks asset rules.)
	 *
	 * @param array[] $conditions Conditions.
	 * @param array   $page       Page.
	 * @return bool
	 */
	private static function conditions_match( array $conditions, array $page ) {
		$post_id = self::post_id_of( $page );
		foreach ( $conditions as $condition ) {
			$value = (string) $condition['value'];
			switch ( $condition['type'] ) {
				case 'front_page':
					if ( self::is_front( $page ) ) {
						return true;
					}
					break;
				case 'post_type':
					if ( '' !== $value && ( (string) self::v( $page, array( 'post', 'type' ), '' ) === $value || (string) self::v( $page, array( 'profile', 'context', 'post_type' ), '' ) === $value ) ) {
						return true;
					}
					break;
				case 'post_ids':
					if ( $post_id > 0 && in_array( (string) $post_id, array_map( 'trim', explode( ',', $value ) ), true ) ) {
						return true;
					}
					break;
				case 'woocommerce':
					if ( true === self::is_woo_page( $page ) ) {
						return true;
					}
					break;
				case 'content_contains':
					$content = self::content_of( $post_id );
					if ( '' !== $value && '' !== $content && false !== stripos( $content, $value ) ) {
						return true;
					}
					break;
				case 'url_contains':
					if ( '' !== $value && false !== stripos( (string) self::v( $page, 'url', '' ), $value ) ) {
						return true;
					}
					break;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ */
	/* Specific asset findings                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * jQuery Migrate (legacy-API compatibility shim) still loading.
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_jquery_migrate( array $pages ) {
		$idx   = array();
		$bytes = 0;
		$rows  = array();
		foreach ( $pages as $i => $page ) {
			$asset = self::find_asset( $page, 'script', array( 'jquery-migrate' ), array( 'jquery-migrate' ) );
			if ( ! $asset ) {
				continue;
			}
			$idx[]  = $i;
			$bytes  = max( $bytes, $asset['bytes'] );
			$rows[] = array(
				self::ref( $pages, $i ),
				self::file_name( $asset['src'] ),
				$asset['bytes'] > 0 ? self::fmt_bytes( $asset['bytes'] ) : '',
			);
		}
		if ( ! $idx ) {
			return array();
		}
		$state = self::tweak_state( 'remove_jquery_migrate', 1, $pages, $idx );
		if ( false === $state ) {
			return array();
		}

		$summary = __( 'jQuery Migrate is a compatibility layer that keeps code written for jQuery versions from a decade ago working. Current WordPress themes and plugins rarely need it, yet it is an extra file to download and run (and it logs console warnings) on every page.', 'blt-optimized' );
		if ( null === $state ) {
			$fix      = self::fix_tweak(
				__( 'Remove jQuery Migrate', 'blt-optimized' ),
				'remove_jquery_migrate',
				1,
				__( 'Very old plugins or themes that call jQuery functions removed years ago can break without it. Check your key pages after enabling; you can switch it back off in Speed Tweaks.', 'blt-optimized' )
			);
			$summary .= ' ' . __( 'Removing it is safe on up-to-date sites; check your main pages afterwards.', 'blt-optimized' );
		} else {
			$fix = self::fix_manual(
				__( 'Find what loads it', 'blt-optimized' ),
				__( "The Remove jQuery Migrate tweak is already on, but something re-adds the script. Look for a plugin or theme that enqueues the handle 'jquery-migrate' itself, or a performance plugin that re-injects it.", 'blt-optimized' )
			);
		}

		return array(
			self::finding(
				array(
					'id'       => 'assets.jquery_migrate',
					'key'      => 'assets.jquery_migrate',
					'category' => 'assets',
					'severity' => 'notice',
					/* translators: %s: number of pages. */
					'title'    => sprintf( __( 'jQuery Migrate (legacy compatibility script) loads on %s', 'blt-optimized' ), self::n_pages( count( $idx ) ) ),
					'summary'  => $summary,
					'impact'   => $bytes > 0
						? array(
							'type'  => 'bytes',
							'value' => $bytes,
							'label' => '',
						)
						: array(
							'type'  => 'count',
							'value' => count( $idx ),
							'label' => '',
						),
					'component' => BLT_Optimized_Components::make( 'core', 'wordpress', __( 'WordPress core', 'blt-optimized' ) ),
					'where'    => self::urls( $pages, $idx ),
					'details'  => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'File', 'blt-optimized' ), __( 'Size', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => $fix,
				)
			),
		);
	}

	/**
	 * WordPress emoji detection script/styles still being printed.
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_emoji( array $pages ) {
		$idx   = array();
		$rows  = array();
		$bytes = 0;
		foreach ( $pages as $i => $page ) {
			$found = self::emoji_evidence( $page );
			if ( null === $found ) {
				continue;
			}
			$idx[]  = $i;
			$bytes  = max( $bytes, $found['bytes'] );
			$rows[] = array( self::ref( $pages, $i ), $found['what'] );
		}
		if ( ! $idx ) {
			return array();
		}
		$state = self::tweak_state( 'disable_emojis', 1, $pages, $idx );
		if ( false === $state ) {
			return array();
		}

		$summary = __( 'WordPress prints a small script and stylesheet on every page so older browsers can render emoji. Every current browser already does, so the script is dead weight on each visit — and without it, nothing visible changes.', 'blt-optimized' );
		if ( null === $state ) {
			$fix = self::fix_tweak( __( 'Disable emoji script', 'blt-optimized' ), 'disable_emojis', 1 );
		} else {
			$fix = self::fix_manual(
				__( 'Find what prints it', 'blt-optimized' ),
				__( 'The Disable emoji tweak is already on, but the emoji script is still printed: another plugin or theme re-adds it. Search its code for print_emoji_detection_script or wp-emoji.', 'blt-optimized' )
			);
		}

		return array(
			self::finding(
				array(
					'id'        => 'assets.emoji',
					'key'       => 'assets.emoji',
					'category'  => 'assets',
					'severity'  => 'notice',
					/* translators: %s: number of pages. */
					'title'     => sprintf( __( 'WordPress emoji script loads on %s', 'blt-optimized' ), self::n_pages( count( $idx ) ) ),
					'summary'   => $summary,
					'impact'    => $bytes > 0
						? array(
							'type'  => 'bytes',
							'value' => $bytes,
							'label' => '',
						)
						: array(
							'type'  => 'count',
							'value' => count( $idx ),
							'label' => '',
						),
					'component' => BLT_Optimized_Components::make( 'core', 'wordpress', __( 'WordPress core', 'blt-optimized' ) ),
					'where'     => self::urls( $pages, $idx ),
					'details'   => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Found', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'       => $fix,
				)
			),
		);
	}

	/**
	 * Evidence of the emoji script on a page, or null.
	 *
	 * Checks every place it can show up: an external wp-emoji-release.min.js,
	 * a script or style tag id starting wp-emoji, the inline style block id,
	 * an id token, or an enqueued handle.
	 *
	 * @param array $page Prepared page.
	 * @return array|null { what, bytes }
	 */
	private static function emoji_evidence( $page ) {
		$html = self::html( $page );
		foreach ( (array) ( isset( $html['scripts'] ) ? $html['scripts'] : array() ) as $script ) {
			if ( is_array( $script ) && ( 0 === strpos( (string) ( isset( $script['id'] ) ? $script['id'] : '' ), 'wp-emoji' ) || false !== strpos( (string) ( isset( $script['src'] ) ? $script['src'] : '' ), 'wp-emoji' ) ) ) {
				$src = (string) ( isset( $script['src'] ) ? $script['src'] : '' );
				return array(
					'what'  => '' !== $src ? self::file_name( $src ) : (string) $script['id'],
					'bytes' => '' !== $src ? self::local_bytes( $src ) : 0,
				);
			}
		}
		foreach ( (array) ( isset( $html['stylesheets'] ) ? $html['stylesheets'] : array() ) as $style ) {
			if ( is_array( $style ) && 0 === strpos( (string) ( isset( $style['id'] ) ? $style['id'] : '' ), 'wp-emoji' ) ) {
				return array(
					'what'  => (string) $style['id'],
					'bytes' => 0,
				);
			}
		}
		$largest = (string) self::v( $html, array( 'inline_styles', 'largest', 'id' ), '' );
		if ( 0 === strpos( $largest, 'wp-emoji' ) ) {
			return array(
				'what'  => $largest,
				'bytes' => (int) self::v( $html, array( 'inline_styles', 'largest', 'bytes' ), 0 ),
			);
		}
		foreach ( $page['_f']['tokens'] as $token ) {
			if ( 0 === strpos( $token, 'wp-emoji' ) || '_wpemojisettings' === $token ) {
				return array(
					'what'  => $token,
					'bytes' => 0,
				);
			}
		}
		$asset = self::find_asset( $page, 'script', array( 'wp-emoji*' ), array( 'wp-emoji' ) );
		if ( $asset ) {
			return array(
				'what'  => '' !== $asset['handle'] ? $asset['handle'] : self::file_name( $asset['src'] ),
				'bytes' => $asset['bytes'],
			);
		}
		return null;
	}

	/**
	 * Dashicons loaded for logged-out visitors.
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_dashicons( array $pages ) {
		$idx       = array();
		$rows      = array();
		$bytes     = 0;
		$requested = array();
		foreach ( $pages as $i => $page ) {
			if ( true === self::v( $page, array( 'profile', 'context', 'logged_in' ), false ) ) {
				continue;
			}
			$asset = self::find_asset( $page, 'style', array( 'dashicons' ), array( 'dashicons.min.css', 'dashicons.css' ) );
			if ( ! $asset ) {
				continue;
			}
			// A page that actually uses dashicon classes needs the font.
			if ( self::tokens_match( $page['_f']['tokens'], array( 'dashicons' ), array() ) ) {
				continue;
			}
			$idx[]  = $i;
			$bytes  = max( $bytes, $asset['bytes'] );
			$rows[] = array( self::ref( $pages, $i ), self::file_name( $asset['src'] ), $asset['bytes'] > 0 ? self::fmt_bytes( $asset['bytes'] ) : '' );
			foreach ( self::page_assets( $page ) as $other ) {
				if ( in_array( 'dashicons', $other['deps'], true ) ) {
					$requested[ self::comp_name( $other['component'] ) ] = true;
				}
			}
		}
		if ( ! $idx ) {
			return array();
		}
		$state = self::tweak_state( 'dashicons_logged_out', 1, $pages, $idx );
		if ( false === $state ) {
			return array();
		}

		$summary = __( 'Dashicons is the icon font of the WordPress admin. Logged-out visitors never see the admin bar, yet some themes and plugins still load the stylesheet (and its font file) for them, and nothing on these pages uses a dashicon.', 'blt-optimized' );
		if ( $requested ) {
			/* translators: %s: list of plugin or theme names. */
			$summary .= ' ' . sprintf( __( 'It is requested as a dependency by: %s.', 'blt-optimized' ), self::name_list( array_keys( $requested ), 3 ) );
		}
		if ( null === $state ) {
			$fix = self::fix_tweak(
				__( 'Remove Dashicons for visitors', 'blt-optimized' ),
				'dashicons_logged_out',
				1,
				__( 'This removes the Dashicons stylesheet for logged-out visitors only (you and other logged-in users are unaffected). If a plugin shows icons from Dashicons on the public site, they will disappear — check your main pages afterwards.', 'blt-optimized' )
			);
		} else {
			$fix = self::fix_manual(
				__( 'Find what loads it', 'blt-optimized' ),
				__( "The Dashicons tweak is already on, but the stylesheet is still loading — likely a plugin that prints it directly. Look for a link tag to dashicons.min.css in the plugin's templates.", 'blt-optimized' )
			);
		}

		return array(
			self::finding(
				array(
					'id'        => 'assets.dashicons',
					'key'       => 'assets.dashicons',
					'category'  => 'assets',
					'severity'  => 'notice',
					/* translators: %s: number of pages. */
					'title'     => sprintf( __( 'The admin icon font (Dashicons) loads for logged-out visitors on %s', 'blt-optimized' ), self::n_pages( count( $idx ) ) ),
					'summary'   => $summary,
					'impact'    => $bytes > 0
						? array(
							'type'  => 'bytes',
							'value' => $bytes,
							'label' => '',
						)
						: array(
							'type'  => 'count',
							'value' => count( $idx ),
							'label' => '',
						),
					'component' => BLT_Optimized_Components::make( 'core', 'wordpress', __( 'WordPress core', 'blt-optimized' ) ),
					'where'     => self::urls( $pages, $idx ),
					'details'   => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'File', 'blt-optimized' ), __( 'Size', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'       => $fix,
				)
			),
		);
	}

	/**
	 * WooCommerce cart fragments loading on pages that are not shop pages.
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_cart_fragments( array $pages ) {
		$idx     = array();
		$rows    = array();
		$boot_ms = 0.0;
		$bytes   = 0;
		foreach ( $pages as $i => $page ) {
			$asset = self::find_asset( $page, 'script', array( 'wc-cart-fragments' ), array( 'cart-fragments' ) );
			if ( ! $asset || false !== self::is_woo_page( $page ) ) {
				continue;
			}
			// A live mini-cart in the header or a widget needs the fragments.
			if ( self::tokens_match( $page['_f']['tokens'], array( 'widget_shopping_cart', 'wc-block-mini-cart', 'woocommerce-mini-cart', 'site-header-cart', 'cart-contents', 'wc-block-cart' ), array() ) ) {
				continue;
			}
			$idx[]   = $i;
			$bytes   = max( $bytes, $asset['bytes'] );
			$boot    = (float) self::v( $page, array( 'profile', 'timing', 'wp_loaded_ms' ), 0 );
			$boot_ms = max( $boot_ms, $boot );
			$type    = (string) self::v( $page, array( 'profile', 'context', 'type' ), (string) self::v( $page, 'type', '' ) );
			$rows[]  = array( self::ref( $pages, $i ), $type, $asset['bytes'] > 0 ? self::fmt_bytes( $asset['bytes'] ) : '' );
		}
		if ( ! $idx ) {
			return array();
		}
		$state = self::tweak_state( 'wc_cart_fragments', 'woo_pages_only', $pages, $idx );
		if ( false === $state ) {
			return array();
		}

		$summary = __( 'WooCommerce\'s cart-fragments script sends an extra uncached AJAX request on every page load to refresh the mini-cart, which starts WordPress and WooCommerce a second time on your server — even on pages with no cart, such as blog posts and the contact page.', 'blt-optimized' );
		if ( $boot_ms > 0 ) {
			/* translators: %s: time. */
			$summary .= ' ' . sprintf( __( 'Starting WordPress takes about %s here, and that cost is paid again for each of these visits.', 'blt-optimized' ), self::fmt_ms( $boot_ms ) );
		}
		if ( null === $state ) {
			$fix = self::fix_tweak(
				__( 'Limit cart fragments to shop pages', 'blt-optimized' ),
				'wc_cart_fragments',
				'woo_pages_only',
				__( 'The cart-fragments script will only load on shop, product, cart, checkout and account pages. A mini-cart in your header will then stop refreshing live on other pages (it still updates on a page reload). You can switch it back in Speed Tweaks.', 'blt-optimized' )
			);
		} else {
			$fix = self::fix_manual(
				__( 'Find what loads it', 'blt-optimized' ),
				__( 'The cart fragments tweak is already on, but wc-cart-fragments still loads on these pages: another plugin or theme enqueues it directly. Look for wp_enqueue_script( \'wc-cart-fragments\' ) in your theme or add-ons.', 'blt-optimized' )
			);
		}

		return array(
			self::finding(
				array(
					'id'        => 'assets.wc_cart_fragments',
					'key'       => 'assets.wc_cart_fragments',
					'category'  => 'assets',
					'severity'  => 'warning',
					/* translators: %s: number of pages. */
					'title'     => sprintf( __( 'WooCommerce cart fragments run on %s that are not shop pages', 'blt-optimized' ), self::n_pages( count( $idx ) ) ),
					'summary'   => $summary,
					'impact'    => $boot_ms > 0
						? self::impact_est( 'ms', (int) round( $boot_ms ) )
						: array(
							'type'  => 'count',
							'value' => count( $idx ),
							'label' => '',
						),
					'component' => BLT_Optimized_Components::make( 'plugin', 'woocommerce', BLT_Optimized_Components::plugin_name( 'woocommerce' ) ),
					'where'     => self::urls( $pages, $idx ),
					'details'   => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Page type', 'blt-optimized' ), __( 'Script size', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'       => $fix,
				)
			),
		);
	}

	/**
	 * Library definitions for duplicate detection.
	 *
	 * @return array[] key => { label, match: path|base, regex }.
	 */
	private static function library_signatures() {
		return array(
			'font-awesome' => array(
				'label' => 'Font Awesome',
				'match' => 'path',
				'regex' => '#font-?awesome#i',
			),
			'slick'        => array(
				'label' => 'Slick',
				'match' => 'base',
				'regex' => '#^slick([-.]|$)#i',
			),
			'swiper'       => array(
				'label' => 'Swiper',
				'match' => 'base',
				'regex' => '#^swiper#i',
			),
			'owl-carousel' => array(
				'label' => 'Owl Carousel',
				'match' => 'base',
				'regex' => '#owl[.\-]?carousel#i',
			),
			'select2'      => array(
				'label' => 'Select2',
				'match' => 'base',
				'regex' => '#^select2#i',
			),
			'bootstrap'    => array(
				'label' => 'Bootstrap',
				'match' => 'base',
				'regex' => '#^bootstrap#i',
			),
			'jquery-ui'    => array(
				'label' => 'jQuery UI',
				'match' => 'path',
				'regex' => '#jquery-ui|/jquery/ui/#i',
			),
			'lodash'       => array(
				'label' => 'Lodash',
				'match' => 'base',
				'regex' => '#^lodash#i',
			),
			'underscore'   => array(
				'label' => 'Underscore',
				'match' => 'base',
				'regex' => '#^underscore#i',
			),
			'moment'       => array(
				'label' => 'Moment.js',
				'match' => 'base',
				'regex' => '#^moment#i',
			),
			'jquery'       => array(
				'label' => 'jQuery',
				'match' => 'base',
				'regex' => '#^jquery([-.]\d[\d.]*)?(\.min)?\.js$#i',
			),
		);
	}

	/**
	 * The same JavaScript/CSS library loaded more than once.
	 *
	 * Two copies count as duplicates when they come from different owners
	 * (theme + plugin, two plugins, a plugin + core) or when the same file name
	 * is served from two places; companion files of one library from one
	 * owner (slick.js + slick-theme.css, font-awesome + its v4 shims) do not.
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_duplicate_libraries( array $pages ) {
		$signatures = self::library_signatures();
		$found      = array();

		foreach ( $pages as $i => $page ) {
			$per_lib = array();
			foreach ( self::page_assets( $page ) as $asset ) {
				if ( '' === $asset['src'] ) {
					continue;
				}
				$path = strtolower( (string) wp_parse_url( $asset['src'], PHP_URL_PATH ) );
				$base = basename( $path );
				foreach ( $signatures as $lib => $signature ) {
					$subject = 'path' === $signature['match'] ? $path : $base;
					if ( preg_match( $signature['regex'], $subject ) ) {
						$per_lib[ $lib . '|' . $asset['type'] ][ self::src_key( $asset['src'] ) ] = $asset;
						break;
					}
				}
			}
			foreach ( $per_lib as $lib_type => $assets ) {
				if ( count( $assets ) < 2 ) {
					continue;
				}
				$comps = array();
				$bases = array();
				foreach ( $assets as $asset ) {
					$comps[ BLT_Optimized_Components::key( $asset['component'] ) ] = true;
					$name    = strtolower( basename( (string) wp_parse_url( $asset['src'], PHP_URL_PATH ) ) );
					$name    = preg_replace( '/[-.]\d+(?:\.\d+)*(?=\.|$)/', '', $name );
					$name    = str_replace( '.min', '', $name );
					$bases[] = $name;
				}
				$repeated = max( array_count_values( $bases ) ) >= 2;
				if ( count( $comps ) < 2 && ! $repeated ) {
					continue;
				}
				list( $lib ) = explode( '|', $lib_type );
				$found[ $lib_type ]['lib']      = $lib;
				$found[ $lib_type ]['pages'][ $i ] = array_values( $assets );
			}
		}

		$findings = array();
		foreach ( $found as $lib_type => $entry ) {
			$label  = $signatures[ $entry['lib'] ]['label'];
			$rows   = array();
			$worst  = 0;
			$copies = 0;
			$names  = array();
			foreach ( $entry['pages'] as $i => $assets ) {
				$sizes = wp_list_pluck( $assets, 'bytes' );
				$extra = array_sum( $sizes ) - max( $sizes );
				$worst = max( $worst, $extra );
				$copies = max( $copies, count( $assets ) );
				foreach ( $assets as $asset ) {
					$handle = '' !== $asset['handle'] ? $asset['handle'] : self::file_name( $asset['src'] );
					$names[ $handle ] = true;
					$rows[]           = array(
						self::ref( $pages, $i ),
						$handle,
						self::comp_name( $asset['component'] ),
						self::file_name( $asset['src'] ),
						$asset['bytes'] > 0 ? self::fmt_bytes( $asset['bytes'] ) : '',
					);
				}
			}

			$title = sprintf(
				/* translators: 1: library name, 2: number of copies, 3: list of handles, 4: number of pages. */
				__( '%1$s is loaded %2$s times (%3$s) on %4$s', 'blt-optimized' ),
				$label,
				number_format_i18n( $copies ),
				self::name_list( array_keys( $names ), 3 ),
				self::n_pages( count( $entry['pages'] ) )
			);

			$findings[] = self::finding(
				array(
					'id'       => 'assets.duplicate_library',
					'key'      => 'assets.duplicate_library:' . $lib_type,
					'category' => 'assets',
					'severity' => 'warning',
					'title'    => $title,
					'summary'  => sprintf(
						/* translators: %s: library name. */
						__( 'Two or more copies of %s are shipped to the browser — typically a theme and a plugin (or two plugins) that each bundle their own. Visitors download and run the same code twice, and the copies can conflict.', 'blt-optimized' ),
						$label
					),
					'impact'   => $worst > 0
						? array(
							'type'  => 'bytes',
							'value' => $worst,
							'label' => '',
						)
						: array(
							'type'  => 'count',
							'value' => max( 1, $copies - 1 ),
							'label' => '',
						),
					'where'    => self::urls( $pages, array_keys( $entry['pages'] ) ),
					'details'  => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Handle', 'blt-optimized' ), __( 'Loaded by', 'blt-optimized' ), __( 'File', 'blt-optimized' ), __( 'Size', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => self::fix_manual(
						__( 'Keep one copy', 'blt-optimized' ),
						sprintf(
							/* translators: %s: library name. */
							__( "1. The \"Loaded by\" column names who loads each copy.\n2. Open the settings of the theme or plugin that is not your main one and switch off its bundled %s (look for options such as \"Load Font Awesome\", \"Enqueue library\" or \"Use theme icons\").\n3. If there is no option, an Asset rule in Speed Tweaks can stop the extra handle loading.", 'blt-optimized' ),
							$label
						)
					),
				)
			);
		}

		return $findings;
	}

	/**
	 * Too many third-party origins per page.
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_third_party( array $pages ) {
		$max   = (int) self::t( 'THIRD_PARTY_MAX' );
		$over  = array();
		$hosts = array();
		foreach ( $pages as $i => $page ) {
			$list = self::v( self::html( $page ), 'third_party', array() );
			if ( ! is_array( $list ) || count( $list ) <= $max ) {
				continue;
			}
			$over[ $i ] = count( $list );
			foreach ( $list as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['host'] ) ) {
					continue;
				}
				$host = (string) $entry['host'];
				if ( ! isset( $hosts[ $host ] ) ) {
					$hosts[ $host ] = array(
						'known'    => '',
						'requests' => 0,
						'kinds'    => array(),
						'pages'    => 0,
					);
				}
				$hosts[ $host ]['known']     = '' !== (string) ( isset( $entry['known'] ) ? $entry['known'] : '' ) ? (string) $entry['known'] : $hosts[ $host ]['known'];
				$hosts[ $host ]['requests']  = max( $hosts[ $host ]['requests'], (int) ( isset( $entry['requests'] ) ? $entry['requests'] : 0 ) );
				$hosts[ $host ]['kinds']     = array_values( array_unique( array_merge( $hosts[ $host ]['kinds'], array_map( 'strval', (array) ( isset( $entry['kinds'] ) ? $entry['kinds'] : array() ) ) ) ) );
				++$hosts[ $host ]['pages'];
			}
		}
		if ( ! $over ) {
			return array();
		}

		arsort( $over );
		$worst_i = (int) key( $over );
		$worst   = (int) reset( $over );
		uasort(
			$hosts,
			static function ( $a, $b ) {
				return array( $b['pages'], $b['requests'] ) <=> array( $a['pages'], $a['requests'] );
			}
		);
		$rows  = array();
		$names = array();
		foreach ( $hosts as $host => $entry ) {
			$rows[] = array(
				$host,
				$entry['known'],
				number_format_i18n( $entry['requests'] ),
				implode( ', ', $entry['kinds'] ),
				number_format_i18n( $entry['pages'] ),
			);
			$names[] = '' !== $entry['known'] ? $entry['known'] : $host;
		}

		/* translators: 1: number of origins, 2: page path, 3: list of services. */
		$title = sprintf( __( 'Pages pull from up to %1$s third-party origins (%2$s), including %3$s', 'blt-optimized' ), number_format_i18n( $worst ), self::ref( $pages, $worst_i ), self::name_list( $names, 3 ) );

		return array(
			self::finding(
				array(
					'id'       => 'assets.third_party',
					'key'      => 'assets.third_party',
					'category' => 'assets',
					'severity' => 'notice',
					'title'    => $title,
					'summary'  => sprintf(
						/* translators: %s: threshold. */
						__( 'Each extra origin means another DNS lookup, TLS handshake and a server you do not control sitting in your page load; more than %s usually means forgotten tracking tags, widgets and fonts. Removing the ones nobody uses is the cheapest speed-up there is.', 'blt-optimized' ),
						number_format_i18n( $max )
					),
					'impact'   => array(
						'type'  => 'count',
						'value' => $worst,
						'label' => sprintf(
							/* translators: %s: number of third-party origins. */
							_n( '%s origin', '%s origins', $worst, 'blt-optimized' ),
							number_format_i18n( $worst )
						),
					),
					'where'    => self::urls( $pages, array_keys( $over ) ),
					'details'  => array(
						'columns' => array( __( 'Host', 'blt-optimized' ), __( 'Service', 'blt-optimized' ), __( 'Requests', 'blt-optimized' ), __( 'Used for', 'blt-optimized' ), __( 'On pages', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => self::fix_manual(
						__( 'Trim third parties', 'blt-optimized' ),
						__( "1. Go through the Host/Service list and remove tags nobody uses any more (old analytics, pixels, chat widgets, social buttons).\n2. Load analytics and marketing tags through one tag manager, after consent.\n3. Self-host fonts and libraries instead of loading them from a public CDN where you can.\n4. For the ones you keep, make sure they load async or deferred.", 'blt-optimized' )
					),
				)
			),
		);
	}

	/**
	 * Google Fonts loaded without a preconnect to the font file origin.
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_font_preconnect( array $pages ) {
		$idx  = array();
		$rows = array();
		foreach ( $pages as $i => $page ) {
			$html = self::html( $page );
			if ( empty( self::v( $html, array( 'fonts', 'google_fonts' ), false ) ) ) {
				continue;
			}
			$preconnect = array_map( 'strval', (array) self::v( $html, array( 'hints', 'preconnect' ), array() ) );
			$has        = false;
			foreach ( $preconnect as $hint ) {
				if ( false !== stripos( $hint, 'fonts.gstatic.com' ) ) {
					$has = true;
					break;
				}
			}
			if ( $has ) {
				continue;
			}
			$idx[]  = $i;
			$rows[] = array( self::ref( $pages, $i ), $preconnect ? implode( ', ', array_slice( $preconnect, 0, 4 ) ) : __( '(none)', 'blt-optimized' ) );
		}
		if ( ! $idx ) {
			return array();
		}
		// Already saved? ("Contains" rather than exact: the setting is a list of origins.)
		$tweaks   = self::tweaks();
		$existing = trim( (string) ( isset( $tweaks['preconnect_origins'] ) ? $tweaks['preconnect_origins'] : '' ) );
		$state    = null;
		if ( false !== stripos( $existing, 'fonts.gstatic.com' ) ) {
			$state = false;
			foreach ( $idx as $i ) {
				if ( empty( $pages[ $i ]['tweaks_off'] ) ) {
					$state = true;
					break;
				}
			}
			if ( false === $state ) {
				return array();
			}
		}

		$summary = __( 'The page loads Google Fonts, whose font files come from fonts.gstatic.com. Without a preconnect hint the browser only opens that connection after it has downloaded and parsed the font stylesheet, so text appears later than it needs to. A preconnect lets the connection set-up overlap with other work.', 'blt-optimized' );
		if ( true === $state ) {
			$fix = self::fix_manual(
				__( 'Check the hint', 'blt-optimized' ),
				__( 'The preconnect origin is already saved in Speed Tweaks, but the page does not print it. Check that no caching/minification plugin strips resource hints, then purge its cache.', 'blt-optimized' )
			);
		} else {
			$fix = self::fix_tweak(
				__( 'Add preconnect for Google Fonts', 'blt-optimized' ),
				'preconnect_origins',
				'' !== $existing ? $existing . "\n" . 'https://fonts.gstatic.com' : 'https://fonts.gstatic.com'
			);
		}

		return array(
			self::finding(
				array(
					'id'       => 'assets.google_fonts_preconnect',
					'key'      => 'assets.google_fonts_preconnect',
					'category' => 'assets',
					'severity' => 'notice',
					/* translators: %s: number of pages. */
					'title'    => sprintf( __( 'Google Fonts load without a preconnect to fonts.gstatic.com on %s', 'blt-optimized' ), self::n_pages( count( $idx ) ) ),
					'summary'  => $summary,
					'impact'   => array(
						'type'  => 'count',
						'value' => count( $idx ),
						'label' => '',
					),
					'where'    => self::urls( $pages, $idx ),
					'details'  => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Preconnect hints present', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => $fix,
				)
			),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Content helpers                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Normalised images of a page, with the queried post attached to those
	 * that live in its content.
	 *
	 * @param array $page Page.
	 * @return array[]
	 */
	private static function page_images( $page ) {
		$out  = array();
		$post = ( isset( $page['post'] ) && is_array( $page['post'] ) && ! empty( $page['post']['id'] ) ) ? $page['post'] : null;
		$html = self::html( $page );
		foreach ( array_values( (array) ( isset( $html['images'] ) ? $html['images'] : array() ) ) as $n => $img ) {
			if ( ! is_array( $img ) || empty( $img['src'] ) || 0 === strpos( (string) $img['src'], 'data:' ) ) {
				continue;
			}
			$src    = (string) $img['src'];
			$format = strtolower( (string) ( isset( $img['format'] ) ? $img['format'] : '' ) );
			if ( '' === $format ) {
				$format = strtolower( pathinfo( (string) wp_parse_url( $src, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
				$format = 'jpeg' === $format ? 'jpg' : $format;
			}
			$in_content = ! empty( $img['in_content'] ) && null !== $post;
			$out[]      = array(
				'src'        => $src,
				'key'        => self::src_key( $src ),
				'file'       => self::file_name( $src ),
				'local'      => ! empty( $img['local'] ),
				'bytes'      => (int) ( isset( $img['bytes'] ) ? $img['bytes'] : 0 ),
				'natural_w'  => (int) ( isset( $img['natural_w'] ) ? $img['natural_w'] : 0 ),
				'natural_h'  => (int) ( isset( $img['natural_h'] ) ? $img['natural_h'] : 0 ),
				'attr_w'     => ( isset( $img['attr_w'] ) && (int) $img['attr_w'] > 0 ) ? (int) $img['attr_w'] : null,
				'attr_h'     => ( isset( $img['attr_h'] ) && (int) $img['attr_h'] > 0 ) ? (int) $img['attr_h'] : null,
				'has_srcset' => ! empty( $img['has_srcset'] ),
				'loading'    => strtolower( (string) ( isset( $img['loading'] ) ? $img['loading'] : '' ) ),
				'format'     => $format,
				'index'      => isset( $img['index'] ) ? (int) $img['index'] : $n,
				'in_content' => $in_content,
				'post'       => $in_content ? $post : null,
			);
		}
		return $out;
	}

	/**
	 * Where an image comes from, as a component: the plugin/theme directory
	 * its file lives in, else the theme that rendered the page's template.
	 *
	 * @param array $page Page.
	 * @param array $img  Normalised image.
	 * @return array|null
	 */
	private static function image_component( $page, array $img ) {
		if ( $img['post'] ) {
			return null;
		}
		$from = BLT_Optimized_Components::from_url( $img['src'] );
		if ( in_array( $from['type'], array( 'plugin', 'mu-plugin', 'theme' ), true ) ) {
			return $from;
		}
		$template = (string) self::v( $page, array( 'profile', 'context', 'template' ), '' );
		if ( '' !== $template && false === strpos( $template, 'template-canvas.php' ) ) {
			$component = BLT_Optimized_Components::from_path( trailingslashit( dirname( wp_normalize_path( WP_CONTENT_DIR ) ) ) . ltrim( $template, '/' ) );
			if ( in_array( $component['type'], array( 'plugin', 'mu-plugin', 'theme' ), true ) ) {
				return $component;
			}
		}
		return null;
	}

	/**
	 * Fix for a content problem: edit the post when the culprit is in its
	 * content, manual steps (naming the template) otherwise.
	 *
	 * @param array|null $post  Post block or null.
	 * @param string     $steps Manual steps for the non-post case.
	 * @return array
	 */
	private static function content_fix( $post, $steps ) {
		if ( is_array( $post ) && ! empty( $post['edit_url'] ) ) {
			return self::fix_link( __( 'Edit post', 'blt-optimized' ), esc_url_raw( (string) $post['edit_url'] ) );
		}
		return self::fix_manual( __( 'How to fix', 'blt-optimized' ), $steps );
	}

	/**
	 * "templates/front-page.php" style hint for where a non-content image is output.
	 *
	 * @param array[] $pages   Prepared pages.
	 * @param int[]   $indexes Page indexes.
	 * @return string Sentence fragment, '' when unknown.
	 */
	private static function template_hint( array $pages, array $indexes ) {
		$names = array();
		foreach ( $indexes as $i ) {
			$name = self::template_name( $pages[ $i ] );
			if ( '' !== $name ) {
				$names[ $name ] = true;
			}
		}
		if ( ! $names ) {
			return '';
		}
		/* translators: %s: list of template file names. */
		return sprintf( __( 'It is output by the theme template %s.', 'blt-optimized' ), self::name_list( array_keys( $names ), 3 ) );
	}

	/* ------------------------------------------------------------------ */
	/* Content checks                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Images without width/height attributes (cause layout shift).
	 *
	 * One finding per post (images in its content) or per image (everywhere
	 * else); beyond MAX_IMAGE_FINDINGS image groups the rest are merged.
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_missing_dimensions( array $pages ) {
		$groups = array();
		foreach ( $pages as $i => $page ) {
			foreach ( self::page_images( $page ) as $img ) {
				if ( null !== $img['attr_w'] && null !== $img['attr_h'] ) {
					continue;
				}
				// Skip 1x1 tracking pixels.
				if ( $img['natural_w'] > 0 && $img['natural_w'] <= 2 && $img['natural_h'] <= 2 ) {
					continue;
				}
				++self::$image_issues;
				$gkey = $img['post'] ? 'post:' . (int) $img['post']['id'] : 'img:' . $img['key'];
				if ( ! isset( $groups[ $gkey ] ) ) {
					$groups[ $gkey ] = array(
						'post'   => $img['post'],
						'items'  => array(),
						'pages'  => array(),
						'images' => array(),
						'first'  => $img,
					);
				}
				$groups[ $gkey ]['items'][]         = array( $i, $img );
				$groups[ $gkey ]['pages'][ $i ]     = true;
				$groups[ $gkey ]['images'][ $img['key'] ] = true;
			}
		}
		if ( ! $groups ) {
			return array();
		}

		$findings = array();
		$img_groups = array();
		foreach ( $groups as $gkey => $group ) {
			if ( $group['post'] ) {
				$findings[] = self::missing_dimensions_finding( $pages, $gkey, array( $group ) );
			} else {
				$img_groups[ $gkey ] = $group;
			}
		}
		uasort(
			$img_groups,
			static function ( $a, $b ) {
				return count( $b['pages'] ) <=> count( $a['pages'] );
			}
		);
		$cap  = (int) self::t( 'MAX_IMAGE_FINDINGS' );
		$head = array_slice( $img_groups, 0, $cap, true );
		$rest = array_slice( $img_groups, $cap, null, true );
		foreach ( $head as $gkey => $group ) {
			$findings[] = self::missing_dimensions_finding( $pages, $gkey, array( $group ) );
		}
		if ( $rest ) {
			$findings[] = self::missing_dimensions_finding( $pages, 'other', array_values( $rest ) );
		}
		return $findings;
	}

	/**
	 * One missing-dimensions finding for a post, an image, or the merged remainder.
	 *
	 * @param array[] $pages  Prepared pages.
	 * @param string  $gkey   Group key (post:ID, img:src or "other").
	 * @param array[] $groups One group, or several for the merged finding.
	 * @return array
	 */
	private static function missing_dimensions_finding( array $pages, $gkey, array $groups ) {
		$rows    = array();
		$idx     = array();
		$unique  = array();
		$first   = $groups[0]['first'];
		$post    = ( 1 === count( $groups ) ) ? $groups[0]['post'] : null;
		foreach ( $groups as $group ) {
			foreach ( $group['items'] as $item ) {
				list( $i, $img ) = $item;
				$idx[]           = $i;
				$unique[ $img['key'] ] = true;
				$missing         = array();
				if ( null === $img['attr_w'] ) {
					$missing[] = 'width';
				}
				if ( null === $img['attr_h'] ) {
					$missing[] = 'height';
				}
				$rows[] = array(
					self::ref( $pages, $i ),
					$img['file'],
					$img['natural_w'] > 0 ? $img['natural_w'] . ' × ' . $img['natural_h'] : '',
					sprintf(
						/* translators: %s: missing attributes, e.g. "width, height". */
						__( 'missing: %s', 'blt-optimized' ),
						implode( ', ', $missing )
					),
				);
			}
		}
		$count = count( $unique );

		if ( $post ) {
			$title = sprintf(
				/* translators: 1: post label, 2: number of images. */
				_n( '%1$s has %2$s image without width and height (causes layout shift)', '%1$s has %2$s images without width and height (causes layout shift)', $count, 'blt-optimized' ),
				self::post_label( $post ),
				number_format_i18n( $count )
			);
			$component = null;
		} elseif ( 'other' === $gkey ) {
			/* translators: %s: number of images. */
			$title     = sprintf( __( '%s more images have no width and height (cause layout shift)', 'blt-optimized' ), number_format_i18n( $count ) );
			$component = null;
		} else {
			$title = sprintf(
				/* translators: 1: image file name, 2: number of pages. */
				__( 'Image “%1$s” has no width and height on %2$s (causes layout shift)', 'blt-optimized' ),
				$first['file'],
				self::n_pages( count( array_unique( $idx ) ) )
			);
			$component = self::image_component( $pages[ $idx[0] ], $first );
		}

		$summary = __( 'Without width and height attributes the browser cannot reserve space for the image, so the text below it jumps down when the image arrives. That layout shift is part of Google\'s Core Web Vitals (CLS) and is what makes pages feel unstable.', 'blt-optimized' );
		$hint    = $post ? '' : self::template_hint( $pages, array_unique( $idx ) );
		if ( '' !== $hint ) {
			$summary .= ' ' . $hint;
		}

		$steps = __( "1. Add the image's real pixel size as width and height attributes (for example width=\"1200\" height=\"800\"); CSS can still scale it.\n2. If a theme template prints the image, use wp_get_attachment_image(), which adds the attributes automatically — or edit the template in the Site Editor.\n3. If a plugin prints it, ask its developer to include the attributes.", 'blt-optimized' );

		return self::finding(
			array(
				'id'        => 'content.missing_dimensions',
				'key'       => 'content.missing_dimensions:' . ( 'other' === $gkey ? 'other' : $gkey ),
				'category'  => 'content',
				'severity'  => 'warning',
				'title'     => $title,
				'summary'   => $summary,
				'impact'    => array(
					'type'  => 'count',
					'value' => $count,
					'label' => sprintf(
						/* translators: %s: number of images. */
						_n( '%s image', '%s images', $count, 'blt-optimized' ),
						number_format_i18n( $count )
					),
				),
				'component' => $component,
				'where'     => self::urls( $pages, array_unique( $idx ) ),
				'details'   => array(
					'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Image', 'blt-optimized' ), __( 'Natural size', 'blt-optimized' ), __( 'Attributes', 'blt-optimized' ) ),
					'rows'    => $rows,
				),
				'fix'       => self::content_fix( $post, $steps ),
			)
		);
	}

	/**
	 * Images much larger than needed: heavy files, or far wider than the
	 * space they are shown in. One finding per image.
	 *
	 * Impact = the file size of the image (what each page view downloads).
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_oversized_images( array $pages ) {
		$bytes_limit = (int) self::t( 'OVERSIZED_BYTES' );
		$ratio       = (float) self::t( 'OVERSIZED_RATIO' );
		$natural_max = (int) self::t( 'OVERSIZED_NATURAL_W' );
		$groups      = array();

		foreach ( $pages as $i => $page ) {
			foreach ( self::page_images( $page ) as $img ) {
				$reasons = array();
				if ( $img['bytes'] > $bytes_limit ) {
					/* translators: %s: file size. */
					$reasons[] = sprintf( __( 'the file is %s', 'blt-optimized' ), self::fmt_bytes( $img['bytes'] ) );
				}
				if ( ! $img['has_srcset'] && null !== $img['attr_w'] && $img['natural_w'] > $ratio * $img['attr_w'] ) {
					/* translators: 1: natural width, 2: displayed width. */
					$reasons[] = sprintf( __( 'it is %1$s px wide but shown at %2$s px', 'blt-optimized' ), number_format_i18n( $img['natural_w'] ), number_format_i18n( $img['attr_w'] ) );
				} elseif ( ! $img['has_srcset'] && null === $img['attr_w'] && $img['natural_w'] > $natural_max ) {
					/* translators: %s: natural width. */
					$reasons[] = sprintf( __( 'it is %s px wide with no smaller versions offered', 'blt-optimized' ), number_format_i18n( $img['natural_w'] ) );
				}
				if ( ! $reasons ) {
					continue;
				}
				++self::$image_issues;
				$gkey = 'img:' . $img['key'];
				if ( ! isset( $groups[ $gkey ] ) ) {
					$groups[ $gkey ] = array(
						'first' => $img,
						'post'  => $img['post'],
						'items' => array(),
						'pages' => array(),
					);
				}
				$groups[ $gkey ]['items'][]     = array( $i, $img, $reasons );
				$groups[ $gkey ]['pages'][ $i ] = true;
			}
		}
		if ( ! $groups ) {
			return array();
		}

		uasort(
			$groups,
			static function ( $a, $b ) {
				return $b['first']['bytes'] <=> $a['first']['bytes'];
			}
		);
		$cap      = (int) self::t( 'MAX_IMAGE_FINDINGS' );
		$findings = array();
		$n        = 0;
		$rest     = array();
		foreach ( $groups as $gkey => $group ) {
			if ( $n < $cap ) {
				$findings[] = self::oversized_finding( $pages, $gkey, $group );
				++$n;
			} else {
				$rest[ $gkey ] = $group;
			}
		}
		if ( $rest ) {
			$findings[] = self::oversized_rest_finding( $pages, $rest );
		}
		return $findings;
	}

	/**
	 * Finding for one oversized image.
	 *
	 * @param array[] $pages Prepared pages.
	 * @param string  $gkey  Group key.
	 * @param array   $group Group data.
	 * @return array
	 */
	private static function oversized_finding( array $pages, $gkey, array $group ) {
		$img   = $group['first'];
		$post  = $group['post'];
		$idx   = array_keys( $group['pages'] );
		$rows  = array();
		foreach ( $group['items'] as $item ) {
			list( $i, $row_img, $reasons ) = $item;
			$rows[]                        = array(
				self::ref( $pages, $i ),
				$row_img['file'],
				$row_img['bytes'] > 0 ? self::fmt_bytes( $row_img['bytes'] ) : '',
				$row_img['natural_w'] > 0 ? $row_img['natural_w'] . ' × ' . $row_img['natural_h'] : '',
				null !== $row_img['attr_w'] ? $row_img['attr_w'] . ' px' : '',
				ucfirst( implode( '; ', $reasons ) ),
			);
		}

		$what  = $img['bytes'] > 0
			/* translators: %s: file size. */
			? sprintf( __( 'a %s image', 'blt-optimized' ), self::fmt_bytes( $img['bytes'] ) )
			: __( 'an oversized image', 'blt-optimized' );
		$shown = '';
		if ( null !== $img['attr_w'] ) {
			/* translators: %s: displayed width in pixels. */
			$shown = ' ' . sprintf( __( 'displayed at %s px', 'blt-optimized' ), number_format_i18n( $img['attr_w'] ) );
		} elseif ( $img['natural_w'] > 0 ) {
			/* translators: %s: width in pixels. */
			$shown = ' ' . sprintf( __( '(%s px wide)', 'blt-optimized' ), number_format_i18n( $img['natural_w'] ) );
		}

		if ( $post ) {
			/* translators: 1: post label, 2: "a 3.8 MB image", 3: " displayed at 600 px". */
			$title = sprintf( __( '%1$s has %2$s%3$s', 'blt-optimized' ), self::post_label( $post ), $what, $shown );
		} else {
			/* translators: 1: "A 3.8 MB image", 2: file name, 3: " displayed at 600 px", 4: number of pages. */
			$title = sprintf( __( '%1$s (%2$s)%3$s is served on %4$s', 'blt-optimized' ), ucfirst( $what ), $img['file'], $shown, self::n_pages( count( $idx ) ) );
		}

		$summary = $img['bytes'] > 0
			/* translators: %s: file size. */
			? sprintf( __( 'Every visitor has to download %s for this one image before the page is complete.', 'blt-optimized' ), self::fmt_bytes( $img['bytes'] ) )
			: __( 'Every visitor downloads far more pixels for this image than the page can show.', 'blt-optimized' );
		if ( $img['bytes'] > 0 && null !== $img['attr_w'] && $img['natural_w'] > $img['attr_w'] ) {
			$target   = min( $img['natural_w'], $img['attr_w'] * 2 );
			$estimate = (int) round( $img['bytes'] * pow( $target / $img['natural_w'], 2 ) );
			if ( $estimate < $img['bytes'] * 0.9 ) {
				/* translators: 1: target width in pixels, 2: estimated size. */
				$summary .= ' ' . sprintf( __( 'Resized to about %1$s px (still sharp on high-density screens) it would weigh roughly %2$s.', 'blt-optimized' ), number_format_i18n( $target ), self::fmt_bytes( $estimate ) );
			}
		}
		if ( ! $post ) {
			$hint = self::template_hint( $pages, $idx );
			if ( '' !== $hint ) {
				$summary .= ' ' . $hint;
			}
		}

		$steps = __( "1. Replace the image with a version resized to the width it is actually displayed at (twice that for sharp high-density screens), or regenerate thumbnails so WordPress can serve a smaller size.\n2. Print it with wp_get_attachment_image() so WordPress adds srcset and the browser picks the right size.\n3. Compress it (and serve WebP) — the image optimization module can do this in bulk.", 'blt-optimized' );

		return self::finding(
			array(
				'id'        => 'content.oversized_image',
				'key'       => 'content.oversized_image:' . $gkey,
				'category'  => 'content',
				'severity'  => 'warning',
				'title'     => $title,
				'summary'   => $summary,
				'impact'    => $img['bytes'] > 0
					? array(
						'type'  => 'bytes',
						'value' => $img['bytes'],
						'label' => '',
					)
					: array(
						'type'  => 'count',
						'value' => 1,
						'label' => '',
					),
				'component' => self::image_component( $pages[ $idx[0] ], $img ),
				'where'     => array_merge( self::urls( $pages, $idx ), array( $img['src'] ) ),
				'details'   => array(
					'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Image', 'blt-optimized' ), __( 'File size', 'blt-optimized' ), __( 'Natural size', 'blt-optimized' ), __( 'Displayed at', 'blt-optimized' ), __( 'Why', 'blt-optimized' ) ),
					'rows'    => $rows,
				),
				'fix'       => self::content_fix( $post, $steps ),
			)
		);
	}

	/**
	 * Merged finding for the oversized images beyond the per-image cap.
	 *
	 * @param array[] $pages Prepared pages.
	 * @param array[] $rest  Remaining groups.
	 * @return array
	 */
	private static function oversized_rest_finding( array $pages, array $rest ) {
		$rows  = array();
		$idx   = array();
		$bytes = 0;
		foreach ( $rest as $group ) {
			foreach ( $group['items'] as $item ) {
				list( $i, $img, $reasons ) = $item;
				$idx[]                     = $i;
				$bytes                    += $img['bytes'];
				$rows[]                    = array(
					self::ref( $pages, $i ),
					$img['file'],
					$img['bytes'] > 0 ? self::fmt_bytes( $img['bytes'] ) : '',
					$img['post'] ? self::post_label( $img['post'] ) : '',
					ucfirst( implode( '; ', $reasons ) ),
				);
			}
		}
		return self::finding(
			array(
				'id'       => 'content.oversized_image',
				'key'      => 'content.oversized_image:other',
				'category' => 'content',
				'severity' => 'warning',
				/* translators: %s: number of images. */
				'title'    => sprintf( __( '%s more images are much larger than needed', 'blt-optimized' ), number_format_i18n( count( $rest ) ) ),
				'summary'  => __( 'These images are heavy files or far wider than the space they are shown in, so visitors download pixels they never see. Start with the largest.', 'blt-optimized' ),
				'impact'   => array(
					'type'  => 'bytes',
					'value' => $bytes,
					'label' => '',
				),
				'where'    => self::urls( $pages, array_unique( $idx ) ),
				'details'  => array(
					'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Image', 'blt-optimized' ), __( 'File size', 'blt-optimized' ), __( 'In', 'blt-optimized' ), __( 'Why', 'blt-optimized' ) ),
					'rows'    => $rows,
				),
				'fix'      => self::fix_manual(
					__( 'How to fix', 'blt-optimized' ),
					__( "Resize each image to the width it is displayed at (twice that for high-density screens) and compress it. The image optimization module can do this in bulk.", 'blt-optimized' )
				),
			)
		);
	}

	/**
	 * The hero image (first image on the page) being lazy-loaded.
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_lazy_hero( array $pages ) {
		$max_index = (int) self::t( 'HERO_MAX_INDEX' );
		$min_width = (int) self::t( 'HERO_MIN_WIDTH' );
		$groups    = array();
		foreach ( $pages as $i => $page ) {
			$images = self::page_images( $page );
			foreach ( $images as $img ) {
				if ( $img['index'] > $max_index || 'lazy' !== $img['loading'] ) {
					continue;
				}
				// A small logo or icon is not what LCP is measured on.
				if ( $img['natural_w'] > 0 && $img['natural_w'] < $min_width ) {
					continue;
				}
				// An earlier, eagerly loaded image at least as big is the real hero; this one is fine.
				$area  = $img['natural_w'] * $img['natural_h'];
				$prior = 0;
				foreach ( $images as $other ) {
					if ( $other['index'] < $img['index'] && 'lazy' !== $other['loading'] ) {
						$prior = max( $prior, $other['natural_w'] * $other['natural_h'] );
					}
				}
				if ( $prior > 0 && ( $area <= 0 || $prior >= $area ) ) {
					continue;
				}
				++self::$image_issues;
				$gkey = 'img:' . $img['key'];
				if ( ! isset( $groups[ $gkey ] ) ) {
					$groups[ $gkey ] = array(
						'first' => $img,
						'post'  => $img['post'],
						'pages' => array(),
						'rows'  => array(),
					);
				}
				$groups[ $gkey ]['pages'][ $i ] = true;
				$groups[ $gkey ]['rows'][]      = array(
					self::ref( $pages, $i ),
					$img['file'],
					(string) ( $img['index'] ),
					$img['loading'],
					$img['bytes'] > 0 ? self::fmt_bytes( $img['bytes'] ) : '',
				);
			}
		}

		$findings = array();
		foreach ( $groups as $gkey => $group ) {
			$img  = $group['first'];
			$post = $group['post'];
			$idx  = array_keys( $group['pages'] );
			if ( $post ) {
				/* translators: 1: post label, 2: image file name. */
				$title = sprintf( __( '%1$s lazy-loads its hero image (%2$s), delaying the Largest Contentful Paint', 'blt-optimized' ), self::post_label( $post ), $img['file'] );
			} else {
				/* translators: 1: image file name, 2: number of pages. */
				$title = sprintf( __( 'The lazy-loaded hero image %1$s delays the Largest Contentful Paint on %2$s', 'blt-optimized' ), $img['file'], self::n_pages( count( $idx ) ) );
			}
			$summary = __( 'The first image on the page is usually what the browser measures the Largest Contentful Paint on. Marked loading="lazy", it is not even requested until layout is done, which can add a second or more before the page looks loaded.', 'blt-optimized' );
			if ( ! $post ) {
				$hint = self::template_hint( $pages, $idx );
				if ( '' !== $hint ) {
					$summary .= ' ' . $hint;
				}
			}
			$steps = __( "1. Remove loading=\"lazy\" from this image and give it fetchpriority=\"high\".\n2. In PHP: wp_get_attachment_image( \$id, \$size, false, array( 'loading' => 'eager', 'fetchpriority' => 'high' ) ).\n3. If a lazy-loading plugin or theme option adds the attribute, exclude the first image(s) in its settings. (WordPress 6.3+ skips lazy-loading for the first large image by itself — something here is overriding that.)", 'blt-optimized' );

			$findings[] = self::finding(
				array(
					'id'        => 'content.lazy_hero',
					'key'       => 'content.lazy_hero:' . $gkey,
					'category'  => 'content',
					'severity'  => 'warning',
					'title'     => $title,
					'summary'   => $summary,
					'impact'    => $img['bytes'] > 0
						? array(
							'type'  => 'bytes',
							'value' => $img['bytes'],
							'label' => '',
						)
						: array(
							'type'  => 'count',
							'value' => count( $idx ),
							'label' => '',
						),
					'component' => self::image_component( $pages[ $idx[0] ], $img ),
					'where'     => array_merge( self::urls( $pages, $idx ), array( $img['src'] ) ),
					'details'   => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Image', 'blt-optimized' ), __( 'Position', 'blt-optimized' ), __( 'Loading', 'blt-optimized' ), __( 'Size', 'blt-optimized' ) ),
						'rows'    => $group['rows'],
					),
					'fix'       => self::content_fix( $post, $steps ),
				)
			);
		}
		return $findings;
	}

	/**
	 * Large JPEG/PNG images that WebP would shrink.
	 *
	 * One aggregate finding: this is a site-wide format decision, not a
	 * per-image defect. Impact = estimated saving on the worst page.
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_legacy_formats( array $pages ) {
		$limit   = (int) self::t( 'LEGACY_FORMAT_BYTES' );
		$ratio   = (float) self::t( 'WEBP_SAVING_RATIO' );
		$images  = array();
		$per_page = array();
		foreach ( $pages as $i => $page ) {
			foreach ( self::page_images( $page ) as $img ) {
				if ( ! in_array( $img['format'], array( 'jpg', 'jpeg', 'png' ), true ) || $img['bytes'] <= $limit ) {
					continue;
				}
				$key = $img['key'];
				if ( ! isset( $images[ $key ] ) ) {
					$images[ $key ] = array(
						'img'   => $img,
						'pages' => array(),
					);
				}
				++self::$image_issues;
				$images[ $key ]['pages'][ $i ] = true;
				$per_page[ $i ]                = ( isset( $per_page[ $i ] ) ? $per_page[ $i ] : 0 ) + $img['bytes'];
			}
		}
		if ( ! $images ) {
			return array();
		}

		uasort(
			$images,
			static function ( $a, $b ) {
				return $b['img']['bytes'] <=> $a['img']['bytes'];
			}
		);
		$total = 0;
		$rows  = array();
		$idx   = array();
		foreach ( $images as $entry ) {
			$img    = $entry['img'];
			$total += $img['bytes'];
			$idx    = array_merge( $idx, array_keys( $entry['pages'] ) );
			$refs   = array();
			foreach ( array_keys( $entry['pages'] ) as $i ) {
				$refs[] = self::ref( $pages, $i );
			}
			$rows[] = array(
				$img['file'],
				self::name_list( $refs, 3 ),
				self::fmt_bytes( $img['bytes'] ),
				strtoupper( $img['format'] ),
				self::fmt_bytes( (int) round( $img['bytes'] * ( 1 - $ratio ) ) ),
			);
		}
		$worst_saving = (int) round( max( $per_page ) * $ratio );
		$count        = count( $images );

		$enabled = self::images_enabled();
		$summary = __( 'JPEG and PNG files over 100 KB are usually 25–35% smaller as WebP at the same visual quality. Smaller images download faster, which helps the Largest Contentful Paint most on mobile connections.', 'blt-optimized' );
		if ( $enabled ) {
			$summary .= ' ' . __( 'Image optimization is switched on, but these files are still served in their original format — run a bulk optimization.', 'blt-optimized' );
			$fix      = self::fix_link( __( 'Open image optimization', 'blt-optimized' ), admin_url( 'admin.php?page=blt-optimizer' ) );
		} else {
			$fix = self::fix_link( __( 'Enable image optimization', 'blt-optimized' ), admin_url( 'admin.php?page=blt-optimized-settings' ) );
		}

		return array(
			self::finding(
				array(
					'id'       => 'content.legacy_format',
					'key'      => 'content.legacy_format',
					'category' => 'content',
					'severity' => 'notice',
					'title'    => sprintf(
						/* translators: 1: number of images, 2: total size. */
						_n( '%1$s JPEG/PNG image (%2$s) could be served as WebP', '%1$s JPEG/PNG images (%2$s together) could be served as WebP', $count, 'blt-optimized' ),
						number_format_i18n( $count ),
						self::fmt_bytes( $total )
					),
					'summary'  => $summary,
					'impact'   => self::impact_est( 'bytes', $worst_saving ),
					'where'    => self::urls( $pages, array_unique( $idx ) ),
					'details'  => array(
						'columns' => array( __( 'Image', 'blt-optimized' ), __( 'Used on', 'blt-optimized' ), __( 'Size', 'blt-optimized' ), __( 'Format', 'blt-optimized' ), __( 'As WebP (est.)', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => $fix,
				)
			),
		);
	}

	/**
	 * Iframes that are expensive to load: YouTube/Vimeo players and maps.
	 *
	 * Flagged when not lazy-loaded, or when a page carries two or more. One
	 * finding per post (embeds inside its content) or per template.
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_heavy_embeds( array $pages ) {
		$multi_at = (int) self::t( 'EMBED_MULTI' );
		$groups   = array();

		foreach ( $pages as $i => $page ) {
			$frames = array();
			foreach ( (array) self::v( self::html( $page ), 'iframes', array() ) as $frame ) {
				if ( ! is_array( $frame ) || empty( $frame['src'] ) ) {
					continue;
				}
				$kind = isset( $frame['kind'] ) ? (string) $frame['kind'] : '';
				if ( '' === $kind || 'other' === $kind ) {
					$host = strtolower( (string) ( isset( $frame['host'] ) ? $frame['host'] : BLT_Optimized_Components::url_host( (string) $frame['src'] ) ) );
					if ( false !== strpos( $host, 'youtube' ) || false !== strpos( $host, 'youtu.be' ) ) {
						$kind = 'youtube';
					} elseif ( false !== strpos( $host, 'vimeo' ) ) {
						$kind = 'vimeo';
					} elseif ( false !== strpos( (string) $frame['src'], 'google.com/maps' ) || false !== strpos( $host, 'maps.google' ) ) {
						$kind = 'maps';
					}
				}
				if ( ! in_array( $kind, array( 'youtube', 'vimeo', 'maps' ), true ) ) {
					continue;
				}
				$frames[] = array(
					'src'  => (string) $frame['src'],
					'kind' => $kind,
					'lazy' => 'lazy' === strtolower( (string) ( isset( $frame['loading'] ) ? $frame['loading'] : '' ) ),
				);
			}
			if ( ! $frames ) {
				continue;
			}

			$multi   = count( $frames ) >= $multi_at;
			$post    = ( isset( $page['post'] ) && is_array( $page['post'] ) && ! empty( $page['post']['id'] ) ) ? $page['post'] : null;
			$content = $post ? self::content_of( (int) $post['id'] ) : '';
			foreach ( $frames as $frame ) {
				if ( $frame['lazy'] && ! $multi ) {
					continue;
				}
				$in_content = $post && '' !== $content && self::iframe_in_content( $frame['src'], $frame['kind'], $content );
				$template   = self::template_name( $page );
				$gkey       = $in_content ? 'post:' . (int) $post['id'] : 'tpl:' . ( '' !== $template ? $template : self::ref( $pages, $i ) );
				if ( ! isset( $groups[ $gkey ] ) ) {
					$groups[ $gkey ] = array(
						'post'     => $in_content ? $post : null,
						'template' => $template,
						'per_page' => array(),
						'rows'     => array(),
					);
				}
				++self::$image_issues;
				$groups[ $gkey ]['per_page'][ $i ][] = $frame['kind'];
				$groups[ $gkey ]['rows'][]           = array(
					self::ref( $pages, $i ),
					self::embed_label( $frame['kind'] ),
					$frame['lazy'] ? __( 'lazy', 'blt-optimized' ) : __( 'loads immediately', 'blt-optimized' ),
					self::trunc( $frame['src'], 110 ),
				);
			}
		}

		$findings = array();
		foreach ( $groups as $gkey => $group ) {
			$idx = array_keys( $group['per_page'] );

			// Estimated weight on the worst page.
			$weights = self::EMBED_WEIGHTS;
			$worst   = 0;
			foreach ( $group['per_page'] as $frames ) {
				$sum = 0;
				foreach ( $frames as $kind ) {
					$sum += isset( $weights[ $kind ] ) ? (int) $weights[ $kind ] : (int) $weights['other'];
				}
				$worst = max( $worst, $sum );
			}

			// "2 YouTube videos and 1 Google Map": the most seen of each kind on a single page.
			$counts = array();
			foreach ( $group['per_page'] as $frames ) {
				foreach ( array_count_values( $frames ) as $kind => $n ) {
					$counts[ $kind ] = max( isset( $counts[ $kind ] ) ? $counts[ $kind ] : 0, $n );
				}
			}
			$parts = array();
			foreach ( $counts as $kind => $n ) {
				$parts[] = self::embed_count_phrase( $kind, $n );
			}
			$what = wp_sprintf( '%l', $parts );

			if ( $group['post'] ) {
				/* translators: 1: post label, 2: "3 YouTube videos". */
				$title = sprintf( __( '%1$s embeds %2$s', 'blt-optimized' ), self::post_label( $group['post'] ), $what );
			} elseif ( '' !== $group['template'] ) {
				/* translators: 1: template file name, 2: "a Google Map", 3: number of pages. */
				$title = sprintf( __( 'Template “%1$s” embeds %2$s on %3$s', 'blt-optimized' ), $group['template'], $what, self::n_pages( count( $idx ) ) );
			} else {
				/* translators: 1: page path, 2: "2 YouTube videos". */
				$title = sprintf( __( '%1$s embeds %2$s', 'blt-optimized' ), self::ref( $pages, $idx[0] ), $what );
			}

			$summary = __( 'Each video or map iframe makes the browser download a full player or map application (roughly 0.4–0.6 MB of scripts each) and open connections to third-party servers before anyone presses play. Lazy-loading the iframe, or showing a click-to-load thumbnail instead, defers all of that until it is needed.', 'blt-optimized' );
			if ( $group['post'] ) {
				$summary .= ' ' . __( 'In the editor, set the embed to load lazily or swap it for a click-to-load (facade) embed.', 'blt-optimized' );
			}
			$steps = __( "1. Add loading=\"lazy\" to the iframe, or replace it with a click-to-load thumbnail (a \"facade\") that loads the real player when clicked.\n2. For YouTube, use the privacy-friendly youtube-nocookie.com address.\n3. Keep at most one heavy embed above the fold.", 'blt-optimized' );

			$findings[] = self::finding(
				array(
					'id'       => 'content.heavy_embeds',
					'key'      => 'content.heavy_embeds:' . $gkey,
					'category' => 'content',
					'severity' => 'warning',
					'title'    => $title,
					'summary'  => $summary,
					'impact'   => self::impact_est( 'bytes', $worst ),
					'where'    => self::urls( $pages, $idx ),
					'details'  => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Embed', 'blt-optimized' ), __( 'Loading', 'blt-optimized' ), __( 'Source', 'blt-optimized' ) ),
						'rows'    => $group['rows'],
					),
					'fix'      => self::content_fix( $group['post'], $steps ),
				)
			);
		}
		return $findings;
	}

	/**
	 * Whether an iframe src appears in the post's raw content.
	 *
	 * @param string $src     Iframe src.
	 * @param string $kind    youtube|vimeo|maps.
	 * @param string $content post_content.
	 * @return bool
	 */
	private static function iframe_in_content( $src, $kind, $content ) {
		$src = html_entity_decode( (string) $src );
		if ( 'youtube' === $kind && preg_match( '#(?:embed/|v=|youtu\.be/|/vi/)([\w-]{6,})#', $src, $m ) ) {
			return false !== strpos( $content, $m[1] );
		}
		if ( 'vimeo' === $kind && preg_match( '#vimeo\.com/(?:video/)?(\d+)#', $src, $m ) ) {
			return false !== strpos( $content, $m[1] );
		}
		return false !== strpos( $content, substr( $src, 0, 100 ) ) || false !== strpos( $content, (string) strtok( $src, '?' ) );
	}

	/**
	 * Display name of an embed kind.
	 *
	 * @param string $kind Kind.
	 * @return string
	 */
	private static function embed_label( $kind ) {
		$labels = array(
			'youtube' => 'YouTube',
			'vimeo'   => 'Vimeo',
			'maps'    => __( 'Google Map', 'blt-optimized' ),
		);
		return isset( $labels[ $kind ] ) ? $labels[ $kind ] : $kind;
	}

	/**
	 * "2 YouTube videos" / "a Google Map".
	 *
	 * @param string $kind Kind.
	 * @param int    $n    Count.
	 * @return string
	 */
	private static function embed_count_phrase( $kind, $n ) {
		switch ( $kind ) {
			case 'youtube':
				/* translators: %s: number of YouTube videos. */
				return sprintf( _n( '%s YouTube video', '%s YouTube videos', $n, 'blt-optimized' ), number_format_i18n( $n ) );
			case 'vimeo':
				/* translators: %s: number of Vimeo videos. */
				return sprintf( _n( '%s Vimeo video', '%s Vimeo videos', $n, 'blt-optimized' ), number_format_i18n( $n ) );
			default:
				/* translators: %s: number of Google Maps. */
				return sprintf( _n( '%s Google Map', '%s Google Maps', $n, 'blt-optimized' ), number_format_i18n( $n ) );
		}
	}

	/**
	 * Oversized DOM.
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_dom_size( array $pages ) {
		$warn = (int) self::t( 'DOM_WARN' );
		$crit = (int) self::t( 'DOM_CRIT' );
		$over = array();
		foreach ( $pages as $i => $page ) {
			$nodes = (int) self::v( self::html( $page ), 'dom_nodes', 0 );
			if ( $nodes > $warn ) {
				$over[ $i ] = $nodes;
			}
		}
		if ( ! $over ) {
			return array();
		}

		arsort( $over );
		$worst_i = (int) key( $over );
		$worst   = (int) reset( $over );
		$rows    = array();
		$builder = '';
		foreach ( $over as $i => $nodes ) {
			$html    = self::html( $pages[ $i ] );
			$found   = self::page_builder( $pages[ $i ] );
			$builder = '' === $builder ? $found : $builder;
			$template = self::template_name( $pages[ $i ] );
			$rows[]  = array(
				self::ref( $pages, $i ),
				number_format_i18n( $nodes ),
				number_format_i18n( (int) self::v( $html, 'max_depth', 0 ) ),
				self::fmt_bytes( (int) self::v( $html, 'bytes', 0 ) ),
				trim( ( '' !== $found ? $found . ' · ' : '' ) . $template, ' ·' ),
			);
		}

		$summary = __( 'A very large DOM makes everything slower: more memory, slower style and layout work on every interaction, and a heavier HTML download. Google flags pages over about 1,500 elements.', 'blt-optimized' );
		if ( '' !== $builder ) {
			/* translators: %s: page builder name. */
			$summary .= ' ' . sprintf( __( '%s wraps content in many nested containers, which is a common cause.', 'blt-optimized' ), $builder );
		}

		/* translators: 1: number of elements, 2: page path, 3: recommended maximum. */
		$title = sprintf( __( 'Pages contain up to %1$s HTML elements (%2$s); the recommended maximum is %3$s', 'blt-optimized' ), number_format_i18n( $worst ), self::ref( $pages, $worst_i ), number_format_i18n( $warn ) );

		return array(
			self::finding(
				array(
					'id'       => 'content.dom_size',
					'key'      => 'content.dom_size',
					'category' => 'content',
					'severity' => $worst > $crit ? 'critical' : 'warning',
					'title'    => $title,
					'summary'  => $summary,
					'impact'   => array(
						'type'  => 'count',
						'value' => $worst,
						'label' => sprintf(
							/* translators: %s: number of elements. */
							_n( '%s element', '%s elements', $worst, 'blt-optimized' ),
							number_format_i18n( $worst )
						),
					),
					'where'    => self::urls( $pages, array_keys( $over ) ),
					'details'  => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Elements', 'blt-optimized' ), __( 'Deepest nesting', 'blt-optimized' ), __( 'HTML size', 'blt-optimized' ), __( 'Built with / template', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => self::fix_manual(
						__( 'Slim down the page', 'blt-optimized' ),
						__( "1. Paginate or load long lists (products, comments, archives) in smaller chunks.\n2. Remove sections, sliders and mega-menus that are hidden or rarely used.\n3. Replace deeply nested page-builder layouts with simpler blocks where you can.\n4. Re-scan to check the element count.", 'blt-optimized' )
					),
				)
			),
		);
	}

	/**
	 * Name of the page builder whose markup dominates a page, or ''.
	 *
	 * @param array $page Prepared page.
	 * @return string
	 */
	private static function page_builder( $page ) {
		$builders = array(
			'Elementor'    => array( 'elementor-section', 'elementor-element', 'e-con' ),
			'Divi'         => array( 'et_pb_' ),
			'WPBakery'     => array( 'vc_row', 'wpb_' ),
			'Beaver Builder' => array( 'fl-builder', 'fl-row' ),
			'Bricks'       => array( 'brxe-' ),
		);
		foreach ( $builders as $name => $needles ) {
			if ( self::tokens_match( $page['_f']['tokens'], $needles, array() ) ) {
				return $name;
			}
		}
		return '';
	}

	/**
	 * Heavy HTML documents.
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_html_size( array $pages ) {
		$limit = (int) self::t( 'HTML_WARN_BYTES' );
		$over  = array();
		foreach ( $pages as $i => $page ) {
			$bytes = (int) self::v( self::html( $page ), 'bytes', 0 );
			if ( $bytes > $limit ) {
				$over[ $i ] = $bytes;
			}
		}
		if ( ! $over ) {
			return array();
		}
		arsort( $over );
		$worst_i = (int) key( $over );
		$worst   = (int) reset( $over );
		$rows    = array();
		foreach ( $over as $i => $bytes ) {
			$html   = self::html( $pages[ $i ] );
			$rows[] = array(
				self::ref( $pages, $i ),
				self::fmt_bytes( $bytes ),
				self::fmt_bytes( (int) self::v( $html, array( 'inline_styles', 'bytes' ), 0 ) ),
				self::fmt_bytes( (int) self::v( $html, array( 'inline_scripts', 'bytes' ), 0 ) ),
				number_format_i18n( (int) self::v( $html, 'dom_nodes', 0 ) ),
			);
		}

		$worst_html = self::html( $pages[ $worst_i ] );
		$inline     = (int) self::v( $worst_html, array( 'inline_styles', 'bytes' ), 0 ) + (int) self::v( $worst_html, array( 'inline_scripts', 'bytes' ), 0 );
		$summary    = __( 'The HTML document must be downloaded and parsed before the browser can discover any stylesheet, script or image, so a heavy document delays everything that follows.', 'blt-optimized' );
		if ( $inline > 0 ) {
			/* translators: 1: inline size, 2: percentage of the document. */
			$summary .= ' ' . sprintf( __( 'On the largest page, %1$s of it (%2$s%%) is inline CSS and JavaScript.', 'blt-optimized' ), self::fmt_bytes( $inline ), number_format_i18n( $inline / max( 1, $worst ) * 100 ) );
		}

		/* translators: 1: page path, 2: size, 3: number of pages over the limit, 4: limit. */
		$title = sprintf( __( 'The HTML of %1$s is %2$s (%3$s over %4$s)', 'blt-optimized' ), self::ref( $pages, $worst_i ), self::fmt_bytes( $worst ), self::n_pages( count( $over ) ), self::fmt_bytes( $limit ) );

		return array(
			self::finding(
				array(
					'id'       => 'content.html_size',
					'key'      => 'content.html_size',
					'category' => 'content',
					'severity' => 'warning',
					'title'    => $title,
					'summary'  => $summary,
					'impact'   => array(
						'type'  => 'bytes',
						'value' => $worst,
						'label' => '',
					),
					'where'    => self::urls( $pages, array_keys( $over ) ),
					'details'  => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'HTML size', 'blt-optimized' ), __( 'Inline CSS', 'blt-optimized' ), __( 'Inline JS', 'blt-optimized' ), __( 'Elements', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => self::fix_manual(
						__( 'Shrink the document', 'blt-optimized' ),
						__( "1. Show fewer items per page (products, posts, comments) and paginate the rest.\n2. Move large inline CSS/JS into cacheable files, and drop sections nobody scrolls to.\n3. Make sure gzip/brotli compression is on so the transfer is a fraction of this size.", 'blt-optimized' )
					),
				)
			),
		);
	}

	/**
	 * Large inline CSS and inline JavaScript.
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_inline_assets( array $pages ) {
		$findings = array();

		$css_limit = (int) self::t( 'INLINE_CSS_WARN_BYTES' );
		$css       = array();
		foreach ( $pages as $i => $page ) {
			$bytes = (int) self::v( self::html( $page ), array( 'inline_styles', 'bytes' ), 0 );
			if ( $bytes > $css_limit ) {
				$css[ $i ] = $bytes;
			}
		}
		if ( $css ) {
			arsort( $css );
			$worst_i = (int) key( $css );
			$worst   = (int) reset( $css );
			$rows    = array();
			foreach ( $css as $i => $bytes ) {
				$largest = self::v( self::html( $pages[ $i ] ), array( 'inline_styles', 'largest' ), array() );
				$id      = (string) self::v( $largest, 'id', '' );
				$owner   = self::inline_style_owner( $pages[ $i ], $id );
				$rows[]  = array(
					self::ref( $pages, $i ),
					self::fmt_bytes( $bytes ),
					'' !== $id ? $id : '',
					(int) self::v( $largest, 'bytes', 0 ) > 0 ? self::fmt_bytes( (int) $largest['bytes'] ) : '',
					$owner ? self::comp_name( $owner ) : '',
				);
			}
			$largest = self::v( self::html( $pages[ $worst_i ] ), array( 'inline_styles', 'largest' ), array() );
			$id      = (string) self::v( $largest, 'id', '' );
			$owner   = self::inline_style_owner( $pages[ $worst_i ], $id );

			/* translators: 1: size, 2: page path. */
			$title = sprintf( __( 'Inline CSS totals %1$s on %2$s', 'blt-optimized' ), self::fmt_bytes( $worst ), self::ref( $pages, $worst_i ) );
			if ( '' !== $id ) {
				/* translators: 1: element id, 2: size. */
				$title .= ' ' . sprintf( __( '(largest block: %1$s, %2$s)', 'blt-optimized' ), $id, self::fmt_bytes( (int) self::v( $largest, 'bytes', 0 ) ) );
			}

			$findings[] = self::finding(
				array(
					'id'        => 'content.inline_css',
					'key'       => 'content.inline_css',
					'category'  => 'content',
					'severity'  => 'notice',
					'title'     => $title,
					'summary'   => __( 'CSS printed inside the page cannot be cached by the browser, so every page view re-downloads it, and it is parsed before anything is painted. Big blocks usually come from page builders, theme options or global style generators.', 'blt-optimized' ),
					'impact'    => array(
						'type'  => 'bytes',
						'value' => $worst,
						'label' => '',
					),
					'component' => $owner,
					'where'     => self::urls( $pages, array_keys( $css ) ),
					'details'   => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Inline CSS', 'blt-optimized' ), __( 'Largest block id', 'blt-optimized' ), __( 'Its size', 'blt-optimized' ), __( 'Likely owner', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'       => self::fix_manual(
						__( 'Move it into a file', 'blt-optimized' ),
						__( "1. Find the plugin or theme option that generates the largest block (named in the table) — builders usually offer \"external CSS file\" or \"CSS print method: external\".\n2. Remove unused custom CSS and unused theme/global style options.\n3. Re-scan to confirm.", 'blt-optimized' )
					),
				)
			);
		}

		$js_limit = (int) self::t( 'INLINE_JS_WARN_BYTES' );
		$js       = array();
		foreach ( $pages as $i => $page ) {
			$bytes = (int) self::v( self::html( $page ), array( 'inline_scripts', 'bytes' ), 0 );
			if ( $bytes > $js_limit ) {
				$js[ $i ] = $bytes;
			}
		}
		if ( $js ) {
			arsort( $js );
			$worst_i = (int) key( $js );
			$worst   = (int) reset( $js );
			$rows    = array();
			foreach ( $js as $i => $bytes ) {
				$rows[] = array(
					self::ref( $pages, $i ),
					self::fmt_bytes( $bytes ),
					number_format_i18n( (int) self::v( self::html( $pages[ $i ] ), array( 'inline_scripts', 'count' ), 0 ) ),
				);
			}
			$count = (int) self::v( self::html( $pages[ $worst_i ] ), array( 'inline_scripts', 'count' ), 0 );
			/* translators: 1: size, 2: page path, 3: number of inline script blocks. */
			$title = sprintf( __( 'Inline JavaScript totals %1$s on %2$s (%3$s blocks)', 'blt-optimized' ), self::fmt_bytes( $worst ), self::ref( $pages, $worst_i ), number_format_i18n( $count ) );

			$findings[] = self::finding(
				array(
					'id'       => 'content.inline_js',
					'key'      => 'content.inline_js',
					'category' => 'content',
					'severity' => 'notice',
					'title'    => $title,
					'summary'  => __( 'Inline scripts and data blobs cannot be cached between page views and are parsed on the main thread before the page is usable. This is typically settings JSON, tracking snippets or builder data printed by plugins.', 'blt-optimized' ),
					'impact'   => array(
						'type'  => 'bytes',
						'value' => $worst,
						'label' => '',
					),
					'where'    => self::urls( $pages, array_keys( $js ) ),
					'details'  => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Inline JS', 'blt-optimized' ), __( 'Script blocks', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => self::fix_manual(
						__( 'Find the source', 'blt-optimized' ),
						__( "1. View the page source and search for the largest <script> blocks — they usually start with a variable named after the plugin that prints them.\n2. Switch off that plugin feature (or the plugin) where it is not needed; use Asset rules to stop it printing on pages that do not use it.\n3. Move tracking snippets into one tag manager.", 'blt-optimized' )
					),
				)
			);
		}

		return $findings;
	}

	/**
	 * Owner of an inline style block, from the enqueued handle its id names
	 * ("…-inline-css" ids are generated from the handle).
	 *
	 * @param array  $page Prepared page.
	 * @param string $id   Element id.
	 * @return array|null Component.
	 */
	private static function inline_style_owner( $page, $id ) {
		if ( '' === $id ) {
			return null;
		}
		$handle = preg_replace( '/-inline-css$|-css$/', '', $id );
		foreach ( self::page_assets( $page ) as $asset ) {
			if ( 'style' === $asset['type'] && $asset['handle'] === $handle ) {
				return $asset['component'];
			}
		}
		if ( 0 === strpos( $handle, 'global-styles' ) || 0 === strpos( $handle, 'wp-' ) ) {
			return BLT_Optimized_Components::make( 'core', 'wordpress', __( 'WordPress core', 'blt-optimized' ) );
		}
		return null;
	}

	/**
	 * Pass finding when images were audited and none had a problem.
	 *
	 * @param array[] $pages Prepared pages.
	 * @return array[]
	 */
	private static function check_images_ok( array $pages ) {
		$images = 0;
		$with   = 0;
		foreach ( $pages as $page ) {
			$list = self::page_images( $page );
			if ( $list ) {
				++$with;
			}
			$images += count( $list );
		}
		if ( $images < 1 || self::$image_issues > 0 ) {
			return array();
		}
		return array(
			self::finding(
				array(
					'id'       => 'content.images_ok',
					'key'      => 'content.images_ok',
					'category' => 'content',
					'severity' => 'pass',
					/* translators: 1: number of images, 2: number of pages. */
					'title'    => sprintf( __( 'Images look healthy (%1$s checked across %2$s)', 'blt-optimized' ), number_format_i18n( $images ), self::n_pages( $with ) ),
					'summary'  => __( 'They have width and height set, sensible file sizes and display sizes, the first image is not lazy-loaded, and no heavy embeds were found.', 'blt-optimized' ),
				)
			),
		);
	}
}
