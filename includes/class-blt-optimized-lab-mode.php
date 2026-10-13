<?php
/**
 * Lab mode — prepares one front-end request to be measured in the admin's
 * browser by the built-in lab test.
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;

/**
 * The built-in lab test (the PageSpeed Insights alternative) loads a page in
 * a visible iframe on the admin screen and reads the browser's own
 * performance APIs. For that to measure what a visitor gets, the one request
 * carrying a lab token must:
 *
 *  - render as a logged-out visitor (the iframe carries the admin's cookies),
 *    with no admin bar;
 *  - bypass page caching, and fetch this site's CSS/JS fresh rather than from
 *    the browser cache warmed by earlier runs;
 *  - allow being framed by this site even where a plugin sends
 *    X-Frame-Options: DENY or a restrictive CSP frame-ancestors;
 *  - print the observer script before anything else in <head>.
 *
 * Loaded by BLT_Optimized_Profiler::maybe_start() only for requests carrying
 * the lab query argument; everything here is static and request-scoped.
 */
class BLT_Optimized_Lab_Mode {

	/**
	 * Query argument carrying the lab token.
	 */
	const QUERY_ARG = 'blt_optimized_lab';

	/**
	 * Cache-busting argument added to local CSS/JS URLs in lab mode.
	 */
	const BUST_ARG = 'blt_lab';

	/**
	 * Token transient prefix and lifetime. A token survives local redirects
	 * (canonical trailing slash, etc.) and is consumed when the page renders.
	 */
	const TOKEN_PREFIX = 'blt_optimized_labt_';
	const TOKEN_TTL    = 180;

	/**
	 * Token for this request ('' when not in lab mode).
	 *
	 * @var string
	 */
	private static $token = '';

	/**
	 * Create a lab token (admin side) and return the URL to load in the iframe.
	 *
	 * @param string $url Page URL on this site.
	 * @return string URL with the lab token.
	 */
	public static function create_url( $url ) {
		$token = wp_generate_password( 32, false, false );
		set_transient(
			self::TOKEN_PREFIX . $token,
			array(
				'created' => time(),
				'user'    => get_current_user_id(),
			),
			self::TOKEN_TTL
		);
		return add_query_arg( self::QUERY_ARG, $token, $url );
	}

