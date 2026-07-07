<?php
/**
 * Admin pages, AJAX handlers, and exports.
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;

/**
 * All admin surface area. Every AJAX/export handler checks manage_options
 * plus a nonce; nothing destructive is reachable via GET.
 */
class BLT_Optimized_Admin {

	const NONCE_ACTION = 'blt_optimized_admin';
	const CAPABILITY   = 'manage_options';

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

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		$ajax = array(
			'scan_start'      => 'ajax_scan_start',
			'scan_tick'       => 'ajax_scan_tick',
			'scan_status'     => 'ajax_scan_status',
			'scan_cancel'     => 'ajax_scan_cancel',
			'scan_results'    => 'ajax_scan_results',
			'cleanup_preview' => 'ajax_cleanup_preview',
			'cleanup_execute' => 'ajax_cleanup_execute',
			'db_overview'     => 'ajax_db_overview',
			'optimize_tables' => 'ajax_optimize_tables',
		);
		foreach ( $ajax as $action => $method ) {
			add_action( 'wp_ajax_blt_optimized_' . $action, array( $this, $method ) );
		}

		add_action( 'admin_post_blt_optimized_export_scan_csv', array( $this, 'export_scan_csv' ) );
		add_action( 'admin_post_blt_optimized_export_audit_csv', array( $this, 'export_audit_csv' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Menu + assets                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Register the admin menu.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'BLT Optimized', 'blt-optimized' ),
			__( 'BLT Optimized', 'blt-optimized' ),
			self::CAPABILITY,
			'blt-optimized',
			array( $this, 'render_scan_page' ),
			'dashicons-chart-pie',
			81
		);
		add_submenu_page( 'blt-optimized', __( 'Disk Usage', 'blt-optimized' ), __( 'Disk Usage', 'blt-optimized' ), self::CAPABILITY, 'blt-optimized', array( $this, 'render_scan_page' ) );
		add_submenu_page( 'blt-optimized', __( 'Database Cleanup', 'blt-optimized' ), __( 'Database Cleanup', 'blt-optimized' ), self::CAPABILITY, 'blt-optimized-cleanup', array( $this, 'render_cleanup_page' ) );
		add_submenu_page( 'blt-optimized', __( 'Optimization', 'blt-optimized' ), __( 'Optimization', 'blt-optimized' ), self::CAPABILITY, 'blt-optimized-db', array( $this, 'render_db_page' ) );
		add_submenu_page( 'blt-optimized', __( 'Audit Log', 'blt-optimized' ), __( 'Audit Log', 'blt-optimized' ), self::CAPABILITY, 'blt-optimized-audit', array( $this, 'render_audit_page' ) );
		add_submenu_page( 'blt-optimized', __( 'Settings', 'blt-optimized' ), __( 'Settings', 'blt-optimized' ), self::CAPABILITY, 'blt-optimized-settings', array( $this, 'render_settings_page' ) );
	}

	/**
	 * Enqueue admin assets on our pages only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( $hook_suffix, 'blt-optimized' ) ) {
			return;
		}

		wp_enqueue_style( 'blt-optimized-admin', BLT_OPTIMIZED_URL . 'assets/admin.css', array(), BLT_OPTIMIZED_VERSION );
		wp_enqueue_script( 'blt-optimized-admin', BLT_OPTIMIZED_URL . 'assets/admin.js', array( 'jquery' ), BLT_OPTIMIZED_VERSION, true );

		wp_localize_script(
			'blt-optimized-admin',
			'bltOptimized',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => array(
					'scanning'      => __( 'Scanning…', 'blt-optimized' ),
					'scanComplete'  => __( 'Scan complete.', 'blt-optimized' ),
					'scanCancelled' => __( 'Scan cancelled.', 'blt-optimized' ),
					'confirmRun'    => __( 'Run this cleanup? Rows shown in the preview will be permanently deleted.', 'blt-optimized' ),
					'backupAck'     => __( 'I understand this permanently deletes data and I have a recent backup of this site.', 'blt-optimized' ),
					'backupTitle'   => __( 'Before your first deletion', 'blt-optimized' ),
					'error'         => __( 'Request failed. Please try again.', 'blt-optimized' ),
					'noItems'       => __( 'Nothing found — already clean.', 'blt-optimized' ),
					'innodbWarn'    => __( 'Large InnoDB table: OPTIMIZE will rebuild it and briefly lock writes. Continue?', 'blt-optimized' ),
				),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Register settings.
	 */
	public function register_settings() {
		register_setting(
			'blt_optimized_settings_group',
			BLT_Optimized::OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);
	}

	/**
	 * Sanitize settings input.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$input = is_array( $input ) ? $input : array();
		$clean = BLT_Optimized::get_settings();

		if ( isset( $input['scan_schedule'] ) && in_array( $input['scan_schedule'], array( 'disabled', 'weekly', 'monthly' ), true ) ) {
			$clean['scan_schedule'] = $input['scan_schedule'];
		}
		if ( isset( $input['revision_retention'] ) ) {
			$clean['revision_retention'] = max( 0, min( 100, (int) $input['revision_retention'] ) );
		}
		if ( isset( $input['trash_age_days'] ) ) {
			$clean['trash_age_days'] = max( 0, min( 365, (int) $input['trash_age_days'] ) );
		}
		if ( isset( $input['exclusions'] ) ) {
			$clean['exclusions'] = sanitize_textarea_field( $input['exclusions'] );
		}

		return $clean;
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: scanning                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Verify capability + nonce for AJAX requests.
	 */
	private function verify_ajax() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'blt-optimized' ) ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}

	/**
	 * Start a manual scan.
	 */
	public function ajax_scan_start() {
		$this->verify_ajax();
		$result = $this->plugin->scanner->start_scan( 'manual' );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $this->scan_progress( $result ) );
	}

	/**
	 * Process one tick interactively (the open admin page drives the scan;
	 * background cron/Action Scheduler picks it up if the page closes).
	 */
	public function ajax_scan_tick() {
		$this->verify_ajax();
		$state = $this->plugin->scanner->process_tick();
		wp_send_json_success( $this->scan_progress( $state ) );
	}

	/**
	 * Current scan status.
	 */
	public function ajax_scan_status() {
		$this->verify_ajax();
		wp_send_json_success( $this->scan_progress( $this->plugin->scanner->get_state() ) );
	}

	/**
	 * Cancel the running scan.
	 */
	public function ajax_scan_cancel() {
		$this->verify_ajax();
		$this->plugin->scanner->cancel_scan();
		wp_send_json_success( $this->scan_progress( $this->plugin->scanner->get_state() ) );
	}

	/**
	 * Results payload for the tree UI.
	 */
	public function ajax_scan_results() {
		$this->verify_ajax();
		$scanner = $this->plugin->scanner;
		wp_send_json_success(
			array(
				'summary'  => $scanner->get_last_scan(),
				'rows'     => array_map( array( $this, 'format_row' ), $scanner->get_results() ),
				'topHogs'  => array_map( array( $this, 'format_row' ), $scanner->get_top_hogs( 20 ) ),
				'labels'   => $this->flag_labels(),
			)
		);
	}

	/**
	 * Compact progress payload.
	 *
	 * @param array $state Scanner state.
	 * @return array
	 */
	private function scan_progress( $state ) {
		return array(
			'status'      => $state['status'],
			'dirsScanned' => (int) $state['dirs_scanned'],
			'queueLength' => count( (array) $state['queue'] ),
			'bytesSeen'   => (int) $state['bytes_seen'],
			'bytesHuman'  => size_format( (int) $state['bytes_seen'] ),
			'method'      => $state['method'],
		);
	}

	/**
	 * Format a scan row for the client.
	 *
	 * @param array $row DB row.
	 * @return array
	 */
	private function format_row( $row ) {
		return array(
			'path'      => $row['path'],
			'parent'    => isset( $row['parent_path'] ) ? $row['parent_path'] : '',
			'type'      => $row['item_type'],
			'depth'     => (int) $row['depth'],
			'bytes'     => (int) $row['size_bytes'],
			'bytesHuman' => size_format( (int) $row['size_bytes'] ),
			'files'     => (int) $row['file_count'],
			'flags'     => array_filter( explode( ',', (string) $row['flags'] ) ),
		);
	}

	/**
	 * Human labels for flag slugs (signatures + built-in detections).
	 *
	 * @return array
	 */
	private function flag_labels() {
		$labels = array(
			'orphaned-plugin-folder' => __( 'Orphaned plugin folder (no installed plugin matches — likely FTP-deleted)', 'blt-optimized' ),
			'inactive-theme'         => __( 'Inactive theme', 'blt-optimized' ),
			'log-file'               => __( 'Growing log file', 'blt-optimized' ),
			'archive-file'           => __( 'Backup/archive file', 'blt-optimized' ),
			'large-file'             => __( 'Unusually large file', 'blt-optimized' ),
		);
		foreach ( $this->plugin->scanner->get_signatures() as $signature ) {
			$labels[ $signature['slug'] ] = $signature['label'];
		}
		return $labels;
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: cleanup                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Preview all cleanup categories (dry run — the default state).
	 */
	public function ajax_cleanup_preview() {
		$this->verify_ajax();

		$cleaner    = $this->plugin->cleaner;
		$categories = $cleaner->get_categories();
		$requested  = isset( $_POST['category'] ) ? sanitize_key( wp_unslash( $_POST['category'] ) ) : '';

		$payload = array();
		foreach ( $categories as $id => $category ) {
			if ( $requested && $requested !== $id ) {
				continue;
			}
			$preview = $cleaner->preview( $id );
			if ( is_wp_error( $preview ) ) {
				continue;
			}
			$payload[ $id ] = array(
				'label'       => $category['label'],
				'description' => $category['description'],
				'flagOnly'    => ! empty( $category['flag_only'] ),
				'count'       => $preview['count'],
				'bytes'       => $preview['bytes'],
				'bytesHuman'  => size_format( $preview['bytes'] ),
				'items'       => isset( $preview['items'] ) ? $preview['items'] : null,
				'notes'       => isset( $preview['notes'] ) ? $preview['notes'] : '',
			);
		}

		wp_send_json_success( array( 'categories' => $payload, 'backupAcked' => $this->backup_acked() ) );
	}

	/**
	 * Execute a cleanup category. Requires the backup acknowledgment once
	 * per user session before the first deletion.
	 */
	public function ajax_cleanup_execute() {
		$this->verify_ajax();

		$category = isset( $_POST['category'] ) ? sanitize_key( wp_unslash( $_POST['category'] ) ) : '';

		if ( ! $this->backup_acked() ) {
			if ( empty( $_POST['backup_ack'] ) ) {
				wp_send_json_error(
					array(
						'code'    => 'backup_ack_required',
						'message' => __( 'Please acknowledge that you have a recent backup before running deletions.', 'blt-optimized' ),
					)
				);
			}
			set_transient( $this->backup_ack_key(), 1, 12 * HOUR_IN_SECONDS );
			$this->plugin->audit_log->log( 'backup_acknowledged', __( 'User acknowledged having a recent backup before bulk deletion.', 'blt-optimized' ) );
		}

		$result = $this->plugin->cleaner->execute( $category );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'rows'       => $result['rows'],
				'bytes'      => $result['bytes'],
				'bytesHuman' => size_format( $result['bytes'] ),
			)
		);
	}

	/**
	 * Whether the current user has acknowledged the backup prompt recently.
	 *
	 * @return bool
	 */
	private function backup_acked() {
		return (bool) get_transient( $this->backup_ack_key() );
	}

	/**
	 * Transient key for the backup acknowledgment.
	 *
	 * @return string
	 */
	private function backup_ack_key() {
		return 'blt_optimized_backup_ack_' . get_current_user_id();
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: DB optimization                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Table stats + autoload audit + MyISAM flags.
	 */
	public function ajax_db_overview() {
		$this->verify_ajax();
		$optimizer = $this->plugin->db_optimizer;
		$stats     = $optimizer->get_table_stats();

		wp_send_json_success(
			array(
				'dbBytes'      => $optimizer->get_db_size(),
				'dbBytesHuman' => size_format( $optimizer->get_db_size() ),
				'tables'       => array_map(
					static function ( $table ) {
						$table['bytesHuman']    = size_format( $table['bytes'] );
						$table['dataFreeHuman'] = size_format( $table['data_free'] );
						return $table;
					},
					$stats
				),
				'autoload'     => $optimizer->autoload_audit( 20 ),
				'myisamCount'  => count( $optimizer->myisam_tables() ),
			)
		);
	}

	/**
	 * Run OPTIMIZE TABLE on selected tables, returning a before/after summary.
	 */
	public function ajax_optimize_tables() {
		$this->verify_ajax();

		$tables = isset( $_POST['tables'] ) ? (array) wp_unslash( $_POST['tables'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$tables = array_map( 'sanitize_text_field', $tables );
		if ( empty( $tables ) ) {
			wp_send_json_error( array( 'message' => __( 'No tables selected.', 'blt-optimized' ) ) );
		}

		$before = $this->plugin->db_optimizer->summary_snapshot();
		$result = $this->plugin->db_optimizer->optimize_tables( $tables );
		$after  = $this->plugin->db_optimizer->summary_snapshot();

		wp_send_json_success(
			array(
				'optimized'   => $result['optimized'],
				'skipped'     => $result['skipped'],
				'beforeBytes' => $result['before_bytes'],
				'afterBytes'  => $result['after_bytes'],
				'beforeHuman' => size_format( $result['before_bytes'] ),
				'afterHuman'  => size_format( $result['after_bytes'] ),
				'before'      => $before,
				'after'       => $after,
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Exports (admin-post, nonce-verified)                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Export the last scan as CSV.
	 */
	public function export_scan_csv() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'blt-optimized' ) );
		}
		check_admin_referer( self::NONCE_ACTION );

		$rows = $this->plugin->scanner->get_results();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="blt-optimized-disk-scan-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, array( 'path', 'type', 'depth', 'size_bytes', 'size_human', 'file_count', 'flags', 'scanned_at_utc' ) );
		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array(
					$row['path'],
					$row['item_type'],
					$row['depth'],
					$row['size_bytes'],
					size_format( (int) $row['size_bytes'] ),
					$row['file_count'],
					$row['flags'],
					$row['scanned_at'],
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Export the audit log as CSV.
	 */
	public function export_audit_csv() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'blt-optimized' ) );
		}
		check_admin_referer( self::NONCE_ACTION );
		$this->plugin->audit_log->export_csv();
	}

	/* ------------------------------------------------------------------ */
	/* Page renderers                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Disk usage page.
	 */
	public function render_scan_page() {
		$summary = $this->plugin->scanner->get_last_scan();
		$du      = $this->plugin->scanner->du_available();
		$export  = wp_nonce_url( admin_url( 'admin-post.php?action=blt_optimized_export_scan_csv' ), self::NONCE_ACTION );
		?>
		<div class="wrap blt-optimized-wrap">
			<h1><?php esc_html_e( 'BLT Optimized — Disk Usage', 'blt-optimized' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Folder-by-folder breakdown of wp-content, with known space hogs flagged. wp-admin and wp-includes are reported as single reference figures.', 'blt-optimized' ); ?>
				<?php if ( $du ) : ?>
					<em><?php esc_html_e( 'Scan method: du (fast).', 'blt-optimized' ); ?></em>
				<?php else : ?>
					<em><?php esc_html_e( 'Scan method: PHP fallback (exec() unavailable on this host).', 'blt-optimized' ); ?></em>
				<?php endif; ?>
			</p>

			<div class="blt-toolbar">
				<button type="button" class="button button-primary" id="blt-scan-start"><?php esc_html_e( 'Scan Now', 'blt-optimized' ); ?></button>
				<button type="button" class="button" id="blt-scan-cancel" style="display:none;"><?php esc_html_e( 'Cancel', 'blt-optimized' ); ?></button>
				<?php if ( $summary ) : ?>
					<a class="button" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Export CSV', 'blt-optimized' ); ?></a>
				<?php endif; ?>
				<span id="blt-scan-progress" class="blt-progress"></span>
			</div>

			<div id="blt-scan-summary" class="blt-cards"></div>

			<div class="blt-columns">
				<div class="blt-col-main">
					<h2><?php esc_html_e( 'Folder tree', 'blt-optimized' ); ?></h2>
					<div id="blt-tree" class="blt-tree"><p class="description"><?php echo $summary ? esc_html__( 'Loading…', 'blt-optimized' ) : esc_html__( 'No scan yet. Click "Scan Now" to run the first scan.', 'blt-optimized' ); ?></p></div>
				</div>
				<div class="blt-col-side">
					<h2><?php esc_html_e( 'Top 20 space hogs', 'blt-optimized' ); ?></h2>
					<div id="blt-top-hogs"></div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Database cleanup page.
	 */
	public function render_cleanup_page() {
		?>
		<div class="wrap blt-optimized-wrap">
			<h1><?php esc_html_e( 'BLT Optimized — Database Cleanup', 'blt-optimized' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Everything below is a dry-run preview by default. Nothing is deleted until you explicitly run a category, and every run is written to the audit log.', 'blt-optimized' ); ?></p>
			<div class="blt-toolbar">
				<button type="button" class="button button-primary" id="blt-cleanup-refresh"><?php esc_html_e( 'Refresh previews', 'blt-optimized' ); ?></button>
			</div>
			<div id="blt-cleanup-list"><p class="description"><?php esc_html_e( 'Loading previews…', 'blt-optimized' ); ?></p></div>
		</div>
		<?php
	}

	/**
	 * DB optimization page.
	 */
	public function render_db_page() {
		?>
		<div class="wrap blt-optimized-wrap">
			<h1><?php esc_html_e( 'BLT Optimized — Database Optimization', 'blt-optimized' ); ?></h1>
			<div id="blt-db-summary" class="blt-cards"></div>

			<h2><?php esc_html_e( 'Autoloaded options', 'blt-optimized' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Autoloaded options are read on every single request. A bloated autoload set is the most common cause of slow wp-admin. Options over 100 KB are flagged.', 'blt-optimized' ); ?></p>
			<div id="blt-autoload"></div>

			<h2><?php esc_html_e( 'Tables', 'blt-optimized' ); ?></h2>
			<p class="description"><?php esc_html_e( 'MyISAM tables benefit most from OPTIMIZE. OPTIMIZE on InnoDB rebuilds the table and briefly locks it — large InnoDB tables warn before running. Remaining MyISAM tables are flagged for InnoDB conversion.', 'blt-optimized' ); ?></p>
			<div class="blt-toolbar">
				<button type="button" class="button button-primary" id="blt-optimize-selected"><?php esc_html_e( 'Optimize selected', 'blt-optimized' ); ?></button>
			</div>
			<div id="blt-tables"><p class="description"><?php esc_html_e( 'Loading…', 'blt-optimized' ); ?></p></div>
		</div>
		<?php
	}

	/**
	 * Audit log page.
	 */
	public function render_audit_page() {
		$entries = $this->plugin->audit_log->get_entries( 200 );
		$export  = wp_nonce_url( admin_url( 'admin-post.php?action=blt_optimized_export_audit_csv' ), self::NONCE_ACTION );
		?>
		<div class="wrap blt-optimized-wrap">
			<h1><?php esc_html_e( 'BLT Optimized — Audit Log', 'blt-optimized' ); ?></h1>
			<div class="blt-toolbar">
				<a class="button" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Export CSV', 'blt-optimized' ); ?></a>
			</div>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'When (UTC)', 'blt-optimized' ); ?></th>
						<th><?php esc_html_e( 'Action', 'blt-optimized' ); ?></th>
						<th><?php esc_html_e( 'Details', 'blt-optimized' ); ?></th>
						<th><?php esc_html_e( 'Reclaimed', 'blt-optimized' ); ?></th>
						<th><?php esc_html_e( 'Rows', 'blt-optimized' ); ?></th>
						<th><?php esc_html_e( 'User', 'blt-optimized' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $entries ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No entries yet.', 'blt-optimized' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $entries as $entry ) : ?>
							<?php $user = get_userdata( (int) $entry['user_id'] ); ?>
							<tr>
								<td><?php echo esc_html( $entry['created_at'] ); ?></td>
								<td><code><?php echo esc_html( $entry['action'] ); ?></code></td>
								<td><?php echo esc_html( $entry['details'] ); ?></td>
								<td><?php echo esc_html( $entry['bytes_reclaimed'] > 0 ? size_format( (int) $entry['bytes_reclaimed'] ) : '—' ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $entry['rows_affected'] ) ); ?></td>
								<td><?php echo esc_html( $user ? $user->user_login : ( $entry['user_id'] ? '#' . $entry['user_id'] : __( 'system', 'blt-optimized' ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Settings page.
	 */
	public function render_settings_page() {
		$settings = BLT_Optimized::get_settings();
		?>
		<div class="wrap blt-optimized-wrap">
			<h1><?php esc_html_e( 'BLT Optimized — Settings', 'blt-optimized' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'blt_optimized_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="blt-scan-schedule"><?php esc_html_e( 'Scheduled auto-scan', 'blt-optimized' ); ?></label></th>
						<td>
							<select id="blt-scan-schedule" name="<?php echo esc_attr( BLT_Optimized::OPTION_SETTINGS ); ?>[scan_schedule]">
								<option value="disabled" <?php selected( $settings['scan_schedule'], 'disabled' ); ?>><?php esc_html_e( 'Disabled', 'blt-optimized' ); ?></option>
								<option value="weekly" <?php selected( $settings['scan_schedule'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'blt-optimized' ); ?></option>
								<option value="monthly" <?php selected( $settings['scan_schedule'], 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'blt-optimized' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Runs the disk scan in the background on a schedule.', 'blt-optimized' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="blt-revision-retention"><?php esc_html_e( 'Revisions to keep per post', 'blt-optimized' ); ?></label></th>
						<td>
							<input type="number" min="0" max="100" id="blt-revision-retention" name="<?php echo esc_attr( BLT_Optimized::OPTION_SETTINGS ); ?>[revision_retention]" value="<?php echo esc_attr( $settings['revision_retention'] ); ?>" class="small-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="blt-trash-age"><?php esc_html_e( 'Trash / spam age threshold (days)', 'blt-optimized' ); ?></label></th>
						<td>
							<input type="number" min="0" max="365" id="blt-trash-age" name="<?php echo esc_attr( BLT_Optimized::OPTION_SETTINGS ); ?>[trash_age_days]" value="<?php echo esc_attr( $settings['trash_age_days'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Trashed posts and spam/trashed comments older than this are eligible for cleanup.', 'blt-optimized' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="blt-exclusions"><?php esc_html_e( 'Excluded paths', 'blt-optimized' ); ?></label></th>
						<td>
							<textarea id="blt-exclusions" name="<?php echo esc_attr( BLT_Optimized::OPTION_SETTINGS ); ?>[exclusions]" rows="6" class="large-text code" placeholder="wp-content/uploads/client-archive&#10;wp-content/*/keep-me"><?php echo esc_textarea( $settings['exclusions'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One per line, relative to the WordPress root. Wildcards (*) supported. These paths are always skipped by the scanner.', 'blt-optimized' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
