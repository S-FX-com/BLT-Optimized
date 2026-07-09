<?php
/**
 * Admin menu, page routing, AJAX handlers.
 *
 * @package BltImageOptimizer
 */

namespace BltImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton handling the wp-admin experience.
 */
class Admin {

	/**
	 * Capability required for all admin actions.
	 */
	const CAP = 'manage_options';

	/**
	 * Top-level menu slug.
	 */
	const MENU_SLUG = 'blt-optimizer';

	/**
	 * Singleton instance.
	 *
	 * @var Admin|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_filter( 'blt_optimized_nav_tabs', array( $this, 'register_nav_tabs' ) );
		add_action( 'admin_init', array( $this, 'handle_settings_post' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );

		// AJAX endpoints.
		add_action( 'wp_ajax_blt_bulk_start', array( $this, 'ajax_bulk_start' ) );
		add_action( 'wp_ajax_blt_bulk_control', array( $this, 'ajax_bulk_control' ) );
		add_action( 'wp_ajax_blt_bulk_status', array( $this, 'ajax_bulk_status' ) );
		add_action( 'wp_ajax_blt_test_connection', array( $this, 'ajax_test_connection' ) );

		// Settings/plugins-page convenience link.
		add_filter( 'plugin_action_links_' . BLT_OPTIMIZER_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Parent menu slug of the BLT Optimized suite. The Images module attaches
	 * its pages as submenus of this shared top-level menu instead of creating
	 * its own, so the merged plugin presents a single admin menu.
	 */
	const PARENT_SLUG = 'blt-optimized';

	/**
	 * Register the Images subpages under the shared BLT Optimized menu.
	 *
	 * The base plugin (BLT_Optimized_Admin) registers the `blt-optimized`
	 * top-level menu; here we only add the image-related submenus so both
	 * feature areas live under one menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Image Optimizer', 'blt-image-optimizer' ),
			__( 'Image Optimizer', 'blt-image-optimizer' ),
			self::CAP,
			self::MENU_SLUG,
			array( $this, 'render_bulk_page' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Image Settings', 'blt-image-optimizer' ),
			__( 'Image Settings', 'blt-image-optimizer' ),
			self::CAP,
			self::MENU_SLUG . '-settings',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Image Log', 'blt-image-optimizer' ),
			__( 'Image Log', 'blt-image-optimizer' ),
			self::CAP,
			self::MENU_SLUG . '-log',
			array( $this, 'render_log_page' )
		);
	}

	/**
	 * Append the image pages to the shared BLT Optimized tab strip.
	 *
	 * Hooked on `blt_optimized_nav_tabs` so the core plugin stays unaware of
	 * this module; the tabs appear across every BLT Optimized screen only while
	 * the module is enabled (this filter is added in init(), which only runs
	 * when the module is booted).
	 *
	 * @param array<string,string> $tabs Existing tabs (slug => label).
	 * @return array<string,string>
	 */
	public function register_nav_tabs( $tabs ) {
		$tabs[ self::MENU_SLUG ]               = __( 'Image Optimizer', 'blt-image-optimizer' );
		$tabs[ self::MENU_SLUG . '-settings' ] = __( 'Image Settings', 'blt-image-optimizer' );
		$tabs[ self::MENU_SLUG . '-log' ]      = __( 'Image Log', 'blt-image-optimizer' );
		return $tabs;
	}

	/**
	 * Add a Settings link on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$url  = admin_url( 'admin.php?page=' . self::MENU_SLUG . '-settings' );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'blt-image-optimizer' ) . '</a>';
		array_unshift( $links, $link );
		return $links;
	}

	/**
	 * Enqueue admin CSS/JS only on our pages.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, self::MENU_SLUG ) && 'index.php' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'blt-optimizer-admin',
			BLT_OPTIMIZER_URL . 'admin/images/assets/blt-admin.css',
			array(),
			BLT_OPTIMIZER_VERSION
		);

		wp_enqueue_script(
			'blt-optimizer-admin',
			BLT_OPTIMIZER_URL . 'admin/images/assets/blt-admin.js',
			array(),
			BLT_OPTIMIZER_VERSION,
			true
		);

		wp_localize_script(
			'blt-optimizer-admin',
			'BltOptimizer',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'blt_optimizer_ajax' ),
				'i18n'    => array(
					'starting'  => __( 'Starting…', 'blt-image-optimizer' ),
					'testing'   => __( 'Testing…', 'blt-image-optimizer' ),
					'confirmCancel' => __( 'Cancel the current bulk run?', 'blt-image-optimizer' ),
				),
			)
		);
	}

	/* --------------------------------------------------------------------- *
	 * Page renderers
	 * --------------------------------------------------------------------- */

