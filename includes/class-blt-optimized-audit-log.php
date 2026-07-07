<?php
/**
 * Audit log — who ran what, when, and how much was reclaimed.
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every scan completion, cleanup run, and optimization pass is logged here,
 * so there is always an answer to "what exactly did this plugin do".
 */
class BLT_Optimized_Audit_Log {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Write a log entry.
	 *
	 * @param string $action          Machine-readable action slug.
	 * @param string $details         Human-readable description.
	 * @param int    $bytes_reclaimed Bytes reclaimed (0 if n/a).
	 * @param int    $rows_affected   Rows affected (0 if n/a).
	 * @return int|false Insert id or false.
	 */
	public function log( $action, $details = '', $bytes_reclaimed = 0, $rows_affected = 0 ) {
		global $wpdb;

		$inserted = $wpdb->insert(
			BLT_Optimized::audit_table(),
			array(
				'action'          => substr( (string) $action, 0, 100 ),
				'details'         => (string) $details,
				'bytes_reclaimed' => max( 0, (int) $bytes_reclaimed ),
				'rows_affected'   => max( 0, (int) $rows_affected ),
				'user_id'         => get_current_user_id(),
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%d', '%d', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Fetch log entries, newest first.
	 *
	 * @param int $limit  Max entries.
	 * @param int $offset Offset.
	 * @return array[]
	 */
	public function get_entries( $limit = 100, $offset = 0 ) {
		global $wpdb;
		$table = BLT_Optimized::audit_table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, action, details, bytes_reclaimed, rows_affected, user_id, created_at
				 FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit,
				$offset
			),
			ARRAY_A
		);
	}

	/**
	 * Total number of entries.
	 *
	 * @return int
	 */
	public function count_entries() {
		global $wpdb;
		$table = BLT_Optimized::audit_table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// phpcs:enable

	/**
	 * Stream the full log as CSV. Exits.
	 */
	public function export_csv() {
		$entries = $this->get_entries( 100000 );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="blt-optimized-audit-log-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, array( 'id', 'action', 'details', 'bytes_reclaimed', 'rows_affected', 'user_id', 'user_login', 'created_at_utc' ) );
		foreach ( $entries as $entry ) {
			$user = get_userdata( (int) $entry['user_id'] );
			fputcsv(
				$out,
				array(
					$entry['id'],
					$entry['action'],
					$entry['details'],
					$entry['bytes_reclaimed'],
					$entry['rows_affected'],
					$entry['user_id'],
					$user ? $user->user_login : '',
					$entry['created_at'],
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
