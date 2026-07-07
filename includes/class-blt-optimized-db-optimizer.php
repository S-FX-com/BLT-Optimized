<?php
/**
 * Database optimization — OPTIMIZE TABLE pass + autoloaded options audit.
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;

/**
 * Table optimization with engine awareness (InnoDB OPTIMIZE rebuilds and
 * briefly locks the table, so large InnoDB tables warn before running),
 * plus the autoloaded-options audit.
 */
class BLT_Optimized_DB_Optimizer {

	/**
	 * InnoDB tables above this size get an explicit warning before OPTIMIZE.
	 */
	const INNODB_WARN_BYTES = 100 * MB_IN_BYTES;

	/**
	 * Single autoloaded options above this size are flagged.
	 */
	const AUTOLOAD_FLAG_BYTES = 100 * KB_IN_BYTES;

	/**
	 * Audit log.
	 *
	 * @var BLT_Optimized_Audit_Log
	 */
	private $audit_log;

	/**
	 * Constructor.
	 *
	 * @param BLT_Optimized_Audit_Log $audit_log Audit log instance.
	 */
	public function __construct( BLT_Optimized_Audit_Log $audit_log ) {
		$this->audit_log = $audit_log;
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Stats for all tables in this site's prefix, engine detection surfaced
	 * first so the UI can warn before OPTIMIZE runs.
	 *
	 * @return array[]
	 */
	public function get_table_stats() {
		global $wpdb;

		$tables = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT table_name AS name, engine, table_rows AS rows_est,
						(data_length + index_length) AS bytes, data_free
				 FROM information_schema.TABLES
				 WHERE table_schema = %s AND table_name LIKE %s
				 ORDER BY (data_length + index_length) DESC',
				DB_NAME,
				$wpdb->esc_like( $wpdb->prefix ) . '%'
			),
			ARRAY_A
		);

		$stats = array();
		foreach ( (array) $tables as $table ) {
			$engine  = (string) $table['engine'];
			$bytes   = (int) $table['bytes'];
			$stats[] = array(
				'name'        => $table['name'],
				'engine'      => $engine,
				'rows_est'    => (int) $table['rows_est'],
				'bytes'       => $bytes,
				'data_free'   => (int) $table['data_free'],
				'is_myisam'   => ( 'MyISAM' === $engine ),
				'innodb_warn' => ( 'InnoDB' === $engine && $bytes > self::INNODB_WARN_BYTES ),
			);
		}
		return $stats;
	}

	/**
	 * Total database size for this prefix.
	 *
	 * @return int Bytes.
	 */
	public function get_db_size() {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(data_length + index_length),0) FROM information_schema.TABLES
				 WHERE table_schema = %s AND table_name LIKE %s',
				DB_NAME,
				$wpdb->esc_like( $wpdb->prefix ) . '%'
			)
		);
	}

	/**
	 * Run OPTIMIZE TABLE on the given tables. Table names are validated
	 * against the live table list — nothing user-supplied reaches SQL.
	 *
	 * @param string[] $tables Table names to optimize.
	 * @return array { optimized: string[], skipped: string[], before_bytes, after_bytes }
	 */
	public function optimize_tables( $tables ) {
		global $wpdb;

		$known = wp_list_pluck( $this->get_table_stats(), 'name' );
		$before = $this->get_db_size();

		$optimized = array();
		$skipped   = array();
		foreach ( (array) $tables as $table ) {
			if ( ! in_array( $table, $known, true ) ) {
				$skipped[] = $table;
				continue;
			}
			$result = $wpdb->query( 'OPTIMIZE TABLE `' . str_replace( '`', '', $table ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( false !== $result ) {
				$optimized[] = $table;
			} else {
				$skipped[] = $table;
			}
		}

		$after = $this->get_db_size();

		$this->audit_log->log(
			'optimize_tables',
			sprintf(
				/* translators: 1: table count, 2: before size, 3: after size. */
				__( 'OPTIMIZE TABLE on %1$d tables. DB size before: %2$s, after: %3$s', 'blt-optimized' ),
				count( $optimized ),
				size_format( $before ),
				size_format( $after )
			),
			max( 0, $before - $after ),
			count( $optimized )
		);

		return array(
			'optimized'    => $optimized,
			'skipped'      => $skipped,
			'before_bytes' => $before,
			'after_bytes'  => $after,
		);
	}

	/**
	 * Autoloaded options audit: total autoload payload, the top N largest
	 * options, and any single option over the flag threshold. This is the
	 * most common real-world cause of slow wp-admin that nobody looks at.
	 *
	 * @param int $limit Number of top options to list.
	 * @return array
	 */
	public function autoload_audit( $limit = 20 ) {
		global $wpdb;

		// WP 6.6+ uses on/off/auto-on/auto variants alongside legacy yes/no.
		$autoload_on = "autoload NOT IN ('no', 'off', 'auto-off')";

		$total = $wpdb->get_row(
			"SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(option_value)),0) AS bytes
			 FROM {$wpdb->options} WHERE {$autoload_on}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$top = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, LENGTH(option_value) AS bytes, autoload
				 FROM {$wpdb->options} WHERE {$autoload_on}
				 ORDER BY LENGTH(option_value) DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit
			),
			ARRAY_A
		);

		$flagged = array();
		foreach ( (array) $top as $option ) {
			if ( (int) $option['bytes'] > self::AUTOLOAD_FLAG_BYTES ) {
				$flagged[] = $option['option_name'];
			}
		}

		return array(
			'total_count' => (int) $total['cnt'],
			'total_bytes' => (int) $total['bytes'],
			'top'         => array_map(
				static function ( $option ) {
					return array(
						'name'     => $option['option_name'],
						'bytes'    => (int) $option['bytes'],
						'autoload' => $option['autoload'],
						'flagged'  => (int) $option['bytes'] > self::AUTOLOAD_FLAG_BYTES,
					);
				},
				(array) $top
			),
			'flagged'     => $flagged,
			'threshold'   => self::AUTOLOAD_FLAG_BYTES,
		);
	}

	/**
	 * Remaining MyISAM tables (WP core has defaulted to InnoDB since 5.5;
	 * these are usually legacy sites or older plugins).
	 *
	 * @return array[]
	 */
	public function myisam_tables() {
		return array_values(
			array_filter(
				$this->get_table_stats(),
				static function ( $table ) {
					return $table['is_myisam'];
				}
			)
		);
	}

	/**
	 * Snapshot for the before/after summary: DB size, top tables, autoload size.
	 *
	 * @param int $top_tables Number of top tables to include.
	 * @return array
	 */
	public function summary_snapshot( $top_tables = 10 ) {
		$stats    = $this->get_table_stats();
		$autoload = $this->autoload_audit( 1 );
		return array(
			'db_bytes'       => $this->get_db_size(),
			'table_count'    => count( $stats ),
			'top_tables'     => array_slice(
				array_map(
					static function ( $table ) {
						return array(
							'name'   => $table['name'],
							'bytes'  => $table['bytes'],
							'engine' => $table['engine'],
						);
					},
					$stats
				),
				0,
				$top_tables
			),
			'autoload_bytes' => $autoload['total_bytes'],
			'generated_at'   => time(),
		);
	}

	// phpcs:enable
}
