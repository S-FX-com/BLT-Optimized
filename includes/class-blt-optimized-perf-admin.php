<?php
/**
 * Admin surface for the performance scan: Performance Scan, Page Profiler
 * and Speed Tweaks screens, their AJAX endpoints and the findings export.
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;

/**
 * Kept apart from BLT_Optimized_Admin (disk/DB screens) so each stays a
 * readable size. Shares its capability, nonce and backup-acknowledgement
 * gate: every endpoint checks manage_options + nonce, and every fix that
 * deletes data requires the same once-per-session backup acknowledgement as
 * the database cleanup screen.
 */
class BLT_Optimized_Perf_Admin {

	/**
	 * Plugin core.
	 *
	 * @var BLT_Optimized
	 */
	private $plugin;

	/**
	 * Main admin (capability/nonce/backup-ack helpers).
	 *
	 * @var BLT_Optimized_Admin
	 */
	private $admin;

	/**
	 * Constructor.
	 *
	 * @param BLT_Optimized       $plugin Plugin core.
	 * @param BLT_Optimized_Admin $admin  Main admin.
	 */
	public function __construct( BLT_Optimized $plugin, BLT_Optimized_Admin $admin ) {
		$this->plugin = $plugin;
		$this->admin  = $admin;

		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		$ajax = array(
			'perf_start'    => 'ajax_start',
			'perf_tick'     => 'ajax_tick',
			'perf_status'   => 'ajax_status',
			'perf_cancel'   => 'ajax_cancel',
			'perf_report'   => 'ajax_report',
			'perf_fix'      => 'ajax_fix',
			'perf_dismiss'  => 'ajax_dismiss',
			'perf_profile'  => 'ajax_profile',
			'perf_pages'    => 'ajax_pages',
			'perf_page'     => 'ajax_page',
			'perf_psi'      => 'ajax_psi',
			'rule_add'      => 'ajax_rule_add',
			'rule_toggle'   => 'ajax_rule_toggle',
			'rule_delete'   => 'ajax_rule_delete',
			'tweaks_status' => 'ajax_tweaks_status',
		);
		foreach ( $ajax as $action => $method ) {
			add_action( 'wp_ajax_blt_optimized_' . $action, array( $this, $method ) );
		}

		add_action( 'admin_post_blt_optimized_export_perf_csv', array( $this, 'export_csv' ) );
	}

	/**
	 * The three screens this class renders.
	 *
	 * @return string[]
	 */
	public static function page_slugs() {
		return array( 'blt-optimized', 'blt-optimized-profiler', 'blt-optimized-tweaks' );
	}

