<?php
/**
 * Uninstall routine — removes custom tables and options cleanly. No orphaned
 * data left behind by the plugin that cleans up orphaned data.
 *
 * @package BLT_Optimized
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}blt_optimized_scans" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}blt_optimized_audit_log" );
// Image module log table (created only when the module was enabled).
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}blt_optimizer_log" );
// phpcs:enable

delete_option( 'blt_optimized_settings' );
delete_option( 'blt_optimized_db_version' );
delete_option( 'blt_optimized_scan_state' );
delete_option( 'blt_optimized_last_scan' );

// Image module options. NOTE: option key differs by one letter from the base
// (`blt_optimizer_*` vs `blt_optimized_*`) — this is intentional, not a typo.
delete_option( 'blt_optimizer_settings' );
delete_option( 'blt_optimizer_db_version' );
delete_option( 'blt_optimizer_queue_state' );

// Durable WebP postmeta (`_blt_webp_sizes`, `_blt_optimized`) is intentionally
// LEFT INTACT: optimized files stay on disk and WordPress keeps serving them
// after the plugin is gone — the agency hand-off contract.

// Update-checker state (plugin-update-checker stores per-slug options).
delete_option( 'external_updates-blt-optimized' );

// Backup acknowledgment transients for any user.
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\\_transient\\_blt\\_optimized\\_%'
	    OR option_name LIKE '\\_transient\\_timeout\\_blt\\_optimized\\_%'"
);

wp_clear_scheduled_hook( 'blt_optimized_scheduled_scan' );
wp_clear_scheduled_hook( 'blt_optimized_scan_tick_event' );
