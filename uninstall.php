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
// phpcs:enable

delete_option( 'blt_optimized_settings' );
delete_option( 'blt_optimized_db_version' );
delete_option( 'blt_optimized_scan_state' );
delete_option( 'blt_optimized_last_scan' );

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