	/* ------------------------------------------------------------------ */
	/* Settings + assets                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Register the Speed Tweaks option for the options.php form.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'blt_optimized_tweaks_group',
			BLT_Optimized_Tweaks::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'BLT_Optimized_Tweaks', 'sanitize' ),
			)
		);
	}

	/**
	 * Enqueue the performance UI on its three screens only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
		if ( false === strpos( $hook_suffix, 'blt-optimized' ) || ! in_array( $page, self::page_slugs(), true ) ) {
			return;
		}

		wp_enqueue_style( 'blt-optimized-performance', BLT_OPTIMIZED_URL . 'assets/performance.css', array( 'blt-optimized-admin' ), BLT_OPTIMIZED_VERSION );
		wp_enqueue_script( 'blt-optimized-performance', BLT_OPTIMIZED_URL . 'assets/performance.js', array( 'jquery', 'blt-optimized-admin' ), BLT_OPTIMIZED_VERSION, true );

		wp_localize_script(
			'blt-optimized-performance',
			'bltPerf',
			array(
				'page'        => $page,
				'backupAcked' => $this->admin->backup_acked(),
				'profilerUrl' => admin_url( 'admin.php?page=blt-optimized-profiler' ),
				'tweaksUrl'   => admin_url( 'admin.php?page=blt-optimized-tweaks' ),
				'homeUrl'     => home_url( '/' ),
				'deferable'   => version_compare( get_bloginfo( 'version' ), '6.3', '>=' ),
				'i18n'        => array(
					'confirmFix'      => __( 'Apply this fix?', 'blt-optimized' ),
					'confirmDelete'   => __( 'This permanently deletes data. Continue?', 'blt-optimized' ),
					'applied'         => __( 'Applied — run the scan again to confirm the improvement.', 'blt-optimized' ),
					'dismissed'       => __( 'Dismissed', 'blt-optimized' ),
					'noReport'        => __( 'No performance scan yet. Click “Run full scan” to analyse this site.', 'blt-optimized' ),
					'profiling'       => __( 'Loading the page and collecting measurements…', 'blt-optimized' ),
					'psiRunning'      => __( 'Asking Google PageSpeed Insights (this can take up to a minute)…', 'blt-optimized' ),
					'error'           => __( 'Request failed. Please try again.', 'blt-optimized' ),
					'scanComplete'    => __( 'Scan complete.', 'blt-optimized' ),
					'scanCancelled'   => __( 'Scan cancelled.', 'blt-optimized' ),
					'confirmRuleDel'  => __( 'Delete this asset rule?', 'blt-optimized' ),
					'safeModeHint'    => __( 'Open the site with every speed tweak and asset rule switched off (only for you).', 'blt-optimized' ),
				),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: full scan                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Start a scan.
	 *
	 * @return void
	 */
	public function ajax_start() {
		$this->admin->verify_ajax();
		$state = $this->plugin->site_audit->start();
		if ( is_wp_error( $state ) ) {
			wp_send_json_error( array( 'message' => $state->get_error_message() ) );
		}
		wp_send_json_success( $this->progress( $state ) );
	}

	/**
	 * Advance the scan by one time-boxed slice.
	 *
	 * @return void
	 */
	public function ajax_tick() {
		$this->admin->verify_ajax();
		wp_send_json_success( $this->progress( $this->plugin->site_audit->tick() ) );
	}

	/**
	 * Current scan status.
	 *
	 * @return void
	 */
	public function ajax_status() {
		$this->admin->verify_ajax();
		wp_send_json_success( $this->progress( $this->plugin->site_audit->get_state() ) );
	}

	/**
	 * Cancel the scan.
	 *
	 * @return void
	 */
	public function ajax_cancel() {
		$this->admin->verify_ajax();
		wp_send_json_success( $this->progress( $this->plugin->site_audit->cancel() ) );
	}

	/**
	 * Compact progress payload.
	 *
	 * @param array $state Scan state.
	 * @return array
	 */
	private function progress( array $state ) {
		$steps   = (array) $state['steps'];
		$total   = max( 1, count( $steps ) );
		$step    = min( (int) $state['step'], $total );
		$urls    = count( (array) $state['urls'] );
		$percent = (int) floor( 100 * $step / $total );

		// Within the pages step, creep forward per page profiled.
		if ( isset( $steps[ $step ] ) && 'pages' === $steps[ $step ] && $urls ) {
			$percent += (int) floor( ( 100 / $total ) * min( 1, $state['url_index'] / $urls ) );
		}

		return array(
			'status'     => $state['status'],
			'label'      => $state['label'],
			'step'       => isset( $steps[ $step ] ) ? $steps[ $step ] : 'done',
			'percent'    => 'completed' === $state['status'] ? 100 : min( 99, $percent ),
			'pagesDone'  => (int) $state['url_index'],
			'pagesTotal' => $urls,
			'errors'     => array_values( (array) $state['errors'] ),
			'startedAt'  => (int) $state['started_at'],
		);
	}

	/**
	 * The stored report, with dismissals and live fix state applied.
	 *
	 * @return void
	 */
	public function ajax_report() {
		$this->admin->verify_ajax();
		wp_send_json_success( $this->report_payload() );
	}

