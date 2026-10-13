<?php
/**
 * Front-end profiler collector — instruments a profiled request.
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;

/**
 * The instrumentation half of BLT_Optimized_Profiler, loaded by
 * BLT_Optimized_Profiler::maybe_start() only for a request that carries a
 * profile token. It extends the gate so the gate's state (active, token
 * options) and constants are shared; everything else here is private to the
 * profiled request. See BLT_Optimized_Profiler for the design notes and the
 * spec's "Module A" for the result schema.
 */
class BLT_Optimized_Profiler_Collector extends BLT_Optimized_Profiler {

	/**
	 * Result caps.
	 */
	const SQL_MAX         = 2000;
	const TOP_N           = 25;
	const MAX_ERRORS      = 50;
	const MAX_TRANSIENTS  = 100;
	const MAX_HTTP        = 50;
	const MAX_ASSETS      = 200;
	const MAX_LOAD_EVENTS = 200;
	const MAX_GROUPS      = 20000;

	/**
	 * Backtrace depth for live attribution, and how many early (deferred)
	 * traces are kept in memory until shutdown.
	 */
	const BACKTRACE_DEPTH = 30;
	const MAX_EARLY       = 40;

	/**
	 * Whether instrumentation is recording (off again once compiling starts).
	 *
	 * @var bool
	 */
	private static $collecting = false;

	/**
	 * Token for this request.
	 *
	 * @var string
	 */
	private static $token = '';

	/**
	 * The token transient's payload (re-issued when the request redirects).
	 *
	 * @var array
	 */
	private static $token_data = array();

	/**
	 * Request start (REQUEST_TIME_FLOAT).
	 *
	 * @var float
	 */
	private static $start = 0.0;

	/**
	 * Named timestamps (microtime).
	 *
	 * @var array<string,float>
	 */
	private static $marks = array();

	/**
	 * Whether SAVEQUERIES was defined by this profiler.
	 *
	 * @var bool
	 */
	private static $savequeries_forced = false;

	/**
	 * Whether queries are being logged by wpdb (SAVEQUERIES on).
	 *
	 * @var bool
	 */
	private static $tracked = false;

	/**
	 * Plugin load events: array( plugin file, seconds, bytes ).
	 *
	 * @var array[]
	 */
	private static $load = array();

	/**
	 * Previous plugin_loaded timestamp.
	 *
	 * @var float
	 */
	private static $last_t = 0.0;

	/**
	 * Memory in use at the previous plugin_loaded event.
	 *
	 * @var int
	 */
	private static $last_mem = 0;

	/**
	 * When `setup_theme` finished (theme timing starts here).
	 *
	 * @var float
	 */
	private static $theme_t = 0.0;

	/**
	 * Memory in use when `setup_theme` finished.
	 *
	 * @var int
	 */
	private static $theme_mem = 0;

	/**
	 * Theme load event: array( stylesheet dir, seconds, bytes ) or null.
	 *
	 * @var array|null
	 */
	private static $theme = null;

	/**
	 * True once `after_setup_theme` is reached; from then on attribution is
	 * resolved immediately instead of at shutdown.
	 *
	 * @var bool
	 */
	private static $eager = false;

	/**
	 * Deferred-trace counter.
	 *
	 * @var int
	 */
	private static $early = 0;

	/**
	 * Component registry for this request: key => component.
	 *
	 * @var array<string,array>
	 */
	private static $components = array();

	/**
	 * Template file chosen by template_include.
	 *
	 * @var string
	 */
	private static $template = '';

	/**
	 * Context captured at template_redirect (null until then).
	 *
	 * @var array|null
	 */
	private static $context = null;

	/**
	 * HTTP API calls.
	 *
	 * @var array[]
	 */
	private static $http = array();

	/**
	 * Indexes into $http of calls still in flight.
	 *
	 * @var int[]
	 */
	private static $http_open = array();

	/**
	 * Distinct PHP errors, keyed by file:line:message.
	 *
	 * @var array[]
	 */
	private static $errors = array();

	/**
	 * Transient writes keyed by scope + name.
	 *
	 * @var array[]
	 */
	private static $transients = array();

	/**
	 * Error handler that was installed before ours.
	 *
	 * @var callable|null
	 */
	private static $prev_handler = null;

	/**
	 * Whether our error handler is installed.
	 *
	 * @var bool
	 */
	private static $handler_installed = false;

	/**
	 * Re-entrancy guard: true while the handler is recording an error.
	 *
	 * @var bool
	 */
	private static $in_handler = false;

	/**
	 * Fallback query counters, used only when SAVEQUERIES is defined false.
	 *
	 * @var array
	 */
	private static $fallback = array(
		'count'    => 0,
		'by_type'  => array(),
		'by_comp'  => array(),
		'groups'   => array(),
		'deferred' => array(),
	);

	/**
	 * Whether this request redirected to another local URL (the token is
	 * re-issued so the target page is profiled instead).
	 *
	 * @var bool
	 */
	private static $redirected = false;

