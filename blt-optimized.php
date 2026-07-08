<?php
/**
 * Plugin Name:       BLT Optimized
 * Plugin URI:        https://github.com/S-FX-com/BLT-Optimized
 * Description:       Disk-usage forensics and database optimization for WordPress. Folder-by-folder wp-content size breakdown, orphaned data cleanup, and table optimization — standalone, zero external dependency.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            S-FX.com Small Business Solutions
 * Author URI:        https://s-fx.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       blt-optimized
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;

define( 'BLT_OPTIMIZED_VERSION', '1.0.1' );
define( 'BLT_OPTIMIZED_FILE', __FILE__ );
define( 'BLT_OPTIMIZED_DIR', plugin_dir_path( __FILE__ ) );
define( 'BLT_OPTIMIZED_URL', plugin_dir_url( __FILE__ ) );

require_once BLT_OPTIMIZED_DIR . 'includes/class-blt-optimized-audit-log.php';
require_once BLT_OPTIMIZED_DIR . 'includes/class-blt-optimized-scanner.php';
require_once BLT_OPTIMIZED_DIR . 'includes/class-blt-optimized-cleaner.php';
require_once BLT_OPTIMIZED_DIR . 'includes/class-blt-optimized-db-optimizer.php';
require_once BLT_OPTIMIZED_DIR . 'includes/class-blt-optimized-admin.php';

/**
 * Plugin update checker (YahnisElsts/plugin-update-checker, v5.x).
 *
 * The library is vendored into plugin-update-checker/ at build time — see
 * plugin-update-checker/README-VENDOR.md. The require is guarded so the
 * plugin functions identically when the library has not been vendored yet
 * (e.g. a development checkout).
 */
if ( file_exists( BLT_OPTIMIZED_DIR . 'plugin-update-checker/plugin-update-checker.php' ) ) {
	require_once BLT_OPTIMIZED_DIR . 'plugin-update-checker/plugin-update-checker.php';

	$blt_optimized_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/S-FX-com/BLT-Optimized/',
		__FILE__,
		'blt-optimized'
	);
	$blt_optimized_update_checker->setBranch( 'main' );

	// If the repository stays private, supply a token via wp-config.php:
	// define( 'BLT_OPTIMIZED_GITHUB_TOKEN', '...' );
	if ( defined( 'BLT_OPTIMIZED_GITHUB_TOKEN' ) && BLT_OPTIMIZED_GITHUB_TOKEN ) {
		$blt_optimized_update_checker->setAuthentication( BLT_OPTIMIZED_GITHUB_TOKEN );
	}
}

/**
 * Main plugin bootstrap.
 */
final class BLT_Optimized {

	const SCANS_TABLE     = 'blt_optimized_scans';
	const AUDIT_TABLE     = 'blt_optimized_audit_log';
	const OPTION_SETTINGS = 'blt_optimized_settings';

	/**
	 * Singleton instance.
	 *
	 * @var BLT_Optimized|null
	 */
	private static $instance = null;

	/**
	 * Module instances.
	 *
	 * @var BLT_Optimized_Scanner
	 */
	public $scanner;

	/**
	 * @var BLT_Optimized_Cleaner
	 */
	public $cleaner;

	/**
	 * @var BLT_Optimized_DB_Optimizer
	 */
	public $db_optimizer;

	/**
	 * @var BLT_Optimized_Audit_Log
	 */
	public $audit_log;

	/**
	 * @var BLT_Optimized_Admin
	 */
	public $admin;

