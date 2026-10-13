<?php
/**
 * Health checks — the server and database half of the Performance Scan.
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read-only checks that need no page request: PHP/OPcache/object-cache setup,
 * debug and cron configuration, then the database — autoloaded options,
 * transients, cron events, Action Scheduler backlogs, revisions, postmeta,
 * table bloat — and any space hogs found by the last disk scan.
 *
 * Why this exists: most "WordPress is slow" causes that live outside the page
 * render are invisible in a front-end profile. A 3 MB autoloaded option, an
 * orphaned cron hook that wakes up every hour, or 60,000 finished Action
 * Scheduler rows each cost time on every request, and each one has a specific
 * owner and a specific fix. Every check therefore returns findings built with
 * BLT_Optimized_Findings::make() that name the exact option, hook, table,
 * plugin or file, quantify it, show real rows as evidence, and attach a
 * concrete fix.
 *
 * Performance contract: run() uses aggregate SQL (COUNT/SUM, GROUP BY ... LIMIT),
 * information_schema through the DB optimizer, and in-memory cron data — never a
 * PHP loop over a big table. Expensive aggregates are time-boxed on the database
 * side and skipped once the overall time budget is spent. Anything optional
 * (Action Scheduler, WooCommerce, OPcache functions, a disk scan) is guarded.
 *
 * All finding text is plain text (not HTML): the UI must escape it on output.
 */
class BLT_Optimized_Health_Checks {

	/* ------------------------------------------------------------------ */
	/* Thresholds                                                          */
	/* ------------------------------------------------------------------ */

	const PHP_CRITICAL_BELOW = '7.4';
	const PHP_WARNING_BELOW  = '8.1';

	const OPCACHE_MIN_HIT_RATE = 90.0;
	const OPCACHE_MIN_SAMPLE   = 20000; // Hits + misses needed before the hit rate is judged (a freshly restarted cache looks cold).

	const AUTOLOAD_WARNING_BYTES  = 800 * KB_IN_BYTES;
	const AUTOLOAD_CRITICAL_BYTES = 2 * MB_IN_BYTES;
	const AUTOLOAD_OPTION_BYTES   = 100 * KB_IN_BYTES;
	const AUTOLOAD_OPTION_CAP     = 15; // Max single-option findings.

	const TRANSIENT_ROWS_NOTICE    = 1000;
	const TRANSIENT_EXPIRED_NOTICE = 100;

	const CRON_OPTION_BYTES    = 100 * KB_IN_BYTES;
	const CRON_ORPHAN_CAP      = 30;
	const CRON_MIN_INTERVAL    = 5 * MINUTE_IN_SECONDS;
	const CRON_OVERDUE_SECONDS = HOUR_IN_SECONDS;

	const AS_FINISHED_ROWS    = 10000;
	const AS_LOG_ROWS         = 50000;
	const AS_STUCK_COUNT      = 100;
	const AS_STUCK_SECONDS    = HOUR_IN_SECONDS;
	const AS_RETENTION_DAYS   = 30; // Mirrors the cleaner's default.
	const AS_LOG_COUNT_CAP    = 1000000;

	const REVISIONS_TOTAL    = 5000;
	const REVISIONS_PER_POST = 50;

	const POSTMETA_BYTES      = 50 * MB_IN_BYTES;
	const POSTMETA_ROWS       = 500000;
	const OEMBED_ROWS         = 500;
	const OEMBED_BYTES        = MB_IN_BYTES;
	const TABLE_OVERHEAD      = 50 * MB_IN_BYTES;
	const TABLE_OVERHEAD_MIN  = 8 * MB_IN_BYTES; // Per InnoDB table: smaller free space is pre-allocated extents, not fragmentation.
	const LARGE_TABLE_BYTES   = 50 * MB_IN_BYTES;
	const LARGE_TABLE_CAP     = 10;
	const OPTIONS_ROWS        = 10000;

	const MEMORY_RECOMMENDED = 128 * MB_IN_BYTES;
	const DEBUG_LOG_BYTES    = 50 * MB_IN_BYTES;

	const STORAGE_MIN_BYTES = 10 * MB_IN_BYTES;
	const STORAGE_CAP       = 15;
	const STORAGE_WARNING   = 50 * MB_IN_BYTES;
	const STORAGE_CRITICAL  = 500 * MB_IN_BYTES;

	/**
	 * Seconds a single optional aggregate query may run on the database side.
	 */
	const QUERY_TIMEBOX = 5;

	/**
	 * Once run() has used this many seconds, remaining optional deep-dive
	 * queries are skipped.
	 */
	const TIME_BUDGET = 8.0;

	/**
	 * Cron hooks WordPress core schedules itself. Never reported as orphaned.
	 * Extend with the `blt_optimized_core_cron_hooks` filter.
	 */
	const CORE_CRON_HOOKS = array(
		'wp_version_check',
		'wp_update_plugins',
		'wp_update_themes',
		'wp_scheduled_delete',
		'wp_scheduled_auto_draft_delete',
		'delete_expired_transients',
		'wp_privacy_delete_old_export_files',
		'wp_privacy_personal_data_cleanup_requests',
		'wp_site_health_scheduled_check',
		'recovery_mode_clean_expired_keys',
		'wp_https_detection',
		'wp_update_user_counts',
		'wp_delete_temp_updater_backups',
		'wp_maybe_auto_update',
		'wp_split_shared_term_batch',
		'wp_update_comment_type_batch',
		'importer_scheduled_cleanup',
		'upgrader_scheduled_cleanup',
		'publish_future_post',
		'do_pings',
		'update_network_counts',
		'action_scheduler_run_queue',
	);

	/**
	 * Single words too generic to prove which plugin owns an option or table.
	 */
	const GENERIC_TOKENS = array(
		'site', 'core', 'cache', 'form', 'forms', 'theme', 'themes', 'user', 'users', 'post', 'posts',
		'page', 'pages', 'widget', 'widgets', 'option', 'options', 'admin', 'settings', 'setting',
		'custom', 'wordpress', 'data', 'api', 'blog', 'tools', 'text', 'image', 'images', 'block',
		'blocks', 'menu', 'menus', 'email', 'mail', 'log', 'logs', 'tags', 'seo', 'stats', 'security',
		'hello', 'index',
	);

	/* ------------------------------------------------------------------ */
	/* State                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * DB optimizer (table stats, autoload audit).
	 *
	 * @var BLT_Optimized_DB_Optimizer
	 */
	private $db;

	/**
	 * Disk scanner (last scan results).
	 *
	 * @var BLT_Optimized_Scanner
	 */
	private $scanner;

	/**
	 * Start time of the current run().
	 *
	 * @var float
	 */
	private $started = 0.0;

	/**
	 * Per-run memo of shared lookups.
	 *
	 * @var array
	 */
	private $memo = array();

	/**
	 * Seconds each check took in the last run(), keyed by check method.
	 *
	 * @var array<string,float>
	 */
	private $timings = array();

	/**
	 * Constructor.
	 *
	 * @param BLT_Optimized_DB_Optimizer $db      DB optimizer.
	 * @param BLT_Optimized_Scanner      $scanner Disk scanner.
	 */
	public function __construct( BLT_Optimized_DB_Optimizer $db, BLT_Optimized_Scanner $scanner ) {
		$this->db      = $db;
		$this->scanner = $scanner;
	}

