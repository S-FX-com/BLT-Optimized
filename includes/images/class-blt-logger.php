<?php
/**
 * Per-image optimization log backed by a custom DB table.
 *
 * @package BltImageOptimizer
 */

namespace BltImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for the {prefix}blt_optimizer_log table.
 */
class Logger {

	/**
	 * Schema version stored in options for upgrade checks.
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Option key for the stored schema version.
	 */
	const DB_VERSION_OPTION = 'blt_optimizer_db_version';

	/**
	 * Valid status enum values.
	 */
	const STATUSES = array( 'pending', 'processing', 'done', 'error', 'skipped' );

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'blt_optimizer_log';
	}

	/**
	 * Create or upgrade the log table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			attachment_id BIGINT UNSIGNED NOT NULL,
			size_name VARCHAR(64) NOT NULL,
			original_file VARCHAR(512),
			optimized_file VARCHAR(512),
			original_size BIGINT,
			optimized_size BIGINT,
			savings_pct DECIMAL(5,2),
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			error_message TEXT,
			processed_at DATETIME,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			INDEX attachment_idx (attachment_id),
			INDEX status_idx (status),
			UNIQUE KEY attachment_size (attachment_id, size_name)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Drop the log table (used by uninstall).
	 *
	 * @return void
	 */
	public static function drop() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name cannot be parameterized.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		delete_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Insert or update a log row for an attachment/size pair.
	 *
	 * @param int                 $attachment_id Attachment post ID.
	 * @param string              $size_name     WP size name (e.g. 'full').
	 * @param array<string,mixed> $data          Column values to set.
	 * @return int Row ID.
	 */
	public static function upsert( $attachment_id, $size_name, array $data = array() ) {
		global $wpdb;

		$table   = self::table();
		$existing = self::get_row( $attachment_id, $size_name );

		$fields = self::sanitize_row( $data );

		if ( $existing ) {
			$wpdb->update( $table, $fields, array( 'id' => $existing->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (int) $existing->id;
		}

		$fields['attachment_id'] = absint( $attachment_id );
		$fields['size_name']     = substr( (string) $size_name, 0, 64 );

		$wpdb->insert( $table, $fields ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * Mark a row's status, optionally with an error message.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $size_name     Size name.
	 * @param string $status        One of self::STATUSES.
	 * @param string $error_message Optional error detail.
	 * @return void
	 */
	public static function set_status( $attachment_id, $size_name, $status, $error_message = '' ) {
		$data = array( 'status' => $status );

		if ( in_array( $status, array( 'done', 'error', 'skipped' ), true ) ) {
			$data['processed_at'] = current_time( 'mysql' );
		}

		if ( '' !== $error_message ) {
			$data['error_message'] = $error_message;
		}

		self::upsert( $attachment_id, $size_name, $data );
	}

	/**
	 * Sanitize a row of column values before writing.
	 *
	 * @param array<string,mixed> $data Input data.
	 * @return array<string,mixed>
	 */
	private static function sanitize_row( array $data ) {
		$out = array();

		if ( isset( $data['original_file'] ) ) {
			$out['original_file'] = substr( (string) $data['original_file'], 0, 512 );
		}
		if ( isset( $data['optimized_file'] ) ) {
			$out['optimized_file'] = substr( (string) $data['optimized_file'], 0, 512 );
		}
		if ( isset( $data['original_size'] ) ) {
			$out['original_size'] = absint( $data['original_size'] );
		}
		if ( isset( $data['optimized_size'] ) ) {
			$out['optimized_size'] = absint( $data['optimized_size'] );
		}
		if ( isset( $data['savings_pct'] ) ) {
			$out['savings_pct'] = round( (float) $data['savings_pct'], 2 );
		}
		if ( isset( $data['status'] ) && in_array( $data['status'], self::STATUSES, true ) ) {
			$out['status'] = $data['status'];
		}
		if ( isset( $data['error_message'] ) ) {
			$out['error_message'] = substr( (string) $data['error_message'], 0, 2000 );
		}
		if ( isset( $data['processed_at'] ) ) {
			$out['processed_at'] = $data['processed_at'];
		}

		return $out;
	}

	/**
	 * Fetch a single row by attachment/size.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $size_name     Size name.
	 * @return object|null
	 */
	public static function get_row( $attachment_id, $size_name ) {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE attachment_id = %d AND size_name = %s LIMIT 1",
				absint( $attachment_id ),
				$size_name
			)
		);
	}

	/**
	 * Query log rows with pagination and optional status filter.
	 *
	 * @param array<string,mixed> $args Query args: status, per_page, page, orderby, order.
	 * @return array{rows:array<object>,total:int}
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$table = self::table();

		$args = wp_parse_args(
			$args,
			array(
				'status'   => '',
				'per_page' => 50,
				'page'     => 1,
				'orderby'  => 'id',
				'order'    => 'DESC',
			)
		);

		$where  = '1=1';
		$params = array();

		if ( '' !== $args['status'] && in_array( $args['status'], self::STATUSES, true ) ) {
			$where   .= ' AND status = %s';
			$params[] = $args['status'];
		}

		$allowed_orderby = array( 'id', 'attachment_id', 'savings_pct', 'processed_at', 'optimized_size' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'id';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$per_page = max( 1, absint( $args['per_page'] ) );
		$offset   = ( max( 1, absint( $args['page'] ) ) - 1 ) * $per_page;

		// Total count.
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$total = (int) $wpdb->get_var( empty( $params ) ? $count_sql : $wpdb->prepare( $count_sql, $params ) );

		// Page of rows.
		$sql           = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$query_params  = array_merge( $params, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $query_params ) );

		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Aggregate statistics for dashboards / progress.
	 *
	 * @return array<string,mixed>
	 */
	public static function stats() {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", OBJECT_K );

		$by_status = array_fill_keys( self::STATUSES, 0 );
		if ( $counts ) {
			foreach ( $counts as $status => $row ) {
				$by_status[ $status ] = (int) $row->n;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$totals = $wpdb->get_row(
			"SELECT
				COALESCE(SUM(original_size),0)  AS orig_bytes,
				COALESCE(SUM(optimized_size),0) AS opt_bytes
			FROM {$table} WHERE status = 'done'"
		);

		$orig_bytes = $totals ? (int) $totals->orig_bytes : 0;
		$opt_bytes  = $totals ? (int) $totals->opt_bytes : 0;
		$saved      = max( 0, $orig_bytes - $opt_bytes );
		$saved_pct  = $orig_bytes > 0 ? round( ( $saved / $orig_bytes ) * 100, 1 ) : 0.0;

		return array(
			'by_status'      => $by_status,
			'total'          => array_sum( $by_status ),
			'original_bytes' => $orig_bytes,
			'optimized_bytes' => $opt_bytes,
			'saved_bytes'    => $saved,
			'saved_pct'      => $saved_pct,
		);
	}

	/**
	 * Clear all log rows (keeps the table).
	 *
	 * @return void
	 */
	public static function truncate() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}
}
