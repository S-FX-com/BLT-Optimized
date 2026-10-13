<?php
/**
 * Front-end profiler gate — the always-loaded part of the profiler.
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;

/**
 * Measures a real front-end page request from the inside.
 *
 * Page speed problems are only attributable from within the request that
 * suffers them: which plugin's load time dominates, which plugin issues the
 * slow or duplicated queries, who makes blocking HTTP calls, which handles are
 * enqueued and how big their files are. The admin triggers one loopback
 * request carrying a one-time token (see BLT_Optimized_Page_Auditor); this
 * class notices the token when the plugin file loads, instruments the request,
 * and — at shutdown — stores the compiled result in a transient for the admin
 * side to pick up with get_result().
 *
 * This class is only the gate and the admin-side token API, so every
 * ordinary request parses a few kilobytes. The instrumentation itself lives
 * in BLT_Optimized_Profiler_Collector, required only once a request carries a
 * profile token.
 *
 * Design constraints:
 *  - Zero cost when inactive: maybe_start() bails on a single isset() check.
 *  - Never breaks a page: every hook callback and the whole shutdown compile
 *    are wrapped in try/catch( \Throwable ); the error handler chains to any
 *    previous handler and otherwise returns false so PHP carries on as usual.
 *  - Bounded memory: SQL strings, error lists and asset lists are capped, and
 *    the query log is aggregated in a single pass.
 *  - Component attribution (plugin/theme/core/external) via
 *    BLT_Optimized_Components. Attribution that would run before
 *    `after_setup_theme` is deferred to shutdown, because resolving a plugin's
 *    display name that early would load translations too early (and its cost
 *    would be billed to whichever plugin happened to be loading).
 *
 * Result schema: see the build spec, "Module A".
 */
class BLT_Optimized_Profiler {

	/**
	 * Query argument carrying the one-time token.
	 */
	const QUERY_ARG = 'blt_optimized_profile';

	/**
	 * Transient prefixes: token (admin -> request) and result (request -> admin).
	 */
	const TOKEN_PREFIX  = 'blt_optimized_prof_';
	const RESULT_PREFIX = 'blt_optimized_profres_';

	/**
	 * Lifetimes, in seconds.
	 */
	const TOKEN_TTL  = 300;
	const RESULT_TTL = 600;

	/**
	 * Whether this request is being profiled.
	 *
	 * @var bool
	 */
	protected static $active = false;

	/**
	 * Options carried by the token.
	 *
	 * @var array
	 */
	protected static $opts = array();

	/**
	 * Create a one-time profile token.
	 *
	 * @param array $opts Options. `tweaks_off` (bool): disable BLT speed tweaks
	 *                    for the profiled request to measure the untouched site.
	 * @return string 32-character alphanumeric token.
	 */
	public static function create_token( array $opts = array() ): string {
		$token = wp_generate_password( 32, false, false );
		set_transient(
			self::TOKEN_PREFIX . $token,
			array(
				'created' => time(),
				'opts'    => array(
					'tweaks_off' => ! empty( $opts['tweaks_off'] ),
				),
			),
			self::TOKEN_TTL
		);
		return $token;
	}

	/**
	 * Fetch (and delete) the result stored by a profiled request.
	 *
	 * @param string $token Token the request was started with.
	 * @return array|null Result, or null when the request has not stored one.
	 */
	public static function get_result( string $token ): ?array {
		if ( 1 !== preg_match( '/^[A-Za-z0-9]{32}\z/', $token ) ) {
			return null;
		}
		$key = self::RESULT_PREFIX . $token;

		// A miss is cached in `notoptions` for the rest of this request, which
		// would hide a result the profiled request stores a moment later.
		if ( ! wp_using_ext_object_cache() ) {
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( '_transient_' . $key, 'options' );
			wp_cache_delete( '_transient_timeout_' . $key, 'options' );
		}
		$result = get_transient( $key );
		if ( false === $result ) {
			return null;
		}
		delete_transient( $key );
		return is_array( $result ) ? $result : null;
	}

	/*
	 * ------------------------------------------------------------------
	 * Activation
	 * ------------------------------------------------------------------
	 */
	/* ------------------------------------------------------------------ */

	/**
	 * Called when the plugin file loads. Does nothing — beyond one isset() —
	 * unless the request carries a valid, unused profile token.
	 *
	 * @return void
	 */
	public static function maybe_start(): void {
		if ( ! isset( $_GET[ self::QUERY_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the single-use token is the credential.
			return;
		}
		// Only now is the collector (the bulk of the profiler) parsed.
		require_once __DIR__ . '/class-blt-optimized-profiler-collector.php';
		BLT_Optimized_Profiler_Collector::start();
	}

	/**
	 * Whether this request is being profiled.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return self::$active;
	}

	/**
	 * Whether BLT speed tweaks must stay off for this (profiled) request.
	 *
	 * @return bool
	 */
	public static function tweaks_disabled(): bool {
		return self::$active && ! empty( self::$opts['tweaks_off'] );
	}
}