	/**
	 * Run every check.
	 *
	 * A check that throws is skipped (and logged under WP_DEBUG) — one broken
	 * query must never cost the user the rest of the report.
	 *
	 * @return array[] Findings (BLT_Optimized_Findings::make() shape).
	 */
	public function run() {
		$this->started = microtime( true );
		$this->memo    = array();
		$this->timings = array();

		$checks = array(
			// Server.
			'check_php_version',
			'check_opcache',
			'check_object_cache',
			'check_debug_settings',
			'check_memory_limit',
			'check_wp_cron',
			// Database.
			'check_autoload',
			'check_transients',
			'check_cron_option',
			'check_orphaned_cron_hooks',
			'check_frequent_cron',
			'check_action_scheduler',
			'check_revisions',
			'check_postmeta',
			'check_oembed_cache',
			'check_table_overhead',
			'check_myisam',
			'check_large_tables',
			'check_options_rows',
			// Storage.
			'check_storage',
		);

		$findings = array();
		foreach ( $checks as $check ) {
			$t0 = microtime( true );
			try {
				$result = $this->$check();
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf( 'BLT Optimized health check %s failed: %s in %s:%d', $check, $e->getMessage(), $e->getFile(), $e->getLine() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
				$result = array();
			}
			$this->timings[ $check ] = microtime( true ) - $t0;
			foreach ( (array) $result as $finding ) {
				$findings[] = $finding;
			}
		}

		/**
		 * Filter the health-check findings before they are returned.
		 *
		 * @param array[] $findings Findings.
		 */
		return apply_filters( 'blt_optimized_health_findings', $findings );
	}

	/**
	 * Seconds each check took in the last run(), for diagnostics.
	 *
	 * @return array<string,float>
	 */
	public function get_timings() {
		return $this->timings;
	}

	/* ================================================================== */
	/* SERVER                                                              */
	/* ================================================================== */

	/**
	 * PHP version: < 7.4 critical, < 8.1 warning, otherwise a pass.
	 *
	 * @return array[]
	 */
	private function check_php_version() {
		$version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
		$details = $this->kv_details(
			array(
				array( __( 'PHP version', 'blt-optimized' ), $version ),
				array( __( 'Server API', 'blt-optimized' ), PHP_SAPI ),
				array( __( 'Recommended', 'blt-optimized' ), __( 'PHP 8.2 or 8.3', 'blt-optimized' ) ),
			)
		);

		if ( version_compare( PHP_VERSION, self::PHP_WARNING_BELOW, '<' ) ) {
			$critical = version_compare( PHP_VERSION, self::PHP_CRITICAL_BELOW, '<' );
			return array(
				$this->finding(
					array(
						'id'       => 'server.php_version',
						'category' => 'server',
						'severity' => $critical ? 'critical' : 'warning',
						/* translators: %s: PHP version. */
						'title'    => sprintf( __( 'PHP %s is end-of-life and slower than current PHP', 'blt-optimized' ), $version ),
						'summary'  => __( 'This PHP branch no longer receives security fixes, and PHP 8.2/8.3 execute WordPress noticeably faster (less CPU per request, lower memory use). Switching is normally a one-click change at the host.', 'blt-optimized' ),
						'where'    => array( 'PHP ' . $version ),
						'details'  => $details,
						'fix'      => array(
							'type'  => 'manual',
							'label' => __( 'Upgrade PHP', 'blt-optimized' ),
							'steps' => sprintf(
								/* translators: %s: PHP version. */
								__( "1. Back up the site and database.\n2. In your host's control panel (cPanel \"MultiPHP Manager\", Plesk, Kinsta/WP Engine/SiteGround PHP settings) or by asking support, switch this site from PHP %s to PHP 8.2 or 8.3.\n3. Reload the site and wp-admin; if a plugin breaks, switch back and update or replace that plugin first.", 'blt-optimized' ),
								$version
							),
						),
					)
				),
			);
		}

		return array(
			$this->finding(
				array(
					'id'       => 'server.php_version',
					'category' => 'server',
					'severity' => 'pass',
					/* translators: %s: PHP version. */
					'title'    => sprintf( __( 'PHP %s is a supported version', 'blt-optimized' ), $version ),
					'summary'  => __( 'The PHP version in use still receives security fixes and has the performance improvements of modern PHP.', 'blt-optimized' ),
					'details'  => $details,
				)
			),
		);
	}

	/**
	 * OPcache: off is critical; on but full or with a low hit rate is a
	 * warning; healthy is a pass. A status we cannot read (restricted API, CLI
	 * with OPcache disabled for CLI only) produces no finding rather than a
	 * false alarm.
	 *
	 * @return array[]
	 */
	private function check_opcache() {
		$has_api     = function_exists( 'opcache_get_status' );
		$ini_enabled = filter_var( ini_get( 'opcache.enable' ), FILTER_VALIDATE_BOOLEAN );
		$status      = $has_api ? @opcache_get_status( false ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- restrict_api and CLI emit warnings.

		$off_steps = __( "1. Ask your host to enable the Zend OPcache extension (most hosts have a PHP-extensions toggle).\n2. If you manage php.ini yourself, set: opcache.enable=1, opcache.memory_consumption=192, opcache.interned_strings_buffer=16, opcache.max_accelerated_files=20000, opcache.validate_timestamps=1, opcache.revalidate_freq=60.\n3. Restart PHP-FPM/Apache and reload the site.", 'blt-optimized' );

		if ( ! $has_api || ( false === $status && ! $ini_enabled ) || ( is_array( $status ) && empty( $status['opcache_enabled'] ) ) ) {
			return array(
				$this->finding(
					array(
						'id'       => 'server.opcache',
						'category' => 'server',
						'severity' => 'critical',
						'title'    => $has_api ? __( 'PHP OPcache is installed but switched off', 'blt-optimized' ) : __( 'PHP OPcache is not installed', 'blt-optimized' ),
						'summary'  => __( 'Without OPcache, PHP re-reads and re-compiles every WordPress, theme and plugin file on every request. Enabling it typically cuts PHP execution time by half or more at no cost.', 'blt-optimized' ),
						'where'    => array( 'php.ini: opcache.enable' ),
						'details'  => $this->kv_details(
							array(
								array( __( 'OPcache extension functions', 'blt-optimized' ), $has_api ? __( 'available', 'blt-optimized' ) : __( 'missing', 'blt-optimized' ) ),
								array( 'opcache.enable', (string) ini_get( 'opcache.enable' ) ),
								array( __( 'Server API', 'blt-optimized' ), PHP_SAPI ),
							)
						),
						'fix'      => array(
							'type'  => 'manual',
							'label' => __( 'Enable OPcache', 'blt-optimized' ),
							'steps' => $off_steps,
						),
					)
				),
			);
		}

		if ( ! is_array( $status ) ) {
			return array(); // Enabled per ini but unreadable (restrict_api / CLI): can't judge.
		}

		$mem       = isset( $status['memory_usage'] ) ? $status['memory_usage'] : array();
		$stats     = isset( $status['opcache_statistics'] ) ? $status['opcache_statistics'] : array();
		$used      = isset( $mem['used_memory'] ) ? (int) $mem['used_memory'] : 0;
		$free      = isset( $mem['free_memory'] ) ? (int) $mem['free_memory'] : 0;
		$wasted    = isset( $mem['wasted_memory'] ) ? (int) $mem['wasted_memory'] : 0;
		$total_mem = $used + $free + $wasted;
		$hits      = isset( $stats['hits'] ) ? (int) $stats['hits'] : 0;
		$misses    = isset( $stats['misses'] ) ? (int) $stats['misses'] : 0;
		$hit_rate  = isset( $stats['opcache_hit_rate'] ) ? (float) $stats['opcache_hit_rate'] : 0.0;
		$scripts   = isset( $stats['num_cached_scripts'] ) ? (int) $stats['num_cached_scripts'] : 0;
		$max_keys  = isset( $stats['max_cached_keys'] ) ? (int) $stats['max_cached_keys'] : 0;
		$oom       = isset( $stats['oom_restarts'] ) ? (int) $stats['oom_restarts'] : 0;
		$full      = ! empty( $status['cache_full'] ) || $oom > 0;
		$low_rate  = ( $hits + $misses ) >= self::OPCACHE_MIN_SAMPLE && $hit_rate < self::OPCACHE_MIN_HIT_RATE;

		$details = $this->kv_details(
			array(
				array( __( 'Memory used / total', 'blt-optimized' ), size_format( $used + $wasted ) . ' / ' . size_format( $total_mem ) ),
				array( __( 'Scripts cached / key slots', 'blt-optimized' ), number_format_i18n( $scripts ) . ' / ' . number_format_i18n( $max_keys ) ),
				array( __( 'Hit rate', 'blt-optimized' ), number_format_i18n( $hit_rate, 1 ) . '% (' . number_format_i18n( $hits ) . ' hits, ' . number_format_i18n( $misses ) . ' misses)' ),
				array( __( 'Restarts because the cache was full', 'blt-optimized' ), number_format_i18n( $oom ) ),
				array( 'opcache.memory_consumption', (string) ini_get( 'opcache.memory_consumption' ) . 'M' ),
				array( 'opcache.max_accelerated_files', (string) ini_get( 'opcache.max_accelerated_files' ) ),
			)
		);

		if ( $full || $low_rate ) {
			return array(
				$this->finding(
					array(
						'id'       => 'server.opcache',
						'category' => 'server',
						'severity' => 'warning',
						'title'    => $full
							? __( 'PHP OPcache is full and is evicting compiled scripts', 'blt-optimized' )
							/* translators: %s: hit rate percentage. */
							: sprintf( __( 'PHP OPcache hit rate is only %s%%', 'blt-optimized' ), number_format_i18n( $hit_rate, 1 ) ),
						'summary'  => $full
							? __( 'When OPcache runs out of memory or key slots it throws away compiled files and recompiles them, so some requests pay the full compile cost again. Give it more memory and file slots.', 'blt-optimized' )
							: __( 'A hit rate under 90% means many PHP files are being compiled again instead of served from memory — usually because the cache is too small for the number of files, is restarted on every deploy or request, or timestamps are re-validated constantly. Give it more memory and file slots and check the restart policy.', 'blt-optimized' ),
						'where'    => array( 'php.ini: opcache.memory_consumption', 'php.ini: opcache.max_accelerated_files' ),
						'details'  => $details,
						'fix'      => array(
							'type'  => 'manual',
							'label' => __( 'Increase OPcache limits', 'blt-optimized' ),
							'steps' => __( "1. In php.ini (or your host's PHP settings) raise opcache.memory_consumption to at least 192 and opcache.max_accelerated_files to 20000 or more.\n2. Make sure opcache.validate_timestamps is not being defeated by a deploy script that restarts PHP on every request.\n3. Restart PHP-FPM/Apache, then check this scan again after some traffic.", 'blt-optimized' ),
						),
					)
				),
			);
		}

		return array(
			$this->finding(
				array(
					'id'       => 'server.opcache',
					'category' => 'server',
					'severity' => 'pass',
					'title'    => ( $hits + $misses ) >= self::OPCACHE_MIN_SAMPLE
						/* translators: %s: hit rate percentage. */
						? sprintf( __( 'OPcache is enabled (%s%% hit rate)', 'blt-optimized' ), number_format_i18n( $hit_rate, 1 ) )
						: __( 'OPcache is enabled', 'blt-optimized' ),
					'summary'  => __( 'PHP is serving pre-compiled scripts from memory and the cache has room to spare.', 'blt-optimized' ),
					'details'  => $details,
				)
			),
		);
	}

	/**
	 * Persistent object cache. Missing is a warning when WooCommerce is active,
	 * autoload is large, or the options table holds many transients; otherwise a
	 * notice. Present is a pass.
	 *
	 * @return array[]
	 */
	private function check_object_cache() {
		if ( wp_using_ext_object_cache() ) {
			$class = isset( $GLOBALS['wp_object_cache'] ) && is_object( $GLOBALS['wp_object_cache'] ) ? get_class( $GLOBALS['wp_object_cache'] ) : '';
			return array(
				$this->finding(
					array(
						'id'       => 'server.object_cache',
						'category' => 'server',
						'severity' => 'pass',
						'title'    => __( 'A persistent object cache is active', 'blt-optimized' ),
						'summary'  => __( 'Repeated database lookups (options, post meta, term lookups, transients) are served from memory across requests.', 'blt-optimized' ),
						'details'  => $this->kv_details(
							array(
								array( __( 'Object cache class', 'blt-optimized' ), '' !== $class ? $class : __( 'unknown', 'blt-optimized' ) ),
								array( __( 'Drop-in file', 'blt-optimized' ), file_exists( WP_CONTENT_DIR . '/object-cache.php' ) ? 'wp-content/object-cache.php' : __( 'not found', 'blt-optimized' ) ),
							)
						),
					)
				),
			);
		}

		$autoload   = $this->autoload();
		$transients = $this->transient_stats();
		$woo        = $this->woo_active();
		$heavy      = $woo || $autoload['total_bytes'] > self::AUTOLOAD_WARNING_BYTES || $transients['rows'] > self::TRANSIENT_ROWS_NOTICE;

		$reasons = array();
		if ( $woo ) {
			$reasons[] = array( __( 'WooCommerce is active', 'blt-optimized' ), __( 'Cart, session, product and order lookups repeat on every request.', 'blt-optimized' ) );
		}
		$reasons[] = array(
			__( 'Autoloaded options', 'blt-optimized' ),
			size_format( $autoload['total_bytes'], 1 ) . ' ' . sprintf(
				/* translators: %s: option count. */
				__( 'in %s options, re-read from MySQL on every request', 'blt-optimized' ),
				number_format_i18n( $autoload['total_count'] )
			),
		);
		$reasons[] = array(
			__( 'Transient rows in wp_options', 'blt-optimized' ),
			number_format_i18n( $transients['rows'] ) . ' (' . size_format( $transients['bytes'], 1 ) . ')',
		);

		return array(
			$this->finding(
				array(
					'id'       => 'server.object_cache',
					'category' => 'server',
					'severity' => $heavy ? 'warning' : 'notice',
					'title'    => $woo
						? __( 'No persistent object cache on a WooCommerce site', 'blt-optimized' )
						: __( 'No persistent object cache (Redis or Memcached)', 'blt-optimized' ),
					'summary'  => __( 'Without one, WordPress forgets everything it looked up when each request ends, so every page load repeats the same database queries and transients live in the slow options table. A Redis/Memcached cache removes most of that work.', 'blt-optimized' ),
					'where'    => array( 'wp-content/object-cache.php' ),
					'details'  => $this->kv_details( $reasons, array( __( 'Why it matters here', 'blt-optimized' ), __( 'Detail', 'blt-optimized' ) ) ),
					'fix'      => array(
						'type'  => 'manual',
						'label' => __( 'Enable Redis/Memcached', 'blt-optimized' ),
						'steps' => __( "1. Ask your host to enable Redis or Memcached for this site (many managed hosts offer it in the dashboard).\n2. Install the matching drop-in plugin (for example \"Redis Object Cache\"), activate it, and click Enable Object Cache.\n3. Re-run this scan: this check turns into a pass and the autoloaded-options and transient findings shrink.", 'blt-optimized' ),
					),
				)
			),
		);
	}

	/**
	 * WP_DEBUG on in production, SAVEQUERIES on, and an oversized debug.log.
	 *
	 * @return array[]
	 */
	private function check_debug_settings() {
		$findings = array();
		$log_path = $this->debug_log_path();
		$log_size = ( '' !== $log_path && @is_file( $log_path ) ) ? (int) @filesize( $log_path ) : 0; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir.
		$env      = wp_get_environment_type();

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && 'production' === $env ) {
			$findings[] = $this->finding(
				array(
					'id'       => 'server.wp_debug',
					'category' => 'server',
					'severity' => 'warning',
					'title'    => __( 'WP_DEBUG is on while the environment type is production', 'blt-optimized' ),
					'summary'  => __( 'Debug mode makes PHP report and (with WP_DEBUG_LOG) write every notice and deprecation on every request, which costs time, can grow debug.log without limit, and may expose paths to visitors.', 'blt-optimized' ),
					'where'    => array( 'wp-config.php: WP_DEBUG' ),
					'details'  => $this->kv_details(
						array(
							array( 'WP_DEBUG', 'true' ),
							array( 'WP_DEBUG_LOG', $this->constant_label( 'WP_DEBUG_LOG' ) ),
							array( 'WP_DEBUG_DISPLAY', $this->constant_label( 'WP_DEBUG_DISPLAY' ) ),
							array( __( 'Environment type', 'blt-optimized' ), $env ),
							array( __( 'debug.log size', 'blt-optimized' ), $log_size > 0 ? size_format( $log_size, 1 ) : __( 'none', 'blt-optimized' ) ),
						)
					),
					'fix'      => array(
						'type'  => 'manual',
						'label' => __( 'Turn off WP_DEBUG', 'blt-optimized' ),
						'steps' => __( "1. Edit wp-config.php and change to: define( 'WP_DEBUG', false );\n2. If this is a staging or development copy instead, mark it so with: define( 'WP_ENVIRONMENT_TYPE', 'staging' );\n3. Remove or archive wp-content/debug.log once you are done debugging.", 'blt-optimized' ),
					),
				)
			);
		}

		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
			$findings[] = $this->finding(
				array(
					'id'       => 'server.savequeries',
					'category' => 'server',
					'severity' => 'warning',
					'title'    => __( 'SAVEQUERIES is enabled and logs every database query', 'blt-optimized' ),
					'summary'  => __( 'With SAVEQUERIES on, WordPress stores each query with its timing and full PHP call stack in memory on every request. That adds CPU and memory to every page load and is only meant for short debugging sessions.', 'blt-optimized' ),
					'where'    => array( 'wp-config.php: SAVEQUERIES' ),
					'fix'      => array(
						'type'  => 'manual',
						'label' => __( 'Remove SAVEQUERIES', 'blt-optimized' ),
						'steps' => __( "Edit wp-config.php and delete the line: define( 'SAVEQUERIES', true ); (or set it to false). A plugin such as Query Monitor does not need it.", 'blt-optimized' ),
					),
				)
			);
		}

		if ( $log_size > self::DEBUG_LOG_BYTES ) {
			$findings[] = $this->finding(
				array(
					'id'        => 'server.debug_log',
					'category'  => 'server',
					'severity'  => 'warning',
					/* translators: %s: file size. */
					'title'     => sprintf( __( 'debug.log has grown to %s', 'blt-optimized' ), size_format( $log_size, 1 ) ),
					'summary'   => __( 'PHP appends to this file on every logged notice. A log this large costs disk space, slows backups and, while logging stays on, keeps growing. Read what is spamming it, fix or silence that, then delete the file.', 'blt-optimized' ),
					'impact'    => array(
						'type'  => 'bytes',
						'value' => $log_size,
					),
					'where'     => array( BLT_Optimized_Components::short_path( $log_path ) ),
					'details'   => $this->kv_details(
						array(
							array( __( 'File', 'blt-optimized' ), BLT_Optimized_Components::short_path( $log_path ) ),
							array( __( 'Size', 'blt-optimized' ), size_format( $log_size, 1 ) ),
							array( __( 'Last written', 'blt-optimized' ), wp_date( 'Y-m-d H:i', (int) @filemtime( $log_path ) ) ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
							array( 'WP_DEBUG_LOG', $this->constant_label( 'WP_DEBUG_LOG' ) ),
						)
					),
					'fix'       => array(
						'type'  => 'link',
						'label' => __( 'Open Disk Usage', 'blt-optimized' ),
						'url'   => admin_url( 'admin.php?page=blt-optimized-disk' ),
					),
				)
			);
		}

		return $findings;
	}

	/**
	 * Effective PHP memory: the larger of WP_MEMORY_LIMIT and PHP's own limit
	 * is what a front-end request can use. Under 128 MB is a notice.
	 *
	 * @return array[]
	 */
	private function check_memory_limit() {
		$wp_limit  = defined( 'WP_MEMORY_LIMIT' ) ? wp_convert_hr_to_bytes( WP_MEMORY_LIMIT ) : 0;
		$php_limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		if ( -1 === (int) $php_limit ) {
			return array(); // Unlimited.
		}
		$effective = max( (int) $wp_limit, (int) $php_limit );
		if ( $effective >= self::MEMORY_RECOMMENDED ) {
			return array();
		}

		return array(
			$this->finding(
				array(
					'id'       => 'server.memory_limit',
					'category' => 'server',
					'severity' => 'notice',
					/* translators: %s: memory limit. */
					'title'    => sprintf( __( 'PHP memory limit is only %s', 'blt-optimized' ), size_format( $effective ) ),
					'summary'  => __( 'Sites with WooCommerce, page builders or many plugins routinely need 128 MB or more; hitting the ceiling ends a request with a fatal error, and a tight limit leaves no room for caches.', 'blt-optimized' ),
					'impact'   => array(
						'type'  => 'bytes',
						'value' => $effective,
					),
					'where'    => array( 'wp-config.php: WP_MEMORY_LIMIT' ),
					'details'  => $this->kv_details(
						array(
							array( 'WP_MEMORY_LIMIT', defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : __( 'not set', 'blt-optimized' ) ),
							array( 'php.ini memory_limit', (string) ini_get( 'memory_limit' ) ),
							array( 'WP_MAX_MEMORY_LIMIT (admin)', defined( 'WP_MAX_MEMORY_LIMIT' ) ? (string) WP_MAX_MEMORY_LIMIT : __( 'not set', 'blt-optimized' ) ),
						)
					),
					'fix'      => array(
						'type'  => 'manual',
						'label' => __( 'Raise the memory limit', 'blt-optimized' ),
						'steps' => __( "Add to wp-config.php, above the line \"That's all, stop editing\": define( 'WP_MEMORY_LIMIT', '256M' ); If it has no effect, ask your host to raise memory_limit in PHP settings.", 'blt-optimized' ),
					),
				)
			),
		);
	}

	/**
	 * WP-Cron: visitor-triggered cron (notice) and overdue events (warning).
	 *
	 * @return array[]
	 */
	private function check_wp_cron() {
		$findings = array();
		$cron     = $this->cron_info();
		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		if ( ! $disabled ) {
			$findings[] = $this->finding(
				array(
					'id'       => 'server.wp_cron',
					'category' => 'server',
					'severity' => 'notice',
					'title'    => __( 'WP-Cron runs on visitor page loads instead of a real cron job', 'blt-optimized' ),
					'summary'  => __( 'With DISABLE_WP_CRON unset, WordPress checks for due tasks during page requests and can spawn a background request on any visitor. A system cron fires on time, never adds to a visitor\'s page load, and keeps working on low-traffic sites.', 'blt-optimized' ),
					'impact'   => array(
						'type'  => 'count',
						'value' => $cron['total'],
						'label' => sprintf(
							/* translators: %s: event count. */
							__( '%s scheduled events', 'blt-optimized' ),
							number_format_i18n( $cron['total'] )
						),
					),
					'where'    => array( 'wp-config.php: DISABLE_WP_CRON', site_url( 'wp-cron.php' ) ),
					'details'  => $this->kv_details(
						array(
							array( __( 'Events scheduled', 'blt-optimized' ), number_format_i18n( $cron['total'] ) ),
							array( __( 'Due right now', 'blt-optimized' ), number_format_i18n( $cron['due'] ) ),
							array( 'DISABLE_WP_CRON', defined( 'DISABLE_WP_CRON' ) ? 'false' : __( 'not defined', 'blt-optimized' ) ),
							array( 'ALTERNATE_WP_CRON', defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ? 'true' : 'false' ),
						)
					),
					'fix'      => array(
						'type'  => 'manual',
						'label' => __( 'Switch to a system cron', 'blt-optimized' ),
						'steps' => sprintf(
							/* translators: 1: wp-cron.php URL. */
							__( "1. Add to wp-config.php: define( 'DISABLE_WP_CRON', true );\n2. In your host's cron panel (or crontab -e) add a job that runs every 5 minutes: */5 * * * * curl -s \"%1\$s?doing_wp_cron\" > /dev/null 2>&1\n   (or, with WP-CLI: */5 * * * * cd /path/to/site && wp cron event run --due-now)\n3. Re-run this scan and confirm no events stay overdue.", 'blt-optimized' ),
							site_url( 'wp-cron.php' )
						),
					),
				)
			);
		}

		if ( $cron['overdue_events'] > 0 ) {
			$rows = array();
			foreach ( array_slice( $cron['overdue_hooks'], 0, 10 ) as $hook => $data ) {
				$rows[] = array(
					$hook,
					number_format_i18n( $data['count'] ),
					wp_date( 'Y-m-d H:i', $data['oldest'] ),
					human_time_diff( $data['oldest'], time() ),
					$data['schedule'],
				);
			}
			$findings[] = $this->finding(
				array(
					'id'       => 'server.cron_overdue',
					'category' => 'server',
					'severity' => 'warning',
					/* translators: %s: event count. */
					'title'    => sprintf( _n( "WP-Cron isn't running reliably: %s event is more than an hour overdue", "WP-Cron isn't running reliably: %s events are more than an hour overdue", $cron['overdue_events'], 'blt-optimized' ), number_format_i18n( $cron['overdue_events'] ) ),
					'summary'  => __( 'Scheduled tasks (backups, emails, stock syncs, cache clears, Action Scheduler queues) are not firing when due, so they pile up and then run in bursts. This usually means WP-Cron is disabled without a system cron, loopback requests are blocked, or the site gets too little traffic.', 'blt-optimized' ),
					'impact'   => array(
						'type'  => 'count',
						'value' => $cron['overdue_events'],
					),
					'where'    => array_slice( array_keys( $cron['overdue_hooks'] ), 0, 10 ),
					'details'  => array(
						'columns' => array( __( 'Hook', 'blt-optimized' ), __( 'Events overdue', 'blt-optimized' ), __( 'Oldest was due', 'blt-optimized' ), __( 'Overdue by', 'blt-optimized' ), __( 'Schedule', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => array(
						'type'  => 'manual',
						'label' => __( 'Get cron running', 'blt-optimized' ),
						'steps' => sprintf(
							/* translators: %s: wp-cron.php URL. */
							__( "1. Run \"wp cron event run --due-now\" (WP-CLI) to clear the backlog, and \"wp cron event list\" to see what is queued.\n2. If DISABLE_WP_CRON is true, make sure a real cron job requests %s every few minutes.\n3. If it is not, check Tools > Site Health for a failing loopback request (security plugin, basic auth or host firewall blocking requests to this site's own URL).", 'blt-optimized' ),
							site_url( 'wp-cron.php' )
						),
					),
				)
			);
		}

		return $findings;
	}

	/* ================================================================== */
	/* DATABASE                                                            */
	/* ================================================================== */

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

	/**
	 * Total autoloaded payload plus a finding per oversized single option.
	 *
	 * @return array[]
	 */
	private function check_autoload() {
		global $wpdb;
		$audit    = $this->autoload();
		$total    = $audit['total_bytes'];
		$top      = $audit['top'];
		$findings = array();

		$rows = array();
		foreach ( array_slice( $top, 0, 10 ) as $option ) {
			$owner  = $this->guess_owner( $option['name'] );
			$rows[] = array(
				$option['name'],
				size_format( $option['bytes'], 1 ),
				$total > 0 ? number_format_i18n( 100 * $option['bytes'] / $total, 1 ) . '%' : '-',
				$owner ? BLT_Optimized_Components::label( $owner ) : __( 'unknown', 'blt-optimized' ),
			);
		}
		$details = array(
			'columns' => array( __( 'Option', 'blt-optimized' ), __( 'Size', 'blt-optimized' ), __( 'Share of autoload', 'blt-optimized' ), __( 'Likely owner', 'blt-optimized' ) ),
			'rows'    => $rows,
		);

		$biggest      = ! empty( $top ) ? $top[0] : null;
		$biggest_own  = $biggest ? $this->guess_owner( $biggest['name'] ) : null;
		$biggest_text = $biggest
			? sprintf(
				/* translators: 1: option name, 2: size, 3: percent of total. */
				__( 'The largest, “%1$s” (%2$s), is %3$s%% of it.', 'blt-optimized' ),
				$biggest['name'],
				size_format( $biggest['bytes'], 1 ),
				number_format_i18n( $total > 0 ? 100 * $biggest['bytes'] / $total : 0, 0 )
			)
			: '';

		if ( $total > self::AUTOLOAD_WARNING_BYTES ) {
			$findings[] = $this->finding(
				array(
					'id'        => 'db.autoload_total',
					'category'  => 'database',
					'severity'  => $total > self::AUTOLOAD_CRITICAL_BYTES ? 'critical' : 'warning',
					/* translators: %s: total size. */
					'title'     => sprintf( __( 'Autoloaded options total %s and load on every request', 'blt-optimized' ), size_format( $total, 1 ) ),
					'summary'   => sprintf(
						/* translators: 1: option count, 2: biggest-option sentence. */
						__( 'WordPress reads all %1$s autoloaded options from the options table on every front-end and admin request, before any page code runs. %2$s Trimming the biggest ones is the quickest way to cut server response time.', 'blt-optimized' ),
						number_format_i18n( $audit['total_count'] ),
						$biggest_text
					),
					'impact'    => array(
						'type'  => 'bytes',
						'value' => $total,
					),
					'component' => $biggest_own,
					'where'     => array_slice( wp_list_pluck( $top, 'name' ), 0, 10 ),
					'details'   => $details,
					'fix'       => array(
						'type'  => 'manual',
						'label' => __( 'Trim autoloaded options', 'blt-optimized' ),
						'steps' => __( "1. Use \"Stop autoloading\" on the oversized options reported separately in this scan (it only changes how the option is loaded and can be reversed from Speed Tweaks).\n2. For options owned by plugins you no longer use, delete the plugin's leftover data.\n3. For active plugins, ask the developer why the option must be autoloaded; large caches and logs belong in transients or non-autoloaded options.", 'blt-optimized' ),
					),
				)
			);
		} else {
			$findings[] = $this->finding(
				array(
					'id'       => 'db.autoload_total',
					'category' => 'database',
					'severity' => 'pass',
					/* translators: %s: total size. */
					'title'    => sprintf( __( 'Autoloaded options are a healthy %s', 'blt-optimized' ), size_format( $total, 1 ) ),
					'summary'  => sprintf(
						/* translators: %s: size threshold. */
						__( 'Everything WordPress preloads on each request is under the %s warning threshold.', 'blt-optimized' ),
						size_format( self::AUTOLOAD_WARNING_BYTES )
					),
					'impact'   => array(
						'type'  => 'bytes',
						'value' => $total,
					),
					'details'  => $details,
				)
			);
		}

		// Single oversized options, excluding anything core depends on.
		$candidates = array();
		foreach ( $top as $option ) {
			if ( $option['bytes'] > self::AUTOLOAD_OPTION_BYTES && ! $this->is_protected_option( $option['name'] ) ) {
				$candidates[] = $option;
			}
			if ( count( $candidates ) >= self::AUTOLOAD_OPTION_CAP ) {
				break;
			}
		}
		if ( empty( $candidates ) ) {
			return $findings;
		}

		$names        = wp_list_pluck( $candidates, 'name' );
		$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		$samples      = array();
		$sample_rows  = $this->get_rows(
			$wpdb->prepare(
				"SELECT option_name, LEFT(option_value, 160) AS sample FROM {$wpdb->options} WHERE option_name IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$names
			)
		);
		foreach ( $sample_rows as $row ) {
			$samples[ $row['option_name'] ] = (string) $row['sample'];
		}

		foreach ( $candidates as $option ) {
			$name   = $option['name'];
			$owner  = $this->guess_owner( $name );
			$sample = isset( $samples[ $name ] ) ? $samples[ $name ] : '';
			$share  = $total > 0 ? 100 * $option['bytes'] / $total : 0;

			$findings[] = $this->finding(
				array(
					'id'        => 'db.autoload_option',
					'key'       => 'db.autoload_option:' . $name,
					'category'  => 'database',
					'severity'  => 'warning',
					'title'     => sprintf(
						/* translators: 1: option name, 2: size. */
						__( 'Autoloaded option “%1$s” is %2$s', 'blt-optimized' ),
						$name,
						size_format( $option['bytes'], 1 )
					),
					'summary'   => sprintf(
						/* translators: 1: option name, 2: percent of all autoloaded data, 3: owner label or generic phrase. */
						__( '“%1$s” alone is %2$s%% of everything WordPress loads from the options table on every request (%3$s). If it is only needed on one screen, stop autoloading it so it is read on demand instead.', 'blt-optimized' ),
						$name,
						number_format_i18n( $share, 1 ),
						$owner ? sprintf(
							/* translators: %s: plugin or theme name. */
							__( 'likely owned by %s', 'blt-optimized' ),
							$owner['name']
						) : __( 'owner unknown', 'blt-optimized' )
					),
					'impact'    => array(
						'type'  => 'bytes',
						'value' => $option['bytes'],
					),
					'component' => $owner,
					'where'     => array( $wpdb->options . '.option_name = ' . $name ),
					'details'   => $this->kv_details(
						array(
							array( __( 'Option', 'blt-optimized' ), $name ),
							array( __( 'Size', 'blt-optimized' ), size_format( $option['bytes'], 1 ) . ' (' . number_format_i18n( $option['bytes'] ) . ' bytes)' ),
							array( __( 'Share of autoloaded data', 'blt-optimized' ), number_format_i18n( $share, 1 ) . '%' ),
							array( __( 'Autoload flag', 'blt-optimized' ), (string) $option['autoload'] ),
							array( __( 'Looks like', 'blt-optimized' ), $this->value_format( $sample ) ),
							array( __( 'Starts with', 'blt-optimized' ), $this->clean_sample( $sample, 120 ) ),
							array( __( 'Likely owner', 'blt-optimized' ), $owner ? BLT_Optimized_Components::label( $owner ) : __( 'unknown', 'blt-optimized' ) ),
						)
					),
					'fix'       => array(
						'type'        => 'action',
						'label'       => __( 'Stop autoloading', 'blt-optimized' ),
						'action'      => 'disable_autoload',
						'args'        => array( 'option' => $name ),
						'destructive' => false,
						'confirm'     => sprintf(
							/* translators: 1: option name, 2: owner label or "its owner". */
							__( 'Stop autoloading “%1$s”? %2$s may read it on every request, in which case it will now be fetched from the database on demand (usually a net win for a large option). You can restore the original setting from Speed Tweaks.', 'blt-optimized' ),
							$name,
							$owner ? $owner['name'] : __( 'The plugin or theme that owns it', 'blt-optimized' )
						),
					),
				)
			);
		}

		return $findings;
	}

	/**
	 * Transients stored in wp_options (no persistent cache) and how many have
	 * already expired.
	 *
	 * @return array[]
	 */
	private function check_transients() {
		global $wpdb;
		$stats      = $this->transient_stats();
		$persistent = wp_using_ext_object_cache();

		$too_many = ! $persistent && $stats['rows'] > self::TRANSIENT_ROWS_NOTICE;
		$expired  = $stats['expired'] >= self::TRANSIENT_EXPIRED_NOTICE;
		if ( ! $too_many && ! $expired ) {
			return array();
		}

		$rows = array();
		if ( ! $this->out_of_time() ) {
			$top = $this->get_rows(
				$wpdb->prepare(
					"SELECT v.option_name, LENGTH(v.option_value) AS bytes, t.option_value AS expires
					 FROM {$wpdb->options} v
					 LEFT JOIN {$wpdb->options} t ON t.option_name = CONCAT(%s, SUBSTRING(v.option_name, 12))
					 WHERE v.option_name LIKE %s AND v.option_name NOT LIKE %s
					 ORDER BY bytes DESC LIMIT 8",
					'_transient_timeout_',
					$wpdb->esc_like( '_transient_' ) . '%',
					$wpdb->esc_like( '_transient_timeout_' ) . '%'
				),
				self::QUERY_TIMEBOX
			);
			foreach ( (array) $top as $row ) {
				$name    = substr( $row['option_name'], strlen( '_transient_' ) );
				$owner   = $this->guess_owner( $name );
				$expires = isset( $row['expires'] ) && '' !== (string) $row['expires'] ? (int) $row['expires'] : 0;
				$rows[]  = array(
					$name,
					size_format( (int) $row['bytes'], 1 ),
					0 === $expires ? __( 'never', 'blt-optimized' ) : ( $expires < time()
						/* translators: %s: time span. */
						? sprintf( __( 'expired %s ago', 'blt-optimized' ), human_time_diff( $expires ) )
						/* translators: %s: time span. */
						: sprintf( __( 'in %s', 'blt-optimized' ), human_time_diff( $expires ) ) ),
					$owner ? BLT_Optimized_Components::label( $owner ) : __( 'unknown', 'blt-optimized' ),
				);
			}
		}

		return array(
			$this->finding(
				array(
					'id'       => 'db.transients',
					'category' => 'database',
					'severity' => 'notice',
					'title'    => sprintf(
						/* translators: 1: transient count, 2: size, 3: expired count. */
						__( '%1$s transients (%2$s) sit in the options table, %3$s already expired', 'blt-optimized' ),
						number_format_i18n( $stats['rows'] ),
						size_format( $stats['bytes'], 1 ),
						number_format_i18n( $stats['expired'] )
					),
					'summary'  => $persistent
						? __( 'Expired transients are dead weight that WordPress only purges lazily. Clearing them shrinks the options table and speeds up queries against it.', 'blt-optimized' )
						: __( 'Without a persistent object cache every transient lives as a row in wp_options. Expired ones are only removed when something happens to read them, so the table keeps growing and every options query gets slower.', 'blt-optimized' ),
					'impact'   => array(
						'type'  => 'bytes',
						'value' => max( $stats['expired_bytes'], 0 ),
						'label' => sprintf(
							/* translators: %s: size. */
							__( '%s expired', 'blt-optimized' ),
							size_format( $stats['expired_bytes'], 1 )
						),
					),
					'where'    => array( $wpdb->options . ' (_transient_%)' ),
					'details'  => array(
						'columns' => array( __( 'Largest transients', 'blt-optimized' ), __( 'Size', 'blt-optimized' ), __( 'Expires', 'blt-optimized' ), __( 'Likely owner', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => array(
						'type'  => 'link',
						'label' => __( 'Clean transients', 'blt-optimized' ),
						'url'   => admin_url( 'admin.php?page=blt-optimized-cleanup' ),
					),
				)
			),
		);
	}

	/**
	 * Size of the `cron` option and which hooks fill it.
	 *
	 * @return array[]
	 */
	private function check_cron_option() {
		global $wpdb;
		$bytes = (int) $wpdb->get_var( $wpdb->prepare( "SELECT LENGTH(option_value) FROM {$wpdb->options} WHERE option_name = %s", 'cron' ) );
		if ( $bytes <= self::CRON_OPTION_BYTES ) {
			return array();
		}

		$cron = $this->cron_info();
		$rows = array();
		foreach ( array_slice( $cron['hooks'], 0, 10, true ) as $hook => $data ) {
			$owner  = $this->guess_owner( $hook, 'hook' );
			$rows[] = array(
				$hook,
				number_format_i18n( $data['events'] ),
				$data['next'] ? wp_date( 'Y-m-d H:i', $data['next'] ) : '-',
				$data['schedule'],
				$data['has_callback'] ? __( 'yes', 'blt-optimized' ) : __( 'NO (orphaned)', 'blt-optimized' ),
				$owner ? BLT_Optimized_Components::label( $owner ) : __( 'unknown', 'blt-optimized' ),
			);
		}
		$top_hook = ! empty( $cron['hooks'] ) ? key( $cron['hooks'] ) : '';
		$top      = $top_hook ? $cron['hooks'][ $top_hook ] : null;

		return array(
			$this->finding(
				array(
					'id'        => 'db.cron_option',
					'category'  => 'database',
					'severity'  => 'warning',
					/* translators: 1: size, 2: event count. */
					'title'     => sprintf( __( 'The cron option is %1$s, holding %2$s scheduled events', 'blt-optimized' ), size_format( $bytes, 1 ), number_format_i18n( $cron['total'] ) ),
					'summary'   => $top
						? sprintf(
							/* translators: 1: hook name, 2: event count. */
							__( 'WordPress loads and rewrites this autoloaded option on every cron run, and every new event rewrites all of it. “%1$s” alone has %2$s queued events — a plugin is re-scheduling the same job without cleaning up.', 'blt-optimized' ),
							$top_hook,
							number_format_i18n( $top['events'] )
						)
						: __( 'WordPress loads and rewrites this autoloaded option on every cron run.', 'blt-optimized' ),
					'impact'    => array(
						'type'  => 'bytes',
						'value' => $bytes,
					),
					'component' => $top_hook ? $this->guess_owner( $top_hook, 'hook' ) : null,
					'where'     => array_slice( array_keys( $cron['hooks'] ), 0, 10 ),
					'details'   => array(
						'columns' => array( __( 'Hook', 'blt-optimized' ), __( 'Queued events', 'blt-optimized' ), __( 'Next run', 'blt-optimized' ), __( 'Schedule', 'blt-optimized' ), __( 'Has a handler', 'blt-optimized' ), __( 'Likely owner', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'       => array(
						'type'  => 'manual',
						'label' => __( 'Clear the pile-up', 'blt-optimized' ),
						'steps' => sprintf(
							/* translators: %s: hook name. */
							__( "1. If a hook shows \"NO (orphaned)\" use its \"Delete cron hook\" fix below.\n2. Otherwise clear the duplicates with WP-CLI: wp cron event delete %s   (the plugin re-adds the single event it needs).\n3. Update or report the plugin that keeps re-scheduling it.", 'blt-optimized' ),
							'' !== $top_hook ? $top_hook : '<hook>'
						),
					),
				)
			),
		);
	}

	/**
	 * Scheduled cron hooks that nothing handles — one warning per hook.
	 *
	 * has_action() is only meaningful with every plugin loaded; run() executes
	 * in a normal admin request, so it is.
	 *
	 * @return array[]
	 */
	private function check_orphaned_cron_hooks() {
		$cron = $this->cron_info();
		$skip = array_flip( (array) apply_filters( 'blt_optimized_core_cron_hooks', self::CORE_CRON_HOOKS ) );

		$orphans = array();
		foreach ( $cron['hooks'] as $hook => $data ) {
			if ( isset( $skip[ $hook ] ) || $data['has_callback'] ) {
				continue;
			}
			$orphans[ $hook ] = $data;
		}
		if ( empty( $orphans ) ) {
			return array();
		}

		$plugins  = $this->installed_plugins();
		$findings = array();
		foreach ( array_slice( $orphans, 0, self::CRON_ORPHAN_CAP, true ) as $hook => $data ) {
			$owner        = $this->guess_owner( $hook, 'hook' );
			$owner_active = $this->cron_hook_owner_active( $hook );
			$owner_note   = '';
			if ( $owner && 'plugin' === $owner['type'] ) {
				$owner_note = isset( $plugins[ $owner['slug'] ] )
					? ( $plugins[ $owner['slug'] ]['active']
						/* translators: %s: plugin name. */
						? sprintf( __( 'The name suggests %s, which is active but no longer registers a handler for it.', 'blt-optimized' ), $owner['name'] )
						/* translators: %s: plugin name. */
						: sprintf( __( 'The name suggests %s, which is installed but deactivated.', 'blt-optimized' ), $owner['name'] ) )
					/* translators: %s: plugin name. */
					: sprintf( __( 'The name suggests %s, which is not installed.', 'blt-optimized' ), $owner['name'] );
			}

			$findings[] = $this->finding(
				array(
					'id'        => 'db.cron_orphan',
					'key'       => 'db.cron_orphan:' . $hook,
					'category'  => 'database',
					'severity'  => $owner_active ? 'notice' : 'warning',
					/* translators: %s: cron hook name. */
					'title'     => sprintf( __( 'Cron hook “%s” is scheduled but nothing handles it', 'blt-optimized' ), $hook ),
					'summary'   => trim(
						sprintf(
							/* translators: 1: hook name, 2: schedule label, 3: owner note. */
							__( 'WP-Cron keeps queuing “%1$s” (%2$s) but no active plugin or theme registers a callback for it, so every run does nothing — usually left behind by a deactivated or deleted plugin. Removing it stops the wasted wake-ups and shrinks the cron option. %3$s', 'blt-optimized' ),
							$hook,
							$data['schedule'],
							$owner_note
						)
					),
					'impact'    => array(
						'type'  => 'count',
						'value' => $data['events'],
						'label' => sprintf(
							/* translators: %s: event count. */
							_n( '%s queued event', '%s queued events', $data['events'], 'blt-optimized' ),
							number_format_i18n( $data['events'] )
						),
					),
					'component' => $owner,
					'where'     => array( $hook ),
					'details'   => $this->kv_details(
						array(
							array( __( 'Hook', 'blt-optimized' ), $hook ),
							array( __( 'Queued events', 'blt-optimized' ), number_format_i18n( $data['events'] ) ),
							array( __( 'Schedule', 'blt-optimized' ), $data['schedule'] ),
							array( __( 'Next run', 'blt-optimized' ), $data['next'] ? wp_date( 'Y-m-d H:i', $data['next'] ) : '-' ),
							array( __( 'Arguments', 'blt-optimized' ), $this->args_label( $data['args'] ) ),
							array( __( 'Handlers registered', 'blt-optimized' ), '0' ),
							array( __( 'Likely owner', 'blt-optimized' ), $owner ? BLT_Optimized_Components::label( $owner ) : __( 'unknown', 'blt-optimized' ) ),
						)
					),
					// An active owner may register its handler only while
					// WP-Cron itself runs, which this admin request can't see —
					// so no one-click delete for those.
					'fix'       => $owner_active ? array(
						'type'  => 'manual',
						'label' => __( 'How to check', 'blt-optimized' ),
						'steps' => sprintf(
							/* translators: 1: plugin name, 2: hook name. */
							__( '%1$s is active, and some plugins only register their cron handlers while WP-Cron itself is running — so “%2$s” may not be orphaned. Check the plugin\'s documentation or ask its developer. If it really is left over, remove it with WP-CLI: wp cron event delete %2$s', 'blt-optimized' ),
							$owner['name'],
							$hook
						),
					) : array(
						'type'        => 'action',
						'label'       => __( 'Delete cron hook', 'blt-optimized' ),
						'action'      => 'delete_cron_hook',
						'args'        => array( 'hook' => $hook ),
						'destructive' => false,
						'confirm'     => sprintf(
							/* translators: %s: hook name. */
							__( 'Remove every scheduled event for “%s”? Nothing currently handles it. If its plugin is reactivated later, it will schedule the hook again by itself.', 'blt-optimized' ),
							$hook
						),
					),
				)
			);
		}

		if ( count( $orphans ) > self::CRON_ORPHAN_CAP ) {
			$rest = array_slice( $orphans, self::CRON_ORPHAN_CAP, null, true );
			$rows = array();
			foreach ( $rest as $hook => $data ) {
				$owner  = $this->guess_owner( $hook, 'hook' );
				$rows[] = array(
					$hook,
					number_format_i18n( $data['events'] ),
					$data['schedule'],
					$owner ? BLT_Optimized_Components::label( $owner ) : __( 'unknown', 'blt-optimized' ),
				);
			}
			$findings[] = $this->finding(
				array(
					'id'       => 'db.cron_orphan_more',
					'category' => 'database',
					'severity' => 'notice',
					/* translators: %s: number of additional hooks. */
					'title'    => sprintf( __( '%s more orphaned cron hooks are not listed individually', 'blt-optimized' ), number_format_i18n( count( $rest ) ) ),
					'summary'  => __( 'More hooks than can be shown separately are scheduled with no handler. Remove them in bulk with WP-CLI after reviewing the list.', 'blt-optimized' ),
					'impact'   => array(
						'type'  => 'count',
						'value' => count( $rest ),
					),
					'where'    => array_slice( array_keys( $rest ), 0, 20 ),
					'details'  => array(
						'columns' => array( __( 'Hook', 'blt-optimized' ), __( 'Queued events', 'blt-optimized' ), __( 'Schedule', 'blt-optimized' ), __( 'Likely owner', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => array(
						'type'  => 'manual',
						'label' => __( 'Delete in bulk', 'blt-optimized' ),
						'steps' => __( "For each hook you recognise as dead, run: wp cron event delete <hook>\nRe-run this scan to see the next batch.", 'blt-optimized' ),
					),
				)
			);
		}

		return $findings;
	}

	/**
	 * Recurring hooks that fire more often than every five minutes.
	 *
	 * @return array[]
	 */
	private function check_frequent_cron() {
		$cron = $this->cron_info();
		$skip = array_flip( array( 'action_scheduler_run_queue' ) ); // Every minute by design.

		$rows  = array();
		$hooks = array();
		foreach ( $cron['hooks'] as $hook => $data ) {
			if ( isset( $skip[ $hook ] ) || $data['interval'] <= 0 || $data['interval'] >= self::CRON_MIN_INTERVAL ) {
				continue;
			}
			$owner   = $this->guess_owner( $hook, 'hook' );
			$hooks[] = $hook;
			$rows[]  = array(
				$hook,
				$data['schedule'],
				$data['has_callback'] ? __( 'yes', 'blt-optimized' ) : __( 'NO (orphaned)', 'blt-optimized' ),
				$owner ? BLT_Optimized_Components::label( $owner ) : __( 'unknown', 'blt-optimized' ),
			);
		}
		if ( empty( $rows ) ) {
			return array();
		}

		return array(
			$this->finding(
				array(
					'id'       => 'db.cron_frequent',
					'category' => 'database',
					'severity' => 'notice',
					'title'    => 1 === count( $hooks )
						/* translators: %s: cron hook name. */
						? sprintf( __( 'Cron hook “%s” runs more often than every 5 minutes', 'blt-optimized' ), $hooks[0] )
						: sprintf(
							/* translators: 1: number of hooks, 2: first hook name. */
							__( '%1$s cron hooks (starting with “%2$s”) run more often than every 5 minutes', 'blt-optimized' ),
							number_format_i18n( count( $hooks ) ),
							$hooks[0]
						),
					'summary'  => __( 'Each firing is a PHP process that loads WordPress; very frequent schedules add constant background load and, with WP-Cron, extra requests to the site. Check whether the plugin lets you lower the frequency.', 'blt-optimized' ),
					'impact'   => array(
						'type'  => 'count',
						'value' => count( $hooks ),
					),
					'where'    => $hooks,
					'details'  => array(
						'columns' => array( __( 'Hook', 'blt-optimized' ), __( 'Schedule', 'blt-optimized' ), __( 'Has a handler', 'blt-optimized' ), __( 'Likely owner', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => array(
						'type'  => 'manual',
						'label' => __( 'Lower the frequency', 'blt-optimized' ),
						'steps' => __( "Open the owning plugin's settings and choose a longer interval (hourly is plenty for most jobs). If there is no setting, ask the plugin's support whether the schedule can be changed.", 'blt-optimized' ),
					),
				)
			),
		);
	}

	/**
	 * Action Scheduler backlogs and stuck queues.
	 *
	 * @return array[]
	 */
	private function check_action_scheduler() {
		global $wpdb;
		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$logs    = $wpdb->prefix . 'actionscheduler_logs';
		if ( ! $this->table_exists( $actions ) ) {
			return array();
		}
		$has_logs = $this->table_exists( $logs );

		$by_status = array();
		foreach ( (array) $this->get_rows( "SELECT status, COUNT(*) AS cnt FROM {$actions} GROUP BY status" ) as $row ) {
			$by_status[ $row['status'] ] = (int) $row['cnt'];
		}
		$finished = 0;
		foreach ( array( 'complete', 'failed', 'canceled' ) as $status ) {
			$finished += isset( $by_status[ $status ] ) ? $by_status[ $status ] : 0;
		}
		$log_rows = $has_logs ? $this->bounded_count( $logs, self::AS_LOG_COUNT_CAP ) : 0;
		$owner    = BLT_Optimized_Components::make( 'plugin', 'action-scheduler', 'Action Scheduler' );
		$findings = array();

		if ( $finished > self::AS_FINISHED_ROWS || $log_rows > self::AS_LOG_ROWS ) {
			$days   = max( 1, (int) apply_filters( 'blt_optimized_action_scheduler_retention_days', self::AS_RETENTION_DAYS ) );
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
			$old    = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$actions} WHERE status IN ('complete','failed','canceled') AND scheduled_date_gmt < %s AND ( last_attempt_gmt IS NULL OR last_attempt_gmt < %s )",
					$cutoff,
					$cutoff
				)
			);

			$stats = $this->table_stats();
			$bytes = 0;
			foreach ( array( $actions, $logs ) as $table ) {
				if ( isset( $stats[ $table ] ) ) {
					$bytes += $stats[ $table ]['bytes'];
				}
			}
			$reclaim = ( $finished > 0 ) ? (int) round( $bytes * min( 1, $old / max( 1, $finished ) ) ) : 0;

			$rows = array();
			foreach ( $by_status as $status => $count ) {
				$rows[] = array( $status, number_format_i18n( $count ) );
			}
			$rows[] = array( __( 'Log rows', 'blt-optimized' ), $log_rows >= self::AS_LOG_COUNT_CAP ? number_format_i18n( self::AS_LOG_COUNT_CAP ) . '+' : number_format_i18n( $log_rows ) );
			/* translators: %d: retention days. */
			$rows[] = array( sprintf( __( 'Finished actions older than %d days (removable)', 'blt-optimized' ), $days ), number_format_i18n( $old ) );
			$rows[] = array( __( 'Table size (actions + logs)', 'blt-optimized' ), size_format( $bytes, 1 ) );

			$fix = $old > 0
				? array(
					'type'        => 'action',
					'label'       => __( 'Clean old actions and logs', 'blt-optimized' ),
					'action'      => 'cleanup',
					'args'        => array( 'category' => 'action_scheduler' ),
					'destructive' => true,
					'confirm'     => sprintf(
						/* translators: 1: action count, 2: days. */
						__( 'Permanently delete %1$s completed, failed and canceled Action Scheduler actions older than %2$d days, plus their log rows? Pending actions are never touched.', 'blt-optimized' ),
						number_format_i18n( $old ),
						$days
					),
				)
				: array(
					'type'  => 'manual',
					'label' => __( 'Shorten retention', 'blt-optimized' ),
					'steps' => __( "Every finished action is newer than the retention window, so there is nothing to purge yet. Either wait, or shorten Action Scheduler's retention with a snippet: add_filter( 'action_scheduler_retention_period', function () { return 7 * DAY_IN_SECONDS; } );\nThen run \"wp action-scheduler clean\" (if the WP-CLI command is available) or let the queue runner purge them.", 'blt-optimized' ),
				);

			$findings[] = $this->finding(
				array(
					'id'        => 'db.action_scheduler',
					'category'  => 'database',
					'severity'  => 'warning',
					'title'     => sprintf(
						/* translators: 1: finished action count, 2: log row count. */
						__( 'Action Scheduler holds %1$s finished actions and %2$s log rows', 'blt-optimized' ),
						number_format_i18n( $finished ),
						$log_rows >= self::AS_LOG_COUNT_CAP ? number_format_i18n( self::AS_LOG_COUNT_CAP ) . '+' : number_format_i18n( $log_rows )
					),
					'summary'   => __( 'WooCommerce and other plugins queue background jobs through Action Scheduler and it normally purges finished ones after 30 days. A backlog this large means the purge is not keeping up (often because the queue runner is stalled) and it bloats two tables that are queried constantly.', 'blt-optimized' ),
					'impact'    => array(
						'type'  => 'bytes',
						'value' => $reclaim,
						'label' => sprintf(
							/* translators: %s: size. */
							__( '~%s reclaimable', 'blt-optimized' ),
							size_format( $reclaim, 1 )
						),
					),
					'component' => $owner,
					'where'     => array( $actions, $logs ),
					'details'   => array(
						'columns' => array( __( 'Measure', 'blt-optimized' ), __( 'Value', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'       => $fix,
				)
			);
		}

		// Pending actions that should have run over an hour ago.
		$past_cutoff = gmdate( 'Y-m-d H:i:s', time() - self::AS_STUCK_SECONDS );
		$stuck       = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$actions} WHERE status = 'pending' AND scheduled_date_gmt < %s",
				$past_cutoff
			)
		);
		if ( $stuck > self::AS_STUCK_COUNT ) {
			$rows = array();
			foreach ( (array) $this->get_rows(
				$wpdb->prepare(
					"SELECT hook, COUNT(*) AS cnt, MIN(scheduled_date_gmt) AS oldest FROM {$actions}
					 WHERE status = 'pending' AND scheduled_date_gmt < %s GROUP BY hook ORDER BY cnt DESC LIMIT 8",
					$past_cutoff
				)
			) as $row ) {
				$oldest = strtotime( $row['oldest'] . ' UTC' );
				$o      = $this->guess_owner( $row['hook'], 'hook' );
				$rows[] = array(
					$row['hook'],
					number_format_i18n( (int) $row['cnt'] ),
					$oldest ? human_time_diff( $oldest ) : '-',
					$o ? BLT_Optimized_Components::label( $o ) : __( 'unknown', 'blt-optimized' ),
				);
			}
			$findings[] = $this->finding(
				array(
					'id'        => 'db.action_scheduler_stuck',
					'category'  => 'database',
					'severity'  => 'warning',
					/* translators: %s: action count. */
					'title'     => sprintf( __( 'Action Scheduler queue looks stuck: %s pending actions are over an hour late', 'blt-optimized' ), number_format_i18n( $stuck ) ),
					'summary'   => __( 'Background jobs (emails, stock and order syncs, webhooks, scheduled sales) are waiting far past their due time, so features that depend on them lag. The queue runner is not being triggered often enough or a job is failing repeatedly.', 'blt-optimized' ),
					'impact'    => array(
						'type'  => 'count',
						'value' => $stuck,
					),
					'component' => $owner,
					'where'     => array( $actions ),
					'details'   => array(
						'columns' => array( __( 'Hook', 'blt-optimized' ), __( 'Overdue actions', 'blt-optimized' ), __( 'Oldest due', 'blt-optimized' ), __( 'Likely owner', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'       => array(
						'type'  => 'manual',
						'label' => __( 'Get the queue running', 'blt-optimized' ),
						'steps' => __( "1. Open WooCommerce > Status > Scheduled Actions > Past-due and look at the failing hook.\n2. Make sure WP-Cron is running (see the WP-Cron findings) or that a real cron calls wp-cron.php.\n3. Process the backlog now with WP-CLI: wp action-scheduler run --batch-size=200", 'blt-optimized' ),
					),
				)
			);
		}

		return $findings;
	}

	/**
	 * Revision bloat: > 5,000 revisions overall or > 50 on a single post.
	 *
	 * @return array[]
	 */
	private function check_revisions() {
		global $wpdb;
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
		if ( 0 === $total ) {
			return array();
		}

		$groups = (array) $this->get_rows(
			"SELECT post_parent, COUNT(*) AS cnt FROM {$wpdb->posts} WHERE post_type = 'revision' GROUP BY post_parent ORDER BY cnt DESC LIMIT 10",
			self::QUERY_TIMEBOX
		);
		$max    = ! empty( $groups ) ? (int) $groups[0]['cnt'] : 0;
		if ( $total <= self::REVISIONS_TOTAL && $max <= self::REVISIONS_PER_POST ) {
			return array();
		}

		$keep = max( 0, (int) BLT_Optimized::get_settings()['revision_retention'] );

		$excess = (int) $this->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(cnt - %d),0) FROM ( SELECT COUNT(*) AS cnt FROM {$wpdb->posts} WHERE post_type = 'revision' GROUP BY post_parent HAVING cnt > %d ) t",
				$keep,
				$keep
			),
			self::QUERY_TIMEBOX
		);

		$bytes = 0;
		if ( ! $this->out_of_time() ) {
			$bytes = (int) $this->get_var(
				"SELECT COALESCE(SUM(LENGTH(post_content) + LENGTH(post_title) + LENGTH(post_excerpt)),0) FROM {$wpdb->posts} WHERE post_type = 'revision'",
				self::QUERY_TIMEBOX
			);
		}
		$reclaim = $total > 0 ? (int) round( $bytes * min( 1, $excess / $total ) ) : 0;

		// Titles for the worst offenders in one query.
		$titles = array();
		$ids    = array_map( 'intval', wp_list_pluck( $groups, 'post_parent' ) );
		if ( $ids ) {
			$in = implode( ',', $ids );
			foreach ( (array) $this->get_rows( "SELECT ID, post_title, post_type FROM {$wpdb->posts} WHERE ID IN ({$in})" ) as $row ) {
				$titles[ (int) $row['ID'] ] = $row;
			}
		}
		$rows = array();
		foreach ( $groups as $group ) {
			$id     = (int) $group['post_parent'];
			$row    = isset( $titles[ $id ] ) ? $titles[ $id ] : null;
			$rows[] = array(
				$row ? ( '' !== trim( $row['post_title'] ) ? $row['post_title'] : __( '(no title)', 'blt-optimized' ) ) : __( '(post no longer exists)', 'blt-optimized' ),
				$id,
				$row ? $row['post_type'] : '-',
				number_format_i18n( (int) $group['cnt'] ),
			);
		}

		$worst = ( ! empty( $groups ) && isset( $titles[ (int) $groups[0]['post_parent'] ] ) ) ? $titles[ (int) $groups[0]['post_parent'] ]['post_title'] : '';

		return array(
			$this->finding(
				array(
					'id'       => 'db.revisions',
					'category' => 'database',
					'severity' => 'warning',
					'title'    => sprintf(
						/* translators: 1: revision count, 2: max revisions on one post. */
						__( '%1$s post revisions, up to %2$s on a single post', 'blt-optimized' ),
						number_format_i18n( $total ),
						number_format_i18n( $max )
					),
					'summary'  => sprintf(
						/* translators: 1: worst post title (may be empty), 2: excess revision count, 3: retention number. */
						__( 'Every save stores a full copy of the post in wp_posts. %1$s Keeping the newest %3$s per post would remove %2$s revisions and make post queries and backups lighter.', 'blt-optimized' ),
						'' !== $worst ? sprintf(
							/* translators: %s: post title. */
							__( '“%s” has the most.', 'blt-optimized' ),
							$worst
						) : '',
						number_format_i18n( $excess ),
						number_format_i18n( $keep )
					),
					'impact'   => array(
						'type'  => 'bytes',
						'value' => $reclaim,
						'label' => sprintf(
							/* translators: %s: size. */
							__( '~%s reclaimable', 'blt-optimized' ),
							size_format( $reclaim, 1 )
						),
					),
					'where'    => array( $wpdb->posts . ' (post_type = revision)' ),
					'details'  => array(
						'columns' => array( __( 'Post', 'blt-optimized' ), __( 'ID', 'blt-optimized' ), __( 'Type', 'blt-optimized' ), __( 'Revisions', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => array(
						'type'        => 'action',
						'label'       => __( 'Delete excess revisions', 'blt-optimized' ),
						'action'      => 'cleanup',
						'args'        => array( 'category' => 'revisions' ),
						'destructive' => true,
						'confirm'     => sprintf(
							/* translators: 1: revision count, 2: retention number. */
							__( 'Permanently delete about %1$s revisions, keeping the newest %2$s per post (set in Settings)? The current published content of every post is unaffected.', 'blt-optimized' ),
							number_format_i18n( $excess ),
							number_format_i18n( $keep )
						),
					),
				)
			),
		);
	}

	/**
	 * Postmeta bloat: shows which meta keys hold the bytes when the table is
	 * over 50 MB or 500,000 rows.
	 *
	 * @return array[]
	 */
	private function check_postmeta() {
		global $wpdb;
		$stats = $this->table_stats();
		if ( isset( $stats[ $wpdb->postmeta ] ) ) {
			$bytes = $stats[ $wpdb->postmeta ]['bytes'];
			$rows  = $stats[ $wpdb->postmeta ]['rows_est'];
		} else {
			$bytes = 0;
			$rows  = $this->bounded_count( $wpdb->postmeta, self::POSTMETA_ROWS + 1 );
		}
		if ( $bytes <= self::POSTMETA_BYTES && $rows <= self::POSTMETA_ROWS ) {
			return array();
		}

		$table_rows = array();
		$top_key    = '';
		if ( ! $this->out_of_time() ) {
			$top = $this->get_rows(
				"SELECT meta_key, COUNT(*) AS cnt, COALESCE(SUM(LENGTH(meta_value)),0) AS bytes FROM {$wpdb->postmeta} GROUP BY meta_key ORDER BY bytes DESC LIMIT 10",
				self::QUERY_TIMEBOX * 2
			);
			foreach ( (array) $top as $row ) {
				$owner        = $this->guess_owner( $row['meta_key'], 'meta' );
				$top_key      = '' === $top_key ? $row['meta_key'] : $top_key;
				$table_rows[] = array(
					$row['meta_key'],
					number_format_i18n( (int) $row['cnt'] ),
					size_format( (int) $row['bytes'], 1 ),
					$owner ? BLT_Optimized_Components::label( $owner ) : __( 'unknown', 'blt-optimized' ),
				);
			}
		}

		return array(
			$this->finding(
				array(
					'id'        => 'db.postmeta',
					'category'  => 'database',
					'severity'  => 'notice',
					'title'     => sprintf(
						/* translators: 1: row count, 2: size. */
						__( 'Post meta table is large: about %1$s rows, %2$s', 'blt-optimized' ),
						number_format_i18n( $rows ),
						size_format( $bytes, 1 )
					),
					'summary'   => '' !== $top_key
						? sprintf(
							/* translators: %s: meta key. */
							__( 'Every post load pulls this table in, and meta-key lookups scan it. The heaviest key is “%s” — see the table for what else is taking space, then check the owning plugin\'s data-retention settings.', 'blt-optimized' ),
							$top_key
						)
						: __( 'Every post load pulls this table in, and meta-key lookups scan it. Check which plugins write the most meta.', 'blt-optimized' ),
					'impact'    => array(
						'type'  => 'bytes',
						'value' => $bytes,
					),
					'component' => '' !== $top_key ? $this->guess_owner( $top_key, 'meta' ) : null,
					'where'     => array( $wpdb->postmeta ),
					'details'   => array(
						'columns' => array( __( 'Meta key', 'blt-optimized' ), __( 'Rows', 'blt-optimized' ), __( 'Size of values', 'blt-optimized' ), __( 'Likely owner', 'blt-optimized' ) ),
						'rows'    => $table_rows,
					),
					'fix'       => array(
						'type'  => 'link',
						'label' => __( 'Open Database Cleanup', 'blt-optimized' ),
						'url'   => admin_url( 'admin.php?page=blt-optimized-cleanup' ),
					),
				)
			),
		);
	}

	/**
	 * Cached oEmbed responses stored in post meta.
	 *
	 * @return array[]
	 */
	private function check_oembed_cache() {
		global $wpdb;
		$rows  = $this->get_rows(
			$wpdb->prepare(
				"SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(meta_value)),0) AS bytes FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
				$wpdb->esc_like( '_oembed_' ) . '%'
			),
			self::QUERY_TIMEBOX
		);
		$row   = ! empty( $rows ) ? $rows[0] : null;
		$count = $row ? (int) $row['cnt'] : 0;
		$bytes = $row ? (int) $row['bytes'] : 0;
		if ( $count < self::OEMBED_ROWS && $bytes < self::OEMBED_BYTES ) {
			return array();
		}

		return array(
			$this->finding(
				array(
					'id'       => 'db.oembed_cache',
					'category' => 'database',
					'severity' => 'notice',
					'title'    => sprintf(
						/* translators: 1: row count, 2: size. */
						__( '%1$s cached oEmbed responses (%2$s) in post meta', 'blt-optimized' ),
						number_format_i18n( $count ),
						size_format( $bytes, 1 )
					),
					'summary'  => __( 'WordPress stores the HTML for every embedded video, tweet or link as a hidden _oembed_* post-meta row. They are pure cache — regenerated the next time the post is viewed — but they inflate the postmeta table.', 'blt-optimized' ),
					'impact'   => array(
						'type'  => 'bytes',
						'value' => $bytes,
					),
					'where'    => array( $wpdb->postmeta . ' (meta_key LIKE _oembed_%)' ),
					'details'  => $this->kv_details(
						array(
							array( __( 'Rows', 'blt-optimized' ), number_format_i18n( $count ) ),
							array( __( 'Size of values', 'blt-optimized' ), size_format( $bytes, 1 ) ),
						)
					),
					'fix'      => array(
						'type'  => 'link',
						'label' => __( 'Clear oEmbed cache', 'blt-optimized' ),
						'url'   => admin_url( 'admin.php?page=blt-optimized-cleanup' ),
					),
				)
			),
		);
	}

	/**
	 * Reclaimable table overhead (data_free).
	 *
	 * @return array[]
	 */
	private function check_table_overhead() {
		global $wpdb;
		$stats = $this->table_stats();
		if ( empty( $stats ) ) {
			return array();
		}

		// With a shared InnoDB tablespace every table reports the whole
		// tablespace's free space — counting it per table would multiply it.
		$file_per_table = '0' !== (string) $wpdb->get_var( 'SELECT @@innodb_file_per_table' );

		$overhead   = 0;
		$innodb_max = 0;
		$rows_src   = array();
		foreach ( $stats as $table ) {
			if ( $table['data_free'] <= 0 ) {
				continue;
			}
			// InnoDB pre-extends each table file by a few MB, so every WooCommerce
			// table "has" free space; only count tables where it is substantial.
			if ( 'InnoDB' === $table['engine'] && $file_per_table && $table['data_free'] < self::TABLE_OVERHEAD_MIN ) {
				continue;
			}
			if ( 'InnoDB' === $table['engine'] && ! $file_per_table ) {
				$innodb_max = max( $innodb_max, $table['data_free'] );
				continue;
			}
			$overhead  += $table['data_free'];
			$rows_src[] = $table;
		}
		$overhead += $innodb_max;

		if ( $overhead <= self::TABLE_OVERHEAD ) {
			return array();
		}

		usort(
			$rows_src,
			static function ( $a, $b ) {
				return $b['data_free'] <=> $a['data_free'];
			}
		);
		$rows = array();
		foreach ( array_slice( $rows_src, 0, 10 ) as $table ) {
			$rows[] = array(
				$table['name'],
				$table['engine'],
				size_format( $table['bytes'], 1 ),
				size_format( $table['data_free'], 1 ),
			);
		}

		return array(
			$this->finding(
				array(
					'id'       => 'db.table_overhead',
					'category' => 'database',
					'severity' => 'notice',
					/* translators: %s: size. */
					'title'    => sprintf( __( '%s of reclaimable overhead is stranded in database tables', 'blt-optimized' ), size_format( $overhead, 1 ) ),
					'summary'  => $file_per_table
						? __( 'Deleted rows leave free space inside the table files (fragmentation). OPTIMIZE TABLE rebuilds them smaller, which shortens scans and backups.', 'blt-optimized' )
						: __( 'The shared InnoDB tablespace holds this much unused space. OPTIMIZE TABLE will not shrink a shared tablespace; it only helps MyISAM tables.', 'blt-optimized' ),
					'impact'   => array(
						'type'  => 'bytes',
						'value' => $overhead,
					),
					'where'    => wp_list_pluck( array_slice( $rows_src, 0, 10 ), 'name' ),
					'details'  => array(
						'columns' => array( __( 'Table', 'blt-optimized' ), __( 'Engine', 'blt-optimized' ), __( 'Size', 'blt-optimized' ), __( 'Overhead', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => array(
						'type'  => 'link',
						'label' => __( 'Optimize tables', 'blt-optimized' ),
						'url'   => admin_url( 'admin.php?page=blt-optimized-db' ),
					),
				)
			),
		);
	}

	/**
	 * Remaining MyISAM tables.
	 *
	 * @return array[]
	 */
	private function check_myisam() {
		$myisam = array();
		foreach ( $this->table_stats() as $table ) {
			if ( $table['is_myisam'] ) {
				$myisam[] = $table;
			}
		}
		if ( empty( $myisam ) ) {
			return array();
		}

		$rows = array();
		foreach ( array_slice( $myisam, 0, 15 ) as $table ) {
			$rows[] = array( $table['name'], size_format( $table['bytes'], 1 ), number_format_i18n( $table['rows_est'] ) );
		}
		$alter = array();
		foreach ( array_slice( $myisam, 0, 5 ) as $table ) {
			$alter[] = 'ALTER TABLE `' . str_replace( '`', '', $table['name'] ) . '` ENGINE=InnoDB;';
		}

		return array(
			$this->finding(
				array(
					'id'       => 'db.myisam',
					'category' => 'database',
					'severity' => 'notice',
					'title'    => 1 === count( $myisam )
						/* translators: %s: table name. */
						? sprintf( __( 'Table “%s” still uses the MyISAM engine', 'blt-optimized' ), $myisam[0]['name'] )
						: sprintf(
							/* translators: 1: table count, 2: first table name. */
							__( '%1$s tables still use the MyISAM engine (including “%2$s”)', 'blt-optimized' ),
							number_format_i18n( count( $myisam ) ),
							$myisam[0]['name']
						),
					'summary'  => __( 'MyISAM locks the whole table for every write and is not crash-safe, so a busy site queues readers behind writers. InnoDB uses row-level locking and caches data far better.', 'blt-optimized' ),
					'impact'   => array(
						'type'  => 'count',
						'value' => count( $myisam ),
						'label' => sprintf(
							/* translators: %s: table count. */
							_n( '%s table', '%s tables', count( $myisam ), 'blt-optimized' ),
							number_format_i18n( count( $myisam ) )
						),
					),
					'where'    => wp_list_pluck( array_slice( $myisam, 0, 15 ), 'name' ),
					'details'  => array(
						'columns' => array( __( 'Table', 'blt-optimized' ), __( 'Size', 'blt-optimized' ), __( 'Rows (est.)', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => array(
						'type'  => 'manual',
						'label' => __( 'Convert to InnoDB', 'blt-optimized' ),
						'steps' => __( "1. Take a database backup.\n2. In phpMyAdmin (SQL tab) or WP-CLI (wp db query) run, one table at a time:\n", 'blt-optimized' ) . implode( "\n", $alter ) . ( count( $myisam ) > 5 ? "\n" . __( '...and the same for the remaining tables listed above.', 'blt-optimized' ) : '' ),
					),
				)
			),
		);
	}

	/**
	 * Large non-core tables, each attributed to the plugin that likely owns it.
	 *
	 * @return array[]
	 */
	private function check_large_tables() {
		global $wpdb;
		$stats = $this->table_stats();
		if ( empty( $stats ) ) {
			return array();
		}

		$core = array();
		foreach ( $wpdb->tables( 'all', true ) as $name ) {
			$core[ strtolower( $name ) ] = true;
		}

		$findings = array();
		foreach ( $stats as $table ) { // Sorted largest first.
			if ( $table['bytes'] < self::LARGE_TABLE_BYTES ) {
				break;
			}
			if ( isset( $core[ strtolower( $table['name'] ) ] ) ) {
				continue;
			}

			$owner = $this->table_owner( $table['name'] );
			$label = $owner ? $owner['name'] : __( 'unknown plugin', 'blt-optimized' );
			$steps = $owner
				? sprintf(
					/* translators: 1: plugin label, 2: table name. */
					__( "1. Open %1\$s's settings and look for a data-retention, log-cleanup or statistics-purge option.\n2. If it has none, ask the plugin's support how to prune %2\$s.\n3. After pruning, run OPTIMIZE TABLE from DB Optimization to give the space back.", 'blt-optimized' ),
					$label,
					$table['name']
				)
				: sprintf(
					/* translators: %s: table name. */
					__( "1. Work out which plugin created %s (search the plugins' code for the table name).\n2. If that plugin is no longer installed, back up the database and drop the table; otherwise prune it from the plugin's settings.\n3. Then run OPTIMIZE TABLE from DB Optimization.", 'blt-optimized' ),
					$table['name']
				);

			$findings[] = $this->finding(
				array(
					'id'        => 'db.large_table',
					'key'       => 'db.large_table:' . $table['name'],
					'category'  => 'database',
					'severity'  => 'notice',
					'title'     => sprintf(
						/* translators: 1: table name, 2: size, 3: owner label. */
						__( 'Table “%1$s” is %2$s (%3$s)', 'blt-optimized' ),
						$table['name'],
						size_format( $table['bytes'], 1 ),
						$label
					),
					'summary'   => __( 'Large plugin tables slow backups, migrations and any query that scans them. They usually hold logs, statistics or caches that can be pruned without losing real content.', 'blt-optimized' ),
					'impact'    => array(
						'type'  => 'bytes',
						'value' => $table['bytes'],
					),
					'component' => $owner,
					'where'     => array( $table['name'] ),
					'details'   => $this->kv_details(
						array(
							array( __( 'Table', 'blt-optimized' ), $table['name'] ),
							array( __( 'Size (data + indexes)', 'blt-optimized' ), size_format( $table['bytes'], 1 ) ),
							array( __( 'Rows (estimate)', 'blt-optimized' ), number_format_i18n( $table['rows_est'] ) ),
							array( __( 'Engine', 'blt-optimized' ), $table['engine'] ),
							array( __( 'Reclaimable overhead', 'blt-optimized' ), size_format( $table['data_free'], 1 ) ),
							array( __( 'Likely owner', 'blt-optimized' ), $label ),
						)
					),
					'fix'       => array(
						'type'  => 'manual',
						'label' => __( 'Prune the table', 'blt-optimized' ),
						'steps' => $steps,
					),
				)
			);

			if ( count( $findings ) >= self::LARGE_TABLE_CAP ) {
				break;
			}
		}

		return $findings;
	}

	/**
	 * Row count of wp_options with the biggest name families as evidence.
	 *
	 * @return array[]
	 */
	private function check_options_rows() {
		global $wpdb;
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );
		if ( $count <= self::OPTIONS_ROWS ) {
			return array();
		}

		$rows = array();
		if ( ! $this->out_of_time() ) {
			// Group by the first two name segments, ignoring the transient wrappers.
			$family = "SUBSTRING_INDEX( TRIM(LEADING '_' FROM CASE
				WHEN option_name LIKE '\\_site\\_transient\\_timeout\\_%' THEN SUBSTRING(option_name, 25)
				WHEN option_name LIKE '\\_site\\_transient\\_%' THEN SUBSTRING(option_name, 17)
				WHEN option_name LIKE '\\_transient\\_timeout\\_%' THEN SUBSTRING(option_name, 20)
				WHEN option_name LIKE '\\_transient\\_%' THEN SUBSTRING(option_name, 12)
				ELSE option_name END), '_', 2 )";
			$top    = $this->get_rows(
				"SELECT {$family} AS family, COUNT(*) AS cnt, COALESCE(SUM(LENGTH(option_value)),0) AS bytes
				 FROM {$wpdb->options} GROUP BY family ORDER BY cnt DESC LIMIT 8",
				self::QUERY_TIMEBOX
			);
			foreach ( (array) $top as $row ) {
				$owner  = $this->guess_owner( $row['family'] );
				$rows[] = array(
					$row['family'] . '*',
					number_format_i18n( (int) $row['cnt'] ),
					size_format( (int) $row['bytes'], 1 ),
					$owner ? BLT_Optimized_Components::label( $owner ) : __( 'unknown', 'blt-optimized' ),
				);
			}
		}
		$lead = ! empty( $rows ) ? rtrim( $rows[0][0], '*' ) : '';

		return array(
			$this->finding(
				array(
					'id'        => 'db.options_rows',
					'category'  => 'database',
					'severity'  => 'notice',
					/* translators: %s: row count. */
					'title'     => sprintf( __( 'The options table has %s rows', 'blt-optimized' ), number_format_i18n( $count ) ),
					'summary'   => '' !== $lead
						? sprintf(
							/* translators: %s: option name prefix. */
							__( 'A healthy site has a few hundred to a few thousand. Most of the rows here start with “%s”. Large options tables slow every option lookup and the autoload query that runs on each request.', 'blt-optimized' ),
							$lead
						)
						: __( 'A healthy site has a few hundred to a few thousand. Large options tables slow every option lookup and the autoload query that runs on each request.', 'blt-optimized' ),
					'impact'    => array(
						'type'  => 'count',
						'value' => $count,
					),
					'component' => '' !== $lead ? $this->guess_owner( $lead ) : null,
					'where'     => array( $wpdb->options ),
					'details'   => array(
						'columns' => array( __( 'Name family', 'blt-optimized' ), __( 'Rows', 'blt-optimized' ), __( 'Size', 'blt-optimized' ), __( 'Likely owner', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'       => array(
						'type'  => 'link',
						'label' => __( 'Open Database Cleanup', 'blt-optimized' ),
						'url'   => admin_url( 'admin.php?page=blt-optimized-cleanup' ),
					),
				)
			),
		);
	}

	// phpcs:enable

	/* ================================================================== */
	/* STORAGE (from the last disk scan)                                   */
	/* ================================================================== */

	/**
	 * Space hogs flagged by the last disk scan, one finding per item >= 10 MB.
	 *
	 * @return array[]
	 */
	private function check_storage() {
		$scan = $this->scanner->get_last_scan();
		if ( ! $scan ) {
			return array(
				$this->finding(
					array(
						'id'       => 'storage.no_scan',
						'category' => 'storage',
						'severity' => 'notice',
						'title'    => __( 'Run a disk scan to include folder-level storage findings', 'blt-optimized' ),
						'summary'  => __( 'No disk scan has been run yet, so backups, log files, leftover plugin folders and cache directories are not part of this report. A scan walks wp-content once and takes seconds to a few minutes.', 'blt-optimized' ),
						'source'   => 'disk',
						'fix'      => array(
							'type'  => 'link',
							'label' => __( 'Run a disk scan', 'blt-optimized' ),
							'url'   => admin_url( 'admin.php?page=blt-optimized-disk' ),
						),
					)
				),
			);
		}

		$labels = array(
			'archive-file'           => __( 'archive file', 'blt-optimized' ),
			'log-file'               => __( 'log file', 'blt-optimized' ),
			'orphaned-plugin-folder' => __( 'folder of a plugin that is not installed', 'blt-optimized' ),
			'inactive-theme'         => __( 'inactive theme', 'blt-optimized' ),
		);
		foreach ( (array) $this->scanner->get_signatures() as $signature ) {
			if ( isset( $signature['slug'], $signature['label'] ) && in_array( $signature['category'], array( 'backup-archives', 'cache', 'logs', 'dev-artifacts' ), true ) ) {
				$labels[ $signature['slug'] ] = $signature['label'];
			}
		}

		// Flagged items of at least the minimum size.
		$eligible = array();
		foreach ( (array) $this->scanner->get_top_hogs( 50 ) as $hog ) {
			if ( (int) $hog['size_bytes'] < self::STORAGE_MIN_BYTES ) {
				break; // Sorted by size.
			}
			$hit = array_values( array_intersect( array_filter( explode( ',', (string) $hog['flags'] ) ), array_keys( $labels ) ) );
			if ( ! empty( $hit ) ) {
				$eligible[] = array(
					'hog' => $hog,
					'hit' => $hit,
				);
			}
		}

		$labels['cache-dir'] = __( 'cache folder', 'blt-optimized' );

		$findings = array();
		foreach ( $eligible as $item ) {
			$hog   = $item['hog'];
			$hit   = $item['hit'];
			$bytes = (int) $hog['size_bytes'];
			$path  = (string) $hog['path'];

			// A flagged folder already covers everything inside it.
			foreach ( $eligible as $other ) {
				if ( $other['hog']['path'] !== $path && 0 === strpos( $path . '/', $other['hog']['path'] . '/' ) ) {
					continue 2;
				}
			}

			$flag      = $hit[0];
			$is_file   = 'file' === $hog['item_type'];
			$abs       = untrailingslashit( wp_normalize_path( ABSPATH ) ) . '/' . $path;
			$component = $this->storage_component( $flag, $abs );

			switch ( $flag ) {
				case 'archive-file':
					/* translators: 1: file path, 2: size. */
					$title = sprintf( __( 'Archive file “%1$s” takes %2$s', 'blt-optimized' ), $path, size_format( $bytes, 1 ) );
					$why   = __( 'Backup and export archives left on the server are copies of the site that count against disk quota and make every host-side backup bigger. Download it somewhere safe, then delete it.', 'blt-optimized' );
					break;
				case 'log-file':
					/* translators: 1: file path, 2: size. */
					$title = sprintf( __( 'Log file “%1$s” has grown to %2$s', 'blt-optimized' ), $path, size_format( $bytes, 1 ) );
					$why   = __( 'Logs are appended forever by default. Find out what is filling it, fix or silence that, then delete or rotate the file.', 'blt-optimized' );
					break;
				case 'orphaned-plugin-folder':
					/* translators: 1: folder path, 2: size. */
					$title = sprintf( __( 'Plugin folder “%1$s” (%2$s) is on disk but not installed', 'blt-optimized' ), $path, size_format( $bytes, 1 ) );
					$why   = __( 'WordPress does not list this folder as a plugin, so it is dead weight — typically left behind when a plugin was deleted over FTP. Confirm nothing references it, then remove it.', 'blt-optimized' );
					break;
				case 'inactive-theme':
					/* translators: 1: folder path, 2: size. */
					$title = sprintf( __( 'Inactive theme “%1$s” uses %2$s', 'blt-optimized' ), $path, size_format( $bytes, 1 ) );
					$why   = __( 'Installed but unused themes still take disk space and need security updates. Keep one default theme as a fallback and delete the rest from Appearance > Themes.', 'blt-optimized' );
					break;
				default:
					/* translators: 1: folder path, 2: size, 3: what it is. */
					$title = sprintf( __( '“%1$s” takes %2$s (%3$s)', 'blt-optimized' ), $path, size_format( $bytes, 1 ), $labels[ $flag ] );
					$why   = __( 'Backup sets, caches and generated files accumulate silently. Review the folder, keep what you need and delete the rest.', 'blt-optimized' );
			}

			$findings[] = $this->finding(
				array(
					'id'        => 'storage.hog',
					'key'       => 'storage.hog:' . $path,
					'category'  => 'storage',
					'severity'  => $bytes >= self::STORAGE_CRITICAL ? 'critical' : ( $bytes >= self::STORAGE_WARNING ? 'warning' : 'notice' ),
					'title'     => $title,
					'summary'   => $why,
					'impact'    => array(
						'type'  => 'bytes',
						'value' => $bytes,
					),
					'component' => $component,
					'where'     => array( $path ),
					'details'   => $this->kv_details(
						array(
							array( __( 'Path', 'blt-optimized' ), $path ),
							array( __( 'Type', 'blt-optimized' ), $is_file ? __( 'file', 'blt-optimized' ) : __( 'folder', 'blt-optimized' ) ),
							array( __( 'Size', 'blt-optimized' ), size_format( $bytes, 1 ) ),
							array( __( 'Files inside', 'blt-optimized' ), number_format_i18n( (int) $hog['file_count'] ) ),
							array( __( 'Flagged as', 'blt-optimized' ), implode( ', ', array_map( static function ( $f ) use ( $labels ) {
								return isset( $labels[ $f ] ) ? $labels[ $f ] : $f;
							}, $hit ) ) ),
							array( __( 'Measured', 'blt-optimized' ), ! empty( $scan['completed_at'] ) ? sprintf(
								/* translators: %s: time span. */
								__( 'in the disk scan from %s ago', 'blt-optimized' ),
								human_time_diff( (int) $scan['completed_at'] )
							) : '-' ),
						)
					),
					'source'    => 'disk',
					'fix'       => array(
						'type'  => 'link',
						'label' => __( 'Open Disk Usage', 'blt-optimized' ),
						'url'   => admin_url( 'admin.php?page=blt-optimized-disk' ),
					),
				)
			);

			if ( count( $findings ) >= self::STORAGE_CAP ) {
				break;
			}
		}

		return $findings;
	}

	/* ================================================================== */
	/* Shared lookups                                                      */
	/* ================================================================== */

	/**
	 * Autoloaded options audit (memoised).
	 *
	 * @return array
	 */
	private function autoload() {
		if ( ! isset( $this->memo['autoload'] ) ) {
			$this->memo['autoload'] = $this->db->autoload_audit( 50 );
		}
		return $this->memo['autoload'];
	}

	/**
	 * Table stats keyed by table name (memoised).
	 *
	 * @return array[]
	 */
	private function table_stats() {
		if ( ! isset( $this->memo['tables'] ) ) {
			$this->memo['tables'] = array();
			foreach ( (array) $this->db->get_table_stats() as $table ) {
				$this->memo['tables'][ $table['name'] ] = $table;
			}
		}
		return $this->memo['tables'];
	}

	/**
	 * Transient counts in wp_options (memoised): value rows and bytes,
	 * expired value rows and bytes.
	 *
	 * @return array { rows, bytes, expired, expired_bytes }
	 */
	private function transient_stats() {
		if ( isset( $this->memo['transients'] ) ) {
			return $this->memo['transients'];
		}
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$all = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(option_value)),0) AS bytes FROM {$wpdb->options}
				 WHERE ( option_name LIKE %s AND option_name NOT LIKE %s ) OR ( option_name LIKE %s AND option_name NOT LIKE %s )",
				$wpdb->esc_like( '_transient_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$wpdb->esc_like( '_site_transient_' ) . '%',
				$wpdb->esc_like( '_site_transient_timeout_' ) . '%'
			),
			ARRAY_A
		);

		$expired       = 0;
		$expired_bytes = 0;
		$now           = time();
		foreach ( array(
			array( '_transient_', 20 ),
			array( '_site_transient_', 25 ),
		) as $variant ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(v.option_value)),0) AS bytes
					 FROM {$wpdb->options} t
					 INNER JOIN {$wpdb->options} v ON v.option_name = CONCAT(%s, SUBSTRING(t.option_name, %d))
					 WHERE t.option_name LIKE %s AND CAST(t.option_value AS UNSIGNED) < %d",
					$variant[0],
					$variant[1],
					$wpdb->esc_like( $variant[0] . 'timeout_' ) . '%',
					$now
				),
				ARRAY_A
			);
			if ( $row ) {
				$expired       += (int) $row['cnt'];
				$expired_bytes += (int) $row['bytes'];
			}
		}
		// phpcs:enable

		$this->memo['transients'] = array(
			'rows'          => $all ? (int) $all['cnt'] : 0,
			'bytes'         => $all ? (int) $all['bytes'] : 0,
			'expired'       => $expired,
			'expired_bytes' => $expired_bytes,
		);
		return $this->memo['transients'];
	}

	/**
	 * Cron array digested per hook (memoised).
	 *
	 * hooks[ hook ] = { events, next, schedule (label), interval (seconds, 0 for
	 * one-off), args, has_callback }, sorted by event count descending.
	 *
	 * @return array { total, due, overdue_events, overdue_hooks, hooks }
	 */
	private function cron_info() {
		if ( isset( $this->memo['cron'] ) ) {
			return $this->memo['cron'];
		}

		$now     = time();
		$hooks   = array();
		$total   = 0;
		$due     = 0;
		$overdue = 0;
		$late    = array();

		foreach ( (array) _get_cron_array() as $timestamp => $events ) {
			if ( ! is_array( $events ) ) {
				continue;
			}
			foreach ( $events as $hook => $instances ) {
				if ( ! is_array( $instances ) ) {
					continue;
				}
				foreach ( $instances as $instance ) {
					++$total;
					$interval = isset( $instance['interval'] ) ? (int) $instance['interval'] : 0;
					$schedule = $this->schedule_label( isset( $instance['schedule'] ) ? $instance['schedule'] : false, $interval );

					if ( ! isset( $hooks[ $hook ] ) ) {
						$hooks[ $hook ] = array(
							'events'       => 0,
							'next'         => (int) $timestamp,
							'schedule'     => $schedule,
							'interval'     => $interval,
							'args'         => isset( $instance['args'] ) ? (array) $instance['args'] : array(),
							'has_callback' => false,
						);
					}
					++$hooks[ $hook ]['events'];
					$hooks[ $hook ]['next'] = min( $hooks[ $hook ]['next'], (int) $timestamp );

					if ( $timestamp <= $now ) {
						++$due;
					}
					if ( $timestamp < $now - self::CRON_OVERDUE_SECONDS ) {
						++$overdue;
						if ( ! isset( $late[ $hook ] ) ) {
							$late[ $hook ] = array(
								'count'    => 0,
								'oldest'   => (int) $timestamp,
								'schedule' => $schedule,
							);
						}
						++$late[ $hook ]['count'];
						$late[ $hook ]['oldest'] = min( $late[ $hook ]['oldest'], (int) $timestamp );
					}
				}
			}
		}

		foreach ( $hooks as $hook => $data ) {
			$hooks[ $hook ]['has_callback'] = (bool) has_action( $hook );
		}

		uasort(
			$hooks,
			static function ( $a, $b ) {
				return $b['events'] <=> $a['events'];
			}
		);
		uasort(
			$late,
			static function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);

		$this->memo['cron'] = array(
			'total'          => $total,
			'due'            => $due,
			'overdue_events' => $overdue,
			'overdue_hooks'  => $late,
			'hooks'          => $hooks,
		);
		return $this->memo['cron'];
	}

	/**
	 * Installed plugins keyed by directory slug (memoised): name and active flag.
	 *
	 * @return array<string,array>
	 */
	private function installed_plugins() {
		if ( isset( $this->memo['plugins'] ) ) {
			return $this->memo['plugins'];
		}
		$out = array();
		if ( ! function_exists( 'get_plugins' ) && is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( function_exists( 'get_plugins' ) ) {
			$active  = (array) get_option( 'active_plugins', array() );
			$network = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
			foreach ( get_plugins() as $file => $data ) {
				$dir  = dirname( $file );
				$slug = '.' === $dir ? preg_replace( '/\.php$/', '', $file ) : $dir;
				if ( isset( $out[ $slug ] ) ) {
					continue;
				}
				$out[ $slug ] = array(
					'name'   => ! empty( $data['Name'] ) ? $data['Name'] : $slug,
					'active' => in_array( $file, $active, true ) || in_array( $file, $network, true ),
				);
			}
		}
		$this->memo['plugins'] = $out;
		return $out;
	}

	/**
	 * Whether WooCommerce is loaded.
	 *
	 * @return bool
	 */
	private function woo_active() {
		return class_exists( 'WooCommerce' ) || function_exists( 'WC' );
	}

	/**
	 * Whether a table exists (memoised; name built from the site prefix).
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	private function table_exists( $table ) {
		if ( ! isset( $this->memo['exists'][ $table ] ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->memo['exists'][ $table ] = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		}
		return $this->memo['exists'][ $table ];
	}

	/**
	 * Row count capped at $cap, without scanning more than $cap rows.
	 *
	 * @param string $table Validated table name.
	 * @param int    $cap   Maximum count returned.
	 * @return int
	 */
	private function bounded_count( $table, $cap ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $this->get_var( 'SELECT COUNT(*) FROM ( SELECT 1 FROM `' . str_replace( '`', '', $table ) . '` LIMIT ' . (int) $cap . ' ) t', self::QUERY_TIMEBOX );
	}

	/**
	 * Whether the time budget for optional deep-dive queries is spent.
	 *
	 * @return bool
	 */
	private function out_of_time() {
		return ( microtime( true ) - $this->started ) > self::TIME_BUDGET;
	}

	/**
	 * Wrap a SELECT so the database aborts it after $seconds (MariaDB
	 * max_statement_time, MySQL MAX_EXECUTION_TIME hint).
	 *
	 * @param string $sql     SELECT statement.
	 * @param int    $seconds Limit in seconds; 0 leaves the query unchanged.
	 * @return string
	 */
	private function timebox_sql( $sql, $seconds ) {
		if ( $seconds <= 0 ) {
			return $sql;
		}
		if ( ! isset( $this->memo['mariadb'] ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->memo['mariadb'] = false !== stripos( (string) $wpdb->get_var( 'SELECT VERSION()' ), 'mariadb' );
		}
		if ( $this->memo['mariadb'] ) {
			return 'SET STATEMENT max_statement_time=' . (int) $seconds . ' FOR ' . $sql;
		}
		return (string) preg_replace( '/^\s*SELECT\s/i', 'SELECT /*+ MAX_EXECUTION_TIME(' . ( (int) $seconds * 1000 ) . ') */ ', $sql, 1 );
	}

	/**
	 * Run a SELECT returning rows, optionally time-boxed. Errors (including a
	 * timeout) are swallowed and yield an empty result.
	 *
	 * @param string $sql     Prepared SELECT.
	 * @param int    $seconds Time box in seconds (0 = none).
	 * @return array[]
	 */
	private function get_rows( $sql, $seconds = 0 ) {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $this->timebox_sql( $sql, $seconds ), ARRAY_A );
		$wpdb->suppress_errors( $suppress );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Run a SELECT returning one value, optionally time-boxed.
	 *
	 * @param string $sql     Prepared SELECT.
	 * @param int    $seconds Time box in seconds (0 = none).
	 * @return string|null
	 */
	private function get_var( $sql, $seconds = 0 ) {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$value = $wpdb->get_var( $this->timebox_sql( $sql, $seconds ) );
		$wpdb->suppress_errors( $suppress );
		return $value;
	}

	/* ================================================================== */
	/* Attribution                                                         */
	/* ================================================================== */

	/**
	 * Known name-prefix -> plugin map used to attribute options, tables, cron
	 * hooks and meta keys. Prefixes are matched case-insensitively against the
	 * name with any table prefix and leading underscores removed.
	 *
	 * @return array<string,array> prefix => { slug, name, pattern? }
	 */
	public static function default_owners() {
		return array(
			'wfls_'            => array(
				'slug' => 'wordfence',
				'name' => 'Wordfence',
			),
			'wf'               => array(
				'slug'    => 'wordfence',
				'name'    => 'Wordfence',
				'pattern' => '/^wf[A-Z]/', // wfHits, wfConfig, wfBlockedIPLog ... (case-sensitive).
			),
			'wordfence'        => array(
				'slug' => 'wordfence',
				'name' => 'Wordfence',
			),
			'actionscheduler_' => array(
				'slug' => 'action-scheduler',
				'name' => 'Action Scheduler',
			),
			'action_scheduler' => array(
				'slug' => 'action-scheduler',
				'name' => 'Action Scheduler',
			),
			'wc_'              => array(
				'slug' => 'woocommerce',
				'name' => 'WooCommerce',
			),
			'woocommerce_'     => array(
				'slug' => 'woocommerce',
				'name' => 'WooCommerce',
			),
			'redirection_'     => array(
				'slug' => 'redirection',
				'name' => 'Redirection',
			),
			'yoast_'           => array(
				'slug' => 'wordpress-seo',
				'name' => 'Yoast SEO',
			),
			'wpseo'            => array(
				'slug' => 'wordpress-seo',
				'name' => 'Yoast SEO',
			),
			'rank_math'        => array(
				'slug' => 'seo-by-rank-math',
				'name' => 'Rank Math SEO',
			),
			'aioseo'           => array(
				'slug' => 'all-in-one-seo-pack',
				'name' => 'All in One SEO',
			),
			'e_'               => array(
				'slug' => 'elementor',
				'name' => 'Elementor',
			),
			'elementor'        => array(
				'slug' => 'elementor',
				'name' => 'Elementor',
			),
			'icl_'             => array(
				'slug' => 'sitepress-multilingual-cms',
				'name' => 'WPML',
			),
			'gf_'              => array(
				'slug' => 'gravityforms',
				'name' => 'Gravity Forms',
			),
			'gform_'           => array(
				'slug' => 'gravityforms',
				'name' => 'Gravity Forms',
			),
			'rg_'              => array(
				'slug' => 'gravityforms',
				'name' => 'Gravity Forms',
			),
			'wpforms'          => array(
				'slug' => 'wpforms-lite',
				'name' => 'WPForms',
			),
			'wpcf7'            => array(
				'slug' => 'contact-form-7',
				'name' => 'Contact Form 7',
			),
			'litespeed'        => array(
				'slug' => 'litespeed-cache',
				'name' => 'LiteSpeed Cache',
			),
			'mailpoet_'        => array(
				'slug' => 'mailpoet',
				'name' => 'MailPoet',
			),
			'statistics'       => array(
				'slug' => 'wp-statistics',
				'name' => 'WP Statistics',
			),
			'statistic'        => array(
				'slug' => 'wp-statistics',
				'name' => 'WP Statistics',
			),
			'jetpack'          => array(
				'slug' => 'jetpack',
				'name' => 'Jetpack',
			),
			'itsec_'           => array(
				'slug' => 'better-wp-security',
				'name' => 'Solid Security',
			),
			'wp_rocket'        => array(
				'slug' => 'wp-rocket',
				'name' => 'WP Rocket',
			),
			'wpr_'             => array(
				'slug' => 'wp-rocket',
				'name' => 'WP Rocket',
			),
			'nf3_'             => array(
				'slug' => 'ninja-forms',
				'name' => 'Ninja Forms',
			),
			'frm_'             => array(
				'slug' => 'formidable',
				'name' => 'Formidable Forms',
			),
			'blt_optimized'    => array(
				'slug' => 'blt-optimized',
				'name' => 'BLT Optimized',
			),
		);
	}

	/**
	 * The owner map after the `blt_optimized_table_owners` filter, normalised
	 * and sorted longest prefix first (memoised).
	 *
	 * @return array<string,array>
	 */
	private function owner_map() {
		if ( isset( $this->memo['owner_map'] ) ) {
			return $this->memo['owner_map'];
		}

		/**
		 * Filter the name-prefix to plugin map used to attribute database
		 * tables, options, cron hooks and meta keys to a plugin. Each entry is
		 * `prefix => array( 'slug' => 'plugin-dir', 'name' => 'Plugin Name' )`,
		 * optionally with a case-sensitive regex under 'pattern' that the raw name
		 * must also match. A plain string value is treated as the slug.
		 *
		 * @param array $owners Prefix map.
		 */
		$owners = apply_filters( 'blt_optimized_table_owners', self::default_owners() );

		$map = array();
		foreach ( (array) $owners as $prefix => $info ) {
			if ( is_string( $info ) ) {
				$info = array(
					'slug' => $info,
					'name' => $info,
				);
			}
			if ( ! is_array( $info ) || empty( $info['slug'] ) || '' === (string) $prefix ) {
				continue;
			}
			$map[ strtolower( (string) $prefix ) ] = array(
				'slug'    => (string) $info['slug'],
				'name'    => ! empty( $info['name'] ) ? (string) $info['name'] : (string) $info['slug'],
				'pattern' => ! empty( $info['pattern'] ) ? (string) $info['pattern'] : '',
			);
		}
		uksort(
			$map,
			static function ( $a, $b ) {
				return strlen( $b ) <=> strlen( $a );
			}
		);

		$this->memo['owner_map'] = $map;
		return $map;
	}

	/**
	 * Whether a cron hook's name points at a plugin that is installed and
	 * active. Such a hook may only look orphaned: some plugins register their
	 * handler only while WP-Cron runs, which an admin request never sees.
	 *
	 * @param string $hook Cron hook name.
	 * @return bool
	 */
		public function cron_hook_owner_active( $hook ) {
		$owner = $this->guess_owner( $hook, 'hook' );
		if ( ! $owner || 'plugin' !== $owner['type'] ) {
			return false;
		}
		$plugins = $this->installed_plugins();
		return isset( $plugins[ $owner['slug'] ] ) && ! empty( $plugins[ $owner['slug'] ]['active'] );
	}

	/**
	 * Best-effort owner of an option, hook, meta key or table suffix, from its
	 * name alone: the known-prefix map first, then installed plugin slugs, then
	 * the active theme, then WordPress core for names that are plainly core's.
	 * Returns null when the name does not clearly point at one — a wrong guess
	 * is worse than none.
	 *
	 * @param string $name    Option name, cron hook, meta key or table name without the DB prefix.
	 * @param string $context option|hook|meta|table — decides which core names are recognised.
	 * @return array|null Component.
	 */
	private function guess_owner( $name, $context = 'option' ) {
		$name = (string) $name;
		$key  = $context . ':' . $name;
		if ( isset( $this->memo['owner'][ $key ] ) ) {
			return $this->memo['owner'][ $key ] ? $this->memo['owner'][ $key ] : null;
		}
		$result = $this->resolve_owner( $name, $context );
		// Memoise misses as false so they are not recomputed.
		$this->memo['owner'][ $key ] = $result ? $result : false;
		return $result;
	}

	/**
	 * Uncached owner resolution.
	 *
	 * @param string $name    Name.
	 * @param string $context option|hook|meta|table.
	 * @return array|null
	 */
	private function resolve_owner( $name, $context ) {
		$raw = (string) preg_replace( '/^_(?:site_)?transient_(?:timeout_)?/', '', $name );
		$raw = ltrim( $raw, '_' );
		if ( '' === $raw ) {
			return null;
		}
		$lower   = strtolower( $raw );
		$plugins = $this->installed_plugins();

		if ( 'option' === $context ) {
			// theme_mods_{stylesheet}: owned by that theme.
			if ( preg_match( '/^theme_mods_(.+)$/', $raw, $m ) ) {
				$theme = wp_get_theme( $m[1] );
				return $theme->exists() ? BLT_Optimized_Components::make( 'theme', $m[1], (string) $theme->get( 'Name' ) ) : null;
			}
			// widget_{plugin}_...: the plugin's widget settings, not core's.
			if ( 0 === strpos( $lower, 'widget_' ) ) {
				$inner = $this->resolve_owner( substr( $raw, 7 ), $context );
				if ( $inner && 'core' !== $inner['type'] ) {
					return $inner;
				}
			}
		}

		// 1. Known prefixes.
		foreach ( $this->owner_map() as $prefix => $info ) {
			if ( 0 !== strpos( $lower, $prefix ) ) {
				continue;
			}
			if ( '' !== $info['pattern'] && ! preg_match( $info['pattern'], $raw ) ) {
				continue;
			}
			$display = isset( $plugins[ $info['slug'] ] ) ? $plugins[ $info['slug'] ]['name'] : $info['name'];
			return BLT_Optimized_Components::make( 'plugin', $info['slug'], $display );
		}

		// 2. Installed plugin slugs: the first k name segments, joined, equal the slug without separators.
		$tokens = preg_split( '/[_\-\s.\/:]+/', $lower, -1, PREG_SPLIT_NO_EMPTY );
		if ( empty( $tokens ) ) {
			return null;
		}
		$compact = array();
		foreach ( $plugins as $slug => $data ) {
			$key = str_replace( array( '-', '_', '.' ), '', strtolower( $slug ) );
			if ( strlen( $key ) >= 4 && ! isset( $compact[ $key ] ) ) {
				$compact[ $key ] = $slug;
			}
		}
		$best = null;
		for ( $k = min( 6, count( $tokens ) ); $k >= 1; $k-- ) {
			$joined = implode( '', array_slice( $tokens, 0, $k ) );
			if ( 1 === $k && in_array( $joined, self::GENERIC_TOKENS, true ) ) {
				continue;
			}
			if ( isset( $compact[ $joined ] ) ) {
				$best = $compact[ $joined ];
				break;
			}
		}
		if ( null !== $best ) {
			return BLT_Optimized_Components::make( 'plugin', $best, $plugins[ $best ]['name'] );
		}

		// 3. The active theme (and its parent).
		foreach ( array_unique( array( get_stylesheet(), get_template() ) ) as $theme_slug ) {
			$key = str_replace( array( '-', '_', '.' ), '', strtolower( (string) $theme_slug ) );
			if ( strlen( $key ) < 4 ) {
				continue;
			}
			for ( $k = min( 6, count( $tokens ) ); $k >= 1; $k-- ) {
				if ( 1 === $k && in_array( $tokens[0], self::GENERIC_TOKENS, true ) ) {
					continue;
				}
				if ( implode( '', array_slice( $tokens, 0, $k ) ) === $key ) {
					$theme = wp_get_theme( $theme_slug );
					return BLT_Optimized_Components::make( 'theme', $theme_slug, $theme->exists() ? (string) $theme->get( 'Name' ) : $theme_slug );
				}
			}
		}

		// 4. WordPress core, only for names that are plainly its own.
		$core = false;
		if ( 'option' === $context ) {
			$core = $this->is_protected_option( $name )
				|| preg_match( '/^(?:wp_(?:user_roles|core_|styles_for_blocks|theme_files|force_deactivated_plugins|attachment_pages|page_for_privacy|calendar_block|global_styles)|widget_|theme_mods_|update_(?:core|plugins|themes)$|feed_|dash_|doing_cron$|plugin_slugs$|poptags_|can_compress_scripts$|fresh_site$|recently_activated$|auto_core_update|finished_updating|sticky_posts$)/', $lower );
		} elseif ( 'hook' === $context ) {
			$core = in_array( $name, (array) apply_filters( 'blt_optimized_core_cron_hooks', self::CORE_CRON_HOOKS ), true );
		} elseif ( 'meta' === $context ) {
			$core = (bool) preg_match( '/^(?:wp_|edit_lock|edit_last|thumbnail_id|menu_item_|oembed_|pingme|encloseme)/', $lower );
		}
		if ( $core ) {
			return BLT_Optimized_Components::make( 'core', 'wordpress', __( 'WordPress core', 'blt-optimized' ) );
		}

		return null;
	}

	/**
	 * Owner of a database table from its name.
	 *
	 * @param string $table Full table name.
	 * @return array|null
	 */
	private function table_owner( $table ) {
		global $wpdb;
		$suffix = (string) $table;
		if ( 0 === strpos( $suffix, $wpdb->prefix ) ) {
			$suffix = substr( $suffix, strlen( $wpdb->prefix ) );
		}
		// Multisite sub-site prefix (wp_2_...).
		$suffix = (string) preg_replace( '/^\d+_/', '', $suffix );
		return $this->guess_owner( $suffix, 'table' );
	}

	/**
	 * Component for a storage item: the plugin or theme folder it sits in,
	 * else the plugin its backup/log signature belongs to, else null.
	 *
	 * @param string $flag Primary flag.
	 * @param string $abs  Absolute path.
	 * @return array|null
	 */
	private function storage_component( $flag, $abs ) {
		$by_signature = array(
			'updraft-backups'    => array( 'updraftplus', 'UpdraftPlus' ),
			'ai1wm-backups'      => array( 'all-in-one-wp-migration', 'All-in-One WP Migration' ),
			'duplicator-backups' => array( 'duplicator', 'Duplicator' ),
			'backwpup-backups'   => array( 'backwpup', 'BackWPup' ),
			'wc-logs'            => array( 'woocommerce', 'WooCommerce' ),
		);
		if ( isset( $by_signature[ $flag ] ) ) {
			$plugins = $this->installed_plugins();
			$slug    = $by_signature[ $flag ][0];
			return BLT_Optimized_Components::make( 'plugin', $slug, isset( $plugins[ $slug ] ) ? $plugins[ $slug ]['name'] : $by_signature[ $flag ][1] );
		}
		if ( in_array( $flag, array( 'orphaned-plugin-folder', 'inactive-theme' ), true ) ) {
			$component = BLT_Optimized_Components::from_path( $abs );
			if ( in_array( $component['type'], array( 'plugin', 'theme' ), true ) ) {
				return $component;
			}
		}
		return null;
	}

	/**
	 * Option names that must never have autoload disabled by a fix. Defers to
	 * BLT_Optimized_Fixes::protected_options() when it exists so the list has
	 * one source of truth; otherwise the built-in core allow-list.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	private function is_protected_option( $name ) {
		if ( ! isset( $this->memo['protected'] ) ) {
			global $wpdb;
			$list = array();
			if ( method_exists( 'BLT_Optimized_Fixes', 'protected_options' ) ) {
				$list = BLT_Optimized_Fixes::protected_options();
			}
			if ( ! is_array( $list ) || empty( $list ) ) {
				$list = array(
					'cron',
					'rewrite_rules',
					'wp_user_roles',
					$wpdb->prefix . 'user_roles',
					'active_plugins',
					'sidebars_widgets',
					'widget_*',
					'theme_mods_*',
					'template',
					'stylesheet',
					'siteurl',
					'home',
					'blogname',
					'uninstall_plugins',
				);
			}
			$exact    = array();
			$patterns = array();
			foreach ( $list as $entry ) {
				$entry = strtolower( str_replace( '{prefix}', $wpdb->prefix, (string) $entry ) );
				if ( false !== strpos( $entry, '*' ) ) {
					$patterns[] = $entry;
				} else {
					$exact[ $entry ] = true;
				}
			}
			$this->memo['protected'] = array(
				'exact'    => $exact,
				'patterns' => $patterns,
			);
		}

		$name = strtolower( (string) $name );
		if ( isset( $this->memo['protected']['exact'][ $name ] ) ) {
			return true;
		}
		foreach ( $this->memo['protected']['patterns'] as $pattern ) {
			if ( fnmatch( $pattern, $name ) ) {
				return true;
			}
		}
		return false;
	}

	/* ================================================================== */
	/* Formatting helpers                                                  */
	/* ================================================================== */

	/**
	 * Build a finding with this module's defaults.
	 *
	 * @param array $args Finding args.
	 * @return array
	 */
	private function finding( array $args ) {
		if ( ! isset( $args['source'] ) ) {
			$args['source'] = 'health';
		}
		return BLT_Optimized_Findings::make( $args );
	}

	/**
	 * Two-column evidence table from [label, value] pairs.
	 *
	 * @param array[]    $pairs   Rows.
	 * @param array|null $columns Optional column headings.
	 * @return array details
	 */
	private function kv_details( array $pairs, $columns = null ) {
		return array(
			'columns' => $columns ? $columns : array( __( 'Setting', 'blt-optimized' ), __( 'Value', 'blt-optimized' ) ),
			'rows'    => array_map(
				static function ( $pair ) {
					return array( (string) $pair[0], (string) $pair[1] );
				},
				$pairs
			),
		);
	}

	/**
	 * Human label for a constant that may be undefined, boolean or a string.
	 *
	 * @param string $name Constant name.
	 * @return string
	 */
	private function constant_label( $name ) {
		if ( ! defined( $name ) ) {
			return __( 'not defined', 'blt-optimized' );
		}
		$value = constant( $name );
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		return (string) $value;
	}

	/**
	 * Path of the PHP debug log, or '' when none is configured or present.
	 *
	 * @return string
	 */
	private function debug_log_path() {
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG && ! in_array( strtolower( WP_DEBUG_LOG ), array( 'true', 'false', '1', '0' ), true ) ) {
			return wp_normalize_path( WP_DEBUG_LOG );
		}
		return wp_normalize_path( WP_CONTENT_DIR . '/debug.log' );
	}

	/**
	 * Label for a cron schedule: "hourly (every 1 hour)" or "one-time".
	 *
	 * @param string|false $schedule Schedule name.
	 * @param int          $interval Interval in seconds.
	 * @return string
	 */
	private function schedule_label( $schedule, $interval ) {
		if ( ! $schedule || $interval <= 0 ) {
			return __( 'one-time', 'blt-optimized' );
		}
		return sprintf(
			/* translators: 1: schedule name, 2: interval. */
			__( '%1$s (every %2$s)', 'blt-optimized' ),
			$schedule,
			human_time_diff( 0, $interval )
		);
	}

	/**
	 * Short label for cron event arguments.
	 *
	 * @param array $args Event args.
	 * @return string
	 */
	private function args_label( $args ) {
		if ( empty( $args ) ) {
			return __( 'none', 'blt-optimized' );
		}
		$json = wp_json_encode( $args );
		return $this->clean_sample( false === $json ? '' : $json, 120 );
	}

	/**
	 * Printable, UTF-8-safe, length-capped excerpt of an option value.
	 *
	 * @param string $sample Raw text.
	 * @param int    $max    Max characters.
	 * @return string
	 */
	private function clean_sample( $sample, $max ) {
		$sample = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) $sample );
		$sample = wp_check_invalid_utf8( $sample, true );
		$sample = trim( $sample );
		if ( '' === $sample ) {
			return __( '(empty)', 'blt-optimized' );
		}
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $sample ) > $max ) {
			return mb_substr( $sample, 0, $max ) . '…';
		}
		return strlen( $sample ) > $max ? substr( $sample, 0, $max ) . '…' : $sample;
	}

	/**
	 * Rough format of a stored value from its first characters.
	 *
	 * @param string $sample Start of the value.
	 * @return string
	 */
	private function value_format( $sample ) {
		$sample = ltrim( (string) $sample );
		if ( preg_match( '/^(?:a|O|s|i|b|d):\d+[:;{]/', $sample ) ) {
			return __( 'serialized PHP data', 'blt-optimized' );
		}
		if ( '' !== $sample && ( '{' === $sample[0] || '[' === $sample[0] ) ) {
			return __( 'JSON', 'blt-optimized' );
		}
		if ( preg_match( '/^[A-Za-z0-9+\/=]{80,}$/', $sample ) ) {
			return __( 'encoded/base64 blob', 'blt-optimized' );
		}
		return '' === $sample ? __( 'empty', 'blt-optimized' ) : __( 'plain text', 'blt-optimized' );
	}
}