	/**
	 * Validate the token, consume it, and instrument the request.
	 *
	 * @return void
	 */
	public static function start(): void {
		if ( self::$active ) {
			return;
		}

		// The token is validated against a strict pattern below; nothing else is read from the request here.
		$token = isset( $_GET[ self::QUERY_ARG ] ) ? wp_unslash( $_GET[ self::QUERY_ARG ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! is_string( $token ) || 1 !== preg_match( '/^[A-Za-z0-9]{32}$/', $token ) ) {
			return;
		}

		// Only genuine front-end page loads are profiled.
		if ( 'cli' === PHP_SAPI || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$data = get_transient( self::TOKEN_PREFIX . $token );
		if ( ! is_array( $data ) ) {
			return;
		}
		// Single use: whoever deletes the row wins, concurrent replays lose.
		if ( ! delete_transient( self::TOKEN_PREFIX . $token ) ) {
			return;
		}

		self::$active     = true;
		self::$collecting = true;
		self::$token      = $token;
		self::$token_data = $data;
		self::$opts       = isset( $data['opts'] ) && is_array( $data['opts'] ) ? $data['opts'] : array();

		$now                       = microtime( true );
		self::$start               = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : $now;
		self::$marks['bootstrap']  = $now;
		self::$last_t              = $now;
		self::$last_mem            = memory_get_usage();
		self::$fallback['by_type'] = self::empty_by_type();

		if ( ! defined( 'SAVEQUERIES' ) ) {
			define( 'SAVEQUERIES', true );
			self::$savequeries_forced = true;
		}
		self::$tracked = (bool) SAVEQUERIES;

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		if ( self::$tracked ) {
			add_filter( 'log_query_custom_data', array( __CLASS__, 'on_log_query' ), 10, 5 );
		} else {
			add_filter( 'query', array( __CLASS__, 'on_query' ), PHP_INT_MAX );
		}

		add_action( 'plugin_loaded', array( __CLASS__, 'on_plugin_loaded' ), PHP_INT_MIN, 1 );
		add_action( 'plugins_loaded', array( __CLASS__, 'on_plugins_loaded' ), PHP_INT_MAX );
		add_action( 'setup_theme', array( __CLASS__, 'on_setup_theme' ), PHP_INT_MAX );
		add_action( 'after_setup_theme', array( __CLASS__, 'on_after_setup_theme' ), PHP_INT_MIN );
		add_action( 'wp_loaded', array( __CLASS__, 'on_wp_loaded' ), PHP_INT_MAX );
		add_action( 'template_redirect', array( __CLASS__, 'on_template_redirect' ), PHP_INT_MAX );
		add_filter( 'template_include', array( __CLASS__, 'on_template_include' ), PHP_INT_MAX );
		add_action( 'send_headers', array( __CLASS__, 'on_send_headers' ) );
		add_filter( 'pre_http_request', array( __CLASS__, 'on_pre_http_request' ), PHP_INT_MAX, 3 );
		add_action( 'http_api_debug', array( __CLASS__, 'on_http_api_debug' ), PHP_INT_MIN, 5 );
		// WordPress 6.8 renamed the "after a transient is set" actions and
		// deprecated the old names — hooking a deprecated action logs a notice
		// on every request, so pick the right pair for this version.
		$wp_version = isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '';
		if ( '' !== $wp_version && version_compare( $wp_version, '6.8-alpha', '>=' ) ) {
			add_action( 'set_transient', array( __CLASS__, 'on_transient_set' ), 10, 3 );
			add_action( 'set_site_transient', array( __CLASS__, 'on_site_transient_set' ), 10, 3 );
		} else {
			add_action( 'setted_transient', array( __CLASS__, 'on_transient_set' ), 10, 3 );
			add_action( 'setted_site_transient', array( __CLASS__, 'on_site_transient_set' ), 10, 3 );
		}
		add_filter( 'wp_redirect', array( __CLASS__, 'on_redirect' ), PHP_INT_MAX, 2 );
		add_action( 'shutdown', array( __CLASS__, 'on_shutdown' ), PHP_INT_MAX );

		// Chain, never replace: remember whatever handler was there before.
		$previous                = set_error_handler( array( __CLASS__, 'handle_error' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- chained, restored on shutdown.
		self::$prev_handler      = $previous;
		self::$handler_installed = true;
	}

	/*
	 * ------------------------------------------------------------------
	 * Hook callbacks (public only because WordPress calls them)
	 * ------------------------------------------------------------------
	 */
	/* ------------------------------------------------------------------ */

	/**
	 * Record how long the plugin that just loaded took. The path is resolved
	 * to a component at shutdown, not here, so this stays a few instructions.
	 *
	 * @param string $plugin Full path to the plugin's main file.
	 * @return void
	 */
	public static function on_plugin_loaded( $plugin = '' ) {
		$now = microtime( true );
		$mem = memory_get_usage();
		if ( count( self::$load ) < self::MAX_LOAD_EVENTS ) {
			self::$load[] = array( (string) $plugin, $now - self::$last_t, max( 0, $mem - self::$last_mem ) );
		}
		self::$last_t   = $now;
		self::$last_mem = $mem;
	}

	/**
	 * Milestone: all plugins loaded.
	 *
	 * @return void
	 */
	public static function on_plugins_loaded() {
		self::$marks['plugins_loaded'] = microtime( true );
	}

	/**
	 * Start timing the theme (its functions.php files run between
	 * `setup_theme` and `after_setup_theme`).
	 *
	 * @return void
	 */
	public static function on_setup_theme() {
		self::$theme_t   = microtime( true );
		self::$theme_mem = memory_get_usage();
	}

	/**
	 * Stop timing the theme; from here on attribution is resolved eagerly.
	 *
	 * @return void
	 */
	public static function on_after_setup_theme() {
		$dt  = microtime( true ) - self::$theme_t;
		$mem = max( 0, memory_get_usage() - self::$theme_mem );
		if ( self::$theme_t > 0 ) {
			try {
				$dir         = function_exists( 'get_stylesheet_directory' ) ? (string) get_stylesheet_directory() : '';
				self::$theme = array( $dir, $dt, $mem );
			} catch ( \Throwable $e ) {
				self::$theme = null;
			}
		}
		self::$eager = true;
	}

	/**
	 * Milestone: WordPress fully loaded.
	 *
	 * @return void
	 */
	public static function on_wp_loaded() {
		self::$marks['wp_loaded'] = microtime( true );
	}

	/**
	 * Milestone: about to choose and render the template. The main query is
	 * final here, so this is where the page context is captured.
	 *
	 * @return void
	 */
	public static function on_template_redirect() {
		self::$marks['template_redirect'] = microtime( true );
		try {
			self::$context = self::build_context();
		} catch ( \Throwable $e ) {
			self::$context = null;
		}
	}

	/**
	 * Remember the template WordPress picked; the value is returned untouched.
	 *
	 * @param string $template Template file.
	 * @return string
	 */
	public static function on_template_include( $template ) {
		if ( is_string( $template ) ) {
			self::$template = $template;
		}
		return $template;
	}

	/**
	 * Mark the response as profiled and keep it out of page caches.
	 *
	 * @return void
	 */
	public static function on_send_headers() {
		if ( headers_sent() ) {
			return;
		}
		try {
			nocache_headers();
			header( 'X-BLT-Profiled: 1' );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Attach the originating component to a logged query. Runs once per query
	 * (SAVEQUERIES mode); aggregation happens at shutdown.
	 *
	 * @param array  $query_data      Custom query data.
	 * @param string $query           SQL.
	 * @param float  $query_time      Seconds.
	 * @param string $query_callstack Caller summary.
	 * @param float  $query_start     Start timestamp.
	 * @return array
	 */
	public static function on_log_query( $query_data, $query = '', $query_time = 0, $query_callstack = '', $query_start = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- fixed hook signature.
		if ( ! is_array( $query_data ) ) {
			$query_data = array();
		}
		if ( self::$collecting ) {
			try {
				$query_data['blt'] = self::attribute_now();
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		return $query_data;
	}

	/**
	 * Fallback when SAVEQUERIES is defined false: count queries as they run.
	 * No timings are available in this mode.
	 *
	 * @param string $query SQL.
	 * @return string The unchanged SQL.
	 */
	public static function on_query( $query ) {
		if ( ! self::$collecting || ! is_string( $query ) ) {
			return $query;
		}
		try {
			$fb = &self::$fallback;
			++$fb['count'];
			++$fb['by_type'][ self::query_type( $query ) ];

			$attr = self::attribute_now();
			$ckey = null;
			if ( isset( $attr['t'] ) ) {
				$fb['deferred'][] = $attr['t'];
			} else {
				$ckey                   = $attr['c'];
				$fb['by_comp'][ $ckey ] = isset( $fb['by_comp'][ $ckey ] ) ? $fb['by_comp'][ $ckey ] + 1 : 1;
			}

			$hash = self::group_key( $query );
			if ( isset( $fb['groups'][ $hash ] ) ) {
				++$fb['groups'][ $hash ][0];
				if ( null !== $ckey ) {
					$fb['groups'][ $hash ][2][ $ckey ] = true;
				}
			} elseif ( count( $fb['groups'] ) < self::MAX_GROUPS ) {
				$fb['groups'][ $hash ] = array(
					1,
					self::display_sql( $query ),
					null !== $ckey ? array( $ckey => true ) : array(),
					null !== $ckey ? $attr['f'] : '',
				);
			}
			unset( $fb );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return $query;
	}

	/**
	 * Start timing an outgoing HTTP request. The filter value is returned
	 * unchanged; requests short-circuited by another filter are not real
	 * network calls and are skipped.
	 *
	 * @param false|array|WP_Error $pre         Short-circuit value.
	 * @param array                $parsed_args Request args.
	 * @param string               $url         URL.
	 * @return false|array|WP_Error
	 */
	public static function on_pre_http_request( $pre, $parsed_args = array(), $url = '' ) {
		if ( false !== $pre || ! self::$collecting || count( self::$http ) >= self::MAX_HTTP ) {
			return $pre;
		}
		try {
			$index                = count( self::$http );
			self::$http[ $index ] = array(
				'url'    => self::clean_url( $url ),
				'method' => is_array( $parsed_args ) && isset( $parsed_args['method'] ) ? strtoupper( (string) $parsed_args['method'] ) : 'GET',
				'raw'    => (string) $url,
				'attr'   => self::attribute_now(),
				'ms'     => null,
				'status' => 0,
				'error'  => '',
				't0'     => microtime( true ),
			);
			self::$http_open[]    = $index;
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return $pre;
	}

	/**
	 * Stop the HTTP timer started in on_pre_http_request().
	 *
	 * @param array|WP_Error $response    Response or error.
	 * @param string         $context     Context ('response').
	 * @param string         $class_name  Transport class.
	 * @param array          $parsed_args Request args.
	 * @param string         $url         URL.
	 * @return void
	 */
	public static function on_http_api_debug( $response = null, $context = '', $class_name = '', $parsed_args = array(), $url = '' ) {
		$now = microtime( true );
		if ( ! self::$collecting || empty( self::$http_open ) ) {
			return;
		}
		try {
			// Match by URL, newest first; fall back to the newest open call.
			$slot = count( self::$http_open ) - 1;
			for ( $i = $slot; $i >= 0; $i-- ) {
				if ( self::$http[ self::$http_open[ $i ] ]['raw'] === (string) $url ) {
					$slot = $i;
					break;
				}
			}
			$index = self::$http_open[ $slot ];
			array_splice( self::$http_open, $slot, 1 );

			$call       = &self::$http[ $index ];
			$call['ms'] = ( $now - $call['t0'] ) * 1000;
			if ( is_wp_error( $response ) ) {
				$call['error'] = self::cut( $response->get_error_message(), 300 );
			} elseif ( is_array( $response ) ) {
				$call['status'] = (int) wp_remote_retrieve_response_code( $response );
			}
			unset( $call );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Record a transient write.
	 *
	 * @param string $transient  Name.
	 * @param mixed  $value      Value (unused).
	 * @param int    $expiration Seconds until expiry (0 = none).
	 * @return void
	 */
	public static function on_transient_set( $transient, $value = null, $expiration = 0 ) {
		self::record_transient( (string) $transient, (int) $expiration, 'blog' );
	}

	/**
	 * Record a site-transient write.
	 *
	 * @param string $transient  Name.
	 * @param mixed  $value      Value (unused).
	 * @param int    $expiration Seconds until expiry (0 = none).
	 * @return void
	 */
	public static function on_site_transient_set( $transient, $value = null, $expiration = 0 ) {
		self::record_transient( (string) $transient, (int) $expiration, 'site' );
	}

	/**
	 * Keep a profiled request on track when it redirects to another local URL
	 * (trailing-slash and canonical redirects are common): the token is
	 * re-issued and carried along so the page that actually renders gets
	 * profiled, not the redirect itself.
	 *
	 * @param string $location Redirect target.
	 * @param int    $status   HTTP status.
	 * @return string The (possibly token-carrying) location.
	 */
	public static function on_redirect( $location, $status = 302 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- fixed hook signature.
		if ( ! self::$collecting || ! is_string( $location ) || '' === $location ) {
			return $location;
		}
		try {
			$host = BLT_Optimized_Components::url_host( $location );
			if ( '' !== $host && ! BLT_Optimized_Components::is_local_host( $host ) ) {
				return $location;
			}
			set_transient( self::TOKEN_PREFIX . self::$token, self::$token_data, self::TOKEN_TTL );
			if ( false === strpos( $location, self::QUERY_ARG . '=' ) ) {
				$location = add_query_arg( self::QUERY_ARG, self::$token, $location );
			}
			self::$redirected = true;
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return $location;
	}

	/**
	 * PHP error handler: record, then chain to the previous handler. When
	 * there is none, return false so PHP's own handling continues untouched.
	 *
	 * @param int    $errno   Level.
	 * @param string $errstr  Message.
	 * @param string $errfile File.
	 * @param int    $errline Line.
	 * @return bool
	 */
	public static function handle_error( $errno, $errstr, $errfile = '', $errline = 0 ) {
		if ( self::$collecting && ! self::$in_handler && ( error_reporting() & $errno ) ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- read-only: honours @ and the site's error_reporting.
			self::$in_handler = true;
			try {
				self::record_error( (int) $errno, (string) $errstr, (string) $errfile, (int) $errline );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			self::$in_handler = false;
		}
		if ( null !== self::$prev_handler ) {
			return call_user_func( self::$prev_handler, $errno, $errstr, $errfile, $errline );
		}
		return false;
	}

	/**
	 * Final hook: compile and store the result. Profiling must never break a
	 * page, so everything is inside try/catch.
	 *
	 * @return void
	 */
	public static function on_shutdown() {
		if ( ! self::$active || ! self::$collecting ) {
			return;
		}
		self::$collecting        = false;
		self::$marks['shutdown'] = microtime( true );
		try {
			self::restore_error_handler();
			if ( self::$redirected ) {
				// The redirect target carries the (re-issued) token and will
				// store the result for the page that actually renders.
				return;
			}
			$result = self::compile();
			set_transient( self::RESULT_PREFIX . self::$token, $result, self::RESULT_TTL );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/*
	 * ------------------------------------------------------------------
	 * Recording helpers
	 * ------------------------------------------------------------------
	 */
	/* ------------------------------------------------------------------ */

	/**
	 * Attribute the current call stack. Returns the resolved attribution
	 * ( c => component key, f => caller ), or — before `after_setup_theme` —
	 * the raw trace ( t ) to be resolved at shutdown.
	 *
	 * @return array
	 */
	private static function attribute_now() {
		$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, self::BACKTRACE_DEPTH ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- attribution is the point of this class.

		if ( ! self::$eager ) {
			if ( self::$early < self::MAX_EARLY ) {
				++self::$early;
				return array( 't' => $trace );
			}
			// Over budget: resolve now (a possible early-translation notice on
			// non-English sites is preferable to unbounded memory).
		}

		$attr = BLT_Optimized_Components::from_backtrace( $trace );
		if ( 'core' === $attr['component']['type'] && count( $trace ) >= self::BACKTRACE_DEPTH ) {
			// A deep stack with only core frames in the window: look further.
			$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
			$attr  = BLT_Optimized_Components::from_backtrace( $trace );
		}
		return array(
			'c' => self::register( $attr['component'] ),
			'f' => self::cut( self::caller_for( $attr, $trace ), 300 ),
		);
	}

	/**
	 * Caller label for an attribution. Plugin and theme attributions keep the
	 * label from BLT_Optimized_Components (the function in their code that made
	 * the call). When every frame is core the label would otherwise name the
	 * hook machinery that happened to run our callback, so look past the
	 * plumbing files for the core function that actually did the work.
	 *
	 * @param array $attr  { component, caller } from BLT_Optimized_Components::from_backtrace().
	 * @param array $trace The trace it was computed from.
	 * @return string
	 */
	private static function caller_for( array $attr, array $trace ) {
		if ( 'core' !== $attr['component']['type'] ) {
			return (string) $attr['caller'];
		}
		static $plumbing = array(
			'class-wp-hook.php',
			'plugin.php',
			'class-wpdb.php',
			'class-wp-http.php',
			'http.php',
			'option.php',
			'class-blt-optimized-profiler.php',
			'class-blt-optimized-components.php',
		);
		foreach ( $trace as $i => $frame ) {
			if ( empty( $frame['file'] ) || in_array( basename( $frame['file'] ), $plumbing, true ) ) {
				continue;
			}
			$up    = isset( $trace[ $i + 1 ] ) ? $trace[ $i + 1 ] : $frame;
			$label = '';
			if ( ! empty( $up['function'] ) ) {
				$label = ( ! empty( $up['class'] ) ? $up['class'] . ( isset( $up['type'] ) ? $up['type'] : '->' ) : '' ) . $up['function'] . '()';
			}
			return trim( $label . ' ' . BLT_Optimized_Components::short_path( $frame['file'] ) . ':' . ( isset( $frame['line'] ) ? (int) $frame['line'] : 0 ) );
		}
		return (string) $attr['caller'];
	}

	/**
	 * Resolve an attribution to array( component, caller ).
	 *
	 * @param array|null $attr Result of attribute_now(), or null.
	 * @return array
	 */
	private static function resolve( $attr ) {
		if ( is_array( $attr ) && isset( $attr['c'] ) && isset( self::$components[ $attr['c'] ] ) ) {
			return array( self::$components[ $attr['c'] ], isset( $attr['f'] ) ? (string) $attr['f'] : '' );
		}
		if ( is_array( $attr ) && isset( $attr['t'] ) && is_array( $attr['t'] ) ) {
			$resolved = BLT_Optimized_Components::from_backtrace( $attr['t'] );
			self::register( $resolved['component'] );
			return array( $resolved['component'], self::cut( self::caller_for( $resolved, $attr['t'] ), 300 ) );
		}
		return array( self::core_component(), '' );
	}

	/**
	 * Remember a component and return its key.
	 *
	 * @param array $component Component.
	 * @return string
	 */
	private static function register( $component ) {
		$key = BLT_Optimized_Components::key( $component );
		if ( ! isset( self::$components[ $key ] ) ) {
			self::$components[ $key ] = $component;
		}
		return $key;
	}

	/**
	 * WordPress core component, registered.
	 *
	 * @return array
	 */
	private static function core_component() {
		$component = BLT_Optimized_Components::make( 'core', 'wordpress', __( 'WordPress core', 'blt-optimized' ) ); // phpcs:ignore WordPress.WP.CapitalPDangit -- component slug.
		self::register( $component );
		return $component;
	}

	/**
	 * Record a PHP error (deduplicated, capped).
	 *
	 * @param int    $errno   Level.
	 * @param string $errstr  Message.
	 * @param string $errfile File.
	 * @param int    $errline Line.
	 * @return void
	 */
	private static function record_error( $errno, $errstr, $errfile, $errline ) {
		$message = self::cut( $errstr, 500 );
		$key     = $errfile . ':' . $errline . ':' . $message;

		if ( isset( self::$errors[ $key ] ) ) {
			++self::$errors[ $key ]['count'];
			return;
		}
		if ( count( self::$errors ) >= self::MAX_ERRORS ) {
			return;
		}

		switch ( $errno ) {
			case E_WARNING:
			case E_USER_WARNING:
			case E_CORE_WARNING:
			case E_COMPILE_WARNING:
				$level = 'warning';
				break;
			case E_NOTICE:
			case E_USER_NOTICE:
				$level = 'notice';
				break;
			case E_DEPRECATED:
			case E_USER_DEPRECATED:
				$level = 'deprecated';
				break;
			case E_STRICT:
				$level = 'strict';
				break;
			default:
				$level = 'other';
		}

		$entry = array(
			'level'   => $level,
			'message' => $message,
			'file'    => $errfile,
			'line'    => $errline,
			'attr'    => null,
			'count'   => 1,
		);

		// WordPress raises deprecation / _doing_it_wrong notices from its own
		// files with trigger_error(); the culprit is whoever called it.
		if ( in_array( $errno, array( E_USER_DEPRECATED, E_USER_NOTICE, E_USER_WARNING ), true ) && 'core' === BLT_Optimized_Components::from_path( $errfile )['type'] ) {
			foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 25 ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
				if ( empty( $frame['file'] ) ) {
					continue;
				}
				$component = BLT_Optimized_Components::from_path( $frame['file'] );
				if ( 'core' !== $component['type'] && false === strpos( wp_normalize_path( $frame['file'] ), 'class-blt-optimized-profiler.php' ) ) {
					$entry['file'] = $frame['file'];
					$entry['line'] = isset( $frame['line'] ) ? (int) $frame['line'] : 0;
					break;
				}
			}
		}

		self::$errors[ $key ] = $entry;
	}

	/**
	 * Record a transient write.
	 *
	 * @param string $name       Transient name.
	 * @param int    $expiration Expiration in seconds.
	 * @param string $scope      'blog' or 'site'.
	 * @return void
	 */
	private static function record_transient( $name, $expiration, $scope ) {
		if ( ! self::$collecting || '' === $name || 0 === strpos( $name, 'blt_optimized_prof' ) ) {
			return;
		}
		try {
			$key = $scope . ':' . $name;
			if ( isset( self::$transients[ $key ] ) ) {
				++self::$transients[ $key ]['count'];
				return;
			}
			if ( count( self::$transients ) >= self::MAX_TRANSIENTS ) {
				return;
			}
			self::$transients[ $key ] = array(
				'name'       => self::cut( $name, 200 ),
				'expiration' => $expiration,
				'attr'       => self::attribute_now(),
				'count'      => 1,
			);
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Put PHP's error handler back the way we found it — but only if ours is
	 * still on top; if someone stacked another handler over it, leave theirs
	 * in place (ours is already inert: collecting is off).
	 *
	 * @return void
	 */
	private static function restore_error_handler() {
		if ( ! self::$handler_installed ) {
			return;
		}
		self::$handler_installed = false;

		$top = set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- probing the handler stack.
			static function () {
				return false;
			}
		);
		restore_error_handler(); // Pops the probe.
		if ( array( __CLASS__, 'handle_error' ) === $top ) {
			restore_error_handler(); // Pops ours.
		}
	}

	/*
	 * ------------------------------------------------------------------
	 * Compiling
	 * ------------------------------------------------------------------
	 */
	/* ------------------------------------------------------------------ */

	/**
	 * Compile everything collected into the result array.
	 *
	 * Each section is built independently so a failure in one (say, a
	 * malformed asset registration) cannot lose the others.
	 *
	 * @return array
	 */
	private static function compile() {
		return array(
			'version'              => 1,
			'request'              => array( 'uri' => self::request_uri() ),
			'timing'               => self::section( 'timing', array() ),
			'memory'               => self::section(
				'memory',
				array(
					'peak'  => 0,
					'limit' => 0,
				)
			),
			'env'                  => self::section( 'env', array() ),
			'context'              => self::section( 'context', array() ),
			'queries'              => self::section( 'queries', array() ),
			'components_load'      => self::section( 'components_load', array() ),
			'components_load_note' => self::section( 'components_load_note', '' ),
			'http'                 => self::section( 'http', array() ),
			'assets'               => self::section(
				'assets',
				array(
					'scripts' => array(),
					'styles'  => array(),
				)
			),
			'errors'               => self::section( 'errors', array() ),
			'cache'                => self::section(
				'cache',
				array(
					'persistent' => false,
					'hits'       => null,
					'misses'     => null,
				)
			),
			'transients_set'       => self::section( 'transients_set', array() ),
		);
	}

	/**
	 * Run one section builder, returning $default if it throws.
	 *
	 * @param string $name     Section.
	 * @param mixed  $fallback Value returned if the builder throws.
	 * @return mixed
	 */
	private static function section( $name, $fallback ) {
		try {
			return call_user_func( array( __CLASS__, 'compile_' . $name ) );
		} catch ( \Throwable $e ) {
			unset( $e );
			return $fallback;
		}
	}

	/**
	 * Request URI without our own query arguments.
	 *
	 * @return string
	 */
	private static function request_uri() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
		$uri = is_string( $uri ) ? $uri : '';
		try {
			$uri = remove_query_arg( array( self::QUERY_ARG, 'blt_nc' ), $uri );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		$uri = preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $uri );
		return self::cut( (string) $uri, 2000 );
	}

	/**
	 * Milliseconds since the request started at a mark (0.0 if never reached).
	 *
	 * @param string $mark Mark name.
	 * @return float
	 */
	private static function ms_at( $mark ) {
		return isset( self::$marks[ $mark ] ) ? round( ( self::$marks[ $mark ] - self::$start ) * 1000, 2 ) : 0.0;
	}

	/**
	 * Timing section.
	 *
	 * @return array
	 */
	private static function compile_timing() {
		$render = 0.0;
		if ( isset( self::$marks['template_redirect'], self::$marks['shutdown'] ) ) {
			$render = round( ( self::$marks['shutdown'] - self::$marks['template_redirect'] ) * 1000, 2 );
		}
		return array(
			'total_ms'             => self::ms_at( 'shutdown' ),
			'bootstrap_ms'         => self::ms_at( 'bootstrap' ),
			'plugins_loaded_ms'    => self::ms_at( 'plugins_loaded' ),
			'wp_loaded_ms'         => self::ms_at( 'wp_loaded' ),
			'template_redirect_ms' => self::ms_at( 'template_redirect' ),
			'render_ms'            => $render,
		);
	}

	/**
	 * Memory section.
	 *
	 * @return array
	 */
	private static function compile_memory() {
		$limit = (int) wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		return array(
			'peak'  => (int) memory_get_peak_usage( true ),
			'limit' => $limit > 0 ? $limit : 0,
		);
	}

	/**
	 * Environment section.
	 *
	 * @return array
	 */
	private static function compile_env() {
		global $wp_version;

		$opcache = false;
		if ( function_exists( 'opcache_get_status' ) ) {
			$status = @opcache_get_status( false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- restricted API warns on some hosts.
			if ( is_array( $status ) ) {
				$opcache = ! empty( $status['opcache_enabled'] );
			} else {
				$opcache = (bool) filter_var( ini_get( 'opcache.enable' ), FILTER_VALIDATE_BOOLEAN );
			}
		}

		return array(
			'php'                => PHP_VERSION,
			'wp'                 => isset( $wp_version ) ? (string) $wp_version : '',
			'opcache'            => $opcache,
			'object_cache'       => (bool) wp_using_ext_object_cache(),
			'savequeries_forced' => self::$savequeries_forced,
		);
	}

	/**
	 * Context captured at template_redirect, or built now if that never ran
	 * (redirect, early exit). The template is filled in here: it is only known
	 * after template_redirect.
	 *
	 * @return array
	 */
	private static function compile_context() {
		$context = is_array( self::$context ) ? self::$context : self::build_context();

		// Block themes render through template-canvas.php (or, for some
		// WooCommerce pages, no file at all); the block template id
		// ("theme//home") names the template that really rendered.
		$block_id = ( isset( $GLOBALS['_wp_current_template_id'] ) && is_string( $GLOBALS['_wp_current_template_id'] ) ) ? $GLOBALS['_wp_current_template_id'] : '';
		$template = '';
		if ( '' !== $block_id && ( '' === self::$template || false !== strpos( wp_normalize_path( self::$template ), '/template-canvas.php' ) ) ) {
			$template = $block_id;
		} elseif ( '' !== self::$template ) {
			$template = BLT_Optimized_Components::short_path( self::$template );
		}
		$context['template'] = $template;

		return $context;
	}

	/**
	 * Describe the request: what kind of page, which object, which template.
	 *
	 * @return array
	 */
	private static function build_context() {
		global $wp_query;

		$have = $wp_query instanceof WP_Query;
		$cond = static function ( $callback ) use ( $have ) {
			return ( $have && function_exists( $callback ) ) ? (bool) call_user_func( $callback ) : false;
		};
		$woo  = static function ( $callback ) use ( $have ) {
			if ( ! function_exists( $callback ) ) {
				return null; // WooCommerce absent.
			}
			return $have ? (bool) call_user_func( $callback ) : false;
		};

		$conditionals = array(
			'is_front_page'   => $cond( 'is_front_page' ),
			'is_home'         => $cond( 'is_home' ),
			'is_singular'     => $cond( 'is_singular' ),
			'is_page'         => $cond( 'is_page' ),
			'is_single'       => $cond( 'is_single' ),
			'is_archive'      => $cond( 'is_archive' ),
			'is_search'       => $cond( 'is_search' ),
			'is_404'          => $cond( 'is_404' ),
			'is_woocommerce'  => $woo( 'is_woocommerce' ),
			'is_cart'         => $woo( 'is_cart' ),
			'is_checkout'     => $woo( 'is_checkout' ),
			'is_account_page' => $woo( 'is_account_page' ),
		);

		if ( $conditionals['is_404'] ) {
			$type = '404';
		} elseif ( $conditionals['is_search'] ) {
			$type = 'search';
		} elseif ( $conditionals['is_front_page'] ) {
			$type = 'front_page';
		} elseif ( $conditionals['is_home'] ) {
			$type = 'home';
		} elseif ( $conditionals['is_singular'] ) {
			$type = 'singular';
		} elseif ( $conditionals['is_archive'] ) {
			$type = 'archive';
		} else {
			$type = 'other';
		}

		$object_id = $have && function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0;
		$post_type = '';
		if ( $have ) {
			if ( $conditionals['is_singular'] && $object_id > 0 ) {
				$post_type = (string) get_post_type( $object_id );
			} else {
				$queried = $wp_query->get( 'post_type' );
				$queried = is_array( $queried ) ? reset( $queried ) : $queried;
				if ( is_string( $queried ) && '' !== $queried && 'any' !== $queried ) {
					$post_type = $queried;
				} else {
					$object = get_queried_object();
					if ( $object instanceof WP_Term ) {
						$taxonomy  = get_taxonomy( $object->taxonomy );
						$post_type = ( $taxonomy && ! empty( $taxonomy->object_type ) ) ? (string) reset( $taxonomy->object_type ) : 'post';
					} elseif ( $conditionals['is_home'] || $conditionals['is_front_page'] || $wp_query->is_date() || $wp_query->is_author() ) {
						$post_type = 'post';
					}
				}
			}
		}

		return array(
			'template'     => '',
			'type'         => $type,
			'post_type'    => $post_type,
			'object_id'    => $object_id,
			'logged_in'    => function_exists( 'is_user_logged_in' ) ? (bool) is_user_logged_in() : false,
			'conditionals' => $conditionals,
		);
	}

	/**
	 * Queries section: one pass over $wpdb->queries.
	 *
	 * @return array
	 */
	private static function compile_queries() {
		global $wpdb;

		$out = array(
			'count'        => 0,
			'time_ms'      => 0.0,
			'tracked'      => self::$tracked,
			'by_type'      => self::empty_by_type(),
			'by_component' => array(),
			'slowest'      => array(),
			'duplicates'   => array(),
			'untracked'    => 0,
		);

		if ( ! self::$tracked ) {
			return self::compile_fallback_queries( $out );
		}

		$queries = ( isset( $wpdb ) && is_array( $wpdb->queries ) ) ? $wpdb->queries : array();
		$core    = BLT_Optimized_Components::key( self::core_component() );

		$by_comp = array();
		$times   = array();
		$groups  = array();
		$total   = 0.0;

		foreach ( $queries as $i => $q ) {
			$sql = isset( $q[0] ) ? (string) $q[0] : '';
			$t   = isset( $q[1] ) ? (float) $q[1] : 0.0;

			// Attribution: resolved in the filter, deferred (early queries), or
			// absent (logged before we started, or custom data replaced).
			$caller = '';
			$ckey   = $core;
			if ( isset( $q[4]['blt'] ) && is_array( $q[4]['blt'] ) ) {
				$attr = $q[4]['blt'];
				if ( isset( $attr['c'] ) && isset( self::$components[ $attr['c'] ] ) ) {
					$ckey   = $attr['c'];
					$caller = isset( $attr['f'] ) ? (string) $attr['f'] : '';
				} elseif ( isset( $attr['t'] ) ) {
					$resolved = self::resolve( $attr );
					$ckey     = BLT_Optimized_Components::key( $resolved[0] );
					$caller   = $resolved[1];
				}
			}

			++$out['by_type'][ self::query_type( $sql ) ];
			$total      += $t;
			$times[ $i ] = $t;

			if ( isset( $by_comp[ $ckey ] ) ) {
				++$by_comp[ $ckey ][0];
				$by_comp[ $ckey ][1] += $t;
			} else {
				$by_comp[ $ckey ] = array( 1, $t );
			}

			// Duplicates: group by whitespace-collapsed SQL.
			$hash = self::group_key( $sql );
			if ( isset( $groups[ $hash ] ) ) {
				++$groups[ $hash ][0];
				$groups[ $hash ][1]         += $t;
				$groups[ $hash ][3][ $ckey ] = true;
			} elseif ( count( $groups ) < self::MAX_GROUPS ) {
				$groups[ $hash ] = array( 1, $t, $i, array( $ckey => true ), $caller );
			}
		}

		$out['count']     = count( $queries );
		$out['time_ms']   = round( $total * 1000, 2 );
		$out['untracked'] = isset( $wpdb->num_queries ) ? max( 0, (int) $wpdb->num_queries - $out['count'] ) : 0;

		// By component, slowest first.
		uasort(
			$by_comp,
			static function ( $a, $b ) {
				return $b[1] <=> $a[1];
			}
		);
		foreach ( $by_comp as $key => $agg ) {
			if ( isset( self::$components[ $key ] ) ) {
				$out['by_component'][] = array(
					'component' => self::$components[ $key ],
					'count'     => $agg[0],
					'time_ms'   => round( $agg[1] * 1000, 3 ),
				);
			}
		}

		// Slowest individual queries.
		arsort( $times );
		foreach ( array_slice( $times, 0, self::TOP_N, true ) as $i => $t ) {
			$q      = $queries[ $i ];
			$ckey   = $core;
			$caller = '';
			if ( isset( $q[4]['blt'] ) ) {
				$resolved = self::resolve( $q[4]['blt'] );
				$ckey     = BLT_Optimized_Components::key( $resolved[0] );
				$caller   = $resolved[1];
			}
			$out['slowest'][] = array(
				'sql'       => self::display_sql( (string) $q[0] ),
				'ms'        => round( $t * 1000, 3 ),
				'component' => isset( self::$components[ $ckey ] ) ? self::$components[ $ckey ] : self::core_component(),
				'caller'    => $caller,
			);
		}

		// Duplicates: count >= 2, most repeated first.
		$dupes = array();
		foreach ( $groups as $hash => $g ) {
			if ( $g[0] >= 2 ) {
				$dupes[ $hash ] = $g;
			}
		}
		uasort(
			$dupes,
			static function ( $a, $b ) {
				return ( $b[0] === $a[0] ) ? ( $b[1] <=> $a[1] ) : ( $b[0] <=> $a[0] );
			}
		);
		foreach ( array_slice( $dupes, 0, self::TOP_N, true ) as $g ) {
			$components = array();
			foreach ( array_keys( $g[3] ) as $key ) {
				if ( isset( self::$components[ $key ] ) ) {
					$components[] = self::$components[ $key ];
				}
			}
			$out['duplicates'][] = array(
				'sql'        => self::display_sql( (string) $queries[ $g[2] ][0] ),
				'count'      => $g[0],
				'time_ms'    => round( $g[1] * 1000, 3 ),
				'components' => $components,
				'caller'     => $g[4],
			);
		}

		return $out;
	}

	/**
	 * Queries section when SAVEQUERIES is defined false: counts only.
	 *
	 * @param array $out Section skeleton.
	 * @return array
	 */
	private static function compile_fallback_queries( array $out ) {
		global $wpdb;

		$fb = self::$fallback;

		$by_comp = $fb['by_comp'];
		foreach ( $fb['deferred'] as $trace ) {
			$resolved        = self::resolve( array( 't' => $trace ) );
			$key             = BLT_Optimized_Components::key( $resolved[0] );
			$by_comp[ $key ] = isset( $by_comp[ $key ] ) ? $by_comp[ $key ] + 1 : 1;
		}
		arsort( $by_comp );

		$out['count']   = (int) $fb['count'];
		$out['by_type'] = array_merge( self::empty_by_type(), $fb['by_type'] );
		foreach ( $by_comp as $key => $count ) {
			if ( isset( self::$components[ $key ] ) ) {
				$out['by_component'][] = array(
					'component' => self::$components[ $key ],
					'count'     => $count,
					'time_ms'   => 0.0,
				);
			}
		}

		$dupes = array();
		foreach ( $fb['groups'] as $hash => $g ) {
			if ( $g[0] >= 2 ) {
				$dupes[ $hash ] = $g;
			}
		}
		uasort(
			$dupes,
			static function ( $a, $b ) {
				return $b[0] <=> $a[0];
			}
		);
		foreach ( array_slice( $dupes, 0, self::TOP_N, true ) as $g ) {
			$components = array();
			foreach ( array_keys( $g[2] ) as $key ) {
				if ( isset( self::$components[ $key ] ) ) {
					$components[] = self::$components[ $key ];
				}
			}
			$out['duplicates'][] = array(
				'sql'        => $g[1],
				'count'      => $g[0],
				'time_ms'    => 0.0,
				'components' => $components,
				'caller'     => $g[3],
			);
		}

		$out['untracked'] = isset( $wpdb->num_queries ) ? max( 0, (int) $wpdb->num_queries - $out['count'] ) : 0;
		return $out;
	}

	/**
	 * Per-component load cost: plugins (plugin_loaded deltas) plus the theme.
	 *
	 * @return array
	 */
	private static function compile_components_load() {
		$rows = array();

		$add = static function ( $component, $seconds, $bytes ) use ( &$rows ) {
			$key = BLT_Optimized_Components::key( $component );
			if ( ! isset( $rows[ $key ] ) ) {
				$rows[ $key ] = array(
					'component' => $component,
					'ms'        => 0.0,
					'memory'    => 0,
				);
			}
			$rows[ $key ]['ms']     += $seconds * 1000;
			$rows[ $key ]['memory'] += (int) $bytes;
		};

		foreach ( self::$load as $event ) {
			if ( '' !== $event[0] ) {
				$add( BLT_Optimized_Components::from_path( $event[0] ), $event[1], $event[2] );
			}
		}
		if ( is_array( self::$theme ) && '' !== self::$theme[0] ) {
			$add( BLT_Optimized_Components::from_path( trailingslashit( self::$theme[0] ) . 'functions.php' ), self::$theme[1], self::$theme[2] );
		}

		$rows = array_values( $rows );
		foreach ( $rows as &$row ) {
			$row['ms'] = round( $row['ms'], 2 );
		}
		unset( $row );
		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['ms'] <=> $a['ms'];
			}
		);
		return $rows;
	}

	/**
	 * Explains what the load-time list can and cannot show, naming the plugins
	 * that loaded before this one (and so could not be timed).
	 *
	 * @return string
	 */
	private static function compile_components_load_note() {
		$note = __( 'Load times are measured from inside the request: the time between one plugin being loaded and the next, plus the active theme\'s functions.php. Must-use plugins and plugins that load before BLT Optimized cannot be timed individually; their cost is part of the bootstrap time.', 'blt-optimized' );

		if ( function_exists( 'wp_get_active_and_valid_plugins' ) && function_exists( 'plugin_basename' ) ) {
			$self    = plugin_basename( BLT_OPTIMIZED_FILE );
			$earlier = array();
			foreach ( wp_get_active_and_valid_plugins() as $plugin ) {
				if ( plugin_basename( $plugin ) === $self ) {
					break;
				}
				$earlier[] = BLT_Optimized_Components::from_path( $plugin )['name'];
			}
			if ( ! empty( $earlier ) ) {
				/* translators: %s: comma-separated plugin names. */
				$note .= ' ' . sprintf( __( 'Not timed (loaded earlier): %s.', 'blt-optimized' ), implode( ', ', array_slice( $earlier, 0, 20 ) ) );
			}
		}
		if ( function_exists( 'wp_get_mu_plugins' ) ) {
			$mu = count( wp_get_mu_plugins() );
			if ( $mu > 0 ) {
				/* translators: %d: number of must-use plugins. */
				$note .= ' ' . sprintf( _n( '%d must-use plugin was also not timed.', '%d must-use plugins were also not timed.', $mu, 'blt-optimized' ), $mu );
			}
		}
		return $note;
	}

	/**
	 * HTTP API calls made while the page rendered.
	 *
	 * @return array
	 */
	private static function compile_http() {
		$out = array();
		foreach ( self::$http as $call ) {
			if ( null === $call['ms'] ) {
				continue; // Never completed (blocked or short-circuited): not a network call.
			}
			$resolved = self::resolve( $call['attr'] );
			$out[]    = array(
				'url'       => $call['url'],
				'method'    => $call['method'],
				'ms'        => round( $call['ms'], 2 ),
				'status'    => $call['status'],
				'error'     => $call['error'],
				'component' => $resolved[0],
				'caller'    => $resolved[1],
			);
		}
		return $out;
	}

	/**
	 * Enqueued scripts and styles, with local file sizes.
	 *
	 * @return array
	 */
	private static function compile_assets() {
		global $wp_scripts, $wp_styles;

		// Never instantiate the registries here: a request that did not
		// print assets (redirect, early exit) simply has none.
		return array(
			'scripts' => self::collect_assets( $wp_scripts, true ),
			'styles'  => self::collect_assets( $wp_styles, false ),
		);
	}

	/**
	 * Build the asset list for one registry.
	 *
	 * @param WP_Dependencies|mixed $deps      WP_Scripts or WP_Styles.
	 * @param bool                  $is_script True for scripts.
	 * @return array
	 */
	private static function collect_assets( $deps, $is_script ) {
		$out = array();
		if ( ! ( $deps instanceof WP_Dependencies ) ) {
			return $out;
		}

		$wp_ver = get_bloginfo( 'version' );

		foreach ( (array) $deps->done as $handle ) {
			if ( count( $out ) >= self::MAX_ASSETS ) {
				break;
			}
			if ( ! isset( $deps->registered[ $handle ] ) ) {
				continue;
			}
			$dep = $deps->registered[ $handle ];

			$src = self::asset_url( $dep->src, $deps );

			$bytes     = 0;
			$local     = false;
			$component = null;
			if ( '' !== $src ) {
				$path = BLT_Optimized_Components::url_to_path( $src );
				$host = BLT_Optimized_Components::url_host( $src );
				if ( '' !== $path ) {
					$local     = true;
					$bytes     = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir hosts warn.
					$component = BLT_Optimized_Components::from_path( $path );
				} elseif ( '' !== $host && ! BLT_Optimized_Components::is_local_host( $host ) ) {
					$component = BLT_Optimized_Components::make( 'external', $host, $host );
				} elseif ( '' !== $host ) {
					$local     = true; // Local but dynamic (no file behind the URL).
					$component = BLT_Optimized_Components::from_url( $src );
				}
			}
			if ( null === $component ) {
				// No file of its own (an alias like "jquery", or inline-only CSS):
				// infer the owner from the handle.
				$component = self::component_from_handle( (string) $handle );
			}

			$ver = $dep->ver;
			if ( false === $ver ) {
				$ver = $wp_ver;
			}
			$ver       = is_scalar( $ver ) ? (string) $ver : '';
			$deps_list = array_values( array_map( 'strval', (array) $dep->deps ) );

			$extra = is_array( $dep->extra ) ? $dep->extra : array();

			if ( $is_script ) {
				$inline = 0;
				$data   = $deps->get_data( $handle, 'data' );
				if ( is_string( $data ) ) {
					$inline += strlen( $data );
				}
				foreach ( array( 'before', 'after' ) as $position ) {
					$chunks = $deps->get_data( $handle, $position );
					foreach ( is_array( $chunks ) ? $chunks : array() as $chunk ) {
						$inline += is_string( $chunk ) ? strlen( $chunk ) : 0;
					}
				}

				$strategy = isset( $extra['strategy'] ) ? (string) $extra['strategy'] : '';
				if ( ! in_array( $strategy, array( 'defer', 'async' ), true ) ) {
					$strategy = '';
				}

				$out[] = array(
					'handle'       => (string) $handle,
					'src'          => $src,
					'ver'          => $ver,
					'deps'         => $deps_list,
					'footer'       => ( isset( $deps->groups[ $handle ] ) && (int) $deps->groups[ $handle ] > 0 ) || ( isset( $extra['group'] ) && (int) $extra['group'] > 0 ),
					'strategy'     => $strategy,
					'component'    => $component,
					'bytes'        => $bytes,
					'local'        => $local,
					'inline_bytes' => $inline,
				);
			} else {
				$inline = 0;
				$chunks = $deps->get_data( $handle, 'after' );
				foreach ( is_array( $chunks ) ? $chunks : array() as $chunk ) {
					$inline += is_string( $chunk ) ? strlen( $chunk ) : 0;
				}

				$out[] = array(
					'handle'       => (string) $handle,
					'src'          => $src,
					'ver'          => $ver,
					'deps'         => $deps_list,
					'media'        => ( isset( $dep->args ) && is_string( $dep->args ) && '' !== $dep->args ) ? $dep->args : 'all',
					'component'    => $component,
					'bytes'        => $bytes,
					'local'        => $local,
					'inline_bytes' => $inline,
				);
			}
		}

		return $out;
	}

	/**
	 * Best-effort owner of an asset that has no file (an alias handle or
	 * inline-only CSS): WordPress's own handles by pattern, plugins and the
	 * active theme by handle prefix ("woocommerce-inline" -> WooCommerce).
	 * Anything else is "Unknown" rather than guessed.
	 *
	 * @param string $handle Handle.
	 * @return array Component.
	 */
	private static function component_from_handle( $handle ) {
		static $owners = null;

		if ( 1 === preg_match( '/^(wp-|global-styles|core-|classic-theme|block-style|block-library|jquery|admin-bar|dashicons|thickbox|utils$|common$|heartbeat|customize-|editor-|media-|colors)/', $handle ) ) {
			return BLT_Optimized_Components::make( 'core', 'wordpress', __( 'WordPress core', 'blt-optimized' ) ); // phpcs:ignore WordPress.WP.CapitalPDangit -- component slug.
		}

		if ( null === $owners ) {
			$owners = array();
			foreach ( self::$load as $event ) {
				if ( '' === $event[0] ) {
					continue;
				}
				$component = BLT_Optimized_Components::from_path( $event[0] );
				if ( '' !== $component['slug'] ) {
					$owners[ $component['slug'] ] = $component;
				}
			}
			if ( is_array( self::$theme ) && '' !== self::$theme[0] ) {
				$component = BLT_Optimized_Components::from_path( trailingslashit( self::$theme[0] ) . 'functions.php' );
				if ( 'theme' === $component['type'] ) {
					$owners[ $component['slug'] ] = $component;
				}
			}
			if ( function_exists( 'get_template' ) ) {
				$parent = (string) get_template();
				if ( '' !== $parent && ! isset( $owners[ $parent ] ) ) {
					$owners[ $parent ] = BLT_Optimized_Components::from_path( trailingslashit( get_theme_root() ) . $parent . '/functions.php' );
				}
			}
			uksort(
				$owners,
				static function ( $a, $b ) {
					return strlen( $b ) - strlen( $a ); // Longest slug first: most specific match wins.
				}
			);
		}

		foreach ( $owners as $slug => $component ) {
			if ( $handle === $slug || 0 === strpos( $handle, $slug . '-' ) || 0 === strpos( $handle, $slug . '_' ) ) {
				return $component;
			}
		}
		return BLT_Optimized_Components::make( 'other', 'unknown', __( 'Unknown', 'blt-optimized' ) );
	}

	/**
	 * Absolute URL for a registered src, resolved the way WordPress prints it:
	 * protocol-relative gets a scheme; anything that is neither absolute nor
	 * under content_url is relative to the site root (core's /wp-includes/...).
	 *
	 * @param string|false    $src  Registered src.
	 * @param WP_Dependencies $deps Registry (for base_url / content_url).
	 * @return string
	 */
	private static function asset_url( $src, $deps ) {
		if ( ! is_string( $src ) || '' === $src || 0 === strpos( $src, 'data:' ) ) {
			return '';
		}
		if ( 0 === strpos( $src, '//' ) ) {
			return self::cut( BLT_Optimized_Components::absolutize( $src ), 500 );
		}
		if ( preg_match( '#^https?://#i', $src ) ) {
			return self::cut( $src, 500 );
		}

		$content_url = isset( $deps->content_url ) ? (string) $deps->content_url : '';
		if ( '' !== $content_url && 0 === strpos( $src, $content_url ) ) {
			return self::cut( $src, 500 );
		}

		$base = isset( $deps->base_url ) ? (string) $deps->base_url : '';
		if ( '' === $base ) {
			$base = (string) site_url();
		}
		return self::cut( untrailingslashit( $base ) . '/' . ltrim( $src, '/' ), 500 );
	}

	/**
	 * PHP errors recorded during the request.
	 *
	 * @return array
	 */
	private static function compile_errors() {
		$out = array();
		foreach ( self::$errors as $error ) {
			$out[] = array(
				'level'     => $error['level'],
				'message'   => self::clean_text( $error['message'] ),
				'file'      => BLT_Optimized_Components::short_path( $error['file'] ),
				'line'      => $error['line'],
				'component' => BLT_Optimized_Components::from_path( $error['file'] ),
				'count'     => $error['count'],
			);
		}

		// A fatal error ends the request but still reaches shutdown.
		$last = error_get_last();
		if ( is_array( $last ) && in_array( $last['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) && count( $out ) < self::MAX_ERRORS ) {
			$out[] = array(
				'level'     => 'other',
				'message'   => self::clean_text( self::cut( (string) $last['message'], 500 ) ),
				'file'      => BLT_Optimized_Components::short_path( (string) $last['file'] ),
				'line'      => (int) $last['line'],
				'component' => BLT_Optimized_Components::from_path( (string) $last['file'] ),
				'count'     => 1,
			);
		}
		return $out;
	}

	/**
	 * Object-cache statistics.
	 *
	 * @return array
	 */
	private static function compile_cache() {
		global $wp_object_cache;

		$hits   = null;
		$misses = null;
		if ( is_object( $wp_object_cache ) ) {
			if ( property_exists( $wp_object_cache, 'cache_hits' ) && is_numeric( $wp_object_cache->cache_hits ) ) {
				$hits = (int) $wp_object_cache->cache_hits;
			}
			if ( property_exists( $wp_object_cache, 'cache_misses' ) && is_numeric( $wp_object_cache->cache_misses ) ) {
				$misses = (int) $wp_object_cache->cache_misses;
			}
		}

		return array(
			'persistent' => (bool) wp_using_ext_object_cache(),
			'hits'       => $hits,
			'misses'     => $misses,
		);
	}

	/**
	 * Transients written while the page rendered.
	 *
	 * @return array
	 */
	private static function compile_transients_set() {
		$out = array();
		foreach ( self::$transients as $entry ) {
			$resolved = self::resolve( $entry['attr'] );
			$out[]    = array(
				'name'       => $entry['name'],
				'expiration' => $entry['expiration'],
				'component'  => $resolved[0],
				'count'      => $entry['count'],
			);
		}
		return $out;
	}

	/*
	 * ------------------------------------------------------------------
	 * Small utilities
	 * ------------------------------------------------------------------
	 */
	/* ------------------------------------------------------------------ */

	/**
	 * Zeroed per-type counters.
	 *
	 * @return int[]
	 */
	private static function empty_by_type() {
		return array(
			'SELECT' => 0,
			'INSERT' => 0,
			'UPDATE' => 0,
			'DELETE' => 0,
			'OTHER'  => 0,
		);
	}

	/**
	 * Classify a query by its leading keyword.
	 *
	 * @param string $sql SQL.
	 * @return string SELECT|INSERT|UPDATE|DELETE|OTHER.
	 */
	private static function query_type( $sql ) {
		$head = strtoupper( ltrim( substr( $sql, 0, 24 ), " \t\r\n(" ) );
		foreach ( array( 'SELECT', 'INSERT', 'UPDATE', 'DELETE' ) as $type ) {
			if ( 0 === strncmp( $head, $type, 6 ) ) {
				return $type;
			}
		}
		return 'OTHER';
	}

	/**
	 * Grouping key for duplicate detection: md5 of the whitespace-collapsed
	 * SQL. Very large statements (bulk writes) are hashed as-is instead of
	 * being rewritten.
	 *
	 * @param string $sql SQL.
	 * @return string
	 */
	private static function group_key( $sql ) {
		$sql = trim( $sql ); // No copy when there is nothing to trim.
		if ( strlen( $sql ) > 50000 || ( false === strpbrk( $sql, "\n\r\t" ) && false === strpos( $sql, '  ' ) ) ) {
			return md5( $sql );
		}
		$normalized = preg_replace( '/\s+/', ' ', $sql );
		return md5( null === $normalized ? $sql : $normalized );
	}

	/**
	 * SQL for display: whitespace collapsed, capped at SQL_MAX bytes, valid UTF-8.
	 *
	 * @param string $sql SQL.
	 * @return string
	 */
	private static function display_sql( $sql ) {
		$sql = substr( $sql, 0, self::SQL_MAX * 3 ); // Never regex a megabyte of INSERT.
		$one = preg_replace( '/\s+/', ' ', $sql );
		$one = trim( null === $one ? $sql : $one );
		return self::clean_text( self::cut( $one, self::SQL_MAX ) );
	}

	/**
	 * Truncate to at most $max bytes (ellipsis included) without splitting a
	 * multibyte character.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max bytes.
	 * @return string
	 */
	private static function cut( $text, $max ) {
		$text = (string) $text;
		if ( strlen( $text ) <= $max ) {
			return $text;
		}
		$keep = max( 0, $max - 3 ); // The ellipsis is three bytes.
		$cut  = function_exists( 'mb_strcut' ) ? mb_strcut( $text, 0, $keep, 'UTF-8' ) : substr( $text, 0, $keep );
		return $cut . '…';
	}

	/**
	 * Make a string safe to serialize and JSON-encode (drops invalid UTF-8).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function clean_text( $text ) {
		return (string) wp_check_invalid_utf8( (string) $text, true );
	}

	/**
	 * A URL fit for display: no credentials, and values of secret-looking
	 * query parameters (keys, tokens, signatures…) masked.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function clean_url( $url ) {
		$url   = (string) $url;
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return self::clean_text( self::cut( $url, 300 ) );
		}

		$out  = ( isset( $parts['scheme'] ) ? $parts['scheme'] . ':' : '' ) . '//' . $parts['host'];
		$out .= isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$out .= isset( $parts['path'] ) ? $parts['path'] : '';
		if ( ! empty( $parts['query'] ) ) {
			$pairs = explode( '&', $parts['query'] );
			foreach ( $pairs as &$pair ) {
				$kv = explode( '=', $pair, 2 );
				if ( isset( $kv[1] ) && preg_match( '/key|token|secret|pass|auth|sig|license|nonce|code/i', $kv[0] ) ) {
					$pair = $kv[0] . '=***';
				}
			}
			unset( $pair );
			$out .= '?' . implode( '&', $pairs );
		}
		return self::clean_text( self::cut( $out, 300 ) );
	}
}
