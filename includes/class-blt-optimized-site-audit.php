<?php
/**
 * Performance scan orchestrator — runs every check in resumable steps and
 * compiles one prioritised report.
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;

/**
 * A full performance scan touches the database, every dated uploads folder,
 * several front-end pages (each a real loopback request) and optionally
 * Google's PageSpeed API. That never fits in one PHP request on shared
 * hosting, so the scan is a small state machine: each tick does one
 * time-boxed slice of the current step and persists where it got to, and the
 * open admin page keeps ticking until the report is compiled — the same
 * pattern the disk scanner uses.
 *
 * Steps: health → media → pages → lab (browser) → psi (when enabled) → compile.
 *
 * The lab step is the one the server can't do: the open admin page measures
 * each profiled page in a visible iframe (see BLT_Optimized_Lab_Mode) and
 * posts the results back, then tells the scan to move on.
 */
class BLT_Optimized_Site_Audit {

	/**
	 * Scan state (status, cursor, partial results). Never autoloaded.
	 */
	const STATE_OPTION = 'blt_optimized_perf_state';

	/**
	 * Page reports from the last scan, kept for the Page Profiler. Never autoloaded.
	 */
	const PAGES_OPTION = 'blt_optimized_perf_pages';

	/**
	 * Seconds of work per tick. Loopback page requests are atomic and can
	 * overrun this; the budget only decides whether to start another unit.
	 */
	const TICK_BUDGET = 12;

	/**
	 * PageSpeed Insights runs per scan (the front page, then the next
	 * samples), each a 15–60 s request to Google.
	 */
	const PSI_PAGES = 2;

	/**
	 * Plugin core.
	 *
	 * @var BLT_Optimized
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param BLT_Optimized $plugin Plugin core.
	 */
	public function __construct( BLT_Optimized $plugin ) {
		$this->plugin = $plugin;
	}

	/* ------------------------------------------------------------------ */
	/* State                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Current scan state.
	 *
	 * @return array
	 */
	public function get_state() {
		$defaults = array(
			'status'      => 'idle', // idle|running|completed|cancelled.
			'run_id'      => '',
			'steps'       => array(),
			'step'        => 0,
			'urls'        => array(),
			'url_index'   => 0,
			'psi_index'   => 0,
			'health'      => array(),
			'media'       => array(),
			'pages'       => array(),
			'psi'         => array(),
			'lab_done'    => array(),
			'errors'      => array(),
			'started_at'  => 0,
			'finished_at' => 0,
			'label'       => '',
		);
		$state    = get_option( self::STATE_OPTION, array() );
		return wp_parse_args( is_array( $state ) ? $state : array(), $defaults );
	}