	/**
	 * Render the bulk optimizer page.
	 *
	 * @return void
	 */
	public function render_bulk_page() {
		$this->guard();
		$queue = new Queue();
		$state = $queue->get_state();
		$stats = Logger::stats();
		require BLT_OPTIMIZER_DIR . 'admin/images/views/bulk.php';
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		$this->guard();
		$settings = Settings::all();
		$saved    = isset( $_GET['blt_saved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		require BLT_OPTIMIZER_DIR . 'admin/images/views/settings.php';
	}

	/**
	 * Render the log page.
	 *
	 * @return void
	 */
	public function render_log_page() {
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$per_page = 50;
		$result   = Logger::query(
			array(
				'status'   => $status,
				'page'     => $paged,
				'per_page' => $per_page,
			)
		);
		$stats    = Logger::stats();

		require BLT_OPTIMIZER_DIR . 'admin/images/views/log.php';
	}

	/* --------------------------------------------------------------------- *
	 * Settings POST
	 * --------------------------------------------------------------------- */

	/**
	 * Handle the settings form submission.
	 *
	 * @return void
	 */
	public function handle_settings_post() {
		if ( ! isset( $_POST['blt_settings_submit'] ) ) {
			return;
		}

		$this->guard();

		check_admin_referer( 'blt_save_settings', 'blt_settings_nonce' );

		$raw = isset( $_POST['blt_settings'] ) && is_array( $_POST['blt_settings'] )
			? wp_unslash( $_POST['blt_settings'] ) // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized -- Sanitized in Settings::save().
			: array();

		Settings::save( $raw );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::MENU_SLUG . '-settings',
					'blt_saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/* --------------------------------------------------------------------- *
	 * AJAX handlers
	 * --------------------------------------------------------------------- */

	/**
	 * AJAX: start a bulk run.
	 *
	 * @return void
	 */
	public function ajax_bulk_start() {
		$this->verify_ajax();

		$queue  = new Queue();
		$result = $queue->start();

		if ( ! $queue->scheduler_available() ) {
			wp_send_json_error(
				array(
					'message' => __( 'Action Scheduler is not available. Install WooCommerce or the standalone Action Scheduler library to run bulk optimization.', 'blt-image-optimizer' ),
				)
			);
		}

		wp_send_json_success(
			array(
				'queued'  => $result['queued'],
				'batches' => $result['batches'],
				'state'   => $queue->get_state(),
			)
		);
	}

	/**
	 * AJAX: pause/resume/cancel.
	 *
	 * @return void
	 */
	public function ajax_bulk_control() {
		$this->verify_ajax();

		$action = isset( $_POST['control'] ) ? sanitize_key( wp_unslash( $_POST['control'] ) ) : '';
		$queue  = new Queue();

		switch ( $action ) {
			case 'pause':
				$queue->pause();
				break;
			case 'resume':
				$queue->resume();
				break;
			case 'cancel':
				$queue->cancel();
				break;
			default:
				wp_send_json_error( array( 'message' => __( 'Unknown control action.', 'blt-image-optimizer' ) ) );
		}

		wp_send_json_success( array( 'state' => $queue->get_state() ) );
	}

	/**
	 * AJAX: poll queue status.
	 *
	 * @return void
	 */
	public function ajax_bulk_status() {
		$this->verify_ajax();

		$queue = new Queue();

		wp_send_json_success(
			array(
				'state' => $queue->get_state(),
				'stats' => Logger::stats(),
			)
		);
	}

	/**
	 * AJAX: test Worker connection.
	 *
	 * @return void
	 */
	public function ajax_test_connection() {
		$this->verify_ajax();

		$result = ( new Uploader() )->test_connection();

		if ( $result['ok'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		}

		wp_send_json_error( array( 'message' => $result['message'] ) );
	}

	/* --------------------------------------------------------------------- *
	 * Dashboard widget
	 * --------------------------------------------------------------------- */

	/**
	 * Register the at-a-glance dashboard widget.
	 *
	 * @return void
	 */
	public function register_dashboard_widget() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'blt_optimizer_widget',
			__( 'Blt Image Optimizer', 'blt-image-optimizer' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	/**
	 * Render the dashboard widget body.
	 *
	 * @return void
	 */
	public function render_dashboard_widget() {
		$stats = Logger::stats();
		$saved = size_format( $stats['saved_bytes'], 1 );
		$done  = (int) $stats['by_status']['done'];

		echo '<p>';
		printf(
			/* translators: 1: number of optimized images, 2: bytes saved, 3: savings percentage. */
			esc_html__( '%1$d images optimized — %2$s saved (%3$s%% smaller).', 'blt-image-optimizer' ),
			(int) $done,
			esc_html( $saved ),
			esc_html( (string) $stats['saved_pct'] )
		);
		echo '</p>';

		printf(
			'<p><a class="button" href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ),
			esc_html__( 'Open Bulk Optimizer', 'blt-image-optimizer' )
		);
	}

	/* --------------------------------------------------------------------- *
	 * Helpers
	 * --------------------------------------------------------------------- */

	/**
	 * Capability guard for page renders.
	 *
	 * @return void
	 */
	private function guard() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'blt-image-optimizer' ) );
		}
	}

	/**
	 * Verify nonce + capability for AJAX requests.
	 *
	 * @return void
	 */
	private function verify_ajax() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'blt-image-optimizer' ) ), 403 );
		}

		check_ajax_referer( 'blt_optimizer_ajax', 'nonce' );
	}
}