	/**
	 * Get the singleton.
	 *
	 * @return BLT_Optimized
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire up modules and hooks.
	 */
	private function __construct() {
		$this->audit_log    = new BLT_Optimized_Audit_Log();
		$this->scanner      = new BLT_Optimized_Scanner( $this->audit_log );
		$this->cleaner      = new BLT_Optimized_Cleaner( $this->audit_log );
		$this->db_optimizer = new BLT_Optimized_DB_Optimizer( $this->audit_log );
		$this->admin        = new BLT_Optimized_Admin( $this );

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'blt_optimized_scheduled_scan', array( $this->scanner, 'run_scheduled_scan' ) );
		add_action( 'blt_optimized_scan_tick_event', array( $this->scanner, 'background_tick' ) );
		add_action( 'update_option_' . self::OPTION_SETTINGS, array( $this, 'reschedule_scan' ), 10, 2 );
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'blt-optimized', false, dirname( plugin_basename( BLT_OPTIMIZED_FILE ) ) . '/languages' );
	}

	/**
	 * Full name of the scans table.
	 *
	 * @return string
	 */
	public static function scans_table() {
		global $wpdb;
		return $wpdb->prefix . self::SCANS_TABLE;
	}

	/**
	 * Full name of the audit log table.
	 *
	 * @return string
	 */
	public static function audit_table() {
		global $wpdb;
		return $wpdb->prefix . self::AUDIT_TABLE;
	}

	/**
	 * Plugin settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$defaults = array(
			'scan_schedule'      => 'disabled', // disabled|weekly|monthly.
			'revision_retention' => 5,
			'trash_age_days'     => 30,
			'exclusions'         => '',
		);
		$settings = get_option( self::OPTION_SETTINGS, array() );
		return wp_parse_args( is_array( $settings ) ? $settings : array(), $defaults );
	}

	/**
	 * Activation: create tables, defaults, schedule.
	 */
	public static function activate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$scans           = self::scans_table();
		$audit           = self::audit_table();

		dbDelta(
			"CREATE TABLE {$scans} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				run_id VARCHAR(32) NOT NULL DEFAULT '',
				path VARCHAR(1024) NOT NULL DEFAULT '',
				parent_path VARCHAR(1024) NOT NULL DEFAULT '',
				item_type VARCHAR(10) NOT NULL DEFAULT 'dir',
				depth SMALLINT NOT NULL DEFAULT 0,
				size_bytes BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				file_count BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				flags VARCHAR(500) NOT NULL DEFAULT '',
				scanned_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
				PRIMARY KEY  (id),
				KEY run_depth (run_id, depth),
				KEY path_idx (path(191))
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$audit} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				action VARCHAR(100) NOT NULL DEFAULT '',
				details TEXT NULL,
				bytes_reclaimed BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				rows_affected BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
				PRIMARY KEY  (id),
				KEY action_idx (action),
				KEY created_idx (created_at)
			) {$charset_collate};"
		);

		add_option( self::OPTION_SETTINGS, self::get_settings() );
		add_option( 'blt_optimized_db_version', BLT_OPTIMIZED_VERSION );

		self::schedule_scan_event( self::get_settings() );
	}

	/**
	 * Deactivation: clear scheduled events. Data is kept; uninstall.php removes it.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'blt_optimized_scheduled_scan' );
		wp_clear_scheduled_hook( 'blt_optimized_scan_tick_event' );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'blt_optimized_scan_tick_event' );
			as_unschedule_all_actions( 'blt_optimized_scheduled_scan' );
		}
	}

	/**
	 * Re-schedule the auto-scan when settings change.
	 *
	 * @param mixed $old_value Previous settings.
	 * @param mixed $value     New settings.
	 */
	public function reschedule_scan( $old_value, $value ) {
		wp_clear_scheduled_hook( 'blt_optimized_scheduled_scan' );
		self::schedule_scan_event( wp_parse_args( is_array( $value ) ? $value : array(), self::get_settings() ) );
	}

	/**
	 * Schedule the recurring scan according to settings.
	 *
	 * @param array $settings Plugin settings.
	 */
	private static function schedule_scan_event( $settings ) {
		$schedule = isset( $settings['scan_schedule'] ) ? $settings['scan_schedule'] : 'disabled';
		if ( 'disabled' === $schedule || wp_next_scheduled( 'blt_optimized_scheduled_scan' ) ) {
			return;
		}
		$recurrence = ( 'monthly' === $schedule ) ? 'blt_optimized_monthly' : 'weekly';
		wp_schedule_event( time() + HOUR_IN_SECONDS, $recurrence, 'blt_optimized_scheduled_scan' );
	}
}

register_activation_hook( __FILE__, array( 'BLT_Optimized', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BLT_Optimized', 'deactivate' ) );

/**
 * Register the monthly cron recurrence used by the scheduled scan.
 *
 * @param array $schedules Cron schedules.
 * @return array
 */
function blt_optimized_cron_schedules( $schedules ) {
	if ( ! isset( $schedules['blt_optimized_monthly'] ) ) {
		$schedules['blt_optimized_monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Once Monthly (BLT Optimized)', 'blt-optimized' ),
		);
	}
	return $schedules;
}
add_filter( 'cron_schedules', 'blt_optimized_cron_schedules' );

add_action( 'plugins_loaded', array( 'BLT_Optimized', 'instance' ) );