	/**
	 * Persist state.
	 *
	 * @param array $state State.
	 * @return void
	 */
	private function save_state( array $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Start a new scan.
	 *
	 * @param array $opts { lab: bool } — include the browser lab step. Only a
	 *                    scan driven from an open admin page can run it.
	 * @return array|WP_Error State, or an error when a scan is running.
	 */
	public function start( array $opts = array() ) {
		$state = $this->get_state();
		if ( 'running' === $state['status'] && ( time() - (int) $state['started_at'] ) < HOUR_IN_SECONDS ) {
			return new WP_Error( 'blt_perf_running', __( 'A performance scan is already in progress.', 'blt-optimized' ) );
		}

		$steps = array( 'health', 'media', 'pages' );
		if ( ! empty( $opts['lab'] ) ) {
			$steps[] = 'lab';
		}
		if ( $this->plugin->psi->is_enabled() ) {
			$steps[] = 'psi';
		}
		$steps[] = 'compile';

		/**
		 * Fires before a performance scan starts.
		 */
		do_action( 'blt_optimized_before_perf_scan' );

		$state = array(
			'status'      => 'running',
			'run_id'      => 'perf_' . gmdate( 'YmdHis' ) . '_' . wp_generate_password( 6, false ),
			'steps'       => $steps,
			'step'        => 0,
			'urls'        => array_values( $this->plugin->page_auditor->get_sample_urls() ),
			'url_index'   => 0,
			'psi_index'   => 0,
			'health'      => array(),
			'media'       => array(),
			'pages'       => array(),
			'psi'         => array(),
			'lab_done'    => array(),
			'errors'      => array(),
			'started_at'  => time(),
			'finished_at' => 0,
			'label'       => __( 'Checking server and database…', 'blt-optimized' ),
		);
		$this->save_state( $state );

		return $state;
	}

	/**
	 * Cancel a running scan.
	 *
	 * @return array State.
	 */
	public function cancel() {
		$state = $this->get_state();
		if ( 'running' === $state['status'] ) {
			$this->plugin->media_auditor->cancel();
			$state['status']      = 'cancelled';
			$state['finished_at'] = time();
			$state['label']       = __( 'Scan cancelled.', 'blt-optimized' );
			$this->save_state( $state );
		}
		return $state;
	}

	/**
	 * Do one time-boxed slice of work.
	 *
	 * @return array State.
	 */
	public function tick() {
		$state = $this->get_state();
		if ( 'running' !== $state['status'] ) {
			return $state;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- disabled on some hosts.
		}

		$deadline = microtime( true ) + (float) apply_filters( 'blt_optimized_perf_tick_budget', self::TICK_BUDGET );

		while ( 'running' === $state['status'] && microtime( true ) < $deadline ) {
			$step = isset( $state['steps'][ $state['step'] ] ) ? $state['steps'][ $state['step'] ] : 'compile';

			// The browser runs the lab step; the server just waits for it.
			if ( 'lab' === $step ) {
				if ( ! $this->lab_queue( $state ) ) {
					$state = $this->next_step( $state );
					$this->save_state( $state );
					continue;
				}
				$state['label'] = __( 'Measuring pages in your browser (lab test)…', 'blt-optimized' );
				break;
			}

			try {
				switch ( $step ) {
					case 'health':
						$state = $this->step_health( $state );
						break;
					case 'media':
						$state = $this->step_media( $state, max( 1.0, $deadline - microtime( true ) ) );
						break;
					case 'pages':
						$state = $this->step_pages( $state );
						break;
					case 'psi':
						$state = $this->step_psi( $state );
						// One PSI call per tick: each can take most of a minute.
						$deadline = 0;
						break;
					default:
						$state = $this->step_compile( $state );
						break;
				}
			} catch ( \Throwable $e ) {
				// One failing check must not sink the whole scan: record it,
				// skip the step, and keep going.
				$state['errors'][] = sprintf( '%s: %s', $step, $e->getMessage() );
				$state             = $this->next_step( $state );
			}

			$this->save_state( $state );
		}

		return $state;
	}

	/**
	 * Advance to the next step and set its progress label.
	 *
	 * @param array $state State.
	 * @return array
	 */
	private function next_step( array $state ) {
		++$state['step'];
		$labels = array(
			'media'   => __( 'Auditing the media library for unused files…', 'blt-optimized' ),
			'pages'   => __( 'Profiling front-end pages…', 'blt-optimized' ),
			'lab'     => __( 'Measuring pages in your browser (lab test)…', 'blt-optimized' ),
			'psi'     => __( 'Running Google PageSpeed Insights…', 'blt-optimized' ),
			'compile' => __( 'Compiling the report…', 'blt-optimized' ),
		);
		$next           = isset( $state['steps'][ $state['step'] ] ) ? $state['steps'][ $state['step'] ] : 'compile';
		$state['label'] = isset( $labels[ $next ] ) ? $labels[ $next ] : '';
		return $state;
	}

	/* ------------------------------------------------------------------ */
	/* Steps                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Server + database health checks (fast, single slice).
	 *
	 * @param array $state State.
	 * @return array
	 */
	private function step_health( array $state ) {
		$state['health'] = $this->plugin->health->run();
		return $this->next_step( $state );
	}

	/**
	 * Media audit — resumable across ticks.
	 *
	 * @param array $state  State.
	 * @param float $budget Seconds available.
	 * @return array
	 */
	private function step_media( array $state, $budget ) {
		$auditor = $this->plugin->media_auditor;

		// A media audit left over from before this scan (finished or not)
		// is restarted so the report reflects this run.
		if ( empty( $state['media']['started'] ) ) {
			$auditor->start();
			$state['media']['started'] = 1;
		}

		$media = $auditor->tick( (float) $budget );

		if ( isset( $media['status'] ) && 'running' === $media['status'] ) {
			$state['label'] = $this->media_label( $media );
			return $state;
		}

		$state['media']['findings'] = $auditor->findings();
		return $this->next_step( $state );
	}

	/**
	 * Progress label for the media step.
	 *
	 * @param array $media Media auditor state.
	 * @return string
	 */
	private function media_label( array $media ) {
		if ( isset( $media['folders_done'], $media['folders_total'] ) && $media['folders_total'] ) {
			return sprintf(
				/* translators: 1: folders done, 2: folders total. */
				__( 'Auditing the media library for unused files… (%1$d of %2$d folders)', 'blt-optimized' ),
				(int) $media['folders_done'],
				(int) $media['folders_total']
			);
		}
		return __( 'Auditing the media library for unused files…', 'blt-optimized' );
	}

	/**
	 * Profile the next sample URL (one per slice — each is a full page load).
	 *
	 * @param array $state State.
	 * @return array
	 */
	private function step_pages( array $state ) {
		$urls  = (array) $state['urls'];
		$index = (int) $state['url_index'];

		if ( $index >= count( $urls ) ) {
			return $this->next_step( $state );
		}

		$sample = $urls[ $index ];
		$first  = ( 0 === $index );

		$state['label'] = sprintf(
			/* translators: 1: page number, 2: page count, 3: URL. */
			__( 'Profiling page %1$d of %2$d: %3$s', 'blt-optimized' ),
			$index + 1,
			count( $urls ),
			$sample['url']
		);

		$report = $this->plugin->page_auditor->audit(
			$sample['url'],
			array(
				'label'        => isset( $sample['label'] ) ? $sample['label'] : '',
				'type'         => isset( $sample['type'] ) ? $sample['type'] : '',
				'check_cache'  => $first,
				'check_static' => $first,
			)
		);

		if ( is_wp_error( $report ) ) {
			$state['errors'][] = sprintf( '%s: %s', $sample['url'], $report->get_error_message() );
		} else {
			$state['pages'][] = $report;
		}

		++$state['url_index'];
		return $state;
	}

	/**
	 * Run PageSpeed Insights on the next page (one per slice).
	 *
	 * @param array $state State.
	 * @return array
	 */
	private function step_psi( array $state ) {
		$urls  = array_slice( (array) $state['urls'], 0, self::PSI_PAGES );
		$index = (int) $state['psi_index'];

		if ( $index >= count( $urls ) ) {
			return $this->next_step( $state );
		}

		$result = $this->plugin->psi->run( $urls[ $index ]['url'], 'mobile' );
		if ( is_wp_error( $result ) ) {
			$state['errors'][] = sprintf( 'PageSpeed Insights (%s): %s', $urls[ $index ]['url'], $result->get_error_message() );
			// Quota and reachability errors repeat for every URL: stop asking.
			$state['psi_index'] = count( $urls );
		} else {
			$state['psi'][] = $result;
			++$state['psi_index'];
		}

		return $state;
	}

	/**
	 * Turn everything gathered into the stored report.
	 *
	 * @param array $state State.
	 * @return array
	 */
	private function step_compile( array $state ) {
		$pages = (array) $state['pages'];

		$findings = array_merge(
			(array) $state['health'],
			isset( $state['media']['findings'] ) ? (array) $state['media']['findings'] : array(),
			BLT_Optimized_Page_Findings::analyze( $pages ),
			BLT_Optimized_Lab::findings( $pages ),
			empty( $state['psi'] ) ? array() : BLT_Optimized_PSI::findings( (array) $state['psi'] )
		);

		/**
		 * Filter the compiled performance findings before they are stored.
		 *
		 * @param array[] $findings Findings (BLT_Optimized_Findings shape).
		 * @param array   $state    Scan state with raw step results.
		 */
		$findings = apply_filters( 'blt_optimized_perf_findings', $findings, $state );
		$findings = BLT_Optimized_Findings::sort( array_map( array( 'BLT_Optimized_Findings', 'make' ), (array) $findings ) );

		$report = array(
			'run_id'       => $state['run_id'],
			'generated_at' => time(),
			'duration'     => time() - (int) $state['started_at'],
			'findings'     => $findings,
			'scores'       => BLT_Optimized_Findings::score( $findings, BLT_Optimized_Findings::get_dismissed() ),
			'components'   => BLT_Optimized_Page_Findings::component_summary( $pages ),
			'pages'        => array_map( array( $this, 'page_summary' ), $pages ),
			'psi'          => array_map( array( $this, 'psi_summary' ), (array) $state['psi'] ),
			'errors'       => array_values( (array) $state['errors'] ),
		);

		BLT_Optimized_Findings::save_report( $report );
		$this->save_pages( $pages );

		$counts = array_count_values( wp_list_pluck( $findings, 'severity' ) );
		$this->plugin->audit_log->log(
			'performance_scan',
			sprintf(
				/* translators: 1: score, 2: critical count, 3: warning count, 4: pages profiled. */
				__( 'Performance scan completed. Score %1$d/100 — %2$d critical, %3$d warnings across %4$d pages.', 'blt-optimized' ),
				$report['scores']['overall'],
				isset( $counts['critical'] ) ? $counts['critical'] : 0,
				isset( $counts['warning'] ) ? $counts['warning'] : 0,
				count( $pages )
			)
		);

		/**
		 * Fires after a performance scan's report is stored.
		 *
		 * @param array $report Compiled report.
		 */
		do_action( 'blt_optimized_after_perf_scan', $report );

		// Drop the bulky raw results now that the report holds what's needed.
		$state['status']      = 'completed';
		$state['finished_at'] = time();
		$state['label']       = __( 'Scan complete.', 'blt-optimized' );
		$state['health']      = array();
		$state['media']       = array();
		$state['pages']       = array();
		$state['psi']         = array();
		$state['lab_done']    = array();
		++$state['step'];

		return $state;
	}

	/* ------------------------------------------------------------------ */
	/* Page reports                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Compact per-page row for the report's "pages scanned" table.
	 *
	 * @param array $page Page report.
	 * @return array
	 */
	public function page_summary( $page ) {
		$profile = isset( $page['profile'] ) && is_array( $page['profile'] ) ? $page['profile'] : null;
		$html    = isset( $page['html'] ) ? (array) $page['html'] : array();

		$js_bytes  = 0;
		$css_bytes = 0;
		if ( $profile ) {
			foreach ( (array) $profile['assets']['scripts'] as $script ) {
				$js_bytes += (int) $script['bytes'];
			}
			foreach ( (array) $profile['assets']['styles'] as $style ) {
				$css_bytes += (int) $style['bytes'];
			}
		}

		$image_bytes = 0;
		foreach ( isset( $html['images'] ) ? (array) $html['images'] : array() as $image ) {
			$image_bytes += isset( $image['bytes'] ) ? (int) $image['bytes'] : 0;
		}

		return array(
			'url'         => $page['url'],
			'label'       => isset( $page['label'] ) ? $page['label'] : '',
			'status'      => isset( $page['http']['status'] ) ? (int) $page['http']['status'] : 0,
			'ttfb_ms'     => isset( $page['http']['ttfb_ms'] ) ? $page['http']['ttfb_ms'] : null,
			'php_ms'      => $profile ? $profile['timing']['total_ms'] : null,
			'queries'     => $profile ? (int) $profile['queries']['count'] : null,
			'query_ms'    => $profile ? $profile['queries']['time_ms'] : null,
			'js_bytes'    => $js_bytes,
			'css_bytes'   => $css_bytes,
			'image_bytes' => $image_bytes,
			'html_bytes'  => isset( $html['bytes'] ) ? (int) $html['bytes'] : 0,
			'dom_nodes'   => isset( $html['dom_nodes'] ) ? (int) $html['dom_nodes'] : 0,
			'requests'    => count( isset( $html['scripts'] ) ? (array) $html['scripts'] : array() )
				+ count( isset( $html['stylesheets'] ) ? (array) $html['stylesheets'] : array() )
				+ count( isset( $html['images'] ) ? (array) $html['images'] : array() )
				+ count( isset( $html['iframes'] ) ? (array) $html['iframes'] : array() ),
			'profiled'    => (bool) $profile,
			'error'       => isset( $page['profile_error'] ) ? $page['profile_error'] : '',
			'lab'         => empty( $page['lab'] ) ? null : BLT_Optimized_Lab::page_metrics( $page ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Lab step (driven by the browser)                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Pages the browser still has to measure in this scan.
	 *
	 * @param array|null $state State (current when null).
	 * @return array[] Each { url, label }.
	 */
	public function lab_queue( $state = null ) {
		$state = is_array( $state ) ? $state : $this->get_state();
		$done  = array_flip( (array) $state['lab_done'] );
		$queue = array();
		foreach ( (array) $state['pages'] as $page ) {
			$status = isset( $page['http']['status'] ) ? (int) $page['http']['status'] : 0;
			if ( isset( $done[ $page['url'] ] ) || ( $status && $status >= 400 ) ) {
				continue;
			}
			$queue[] = array(
				'url'   => $page['url'],
				'label' => isset( $page['label'] ) ? $page['label'] : '',
			);
		}
		return $queue;
	}

	/**
	 * Attach a lab result to a page of the running scan.
	 *
	 * @param string $url      Page URL.
	 * @param string $viewport mobile|desktop.
	 * @param array  $lab      Sanitized lab result.
	 * @return array|null The page report, or null when the scan has no such page.
	 */
	public function lab_store( $url, $viewport, array $lab ) {
		$state = $this->get_state();
		if ( 'running' !== $state['status'] ) {
			return null;
		}
		foreach ( $state['pages'] as $i => $page ) {
			if ( self::same_url( $page['url'], $url ) ) {
				$state['pages'][ $i ]['lab'][ $viewport ] = $lab;
				$state['lab_done'][]                      = $page['url'];
				$state['lab_done']                        = array_values( array_unique( $state['lab_done'] ) );
				$this->save_state( $state );
				return $state['pages'][ $i ];
			}
		}
		return null;
	}

	/**
	 * The browser finished (or gave up on) the lab step: move on.
	 *
	 * @return array State.
	 */
	public function lab_done() {
		$state = $this->get_state();
		$steps = (array) $state['steps'];
		if ( 'running' === $state['status'] && isset( $steps[ $state['step'] ] ) && 'lab' === $steps[ $state['step'] ] ) {
			$state = $this->next_step( $state );
			$this->save_state( $state );
		}
		return $state;
	}

	/**
	 * Attach a lab result measured from the Page Profiler to the stored page
	 * report for that URL (creating a minimal one when none exists).
	 *
	 * @param string $url      Page URL.
	 * @param string $viewport mobile|desktop.
	 * @param array  $lab      Sanitized lab result.
	 * @return array The page report.
	 */
	public function remember_lab( $url, $viewport, array $lab ) {
		$pages = $this->get_pages();
		$key   = null;
		foreach ( $pages as $k => $page ) {
			if ( self::same_url( $page['url'], $url ) ) {
				$key = $k;
				break;
			}
		}
		if ( null === $key ) {
			$key           = md5( $url );
			$pages[ $key ] = array(
				'url'        => $url,
				'label'      => '',
				'fetched_at' => time(),
				'profile'    => null,
			);
		}
		$pages[ $key ]['lab'][ $viewport ] = $lab;
		update_option( self::PAGES_OPTION, array_slice( $pages, -12, null, true ), false );
		return $pages[ $key ];
	}

	/**
	 * Same page, ignoring a trailing slash and the scheme/host case.
	 *
	 * @param string $a URL.
	 * @param string $b URL.
	 * @return bool
	 */
	public static function same_url( $a, $b ) {
		$norm = static function ( $url ) {
			$url = strtolower( (string) $url );
			$url = preg_replace( '#^https?://#', '', $url );
			return untrailingslashit( preg_replace( '#/(\?|$)#', '$1', $url ) );
		};
		return $norm( $a ) === $norm( $b );
	}

	/**
	 * Compact PSI row for the report header.
	 *
	 * @param array $result Parsed PSI result.
	 * @return array
	 */
	private function psi_summary( $result ) {
		return array(
			'url'      => isset( $result['url'] ) ? $result['url'] : '',
			'strategy' => isset( $result['strategy'] ) ? $result['strategy'] : 'mobile',
			'score'    => isset( $result['score'] ) ? (int) $result['score'] : null,
			'metrics'  => isset( $result['metrics'] ) ? $result['metrics'] : array(),
			'field'    => isset( $result['field'] ) ? $result['field'] : null,
		);
	}

	/**
	 * Store full page reports (capped) for the Page Profiler.
	 *
	 * @param array[] $pages Page reports.
	 * @return void
	 */
	private function save_pages( array $pages ) {
		$stored = array();
		foreach ( array_slice( $pages, 0, 12 ) as $page ) {
			$stored[ md5( $page['url'] ) ] = $page;
		}
		update_option( self::PAGES_OPTION, $stored, false );
	}

	/**
	 * Stored page reports from the last scan, keyed by md5( url ).
	 *
	 * @return array
	 */
	public function get_pages() {
		$pages = get_option( self::PAGES_OPTION, array() );
		return is_array( $pages ) ? $pages : array();
	}

	/**
	 * Remember a page profiled on demand (Page Profiler) next to the scan's.
	 *
	 * @param array $page Page report.
	 * @return void
	 */
	public function remember_page( array $page ) {
		$pages                        = $this->get_pages();
		$pages[ md5( $page['url'] ) ] = $page;
		update_option( self::PAGES_OPTION, array_slice( $pages, -12, null, true ), false );
	}
}
