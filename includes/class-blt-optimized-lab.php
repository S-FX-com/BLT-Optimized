<?php
/**
 * Lab analysis — validates, summarises and interprets the built-in lab test.
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;

/**
 * The server half of the lab test (the PageSpeed Insights alternative).
 *
 * The browser measures a page in a visible same-origin iframe (assets/lab-
 * observer.js) and posts the raw numbers. This class does three jobs:
 *
 *  1. sanitize() — the security boundary. Whatever the browser posts is data
 *     from a client we do not control: it is bounded in size, decoded with a
 *     depth limit, and rebuilt field by field (types, ranges, caps, string
 *     lengths, control characters, http(s)-only URLs). Unknown keys are
 *     dropped. Attribution (which plugin or theme owns a file) is computed
 *     here from the URL, never taken from the payload.
 *  2. summary() / page_metrics() — the headline numbers and their ratings.
 *  3. findings() — turns the measurements of every measured page (and both
 *     viewports) into the same pointed, attributed findings the rest of the
 *     performance scan produces: one finding per culprit (an image, a layout-
 *     shifting element, a plugin's stylesheet or script), an evidence row per
 *     page and viewport, and the most direct fix available.
 *
 * Relation to BLT_Optimized_Page_Findings: that module reads the HTML the
 * server sends; this one reads what a real browser did with it. Where both
 * can see a problem the lab finding has its own id (`lab.*`), carries measured
 * timings and sizes and names the browser-found culprit; a lab finding is
 * skipped when it could only repeat what the HTML analysis already reports
 * (see check_oversized_images() and check_dom_size()).
 *
 * Every timing in these findings comes from the admin's own browser with no
 * CPU or network throttling, so the summaries say so.
 *
 * Finding ids: lab.lcp, lab.cls, lab.oversized_images, lab.offscreen_images,
 * lab.dom_size, lab.page_weight (content); lab.render_blocking, lab.unused_css,
 * lab.main_thread, lab.tbt, lab.fonts, lab.third_party (assets); lab.ttfb,
 * lab.unavailable, lab.unreliable (server); lab.pass (content).
 *
 * Filters: `blt_optimized_lab_thresholds` (constant name => value) and
 * `blt_optimized_lab_findings` (final list).
 */
class BLT_Optimized_Lab {

	/*
	 * Payload limits (sanitize).
	 */
	const PAYLOAD_VERSION    = 1;
	const MAX_PAYLOAD_BYTES  = 1572864; // 1.5 MB.
	const MAX_JSON_DEPTH     = 16;
	const MAX_TIME_MS        = 120000;
	const MAX_CLS            = 50;
	const MAX_BYTES          = 2000000000;
	const MAX_RESOURCES      = 300;
	const MAX_CSS            = 100;
	const MAX_IMAGES         = 150;
	const MAX_FONTS          = 40;
	const MAX_LOAF           = 20;
	const MAX_LOAF_SCRIPTS   = 30;
	const MAX_SHIFTS         = 10;
	const MAX_SOURCES        = 10;
	const MAX_LONG_TASKS     = 15;
	const MAX_WARNINGS       = 12;
	const MAX_BRICKS_LOOKUPS = 30;

	/*
	 * Thresholds. All are filterable as a set through
	 * `blt_optimized_lab_thresholds` (array keyed by constant name).
	 */

	// Core Web Vitals and friends: good / poor boundaries (Google's own).
	const LCP_GOOD  = 2500;
	const LCP_POOR  = 4000;
	const CLS_GOOD  = 0.1;
	const CLS_POOR  = 0.25;
	const FCP_GOOD  = 1800;
	const FCP_POOR  = 3000;
	const TTFB_GOOD = 800;
	const TTFB_POOR = 1800;
	const TBT_GOOD  = 200;
	const TBT_POOR  = 600;

	// Server.
	const TTFB_CRIT_MS = 3000;

	// LCP causes.
	const LCP_DELAY_MIN_MS     = 300;
	const LCP_DELAY_SHARE      = 0.25;
	const LCP_LOAD_SLOW_MS     = 800;
	const LCP_RENDER_DELAY_MS  = 400;
	const LCP_OVERSIZED_RATIO  = 1.5;
	const LCP_WASTED_MIN_BYTES = 20 * KB_IN_BYTES;

	// CLS.
	const CLS_MIN_SHARE      = 0.02;
	const CLS_MAX_FINDINGS   = 4;
	const CLS_FONT_WINDOW_MS = 400;
	const CLS_LATE_AFTER_MS  = 300;

	// Images.
	const OVERSIZED_RATIO        = 1.5;
	const OVERSIZED_WASTED_BYTES = 50 * KB_IN_BYTES;
	const OVERSIZED_WARN_BYTES   = 200 * KB_IN_BYTES;
	const OVERSIZED_MIN_DPR      = 2;
	const OFFSCREEN_MIN_BYTES    = 30 * KB_IN_BYTES;
	const OFFSCREEN_WARN_BYTES   = 300 * KB_IN_BYTES;
	const MAX_IMAGE_FINDINGS     = 6;

	// DOM and weight.
	const DOM_WARN                  = 1500;
	const DOM_CRIT                  = 3000;
	const DOM_JS_GROWTH             = 1.25;
	const DOM_DEPTH_WARN            = 32;
	const PAGE_WEIGHT_MOBILE_BYTES  = 2.5 * MB_IN_BYTES;
	const PAGE_WEIGHT_DESKTOP_BYTES = 4 * MB_IN_BYTES;

	// Assets.
	const RENDER_BLOCKING_NOTICE_MS    = 100;
	const RENDER_BLOCKING_WARN_MS      = 400;
	const RENDER_BLOCKING_MIN_FILES    = 3;
	const RENDER_BLOCKING_NOTICE_BYTES = 100 * KB_IN_BYTES;
	const RENDER_BLOCKING_WARN_BYTES   = 200 * KB_IN_BYTES;
	const UNUSED_CSS_WARN_BYTES        = 20 * KB_IN_BYTES;
	const UNUSED_CSS_WARN_RATIO        = 0.5;
	const UNUSED_CSS_NOTICE_BYTES      = 10 * KB_IN_BYTES;
	const MAIN_THREAD_WARN_MS          = 250;
	const MAIN_THREAD_NOTICE_MS        = 50;
	const FONT_FILES                   = 5;
	const FONT_BYTES                   = 200 * KB_IN_BYTES;
	const FONT_WARN_FILES              = 10;
	const FONT_WARN_BYTES              = 400 * KB_IN_BYTES;
	const THIRD_PARTY_BYTES            = 200 * KB_IN_BYTES;
	const THIRD_PARTY_WARN_BYTES       = 500 * KB_IN_BYTES;
	const MAX_THIRD_PARTY_FINDINGS     = 4;

	// Presentation.
	const MAX_ROWS_PER_RUN = 6;

	/**
	 * Warning codes a payload may carry (anything else is dropped).
	 */
	const WARNINGS = array( 'hidden_tab', 'framed_blocked', 'no_observer', 'timeout', 'no_lcp', 'no_loaf', 'no_longtask', 'css_truncated' );

	/**
	 * Resolved (filtered) thresholds for the current process.
	 *
	 * @var array|null
	 */
	private static $thresholds = null;

	/**
	 * Per-call memo for sanitize() (URL cleaning, component lookups, Bricks
	 * lookups). Reset at the start and end of every call.
	 *
	 * @var array
	 */
	private static $memo = array();

	/**
	 * Diagnostics from checks that threw during the last findings() call.
	 *
	 * @var string[]
	 */
	private static $errors = array();

	/* ------------------------------------------------------------------ */
	/* Public API                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Validate and normalise a payload posted by the browser.
	 *
	 * @param array|string $raw      Decoded payload or its JSON.
	 * @param string       $url      The page that was measured (must be on this site).
	 * @param string       $viewport mobile|desktop.
	 * @return array|WP_Error Sanitized lab result, or an error.
	 */
	public static function sanitize( $raw, $url, $viewport ) {
		self::reset_memo();

		try {
			$result = self::sanitize_payload( $raw, $url, $viewport );
		} catch ( \Throwable $e ) {
			$result = new WP_Error( 'blt_lab_invalid', __( 'The lab measurement could not be processed.', 'blt-optimized' ) );
		}

		self::reset_memo();
		return $result;
	}

	/**
	 * The headline numbers of one lab result, with ratings.
	 *
	 * @param array $lab Sanitized lab result.
	 * @return array { lcp, cls, tbt, fcp, ttfb, load, ratings, bytes, requests, blocking_count, unused_css_bytes, dom_nodes, warnings }
	 */
	public static function summary( array $lab ) {
		try {
			return self::summarize( $lab );
		} catch ( \Throwable $e ) {
			return self::summarize( array() );
		}
	}

	/**
	 * The work of summary().
	 *
	 * @param array $lab Lab result.
	 * @return array
	 */
	private static function summarize( array $lab ) {
		$nav = self::g( $lab, 'nav' );

		$lcp  = self::metric( self::g( self::g( $lab, 'lcp' ), 'time' ) );
		$cls  = self::metric( self::g( self::g( $lab, 'cls' ), 'value' ), true );
		$tbt  = self::metric( self::g( self::g( $lab, 'tbt' ), 'value' ), true );
		$fcp  = self::metric( self::g( $nav, 'fcp' ) );
		$ttfb = self::metric( self::g( $nav, 'ttfb' ) );
		$load = self::metric( self::g( $nav, 'load' ) );

		$bytes = array(
			'total' => 0,
			'js'    => 0,
			'css'   => 0,
			'img'   => 0,
			'font'  => 0,
			'other' => 0,
		);

		$requests = 0;
		$blocking = 0;
		foreach ( (array) self::g( $lab, 'resources', array() ) as $resource ) {
			if ( ! is_array( $resource ) ) {
				continue;
			}
			++$requests;
			if ( ! empty( $resource['blocking'] ) ) {
				++$blocking;
			}
			$size = self::resource_size( $resource );
			$kind = self::s( $resource, 'kind' );
			if ( ! isset( $bytes[ $kind ] ) || 'total' === $kind ) {
				$kind = self::kind_of( $resource );
			}
			if ( ! isset( $bytes[ $kind ] ) || 'total' === $kind ) {
				$kind = 'other';
			}
			$bytes[ $kind ] += $size;
			$bytes['total'] += $size;
		}

		// The HTML document itself (resource timing does not list it).
		if ( is_array( $nav ) ) {
			$doc = self::i( $nav, 'transfer' );
			if ( $doc <= 0 ) {
				$doc = self::i( $nav, 'encoded' );
			}
			if ( $doc > 0 || $requests > 0 ) {
				++$requests;
			}
			$bytes['other'] += $doc;
			$bytes['total'] += $doc;
		}

		$unused = 0;
		foreach ( (array) self::g( $lab, 'css', array() ) as $sheet ) {
			if ( is_array( $sheet ) && ! empty( $sheet['accessible'] ) ) {
				$unused += self::i( $sheet, 'unusedBytes' );
			}
		}

		$nodes = self::g( self::g( $lab, 'dom' ), 'nodes' );

		return array(
			'lcp'              => $lcp,
			'cls'              => $cls,
			'tbt'              => $tbt,
			'fcp'              => $fcp,
			'ttfb'             => $ttfb,
			'load'             => $load,
			'ratings'          => array(
				'lcp'  => self::rating( 'lcp', $lcp ),
				'cls'  => self::rating( 'cls', $cls ),
				'tbt'  => self::rating( 'tbt', $tbt ),
				'fcp'  => self::rating( 'fcp', $fcp ),
				'ttfb' => self::rating( 'ttfb', $ttfb ),
			),
			'bytes'            => $bytes,
			'requests'         => $requests,
			'blocking_count'   => $blocking,
			'unused_css_bytes' => $unused,
			'dom_nodes'        => ( is_int( $nodes ) || is_float( $nodes ) ) && is_finite( $nodes ) ? (int) $nodes : null,
			'warnings'         => array_values( array_filter( (array) self::g( $lab, 'warnings', array() ), 'is_string' ) ),
		);
	}

	/**
	 * 'good'|'needs-improvement'|'poor' for a metric value (null when unknown).
	 *
	 * @param string         $metric lcp|cls|tbt|fcp|ttfb.
	 * @param int|float|null $value  Value (ms, or unitless for CLS).
	 * @return string|null
	 */
	public static function rating( $metric, $value ) {
		$metric = strtolower( (string) $metric );
		if ( ! in_array( $metric, array( 'lcp', 'cls', 'tbt', 'fcp', 'ttfb' ), true ) || null === $value || ! is_numeric( $value ) ) {
			return null;
		}
		$good  = (float) self::t( strtoupper( $metric ) . '_GOOD' );
		$poor  = (float) self::t( strtoupper( $metric ) . '_POOR' );
		$value = (float) $value;
		if ( $value <= $good ) {
			return 'good';
		}
		return $value <= $poor ? 'needs-improvement' : 'poor';
	}

	/**
	 * Compact per-viewport summaries of a page report, for tables.
	 *
	 * @param array $page Page report (carrying `lab`).
	 * @return array { mobile: summary|null, desktop: summary|null }
	 */
	public static function page_metrics( array $page ) {
		$out = array(
			'mobile'  => null,
			'desktop' => null,
		);
		foreach ( array( 'mobile', 'desktop' ) as $viewport ) {
			$lab = self::g( self::g( $page, 'lab' ), $viewport );
			if ( is_array( $lab ) && $lab ) {
				$out[ $viewport ] = self::summary( $lab );
			}
		}
		return $out;
	}

	/**
	 * Diagnostics from checks that threw during the last findings() call.
	 *
	 * @return string[]
	 */
	public static function last_errors() {
		return self::$errors;
	}

	/* ------------------------------------------------------------------ */
	/* Sanitizing                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Forget per-call memoised lookups.
	 *
	 * @return void
	 */
	private static function reset_memo() {
		self::$memo = array(
			'urls'       => array(),
			'components' => array(),
			'bricks'     => array(),
			'lookups'    => 0,
			'slugs'      => null,
		);
	}

	/**
	 * The real work of sanitize() (separate so every failure path is contained).
	 *
	 * @param array|string $raw      Payload.
	 * @param string       $url      Page URL.
	 * @param string       $viewport Viewport name.
	 * @return array|WP_Error
	 */
	private static function sanitize_payload( $raw, $url, $viewport ) {
		$viewport = ( 'desktop' === $viewport ) ? 'desktop' : 'mobile';

		$data = self::decode( $raw );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$page_url = self::clean_url( is_string( $url ) ? $url : '' );
		if ( '' === $page_url || ! self::is_local_url( $page_url ) ) {
			return new WP_Error( 'blt_lab_url', __( 'The measured page must be on this site.', 'blt-optimized' ) );
		}

		$version = self::g( $data, 'version' );
		if ( ! is_int( $version ) || self::PAYLOAD_VERSION !== $version ) {
			return new WP_Error( 'blt_lab_version', __( 'This lab measurement comes from an unsupported version of the test script. Reload the page and run the test again.', 'blt-optimized' ) );
		}

		// The URL the browser says it measured: allowed to differ (a canonical
		// redirect), never allowed to point off this site.
		$final = '';
		$given = self::g( $data, 'url' );
		if ( is_string( $given ) && '' !== $given ) {
			$final = self::clean_url( $given );
			if ( '' === $final || ! self::is_local_url( $final ) ) {
				return new WP_Error( 'blt_lab_url', __( 'The measured page must be on this site.', 'blt-optimized' ) );
			}
			if ( $final === $page_url ) {
				$final = '';
			}
		}

		$vp = self::g( $data, 'viewport' );

		return array(
			'version'       => self::PAYLOAD_VERSION,
			'url'           => $page_url,
			'final_url'     => $final,
			'viewport_name' => $viewport,
			'measured_at'   => time(),
			'viewport'      => array(
				'w'   => self::to_int( self::g( $vp, 'w' ), 0, 20000 ),
				'h'   => self::to_int( self::g( $vp, 'h' ), 0, 20000 ),
				'dpr' => (float) self::to_num( self::g( $vp, 'dpr' ), 0, 10, 2, 1 ),
			),
			'nav'           => self::clean_nav( self::g( $data, 'nav' ) ),
			'lcp'           => self::clean_lcp( self::g( $data, 'lcp' ) ),
			'cls'           => self::clean_cls( self::g( $data, 'cls' ) ),
			'tbt'           => self::clean_tbt( self::g( $data, 'tbt' ) ),
			'loaf'          => self::clean_loaf( self::g( $data, 'loaf' ), $page_url ),
			'resources'     => self::clean_resources( self::g( $data, 'resources' ) ),
			'css'           => self::clean_css( self::g( $data, 'css' ) ),
			'images'        => self::clean_images( self::g( $data, 'images' ) ),
			'fonts'         => self::clean_fonts( self::g( $data, 'fonts' ) ),
			'dom'           => self::clean_dom( self::g( $data, 'dom' ) ),
			'collectedAt'   => self::to_num( self::g( $data, 'collectedAt' ), 0, 10000000 ),
			'warnings'      => self::clean_warnings( self::g( $data, 'warnings' ) ),
		);
	}

	/**
	 * Decode the posted payload, enforcing the size and depth limits.
	 *
	 * @param mixed $raw Array or JSON string.
	 * @return array|WP_Error
	 */
	private static function decode( $raw ) {
		$too_big = new WP_Error( 'blt_lab_too_large', __( 'The lab measurement is too large to store.', 'blt-optimized' ) );

		if ( is_string( $raw ) ) {
			if ( strlen( $raw ) > self::MAX_PAYLOAD_BYTES ) {
				return $too_big;
			}
			$data = json_decode( $raw, true, self::MAX_JSON_DEPTH, JSON_INVALID_UTF8_SUBSTITUTE );
		} elseif ( is_array( $raw ) ) {
			// Measured by walking it with a budget (never by encoding it): a request
			// can hand us an array whose values share one huge string many times over.
			$budget = self::MAX_PAYLOAD_BYTES;
			$status = self::within_budget( $raw, $budget, 0 );
			if ( 'size' === $status ) {
				return $too_big;
			}
			if ( 'ok' !== $status ) {
				return new WP_Error( 'blt_lab_invalid', __( 'The lab measurement is not valid data.', 'blt-optimized' ) );
			}
			$data = $raw;
		} else {
			return new WP_Error( 'blt_lab_invalid', __( 'The lab measurement is not valid data.', 'blt-optimized' ) );
		}

		if ( ! is_array( $data ) || array() === $data ) {
			return new WP_Error( 'blt_lab_invalid', __( 'The lab measurement is not valid data.', 'blt-optimized' ) );
		}
		return $data;
	}