	/**
	 * Report payload shared by the report and dismiss endpoints.
	 *
	 * @return array
	 */
	private function report_payload() {
		$report    = BLT_Optimized_Findings::get_report();
		$dismissed = BLT_Optimized_Findings::get_dismissed();

		if ( $report ) {
			// Scores are recomputed so dismissing a finding updates them
			// without a re-scan.
			$report['scores']        = BLT_Optimized_Findings::score( (array) $report['findings'], $dismissed );
			$report['generatedHuman'] = sprintf(
				/* translators: %s: human time difference. */
				__( '%s ago', 'blt-optimized' ),
				human_time_diff( (int) $report['generated_at'] )
			);
		}

		return array(
			'report'      => $report,
			'dismissed'   => $dismissed,
			'categories'  => array(
				'server'   => __( 'Server & PHP', 'blt-optimized' ),
				'database' => __( 'Database', 'blt-optimized' ),
				'assets'   => __( 'Scripts & styles', 'blt-optimized' ),
				'content'  => __( 'Content', 'blt-optimized' ),
				'storage'  => __( 'Storage', 'blt-optimized' ),
			),
			'backupAcked' => $this->admin->backup_acked(),
			'exportUrl'   => wp_nonce_url( admin_url( 'admin-post.php?action=blt_optimized_export_perf_csv' ), BLT_Optimized_Admin::NONCE_ACTION ),
		);
	}