	/**
	 * Whether this request is a lab-mode request.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return '' !== self::$token;
	}

	/**
	 * Validate the token and switch the request into lab mode. Called from the
	 * profiler gate at plugin load, before anything resolves the current user.
	 *
	 * @return void
	 */
	public static function start() {
		if ( self::is_active() ) {
			return;
		}

		$token = isset( $_GET[ self::QUERY_ARG ] ) ? $_GET[ self::QUERY_ARG ] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- the single-use token is the credential; validated below.
		if ( ! is_string( $token ) || 1 !== preg_match( '/^[A-Za-z0-9]{32}\z/', $token ) ) {
			return;
		}
		if ( 'cli' === PHP_SAPI || is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( ! is_array( get_transient( self::TOKEN_PREFIX . $token ) ) ) {
			return;
		}

		self::$token = $token;

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		// Measure what a visitor gets: no logged-in user, no admin bar.
		add_filter( 'determine_current_user', '__return_zero', PHP_INT_MAX );
		add_filter( 'show_admin_bar', '__return_false', PHP_INT_MAX );
		// Anything that resolved the user before this plugin loaded is reset.
		add_action( 'plugins_loaded', array( __CLASS__, 'reset_user' ), 0 );
		add_action( 'init', array( __CLASS__, 'reset_user' ), 0 );

		add_filter( 'style_loader_src', array( __CLASS__, 'bust' ), PHP_INT_MAX );
		add_filter( 'script_loader_src', array( __CLASS__, 'bust' ), PHP_INT_MAX );
		add_filter( 'wp_redirect', array( __CLASS__, 'carry_token' ), PHP_INT_MAX );
		add_action( 'send_headers', array( __CLASS__, 'send_headers' ), PHP_INT_MAX );
		add_action( 'template_redirect', array( __CLASS__, 'consume' ), PHP_INT_MAX );
		add_action( 'wp_head', array( __CLASS__, 'print_observer' ), -9999 );
	}

	/**
	 * Drop any user resolved before lab mode started.
	 *
	 * @return void
	 */
	public static function reset_user() {
		if ( function_exists( 'wp_set_current_user' ) && get_current_user_id() ) {
			wp_set_current_user( 0 );
		}
	}

	/**
	 * Cache-bust this site's stylesheets and scripts so each run fetches them
	 * like a first visit would. External URLs are left alone.
	 *
	 * @param string $src Asset URL.
	 * @return string
	 */
	public static function bust( $src ) {
		if ( ! is_string( $src ) || '' === $src ) {
			return $src;
		}
		$host = BLT_Optimized_Components::url_host( $src );
		if ( '' !== $host && ! BLT_Optimized_Components::is_local_host( $host ) ) {
			return $src;
		}
		return add_query_arg( self::BUST_ARG, substr( self::$token, 0, 8 ), $src );
	}

	/**
	 * Keep the token on local redirects (e.g. the canonical trailing slash), so
	 * the page that finally renders is the one measured.
	 *
	 * @param string $location Redirect target.
	 * @return string
	 */
	public static function carry_token( $location ) {
		if ( ! is_string( $location ) || '' === $location ) {
			return $location;
		}
		$host = BLT_Optimized_Components::url_host( $location );
		if ( '' !== $host && ! BLT_Optimized_Components::is_local_host( $host ) ) {
			return $location;
		}
		return add_query_arg( self::QUERY_ARG, self::$token, $location );
	}

	/**
	 * No caching, and allow this site to frame the response where PHP set a
	 * header forbidding it. Headers a web server adds itself can't be changed
	 * here; the lab test reports those pages as blocked.
	 *
	 * @return void
	 */
	public static function send_headers() {
		nocache_headers();
		if ( headers_sent() ) {
			return;
		}

		header( 'X-BLT-Lab: 1' );

		$csp = array();
		foreach ( headers_list() as $line ) {
			$parts = explode( ':', $line, 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}
			$name  = strtolower( trim( $parts[0] ) );
			$value = trim( $parts[1] );

			if ( 'x-frame-options' === $name && 0 !== strcasecmp( $value, 'SAMEORIGIN' ) ) {
				header_remove( 'X-Frame-Options' );
			}
			if ( 'content-security-policy' === $name ) {
				$csp[] = $value;
			}
		}

		// Rewrite only when a policy restricts framing; every policy is kept,
		// with frame-ancestors narrowed to this site.
		$restricts = false;
		foreach ( $csp as $value ) {
			$restricts = $restricts || false !== stripos( $value, 'frame-ancestors' );
		}
		if ( ! $restricts ) {
			return;
		}
		header_remove( 'Content-Security-Policy' );
		foreach ( $csp as $value ) {
			$directives = array_filter(
				array_map( 'trim', explode( ';', $value ) ),
				static function ( $directive ) {
					return '' !== $directive && 0 !== stripos( $directive, 'frame-ancestors' );
				}
			);
			$directives[] = "frame-ancestors 'self'";
			header( 'Content-Security-Policy: ' . implode( '; ', $directives ), false );
		}
	}

	/**
	 * Consume the token once the page itself renders (redirect hops that exit
	 * earlier in template_redirect keep it for their target).
	 *
	 * @return void
	 */
	public static function consume() {
		delete_transient( self::TOKEN_PREFIX . self::$token );
	}

	/**
	 * Print the observer first in <head>, before any stylesheet or script, so
	 * its PerformanceObservers see the whole page load.
	 *
	 * @return void
	 */
	public static function print_observer() {
		$file = BLT_OPTIMIZED_DIR . 'assets/lab-observer.js';
		if ( ! is_readable( $file ) ) {
			return;
		}
		$js = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
		if ( '' === trim( $js ) ) {
			return;
		}
		// A literal "</script" inside the file would end the tag early.
		$js = str_ireplace( '</script', '<\/script', $js );
		echo "<script id=\"blt-optimized-lab-observer\">\n" . $js . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static plugin file, closing tags neutralised above.
	}
}