	/**
	 * Walk an array, spending a byte budget; stop at the first sign of trouble.
	 *
	 * @param mixed $value  Value.
	 * @param int   $budget Remaining bytes (by reference).
	 * @param int   $depth  Nesting depth.
	 * @return string ok|size|invalid (invalid: too deep, or an object/resource).
	 */
	private static function within_budget( $value, &$budget, $depth ) {
		if ( is_array( $value ) ) {
			if ( $depth >= self::MAX_JSON_DEPTH ) {
				return 'invalid';
			}
			foreach ( $value as $key => $item ) {
				$budget -= strlen( (string) $key ) + 4;
				if ( $budget < 0 ) {
					return 'size';
				}
				$status = self::within_budget( $item, $budget, $depth + 1 );
				if ( 'ok' !== $status ) {
					return $status;
				}
			}
			return 'ok';
		}
		if ( is_string( $value ) ) {
			$budget -= strlen( $value ) + 2;
		} elseif ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			$budget -= 8;
		} else {
			return 'invalid';
		}
		return $budget < 0 ? 'size' : 'ok';
	}

	/**
	 * Navigation timing block.
	 *
	 * @param mixed $nav Raw.
	 * @return array|null
	 */
	private static function clean_nav( $nav ) {
		if ( ! is_array( $nav ) ) {
			return null;
		}
		$out = array();
		foreach ( array( 'ttfb', 'fcp', 'domInteractive', 'dcl', 'load' ) as $key ) {
			$out[ $key ] = self::to_num( self::g( $nav, $key ), 0, self::MAX_TIME_MS );
		}
		foreach ( array( 'transfer', 'encoded', 'decoded' ) as $key ) {
			$out[ $key ] = self::to_int( self::g( $nav, $key ), 0, self::MAX_BYTES );
		}
		$out['protocol'] = self::to_token( self::g( $nav, 'protocol' ), 20 );
		return $out;
	}

	/**
	 * Largest Contentful Paint block.
	 *
	 * @param mixed $lcp Raw.
	 * @return array|null
	 */
	private static function clean_lcp( $lcp ) {
		if ( ! is_array( $lcp ) ) {
			return null;
		}
		$time = self::to_num( self::g( $lcp, 'time' ), 0, self::MAX_TIME_MS );
		if ( null === $time ) {
			return null;
		}

		$url      = self::clean_url( self::g( $lcp, 'url' ) );
		$bricks   = self::to_id( self::g( $lcp, 'bricksId' ) );
		$resource = null;
		$res      = self::g( $lcp, 'resource' );
		if ( is_array( $res ) ) {
			$start = self::to_num( self::g( $res, 'start' ), 0, self::MAX_TIME_MS );
			$end   = self::to_num( self::g( $res, 'end' ), 0, self::MAX_TIME_MS );
			if ( null !== $start && null !== $end ) {
				$resource = array(
					'start'                => $start,
					'end'                  => $end,
					'renderBlockingBefore' => self::to_int( self::g( $res, 'renderBlockingBefore' ), 0, 1000 ),
				);
			}
		}

		return array(
			'time'          => $time,
			'size'          => self::to_int( self::g( $lcp, 'size' ), 0, self::MAX_BYTES ),
			'url'           => $url,
			'tag'           => self::to_token( self::g( $lcp, 'tag' ), 30 ),
			'selector'      => self::to_str( self::g( $lcp, 'selector' ), 200 ),
			'id'            => self::to_str( self::g( $lcp, 'id' ), 100 ),
			'bricksId'      => $bricks,
			'text'          => self::to_str( self::g( $lcp, 'text' ), 80, true ),
			'isImage'       => self::to_flag( self::g( $lcp, 'isImage' ) ),
			'loading'       => self::to_token( self::g( $lcp, 'loading' ), 12 ),
			'fetchpriority' => self::to_token( self::g( $lcp, 'fetchpriority' ), 12 ),
			'decoding'      => self::to_token( self::g( $lcp, 'decoding' ), 12 ),
			'naturalW'      => self::to_int( self::g( $lcp, 'naturalW' ), 0, 100000 ),
			'naturalH'      => self::to_int( self::g( $lcp, 'naturalH' ), 0, 100000 ),
			'renderedW'     => (float) self::to_num( self::g( $lcp, 'renderedW' ), 0, 100000, 1, 0 ),
			'renderedH'     => (float) self::to_num( self::g( $lcp, 'renderedH' ), 0, 100000, 1, 0 ),
			'isBackground'  => self::to_flag( self::g( $lcp, 'isBackground' ) ),
			'resource'      => $resource,
			'component'     => '' !== $url ? self::component_for( $url ) : null,
			'bricks'        => self::bricks_for( $bricks ),
		);
	}

	/**
	 * Cumulative Layout Shift block.
	 *
	 * @param mixed $cls Raw.
	 * @return array|null
	 */
	private static function clean_cls( $cls ) {
		if ( ! is_array( $cls ) ) {
			return null;
		}
		$value = self::to_num( self::g( $cls, 'value' ), 0, self::MAX_CLS, 4 );
		if ( null === $value ) {
			return null;
		}

		$shifts = array();
		foreach ( self::lst( self::g( $cls, 'shifts' ), self::MAX_SHIFTS ) as $shift ) {
			if ( ! is_array( $shift ) ) {
				continue;
			}
			$sources = array();
			foreach ( self::lst( self::g( $shift, 'sources' ), self::MAX_SOURCES ) as $source ) {
				if ( ! is_array( $source ) ) {
					continue;
				}
				$bricks    = self::to_id( self::g( $source, 'bricksId' ) );
				$sources[] = array(
					'selector' => self::to_str( self::g( $source, 'selector' ), 200 ),
					'tag'      => self::to_token( self::g( $source, 'tag' ), 30 ),
					'bricksId' => $bricks,
					'prevRect' => self::to_rect( self::g( $source, 'prevRect' ) ),
					'currRect' => self::to_rect( self::g( $source, 'currRect' ) ),
					'bricks'   => self::bricks_for( $bricks ),
				);
			}
			$shifts[] = array(
				'value'   => (float) self::to_num( self::g( $shift, 'value' ), 0, self::MAX_CLS, 4, 0 ),
				'time'    => (float) self::to_num( self::g( $shift, 'time' ), 0, self::MAX_TIME_MS, 1, 0 ),
				'sources' => $sources,
			);
		}
		usort(
			$shifts,
			static function ( $a, $b ) {
				return $b['value'] <=> $a['value'];
			}
		);

		return array(
			'value'  => $value,
			'shifts' => $shifts,
		);
	}

	/**
	 * Total Blocking Time block.
	 *
	 * @param mixed $tbt Raw.
	 * @return array|null
	 */
	private static function clean_tbt( $tbt ) {
		if ( ! is_array( $tbt ) ) {
			return null;
		}
		$value = self::to_num( self::g( $tbt, 'value' ), 0, self::MAX_TIME_MS );
		if ( null === $value ) {
			return null;
		}
		$tasks = array();
		foreach ( self::lst( self::g( $tbt, 'longTasks' ), self::MAX_LONG_TASKS ) as $task ) {
			if ( ! is_array( $task ) ) {
				continue;
			}
			$tasks[] = array(
				'start'    => (float) self::to_num( self::g( $task, 'start' ), 0, self::MAX_TIME_MS, 1, 0 ),
				'duration' => (float) self::to_num( self::g( $task, 'duration' ), 0, self::MAX_TIME_MS, 1, 0 ),
			);
		}
		return array(
			'value'     => $value,
			'longTasks' => $tasks,
			'count'     => self::to_int( self::g( $tbt, 'count' ), 0, 1000000 ),
		);
	}

	/**
	 * Long Animation Frames, with each script attributed to a component.
	 *
	 * @param mixed  $loaf     Raw.
	 * @param string $page_url The measured page (scripts whose source is the page itself are inline scripts).
	 * @return array[]
	 */
	private static function clean_loaf( $loaf, $page_url ) {
		$out  = array();
		$self = self::base_url( $page_url );
		foreach ( self::lst( $loaf, self::MAX_LOAF ) as $frame ) {
			if ( ! is_array( $frame ) ) {
				continue;
			}
			$scripts = array();
			foreach ( self::lst( self::g( $frame, 'scripts' ), self::MAX_LOAF_SCRIPTS ) as $script ) {
				if ( ! is_array( $script ) ) {
					continue;
				}
				$url = self::clean_url( self::g( $script, 'url' ) );
				if ( '' === $url || self::base_url( $url ) === $self ) {
					$component = self::inline_script_component();
					$url       = '';
				} else {
					$component = self::component_for( $url );
				}
				$scripts[] = array(
					'url'          => $url,
					'fn'           => self::to_str( self::g( $script, 'fn' ), 100, true ),
					'invoker'      => self::to_str( self::g( $script, 'invoker' ), 200, true ),
					'invokerType'  => self::to_token( self::g( $script, 'invokerType' ), 40 ),
					'duration'     => (float) self::to_num( self::g( $script, 'duration' ), 0, self::MAX_TIME_MS, 1, 0 ),
					'forcedLayout' => (float) self::to_num( self::g( $script, 'forcedLayout' ), 0, self::MAX_TIME_MS, 1, 0 ),
					'component'    => $component,
				);
			}
			$out[] = array(
				'start'    => (float) self::to_num( self::g( $frame, 'start' ), 0, self::MAX_TIME_MS, 1, 0 ),
				'duration' => (float) self::to_num( self::g( $frame, 'duration' ), 0, self::MAX_TIME_MS, 1, 0 ),
				'blocking' => (float) self::to_num( self::g( $frame, 'blocking' ), 0, self::MAX_TIME_MS, 1, 0 ),
				'scripts'  => $scripts,
			);
		}
		return $out;
	}

	/**
	 * Resource timing entries (sorted by start), each attributed to a component.
	 *
	 * @param mixed $resources Raw.
	 * @return array[]
	 */
	private static function clean_resources( $resources ) {
		$out = array();
		foreach ( self::lst( $resources, self::MAX_RESOURCES ) as $resource ) {
			if ( ! is_array( $resource ) ) {
				continue;
			}
			$url = self::clean_url( self::g( $resource, 'url' ) );
			if ( '' === $url ) {
				continue;
			}
			$transfer      = self::to_int( self::g( $resource, 'transfer' ), 0, self::MAX_BYTES );
			$encoded       = self::to_int( self::g( $resource, 'encoded' ), 0, self::MAX_BYTES );
			$entry         = array(
				'url'       => $url,
				'type'      => self::to_token( self::g( $resource, 'type' ), 20 ),
				'transfer'  => $transfer,
				'encoded'   => $encoded,
				'decoded'   => self::to_int( self::g( $resource, 'decoded' ), 0, self::MAX_BYTES ),
				'start'     => (float) self::to_num( self::g( $resource, 'start' ), 0, self::MAX_TIME_MS, 1, 0 ),
				'end'       => (float) self::to_num( self::g( $resource, 'end' ), 0, self::MAX_TIME_MS, 1, 0 ),
				'blocking'  => self::to_flag( self::g( $resource, 'blocking' ) ),
				// Derived here, never trusted from the browser.
				'cached'    => 0 === $transfer && $encoded > 0,
				'size'      => $transfer > 0 ? $transfer : $encoded,
				'component' => self::component_for( $url ),
			);
			$entry['kind'] = self::kind_of( $entry );
			$out[]         = $entry;
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $a['start'] <=> $b['start'];
			}
		);
		return $out;
	}

	/**
	 * Stylesheet usage entries.
	 *
	 * @param mixed $css Raw.
	 * @return array[]
	 */
	private static function clean_css( $css ) {
		$out = array();
		foreach ( self::lst( $css, self::MAX_CSS ) as $sheet ) {
			if ( ! is_array( $sheet ) ) {
				continue;
			}
			$href      = self::clean_url( self::g( $sheet, 'href' ) );
			$inline_id = self::to_str( self::g( $sheet, 'inlineId' ), 100 );
			$inline_id = preg_replace( '/[^A-Za-z0-9_:.\-]/', '', $inline_id );
			$bytes     = self::to_int( self::g( $sheet, 'bytes' ), 0, self::MAX_BYTES );
			$rules     = self::to_int( self::g( $sheet, 'rules' ), 0, 10000000 );
			$unused_b  = min( $bytes, self::to_int( self::g( $sheet, 'unusedBytes' ), 0, self::MAX_BYTES ) );
			$unused_r  = min( $rules, self::to_int( self::g( $sheet, 'unusedRules' ), 0, 10000000 ) );

			$out[] = array(
				'href'            => $href,
				'inlineId'        => $inline_id,
				'bytes'           => $bytes,
				'rules'           => $rules,
				'unusedRules'     => $unused_r,
				'unusedBytes'     => $unused_b,
				'otherMediaRules' => self::to_int( self::g( $sheet, 'otherMediaRules' ), 0, 10000000 ),
				'accessible'      => self::to_flag( self::g( $sheet, 'accessible' ) ),
				'component'       => '' !== $href ? self::component_for( $href ) : self::inline_style_component( $inline_id ),
			);
		}
		return $out;
	}

	/**
	 * Images in the document, attributed to a component (and a Bricks element).
	 *
	 * @param mixed $images Raw.
	 * @return array[]
	 */
	private static function clean_images( $images ) {
		$out = array();
		foreach ( self::lst( $images, self::MAX_IMAGES ) as $image ) {
			if ( ! is_array( $image ) ) {
				continue;
			}
			$src = self::clean_url( self::g( $image, 'src' ) );
			if ( '' === $src ) {
				continue;
			}
			$bricks = self::to_id( self::g( $image, 'bricksId' ) );
			$out[]  = array(
				'src'               => $src,
				'selector'          => self::to_str( self::g( $image, 'selector' ), 200 ),
				'bricksId'          => $bricks,
				'naturalW'          => self::to_int( self::g( $image, 'naturalW' ), 0, 100000 ),
				'naturalH'          => self::to_int( self::g( $image, 'naturalH' ), 0, 100000 ),
				'renderedW'         => (float) self::to_num( self::g( $image, 'renderedW' ), 0, 100000, 1, 0 ),
				'renderedH'         => (float) self::to_num( self::g( $image, 'renderedH' ), 0, 100000, 1, 0 ),
				'top'               => (float) self::to_num( self::g( $image, 'top' ), -1000000, 1000000, 1, 0 ),
				'inInitialViewport' => self::to_flag( self::g( $image, 'inInitialViewport' ) ),
				'loading'           => self::to_token( self::g( $image, 'loading' ), 12 ),
				'fetchpriority'     => self::to_token( self::g( $image, 'fetchpriority' ), 12 ),
				'decoding'          => self::to_token( self::g( $image, 'decoding' ), 12 ),
				'hasSrcset'         => self::to_flag( self::g( $image, 'hasSrcset' ) ),
				'sizes'             => self::to_str( self::g( $image, 'sizes' ), 200 ),
				'alt'               => self::to_flag( self::g( $image, 'alt' ) ),
				'complete'          => self::to_flag( self::g( $image, 'complete' ) ),
				'component'         => self::component_for( $src ),
				'bricks'            => self::bricks_for( $bricks ),
			);
		}
		return $out;
	}

	/**
	 * Web fonts the document used.
	 *
	 * @param mixed $fonts Raw.
	 * @return array[]
	 */
	private static function clean_fonts( $fonts ) {
		$out = array();
		foreach ( self::lst( $fonts, self::MAX_FONTS ) as $font ) {
			if ( ! is_array( $font ) ) {
				continue;
			}
			$family = self::to_str( self::g( $font, 'family' ), 100, true );
			if ( '' === $family ) {
				continue;
			}
			$out[] = array(
				'family'  => $family,
				'weight'  => self::to_str( self::g( $font, 'weight' ), 20, true ),
				'style'   => self::to_str( self::g( $font, 'style' ), 20, true ),
				'status'  => self::to_token( self::g( $font, 'status' ), 20 ),
				'display' => self::to_token( self::g( $font, 'display' ), 20 ),
			);
		}
		return $out;
	}

	/**
	 * DOM size block.
	 *
	 * @param mixed $dom Raw.
	 * @return array|null
	 */
	private static function clean_dom( $dom ) {
		if ( ! is_array( $dom ) ) {
			return null;
		}
		$nodes = self::to_int( self::g( $dom, 'nodes' ), 0, 10000000, -1 );
		if ( $nodes < 0 ) {
			return null;
		}
		return array(
			'nodes' => $nodes,
			'depth' => self::to_int( self::g( $dom, 'depth' ), 0, 10000 ),
		);
	}

	/**
	 * Warning codes (whitelist, deduplicated).
	 *
	 * @param mixed $warnings Raw.
	 * @return string[]
	 */
	private static function clean_warnings( $warnings ) {
		$out = array();
		foreach ( self::lst( $warnings, self::MAX_WARNINGS * 2 ) as $warning ) {
			if ( is_string( $warning ) && in_array( $warning, self::WARNINGS, true ) ) {
				$out[ $warning ] = true;
			}
		}
		return array_slice( array_keys( $out ), 0, self::MAX_WARNINGS );
	}

	/* ------------------------------------------------------------------ */
	/* Sanitizing primitives                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Null-safe array read.
	 *
	 * @param mixed      $data    Array (anything else yields the default).
	 * @param string|int $key     Key.
	 * @param mixed      $fallback Default.
	 * @return mixed
	 */
	private static function g( $data, $key, $fallback = null ) {
		return ( is_array( $data ) && isset( $data[ $key ] ) ) ? $data[ $key ] : $fallback;
	}

	/**
	 * Null-safe string read: a string or number comes back as a string, anything
	 * else (missing, null, array, object) as the default.
	 *
	 * @param mixed      $data    Array.
	 * @param string|int $key     Key.
	 * @param string     $fallback Default.
	 * @return string
	 */
	private static function s( $data, $key, $fallback = '' ) {
		$value = ( is_array( $data ) && isset( $data[ $key ] ) ) ? $data[ $key ] : null;
		return ( is_string( $value ) || is_int( $value ) || is_float( $value ) ) ? (string) $value : (string) $fallback;
	}

	/**
	 * Null-safe number read: a finite number (or numeric string) comes back as a
	 * float, anything else as the default.
	 *
	 * @param mixed      $data    Array.
	 * @param string|int $key     Key.
	 * @param int|float  $fallback Default.
	 * @return float
	 */
	private static function n( $data, $key, $fallback = 0 ) {
		$value = ( is_array( $data ) && isset( $data[ $key ] ) ) ? $data[ $key ] : null;
		if ( is_int( $value ) || is_float( $value ) || ( is_string( $value ) && is_numeric( $value ) ) ) {
			$value = (float) $value;
			if ( is_finite( $value ) ) {
				return $value;
			}
		}
		return (float) $fallback;
	}

	/**
	 * Null-safe whole-number read (finite, within the range of an int).
	 *
	 * @param mixed      $data    Array.
	 * @param string|int $key     Key.
	 * @param int|float  $fallback Default.
	 * @return int
	 */
	private static function i( $data, $key, $fallback = 0 ) {
		return (int) max( -2147483648, min( 2147483647, round( self::n( $data, $key, $fallback ) ) ) );
	}

	/**
	 * First $cap items of a list; anything that is not an array is an empty list.
	 *
	 * @param mixed $value Raw.
	 * @param int   $cap   Maximum items.
	 * @return array
	 */
	private static function lst( $value, $cap ) {
		return is_array( $value ) ? array_slice( array_values( $value ), 0, $cap ) : array();
	}

	/**
	 * A finite number within [min, max], rounded. Only real numbers count: a
	 * numeric string, boolean or array is "not a number" and yields the default.
	 *
	 * @param mixed          $value    Raw.
	 * @param int|float      $min      Minimum (values below are raised).
	 * @param int|float      $max      Maximum (values above are lowered).
	 * @param int            $decimals Decimals kept.
	 * @param int|float|null $fallback  Result for a non-number.
	 * @return float|int|null
	 */
	private static function to_num( $value, $min, $max, $decimals = 1, $fallback = null ) {
		if ( ! is_int( $value ) && ! is_float( $value ) ) {
			return $fallback;
		}
		$value = (float) $value;
		if ( is_nan( $value ) || is_infinite( $value ) ) {
			return $fallback;
		}
		return round( max( $min, min( $max, $value ) ), $decimals );
	}

	/**
	 * A whole number within [min, max].
	 *
	 * @param mixed $value   Raw.
	 * @param int   $min     Minimum.
	 * @param int   $max     Maximum.
	 * @param int   $fallback Result for a non-number.
	 * @return int
	 */
	private static function to_int( $value, $min, $max, $fallback = 0 ) {
		$number = self::to_num( $value, $min, $max, 0, null );
		return null === $number ? (int) $fallback : (int) $number;
	}

	/**
	 * Strict boolean: only true/false and 1/0 count.
	 *
	 * @param mixed $value Raw.
	 * @return bool
	 */
	private static function to_flag( $value ) {
		return true === $value || 1 === $value;
	}

	/**
	 * A clean display string: valid UTF-8, no control or bidi characters,
	 * collapsed whitespace, truncated (never longer than $max characters).
	 *
	 * @param mixed $value      Raw.
	 * @param int   $max        Maximum characters.
	 * @param bool  $strip_tags Also remove anything tag-like (free text).
	 * @return string
	 */
	private static function to_str( $value, $max, $strip_tags = false ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			$value = (string) $value;
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}
		// Bound the work first: a 1 MB string is cut before any regex touches it.
		$value = substr( $value, 0, ( $max * 4 ) + 8 );
		$value = wp_check_invalid_utf8( $value, true );
		if ( $strip_tags ) {
			$value = wp_strip_all_tags( $value );
		}
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $value );
		$value = (string) preg_replace( '/[\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2060}-\x{2069}\x{FEFF}]/u', '', $value );
		$value = trim( (string) preg_replace( '/\s{2,}/u', ' ', $value ) );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max );
		}
		return substr( $value, 0, $max );
	}

	/**
	 * A lowercase token ([a-z0-9_.:/-]) for enumerations such as loading="lazy".
	 *
	 * @param mixed $value Raw.
	 * @param int   $max   Maximum length.
	 * @return string
	 */
	private static function to_token( $value, $max ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}
		$value = strtolower( substr( $value, 0, $max * 2 ) );
		return substr( (string) preg_replace( '/[^a-z0-9_.:\/\-]/', '', $value ), 0, $max );
	}

	/**
	 * An element id token (Bricks ids are six lowercase letters/digits).
	 *
	 * @param mixed $value Raw.
	 * @return string
	 */
	private static function to_id( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}
		$value = substr( $value, 0, 64 );
		return preg_match( '/^[A-Za-z0-9_-]{1,32}$/', $value ) ? $value : '';
	}

	/**
	 * A rectangle as [x, y, width, height], or null when it is not one.
	 *
	 * @param mixed $value Raw.
	 * @return float[]|null
	 */
	private static function to_rect( $value ) {
		if ( ! is_array( $value ) ) {
			return null;
		}
		$value = array_values( $value );
		if ( count( $value ) < 4 ) {
			return null;
		}
		$rect = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$number = self::to_num( $value[ $i ], -1000000, 1000000 );
			if ( null === $number ) {
				return null;
			}
			$rect[] = (float) $number;
		}
		return $rect;
	}

	/**
	 * A safe http(s) URL: no whitespace, no angle brackets or quotes, at most
	 * 2,000 characters, the lab cache-busting query arguments removed.
	 * Anything else — javascript:, data:, blob:, relative paths — is ''.
	 *
	 * @param mixed $value Raw.
	 * @return string
	 */
	private static function clean_url( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}
		if ( isset( self::$memo['urls'][ $value ] ) ) {
			return self::$memo['urls'][ $value ];
		}

		$url = substr( trim( $value ), 0, 2000 );
		if ( ! preg_match( '#^https?://[^/?\#\s<>"\\\\]+(?:[/?\#][^\s<>"\\\\]*)?$#i', $url ) ) {
			$url = '';
		} else {
			$url = self::strip_bust( $url );
		}

		// Bound the memo too: a hostile payload can carry thousands of distinct values.
		if ( count( self::$memo['urls'] ) < 2000 ) {
			self::$memo['urls'][ $value ] = $url;
		}
		return $url;
	}

	/**
	 * Remove the lab mode's own query arguments from a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function strip_bust( $url ) {
		if ( false === strpos( $url, 'blt_lab=' ) && false === strpos( $url, 'blt_optimized_lab=' ) ) {
			return $url;
		}
		$hash = '';
		$pos  = strpos( $url, '#' );
		if ( false !== $pos ) {
			$hash = substr( $url, $pos );
			$url  = substr( $url, 0, $pos );
		}
		$qpos = strpos( $url, '?' );
		if ( false === $qpos ) {
			return $url . $hash;
		}
		$keep = array();
		foreach ( explode( '&', substr( $url, $qpos + 1 ) ) as $part ) {
			$name = explode( '=', $part, 2 );
			if ( '' === $part || 'blt_lab' === $name[0] || 'blt_optimized_lab' === $name[0] ) {
				continue;
			}
			$keep[] = $part;
		}
		return substr( $url, 0, $qpos ) . ( $keep ? '?' . implode( '&', $keep ) : '' ) . $hash;
	}

	/**
	 * A URL without its query string and fragment.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function base_url( $url ) {
		$cut = strcspn( (string) $url, '?#' );
		return rtrim( substr( (string) $url, 0, $cut ), '/' );
	}

	/**
	 * Whether a (clean, absolute) URL points at this site.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function is_local_url( $url ) {
		$host = BLT_Optimized_Components::url_host( $url );
		return '' !== $host && BLT_Optimized_Components::is_local_host( $host );
	}

	/* ------------------------------------------------------------------ */
	/* Attribution                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * The component that owns a (clean) URL, memoised for this call.
	 *
	 * @param string $url URL.
	 * @return array Component { type, slug, name }.
	 */
	private static function component_for( $url ) {
		if ( isset( self::$memo['components'][ $url ] ) ) {
			return self::$memo['components'][ $url ];
		}
		try {
			$component = BLT_Optimized_Components::from_url( $url );
		} catch ( \Throwable $e ) {
			$component = BLT_Optimized_Components::make( 'other', 'unknown', __( 'Unknown', 'blt-optimized' ) );
		}
		$component = array(
			'type' => isset( $component['type'] ) ? (string) $component['type'] : 'other',
			'slug' => isset( $component['slug'] ) ? (string) $component['slug'] : '',
			'name' => isset( $component['name'] ) ? (string) $component['name'] : '',
		);
		if ( count( self::$memo['components'] ) < 2000 ) {
			self::$memo['components'][ $url ] = $component;
		}
		return $component;
	}

	/**
	 * Component for scripts that run inline in the page's own HTML.
	 *
	 * @return array
	 */
	private static function inline_script_component() {
		return BLT_Optimized_Components::make( 'other', 'inline-scripts', __( 'Inline scripts in the page', 'blt-optimized' ) );
	}

	/**
	 * Best-effort component for an inline <style> from its id. WordPress names
	 * inline styles after the stylesheet handle ("woocommerce-inline-inline-css"),
	 * which usually starts with the owning plugin or theme slug.
	 *
	 * @param string $inline_id The <style> id ('' when it has none).
	 * @return array
	 */
	private static function inline_style_component( $inline_id ) {
		$unknown = BLT_Optimized_Components::make( 'other', 'inline-styles', __( 'Inline styles in the page', 'blt-optimized' ) );
		$stem    = strtolower( (string) $inline_id );
		if ( '' === $stem ) {
			return $unknown;
		}
		$stem = (string) preg_replace( '/-(?:inline-)?css$/', '', $stem );

		// Bricks writes its global and per-page CSS inline.
		if ( 0 === strpos( $stem, 'bricks' ) ) {
			$theme = wp_get_theme( 'bricks' );
			return BLT_Optimized_Components::make( 'theme', 'bricks', $theme->exists() ? (string) $theme->get( 'Name' ) : 'Bricks' );
		}

		// WordPress core's own inline styles.
		foreach ( array( 'global-styles', 'classic-theme-styles', 'wp-block', 'wp-img-auto-sizes', 'core-block', 'admin-bar', 'dashicons', 'wp-emoji' ) as $core ) {
			if ( 0 === strpos( $stem, $core ) ) {
				return BLT_Optimized_Components::make( 'core', 'WordPress', __( 'WordPress core', 'blt-optimized' ) );
			}
		}

		if ( null === self::$memo['slugs'] ) {
			$slugs = array();
			foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
				if ( ! is_string( $file ) || '' === $file ) {
					continue;
				}
				$dir     = dirname( $file );
				$slug    = ( '.' === $dir ) ? preg_replace( '/\.php$/', '', $file ) : $dir;
				$slugs[] = strtolower( (string) $slug );
			}
			// Longest first: "woocommerce-gateway-stripe" before "woocommerce".
			usort(
				$slugs,
				static function ( $a, $b ) {
					return strlen( $b ) <=> strlen( $a );
				}
			);
			self::$memo['slugs'] = $slugs;
		}

		$aliases = array(
			'wc-'   => 'woocommerce',
			'wpcf7' => 'contact-form-7',
		);
		foreach ( $aliases as $prefix => $slug ) {
			if ( 0 === strpos( $stem, $prefix ) && in_array( $slug, self::$memo['slugs'], true ) ) {
				return BLT_Optimized_Components::make( 'plugin', $slug, BLT_Optimized_Components::plugin_name( $slug ) );
			}
		}
		foreach ( self::$memo['slugs'] as $slug ) {
			if ( $stem === $slug || 0 === strpos( $stem, $slug . '-' ) ) {
				return BLT_Optimized_Components::make( 'plugin', $slug, BLT_Optimized_Components::plugin_name( $slug ) );
			}
		}
		foreach ( array_unique( array( strtolower( (string) get_stylesheet() ), strtolower( (string) get_template() ) ) ) as $slug ) {
			if ( '' !== $slug && ( $stem === $slug || 0 === strpos( $stem, $slug . '-' ) ) ) {
				$theme = wp_get_theme( $slug );
				return BLT_Optimized_Components::make( 'theme', $slug, $theme->exists() ? (string) $theme->get( 'Name' ) : $slug );
			}
		}
		if ( 0 === strpos( $stem, 'wp-' ) ) {
			return BLT_Optimized_Components::make( 'core', 'WordPress', __( 'WordPress core', 'blt-optimized' ) );
		}
		return $unknown;
	}

	/**
	 * Locate a Bricks element (memoised, at most MAX_BRICKS_LOOKUPS per payload).
	 *
	 * @param string $element_id Element id without the "brxe-" prefix.
	 * @return array|null Scalar details of the element, or null.
	 */
	private static function bricks_for( $element_id ) {
		if ( '' === $element_id || ! class_exists( 'BLT_Optimized_Bricks' ) || ! method_exists( 'BLT_Optimized_Bricks', 'locate_element' ) ) {
			return null;
		}
		if ( array_key_exists( $element_id, self::$memo['bricks'] ) ) {
			return self::$memo['bricks'][ $element_id ];
		}
		if ( self::$memo['lookups'] >= self::MAX_BRICKS_LOOKUPS ) {
			return null;
		}
		++self::$memo['lookups'];

		try {
			$located = BLT_Optimized_Bricks::locate_element( $element_id );
		} catch ( \Throwable $e ) {
			$located = null;
		}
		$located                             = self::clean_bricks( $located, 0 );
		self::$memo['bricks'][ $element_id ] = $located ? $located : null;
		return self::$memo['bricks'][ $element_id ];
	}

	/**
	 * Reduce whatever the Bricks module returned to bounded scalars (one nested
	 * level of them), with URLs restricted to http(s).
	 *
	 * @param mixed $value Raw.
	 * @param int   $depth Nesting depth.
	 * @return array
	 */
	private static function clean_bricks( $value, $depth ) {
		$out = array();
		if ( ! is_array( $value ) ) {
			return $out;
		}
		foreach ( array_slice( $value, 0, 24, true ) as $key => $item ) {
			if ( ! is_string( $key ) || ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,40}$/', $key ) ) {
				continue;
			}
			if ( is_string( $item ) ) {
				if ( false !== strpos( $key, 'url' ) || false !== strpos( $key, 'link' ) ) {
					$item        = esc_url_raw( $item, array( 'http', 'https' ) );
					$out[ $key ] = self::to_str( $item, 500 );
				} else {
					$out[ $key ] = self::to_str( $item, 200, true );
				}
			} elseif ( is_int( $item ) || is_bool( $item ) ) {
				$out[ $key ] = $item;
			} elseif ( is_float( $item ) ) {
				$out[ $key ] = is_finite( $item ) ? $item : 0;
			} elseif ( 'others' === $key && is_array( $item ) ) {
				// Other posts containing the same element id.
				$out[ $key ] = array_slice( array_values( array_filter( $item, 'is_int' ) ), 0, 5 );
			} elseif ( is_array( $item ) && $depth < 1 ) {
				$out[ $key ] = self::clean_bricks( $item, $depth + 1 );
			}
		}
		return $out;
	}

	/**
	 * Resource kind: js|css|img|font|other.
	 *
	 * @param array $entry Resource { url, type }.
	 * @return string
	 */
	private static function kind_of( array $entry ) {
		$url  = self::s( $entry, 'url', '' );
		$type = self::s( $entry, 'type', '' );

		$ext = '';
		if ( preg_match( '#\.([a-z0-9]{2,5})(?:[?\#]|$)#i', $url, $m ) ) {
			$ext = strtolower( $m[1] );
		}
		if ( in_array( $ext, array( 'js', 'mjs' ), true ) ) {
			return 'js';
		}
		if ( 'css' === $ext ) {
			return 'css';
		}
		if ( in_array( $ext, array( 'woff', 'woff2', 'ttf', 'otf', 'eot' ), true ) ) {
			return 'font';
		}
		if ( in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp', 'apng' ), true ) ) {
			return 'img';
		}
		if ( 'script' === $type ) {
			return 'js';
		}
		if ( 'img' === $type || 'image' === $type ) {
			return 'img';
		}
		// A stylesheet with no .css in its URL (Google Fonts' css2 endpoint, a dynamic stylesheet).
		if ( 'link' === $type && ( false !== strpos( $url, '/css' ) || false !== strpos( $url, 'fonts.googleapis.com' ) ) ) {
			return 'css';
		}
		return 'other';
	}

	/**
	 * Bytes a resource weighs: what crossed the network, or its encoded size
	 * when it came from the cache (so the weight is the same on every run).
	 *
	 * @param array $entry Resource.
	 * @return int
	 */
	private static function resource_size( array $entry ) {
		if ( isset( $entry['size'] ) && is_numeric( $entry['size'] ) ) {
			return max( 0, self::i( $entry, 'size' ) );
		}
		$transfer = self::i( $entry, 'transfer' );
		return $transfer > 0 ? $transfer : max( 0, self::i( $entry, 'encoded' ) );
	}

	/**
	 * A metric value for the summary: a number, or null when it was not measured
	 * (zero means "never happened" for the timings; CLS and TBT may be zero).
	 *
	 * @param mixed $value      Raw.
	 * @param bool  $zero_valid Whether 0 is a real measurement.
	 * @return float|int|null
	 */
	private static function metric( $value, $zero_valid = false ) {
		if ( ( ! is_int( $value ) && ! is_float( $value ) ) || is_nan( (float) $value ) || is_infinite( (float) $value ) ) {
			return null;
		}
		if ( ! $zero_valid && $value <= 0 ) {
			return null;
		}
		return $value;
	}

	/**
	 * Threshold by constant name, filterable.
	 *
	 * @param string $name Constant name.
	 * @return mixed
	 */
	private static function t( $name ) {
		if ( null === self::$thresholds ) {
			$all = ( new ReflectionClass( __CLASS__ ) )->getConstants();

			/**
			 * Filters the thresholds used by the lab analysis.
			 *
			 * @param array $thresholds Constant name => value.
			 */
			$filtered         = apply_filters( 'blt_optimized_lab_thresholds', $all );
			self::$thresholds = is_array( $filtered ) ? $filtered : $all;
		}
		return isset( self::$thresholds[ $name ] ) ? self::$thresholds[ $name ] : constant( __CLASS__ . '::' . $name );
	}

	/* ------------------------------------------------------------------ */
	/* Findings                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Findings from every measured page and viewport, aggregated: one finding
	 * per culprit, an evidence row per page and viewport.
	 *
	 * @param array[] $pages Page reports; `lab` holds `mobile` and/or `desktop`.
	 * @return array[] Findings (source `lab`).
	 */
	public static function findings( array $pages ) {
		self::$errors     = array();
		self::$thresholds = null;
		self::$ctx        = array(
			'handles' => array(),
			'images'  => null,
		);

		$blocked    = array();
		$unreliable = array();
		$runs       = self::collect_runs( $pages, $blocked, $unreliable );

		$findings = array();
		if ( $runs ) {
			$checks = array(
				// Content.
				'check_lcp',
				'check_cls',
				'check_oversized_images',
				'check_offscreen_images',
				'check_dom_size',
				'check_page_weight',
				// Assets.
				'check_render_blocking',
				'check_unused_css',
				'check_main_thread',
				'check_tbt',
				'check_fonts',
				'check_third_party',
				// Server.
				'check_ttfb',
				// Pass.
				'check_pass',
			);
			foreach ( $checks as $check ) {
				$findings = array_merge( $findings, self::guard( $check, $runs ) );
			}
		}
		$findings = array_merge(
			$findings,
			self::guard( 'check_unavailable', $blocked ),
			self::guard( 'check_unreliable', $unreliable )
		);

		/**
		 * Filters the lab findings before they are returned.
		 *
		 * @param array[] $findings Findings.
		 * @param array[] $pages    Page reports.
		 */
		$findings = apply_filters( 'blt_optimized_lab_findings', $findings, $pages );

		self::$ctx = array();
		return is_array( $findings ) ? array_values( $findings ) : array();
	}

	/**
	 * Per-findings() caches (profile handles by page, image candidates).
	 *
	 * @var array
	 */
	private static $ctx = array();

	/**
	 * Run one check, containing any failure.
	 *
	 * @param string $check Method name.
	 * @param array  $input Runs (or the notice lists).
	 * @return array[] Findings.
	 */
	private static function guard( $check, array $input ) {
		try {
			$result = self::$check( $input );
			return is_array( $result ) ? $result : array();
		} catch ( \Throwable $e ) {
			self::$errors[] = $check . ': ' . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')';
			return array();
		}
	}

	/**
	 * Flatten page reports into runs: one per page and measured viewport.
	 * Runs without any measurement (the page refused framing, the observer did
	 * not run) are listed in $blocked; runs flagged unreliable in $unreliable.
	 *
	 * @param array[] $pages      Page reports.
	 * @param array[] $blocked    Filled with { ref, vp, warnings } rows.
	 * @param array[] $unreliable Filled with { ref, vp, warnings } rows.
	 * @return array[] Runs.
	 */
	private static function collect_runs( array $pages, array &$blocked, array &$unreliable ) {
		$runs = array();
		foreach ( array_values( $pages ) as $p => $page ) {
			if ( ! is_array( $page ) || ! isset( $page['lab'] ) || ! is_array( $page['lab'] ) ) {
				continue;
			}
			$url  = ( isset( $page['url'] ) && is_string( $page['url'] ) ) ? $page['url'] : '';
			$ref  = self::page_ref( $url );
			$post = ( isset( $page['post'] ) && is_array( $page['post'] ) && ! empty( $page['post']['id'] ) ) ? $page['post'] : null;
			$name = self::page_name( $page, $ref );

			// Which images the HTML analysis already knows about (see the image checks).
			$in_content  = array();
			$static_over = array();
			$html        = self::g( $page, 'html' );
			foreach ( (array) self::g( $html, 'images', array() ) as $img ) {
				if ( ! is_array( $img ) || '' === self::s( $img, 'src' ) ) {
					continue;
				}
				$key = self::image_key( self::s( $img, 'src' ) );
				if ( $post && ! empty( $img['in_content'] ) ) {
					$in_content[ $key ] = true;
				}
				$attr_w    = self::i( $img, 'attr_w', 0 );
				$natural_w = self::i( $img, 'natural_w', 0 );
				if ( $attr_w > 0 && $natural_w > 2 * $attr_w && empty( $img['has_srcset'] ) ) {
					$static_over[ $key ] = true;
				}
			}

			foreach ( array( 'mobile', 'desktop' ) as $viewport ) {
				$lab = isset( $page['lab'][ $viewport ] ) ? $page['lab'][ $viewport ] : null;
				if ( ! is_array( $lab ) || ! $lab ) {
					continue;
				}
				$warnings = array_values( array_filter( (array) self::g( $lab, 'warnings', array() ), 'is_string' ) );
				$has_data = is_array( self::g( $lab, 'nav' ) ) || is_array( self::g( $lab, 'lcp' ) ) || ! empty( $lab['resources'] );

				$note = array(
					'ref'      => $ref,
					'url'      => $url,
					'vp'       => $viewport,
					'warnings' => $warnings,
				);
				if ( ! $has_data ) {
					if ( array_intersect( $warnings, array( 'framed_blocked', 'no_observer' ) ) ) {
						$blocked[] = $note;
					}
					continue;
				}
				if ( array_intersect( $warnings, array( 'hidden_tab', 'timeout' ) ) ) {
					$unreliable[] = $note;
				}

				$runs[] = array(
					'p'           => $p,
					'vp'          => $viewport,
					'page'        => $page,
					'lab'         => $lab,
					'sum'         => self::summary( $lab ),
					'url'         => $url,
					'ref'         => $ref,
					'name'        => $name,
					'post'        => $post,
					'in_content'  => $in_content,
					'static_over' => $static_over,
				);
			}
		}
		return $runs;
	}

	/* ------------------------------------------------------------------ */
	/* Presentation helpers                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Short page reference: the path (and query).
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
			unset( $args['blt_optimized_lab'], $args['blt_lab'], $args['blt_optimized_profile'], $args['blt_nc'] );
			$query = http_build_query( $args );
		}
		return $path . ( '' !== $query ? '?' . $query : '' );
	}

	/**
	 * How to refer to a page in a sentence: its post title, its label, its path.
	 *
	 * @param array  $page Page report.
	 * @param string $ref  Page reference (path).
	 * @return string
	 */
	private static function page_name( array $page, $ref ) {
		$post  = self::g( $page, 'post' );
		$title = is_array( $post ) ? trim( wp_strip_all_tags( self::s( $post, 'title', '' ) ) ) : '';
		if ( '' !== $title ) {
			/* translators: %s: post title. */
			return sprintf( __( '“%s”', 'blt-optimized' ), self::trunc( $title, 50 ) );
		}
		if ( '/' === $ref ) {
			return __( 'the home page', 'blt-optimized' );
		}
		$label = trim( self::s( $page, 'label', '' ) );
		if ( '' !== $label && '/' !== $label[0] ) {
			return self::trunc( $label, 50 );
		}
		return $ref;
	}

	/**
	 * "Home", "Home, Shop and Contact" or "5 pages" for the pages behind some runs.
	 *
	 * @param array[] $runs All runs.
	 * @param int[]   $ris  Run indexes.
	 * @return string
	 */
	private static function pages_phrase( array $runs, array $ris ) {
		$names = array();
		foreach ( $ris as $ri ) {
			$names[ $runs[ $ri ]['p'] ] = $runs[ $ri ]['name'];
		}
		if ( count( $names ) > 3 ) {
			return self::n_pages( count( $names ) );
		}
		return wp_sprintf( '%l', array_values( $names ) );
	}

	/**
	 * " (mobile)" / " (mobile and desktop)" for the viewports behind some runs.
	 *
	 * @param array[] $runs All runs.
	 * @param int[]   $ris  Run indexes.
	 * @return string
	 */
	private static function devices_phrase( array $runs, array $ris ) {
		$names = array();
		foreach ( array( 'mobile', 'desktop' ) as $viewport ) {
			foreach ( $ris as $ri ) {
				if ( $runs[ $ri ]['vp'] === $viewport ) {
					$names[] = strtolower( self::device( $viewport ) );
					break;
				}
			}
		}
		return $names ? ' (' . wp_sprintf( '%l', $names ) . ')' : '';
	}

	/**
	 * Distinct page URLs behind some runs (a finding's `where`).
	 *
	 * @param array[] $runs All runs.
	 * @param int[]   $ris  Run indexes.
	 * @return string[]
	 */
	private static function urls_of( array $runs, array $ris ) {
		$urls = array();
		foreach ( $ris as $ri ) {
			if ( '' !== $runs[ $ri ]['url'] ) {
				$urls[] = $runs[ $ri ]['url'];
			}
		}
		return array_values( array_unique( $urls ) );
	}

	/**
	 * Viewport label.
	 *
	 * @param string $viewport mobile|desktop.
	 * @return string
	 */
	private static function device( $viewport ) {
		return 'desktop' === $viewport ? __( 'Desktop', 'blt-optimized' ) : __( 'Mobile', 'blt-optimized' );
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
	 * @param int|float $ms Milliseconds.
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
	 * A CLS score.
	 *
	 * @param int|float $value Score.
	 * @return string
	 */
	private static function fmt_cls( $value ) {
		return number_format_i18n( (float) $value, (float) $value < 0.1 ? 3 : 2 );
	}

	/**
	 * Rating in words.
	 *
	 * @param string|null $rating good|needs-improvement|poor.
	 * @return string
	 */
	private static function rating_word( $rating ) {
		if ( 'poor' === $rating ) {
			return __( 'poor', 'blt-optimized' );
		}
		if ( 'needs-improvement' === $rating ) {
			return __( 'needs improvement', 'blt-optimized' );
		}
		return __( 'good', 'blt-optimized' );
	}

	/**
	 * Collapse whitespace and truncate.
	 *
	 * @param string $text Text.
	 * @param int    $max  Maximum characters.
	 * @return string
	 */
	private static function trunc( $text, $max = 80 ) {
		$text = trim( (string) preg_replace( '/\s+/', ' ', (string) $text ) );
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max - 1 ) . '…' : $text;
		}
		return strlen( $text ) > $max ? substr( $text, 0, $max - 1 ) . '…' : $text;
	}

	/**
	 * File name of a URL (or its host when there is none).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function file_name( $url ) {
		$url  = is_string( $url ) ? $url : '';
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$name = rawurldecode( basename( $path ) );
		if ( '' === $name || '/' === $name ) {
			$name = (string) wp_parse_url( $url, PHP_URL_HOST );
		}
		return self::trunc( $name, 60 );
	}

	/**
	 * Normalised image identity: file name without WordPress's size suffix, so
	 * a 300x200 and a 1024x683 rendition of one upload compare equal.
	 *
	 * @param string $url Image URL.
	 * @return string
	 */
	private static function image_key( $url ) {
		$path = strtolower( rawurldecode( (string) wp_parse_url( is_string( $url ) ? $url : '', PHP_URL_PATH ) ) );
		$path = (string) preg_replace( '/-(?:\d+x\d+|scaled|rotated)(?=\.[a-z0-9]+$)/', '', $path );
		return $path;
	}

	/**
	 * Numbered steps, one per line.
	 *
	 * @param string[] $steps Step texts.
	 * @return string
	 */
	private static function steps( array $steps ) {
		$lines = array();
		$n     = 0;
		foreach ( $steps as $step ) {
			if ( '' === trim( (string) $step ) ) {
				continue;
			}
			++$n;
			$lines[] = $n . '. ' . $step;
		}
		return implode( "\n", $lines );
	}

	/**
	 * The sentence every timing-based summary ends with.
	 *
	 * @return string
	 */
	private static function note() {
		return __( 'Timings are measured in your browser (unthrottled), so visitors on a mid-range phone or a slower connection will see larger numbers.', 'blt-optimized' );
	}

	/**
	 * Build a finding (source `lab`).
	 *
	 * @param array $args Finding args.
	 * @return array
	 */
	private static function finding( array $args ) {
		$args['source'] = 'lab';
		return BLT_Optimized_Findings::make( $args );
	}

	/**
	 * Impact block in milliseconds (measured, so no estimate marker).
	 *
	 * @param int|float $ms Milliseconds.
	 * @return array
	 */
	private static function impact_ms( $ms ) {
		return array(
			'type'  => 'ms',
			'value' => round( (float) $ms ),
			'label' => self::fmt_ms( $ms ),
		);
	}

	/**
	 * Impact block in bytes; `$estimate` marks a figure that is calculated, not measured.
	 *
	 * @param int|float $bytes    Bytes.
	 * @param bool      $estimate Whether it is an estimate.
	 * @return array
	 */
	private static function impact_bytes( $bytes, $estimate = false ) {
		return array(
			'type'  => 'bytes',
			'value' => (int) $bytes,
			'label' => $estimate
				/* translators: %s: an estimated size such as "120 KB". */
				? sprintf( __( '~%s (est.)', 'blt-optimized' ), self::fmt_bytes( $bytes ) )
				: self::fmt_bytes( $bytes ),
		);
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
	 * Link fix block (the steps ride along for clients that show them).
	 *
	 * @param string $label Label.
	 * @param string $url   URL.
	 * @param string $steps Optional steps.
	 * @return array
	 */
	private static function fix_link( $label, $url, $steps = '' ) {
		return array(
			'type'  => 'link',
			'label' => $label,
			'url'   => $url,
			'steps' => $steps,
		);
	}

	/**
	 * The most direct fix for a problem located in one place: the Bricks
	 * editor for a Bricks element, the post editor for a post's content, the
	 * concrete steps otherwise.
	 *
	 * @param array|null $bricks Normalised Bricks info.
	 * @param array|null $post   Page report `post` block.
	 * @param string     $steps  Manual steps.
	 * @return array
	 */
	private static function located_fix( $bricks, $post, $steps ) {
		if ( is_array( $bricks ) && '' !== $bricks['edit_url'] ) {
			return self::fix_link( __( 'Edit in Bricks', 'blt-optimized' ), $bricks['edit_url'], $steps );
		}
		if ( is_array( $post ) && ! empty( $post['edit_url'] ) && is_string( $post['edit_url'] ) ) {
			return self::fix_link( __( 'Edit post', 'blt-optimized' ), esc_url_raw( $post['edit_url'] ), $steps );
		}
		return self::fix_manual( __( 'How to fix', 'blt-optimized' ), $steps );
	}

	/**
	 * Normalise what the Bricks module reported about an element
	 * (BLT_Optimized_Bricks::locate_element(): post_id, post_title, post_type,
	 * template_type, area, element_name, edit_url, label, others, in_component).
	 * Older or different shapes degrade to whatever label, title and edit link
	 * they carry.
	 *
	 * @param mixed $bricks Stored `bricks` value (scalars, one nested level) or null.
	 * @return array|null { label, element, where, edit_url } where `where` reads "header template “Main”".
	 */
	private static function bricks_info( $bricks ) {
		if ( ! is_array( $bricks ) || ! $bricks ) {
			return null;
		}
		$text = static function ( $value ) {
			return is_string( $value ) ? trim( $value ) : '';
		};

		$element = $text( self::g( $bricks, 'element_name' ) );
		$label   = $text( self::g( $bricks, 'label' ) );
		if ( '' === $label ) {
			foreach ( array( 'element_label', 'name', 'element' ) as $key ) {
				if ( '' !== $text( self::g( $bricks, $key ) ) ) {
					$label = $text( $bricks[ $key ] );
					break;
				}
			}
		}
		if ( '' === $label && '' !== $element ) {
			$label = ucfirst( str_replace( array( '-', '_' ), ' ', $element ) );
		}

		$title = $text( self::g( $bricks, 'post_title' ) );
		foreach ( array( 'template_title', 'template_name', 'source_title' ) as $key ) {
			if ( '' === $title && '' !== $text( self::g( $bricks, $key ) ) ) {
				$title = $text( $bricks[ $key ] );
			}
		}
		foreach ( array( 'template', 'source', 'post' ) as $key ) {
			$nested = self::g( $bricks, $key );
			if ( '' === $title && is_array( $nested ) ) {
				foreach ( array( 'title', 'name', 'post_title' ) as $sub ) {
					if ( '' === $title && '' !== $text( self::g( $nested, $sub ) ) ) {
						$title = $text( $nested[ $sub ] );
					}
				}
			}
		}

		$where = '';
		if ( '' !== $title ) {
			$area = $text( self::g( $bricks, 'area' ) );
			$type = $text( self::g( $bricks, 'post_type' ) );
			$kind = $text( self::g( $bricks, 'template_type' ) );
			if ( ! empty( $bricks['in_component'] ) || 'component' === $area ) {
				/* translators: %s: Bricks component name. */
				$where = sprintf( __( 'component “%s”', 'blt-optimized' ), $title );
			} elseif ( 'header' === $area || 'footer' === $area ) {
				/* translators: 1: "header" or "footer", 2: template title. */
				$where = sprintf( __( '%1$s template “%2$s”', 'blt-optimized' ), $area, $title );
			} elseif ( 'bricks_template' === $type ) {
				$known = array( 'header', 'footer', 'archive', 'search', 'error', 'section' );
				$where = in_array( $kind, $known, true )
					/* translators: 1: template type (archive, search, section…), 2: template title. */
					? sprintf( __( '%1$s template “%2$s”', 'blt-optimized' ), $kind, $title )
					/* translators: %s: template title. */
					: sprintf( __( 'template “%s”', 'blt-optimized' ), $title );
			} elseif ( '' !== $type && get_post_type_object( $type ) && ! empty( get_post_type_object( $type )->labels->singular_name ) ) {
				/* translators: 1: content type (Page, Post), 2: title. */
				$where = sprintf( __( '%1$s “%2$s”', 'blt-optimized' ), strtolower( get_post_type_object( $type )->labels->singular_name ), $title );
			} else {
				/* translators: %s: title. */
				$where = sprintf( __( '“%s”', 'blt-optimized' ), $title );
			}
		}

		$edit = $text( self::g( $bricks, 'edit_url' ) );
		if ( '' === $edit ) {
			$edit = $text( self::g( $bricks, 'edit_link' ) );
		}
		$edit = '' !== $edit ? esc_url_raw( $edit, array( 'http', 'https' ) ) : '';

		if ( '' === $label && '' === $where && '' === $edit ) {
			return null;
		}
		return array(
			'label'    => $label,
			'element'  => $element,
			'where'    => $where,
			'edit_url' => $edit,
		);
	}

	/**
	 * A Bricks element as a sentence subject: the Bricks element “Hero” (in page “Home”).
	 *
	 * @param array  $bricks Normalised Bricks info.
	 * @param string $id     Element id (without the brxe- prefix).
	 * @return string
	 */
	private static function bricks_subject( array $bricks, $id ) {
		$name = '' !== $bricks['label'] ? $bricks['label'] : '#brxe-' . $id;
		/* translators: %s: Bricks element name. */
		$text = sprintf( __( 'the Bricks element “%s”', 'blt-optimized' ), $name );
		if ( '' !== $bricks['where'] ) {
			/* translators: %s: where the element lives, e.g. 'header template “Main”'. */
			$text .= ' ' . sprintf( __( '(in %s)', 'blt-optimized' ), $bricks['where'] );
		}
		return $text;
	}

	/**
	 * Profile handle of an asset URL on a page ('' when the page was not profiled
	 * or the asset is not one WordPress enqueued).
	 *
	 * @param array  $run Run.
	 * @param string $url Asset URL.
	 * @return string
	 */
	private static function handle_for( array $run, $url ) {
		$p = $run['p'];
		if ( ! isset( self::$ctx['handles'][ $p ] ) ) {
			$map     = array();
			$profile = self::g( $run['page'], 'profile' );
			$assets  = self::g( $profile, 'assets' );
			foreach ( array( 'scripts', 'styles' ) as $kind ) {
				foreach ( (array) self::g( $assets, $kind, array() ) as $asset ) {
					if ( is_array( $asset ) && '' !== self::s( $asset, 'src' ) && '' !== self::s( $asset, 'handle' ) ) {
						$map[ self::path_key( $asset['src'] ) ] = self::s( $asset, 'handle' );
					}
				}
			}
			self::$ctx['handles'][ $p ] = $map;
		}
		$key = self::path_key( $url );
		return isset( self::$ctx['handles'][ $p ][ $key ] ) ? self::$ctx['handles'][ $p ][ $key ] : '';
	}

	/**
	 * Lowercased URL path, for matching a stylesheet or script across reports.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function path_key( $url ) {
		return strtolower( (string) wp_parse_url( is_string( $url ) ? $url : '', PHP_URL_PATH ) );
	}

	/**
	 * Pixel density to judge image sizes against: the measured one, and at
	 * least 2 on the mobile viewport (the phones the test stands in for).
	 *
	 * @param array $run Run.
	 * @return float
	 */
	private static function effective_dpr( array $run ) {
		$dpr = self::n( self::g( $run['lab'], 'viewport' ), 'dpr', 1 );
		$dpr = max( 1.0, $dpr );
		return 'mobile' === $run['vp'] ? max( $dpr, (float) self::t( 'OVERSIZED_MIN_DPR' ) ) : $dpr;
	}

	/**
	 * File sizes of a run's resources, by URL.
	 *
	 * @param array $run Run.
	 * @return array<string,int>
	 */
	private static function sizes_of( array $run ) {
		$sizes = array();
		foreach ( (array) self::g( $run['lab'], 'resources', array() ) as $resource ) {
			if ( is_array( $resource ) && '' !== self::s( $resource, 'url' ) ) {
				$sizes[ self::s( $resource, 'url' ) ] = self::resource_size( $resource );
			}
		}
		return $sizes;
	}

	/**
	 * Whether a component is a plugin, theme or mu-plugin (something the site
	 * owner can act on), as opposed to uploads, core or an external host.
	 *
	 * @param mixed $component Component.
	 * @return bool
	 */
	private static function is_actionable( $component ) {
		return is_array( $component ) && isset( $component['type'] ) && in_array( $component['type'], array( 'plugin', 'mu-plugin', 'theme' ), true );
	}

	/**
	 * A well-formed component from whatever the stored lab carried.
	 *
	 * @param mixed  $component Component or null.
	 * @param string $url       URL to attribute when there is none.
	 * @return array
	 */
	private static function comp( $component, $url = '' ) {
		if ( ! is_array( $component ) || ( '' === self::s( $component, 'slug' ) && '' === self::s( $component, 'name' ) ) ) {
			if ( '' !== $url ) {
				return BLT_Optimized_Components::from_url( $url );
			}
			return BLT_Optimized_Components::make( 'other', 'unknown', __( 'Unknown', 'blt-optimized' ) );
		}
		$name = self::s( $component, 'name' );
		return BLT_Optimized_Components::make(
			self::s( $component, 'type', 'other' ),
			self::s( $component, 'slug' ),
			'' !== $name ? $name : self::s( $component, 'slug' )
		);
	}

	/**
	 * Plain name of a component ("WooCommerce"), with the known service name
	 * for an external host ("Google Fonts").
	 *
	 * @param array $component Component.
	 * @return string
	 */
	private static function comp_name( array $component ) {
		if ( isset( $component['type'] ) && 'external' === $component['type'] && ! empty( $component['slug'] ) ) {
			$known = self::known_service( (string) $component['slug'] );
			if ( '' !== $known ) {
				return $known;
			}
		}
		$name = self::s( $component, 'name' );
		return '' !== $name ? $name : self::s( $component, 'slug', '?' );
	}

	/**
	 * Well-known service behind a third-party host ('' when not recognised).
	 *
	 * @param string $host Host name.
	 * @return string
	 */
	private static function known_service( $host ) {
		static $map = null;
		if ( null === $map ) {
			$map = array(
				'fonts.googleapis.com'          => 'Google Fonts',
				'fonts.gstatic.com'             => 'Google Fonts',
				'www.googletagmanager.com'      => 'Google Tag Manager',
				'googletagmanager.com'          => 'Google Tag Manager',
				'www.google-analytics.com'      => 'Google Analytics',
				'google-analytics.com'          => 'Google Analytics',
				'analytics.google.com'          => 'Google Analytics',
				'connect.facebook.net'          => 'Facebook Pixel',
				'static.hotjar.com'             => 'Hotjar',
				'script.hotjar.com'             => 'Hotjar',
				'www.google.com'                => 'Google (reCAPTCHA / Maps)',
				'www.gstatic.com'               => 'Google (reCAPTCHA / Maps)',
				'maps.googleapis.com'           => 'Google Maps',
				'www.youtube.com'               => 'YouTube',
				'i.ytimg.com'                   => 'YouTube',
				's.ytimg.com'                   => 'YouTube',
				'player.vimeo.com'              => 'Vimeo',
				'i.vimeocdn.com'                => 'Vimeo',
				'js.stripe.com'                 => 'Stripe',
				'cdn.jsdelivr.net'              => 'jsDelivr (public CDN)',
				'cdnjs.cloudflare.com'          => 'cdnjs (public CDN)',
				'unpkg.com'                     => 'unpkg (public CDN)',
				'ajax.googleapis.com'           => 'Google Hosted Libraries',
				'use.fontawesome.com'           => 'Font Awesome',
				'kit.fontawesome.com'           => 'Font Awesome',
				'secure.gravatar.com'           => 'Gravatar',
				'stats.wp.com'                  => 'Jetpack / WordPress.com Stats',
				'static.cloudflareinsights.com' => 'Cloudflare Web Analytics',
				'cdn.cookielaw.org'             => 'OneTrust',
				'consent.cookiebot.com'         => 'Cookiebot',
			);

			/**
			 * Filters the services the lab test recognises by host (host => name).
			 * Shares its meaning with `blt_optimized_known_third_parties`.
			 *
			 * @param array<string,string> $map Host => service name.
			 */
			$filtered = apply_filters( 'blt_optimized_known_third_parties', $map );
			$map      = is_array( $filtered ) ? $filtered : $map;
		}
		$host = strtolower( $host );
		if ( isset( $map[ $host ] ) && is_string( $map[ $host ] ) ) {
			return $map[ $host ];
		}
		return '';
	}

	/* ------------------------------------------------------------------ */
	/* Content: Largest Contentful Paint                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * LCP over the "good" threshold: one finding per LCP element (an image
	 * file, a Bricks element, a text block), across pages and viewports.
	 *
	 * @param array[] $runs Runs.
	 * @return array[]
	 */
	private static function check_lcp( array $runs ) {
		$groups   = array();
		$analyses = array();
		foreach ( $runs as $ri => $run ) {
			$lcp = self::g( $run['lab'], 'lcp' );
			if ( ! is_array( $lcp ) || ! in_array( $run['sum']['ratings']['lcp'], array( 'needs-improvement', 'poor' ), true ) ) {
				continue;
			}
			$analyses[ $ri ]                                     = self::lcp_analysis( $run );
			$groups[ self::lcp_key( $lcp, $analyses[ $ri ] ) ][] = $ri;
		}

		$findings = array();
		foreach ( $groups as $key => $ris ) {
			usort(
				$ris,
				static function ( $a, $b ) use ( $runs ) {
					return $runs[ $b ]['sum']['lcp'] <=> $runs[ $a ]['sum']['lcp'];
				}
			);
			$findings[] = self::lcp_finding( $runs, $key, $ris, $analyses );
		}
		return $findings;
	}

	/**
	 * Identity of an LCP culprit across runs: an image file, a Bricks element,
	 * or - for plain text, where the element itself is rarely the problem - the
	 * site-wide cause that held it back (render-blocking files, a slow server,
	 * a web font), so one cause is one finding however many pages it hits.
	 *
	 * @param array $lcp      LCP block.
	 * @param array $analysis lcp_analysis() of the run.
	 * @return string
	 */
	private static function lcp_key( array $lcp, array $analysis ) {
		if ( '' !== self::s( $lcp, 'bricksId' ) ) {
			return 'bricks:' . self::s( $lcp, 'bricksId' );
		}
		if ( '' !== self::s( $lcp, 'url' ) ) {
			return 'img:' . self::image_key( self::s( $lcp, 'url' ) );
		}
		foreach ( array( 'blocking', 'ttfb', 'font', 'render_delay' ) as $cause ) {
			if ( isset( $analysis['causes'][ $cause ] ) ) {
				return 'text:' . $cause;
			}
		}
		return 'el:' . ( '' !== self::s( $lcp, 'selector' ) ? self::s( $lcp, 'selector' ) : 'lcp' );
	}

	/**
	 * The LCP element as a sentence subject.
	 *
	 * @param array $lcp LCP block.
	 * @return string
	 */
	private static function lcp_subject( array $lcp ) {
		$bricks = self::bricks_info( self::g( $lcp, 'bricks' ) );
		$id     = self::s( $lcp, 'bricksId', '' );
		if ( $bricks ) {
			return self::bricks_subject( $bricks, $id );
		}

		$tag = self::s( $lcp, 'tag', '' );
		if ( '' !== self::s( $lcp, 'url', '' ) ) {
			$file = self::file_name( self::s( $lcp, 'url' ) );
			/* translators: %s: image file name. */
			$text = ! empty( $lcp['isBackground'] ) ? sprintf( __( 'the background image “%s”', 'blt-optimized' ), $file ) : sprintf( __( 'the image “%s”', 'blt-optimized' ), $file );
		} else {
			$snippet = self::trunc( self::s( $lcp, 'text', '' ), 50 );
			if ( preg_match( '/^h[1-6]$/', $tag ) && '' !== $snippet ) {
				/* translators: %s: heading text. */
				$text = sprintf( __( 'the heading “%s”', 'blt-optimized' ), $snippet );
			} elseif ( '' !== $snippet ) {
				/* translators: %s: text of the element. */
				$text = sprintf( __( 'the text “%s”', 'blt-optimized' ), $snippet );
			} elseif ( '' !== self::s( $lcp, 'selector', '' ) ) {
				/* translators: %s: CSS selector. */
				$text = sprintf( __( 'the element %s', 'blt-optimized' ), self::s( $lcp, 'selector' ) );
			} else {
				$text = __( 'the largest element', 'blt-optimized' );
			}
		}
		if ( '' !== $id ) {
			/* translators: %s: Bricks element id. */
			$text .= ' ' . sprintf( __( '(Bricks element #brxe-%s)', 'blt-optimized' ), $id );
		}
		return $text;
	}

	/**
	 * What held an LCP back in one run: the four phases of its timeline and
	 * the causes that stand out, each with the step that addresses it.
	 *
	 * @param array $run Run.
	 * @return array { phases: { ttfb, delay, load, render }, causes: array<code,{text,step}>, dominant: string }
	 */
	private static function lcp_analysis( array $run ) {
		$lab    = $run['lab'];
		$lcp    = $lab['lcp'];
		$nav    = self::g( $lab, 'nav' );
		$time   = self::n( $lcp, 'time', 0 );
		$ttfb   = min( $time, max( 0.0, self::n( $nav, 'ttfb', 0 ) ) );
		$is_img = ! empty( $lcp['isImage'] ) && '' !== self::s( $lcp, 'url', '' );
		$url    = self::s( $lcp, 'url', '' );

		$res = self::g( $lcp, 'resource' );
		if ( $is_img && ! is_array( $res ) ) {
			foreach ( (array) self::g( $lab, 'resources', array() ) as $resource ) {
				if ( is_array( $resource ) && self::s( $resource, 'url' ) === $url ) {
					$res = $resource;
					break;
				}
			}
		}

		$phases = array(
			'ttfb'   => $ttfb,
			'delay'  => null,
			'load'   => null,
			'render' => max( 0.0, $time - $ttfb ),
		);
		if ( is_array( $res ) && isset( $res['start'], $res['end'] ) ) {
			$start            = max( $ttfb, (float) $res['start'] );
			$end              = max( $start, min( $time, (float) $res['end'] ) );
			$phases['delay']  = $start - $ttfb;
			$phases['load']   = $end - $start;
			$phases['render'] = max( 0.0, $time - $end );
		}

		$bytes  = 0;
		$cached = false;
		if ( $is_img ) {
			$sizes = self::sizes_of( $run );
			$bytes = isset( $sizes[ $url ] ) ? (int) $sizes[ $url ] : 0;
			foreach ( (array) self::g( $lab, 'resources', array() ) as $resource ) {
				if ( is_array( $resource ) && self::s( $resource, 'url' ) === $url ) {
					$cached = ! empty( $resource['cached'] );
					break;
				}
			}
		}

		$blocking = self::i( $res, 'renderBlockingBefore', 0 );
		$count    = 0;
		$last_end = 0.0;
		foreach ( (array) self::g( $lab, 'resources', array() ) as $resource ) {
			if ( is_array( $resource ) && ! empty( $resource['blocking'] ) ) {
				++$count;
				$last_end = max( $last_end, self::n( $resource, 'end', 0 ) );
			}
		}
		$blocking = max( $blocking, $count );

		$causes = array();

		if ( $is_img && 'lazy' === self::g( $lcp, 'loading' ) ) {
			$causes['lazy'] = array(
				'text' => __( 'The LCP image has loading="lazy", so the browser holds back its download until layout.', 'blt-optimized' ),
				'step' => __( 'Remove loading="lazy" from the LCP image (in the theme template, the page builder\'s image setting or the image block) and add fetchpriority="high" instead. Lazy-load only images below the fold.', 'blt-optimized' ),
			);
		}
		if ( $is_img && ! empty( $lcp['isBackground'] ) ) {
			$causes['background'] = array(
				'text' => __( 'The LCP image is a CSS background image, which the browser can only find after the stylesheet has loaded.', 'blt-optimized' ),
				'step' => __( 'Show the hero as a real <img> element (or preload it with <link rel="preload" as="image" fetchpriority="high">) instead of a CSS background, so the browser finds it in the HTML.', 'blt-optimized' ),
			);
		} elseif ( $is_img && null !== $phases['delay'] && $phases['delay'] >= (float) self::t( 'LCP_DELAY_MIN_MS' ) && $phases['delay'] >= (float) self::t( 'LCP_DELAY_SHARE' ) * $time ) {
			$causes['late'] = array(
				'text' => sprintf(
					/* translators: %s: milliseconds. */
					__( 'The browser only began downloading the image %s after the HTML arrived: it is discovered late (blocked behind scripts or styles, or added by JavaScript).', 'blt-optimized' ),
					self::fmt_ms( $phases['delay'] )
				),
				'step' => __( 'Make the image discoverable earlier: print it in the HTML as an <img> (not injected by JavaScript) and preload it with <link rel="preload" as="image" fetchpriority="high">.', 'blt-optimized' ),
			);
		}
		if ( $is_img && 'lazy' !== self::g( $lcp, 'loading' ) && 'high' !== self::g( $lcp, 'fetchpriority' ) ) {
			$causes['priority'] = array(
				'text' => __( 'The LCP image does not have fetchpriority="high", so it competes with every other image for bandwidth.', 'blt-optimized' ),
				'step' => __( 'Add fetchpriority="high" to the LCP image. WordPress 6.3+ does this for the first large image it prints itself; images from page builders or custom markup need it added by hand.', 'blt-optimized' ),
			);
		}
		if ( $is_img ) {
			$natural  = self::i( $lcp, 'naturalW', 0 );
			$rendered = self::n( $lcp, 'renderedW', 0 );
			$dpr      = self::effective_dpr( $run );
			$need     = $rendered * $dpr;
			if ( $natural > 0 && $need > 0 && $natural > (float) self::t( 'LCP_OVERSIZED_RATIO' ) * $need ) {
				$text = sprintf(
					/* translators: 1: image width in pixels, 2: displayed width in pixels, 3: pixel density. */
					__( 'The image file is %1$s px wide but displayed at %2$s px (about %3$s px is enough at %4$s× screens).', 'blt-optimized' ),
					number_format_i18n( $natural ),
					number_format_i18n( $rendered ),
					number_format_i18n( ceil( $need ) ),
					number_format_i18n( $dpr, 1 )
				);
				if ( $bytes > 0 ) {
					$wasted = (int) round( $bytes * ( 1 - pow( $need / $natural, 2 ) ) );
					if ( $wasted >= (int) self::t( 'LCP_WASTED_MIN_BYTES' ) ) {
						/* translators: %s: file size. */
						$text .= ' ' . sprintf( __( 'About %s of it is never visible.', 'blt-optimized' ), self::fmt_bytes( $wasted ) );
					}
				}
				$causes['oversized'] = array(
					'text' => $text,
					'step' => sprintf(
						/* translators: %s: width in pixels. */
						__( 'Serve the LCP image at about %s px wide (and offer smaller sizes with srcset) instead of the full-size upload.', 'blt-optimized' ),
						number_format_i18n( ceil( $need ) )
					),
				);
			}
			if ( null !== $phases['load'] && $phases['load'] >= (float) self::t( 'LCP_LOAD_SLOW_MS' ) && ! $cached ) {
				$causes['slow_load'] = array(
					'text' => sprintf(
						/* translators: 1: milliseconds, 2: file size. */
						__( 'Downloading the image took %1$s%2$s.', 'blt-optimized' ),
						self::fmt_ms( $phases['load'] ),
						$bytes > 0 ? ' (' . self::fmt_bytes( $bytes ) . ')' : ''
					),
					'step' => __( 'Make the LCP image lighter: compress it and serve WebP or AVIF (the image optimization module can do this in bulk).', 'blt-optimized' ),
				);
			}
		}
		if ( $blocking > 0 && ( $phases['render'] >= (float) self::t( 'LCP_RENDER_DELAY_MS' ) || ! $is_img ) ) {
			$causes['blocking'] = array(
				'text' => sprintf(
					/* translators: 1: number of files, 2: milliseconds. */
					_n( '%1$s render-blocking file holds back all painting until %2$s.', '%1$s render-blocking files hold back all painting until %2$s.', $blocking, 'blt-optimized' ),
					number_format_i18n( $blocking ),
					self::fmt_ms( $last_end )
				),
				'step' => __( 'Remove or defer the render-blocking scripts and stylesheets listed under "render-blocking" findings; nothing can paint, including the LCP element, until they finish.', 'blt-optimized' ),
			);
		} elseif ( $phases['render'] >= (float) self::t( 'LCP_RENDER_DELAY_MS' ) ) {
			$tbt                    = self::n( self::g( $lab, 'tbt' ), 'value', 0 );
			$causes['render_delay'] = array(
				'text' => sprintf(
					/* translators: %s: milliseconds. */
					__( 'The element waited another %1$s to be painted after its data was ready%2$s.', 'blt-optimized' ),
					self::fmt_ms( $phases['render'] ),
					$tbt > 0 ? __( ' (the main thread was busy running JavaScript)', 'blt-optimized' ) : ''
				),
				'step' => __( 'Cut the JavaScript that runs before first paint (see the main-thread findings) so the browser can paint the LCP element as soon as it is ready.', 'blt-optimized' ),
			);
		}
		if ( $ttfb > (float) self::t( 'TTFB_GOOD' ) ) {
			$causes['ttfb'] = array(
				'text' => sprintf(
					/* translators: %s: milliseconds. */
					__( 'The server took %s to start answering, before anything else could begin.', 'blt-optimized' ),
					self::fmt_ms( $ttfb )
				),
				'step' => __( 'Speed up the server response: page caching (a caching plugin or your host\'s cache), a faster PHP version, fewer slow plugins (see the server findings).', 'blt-optimized' ),
			);
		}
		if ( ! $is_img ) {
			foreach ( (array) self::g( $lab, 'fonts', array() ) as $font ) {
				if ( is_array( $font ) && in_array( self::s( $font, 'display', '' ), array( '', 'auto', 'block' ), true ) ) {
					$causes['font'] = array(
						'text' => __( 'The text can stay invisible until a web font has loaded (the font has no font-display: swap).', 'blt-optimized' ),
						'step' => __( 'Use font-display: swap (or optional) for web fonts and preload the main font file so the LCP text is visible immediately.', 'blt-optimized' ),
					);
					break;
				}
			}
		}

		// Largest phase.
		$dominant = 'render';
		$largest  = -1.0;
		foreach ( $phases as $name => $value ) {
			if ( null !== $value && $value > $largest ) {
				$largest  = $value;
				$dominant = $name;
			}
		}

		return array(
			'phases'   => $phases,
			'causes'   => $causes,
			'dominant' => $dominant,
		);
	}

	/**
	 * One LCP finding.
	 *
	 * @param array[] $runs     All runs.
	 * @param string  $key      Culprit identity.
	 * @param int[]   $ris      Run indexes in the group, worst first.
	 * @param array[] $analyses lcp_analysis() per run index.
	 * @return array
	 */
	private static function lcp_finding( array $runs, $key, array $ris, array $analyses ) {
		$lead    = $runs[ $ris[0] ];
		$lcp     = $lead['lab']['lcp'];
		$shared  = 0 === strpos( $key, 'text:' );
		$subject = $shared ? __( 'the main content', 'blt-optimized' ) : self::lcp_subject( $lcp );
		$worst   = (float) $lead['sum']['lcp'];
		$poor    = false;
		$causes  = array();
		$rows    = array();
		$phase_n = null;

		foreach ( $ris as $ri ) {
			$run      = $runs[ $ri ];
			$analysis = $analyses[ $ri ];
			$poor     = $poor || 'poor' === $run['sum']['ratings']['lcp'];
			if ( null === $phase_n ) {
				$phase_n = $analysis;
			}
			foreach ( $analysis['causes'] as $code => $cause ) {
				if ( ! isset( $causes[ $code ] ) ) {
					$causes[ $code ] = $cause;
				}
			}
			$ph     = $analysis['phases'];
			$rows[] = array(
				$run['ref'],
				self::device( $run['vp'] ),
				self::trunc( ucfirst( self::lcp_subject( $run['lab']['lcp'] ) ), 70 ),
				self::fmt_ms( $run['sum']['lcp'] ),
				self::fmt_ms( $ph['ttfb'] ),
				null !== $ph['delay'] ? self::fmt_ms( $ph['delay'] ) : '–',
				null !== $ph['load'] ? self::fmt_ms( $ph['load'] ) : '–',
				self::fmt_ms( $ph['render'] ),
			);
		}

		// Show causes in order of how directly they hold the LCP back.
		$order  = array( 'lazy', 'background', 'late', 'priority', 'oversized', 'slow_load', 'blocking', 'render_delay', 'ttfb', 'font' );
		$sorted = array();
		foreach ( $order as $code ) {
			if ( isset( $causes[ $code ] ) ) {
				$sorted[ $code ] = $causes[ $code ];
			}
		}

		$phase_names = array(
			'ttfb'   => __( 'waiting for the server to respond', 'blt-optimized' ),
			'delay'  => __( 'waiting to start downloading the image', 'blt-optimized' ),
			'load'   => __( 'downloading the image', 'blt-optimized' ),
			'render' => __( 'waiting to paint once the content was ready', 'blt-optimized' ),
		);

		$summary = sprintf(
			/* translators: 1: description of the LCP element, 2: time, 3: rating word, 4: "good" threshold. */
			__( 'In the lab test the largest element on screen, %1$s, finished painting after %2$s (%3$s; under %4$s is good).', 'blt-optimized' ),
			$shared ? self::lcp_subject( $lcp ) : $subject,
			self::fmt_ms( $worst ),
			self::rating_word( $poor ? 'poor' : 'needs-improvement' ),
			self::fmt_ms( self::t( 'LCP_GOOD' ) )
		);
		if ( $phase_n ) {
			$phases = $phase_n['phases'];
			$lead_v = isset( $phases[ $phase_n['dominant'] ] ) ? (float) $phases[ $phase_n['dominant'] ] : 0.0;
			if ( $lead_v > 0 ) {
				$summary .= ' ' . sprintf(
					/* translators: 1: milliseconds, 2: phase description. */
					__( 'The biggest share of that, %1$s, is spent %2$s.', 'blt-optimized' ),
					self::fmt_ms( $lead_v ),
					$phase_names[ $phase_n['dominant'] ]
				);
			}
		}
		foreach ( array_slice( $sorted, 0, 2 ) as $cause ) {
			$summary .= ' ' . $cause['text'];
		}
		$summary .= ' ' . self::note();

		$steps = array();
		foreach ( $sorted as $cause ) {
			$steps[] = $cause['step'];
		}
		if ( ! $steps ) {
			$steps[] = __( 'Compare the phases in the table: the longest one is where to start (server response, finding the resource, downloading it, or painting it).', 'blt-optimized' );
			$steps[] = __( 'Keep the LCP element simple and early in the HTML; avoid hiding it behind animations, sliders or JavaScript-rendered content.', 'blt-optimized' );
		}

		$same_page = 1 === count(
			array_unique(
				array_map(
					static function ( $ri ) use ( $runs ) {
						return $runs[ $ri ]['p'];
					},
					$ris
				)
			)
		);
		$post      = $same_page ? $lead['post'] : null;

		$title = sprintf(
			/* translators: 1: time, 2: description of the LCP element, 3: page names, 4: " (mobile)". */
			__( 'Largest Contentful Paint takes %1$s: %2$s on %3$s%4$s', 'blt-optimized' ),
			self::fmt_ms( $worst ),
			$subject,
			self::pages_phrase( $runs, $ris ),
			self::devices_phrase( $runs, $ris )
		);
		if ( $shared ) {
			$reasons = array(
				'text:blocking'     => __( 'is held back by render-blocking files', 'blt-optimized' ),
				'text:ttfb'         => __( 'waits for a slow server response', 'blt-optimized' ),
				'text:font'         => __( 'waits for a web font', 'blt-optimized' ),
				'text:render_delay' => __( 'waits on the main thread to paint', 'blt-optimized' ),
			);
			$title   = sprintf(
				/* translators: 1: time, 2: page names, 3: " (mobile)", 4: reason, e.g. "is held back by render-blocking files". */
				__( 'Largest Contentful Paint takes %1$s on %2$s%3$s: the main content %4$s', 'blt-optimized' ),
				self::fmt_ms( $worst ),
				self::pages_phrase( $runs, $ris ),
				self::devices_phrase( $runs, $ris ),
				isset( $reasons[ $key ] ) ? $reasons[ $key ] : ''
			);
		}

		$where = self::urls_of( $runs, $ris );
		if ( '' !== self::s( $lcp, 'url' ) ) {
			$where[] = self::s( $lcp, 'url' );
		}

		return self::finding(
			array(
				'id'        => 'lab.lcp',
				'key'       => 'lab.lcp:' . $key,
				'category'  => 'content',
				'severity'  => $poor ? 'critical' : 'warning',
				'title'     => $title,
				'summary'   => $summary,
				'impact'    => self::impact_ms( $worst ),
				'component' => self::is_actionable( self::g( $lcp, 'component' ) ) ? self::comp( $lcp['component'] ) : null,
				'where'     => $where,
				'details'   => array(
					'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Device', 'blt-optimized' ), __( 'LCP element', 'blt-optimized' ), __( 'LCP', 'blt-optimized' ), __( 'Server response', 'blt-optimized' ), __( 'Load delay', 'blt-optimized' ), __( 'Load time', 'blt-optimized' ), __( 'Render delay', 'blt-optimized' ) ),
					'rows'    => $rows,
				),
				'fix'       => self::located_fix( self::bricks_info( self::g( $lcp, 'bricks' ) ), $post, self::steps( $steps ) ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Content: Cumulative Layout Shift                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * CLS over the "good" threshold: one finding per shifting element.
	 *
	 * @param array[] $runs Runs.
	 * @return array[]
	 */
	private static function check_cls( array $runs ) {
		$culprits = array();
		foreach ( $runs as $ri => $run ) {
			$cls = self::g( $run['lab'], 'cls' );
			if ( ! is_array( $cls ) || ! in_array( $run['sum']['ratings']['cls'], array( 'needs-improvement', 'poor' ), true ) ) {
				continue;
			}
			foreach ( self::cls_shares( $cls ) as $ekey => $share ) {
				if ( ! isset( $culprits[ $ekey ] ) ) {
					$culprits[ $ekey ] = array(
						'source' => $share['source'],
						'runs'   => array(),
						'total'  => 0.0,
						'max'    => 0.0,
					);
				}
				$culprits[ $ekey ]['runs'][ $ri ] = $share;
				$culprits[ $ekey ]['total']      += $share['value'];
				$culprits[ $ekey ]['max']         = max( $culprits[ $ekey ]['max'], $share['value'] );
			}
		}
		if ( ! $culprits ) {
			return array();
		}

		uasort(
			$culprits,
			static function ( $a, $b ) {
				return $b['total'] <=> $a['total'];
			}
		);

		$findings = array();
		$limit    = (int) self::t( 'CLS_MAX_FINDINGS' );
		foreach ( $culprits as $ekey => $culprit ) {
			if ( $limit <= count( $findings ) || ( $findings && (float) self::t( 'CLS_MIN_SHARE' ) > $culprit['max'] ) ) {
				break;
			}
			$findings[] = self::cls_finding( $runs, $ekey, $culprit, 0 === count( $findings ) );
		}
		return $findings;
	}

	/**
	 * Each element's share of a page's layout shift. A shift's score is split
	 * between the elements responsible for it, then scaled so the shares add up
	 * to the page's CLS. When some elements in a shift changed size (an
	 * unsized image or embed growing, a banner expanding) they are the
	 * instigators and the elements that merely moved are their victims; when
	 * nothing changed size, the shift is split between the elements that moved,
	 * in proportion to area times distance.
	 *
	 * @param array $cls CLS block.
	 * @return array<string,array> Element key => { value, count, time, dy, role: size|moved, victims: string[], source }.
	 */
	private static function cls_shares( array $cls ) {
		$acc = array();
		$sum = 0.0;
		foreach ( (array) self::g( $cls, 'shifts', array() ) as $shift ) {
			if ( ! is_array( $shift ) ) {
				continue;
			}
			$value = self::n( $shift, 'value', 0 );
			if ( $value <= 0 ) {
				continue;
			}
			$sources = array_values( array_filter( (array) self::g( $shift, 'sources', array() ), 'is_array' ) );
			if ( ! $sources ) {
				$sources = array( array() );
			}

			$change  = array();
			$growers = array();
			foreach ( $sources as $i => $source ) {
				$change[ $i ] = self::size_change( $source );
				if ( $change[ $i ] > 0 ) {
					$growers[ $i ] = $change[ $i ];
				}
			}
			$weights = array();
			if ( $growers ) {
				$weights = $growers;
			} else {
				// Nothing changed size: whatever caused the shift is not in the list.
				// Name the element that moved the most and list the rest as moved too.
				$best = null;
				foreach ( $sources as $i => $source ) {
					$weight = self::shift_weight( $source );
					if ( null === $best || $weight > $best[1] ) {
						$best = array( $i, $weight );
					}
				}
				$weights[ $best[0] ] = $best[1];
			}
			$total = array_sum( $weights );

			$victims = array();
			foreach ( $sources as $i => $source ) {
				if ( ! isset( $weights[ $i ] ) && '' !== self::s( $source, 'selector' ) ) {
					$victims[] = self::s( $source, 'selector' );
				}
			}

			foreach ( $weights as $i => $weight ) {
				$source = $sources[ $i ];
				$part   = $total > 0 ? $value * $weight / $total : $value / count( $weights );
				$ekey   = self::element_key( $source );
				$prev   = self::g( $source, 'prevRect' );
				$curr   = self::g( $source, 'currRect' );
				$dy     = ( is_array( $prev ) && is_array( $curr ) && count( $prev ) > 3 && count( $curr ) > 3 ) ? (float) $curr[1] - (float) $prev[1] : 0.0;
				if ( ! isset( $acc[ $ekey ] ) ) {
					$acc[ $ekey ] = array(
						'value'   => 0.0,
						'count'   => 0,
						'time'    => self::n( $shift, 'time', 0 ),
						'best'    => 0.0,
						'dy'      => 0.0,
						'role'    => $growers ? 'size' : 'moved',
						'victims' => array(),
						'source'  => $source,
					);
				}
				$acc[ $ekey ]['value'] += $part;
				++$acc[ $ekey ]['count'];
				if ( abs( $dy ) > abs( $acc[ $ekey ]['dy'] ) ) {
					$acc[ $ekey ]['dy'] = $dy;
				}
				$acc[ $ekey ]['victims'] = array_slice( array_values( array_unique( array_merge( $acc[ $ekey ]['victims'], $victims ) ) ), 0, 3 );
				if ( $growers ) {
					$acc[ $ekey ]['role'] = 'size';
				}
				if ( $part > $acc[ $ekey ]['best'] ) {
					$acc[ $ekey ]['best']   = $part;
					$acc[ $ekey ]['time']   = self::n( $shift, 'time', 0 );
					$acc[ $ekey ]['source'] = $source;
				}
				$sum += $part;
			}
		}
		if ( $sum > 0 ) {
			$scale = self::n( $cls, 'value', 0 ) / $sum;
			foreach ( $acc as $ekey => $row ) {
				$acc[ $ekey ]['value'] = $row['value'] * $scale;
			}
		}
		return $acc;
	}

	/**
	 * By how much (px²) an element's box changed size in a shift; 0 when it
	 * only moved or the rectangles are unknown. Changes under 2 px either way
	 * are rounding noise.
	 *
	 * @param array $source CLS source.
	 * @return float
	 */
	private static function size_change( array $source ) {
		$prev = self::g( $source, 'prevRect' );
		$curr = self::g( $source, 'currRect' );
		if ( ! is_array( $prev ) || ! is_array( $curr ) || count( $prev ) < 4 || count( $curr ) < 4 ) {
			return 0.0;
		}
		if ( abs( (float) $curr[2] - (float) $prev[2] ) < 2 && abs( (float) $curr[3] - (float) $prev[3] ) < 2 ) {
			return 0.0;
		}
		return max( 1.0, abs( (float) $curr[2] * (float) $curr[3] - (float) $prev[2] * (float) $prev[3] ) );
	}

	/**
	 * How much a shifted element moved: area times distance.
	 *
	 * @param array $source CLS source.
	 * @return float
	 */
	private static function shift_weight( array $source ) {
		$prev = self::g( $source, 'prevRect' );
		$curr = self::g( $source, 'currRect' );
		if ( ! is_array( $prev ) || ! is_array( $curr ) || count( $prev ) < 4 || count( $curr ) < 4 ) {
			return 1.0;
		}
		$area = max( (float) $prev[2] * (float) $prev[3], (float) $curr[2] * (float) $curr[3] );
		$dist = hypot( (float) $curr[0] - (float) $prev[0], (float) $curr[1] - (float) $prev[1] );
		return max( 1.0, $area ) * max( 1.0, $dist );
	}

	/**
	 * Identity of a shifting element across runs.
	 *
	 * @param array $source CLS source.
	 * @return string
	 */
	private static function element_key( array $source ) {
		if ( '' !== self::s( $source, 'bricksId' ) ) {
			return 'bricks:' . self::s( $source, 'bricksId' );
		}
		if ( '' !== self::s( $source, 'selector' ) ) {
			return 'el:' . self::s( $source, 'selector' );
		}
		if ( '' !== self::s( $source, 'tag' ) ) {
			return 'tag:' . self::s( $source, 'tag' );
		}
		return 'unknown';
	}

	/**
	 * A shifting element as a sentence subject.
	 *
	 * @param array $run    A run in which it shifted.
	 * @param array $source Its CLS source.
	 * @return string
	 */
	private static function shift_subject( array $run, array $source ) {
		$bricks = self::bricks_info( self::g( $source, 'bricks' ) );
		if ( $bricks ) {
			return self::bricks_subject( $bricks, self::s( $source, 'bricksId', '' ) );
		}

		$selector = self::s( $source, 'selector', '' );
		$tag      = self::s( $source, 'tag', '' );
		if ( 'img' === $tag ) {
			foreach ( (array) self::g( $run['lab'], 'images', array() ) as $image ) {
				if ( is_array( $image ) && '' !== $selector && self::g( $image, 'selector' ) === $selector && ! empty( $image['src'] ) ) {
					/* translators: 1: image file name, 2: CSS selector. */
					return sprintf( __( 'the image “%1$s” (%2$s)', 'blt-optimized' ), self::file_name( $image['src'] ), $selector );
				}
			}
			/* translators: %s: CSS selector. */
			return sprintf( __( 'the image %s', 'blt-optimized' ), '' !== $selector ? $selector : 'img' );
		}
		if ( in_array( $tag, array( 'iframe', 'video', 'embed', 'object' ), true ) ) {
			/* translators: %s: CSS selector. */
			return sprintf( __( 'the embedded %s', 'blt-optimized' ), '' !== $selector ? $selector : $tag );
		}
		if ( '' === $selector && '' === $tag ) {
			return __( 'content on the page', 'blt-optimized' );
		}
		/* translators: %s: CSS selector. */
		return sprintf( __( 'the element %s', 'blt-optimized' ), '' !== $selector ? $selector : $tag );
	}

	/**
	 * Likely reasons an element shifted, from what the browser recorded.
	 *
	 * @param array $run   Run.
	 * @param array $share The element's share in this run.
	 * @return array<string,array> code => { text, step }
	 */
	private static function cls_causes( array $run, array $share ) {
		$source = $share['source'];
		$tag    = self::s( $source, 'tag', '' );
		$time   = (float) $share['time'];
		$prev   = self::g( $source, 'prevRect' );
		$curr   = self::g( $source, 'currRect' );
		$causes = array();

		if ( 'size' === $share['role'] ) {
			if ( 'img' === $tag ) {
				$causes['image'] = array(
					'text' => __( 'The image had no reserved space: it grew when it loaded and pushed the content below it down.', 'blt-optimized' ),
					'step' => __( 'Give the image width and height attributes (its real pixel size) or a CSS aspect-ratio, so the browser reserves its space before it downloads.', 'blt-optimized' ),
				);
			} elseif ( in_array( $tag, array( 'iframe', 'video', 'embed', 'object' ), true ) ) {
				$causes['embed'] = array(
					'text' => __( 'The embedded player had no reserved height: it grew when it loaded and the content below it jumped.', 'blt-optimized' ),
					'step' => __( 'Wrap the embed in a container with a fixed aspect-ratio (for example aspect-ratio: 16 / 9) or give the iframe width and height attributes.', 'blt-optimized' ),
				);
			} else {
				$text = __( 'The element changed size after it was first painted (content, a font or styles arrived late).', 'blt-optimized' );
				if ( is_array( $prev ) && is_array( $curr ) && count( $prev ) > 3 && count( $curr ) > 3 && abs( (float) $curr[3] - (float) $prev[3] ) >= 2 ) {
					$text = sprintf(
						/* translators: 1: height before, 2: height after (pixels). */
						__( 'The element grew from %1$s to %2$s px tall after it was first painted (content, a font or styles arrived late).', 'blt-optimized' ),
						number_format_i18n( round( (float) $prev[3] ) ),
						number_format_i18n( round( (float) $curr[3] ) )
					);
				}
				$causes['resize'] = array(
					'text' => $text,
					'step' => __( 'Reserve the element\'s final size up front with a min-height or aspect-ratio, so late content, fonts or styles fill it instead of resizing it.', 'blt-optimized' ),
				);
			}
		} else {
			$dy = (float) $share['dy'];
			if ( $dy >= 1 ) {
				/* translators: %s: pixels. */
				$text = sprintf( __( 'Something above it appeared or grew after the first paint and pushed it down by %s px.', 'blt-optimized' ), number_format_i18n( round( $dy ) ) );
			} elseif ( $dy <= -1 ) {
				/* translators: %s: pixels. */
				$text = sprintf( __( 'Something above it shrank or disappeared after the first paint and pulled it up by %s px.', 'blt-optimized' ), number_format_i18n( round( abs( $dy ) ) ) );
			} else {
				$text = __( 'It was pushed sideways after the first paint by something that appeared next to it.', 'blt-optimized' );
			}
			$causes['pushed'] = array(
				'text' => $text,
				'step' => __( 'Find what appears, grows or disappears around this element after the first paint (late-loading images, embeds, banners, notices or fonts) and reserve its space with explicit dimensions or a min-height.', 'blt-optimized' ),
			);
		}

		if ( 'img' !== $tag && ! in_array( $tag, array( 'iframe', 'video', 'embed', 'object' ), true ) ) {
			$window = (float) self::t( 'CLS_FONT_WINDOW_MS' );
			foreach ( (array) self::g( $run['lab'], 'resources', array() ) as $resource ) {
				if ( is_array( $resource ) && 'font' === self::g( $resource, 'kind' ) && abs( self::n( $resource, 'end', 0 ) - $time ) <= $window ) {
					$causes['font'] = array(
						'text' => __( 'The shift happened right when a web font finished loading: the text was re-laid out with different letter widths.', 'blt-optimized' ),
						'step' => __( 'Preload the main web font and use font-display: optional (or swap with a size-adjusted fallback font) so the text does not change size when the font arrives.', 'blt-optimized' ),
					);
					break;
				}
			}
		}

		$load = self::n( self::g( $run['lab'], 'nav' ), 'load', 0 );
		if ( $load > 0 && $time > $load + (float) self::t( 'CLS_LATE_AFTER_MS' ) ) {
			$causes['late'] = array(
				'text' => sprintf(
					/* translators: %s: milliseconds. */
					__( 'It shifted %s after the page finished loading: content (a banner, widget or notice) was inserted late.', 'blt-optimized' ),
					self::fmt_ms( $time - $load )
				),
				'step' => __( 'Reserve space (a min-height or placeholder) for content that is added after load, such as banners, cookie notices, ads and widgets, or show it in a position that does not push other content.', 'blt-optimized' ),
			);
		}
		return $causes;
	}

	/**
	 * One CLS finding for a shifting element.
	 *
	 * @param array[] $runs     All runs.
	 * @param string  $ekey     Element identity.
	 * @param array   $culprit  { source, runs: run index => share, total, max }.
	 * @param bool    $top      Whether it is the biggest culprit (decides critical).
	 * @return array
	 */
	private static function cls_finding( array $runs, $ekey, array $culprit, $top ) {
		$ris = array_keys( $culprit['runs'] );
		usort(
			$ris,
			static function ( $a, $b ) use ( $culprit ) {
				return $culprit['runs'][ $b ]['value'] <=> $culprit['runs'][ $a ]['value'];
			}
		);
		$lead    = $runs[ $ris[0] ];
		$lead_sh = $culprit['runs'][ $ris[0] ];
		$source  = $lead_sh['source'];
		$what    = self::shift_subject( $lead, $source );
		$sizing  = 'size' === $lead_sh['role'];

		$worst_cls = 0.0;
		$poor      = false;
		$causes    = array();
		$victims   = array();
		$rows      = array();
		foreach ( $ris as $ri ) {
			$run       = $runs[ $ri ];
			$share     = $culprit['runs'][ $ri ];
			$page_cls  = (float) $run['sum']['cls'];
			$worst_cls = max( $worst_cls, $page_cls );
			$poor      = $poor || 'poor' === $run['sum']['ratings']['cls'];
			$victims   = array_merge( $victims, $share['victims'] );
			foreach ( self::cls_causes( $run, $share ) as $code => $cause ) {
				if ( ! isset( $causes[ $code ] ) ) {
					$causes[ $code ] = $cause;
				}
			}
			$rows[] = array(
				$run['ref'],
				self::device( $run['vp'] ),
				self::fmt_cls( $page_cls ),
				self::fmt_cls( $share['value'] ),
				number_format_i18n( $share['count'] ),
				self::fmt_ms( $share['time'] ),
				'size' === $share['role'] ? __( 'changed size', 'blt-optimized' ) : ( abs( $share['dy'] ) >= 1 ? sprintf( /* translators: %s: pixels. */ __( 'moved %s px', 'blt-optimized' ), number_format_i18n( round( abs( $share['dy'] ) ) ) ) : __( 'moved', 'blt-optimized' ) ),
			);
		}

		$order  = array( 'image', 'embed', 'resize', 'pushed', 'font', 'late' );
		$sorted = array();
		foreach ( $order as $code ) {
			if ( isset( $causes[ $code ] ) ) {
				$sorted[ $code ] = $causes[ $code ];
			}
		}

		$summary = sprintf(
			/* translators: 1: description of the element, 2: "changed size" or "moved", 3: its share of the layout shift score, 4: the page's layout shift score, 5: rating word, 6: "good" threshold. */
			__( 'In the lab test %1$s %2$s after it was first painted, adding about %3$s to the page\'s layout shift score of %4$s (%5$s; under %6$s is good).', 'blt-optimized' ),
			$what,
			$sizing ? __( 'changed size', 'blt-optimized' ) : __( 'moved', 'blt-optimized' ),
			self::fmt_cls( $culprit['max'] ),
			self::fmt_cls( $worst_cls ),
			self::rating_word( $poor ? 'poor' : 'needs-improvement' ),
			self::fmt_cls( self::t( 'CLS_GOOD' ) )
		);
		$victims = array_values( array_unique( $victims ) );
		if ( $victims ) {
			$summary .= ' ' . sprintf(
				$sizing
					/* translators: %s: list of CSS selectors. */
					? __( 'Content that moved because of it: %s.', 'blt-optimized' )
					/* translators: %s: list of CSS selectors. */
					: __( 'Also moved in the same shift: %s.', 'blt-optimized' ),
				wp_sprintf( '%l', array_slice( $victims, 0, 3 ) )
			);
		}
		foreach ( array_slice( $sorted, 0, 2 ) as $cause ) {
			$summary .= ' ' . $cause['text'];
		}
		$summary .= ' ' . __( 'Layout shifts depend on load order and timing; this was measured in your browser (unthrottled), where a faster connection can hide shifts that slower visitors see.', 'blt-optimized' );

		$steps = array();
		foreach ( $sorted as $cause ) {
			$steps[] = $cause['step'];
		}

		$same_page = 1 === count(
			array_unique(
				array_map(
					static function ( $ri ) use ( $runs ) {
						return $runs[ $ri ]['p'];
					},
					$ris
				)
			)
		);

		$title = sprintf(
			$sizing
				/* translators: 1: layout shift score, 2: description of the element, 3: page names, 4: " (mobile)". */
				? __( 'Layout shift (CLS %1$s): %2$s changes size after it appears and shifts the page on %3$s%4$s', 'blt-optimized' )
				/* translators: 1: layout shift score, 2: description of the element, 3: page names, 4: " (mobile)". */
				: __( 'Layout shift (CLS %1$s): %2$s is pushed around after it appears on %3$s%4$s', 'blt-optimized' ),
			self::fmt_cls( $worst_cls ),
			$what,
			self::pages_phrase( $runs, $ris ),
			self::devices_phrase( $runs, $ris )
		);

		return self::finding(
			array(
				'id'        => 'lab.cls',
				'key'       => 'lab.cls:' . $ekey,
				'category'  => 'content',
				'severity'  => ( $poor && $top ) ? 'critical' : 'warning',
				'title'     => $title,
				'summary'   => $summary,
				'impact'    => array(
					'type'  => 'none',
					'value' => 0,
					/* translators: %s: layout shift score. */
					'label' => sprintf( __( 'CLS %s', 'blt-optimized' ), self::fmt_cls( $worst_cls ) ),
				),
				'component' => null,
				'where'     => self::urls_of( $runs, $ris ),
				'details'   => array(
					'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Device', 'blt-optimized' ), __( 'Page CLS', 'blt-optimized' ), __( 'This element', 'blt-optimized' ), __( 'Shifts', 'blt-optimized' ), __( 'When', 'blt-optimized' ), __( 'What happened', 'blt-optimized' ) ),
					'rows'    => $rows,
				),
				'fix'       => self::located_fix( self::bricks_info( self::g( $source, 'bricks' ) ), $same_page ? $lead['post'] : null, self::steps( $steps ) ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Content: images                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Walk every measured image once and sort the candidates into the two image
	 * checks: downloaded far larger than displayed, and loaded eagerly although
	 * below the fold. Memoised for the current findings() call.
	 *
	 * @param array[] $runs Runs.
	 * @return array { oversized: array[], offscreen: array[] }
	 */
	private static function scan_images( array $runs ) {
		if ( isset( self::$ctx['images'] ) && is_array( self::$ctx['images'] ) ) {
			return self::$ctx['images'];
		}

		$over  = array();
		$off   = array();
		$ratio = (float) self::t( 'OVERSIZED_RATIO' );
		$min_w = (int) self::t( 'OVERSIZED_WASTED_BYTES' );

		foreach ( $runs as $ri => $run ) {
			$sizes = self::sizes_of( $run );
			$dpr   = self::effective_dpr( $run );

			foreach ( (array) self::g( $run['lab'], 'images', array() ) as $img ) {
				if ( ! is_array( $img ) || '' === self::s( $img, 'src' ) ) {
					continue;
				}
				$src      = self::s( $img, 'src' );
				$bytes    = isset( $sizes[ $src ] ) ? (int) $sizes[ $src ] : 0;
				$natural  = self::i( $img, 'naturalW', 0 );
				$rendered = self::n( $img, 'renderedW', 0 );
				$hidden   = $rendered <= 0 && self::n( $img, 'renderedH', 0 ) <= 0;

				// Vector images have no meaningful pixel size; 1x1 images are trackers.
				if ( $bytes <= 0 || $natural <= 2 || preg_match( '/\.svgz?(?:$|[?#])/i', $src ) ) {
					continue;
				}

				$group = null;

				if ( ! $hidden && $rendered > 0 ) {
					$need = $rendered * $dpr;
					if ( $natural > $ratio * $need ) {
						$wasted = (int) round( $bytes * ( 1 - pow( $need / $natural, 2 ) ) );
						// The HTML analysis already reports images wider than twice their
						// width attribute; repeating them adds nothing.
						if ( $wasted > $min_w && ! isset( $run['static_over'][ self::image_key( $src ) ] ) ) {
							$group  = self::image_group( $run, $img );
							$over[] = array(
								'ri'     => $ri,
								'img'    => $img,
								'bytes'  => $bytes,
								'wasted' => $wasted,
								'need'   => (int) ceil( $need ),
								'gkey'   => $group[0],
								'ginfo'  => $group[1],
							);
						}
					}
				}

				if ( empty( $img['inInitialViewport'] ) && 'lazy' !== self::g( $img, 'loading' ) && ! empty( $img['complete'] ) ) {
					$group = null === $group ? self::image_group( $run, $img ) : $group;
					$off[] = array(
						'ri'     => $ri,
						'img'    => $img,
						'bytes'  => $bytes,
						'hidden' => $hidden,
						'gkey'   => $group[0],
						'ginfo'  => $group[1],
					);
				}
			}
		}

		self::$ctx['images'] = array(
			'oversized' => $over,
			'offscreen' => $off,
		);
		return self::$ctx['images'];
	}

	/**
	 * Who owns an image: the post whose content holds it, the Bricks template
	 * it is built in, the plugin or theme whose files print it, or just the page.
	 *
	 * @param array $run Run.
	 * @param array $img Image.
	 * @return array { 0: group key, 1: group info }
	 */
	private static function image_group( array $run, array $img ) {
		if ( $run['post'] && isset( $run['in_content'][ self::image_key( self::s( $img, 'src' ) ) ] ) ) {
			return array(
				'post:' . (int) $run['post']['id'],
				array(
					'type' => 'post',
					'post' => $run['post'],
				),
			);
		}

		$bricks = self::bricks_info( self::g( $img, 'bricks' ) );
		if ( $bricks && ( '' !== $bricks['edit_url'] || '' !== $bricks['where'] ) ) {
			return array(
				'bricks:' . ( '' !== $bricks['edit_url'] ? $bricks['edit_url'] : $bricks['where'] ),
				array(
					'type'   => 'bricks',
					'bricks' => $bricks,
				),
			);
		}

		$component = self::comp( self::g( $img, 'component' ), self::s( $img, 'src' ) );
		if ( self::is_actionable( $component ) ) {
			return array(
				'comp:' . BLT_Optimized_Components::key( $component ),
				array(
					'type'      => 'comp',
					'component' => $component,
				),
			);
		}

		return array(
			'page:' . $run['url'],
			array(
				'type' => 'page',
				'name' => $run['name'],
				'post' => $run['post'],
			),
		);
	}

	/**
	 * Sentence subject for an image group: Post “X”, The Y theme, “Home”.
	 *
	 * @param array $info Group info.
	 * @return string
	 */
	private static function group_subject( array $info ) {
		switch ( $info['type'] ) {
			case 'post':
				return self::post_label( $info['post'] );
			case 'bricks':
				if ( '' !== $info['bricks']['where'] ) {
					/* translators: %s: where the elements live, e.g. 'header template “Main”'. */
					return sprintf( __( 'Bricks %s', 'blt-optimized' ), $info['bricks']['where'] );
				}
				return __( 'Bricks elements', 'blt-optimized' );
			case 'comp':
				return self::comp_subject( $info['component'] );
			case 'more':
				return __( 'More images', 'blt-optimized' );
		}
		return isset( $info['name'] ) ? (string) $info['name'] : __( 'The page', 'blt-optimized' );
	}

	/**
	 * Component as the subject of a sentence: "WooCommerce", "The Twenty
	 * Twenty-Four theme".
	 *
	 * @param array $component Component.
	 * @return string
	 */
	private static function comp_subject( array $component ) {
		$name = self::comp_name( $component );
		$type = isset( $component['type'] ) ? $component['type'] : '';
		if ( 'theme' === $type ) {
			/* translators: %s: theme name. */
			return sprintf( __( 'The %s theme', 'blt-optimized' ), $name );
		}
		if ( 'mu-plugin' === $type ) {
			/* translators: %s: must-use plugin name. */
			return sprintf( __( 'The %s must-use plugin', 'blt-optimized' ), $name );
		}
		return $name;
	}

	/**
	 * Label of a post for sentence use: Post “Summer sale”.
	 *
	 * @param array $post Page report `post` block.
	 * @return string
	 */
	private static function post_label( $post ) {
		$title = trim( wp_strip_all_tags( self::s( $post, 'title', '' ) ) );
		if ( '' === $title ) {
			$title = __( '(no title)', 'blt-optimized' );
		}
		$type = self::s( $post, 'type', 'post' );
		$noun = ucfirst( $type );
		$obj  = get_post_type_object( $type );
		if ( $obj && ! empty( $obj->labels->singular_name ) ) {
			$noun = $obj->labels->singular_name;
		}
		/* translators: 1: content type (Post, Page, Product), 2: its title. */
		return sprintf( __( '%1$s “%2$s”', 'blt-optimized' ), $noun, self::trunc( $title, 60 ) );
	}

	/**
	 * Images far larger than the space they are shown in, measured with the
	 * real layout of this viewport.
	 *
	 * @param array[] $runs Runs.
	 * @return array[]
	 */
	private static function check_oversized_images( array $runs ) {
		$scan = self::scan_images( $runs );
		return $scan['oversized'] ? self::image_findings( $runs, $scan['oversized'], 'oversized' ) : array();
	}

	/**
	 * Images below the first screen that the browser downloaded immediately.
	 *
	 * @param array[] $runs Runs.
	 * @return array[]
	 */
	private static function check_offscreen_images( array $runs ) {
		$scan = self::scan_images( $runs );
		return $scan['offscreen'] ? self::image_findings( $runs, $scan['offscreen'], 'offscreen' ) : array();
	}

	/**
	 * Group image candidates by owner and build one finding per group (the
	 * smallest groups beyond the cap are merged into one).
	 *
	 * @param array[] $runs  Runs.
	 * @param array[] $cands Candidates.
	 * @param string  $mode  oversized|offscreen.
	 * @return array[]
	 */
	private static function image_findings( array $runs, array $cands, $mode ) {
		$metric = 'oversized' === $mode ? 'wasted' : 'bytes';
		$groups = array();
		foreach ( $cands as $cand ) {
			$gkey = $cand['gkey'];
			if ( ! isset( $groups[ $gkey ] ) ) {
				$groups[ $gkey ] = array(
					'info'    => $cand['ginfo'],
					'items'   => array(),
					'per_run' => array(),
					'worst'   => 0,
				);
			}
			$groups[ $gkey ]['items'][]                = $cand;
			$groups[ $gkey ]['per_run'][ $cand['ri'] ] = ( isset( $groups[ $gkey ]['per_run'][ $cand['ri'] ] ) ? $groups[ $gkey ]['per_run'][ $cand['ri'] ] : 0 ) + $cand[ $metric ];
		}
		foreach ( $groups as $gkey => $group ) {
			$groups[ $gkey ]['worst'] = max( $group['per_run'] );
			if ( 'offscreen' === $mode && $groups[ $gkey ]['worst'] < (int) self::t( 'OFFSCREEN_MIN_BYTES' ) ) {
				unset( $groups[ $gkey ] );
			}
		}
		if ( ! $groups ) {
			return array();
		}
		uasort(
			$groups,
			static function ( $a, $b ) {
				return $b['worst'] <=> $a['worst'];
			}
		);

		$cap  = (int) self::t( 'MAX_IMAGE_FINDINGS' );
		$head = array_slice( $groups, 0, $cap, true );
		$rest = array_slice( $groups, $cap, null, true );
		$out  = array();
		foreach ( $head as $gkey => $group ) {
			$out[] = self::image_group_finding( $runs, $gkey, $group, $mode );
		}
		if ( $rest ) {
			$merged = array(
				'info'    => array( 'type' => 'more' ),
				'items'   => array(),
				'per_run' => array(),
				'worst'   => 0,
			);
			foreach ( $rest as $group ) {
				$merged['items'] = array_merge( $merged['items'], $group['items'] );
				foreach ( $group['per_run'] as $ri => $value ) {
					$merged['per_run'][ $ri ] = ( isset( $merged['per_run'][ $ri ] ) ? $merged['per_run'][ $ri ] : 0 ) + $value;
				}
			}
			$merged['worst'] = max( $merged['per_run'] );
			$out[]           = self::image_group_finding( $runs, 'more', $merged, $mode );
		}
		return $out;
	}

	/**
	 * One image finding (oversized or offscreen) for an owner.
	 *
	 * @param array[] $runs  Runs.
	 * @param string  $gkey  Group key.
	 * @param array   $group { info, items, per_run, worst }.
	 * @param string  $mode  oversized|offscreen.
	 * @return array
	 */
	private static function image_group_finding( array $runs, $gkey, array $group, $mode ) {
		$over  = 'oversized' === $mode;
		$info  = $group['info'];
		$who   = self::group_subject( $info );
		$items = $group['items'];
		$ris   = array_values( array_unique( wp_list_pluck( $items, 'ri' ) ) );
		$count = count(
			array_unique(
				array_map(
					static function ( $item ) {
						return self::image_key( $item['img']['src'] );
					},
					$items
				)
			)
		);

		usort(
			$items,
			static function ( $a, $b ) use ( $over ) {
				return $over ? $b['wasted'] <=> $a['wasted'] : $b['bytes'] <=> $a['bytes'];
			}
		);

		$rows = array();
		$seen = array();
		foreach ( $items as $item ) {
			$run = $runs[ $item['ri'] ];
			$img = $item['img'];
			$n   = isset( $seen[ $item['ri'] ] ) ? $seen[ $item['ri'] ] : 0;
			if ( $n >= self::MAX_ROWS_PER_RUN ) {
				continue;
			}
			$seen[ $item['ri'] ] = $n + 1;
			if ( $over ) {
				$rows[] = array(
					$run['ref'],
					self::device( $run['vp'] ),
					self::file_name( self::s( $img, 'src' ) ),
					number_format_i18n( self::i( $img, 'naturalW' ) ) . ' × ' . number_format_i18n( self::i( $img, 'naturalH' ) ) . ' px',
					number_format_i18n( self::i( $img, 'renderedW' ) ) . ' × ' . number_format_i18n( self::i( $img, 'renderedH' ) ) . ' px',
					self::fmt_bytes( $item['bytes'] ),
					'~' . self::fmt_bytes( $item['wasted'] ),
				);
			} else {
				$rows[] = array(
					$run['ref'],
					self::device( $run['vp'] ),
					self::file_name( self::s( $img, 'src' ) ),
					$item['hidden']
						? __( 'hidden (slide, tab or collapsed block)', 'blt-optimized' )
						/* translators: %s: distance from the top of the page in pixels. */
						: sprintf( __( '%s px from the top', 'blt-optimized' ), number_format_i18n( self::i( $img, 'top' ) ) ),
					self::fmt_bytes( $item['bytes'] ),
				);
			}
		}

		$worst = (int) $group['worst'];
		$next  = $over ? self::t( 'OVERSIZED_WARN_BYTES' ) : self::t( 'OFFSCREEN_WARN_BYTES' );
		if ( $over ) {
			$title = sprintf(
				/* translators: 1: owner (post, template, plugin or page), 2: number of images, 3: wasted size. */
				_n( '%1$s: %2$s image is downloaded much larger than it is shown (about %3$s wasted)', '%1$s: %2$s images are downloaded much larger than they are shown (about %3$s wasted)', $count, 'blt-optimized' ),
				$who,
				number_format_i18n( $count ),
				self::fmt_bytes( $worst )
			);
			$summary = sprintf(
				/* translators: %s: wasted size. */
				__( 'The browser downloaded image files with many more pixels than the layout shows them at (counting 2× screens on mobile). About %s of that is never visible; resized files load faster and cost less data. Sizes are what this browser actually fetched.', 'blt-optimized' ),
				self::fmt_bytes( $worst )
			);
		} else {
			$title = sprintf(
				/* translators: 1: owner (post, template, plugin or page), 2: number of images, 3: size. */
				_n( '%1$s: %2$s image below the fold is loaded immediately (%3$s)', '%1$s: %2$s images below the fold are loaded immediately (%3$s)', $count, 'blt-optimized' ),
				$who,
				number_format_i18n( $count ),
				self::fmt_bytes( $worst )
			);
			$summary = sprintf(
				/* translators: %s: size. */
				__( 'The browser downloaded %s of images that start outside the first screen (or are hidden) before the visitor could see them; they compete with the content that is visible. Lazy-loading them frees bandwidth for the LCP image and the rest of the first screen.', 'blt-optimized' ),
				self::fmt_bytes( $worst )
			);
		}

		if ( $over ) {
			$steps = self::steps(
				array(
					__( 'Replace each image with a copy resized to the width it is shown at (twice that for sharp phone and retina screens), or choose a smaller size when inserting it from the media library.', 'blt-optimized' ),
					__( 'If a template prints the image, use wp_get_attachment_image() (or a block/builder image element) so WordPress adds srcset and sizes and the browser picks the right file.', 'blt-optimized' ),
					__( 'Compress the files and serve WebP or AVIF; the image optimization module can do this in bulk.', 'blt-optimized' ),
				)
			);
		} else {
			$steps = self::steps(
				array(
					__( 'Add loading="lazy" to images below the first screen. WordPress does this for images in post content; check the theme template, slider or gallery plugin, or the page builder\'s image setting.', 'blt-optimized' ),
					__( 'For images in hidden slides, tabs or accordions, enable the slider\'s own lazy-loading so inactive slides are not downloaded up front.', 'blt-optimized' ),
					__( 'Keep the first (LCP) image eager and give it fetchpriority="high"; lazy-load everything after it.', 'blt-optimized' ),
				)
			);
		}

		$post   = ( 'post' === $info['type'] || 'page' === $info['type'] ) ? ( isset( $info['post'] ) ? $info['post'] : null ) : null;
		$bricks = 'bricks' === $info['type'] ? $info['bricks'] : null;
		$fix    = self::located_fix( $bricks, $post, $steps );

		$where = self::urls_of( $runs, $ris );
		foreach ( array_slice(
			array_unique(
				array_map(
					static function ( $item ) {
						return $item['img']['src'];
					},
					$items
				)
			),
			0,
			5
		) as $src ) {
			$where[] = $src;
		}

		$columns = $over
			? array( __( 'Page', 'blt-optimized' ), __( 'Device', 'blt-optimized' ), __( 'Image', 'blt-optimized' ), __( 'File size in pixels', 'blt-optimized' ), __( 'Shown at', 'blt-optimized' ), __( 'File size', 'blt-optimized' ), __( 'Wasted (est.)', 'blt-optimized' ) )
			: array( __( 'Page', 'blt-optimized' ), __( 'Device', 'blt-optimized' ), __( 'Image', 'blt-optimized' ), __( 'Position', 'blt-optimized' ), __( 'File size', 'blt-optimized' ) );

		return self::finding(
			array(
				'id'        => $over ? 'lab.oversized_images' : 'lab.offscreen_images',
				'key'       => ( $over ? 'lab.oversized_images:' : 'lab.offscreen_images:' ) . $gkey,
				'category'  => 'content',
				'severity'  => $worst >= $next ? 'warning' : 'notice',
				'title'     => $title,
				'summary'   => $summary,
				'impact'    => self::impact_bytes( $worst, $over ),
				'component' => 'comp' === $info['type'] ? $info['component'] : null,
				'where'     => $where,
				'details'   => array(
					'columns' => $columns,
					'rows'    => $rows,
				),
				'fix'       => $fix,
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Content: DOM size and page weight                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * DOM size once the page has run in the browser. Skipped for pages whose
	 * HTML analysis already reports the same size; reported when JavaScript
	 * grows the DOM past the threshold (or well beyond what the HTML held).
	 *
	 * @param array[] $runs Runs.
	 * @return array[]
	 */
	private static function check_dom_size( array $runs ) {
		$warn       = (int) self::t( 'DOM_WARN' );
		$crit       = (int) self::t( 'DOM_CRIT' );
		$growth     = (float) self::t( 'DOM_JS_GROWTH' );
		$rows       = array();
		$ris        = array();
		$worst      = 0;
		$worst_html = 0;
		$deepest    = 0;

		foreach ( $runs as $ri => $run ) {
			$nodes = self::i( self::g( $run['lab'], 'dom' ), 'nodes', 0 );
			if ( $nodes < $warn ) {
				continue;
			}
			$html = self::i( self::g( $run['page'], 'html' ), 'dom_nodes', 0 );
			if ( $html >= $warn && $nodes < $html * $growth ) {
				continue;
			}
			$depth = self::i( self::g( $run['lab'], 'dom' ), 'depth', 0 );
			$ris[] = $ri;
			if ( $nodes > $worst ) {
				$worst      = $nodes;
				$worst_html = $html;
			}
			$deepest = max( $deepest, $depth );
			$rows[]  = array(
				$run['ref'],
				self::device( $run['vp'] ),
				number_format_i18n( $nodes ),
				$html > 0 ? number_format_i18n( $html ) : '–',
				$html > 0 ? number_format_i18n( max( 0, $nodes - $html ) ) : '–',
				$depth > 0 ? number_format_i18n( $depth ) : '–',
			);
		}
		if ( ! $ris ) {
			return array();
		}

		$title = sprintf(
			/* translators: 1: number of elements, 2: page names, 3: " (mobile)". */
			__( 'The page has %1$s DOM elements once it has loaded in the browser on %2$s%3$s', 'blt-optimized' ),
			number_format_i18n( $worst ),
			self::pages_phrase( $runs, $ris ),
			self::devices_phrase( $runs, $ris )
		);
		if ( $worst_html > 0 && $worst > $worst_html ) {
			$title = sprintf(
				/* translators: 1: number of elements, 2: page names, 3: elements in the served HTML. */
				__( 'JavaScript grows the page to %1$s DOM elements on %2$s (the HTML holds %3$s)', 'blt-optimized' ),
				number_format_i18n( $worst ),
				self::pages_phrase( $runs, $ris ),
				number_format_i18n( $worst_html )
			);
		}

		$summary = __( 'The browser counted this many elements after scripts finished. A large DOM makes every style recalculation and layout slower, uses more memory and delays interactivity, most noticeably on phones.', 'blt-optimized' );
		if ( $deepest >= (int) self::t( 'DOM_DEPTH_WARN' ) ) {
			$summary .= ' ' . sprintf(
				/* translators: %s: nesting depth. */
				__( 'The deepest branch is %s levels deep (deep nesting is the typical sign of stacked page-builder containers).', 'blt-optimized' ),
				number_format_i18n( $deepest )
			);
		}

		return array(
			self::finding(
				array(
					'id'       => 'lab.dom_size',
					'key'      => 'lab.dom_size',
					'category' => 'content',
					'severity' => $worst >= $crit ? 'critical' : 'warning',
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
					'where'    => self::urls_of( $runs, $ris ),
					'details'  => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Device', 'blt-optimized' ), __( 'Elements in the browser', 'blt-optimized' ), __( 'Elements in the HTML', 'blt-optimized' ), __( 'Added by JavaScript', 'blt-optimized' ), __( 'Deepest nesting', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => self::fix_manual(
						__( 'How to fix', 'blt-optimized' ),
						self::steps(
							array(
								__( 'Simplify the page structure: each page-builder section, container and column adds wrapper elements; remove empty or purely decorative wrappers and flatten nested containers.', 'blt-optimized' ),
								__( 'Split long lists (products, comments, related posts, mega-menu items) with pagination or "load more" so they are not all in the page at once.', 'blt-optimized' ),
								__( 'Look at what scripts add after load (sliders, mega menus, hidden tabs, widgets): each hidden copy of content still counts. Remove what the page does not need.', 'blt-optimized' ),
							)
						)
					),
				)
			),
		);
	}

	/**
	 * Total page weight as the browser downloaded it.
	 *
	 * @param array[] $runs Runs.
	 * @return array[]
	 */
	private static function check_page_weight( array $runs ) {
		$rows  = array();
		$ris   = array();
		$worst = null;
		$crit  = false;

		foreach ( $runs as $ri => $run ) {
			$total = (int) $run['sum']['bytes']['total'];
			$limit = (float) self::t( 'desktop' === $run['vp'] ? 'PAGE_WEIGHT_DESKTOP_BYTES' : 'PAGE_WEIGHT_MOBILE_BYTES' );
			if ( $total <= $limit ) {
				continue;
			}
			$ris[]  = $ri;
			$crit   = $crit || $total >= 2 * $limit;
			$bytes  = $run['sum']['bytes'];
			$rows[] = array(
				$run['ref'],
				self::device( $run['vp'] ),
				self::fmt_bytes( $total ),
				self::fmt_bytes( $bytes['img'] ),
				self::fmt_bytes( $bytes['js'] ),
				self::fmt_bytes( $bytes['css'] ),
				self::fmt_bytes( $bytes['font'] ),
				number_format_i18n( $run['sum']['requests'] ),
			);
			if ( null === $worst || $total > (int) $runs[ $worst ]['sum']['bytes']['total'] ) {
				$worst = $ri;
			}
		}
		if ( ! $ris ) {
			return array();
		}

		$lead  = $runs[ $worst ];
		$bytes = $lead['sum']['bytes'];
		$kinds = array(
			'img'   => __( 'Images', 'blt-optimized' ),
			'js'    => __( 'JavaScript', 'blt-optimized' ),
			'css'   => __( 'CSS', 'blt-optimized' ),
			'font'  => __( 'Fonts', 'blt-optimized' ),
			'other' => __( 'Other files', 'blt-optimized' ),
		);
		$main  = 'img';
		foreach ( $kinds as $kind => $label ) {
			if ( $bytes[ $kind ] > $bytes[ $main ] ) {
				$main = $kind;
			}
		}
		$share = $bytes['total'] > 0 ? (int) round( 100 * $bytes[ $main ] / $bytes['total'] ) : 0;

		// The three heaviest files of the heaviest run, by name.
		$big = array();
		foreach ( (array) self::g( $lead['lab'], 'resources', array() ) as $resource ) {
			if ( is_array( $resource ) && '' !== self::s( $resource, 'url' ) ) {
				$big[ self::s( $resource, 'url' ) ] = self::resource_size( $resource );
			}
		}
		arsort( $big );
		$names = array();
		foreach ( array_slice( $big, 0, 3, true ) as $url => $size ) {
			$names[] = self::file_name( $url ) . ' (' . self::fmt_bytes( $size ) . ')';
		}

		$title = sprintf(
			/* translators: 1: total size, 2: page names, 3: " (mobile)", 4: kind of file, 5: percent. */
			__( 'Pages download %1$s in the browser on %2$s%3$s: %4$s are %5$s%% of it', 'blt-optimized' ),
			self::fmt_bytes( $bytes['total'] ),
			self::pages_phrase( $runs, $ris ),
			self::devices_phrase( $runs, $ris ),
			strtolower( $kinds[ $main ] ),
			number_format_i18n( $share )
		);

		$summary = sprintf(
			/* translators: %s: total size. */
			__( 'The browser transferred %s to load the heaviest of these pages (files served from the browser cache count at their full size, so the figure is stable between runs). Heavy pages load slowly on mobile connections and use visitors\' data.', 'blt-optimized' ),
			self::fmt_bytes( $bytes['total'] )
		);
		if ( $names ) {
			/* translators: %s: list of file names with sizes. */
			$summary .= ' ' . sprintf( __( 'Heaviest files: %s.', 'blt-optimized' ), wp_sprintf( '%l', $names ) );
		}

		$fix = self::fix_manual(
			__( 'How to fix', 'blt-optimized' ),
			self::steps(
				array(
					__( 'Start with the heaviest files named above and the largest category in the table.', 'blt-optimized' ),
					__( 'Images: resize and compress them, serve WebP or AVIF, and lazy-load the ones below the fold.', 'blt-optimized' ),
					__( 'JavaScript and CSS: remove plugins and tags the page does not need, and load assets only on the pages that use them (Speed Tweaks → Asset rules).', 'blt-optimized' ),
					__( 'Video and third-party embeds: replace autoplaying or embedded players with a thumbnail that loads the player on click.', 'blt-optimized' ),
				)
			)
		);
		if ( 'img' === $main ) {
			$fix = self::fix_link(
				self::images_enabled() ? __( 'Open image optimization', 'blt-optimized' ) : __( 'Enable image optimization', 'blt-optimized' ),
				admin_url( self::images_enabled() ? 'admin.php?page=blt-optimizer' : 'admin.php?page=blt-optimized-settings' ),
				$fix['steps']
			);
		}

		return array(
			self::finding(
				array(
					'id'       => 'lab.page_weight',
					'key'      => 'lab.page_weight',
					'category' => 'content',
					'severity' => $crit ? 'critical' : 'warning',
					'title'    => $title,
					'summary'  => $summary,
					'impact'   => self::impact_bytes( $bytes['total'] ),
					'where'    => self::urls_of( $runs, $ris ),
					'details'  => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Device', 'blt-optimized' ), __( 'Total', 'blt-optimized' ), __( 'Images', 'blt-optimized' ), __( 'JavaScript', 'blt-optimized' ), __( 'CSS', 'blt-optimized' ), __( 'Fonts', 'blt-optimized' ), __( 'Requests', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => $fix,
				)
			),
		);
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
	/* Assets: render-blocking files                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Render-blocking stylesheets and scripts, grouped per component, with the
	 * time the browser spent waiting for them.
	 *
	 * @param array[] $runs Runs.
	 * @return array[]
	 */
	private static function check_render_blocking( array $runs ) {
		$groups = array();
		foreach ( $runs as $ri => $run ) {
			$ttfb = self::n( self::g( $run['lab'], 'nav' ), 'ttfb', 0 );
			foreach ( (array) self::g( $run['lab'], 'resources', array() ) as $resource ) {
				if ( ! is_array( $resource ) || empty( $resource['blocking'] ) || '' === self::s( $resource, 'url' ) ) {
					continue;
				}
				$component = self::comp( self::g( $resource, 'component' ), self::s( $resource, 'url' ) );
				$ckey      = BLT_Optimized_Components::key( $component );
				if ( ! isset( $groups[ $ckey ] ) ) {
					$groups[ $ckey ] = array(
						'component' => $component,
						'runs'      => array(),
					);
				}
				if ( ! isset( $groups[ $ckey ]['runs'][ $ri ] ) ) {
					$groups[ $ckey ]['runs'][ $ri ] = array(
						'files'    => array(),
						'bytes'    => 0,
						'last_end' => 0.0,
						'delay'    => 0.0,
					);
				}
				$size              = self::resource_size( $resource );
				$end               = self::n( $resource, 'end', 0 );
				$entry             = &$groups[ $ckey ]['runs'][ $ri ];
				$entry['files'][]  = $resource;
				$entry['bytes']   += $size;
				$entry['last_end'] = max( $entry['last_end'], $end );
				$entry['delay']    = max( 0.0, $entry['last_end'] - $ttfb );
				unset( $entry );
			}
		}

		$findings = array();
		foreach ( $groups as $ckey => $group ) {
			$worst_delay = 0.0;
			$worst_bytes = 0;
			$most_files  = 0;
			foreach ( $group['runs'] as $entry ) {
				$worst_delay = max( $worst_delay, $entry['delay'] );
				$worst_bytes = max( $worst_bytes, $entry['bytes'] );
				$most_files  = max( $most_files, count( $entry['files'] ) );
			}
			if ( $worst_delay < (float) self::t( 'RENDER_BLOCKING_NOTICE_MS' ) && $most_files < (int) self::t( 'RENDER_BLOCKING_MIN_FILES' ) && $worst_bytes < (int) self::t( 'RENDER_BLOCKING_NOTICE_BYTES' ) ) {
				continue;
			}
			$findings[] = self::render_blocking_finding( $runs, $ckey, $group, $worst_delay, $worst_bytes, $most_files );
		}
		return $findings;
	}

	/**
	 * One render-blocking finding for a component.
	 *
	 * @param array[] $runs        All runs.
	 * @param string  $ckey        Component key.
	 * @param array   $group       { component, runs: run index => { files, bytes, last_end, delay } }.
	 * @param float   $worst_delay Longest wait (ms after the HTML arrived).
	 * @param int     $worst_bytes Most bytes blocking in one run.
	 * @param int     $most_files  Most files blocking in one run.
	 * @return array
	 */
	private static function render_blocking_finding( array $runs, $ckey, array $group, $worst_delay, $worst_bytes, $most_files ) {
		$component = $group['component'];
		$name      = self::comp_name( $component );
		$ris       = array_keys( $group['runs'] );
		$rows      = array();
		$has_js    = false;
		$has_css   = false;
		$handles   = array();

		$has_jquery  = false;
		$has_migrate = false;
		foreach ( $group['runs'] as $ri => $entry ) {
			$run   = $runs[ $ri ];
			$shown = 0;
			foreach ( $entry['files'] as $file ) {
				$kind = self::s( $file, 'kind', self::kind_of( $file ) );
				if ( 'js' === $kind ) {
					if ( self::is_jquery( self::s( $file, 'url' ) ) ) {
						$has_jquery  = true;
						$has_migrate = $has_migrate || false !== stripos( self::s( $file, 'url' ), 'jquery-migrate' );
					} else {
						$has_js = true;
					}
				} else {
					$has_css = true;
				}
				$handle = self::handle_for( $run, self::s( $file, 'url' ) );
				if ( '' !== $handle ) {
					$handles[ $handle ] = true;
				}
				if ( $shown++ < self::MAX_ROWS_PER_RUN ) {
					$rows[] = array(
						$run['ref'],
						self::device( $run['vp'] ),
						self::file_name( self::s( $file, 'url' ) ),
						'js' === $kind ? __( 'script', 'blt-optimized' ) : __( 'stylesheet', 'blt-optimized' ),
						self::resource_size( $file ) > 0 ? self::fmt_bytes( self::resource_size( $file ) ) : '–',
						self::fmt_ms( self::n( $file, 'end', 0 ) ),
						$handle,
					);
				}
			}
		}

		$title = sprintf(
			/* translators: 1: plugin or theme name, 2: number of files, 3: milliseconds, 4: page names. */
			_n( '%1$s blocks rendering with %2$s file (the browser waits up to %3$s for it on %4$s)', '%1$s blocks rendering with %2$s files (the browser waits up to %3$s for them on %4$s)', $most_files, 'blt-optimized' ),
			$name,
			number_format_i18n( $most_files ),
			self::fmt_ms( $worst_delay ),
			self::pages_phrase( $runs, $ris )
		);

		$summary = sprintf(
			/* translators: 1: size, 2: milliseconds after the HTML arrived. */
			__( 'The browser cannot paint anything until these stylesheets and scripts in the page head (%1$s) have loaded: the last one finished %2$s after the HTML arrived.', 'blt-optimized' ),
			$worst_bytes > 0 ? self::fmt_bytes( $worst_bytes ) : __( 'size not reported', 'blt-optimized' ),
			self::fmt_ms( $worst_delay )
		);

		$state = self::defer_state();
		if ( $has_js && 'offer' === $state ) {
			$fix      = array(
				'type'    => 'action',
				'label'   => __( 'Defer scripts', 'blt-optimized' ),
				'action'  => 'tweak',
				'args'    => array(
					'key'   => 'defer_scripts',
					'value' => 1,
				),
				'confirm' => __( 'This adds the defer attribute to front-end scripts. WordPress only does it where its own rules say it is safe (jQuery stays as it is), but test your key pages afterwards; you can switch it off again in Speed Tweaks.', 'blt-optimized' ),
				'steps'   => $has_css ? __( 'Scripts are fixed by the Defer scripts tweak. Stylesheets cannot be deferred safely: inline the critical CSS and load the rest asynchronously, or load the stylesheet only on the pages that need it (Speed Tweaks → Asset rules).', 'blt-optimized' ) : '',
			);
			$summary .= ' ' . __( 'The Defer scripts tweak fixes the scripts in one click; WordPress skips any script it cannot safely defer.', 'blt-optimized' );
		} elseif ( $has_js && 'on' === $state ) {
			$summary .= ' ' . __( 'The Defer scripts tweak is already on, so WordPress is holding these scripts back on purpose.', 'blt-optimized' );
		}
		if ( ! ( $has_js && 'offer' === $state ) ) {
			$steps = array();
			if ( $has_js && 'on' === $state ) {
				$steps[] = __( 'The Defer scripts tweak is already on, so WordPress is holding these scripts back on purpose (another script or inline code needs them immediately). Check whether the handle is in the "never defer" list, or ask the plugin or theme for a deferred version.', 'blt-optimized' );
			} elseif ( $has_js ) {
				$steps[] = __( 'Update WordPress to 6.3 or newer: it can defer scripts safely with a single tweak. Until then, load each script in the footer (the plugin or theme usually has a setting).', 'blt-optimized' );
			}
			if ( $has_css ) {
				$steps[] = __( 'Stylesheets block rendering by design. Inline the small critical part of the CSS and load the rest asynchronously (media="print" onload swap), or stop loading the stylesheet on pages that do not use it (Speed Tweaks → Asset rules).', 'blt-optimized' );
			}
			if ( $has_jquery ) {
				$steps[] = __( 'jQuery is loaded in the page head because other scripts depend on it, and the Defer scripts tweak deliberately leaves it alone: deferring it would break scripts that run inline. The lasting fix is to remove the plugins and theme features that need jQuery, or have them load in the footer.', 'blt-optimized' );
			}
			if ( $has_migrate ) {
				$steps[] = __( 'jQuery Migrate is a second blocking file kept for old plugins: switch on "Remove jQuery Migrate" in Speed Tweaks if nothing on the site still needs it.', 'blt-optimized' );
			}
			if ( $handles ) {
				/* translators: %s: list of script/style handles. */
				$steps[] = sprintf( __( 'Handles to look for: %s.', 'blt-optimized' ), implode( ', ', array_keys( $handles ) ) );
			}
			$fix = self::fix_manual( __( 'How to fix', 'blt-optimized' ), self::steps( $steps ) );
		}

		$summary .= ' ' . self::note();

		$severity = ( $worst_delay >= (float) self::t( 'RENDER_BLOCKING_WARN_MS' ) || $worst_bytes >= (int) self::t( 'RENDER_BLOCKING_WARN_BYTES' ) ) ? 'warning' : 'notice';

		return self::finding(
			array(
				'id'        => 'lab.render_blocking',
				'key'       => 'lab.render_blocking:' . $ckey,
				'category'  => 'assets',
				'severity'  => $severity,
				'title'     => $title,
				'summary'   => $summary,
				'impact'    => self::impact_ms( $worst_delay ),
				'component' => $component,
				'where'     => self::urls_of( $runs, $ris ),
				'details'   => array(
					'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Device', 'blt-optimized' ), __( 'File', 'blt-optimized' ), __( 'Type', 'blt-optimized' ), __( 'Size', 'blt-optimized' ), __( 'Finished at', 'blt-optimized' ), __( 'Handle', 'blt-optimized' ) ),
					'rows'    => $rows,
				),
				'fix'       => $fix,
			)
		);
	}

	/**
	 * State of the "Defer scripts" tweak as a fix: offer it, already on (the
	 * scripts are blocking anyway), or unsupported (WordPress before 6.3).
	 *
	 * @return string offer|on|unsupported
	 */
	private static function defer_state() {
		if ( version_compare( get_bloginfo( 'version' ), '6.3', '<' ) ) {
			return 'unsupported';
		}
		if ( class_exists( 'BLT_Optimized_Tweaks' ) && method_exists( 'BLT_Optimized_Tweaks', 'get' ) ) {
			try {
				$tweaks = BLT_Optimized_Tweaks::get();
				if ( is_array( $tweaks ) && ! empty( $tweaks['defer_scripts'] ) ) {
					return 'on';
				}
			} catch ( \Throwable $e ) {
				return 'offer';
			}
		}
		return 'offer';
	}

	/**
	 * Whether a script URL is jQuery or its migrate plugin (never deferred by
	 * the defer tweak, so a defer fix would not cover it).
	 *
	 * @param string $url Script URL.
	 * @return bool
	 */
	private static function is_jquery( $url ) {
		return is_string( $url ) && (bool) preg_match( '#/jquery(?:-migrate)?(?:\.min)?\.js#i', $url );
	}

	/* ------------------------------------------------------------------ */
	/* Assets: unused CSS                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * CSS rules that matched nothing on the page, per component.
	 *
	 * @param array[] $runs Runs.
	 * @return array[]
	 */
	private static function check_unused_css( array $runs ) {
		$groups = array();
		foreach ( $runs as $ri => $run ) {
			foreach ( (array) self::g( $run['lab'], 'css', array() ) as $sheet ) {
				if ( ! is_array( $sheet ) || empty( $sheet['accessible'] ) || self::i( $sheet, 'bytes', 0 ) <= 0 ) {
					continue;
				}
				$href      = self::s( $sheet, 'href', '' );
				$component = self::comp( self::g( $sheet, 'component' ), $href );
				$ckey      = BLT_Optimized_Components::key( $component );
				if ( ! isset( $groups[ $ckey ] ) ) {
					$groups[ $ckey ] = array(
						'component' => $component,
						'runs'      => array(),
						'sheets'    => array(),
					);
				}
				if ( ! isset( $groups[ $ckey ]['runs'][ $ri ] ) ) {
					$groups[ $ckey ]['runs'][ $ri ] = array(
						'bytes'  => 0,
						'unused' => 0,
						'sheets' => array(),
					);
				}
				$bytes                                      = (int) $sheet['bytes'];
				$unused                                     = self::i( $sheet, 'unusedBytes', 0 );
				$groups[ $ckey ]['runs'][ $ri ]['bytes']   += $bytes;
				$groups[ $ckey ]['runs'][ $ri ]['unused']  += $unused;
				$groups[ $ckey ]['runs'][ $ri ]['sheets'][] = $sheet;

				$skey = '' !== $href ? self::path_key( $href ) : 'inline:' . self::s( $sheet, 'inlineId', '' );
				if ( ! isset( $groups[ $ckey ]['sheets'][ $skey ] ) ) {
					$groups[ $ckey ]['sheets'][ $skey ] = array(
						'name'  => '' !== $href ? self::file_name( $href ) : self::inline_name( $sheet ),
						'pages' => array(),
					);
				}
				// Per page: unused only if almost nothing matched in every run of that page.
				$mostly = $bytes > 0 && $unused / $bytes >= 0.9;
				$p      = $run['p'];
				$groups[ $ckey ]['sheets'][ $skey ]['pages'][ $p ] = isset( $groups[ $ckey ]['sheets'][ $skey ]['pages'][ $p ] ) ? ( $groups[ $ckey ]['sheets'][ $skey ]['pages'][ $p ] && $mostly ) : $mostly;
			}
		}

		$findings = array();
		foreach ( $groups as $ckey => $group ) {
			$worst = null;
			foreach ( $group['runs'] as $ri => $entry ) {
				if ( null === $worst || $entry['unused'] > $group['runs'][ $worst ]['unused'] ) {
					$worst = $ri;
				}
			}
			$unused = (int) $group['runs'][ $worst ]['unused'];
			$total  = (int) $group['runs'][ $worst ]['bytes'];
			$ratio  = $total > 0 ? $unused / $total : 0.0;
			if ( $unused >= (int) self::t( 'UNUSED_CSS_WARN_BYTES' ) && $ratio >= (float) self::t( 'UNUSED_CSS_WARN_RATIO' ) && 'core' !== $group['component']['type'] ) {
				// WordPress core's own CSS offers no safe fix, so it never rises above a notice.
				$severity = 'warning';
			} elseif ( $unused >= (int) self::t( 'UNUSED_CSS_NOTICE_BYTES' ) ) {
				$severity = 'notice';
			} else {
				continue;
			}
			$findings[] = self::unused_css_finding( $runs, $ckey, $group, $worst, $severity );
		}
		return $findings;
	}

	/**
	 * Display name of an inline stylesheet.
	 *
	 * @param array $sheet CSS entry.
	 * @return string
	 */
	private static function inline_name( array $sheet ) {
		$id = self::s( $sheet, 'inlineId', '' );
		/* translators: %s: id of the <style> element. */
		return '' !== $id ? sprintf( __( 'inline style #%s', 'blt-optimized' ), $id ) : __( 'inline style', 'blt-optimized' );
	}

	/**
	 * One unused-CSS finding for a component.
	 *
	 * @param array[] $runs     All runs.
	 * @param string  $ckey     Component key.
	 * @param array   $group    { component, runs, sheets }.
	 * @param int     $worst    Index of the run with the most unused CSS.
	 * @param string  $severity warning|notice.
	 * @return array
	 */
	private static function unused_css_finding( array $runs, $ckey, array $group, $worst, $severity ) {
		$component = $group['component'];
		$name      = self::comp_name( $component );
		$ris       = array_keys( $group['runs'] );
		$entry     = $group['runs'][ $worst ];
		$unused    = (int) $entry['unused'];
		$pct       = $entry['bytes'] > 0 ? (int) round( 100 * $unused / $entry['bytes'] ) : 0;

		$rows    = array();
		$handles = array();
		$other   = 0;
		foreach ( $group['runs'] as $ri => $run_entry ) {
			$run    = $runs[ $ri ];
			$sheets = $run_entry['sheets'];
			usort(
				$sheets,
				static function ( $a, $b ) {
					return self::i( $b, 'unusedBytes', 0 ) <=> self::i( $a, 'unusedBytes', 0 );
				}
			);
			foreach ( array_slice( $sheets, 0, self::MAX_ROWS_PER_RUN ) as $sheet ) {
				$href   = self::s( $sheet, 'href', '' );
				$bytes  = self::i( $sheet, 'bytes', 0 );
				$un     = self::i( $sheet, 'unusedBytes', 0 );
				$handle = '' !== $href ? self::handle_for( $run, $href ) : '';
				if ( '' !== $handle && $un >= 4 * KB_IN_BYTES ) {
					$handles[ $handle ] = true;
				}
				$rows[] = array(
					$run['ref'],
					self::device( $run['vp'] ),
					'' !== $href ? self::file_name( $href ) : self::inline_name( $sheet ),
					self::fmt_bytes( $bytes ),
					self::fmt_bytes( $un ),
					$bytes > 0 ? (int) round( 100 * $un / $bytes ) . '%' : '–',
					$handle,
				);
			}
			if ( $ri === $worst ) {
				foreach ( $run_entry['sheets'] as $sheet ) {
					$other += self::i( $sheet, 'otherMediaRules', 0 );
				}
			}
		}

		// Sheets that are almost entirely unused on every page they appear on (needs two pages to say so).
		$always = array();
		foreach ( $group['sheets'] as $sheet ) {
			if ( count( $sheet['pages'] ) >= 2 && ! in_array( false, $sheet['pages'], true ) ) {
				$always[] = $sheet['name'];
			}
		}

		$title = sprintf(
			/* translators: 1: plugin or theme name, 2: unused size, 3: percent unused, 4: page names. */
			__( '%1$s ships about %2$s of CSS (%3$s%%) that matches nothing on %4$s', 'blt-optimized' ),
			$name,
			self::fmt_bytes( $unused ),
			number_format_i18n( $pct ),
			self::pages_phrase( $runs, $ris )
		);

		$summary = sprintf(
			/* translators: 1: unused size, 2: total size. */
			__( 'The browser checked every CSS rule against the loaded page: %1$s of %2$s matched no element. The browser still downloads and parses all of it, which delays rendering.', 'blt-optimized' ),
			self::fmt_bytes( $unused ),
			self::fmt_bytes( $entry['bytes'] )
		);
		if ( $always ) {
			/* translators: %s: list of stylesheet names. */
			$summary .= ' ' . sprintf( __( 'Almost nothing in %s is used on any of the measured pages.', 'blt-optimized' ), wp_sprintf( '%l', array_slice( $always, 0, 3 ) ) );
		}
		$summary .= ' ' . __( 'Rules for hover states, menus and pop-ups that only appear after a click count as unused until then, so test before removing anything. The figure is the CSS text size, not the transfer size.', 'blt-optimized' );
		if ( $other > 0 ) {
			/* translators: %s: number of rules. */
			$summary .= ' ' . sprintf( __( '%s rules apply only to other screen sizes and are not counted as unused.', 'blt-optimized' ), number_format_i18n( $other ) );
		}

		$steps       = array();
		$inline_core = 'core' === $component['type'];
		if ( 'external' === $component['type'] ) {
			$steps[] = __( 'Load a smaller or custom build of this stylesheet, or self-host only the parts you use.', 'blt-optimized' );
		} elseif ( $inline_core ) {
			$steps[] = __( 'This CSS is generated by WordPress itself (theme.json global styles and the block library) for every page, so it cannot be trimmed safely with an asset rule.', 'blt-optimized' );
			$steps[] = __( 'If it is large, check the theme\'s theme.json for presets (colours, fonts, gradients, spacing) it defines but never uses, or choose a leaner theme.', 'blt-optimized' );
		} else {
			$steps[] = $handles
				/* translators: %s: list of stylesheet handles. */
				? sprintf( __( 'Load the stylesheet only on the pages that use it: Speed Tweaks → Asset rules → add a rule for the style handle(s) %s, set to load only where needed.', 'blt-optimized' ), implode( ', ', array_keys( $handles ) ) )
				: __( 'Load the stylesheet only on the pages that use it: Speed Tweaks → Asset rules → add a rule for its style handle (the file name in the table shows which one it is).', 'blt-optimized' );
			/* translators: %s: plugin or theme name. */
			$steps[] = sprintf( __( 'Check %s\'s settings for options such as "load assets only where needed", "disable default styles" or modular CSS.', 'blt-optimized' ), $name );
		}
		$steps[] = __( 'Re-run the lab test afterwards and check pages with menus, forms and sliders by hand: styles for interactions that did not happen during the test may be needed.', 'blt-optimized' );

		return self::finding(
			array(
				'id'        => 'lab.unused_css',
				'key'       => 'lab.unused_css:' . $ckey,
				'category'  => 'assets',
				'severity'  => $severity,
				'title'     => $title,
				'summary'   => $summary,
				'impact'    => self::impact_bytes( $unused, true ),
				'component' => $component,
				'where'     => self::urls_of( $runs, $ris ),
				'details'   => array(
					'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Device', 'blt-optimized' ), __( 'Stylesheet', 'blt-optimized' ), __( 'Size', 'blt-optimized' ), __( 'Unused', 'blt-optimized' ), __( 'Unused %', 'blt-optimized' ), __( 'Handle', 'blt-optimized' ) ),
					'rows'    => $rows,
				),
				'fix'       => self::fix_manual( __( 'How to fix', 'blt-optimized' ), self::steps( $steps ) ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Assets: main-thread time                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Script time per component in one run, from the Long Animation Frames the
	 * browser attributed to scripts.
	 *
	 * @param array $run Run.
	 * @return array<string,array> Component key => { component, total, forced, scripts: key => { url, label, trigger, duration, forced } }.
	 */
	private static function loaf_by_component( array $run ) {
		$out = array();
		foreach ( (array) self::g( $run['lab'], 'loaf', array() ) as $frame ) {
			foreach ( (array) self::g( $frame, 'scripts', array() ) as $script ) {
				if ( ! is_array( $script ) ) {
					continue;
				}
				$duration = self::n( $script, 'duration', 0 );
				if ( $duration <= 0 ) {
					continue;
				}
				$url       = self::s( $script, 'url', '' );
				$component = ( '' === $url && empty( $script['component'] ) )
					? self::inline_script_component()
					: self::comp( self::g( $script, 'component' ), $url );
				$ckey      = BLT_Optimized_Components::key( $component );
				$trigger   = self::s( $script, 'fn', '' );
				if ( '' === $trigger ) {
					// The invoker is often the script's own URL; the invoker type says more.
					$invoker = self::s( $script, 'invoker', '' );
					$trigger = ( '' !== $invoker && ! preg_match( '#^https?://#i', $invoker ) ) ? $invoker : self::s( $script, 'invokerType', '' );
				}
				$skey = ( '' !== $url ? self::path_key( $url ) : 'inline' ) . '|' . $trigger;
				if ( ! isset( $out[ $ckey ] ) ) {
					$out[ $ckey ] = array(
						'component' => $component,
						'total'     => 0.0,
						'forced'    => 0.0,
						'scripts'   => array(),
					);
				}
				$forced                  = self::n( $script, 'forcedLayout', 0 );
				$out[ $ckey ]['total']  += $duration;
				$out[ $ckey ]['forced'] += $forced;
				if ( ! isset( $out[ $ckey ]['scripts'][ $skey ] ) ) {
					$out[ $ckey ]['scripts'][ $skey ] = array(
						'url'      => $url,
						'label'    => '' !== $url ? self::file_name( $url ) : __( 'inline script', 'blt-optimized' ),
						'trigger'  => self::trunc( $trigger, 60 ),
						'duration' => 0.0,
						'forced'   => 0.0,
					);
				}
				$out[ $ckey ]['scripts'][ $skey ]['duration'] += $duration;
				$out[ $ckey ]['scripts'][ $skey ]['forced']   += $forced;
			}
		}
		return $out;
	}

	/**
	 * Main-thread script time per component (Σ Long Animation Frame script
	 * durations): ≥ 250 ms warning, ≥ 50 ms notice.
	 *
	 * @param array[] $runs Runs.
	 * @return array[]
	 */
	private static function check_main_thread( array $runs ) {
		$groups = array();
		foreach ( $runs as $ri => $run ) {
			foreach ( self::loaf_by_component( $run ) as $ckey => $entry ) {
				if ( ! isset( $groups[ $ckey ] ) ) {
					$groups[ $ckey ] = array(
						'component' => $entry['component'],
						'runs'      => array(),
						'worst'     => 0.0,
					);
				}
				$groups[ $ckey ]['runs'][ $ri ] = $entry;
				$groups[ $ckey ]['worst']       = max( $groups[ $ckey ]['worst'], $entry['total'] );
			}
		}

		$findings = array();
		foreach ( $groups as $ckey => $group ) {
			if ( $group['worst'] >= (float) self::t( 'MAIN_THREAD_WARN_MS' ) ) {
				$severity = 'warning';
			} elseif ( $group['worst'] >= (float) self::t( 'MAIN_THREAD_NOTICE_MS' ) ) {
				$severity = 'notice';
			} else {
				continue;
			}
			$findings[] = self::main_thread_finding( $runs, $ckey, $group, $severity );
		}
		return $findings;
	}

	/**
	 * One main-thread finding for a component.
	 *
	 * @param array[] $runs     All runs.
	 * @param string  $ckey     Component key.
	 * @param array   $group    { component, runs: run index => entry, worst }.
	 * @param string  $severity warning|notice.
	 * @return array
	 */
	private static function main_thread_finding( array $runs, $ckey, array $group, $severity ) {
		$component = $group['component'];
		$name      = self::comp_name( $component );
		$ris       = array_keys( $group['runs'] );
		$worst     = (float) $group['worst'];
		$forced    = 0.0;
		$rows      = array();
		$handles   = array();

		foreach ( $group['runs'] as $ri => $entry ) {
			$run     = $runs[ $ri ];
			$scripts = $entry['scripts'];
			usort(
				$scripts,
				static function ( $a, $b ) {
					return $b['duration'] <=> $a['duration'];
				}
			);
			if ( $entry['total'] >= $worst ) {
				$forced = max( $forced, $entry['forced'] );
			}
			foreach ( array_slice( $scripts, 0, self::MAX_ROWS_PER_RUN ) as $script ) {
				$handle = '' !== $script['url'] ? self::handle_for( $run, $script['url'] ) : '';
				if ( '' !== $handle ) {
					$handles[ $handle ] = true;
				}
				$rows[] = array(
					$run['ref'],
					self::device( $run['vp'] ),
					$script['label'],
					$script['trigger'],
					self::fmt_ms( $script['duration'] ),
					$script['forced'] > 0 ? self::fmt_ms( $script['forced'] ) : '–',
				);
			}
		}

		$title = sprintf(
			/* translators: 1: plugin, theme or service name, 2: milliseconds, 3: page names. */
			__( '%1$s: %2$s of JavaScript on the main thread while the page loads (%3$s)', 'blt-optimized' ),
			$name,
			self::fmt_ms( $worst ),
			self::pages_phrase( $runs, $ris )
		);

		$summary = sprintf(
			/* translators: %s: milliseconds. */
			__( 'The browser\'s Long Animation Frames report attributes at least %s of script execution to this. While scripts run, the page cannot paint or react to taps, so this time is felt as sluggishness (only frames longer than 50 ms are reported, so the real total can be higher).', 'blt-optimized' ),
			self::fmt_ms( $worst )
		);
		if ( $forced >= 20 ) {
			/* translators: %s: milliseconds. */
			$summary .= ' ' . sprintf( __( 'Of that, %s forced the browser to recalculate layout (scripts reading sizes right after changing the page).', 'blt-optimized' ), self::fmt_ms( $forced ) );
		}
		$summary .= ' ' . self::note();

		$steps = array();
		$type  = isset( $component['type'] ) ? $component['type'] : 'other';
		if ( 'external' === $type ) {
			$steps[] = __( 'Load this script later: after the page is interactive or on first user interaction (a tag manager can fire it on "Window Loaded" or on scroll/click instead of "Page View"). Remove tags you no longer use.', 'blt-optimized' );
			$steps[] = __( 'Replace it with a lighter alternative or a facade (for example a thumbnail that loads a video or chat widget on click).', 'blt-optimized' );
		} elseif ( 'other' === $type && 'inline-scripts' === $component['slug'] ) {
			$steps[] = __( 'These scripts are printed inline in the page HTML. Search the page source for the function names or triggers in the table to find the plugin, theme or builder element that prints them.', 'blt-optimized' );
			$steps[] = __( 'Remove or defer the feature that runs them (sliders, counters, popups, tracking snippets) on pages that do not need it.', 'blt-optimized' );
		} elseif ( 'core' === $type ) {
			$steps[] = __( 'This is WordPress\'s own JavaScript (jQuery and the block and interactivity scripts) and cannot be switched off safely. Its cost grows with the number of scripts that depend on it.', 'blt-optimized' );
			$steps[] = __( 'Reduce what depends on jQuery: remove plugins and theme features you do not use, and work through the other main-thread findings, which name the plugins adding the most.', 'blt-optimized' );
		} else {
			$steps[] = $handles
				/* translators: %s: list of script handles. */
				? sprintf( __( 'Stop loading it where it is not needed: Speed Tweaks → Asset rules → add a rule for the script handle(s) %s limited to the pages that use the feature.', 'blt-optimized' ), implode( ', ', array_keys( $handles ) ) )
				: __( 'Stop loading it where it is not needed: Speed Tweaks → Asset rules → add a rule for its script handle (the file name in the table shows which one it is).', 'blt-optimized' );
			/* translators: %s: plugin or theme name. */
			$steps[] = sprintf( __( 'Check %s\'s settings for features that run on every page (animations, live counters, popups, sliders) and switch off the ones you do not use; update it to its latest version.', 'blt-optimized' ), $name );
		}

		return self::finding(
			array(
				'id'        => 'lab.main_thread',
				'key'       => 'lab.main_thread:' . $ckey,
				'category'  => 'assets',
				'severity'  => $severity,
				'title'     => $title,
				'summary'   => $summary,
				'impact'    => self::impact_ms( $worst ),
				'component' => $component,
				'where'     => self::urls_of( $runs, $ris ),
				'details'   => array(
					'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Device', 'blt-optimized' ), __( 'Script', 'blt-optimized' ), __( 'Function / trigger', 'blt-optimized' ), __( 'Time', 'blt-optimized' ), __( 'Forced layout', 'blt-optimized' ) ),
					'rows'    => $rows,
				),
				'fix'       => self::fix_manual( __( 'How to fix', 'blt-optimized' ), self::steps( $steps ) ),
			)
		);
	}

	/**
	 * Total Blocking Time over the "good" threshold (the lab stand-in for INP).
	 *
	 * @param array[] $runs Runs.
	 * @return array[]
	 */
	private static function check_tbt( array $runs ) {
		$rows    = array();
		$ris     = array();
		$worst   = 0.0;
		$poor    = false;
		$by_comp = array();

		foreach ( $runs as $ri => $run ) {
			if ( ! in_array( $run['sum']['ratings']['tbt'], array( 'needs-improvement', 'poor' ), true ) ) {
				continue;
			}
			$tbt     = self::g( $run['lab'], 'tbt' );
			$ris[]   = $ri;
			$worst   = max( $worst, (float) $run['sum']['tbt'] );
			$poor    = $poor || 'poor' === $run['sum']['ratings']['tbt'];
			$longest = 0.0;
			foreach ( (array) self::g( $tbt, 'longTasks', array() ) as $task ) {
				$longest = max( $longest, self::n( $task, 'duration', 0 ) );
			}

			$top = self::loaf_by_component( $run );
			uasort(
				$top,
				static function ( $a, $b ) {
					return $b['total'] <=> $a['total'];
				}
			);
			$names = array();
			foreach ( array_slice( $top, 0, 2, true ) as $ckey => $entry ) {
				$names[] = self::comp_name( $entry['component'] ) . ' (' . self::fmt_ms( $entry['total'] ) . ')';
			}
			foreach ( $top as $ckey => $entry ) {
				if ( ! isset( $by_comp[ $ckey ] ) ) {
					$by_comp[ $ckey ] = array(
						'name'  => self::comp_name( $entry['component'] ),
						'total' => 0.0,
					);
				}
				$by_comp[ $ckey ]['total'] += $entry['total'];
			}

			$rows[] = array(
				$run['ref'],
				self::device( $run['vp'] ),
				self::fmt_ms( $run['sum']['tbt'] ),
				number_format_i18n( self::i( $tbt, 'count', 0 ) ),
				$longest > 0 ? self::fmt_ms( $longest ) : '–',
				$names ? implode( ', ', $names ) : '–',
			);
		}
		if ( ! $ris ) {
			return array();
		}

		uasort(
			$by_comp,
			static function ( $a, $b ) {
				return $b['total'] <=> $a['total'];
			}
		);
		$top_names = array();
		foreach ( array_slice( $by_comp, 0, 3 ) as $entry ) {
			$top_names[] = $entry['name'];
		}

		$pages = self::pages_phrase( $runs, $ris );
		$title = $top_names
			? sprintf(
				/* translators: 1: milliseconds, 2: page names, 3: " (mobile)", 4: list of plugin/service names. */
				__( 'Total Blocking Time is %1$s on %2$s%3$s: JavaScript from %4$s keeps the main thread busy', 'blt-optimized' ),
				self::fmt_ms( $worst ),
				$pages,
				self::devices_phrase( $runs, $ris ),
				wp_sprintf( '%l', $top_names )
			)
			: sprintf(
				/* translators: 1: milliseconds, 2: page names, 3: " (mobile)". */
				__( 'Total Blocking Time is %1$s on %2$s%3$s: long JavaScript tasks keep the main thread busy', 'blt-optimized' ),
				self::fmt_ms( $worst ),
				$pages,
				self::devices_phrase( $runs, $ris )
			);

		$summary = sprintf(
			/* translators: 1: milliseconds, 2: rating word, 3: "good" threshold. */
			__( 'Total Blocking Time adds up every main-thread task longer than 50 ms after the first paint: %1$s here (%2$s; under %3$s is good). It is the lab stand-in for how sluggish the page feels to a tap while it loads; real interaction delay (INP) needs real clicks and is not measured here.', 'blt-optimized' ),
			self::fmt_ms( $worst ),
			self::rating_word( $poor ? 'poor' : 'needs-improvement' ),
			self::fmt_ms( self::t( 'TBT_GOOD' ) )
		);
		$summary .= ' ' . self::note();

		return array(
			self::finding(
				array(
					'id'       => 'lab.tbt',
					'key'      => 'lab.tbt',
					'category' => 'assets',
					'severity' => $poor ? 'critical' : 'warning',
					'title'    => $title,
					'summary'  => $summary,
					'impact'   => self::impact_ms( $worst ),
					'where'    => self::urls_of( $runs, $ris ),
					'details'  => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Device', 'blt-optimized' ), __( 'Blocking time', 'blt-optimized' ), __( 'Long tasks', 'blt-optimized' ), __( 'Longest task', 'blt-optimized' ), __( 'Biggest contributors', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => self::fix_manual(
						__( 'How to fix', 'blt-optimized' ),
						self::steps(
							array(
								__( 'Work through the "runs … of JavaScript on the main thread" findings for the plugins and services named here; they list the exact scripts and functions.', 'blt-optimized' ),
								__( 'Delay non-essential scripts (chat widgets, analytics, A/B tools, social buttons) until the visitor interacts or the page is idle.', 'blt-optimized' ),
								__( 'Remove plugins and tags the site no longer needs, and avoid sliders, animated counters and heavy page-builder effects above the fold.', 'blt-optimized' ),
							)
						)
					),
				)
			),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Assets: fonts and third parties                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Web fonts: more than 5 files or more than 200 KB from one component.
	 *
	 * @param array[] $runs Runs.
	 * @return array[]
	 */
	private static function check_fonts( array $runs ) {
		$groups = array();
		foreach ( $runs as $ri => $run ) {
			foreach ( (array) self::g( $run['lab'], 'resources', array() ) as $resource ) {
				if ( ! is_array( $resource ) || '' === self::s( $resource, 'url' ) || 'font' !== self::s( $resource, 'kind', self::kind_of( $resource ) ) ) {
					continue;
				}
				$component = self::comp( self::g( $resource, 'component' ), self::s( $resource, 'url' ) );
				$ckey      = BLT_Optimized_Components::key( $component );
				if ( ! isset( $groups[ $ckey ] ) ) {
					$groups[ $ckey ] = array(
						'component' => $component,
						'runs'      => array(),
					);
				}
				if ( ! isset( $groups[ $ckey ]['runs'][ $ri ] ) ) {
					$groups[ $ckey ]['runs'][ $ri ] = array(
						'files' => array(),
						'bytes' => 0,
					);
				}
				if ( ! isset( $groups[ $ckey ]['runs'][ $ri ]['files'][ self::s( $resource, 'url' ) ] ) ) {
					$groups[ $ckey ]['runs'][ $ri ]['files'][ self::s( $resource, 'url' ) ] = self::resource_size( $resource );
					$groups[ $ckey ]['runs'][ $ri ]['bytes']                               += self::resource_size( $resource );
				}
			}
		}

		$findings = array();
		foreach ( $groups as $ckey => $group ) {
			$files = 0;
			$bytes = 0;
			foreach ( $group['runs'] as $entry ) {
				$files = max( $files, count( $entry['files'] ) );
				$bytes = max( $bytes, $entry['bytes'] );
			}
			if ( $files <= (int) self::t( 'FONT_FILES' ) && $bytes <= (int) self::t( 'FONT_BYTES' ) ) {
				continue;
			}
			$findings[] = self::fonts_finding( $runs, $ckey, $group, $files, $bytes );
		}
		return $findings;
	}

	/**
	 * One heavy-fonts finding for a component.
	 *
	 * @param array[] $runs  All runs.
	 * @param string  $ckey  Component key.
	 * @param array   $group { component, runs: run index => { files, bytes } }.
	 * @param int     $files Most font files in one run.
	 * @param int     $bytes Most font bytes in one run.
	 * @return array
	 */
	private static function fonts_finding( array $runs, $ckey, array $group, $files, $bytes ) {
		$component = $group['component'];
		$name      = self::comp_name( $component );
		$ris       = array_keys( $group['runs'] );

		$rows = array();
		foreach ( $group['runs'] as $ri => $entry ) {
			$run       = $runs[ $ri ];
			$files_run = $entry['files'];
			arsort( $files_run );
			foreach ( array_slice( $files_run, 0, self::MAX_ROWS_PER_RUN, true ) as $url => $size ) {
				$rows[] = array(
					$run['ref'],
					self::device( $run['vp'] ),
					self::file_name( $url ),
					$size > 0 ? self::fmt_bytes( $size ) : '–',
				);
			}
		}

		// Families and whether any lacks a font-display strategy.
		$families = array();
		$display  = false;
		foreach ( $ris as $ri ) {
			foreach ( (array) self::g( $runs[ $ri ]['lab'], 'fonts', array() ) as $font ) {
				if ( ! is_array( $font ) || '' === self::s( $font, 'family' ) ) {
					continue;
				}
				$families[ self::s( $font, 'family' ) ] = true;
				if ( in_array( self::s( $font, 'display', '' ), array( '', 'auto', 'block' ), true ) ) {
					$display = true;
				}
			}
		}

		$title = sprintf(
			/* translators: 1: plugin, theme or service name, 2: number of font files, 3: size, 4: page names. */
			_n( '%1$s loads %2$s web font file (%3$s) on %4$s', '%1$s loads %2$s web font files (%3$s) on %4$s', $files, 'blt-optimized' ),
			$name,
			number_format_i18n( $files ),
			self::fmt_bytes( $bytes ),
			self::pages_phrase( $runs, $ris )
		);

		$summary = sprintf(
			/* translators: 1: number of files, 2: size. */
			__( 'The browser downloaded %1$s font files totalling %2$s. Every weight and style of a font family is a separate file, and text can stay invisible or jump when the font arrives.', 'blt-optimized' ),
			number_format_i18n( $files ),
			self::fmt_bytes( $bytes )
		);
		if ( $families ) {
			/* translators: %s: list of font family names. */
			$summary .= ' ' . sprintf( __( 'Families in use: %s.', 'blt-optimized' ), wp_sprintf( '%l', array_slice( array_keys( $families ), 0, 6 ) ) );
		}
		if ( $display ) {
			$summary .= ' ' . __( 'At least one font has no font-display: swap, so its text is invisible until it loads.', 'blt-optimized' );
		}

		$steps = array(
			__( 'Load only the weights and styles the design actually uses (usually regular and bold); each extra weight is another file.', 'blt-optimized' ),
			__( 'Serve WOFF2 only and limit the character set (for example latin) instead of every language subset.', 'blt-optimized' ),
			__( 'Add font-display: swap and preload the one or two fonts used above the fold.', 'blt-optimized' ),
		);
		if ( 'external' === $component['type'] ) {
			$steps[] = __( 'Consider hosting the fonts on your own domain: it saves a connection to another server and lets them be cached with your other assets.', 'blt-optimized' );
		}
		if ( 'plugin' === $component['type'] || 'theme' === $component['type'] ) {
			/* translators: %s: plugin or theme name. */
			$steps[] = sprintf( __( 'Check %s\'s settings (or the theme\'s theme.json) for font options and remove font families, weights and styles the design does not use.', 'blt-optimized' ), $name );
		}
		foreach ( array_keys( $families ) as $family ) {
			if ( preg_match( '/icon|awesome|dashicons|glyph|symbol/i', (string) $family ) ) {
				$steps[] = __( 'An icon font is loaded: replace it with a few inline SVGs, or a subset containing only the icons in use.', 'blt-optimized' );
				break;
			}
		}

		return self::finding(
			array(
				'id'        => 'lab.fonts',
				'key'       => 'lab.fonts:' . $ckey,
				'category'  => 'assets',
				'severity'  => ( $bytes > (int) self::t( 'FONT_WARN_BYTES' ) || $files >= (int) self::t( 'FONT_WARN_FILES' ) ) ? 'warning' : 'notice',
				'title'     => $title,
				'summary'   => $summary,
				'impact'    => self::impact_bytes( $bytes ),
				'component' => $component,
				'where'     => self::urls_of( $runs, $ris ),
				'details'   => array(
					'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Device', 'blt-optimized' ), __( 'Font file', 'blt-optimized' ), __( 'Size', 'blt-optimized' ) ),
					'rows'    => $rows,
				),
				'fix'       => self::fix_manual( __( 'How to fix', 'blt-optimized' ), self::steps( $steps ) ),
			)
		);
	}

	/**
	 * Third-party hosts that account for a lot of downloaded bytes.
	 *
	 * @param array[] $runs Runs.
	 * @return array[]
	 */
	private static function check_third_party( array $runs ) {
		$hosts = array();
		foreach ( $runs as $ri => $run ) {
			foreach ( (array) self::g( $run['lab'], 'resources', array() ) as $resource ) {
				if ( ! is_array( $resource ) || '' === self::s( $resource, 'url' ) ) {
					continue;
				}
				$component = self::comp( self::g( $resource, 'component' ), self::s( $resource, 'url' ) );
				// Font files have their own finding (lab.fonts).
				if ( 'external' !== $component['type'] || 'font' === self::s( $resource, 'kind', self::kind_of( $resource ) ) ) {
					continue;
				}
				$host = (string) $component['slug'];
				if ( ! isset( $hosts[ $host ] ) ) {
					$hosts[ $host ] = array(
						'component' => $component,
						'runs'      => array(),
						'worst'     => 0,
					);
				}
				if ( ! isset( $hosts[ $host ]['runs'][ $ri ] ) ) {
					$hosts[ $host ]['runs'][ $ri ] = array(
						'bytes'    => 0,
						'requests' => 0,
						'blocking' => 0,
					);
				}
				$entry                   = &$hosts[ $host ]['runs'][ $ri ];
				$entry['bytes']         += self::resource_size( $resource );
				$entry['requests']      += 1;
				$entry['blocking']      += ! empty( $resource['blocking'] ) ? 1 : 0;
				$hosts[ $host ]['worst'] = max( $hosts[ $host ]['worst'], $entry['bytes'] );
				unset( $entry );
			}
		}

		$min = (int) self::t( 'THIRD_PARTY_BYTES' );
		foreach ( $hosts as $host => $group ) {
			if ( $group['worst'] < $min ) {
				unset( $hosts[ $host ] );
			}
		}
		if ( ! $hosts ) {
			return array();
		}
		uasort(
			$hosts,
			static function ( $a, $b ) {
				return $b['worst'] <=> $a['worst'];
			}
		);

		$findings = array();
		foreach ( array_slice( $hosts, 0, (int) self::t( 'MAX_THIRD_PARTY_FINDINGS' ), true ) as $host => $group ) {
			$findings[] = self::third_party_finding( $runs, $host, $group );
		}
		return $findings;
	}

	/**
	 * One third-party finding.
	 *
	 * @param array[] $runs  All runs.
	 * @param string  $host  Host name.
	 * @param array   $group { component, runs: run index => { bytes, requests, blocking }, worst }.
	 * @return array
	 */
	private static function third_party_finding( array $runs, $host, array $group ) {
		$component = $group['component'];
		$name      = self::comp_name( $component );
		$ris       = array_keys( $group['runs'] );
		$worst     = (int) $group['worst'];
		$requests  = 0;
		$rows      = array();
		foreach ( $group['runs'] as $ri => $entry ) {
			$run      = $runs[ $ri ];
			$requests = max( $requests, $entry['requests'] );
			$rows[]   = array(
				$run['ref'],
				self::device( $run['vp'] ),
				self::fmt_bytes( $entry['bytes'] ),
				number_format_i18n( $entry['requests'] ),
				number_format_i18n( $entry['blocking'] ),
			);
		}
		$label = $name === $host ? $host : $name . ' (' . $host . ')';

		$title = sprintf(
			/* translators: 1: service or host name, 2: size, 3: number of requests, 4: page names. */
			_n( '%1$s downloads %2$s in %3$s request on %4$s', '%1$s downloads %2$s in %3$s requests on %4$s', $requests, 'blt-optimized' ),
			$label,
			self::fmt_bytes( $worst ),
			number_format_i18n( $requests ),
			self::pages_phrase( $runs, $ris )
		);

		$summary = sprintf(
			/* translators: 1: host name, 2: size. */
			__( 'Files from %1$s add %2$s to the page. Third-party files are outside your control, are fetched over extra connections and often run JavaScript on the main thread. Browsers only report sizes for hosts that send a Timing-Allow-Origin header, so the real weight can be higher.', 'blt-optimized' ),
			$host,
			self::fmt_bytes( $worst )
		);

		$steps = array(
			__( 'Check that you still need this service on every page; remove tags and plugins that are no longer used.', 'blt-optimized' ),
			__( 'Load it only on the pages that use it, and only after the visitor interacts (a thumbnail that loads a video, map or chat widget on click saves most of the weight).', 'blt-optimized' ),
			__( 'Where possible, host the file yourself or use a lighter alternative.', 'blt-optimized' ),
		);

		return self::finding(
			array(
				'id'        => 'lab.third_party',
				'key'       => 'lab.third_party:' . $host,
				'category'  => 'assets',
				'severity'  => $worst >= (int) self::t( 'THIRD_PARTY_WARN_BYTES' ) ? 'warning' : 'notice',
				'title'     => $title,
				'summary'   => $summary,
				'impact'    => self::impact_bytes( $worst ),
				'component' => $component,
				'where'     => self::urls_of( $runs, $ris ),
				'details'   => array(
					'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Device', 'blt-optimized' ), __( 'Downloaded', 'blt-optimized' ), __( 'Requests', 'blt-optimized' ), __( 'Render-blocking', 'blt-optimized' ) ),
					'rows'    => $rows,
				),
				'fix'       => self::fix_manual( __( 'How to fix', 'blt-optimized' ), self::steps( $steps ) ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Server                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Time to first byte rated "poor" in the browser. The profiler-based server
	 * findings can describe the same slowness from the PHP side; this one is
	 * what the browser saw (page caching is bypassed for lab runs).
	 *
	 * @param array[] $runs Runs.
	 * @return array[]
	 */
	private static function check_ttfb( array $runs ) {
		$rows  = array();
		$ris   = array();
		$worst = 0.0;
		foreach ( $runs as $ri => $run ) {
			if ( 'poor' !== $run['sum']['ratings']['ttfb'] ) {
				continue;
			}
			$ris[]  = $ri;
			$worst  = max( $worst, (float) $run['sum']['ttfb'] );
			$php    = self::g( self::g( self::g( $run['page'], 'profile' ), 'timing' ), 'total_ms' );
			$rows[] = array(
				$run['ref'],
				self::device( $run['vp'] ),
				self::fmt_ms( $run['sum']['ttfb'] ),
				is_numeric( $php ) ? self::fmt_ms( $php ) : '–',
			);
		}
		if ( ! $ris ) {
			return array();
		}

		$title = sprintf(
			/* translators: 1: milliseconds, 2: page names, 3: " (mobile)". */
			__( 'The server takes %1$s to start answering on %2$s%3$s (browser-measured time to first byte)', 'blt-optimized' ),
			self::fmt_ms( $worst ),
			self::pages_phrase( $runs, $ris ),
			self::devices_phrase( $runs, $ris )
		);

		$summary = sprintf(
			/* translators: 1: milliseconds, 2: "poor" threshold. */
			__( 'The browser waited %1$s for the first byte of the page (slower than %2$s is rated poor). Everything else waits for it. Lab runs bypass page caching, so this is the uncached page-generation time; a page cache would serve visitors much faster, but logged-out cache misses and first visits pay this cost.', 'blt-optimized' ),
			self::fmt_ms( $worst ),
			self::fmt_ms( self::t( 'TTFB_POOR' ) )
		);
		$summary .= ' ' . self::note();

		return array(
			self::finding(
				array(
					'id'       => 'lab.ttfb',
					'key'      => 'lab.ttfb',
					'category' => 'server',
					'severity' => $worst >= (float) self::t( 'TTFB_CRIT_MS' ) ? 'critical' : 'warning',
					'title'    => $title,
					'summary'  => $summary,
					'impact'   => self::impact_ms( $worst ),
					'where'    => self::urls_of( $runs, $ris ),
					'details'  => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Device', 'blt-optimized' ), __( 'Time to first byte', 'blt-optimized' ), __( 'PHP generation (profiler)', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => self::fix_manual(
						__( 'How to fix', 'blt-optimized' ),
						self::steps(
							array(
								__( 'Enable full-page caching (a caching plugin or your host\'s built-in cache) so most visitors never wait for PHP.', 'blt-optimized' ),
								__( 'Look at the server findings of this scan (slow plugins, database queries, external HTTP calls) for what makes the uncached page slow.', 'blt-optimized' ),
								__( 'Ask your host about the PHP version, OPcache and a persistent object cache (Redis or Memcached).', 'blt-optimized' ),
							)
						)
					),
				)
			),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Pass and notices                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * One "healthy" finding when every Core Web Vitals lab metric is good on
	 * every measured page and viewport.
	 *
	 * @param array[] $runs Runs.
	 * @return array[]
	 */
	private static function check_pass( array $runs ) {
		$seen_lcp = false;
		$seen_cls = false;
		$rows     = array();
		$pages    = array();
		$worst    = array(
			'lcp' => 0.0,
			'cls' => 0.0,
			'tbt' => 0.0,
		);

		foreach ( $runs as $run ) {
			$ratings = $run['sum']['ratings'];
			foreach ( array( 'lcp', 'cls', 'tbt' ) as $metric ) {
				if ( null !== $ratings[ $metric ] && 'good' !== $ratings[ $metric ] ) {
					return array();
				}
				if ( null !== $run['sum'][ $metric ] ) {
					$worst[ $metric ] = max( $worst[ $metric ], (float) $run['sum'][ $metric ] );
				}
			}
			$seen_lcp           = $seen_lcp || null !== $ratings['lcp'];
			$seen_cls           = $seen_cls || null !== $ratings['cls'];
			$pages[ $run['p'] ] = true;
			$rows[]             = array(
				$run['ref'],
				self::device( $run['vp'] ),
				null !== $run['sum']['lcp'] ? self::fmt_ms( $run['sum']['lcp'] ) : '–',
				null !== $run['sum']['cls'] ? self::fmt_cls( $run['sum']['cls'] ) : '–',
				null !== $run['sum']['tbt'] ? self::fmt_ms( $run['sum']['tbt'] ) : '–',
				null !== $run['sum']['fcp'] ? self::fmt_ms( $run['sum']['fcp'] ) : '–',
				null !== $run['sum']['ttfb'] ? self::fmt_ms( $run['sum']['ttfb'] ) : '–',
			);
		}
		if ( ! $seen_lcp || ! $seen_cls ) {
			return array();
		}

		$title = sprintf(
			/* translators: 1: number of pages, 2: LCP, 3: CLS, 4: TBT. */
			_n( 'Core Web Vitals pass the lab test on %1$s page (worst LCP %2$s, CLS %3$s, blocking time %4$s)', 'Core Web Vitals pass the lab test on %1$s pages (worst LCP %2$s, CLS %3$s, blocking time %4$s)', count( $pages ), 'blt-optimized' ),
			number_format_i18n( count( $pages ) ),
			self::fmt_ms( $worst['lcp'] ),
			self::fmt_cls( $worst['cls'] ),
			self::fmt_ms( $worst['tbt'] )
		);

		return array(
			self::finding(
				array(
					'id'       => 'lab.pass',
					'key'      => 'lab.pass',
					'category' => 'content',
					'severity' => 'pass',
					'title'    => $title,
					'summary'  => __( 'Largest Contentful Paint, Cumulative Layout Shift and Total Blocking Time (the lab stand-in for interaction delay) are all in the good range on every measured page and device. INP needs real interactions and is not measured here. ', 'blt-optimized' ) . self::note(),
					'where'    => array(),
					'details'  => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Device', 'blt-optimized' ), __( 'LCP', 'blt-optimized' ), __( 'CLS', 'blt-optimized' ), __( 'Blocking time', 'blt-optimized' ), __( 'First paint', 'blt-optimized' ), __( 'First byte', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
				)
			),
		);
	}

	/**
	 * Pages the lab test could not measure at all.
	 *
	 * @param array[] $blocked Rows { ref, url, vp, warnings }.
	 * @return array[]
	 */
	private static function check_unavailable( array $blocked ) {
		if ( ! $blocked ) {
			return array();
		}
		$rows  = array();
		$urls  = array();
		$pages = array();
		foreach ( $blocked as $row ) {
			$pages[ $row['ref'] ] = true;
			if ( '' !== $row['url'] ) {
				$urls[] = $row['url'];
			}
			$rows[] = array(
				$row['ref'],
				self::device( $row['vp'] ),
				in_array( 'framed_blocked', $row['warnings'], true )
					? __( 'The page refused to load inside the test frame', 'blt-optimized' )
					: __( 'The measuring script did not run on the page', 'blt-optimized' ),
			);
		}
		$count = count( $pages );

		return array(
			self::finding(
				array(
					'id'       => 'lab.unavailable',
					'key'      => 'lab.unavailable',
					'category' => 'server',
					'severity' => 'notice',
					'title'    => sprintf(
						/* translators: %s: number of pages. */
						_n( 'The lab test could not measure %s page in the browser', 'The lab test could not measure %s pages in the browser', $count, 'blt-optimized' ),
						number_format_i18n( $count )
					),
					'summary'  => __( 'The lab test loads each page in a frame on this screen. A page cannot be measured when a header forbids framing it (X-Frame-Options or Content-Security-Policy frame-ancestors) or when the measuring script does not run on it. PHP-set headers are relaxed for the test request automatically; headers sent by the web server, a CDN or a security service are not.', 'blt-optimized' ),
					'impact'   => array(
						'type'  => 'count',
						'value' => $count,
						'label' => sprintf(
							/* translators: %s: number of pages. */
							_n( '%s page', '%s pages', $count, 'blt-optimized' ),
							number_format_i18n( $count )
						),
					),
					'where'    => array_values( array_unique( $urls ) ),
					'details'  => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Device', 'blt-optimized' ), __( 'Reason', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => self::fix_manual(
						__( 'How to fix', 'blt-optimized' ),
						self::steps(
							array(
								__( 'Look at the response headers of the page (browser developer tools → Network → the page request). If you see X-Frame-Options: DENY or a Content-Security-Policy with frame-ancestors, find where it is set: the web server configuration, a CDN or firewall (Cloudflare, Sucuri) or a security plugin, and allow your own site ("SAMEORIGIN" or frame-ancestors \'self\').', 'blt-optimized' ),
								__( 'Or run the Page Profiler lab test in a pop-up window for these pages, which does not need framing.', 'blt-optimized' ),
								__( 'If the headers are fine, a JavaScript error very early in the page may stop the measuring script; check the browser console for errors.', 'blt-optimized' ),
							)
						)
					),
				)
			),
		);
	}

	/**
	 * Measurements taken under conditions that make them unreliable.
	 *
	 * @param array[] $unreliable Rows { ref, url, vp, warnings }.
	 * @return array[]
	 */
	private static function check_unreliable( array $unreliable ) {
		if ( ! $unreliable ) {
			return array();
		}
		$rows  = array();
		$urls  = array();
		$pages = array();
		foreach ( $unreliable as $row ) {
			$pages[ $row['ref'] ] = true;
			if ( '' !== $row['url'] ) {
				$urls[] = $row['url'];
			}
			$reasons = array();
			if ( in_array( 'hidden_tab', $row['warnings'], true ) ) {
				$reasons[] = __( 'the browser tab was in the background (browsers pause painting there)', 'blt-optimized' );
			}
			if ( in_array( 'timeout', $row['warnings'], true ) ) {
				$reasons[] = __( 'the page was still loading when the test stopped waiting', 'blt-optimized' );
			}
			$rows[] = array( $row['ref'], self::device( $row['vp'] ), ucfirst( implode( '; ', $reasons ) ) );
		}
		$count = count( $pages );

		return array(
			self::finding(
				array(
					'id'       => 'lab.unreliable',
					'key'      => 'lab.unreliable',
					'category' => 'server',
					'severity' => 'notice',
					'title'    => sprintf(
						/* translators: %s: number of pages. */
						_n( 'The lab numbers for %s page may be unreliable', 'The lab numbers for %s pages may be unreliable', $count, 'blt-optimized' ),
						number_format_i18n( $count )
					),
					'summary'  => __( 'These measurements were taken while the admin tab was hidden or before the page finished loading, so paint timings and layout shifts may be missing or too high. Keep the tab in the foreground during the test and run it again.', 'blt-optimized' ),
					'where'    => array_values( array_unique( $urls ) ),
					'details'  => array(
						'columns' => array( __( 'Page', 'blt-optimized' ), __( 'Device', 'blt-optimized' ), __( 'Why', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => self::fix_manual(
						__( 'How to fix', 'blt-optimized' ),
						self::steps(
							array(
								__( 'Run the lab test again and leave this browser tab visible until it finishes.', 'blt-optimized' ),
								__( 'If a page keeps timing out, check for scripts or requests that never finish loading (see the resources in the Page Profiler).', 'blt-optimized' ),
							)
						)
					),
				)
			),
		);
	}
}