	/**
	 * Apply a fix from a finding.
	 *
	 * POST: fix_action (string), args (JSON object), backup_ack (bool),
	 * finding_key (optional; recorded as dismissed-as-fixed on success).
	 *
	 * @return void
	 */
	public function ajax_fix() {
		$this->admin->verify_ajax();

		$action = isset( $_POST['fix_action'] ) ? sanitize_key( wp_unslash( $_POST['fix_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_ajax().
		$args   = $this->json_arg( 'args' );

		if ( '' === $action || ! array_key_exists( $action, BLT_Optimized_Fixes::actions() ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown fix.', 'blt-optimized' ) ) );
		}

		if ( BLT_Optimized_Fixes::is_destructive( $action ) ) {
			$this->admin->ensure_backup_ack();
		}

		$result = $this->plugin->fixes->apply( $action, $args );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Dismiss or restore a finding.
	 *
	 * @return void
	 */
	public function ajax_dismiss() {
		$this->admin->verify_ajax();
		$key       = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_ajax().
		$dismissed = ! empty( $_POST['dismissed'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_ajax().
		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'Missing finding.', 'blt-optimized' ) ) );
		}
		BLT_Optimized_Findings::set_dismissed( $key, $dismissed );
		wp_send_json_success( $this->report_payload() );
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: page profiler                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Profile one URL now. With compare=1, also profile it with every speed
	 * tweak and asset rule bypassed, so the gain is measured, not assumed.
	 *
	 * @return void
	 */
	public function ajax_profile() {
		$this->admin->verify_ajax();

		$url     = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_ajax().
		$compare = ! empty( $_POST['compare'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_ajax().

		if ( '' === $url ) {
			wp_send_json_error( array( 'message' => __( 'Enter a URL on this site.', 'blt-optimized' ) ) );
		}

		$page = $this->plugin->page_auditor->audit( $url, array( 'check_cache' => true ) );
		if ( is_wp_error( $page ) ) {
			wp_send_json_error( array( 'message' => $page->get_error_message() ) );
		}
		$this->plugin->site_audit->remember_page( $page );

		$payload = array(
			'page'     => $page,
			'summary'  => $this->plugin->site_audit->page_summary( $page ),
			'findings' => BLT_Optimized_Findings::sort( BLT_Optimized_Page_Findings::analyze( array( $page ) ) ),
		);

		if ( $compare ) {
			$baseline = $this->plugin->page_auditor->audit( $url, array( 'tweaks_off' => true ) );
			if ( ! is_wp_error( $baseline ) ) {
				$payload['baseline'] = $this->plugin->site_audit->page_summary( $baseline );
			}
		}

		wp_send_json_success( $payload );
	}

	/**
	 * Sample URLs plus pages from the last scan (for the profiler picker).
	 *
	 * @return void
	 */
	public function ajax_pages() {
		$this->admin->verify_ajax();

		$stored = array();
		foreach ( $this->plugin->site_audit->get_pages() as $key => $page ) {
			$summary         = $this->plugin->site_audit->page_summary( $page );
			$summary['key']  = $key;
			$summary['when'] = isset( $page['fetched_at'] ) ? (int) $page['fetched_at'] : 0;
			$stored[]        = $summary;
		}

		wp_send_json_success(
			array(
				'samples' => $this->plugin->page_auditor->get_sample_urls(),
				'stored'  => $stored,
			)
		);
	}

	/**
	 * One stored page report (full detail) by key.
	 *
	 * @return void
	 */
	public function ajax_page() {
		$this->admin->verify_ajax();
		$key   = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_ajax().
		$pages = $this->plugin->site_audit->get_pages();
		if ( ! isset( $pages[ $key ] ) ) {
			wp_send_json_error( array( 'message' => __( 'That page report is no longer stored. Profile the page again.', 'blt-optimized' ) ) );
		}
		$page = $pages[ $key ];
		wp_send_json_success(
			array(
				'page'     => $page,
				'summary'  => $this->plugin->site_audit->page_summary( $page ),
				'findings' => BLT_Optimized_Findings::sort( BLT_Optimized_Page_Findings::analyze( array( $page ) ) ),
			)
		);
	}

	/**
	 * Run PageSpeed Insights for one URL on demand.
	 *
	 * @return void
	 */
	public function ajax_psi() {
		$this->admin->verify_ajax();

		if ( ! $this->plugin->psi->is_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'PageSpeed Insights is off. Enable it under Settings → Performance scan.', 'blt-optimized' ) ) );
		}

		$url      = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_ajax().
		$strategy = isset( $_POST['strategy'] ) && 'desktop' === $_POST['strategy'] ? 'desktop' : 'mobile'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_ajax().

		$result = $this->plugin->psi->run( $url, $strategy );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'psi'      => $result,
				'findings' => BLT_Optimized_Findings::sort( BLT_Optimized_PSI::findings( array( $result ) ) ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: asset rules + tweak state                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Add an asset rule. POST: type, handle, mode, conditions (JSON array), note.
	 *
	 * @return void
	 */
	public function ajax_rule_add() {
		$this->admin->verify_ajax();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verify_ajax().
		$rule = array(
			'type'       => isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '',
			'handle'     => isset( $_POST['handle'] ) ? sanitize_text_field( wp_unslash( $_POST['handle'] ) ) : '',
			'mode'       => isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '',
			'conditions' => $this->json_arg( 'conditions' ),
			'note'       => isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '',
		);
		// phpcs:enable

		$result = $this->plugin->fixes->apply( 'asset_rule', $rule );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $this->tweaks_payload() );
	}

	/**
	 * Enable/disable an asset rule. POST: id, enabled.
	 *
	 * @return void
	 */
	public function ajax_rule_toggle() {
		$this->admin->verify_ajax();
		$id      = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_ajax().
		$enabled = ! empty( $_POST['enabled'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_ajax().
		if ( ! BLT_Optimized_Tweaks::set_rule_enabled( $id, $enabled ) ) {
			wp_send_json_error( array( 'message' => __( 'Rule not found.', 'blt-optimized' ) ) );
		}
		$this->plugin->audit_log->log( 'asset_rule_toggle', sprintf( '%s %s', $id, $enabled ? 'enabled' : 'disabled' ) );
		wp_send_json_success( $this->tweaks_payload() );
	}

	/**
	 * Delete an asset rule. POST: id.
	 *
	 * @return void
	 */
	public function ajax_rule_delete() {
		$this->admin->verify_ajax();
		$id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_ajax().
		if ( ! BLT_Optimized_Tweaks::remove_rule( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Rule not found.', 'blt-optimized' ) ) );
		}
		$this->plugin->audit_log->log( 'asset_rule_delete', $id );
		wp_send_json_success( $this->tweaks_payload() );
	}

	/**
	 * Rules + reversible autoload changes, for the Speed Tweaks screen.
	 *
	 * @return void
	 */
	public function ajax_tweaks_status() {
		$this->admin->verify_ajax();
		wp_send_json_success( $this->tweaks_payload() );
	}

	/**
	 * Speed Tweaks screen data.
	 *
	 * @return array
	 */
	private function tweaks_payload() {
		$changes = array();
		foreach ( BLT_Optimized_Fixes::autoload_changes() as $option => $change ) {
			$changes[] = array(
				'option'     => $option,
				'bytes'      => isset( $change['bytes'] ) ? (int) $change['bytes'] : 0,
				'bytesHuman' => size_format( isset( $change['bytes'] ) ? (int) $change['bytes'] : 0 ),
				'changedAt'  => isset( $change['changed_at'] ) ? (int) $change['changed_at'] : 0,
			);
		}

		return array(
			'rules'           => array_values( BLT_Optimized_Tweaks::rules() ),
			'autoloadChanges' => $changes,
		);
	}

	/**
	 * Decode a JSON-encoded POST field into an array.
	 *
	 * @param string $field POST field.
	 * @return array
	 */
	private function json_arg( $field ) {
		if ( ! isset( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by every caller.
			return array();
		}
		$raw = wp_unslash( $_POST[ $field ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified by caller; decoded then validated field-by-field by the fix/rule handler.
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/* ------------------------------------------------------------------ */
	/* Export                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Export the last report's findings as CSV (agency hand-off).
	 *
	 * @return void
	 */
	public function export_csv() {
		if ( ! current_user_can( BLT_Optimized_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'blt-optimized' ) );
		}
		check_admin_referer( BLT_Optimized_Admin::NONCE_ACTION );

		$report    = BLT_Optimized_Findings::get_report();
		$dismissed = array_flip( BLT_Optimized_Findings::get_dismissed() );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="blt-optimized-performance-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, array( 'severity', 'category', 'title', 'impact', 'component', 'where', 'summary', 'fix', 'dismissed', 'id' ) );
		foreach ( $report ? (array) $report['findings'] : array() as $finding ) {
			fputcsv(
				$out,
				array(
					$finding['severity'],
					$finding['category'],
					$finding['title'],
					$finding['impact']['label'],
					$finding['component'] ? BLT_Optimized_Components::label( $finding['component'] ) : '',
					implode( ' | ', array_slice( (array) $finding['where'], 0, 10 ) ),
					$finding['summary'],
					is_array( $finding['fix'] ) ? $finding['fix']['label'] : '',
					isset( $dismissed[ $finding['key'] ] ) ? 'yes' : '',
					$finding['id'],
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Screens                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Performance Scan screen.
	 *
	 * @return void
	 */
	public function render_perf_page() {
		include BLT_OPTIMIZED_DIR . 'admin/views/performance.php';
	}

	/**
	 * Page Profiler screen.
	 *
	 * @return void
	 */
	public function render_profiler_page() {
		include BLT_OPTIMIZED_DIR . 'admin/views/profiler.php';
	}

	/**
	 * Speed Tweaks screen.
	 *
	 * @return void
	 */
	public function render_tweaks_page() {
		$tweaks = BLT_Optimized_Tweaks::get();
		include BLT_OPTIMIZED_DIR . 'admin/views/tweaks.php';
	}
}
