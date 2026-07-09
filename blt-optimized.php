<?php
/**
 * Plugin Name:       BLT Optimized
 * Plugin URI:        https://github.com/S-FX-com/BLT-Optimized
 * Description:       Disk-usage forensics and database optimization for WordPress — folder-by-folder wp-content size breakdown, orphaned data cleanup, and table optimization. Includes an optional image-optimization module (compress + WebP via a self-hosted Cloudflare Worker). Disk/DB core is standalone with zero external dependency.
 * Version:           1.1.3
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

define( 'BLT_OPTIMIZED_VERSION', '1.1.3' );
define( 'BLT_OPTIMIZED_FILE', __FILE__ );
define( 'BLT_OPTIMIZED_DIR', plugin_dir_path( __FILE__ ) );
define( 'BLT_OPTIMIZED_URL', plugin_dir_url( __FILE__ ) );

require_once BLT_OPTIMIZED_DIR . 'includes/class-blt-optimized-audit-log.php';
require_once BLT_OPTIMIZED_DIR . 'includes/class-blt-optimized-scanner.php';
require_once BLT_OPTIMIZED_DIR . 'includes/class-blt-optimized-cleaner.php';
require_once BLT_OPTIMIZED_DIR . 'includes/class-blt-optimized-db-optimizer.php';
require_once BLT_OPTIMIZED_DIR . 'includes/class-blt-optimized-admin.php';

/**
 * Image-optimization module (optional).
 *
 * The module was originally the standalone "Blt Image Optimizer" plugin. It is
 * self-contained under the BltImageOptimizer namespace and is only booted when
 * the `enable_images` setting is on (see BLT_Optimized::boot_images()), so the
 * disk/DB core keeps its zero-external-dependency guarantee when the module is
 * off. Its classes reuse the BLT_OPTIMIZER_* constants below (aliases of the
 * base plugin's constants) so the ported code needs no path rewrites.
 */
define( 'BLT_OPTIMIZER_VERSION', BLT_OPTIMIZED_VERSION );
define( 'BLT_OPTIMIZER_DIR', BLT_OPTIMIZED_DIR );
define( 'BLT_OPTIMIZER_URL', BLT_OPTIMIZED_URL );
define( 'BLT_OPTIMIZER_FILE', BLT_OPTIMIZED_FILE );
define( 'BLT_OPTIMIZER_BASENAME', plugin_basename( BLT_OPTIMIZED_FILE ) );

/**
 * Autoloader for the BltImageOptimizer namespace.
 *
 * Maps BltImageOptimizer\Foo_Bar to includes/images/class-blt-foo-bar.php,
 * falling back to admin/images/. Only the image module uses this namespace;
 * the disk/DB core is un-namespaced and loaded via the require_once calls above.
 */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'BltImageOptimizer\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$relative = strtolower( str_replace( '_', '-', $relative ) );
		$file     = 'class-blt-' . $relative . '.php';

		$candidates = array(
			BLT_OPTIMIZED_DIR . 'includes/images/' . $file,
			BLT_OPTIMIZED_DIR . 'admin/images/' . $file,
		);

		foreach ( $candidates as $path ) {
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);

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

	// Without this, PUC installs GitHub's auto-generated source archive for the
	// release tag instead of the release.yml-built zip — which never contains
	// this vendored plugin-update-checker/ directory (it's .gitignore'd), so the
	// very first auto-update would silently disable all future update checks.
	$blt_optimized_update_checker->getVcsApi()->enableReleaseAssets( '/\.zip$/' );

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
		add_action( 'update_option_' . self::OPTION_SETTINGS, array( $this, 'maybe_setup_images' ), 10, 2 );

		$this->boot_images();
	}

	/**
	 * Boot the optional image-optimization module when enabled.
	 *
	 * The module (BltImageOptimizer\Core / \Admin) wires its own upload,
	 * bulk-queue, URL-rewrite, and admin hooks. Kept behind the `enable_images`
	 * setting so the disk/DB core stays standalone when the module is off.
	 *
	 * @return void
	 */
	private function boot_images() {
		$settings = self::get_settings();
		if ( empty( $settings['enable_images'] ) ) {
			return;
		}

		if ( ! class_exists( '\\BltImageOptimizer\\Core' ) ) {
			return;
		}

		\BltImageOptimizer\Core::instance()->init();

		if ( is_admin() && class_exists( '\\BltImageOptimizer\\Admin' ) ) {
			\BltImageOptimizer\Admin::instance()->init();
		}
	}

	/**
	 * Create the image module's table + default settings the first time the
	 * module is switched on (activation may have run while it was off).
	 *
	 * @param mixed $old_value Previous settings.
	 * @param mixed $value     New settings.
	 * @return void
	 */
	public function maybe_setup_images( $old_value, $value ) {
		$was_on = is_array( $old_value ) && ! empty( $old_value['enable_images'] );
		$now_on = is_array( $value ) && ! empty( $value['enable_images'] );

		if ( $now_on && ! $was_on ) {
			self::install_images();
		}
	}

	/**
	 * Install the image module's storage (log table + seeded settings).
	 * Idempotent — safe to call whenever the module becomes active.
	 *
	 * @return void
	 */
	public static function install_images() {
		if ( class_exists( '\\BltImageOptimizer\\Logger' ) ) {
			\BltImageOptimizer\Logger::install();
		}
		if ( class_exists( '\\BltImageOptimizer\\Settings' ) ) {
			\BltImageOptimizer\Settings::seed_defaults();
		}
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
			'show_top_menu'      => 1, // Top-level admin menu.
			'show_tools_menu'    => 0, // Entry under the Tools menu.
			'enable_images'      => 0, // Optional image-optimization module (off by default).
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

		// If the image module is already enabled (e.g. re-activation after a
		// previous toggle), ensure its storage exists. When off, nothing image
		// related is created — the disk/DB core stays standalone.
		if ( ! empty( self::get_settings()['enable_images'] ) ) {
			self::install_images();
		}

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
			// Image module bulk queue.
			as_unschedule_all_actions( 'blt_optimizer_process_batch' );
			as_unschedule_all_actions( 'blt_optimizer_process_single' );
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
