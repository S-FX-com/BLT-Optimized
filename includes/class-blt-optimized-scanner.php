<?php
/**
 * Disk usage scanner — batched, resumable BFS scan of wp-content.
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scans wp-content breadth-first in time-boxed ticks, persisting the queue
 * between ticks so a scan survives timeouts, deploys, and restarts. Uses
 * `du -sb` when exec() is available, falling back to a pure-PHP recursive
 * iterator on hosts where exec() is disabled.
 */
class BLT_Optimized_Scanner {

	const STATE_OPTION     = 'blt_optimized_scan_state';
	const LAST_SCAN_OPTION = 'blt_optimized_last_scan';

	/**
	 * Seconds of work per tick. Kept under typical 30s shared-hosting limits.
	 */
	const TICK_BUDGET = 15;

	/**
	 * Directory depth below ABSPATH that gets its own tree node. Anything
	 * deeper is rolled into its parent as a single recursive total. Depth 4
	 * covers wp-content/uploads/2024/08 and plugin subfolders.
	 */
	const MAX_DEPTH = 4;

	/**
	 * Notable-file thresholds.
	 */
	const LOG_FILE_MIN_BYTES     = MB_IN_BYTES;
	const ARCHIVE_FILE_MIN_BYTES = 10 * MB_IN_BYTES;
	const MAX_NOTABLE_FILES      = 500;

	/**
	 * How many "largest single files" to track across a scan.
	 */
	const TOP_FILES_LIMIT = 20;

	/**
	 * Audit log.
	 *
	 * @var BLT_Optimized_Audit_Log
	 */
	private $audit_log;

	/**
	 * Cached exec()/du availability for this request.
	 *
	 * @var bool|null
	 */
	private $du_available = null;

	/**
	 * Running list of the largest individual files seen this run, kept sorted
	 * largest-first and trimmed to TOP_FILES_LIMIT. Loaded from scan state at
	 * the start of each tick and written back at the end so it survives the
	 * batched, multi-tick scan. Each entry: [ path, bytes ].
	 *
	 * @var array[]
	 */
	private $run_top_files = array();

	/**
	 * Constructor.
	 *
	 * @param BLT_Optimized_Audit_Log $audit_log Audit log instance.
	 */
	public function __construct( BLT_Optimized_Audit_Log $audit_log ) {
		$this->audit_log = $audit_log;
	}

	/* ------------------------------------------------------------------ */
	/* Scan lifecycle                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Current scan state.
	 *
	 * @return array
	 */
	public function get_state() {
		$defaults = array(
			'status'       => 'idle', // idle|running|completed|cancelled.
			'run_id'       => '',
			'queue'        => array(),
			'dirs_scanned' => 0,
			'files_seen'   => 0,
			'bytes_seen'   => 0,
			'method'       => '',
			'started_at'   => 0,
			'finished_at'  => 0,
			'trigger'      => 'manual',
		);
		$state    = get_option( self::STATE_OPTION, array() );
		return wp_parse_args( is_array( $state ) ? $state : array(), $defaults );
	}

	/**
	 * Persist scan state.
	 *
	 * @param array $state State array.
	 */
	private function save_state( $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Start a new scan. Seeds the BFS queue with wp-content, plus wp-admin
	 * and wp-includes as single reference figures (never drilled into).
	 *
	 * @param string $trigger 'manual' or 'scheduled'.
	 * @return array|WP_Error New state, or error if a scan is already running.
	 */
	public function start_scan( $trigger = 'manual' ) {
		$state = $this->get_state();
		if ( 'running' === $state['status'] ) {
			return new WP_Error( 'blt_scan_running', __( 'A scan is already in progress.', 'blt-optimized' ) );
		}

		/**
		 * Fires before a disk scan starts.
		 *
		 * @param string $trigger 'manual' or 'scheduled'.
		 */
		do_action( 'blt_optimized_before_scan', $trigger );

		$run_id = 'scan_' . gmdate( 'YmdHis' ) . '_' . wp_generate_password( 6, false );

		$state = array(
			'status'       => 'running',
			'run_id'       => $run_id,
			'queue'        => array(
				// Item: [ relative path, depth, force_leaf ].
				array( $this->relative_path( WP_CONTENT_DIR ), 0, false ),
				array( 'wp-admin', 0, true ),
				array( 'wp-includes', 0, true ),
			),
			'dirs_scanned' => 0,
			'files_seen'   => 0,
			'bytes_seen'   => 0,
			'method'       => $this->du_available() ? 'du' : 'php',
			'started_at'   => time(),
			'finished_at'  => 0,
			'trigger'      => $trigger,
		);
		$this->save_state( $state );

		return $state;
	}

	/**
	 * Cancel a running scan.
	 */
	public function cancel_scan() {
		$state = $this->get_state();
		if ( 'running' === $state['status'] ) {
			$state['status']      = 'cancelled';
			$state['queue']       = array();
			$state['finished_at'] = time();
			$this->save_state( $state );
			$this->delete_run_rows( $state['run_id'] );
		}
	}

	/**
	 * Entry point for the scheduled (cron) scan.
	 */
	public function run_scheduled_scan() {
		$started = $this->start_scan( 'scheduled' );
		if ( ! is_wp_error( $started ) ) {
			$this->queue_background_tick();
		}
	}

	/**
	 * Background tick handler (Action Scheduler or WP-Cron). Processes one
	 * time-boxed batch and chains the next tick until the queue drains.
	 */
	public function background_tick() {
		$state = $this->get_state();
		if ( 'running' !== $state['status'] ) {
			return;
		}
		$state = $this->process_tick();
		if ( 'running' === $state['status'] ) {
			$this->queue_background_tick();
		}
	}

	/**
	 * Chain the next background tick. Uses Action Scheduler when present
	 * (e.g. bundled with WooCommerce), otherwise a WP-Cron single event.
	 */
	public function queue_background_tick() {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'blt_optimized_scan_tick_event', array(), 'blt-optimized' );
		} elseif ( ! wp_next_scheduled( 'blt_optimized_scan_tick_event' ) ) {
			wp_schedule_single_event( time() + 5, 'blt_optimized_scan_tick_event' );
		}
	}

	/**
	 * Process one time-boxed tick of the BFS queue.
	 *
	 * @return array Updated state.
	 */
	public function process_tick() {
		$state = $this->get_state();
		if ( 'running' !== $state['status'] ) {
			return $state;
		}

		$deadline   = microtime( true ) + (float) apply_filters( 'blt_optimized_tick_budget', self::TICK_BUDGET );
		$exclusions = $this->get_exclusions();

		$this->run_top_files = ( isset( $state['top_files'] ) && is_array( $state['top_files'] ) ) ? $state['top_files'] : array();

		while ( ! empty( $state['queue'] ) && microtime( true ) < $deadline ) {
			list( $rel_path, $depth, $force_leaf ) = array_pad( array_shift( $state['queue'] ), 3, false );

			$abs_path = $this->absolute_path( $rel_path );
			if ( ! is_dir( $abs_path ) || is_link( $abs_path ) || $this->is_excluded( $rel_path, $exclusions ) ) {
				continue;
			}

			if ( $force_leaf || $depth >= $this->max_depth() ) {
				$this->scan_leaf( $state, $rel_path, $depth );
			} else {
				$this->scan_branch( $state, $rel_path, $depth, $exclusions );
			}

			$state['dirs_scanned']++;
		}

		$state['top_files'] = $this->run_top_files;

		if ( empty( $state['queue'] ) ) {
			$this->finalize_scan( $state );
			$state['status']      = 'completed';
			$state['finished_at'] = time();
		}

		$this->save_state( $state );
		return $state;
	}

	/* ------------------------------------------------------------------ */
	/* Directory processing                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Scan a branch directory: sum its direct files, record notable files,
	 * and enqueue its subdirectories for later ticks.
	 *
	 * @param array  $state      Scan state (by reference).
	 * @param string $rel_path   Relative path.
	 * @param int    $depth      Depth below ABSPATH root nodes.
	 * @param array  $exclusions Exclusion patterns.
	 */
	private function scan_branch( &$state, $rel_path, $depth, $exclusions ) {
		$abs_path   = $this->absolute_path( $rel_path );
		$own_bytes  = 0;
		$own_files  = 0;
		$entries    = @scandir( $abs_path );

		if ( false === $entries ) {
			return;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$child_abs = $abs_path . '/' . $entry;
			$child_rel = $rel_path . '/' . $entry;

			if ( is_link( $child_abs ) ) {
				continue;
			}

			if ( is_dir( $child_abs ) ) {
				if ( ! $this->is_excluded( $child_rel, $exclusions ) ) {
					$state['queue'][] = array( $child_rel, $depth + 1, false );
				}
				continue;
			}

			$size       = (int) @filesize( $child_abs );
			$own_bytes += $size;
			$own_files++;
			$this->record_top_file( $child_rel, $size );
			$this->maybe_record_notable_file( $state, $child_rel, $entry, $size, $depth + 1 );
		}

		$this->insert_row( $state['run_id'], $rel_path, $depth, $own_bytes, $own_files );
		$state['files_seen'] += $own_files;
		$state['bytes_seen'] += $own_bytes;
	}

	/**
	 * Scan a leaf directory: record its full recursive size as one node.
	 *
	 * @param array  $state    Scan state (by reference).
	 * @param string $rel_path Relative path.
	 * @param int    $depth    Depth.
	 */
	private function scan_leaf( &$state, $rel_path, $depth ) {
		$abs_path = $this->absolute_path( $rel_path );
		$totals   = $this->recursive_size( $abs_path );

		$this->insert_row( $state['run_id'], $rel_path, $depth, $totals['bytes'], $totals['files'] );
		$state['files_seen'] += $totals['files'];
		$state['bytes_seen'] += $totals['bytes'];
	}

	/**
	 * Full recursive size of a directory — `du -sb` when exec() is usable,
	 * pure-PHP RecursiveDirectoryIterator otherwise.
	 *
	 * @param string $abs_path Absolute directory path.
	 * @return array { bytes: int, files: int }
	 */
	public function recursive_size( $abs_path ) {
		if ( $this->du_available() ) {
			$du = $this->du_size( $abs_path );
			if ( null !== $du ) {
				// du doesn't report file counts; count files only when cheap is
				// not possible — the count is informational, bytes are the point.
				return array(
					'bytes' => $du,
					'files' => $this->php_count_files( $abs_path ),
				);
			}
		}
		return $this->php_recursive_size( $abs_path );
	}

	/**
	 * Detect whether exec() is callable and `du` responds.
	 *
	 * @return bool
	 */
	public function du_available() {
		if ( null !== $this->du_available ) {
			return $this->du_available;
		}

		$this->du_available = false;
		if ( function_exists( 'exec' ) ) {
			$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
			if ( ! in_array( 'exec', $disabled, true ) ) {
				$output = array();
				$code   = 1;
				@exec( 'du --version 2>/dev/null', $output, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
				$this->du_available = ( 0 === $code );
			}
		}

		return $this->du_available;
	}

	/**
	 * `du -sb` size of a directory.
	 *
	 * @param string $abs_path Absolute path.
	 * @return int|null Bytes, or null on failure.
	 */
	private function du_size( $abs_path ) {
		$output = array();
		$code   = 1;
		@exec( 'du -sb ' . escapeshellarg( $abs_path ) . ' 2>/dev/null', $output, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		if ( 0 === $code && ! empty( $output[0] ) && preg_match( '/^(\d+)\s/', $output[0], $m ) ) {
			return (int) $m[1];
		}
		return null;
	}

	/**
	 * Pure-PHP recursive directory size + file count.
	 *
	 * @param string $abs_path Absolute path.
	 * @return array { bytes: int, files: int }
	 */
	private function php_recursive_size( $abs_path ) {
		$bytes = 0;
		$files = 0;
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $abs_path, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY,
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);
			foreach ( $iterator as $file ) {
				if ( $file->isFile() && ! $file->isLink() ) {
					$size   = (int) $file->getSize();
					$bytes += $size;
					$files++;
					$this->record_top_file( $this->relative_path( $file->getPathname() ), $size );
				}
			}
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Unreadable subtree — report what we could reach.
		}
		return array(
			'bytes' => $bytes,
			'files' => $files,
		);
	}

	/**
	 * Recursive file count (used alongside du, which only reports bytes).
	 *
	 * @param string $abs_path Absolute path.
	 * @return int
	 */
	private function php_count_files( $abs_path ) {
		$files = 0;
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $abs_path, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY,
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);
			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$files++;
					if ( ! $file->isLink() ) {
						$this->record_top_file( $this->relative_path( $file->getPathname() ), (int) $file->getSize() );
					}
				}
			}
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Unreadable subtree.
		}
		return $files;
	}

	/**
	 * Record individually-notable files (unbounded logs, backup archives,
	 * anything unusually large) as file rows in the scan table.
	 *
	 * @param array  $state    Scan state (by reference).
	 * @param string $rel_path Relative file path.
	 * @param string $filename Basename.
	 * @param int    $size     Bytes.
	 * @param int    $depth    Depth.
	 */
	private function maybe_record_notable_file( &$state, $rel_path, $filename, $size, $depth ) {
		if ( empty( $state['notable_count'] ) ) {
			$state['notable_count'] = 0;
		}
		if ( $state['notable_count'] >= self::MAX_NOTABLE_FILES ) {
			return;
		}

		$flags = array();
		$lower = strtolower( $filename );

		if ( preg_match( '/\.(log|txt)$/', $lower ) && ( $size >= self::LOG_FILE_MIN_BYTES && ( false !== strpos( $lower, 'log' ) || 'debug.log' === $lower ) ) ) {
			$flags[] = 'log-file';
		} elseif ( in_array( $lower, array( 'debug.log', 'error_log', 'php_errorlog' ), true ) && $size >= self::LOG_FILE_MIN_BYTES ) {
			$flags[] = 'log-file';
		}

		if ( preg_match( '/\.(zip|gz|tar|tgz|wpress|sql|sqlite|bak|rar|7z)$/', $lower ) && $size >= self::ARCHIVE_FILE_MIN_BYTES ) {
			$flags[] = 'archive-file';
		}

		if ( empty( $flags ) && $size >= 100 * MB_IN_BYTES ) {
			$flags[] = 'large-file';
		}

		if ( empty( $flags ) ) {
			return;
		}

		$this->insert_row( $state['run_id'], $rel_path, $depth, $size, 1, 'file', $flags );
		$state['notable_count']++;
	}

	/**
	 * Offer a file to the "largest files" tracker. The list is kept sorted
	 * largest-first and capped at TOP_FILES_LIMIT; a file smaller than the
	 * current smallest tracked file is rejected without a sort, so the common
	 * case over millions of files is a single comparison.
	 *
	 * @param string $rel_path Relative file path.
	 * @param int    $size     Size in bytes.
	 */
	private function record_top_file( $rel_path, $size ) {
		if ( $size <= 0 ) {
			return;
		}

		$count = count( $this->run_top_files );
		if ( $count >= self::TOP_FILES_LIMIT && $size <= $this->run_top_files[ $count - 1 ]['bytes'] ) {
			return;
		}

		$this->run_top_files[] = array(
			'path'  => $rel_path,
			'bytes' => $size,
		);

		usort(
			$this->run_top_files,
			static function ( $a, $b ) {
				return $b['bytes'] <=> $a['bytes'];
			}
		);

		if ( count( $this->run_top_files ) > self::TOP_FILES_LIMIT ) {
			$this->run_top_files = array_slice( $this->run_top_files, 0, self::TOP_FILES_LIMIT );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Finalization                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Roll child sizes up into parents, apply space-hog signature flags,
	 * detect orphaned plugin folders and inactive themes, then publish the
	 * run and discard rows from previous runs.
	 *
	 * @param array $state Scan state.
	 */
	private function finalize_scan( $state ) {
		global $wpdb;

		$table  = BLT_Optimized::scans_table();
		$run_id = $state['run_id'];

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, path, parent_path, item_type, depth, size_bytes, file_count, flags FROM {$table} WHERE run_id = %s AND item_type = 'dir'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$run_id
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( $rows ) {
			// Index by path, then accumulate children into parents deepest-first.
			$by_path = array();
			foreach ( $rows as $i => $row ) {
				$by_path[ $row['path'] ] = $i;
			}
			usort( $rows, static function ( $a, $b ) {
				return (int) $b['depth'] - (int) $a['depth'];
			} );
			$totals = array();
			foreach ( $rows as $row ) {
				$path                       = $row['path'];
				$totals[ $path ]['bytes']   = ( $totals[ $path ]['bytes'] ?? 0 ) + (int) $row['size_bytes'];
				$totals[ $path ]['files']   = ( $totals[ $path ]['files'] ?? 0 ) + (int) $row['file_count'];
				$parent                     = $row['parent_path'];
				if ( '' !== $parent && isset( $by_path[ $parent ] ) ) {
					$totals[ $parent ]['bytes'] = ( $totals[ $parent ]['bytes'] ?? 0 ) + $totals[ $path ]['bytes'];
					$totals[ $parent ]['files'] = ( $totals[ $parent ]['files'] ?? 0 ) + $totals[ $path ]['files'];
				}
			}

			$orphaned_plugin_dirs = $this->orphaned_plugin_dirs();
			$inactive_theme_dirs  = $this->inactive_theme_dirs();

			foreach ( $rows as $row ) {
				$path  = $row['path'];
				$flags = $this->signature_flags( $path );

				if ( in_array( $path, $orphaned_plugin_dirs, true ) ) {
					$flags[] = 'orphaned-plugin-folder';
				}
				if ( in_array( $path, $inactive_theme_dirs, true ) ) {
					$flags[] = 'inactive-theme';
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$table,
					array(
						'size_bytes' => $totals[ $path ]['bytes'] ?? (int) $row['size_bytes'],
						'file_count' => $totals[ $path ]['files'] ?? (int) $row['file_count'],
						'flags'      => implode( ',', array_unique( $flags ) ),
					),
					array( 'id' => $row['id'] ),
					array( '%d', '%d', '%s' ),
					array( '%d' )
				);
			}
		}

		// Drop rows belonging to older runs now that this one is complete.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE run_id != %s", $run_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$summary = array(
			'run_id'       => $run_id,
			'completed_at' => time(),
			'trigger'      => $state['trigger'],
			'method'       => $state['method'],
			'dirs_scanned' => $state['dirs_scanned'],
			'files_seen'   => $state['files_seen'],
			'bytes_seen'   => $state['bytes_seen'],
			'image_sizes'  => $this->registered_image_sizes_report(),
			'top_files'    => ( isset( $state['top_files'] ) && is_array( $state['top_files'] ) ) ? array_slice( $state['top_files'], 0, self::TOP_FILES_LIMIT ) : array(),
		);
		update_option( self::LAST_SCAN_OPTION, $summary, false );

		$this->audit_log->log(
			'disk_scan_completed',
			sprintf(
				/* translators: 1: directory count, 2: human-readable size, 3: scan method. */
				__( 'Disk scan completed: %1$d directories, %2$s total, method: %3$s', 'blt-optimized' ),
				$state['dirs_scanned'],
				size_format( $state['bytes_seen'] ),
				$state['method']
			),
			0,
			$state['dirs_scanned']
		);

		/**
		 * Fires after a disk scan completes.
		 *
		 * @param array $summary Scan summary.
		 */
		do_action( 'blt_optimized_after_scan', $summary );
	}

	/* ------------------------------------------------------------------ */
	/* Detections                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Known space-hog signature list. Matched against relative paths.
	 *
	 * @return array[] Each: slug, label, pattern (regex), category.
	 */
	public function get_signatures() {
		$signatures = array(
			array(
				'slug'     => 'updraft-backups',
				'label'    => __( 'UpdraftPlus backup archives', 'blt-optimized' ),
				'pattern'  => '#^wp-content/updraft(/|$)#',
				'category' => 'backup-archives',
			),
			array(
				'slug'     => 'ai1wm-backups',
				'label'    => __( 'All-in-One WP Migration backups', 'blt-optimized' ),
				'pattern'  => '#^wp-content/(ai1wm-backups|uploads/ai1wm-backups)(/|$)#',
				'category' => 'backup-archives',
			),
			array(
				'slug'     => 'duplicator-backups',
				'label'    => __( 'Duplicator packages', 'blt-optimized' ),
				'pattern'  => '#^wp-content/(backups-dup-lite|backups-dup-pro|uploads/duplicator)(/|$)#',
				'category' => 'backup-archives',
			),
			array(
				'slug'     => 'backwpup-backups',
				'label'    => __( 'BackWPup backup archives', 'blt-optimized' ),
				'pattern'  => '#^wp-content/uploads/backwpup[^/]*(/|$)#',
				'category' => 'backup-archives',
			),
			array(
				'slug'     => 'generic-backups',
				'label'    => __( 'Backup directory', 'blt-optimized' ),
				'pattern'  => '#^wp-content/(backups?|wp-snapshots)(/|$)#',
				'category' => 'backup-archives',
			),
			array(
				'slug'     => 'cache-dir',
				'label'    => __( 'Cache directory (verify the owning plugin is still active)', 'blt-optimized' ),
				'pattern'  => '#^wp-content/(cache|w3tc-config|wp-rocket-config|litespeed)(/|$)#',
				'category' => 'cache',
			),
			array(
				'slug'     => 'elementor-uploads',
				'label'    => __( 'Elementor generated assets', 'blt-optimized' ),
				'pattern'  => '#^wp-content/uploads/elementor(/|$)#',
				'category' => 'plugin-generated',
			),
			array(
				'slug'     => 'woocommerce-uploads',
				'label'    => __( 'WooCommerce uploads', 'blt-optimized' ),
				'pattern'  => '#^wp-content/uploads/woocommerce_uploads(/|$)#',
				'category' => 'plugin-generated',
			),
			array(
				'slug'     => 'wc-logs',
				'label'    => __( 'WooCommerce logs', 'blt-optimized' ),
				'pattern'  => '#^wp-content/uploads/wc-logs(/|$)#',
				'category' => 'logs',
			),
			array(
				'slug'     => 'git-dir',
				'label'    => __( 'Deployed .git directory', 'blt-optimized' ),
				'pattern'  => '#/\.git(/|$)#',
				'category' => 'dev-artifacts',
			),
			array(
				'slug'     => 'node-modules',
				'label'    => __( 'node_modules directory', 'blt-optimized' ),
				'pattern'  => '#/node_modules(/|$)#',
				'category' => 'dev-artifacts',
			),
			array(
				'slug'     => 'multisite-uploads',
				'label'    => __( 'Multisite per-site uploads', 'blt-optimized' ),
				'pattern'  => '#^wp-content/uploads/sites(/|$)#',
				'category' => 'multisite',
			),
			array(
				'slug'     => 'upgrade-leftovers',
				'label'    => __( 'Leftover upgrade working files', 'blt-optimized' ),
				'pattern'  => '#^wp-content/upgrade(/|$)#',
				'category' => 'cache',
			),
		);

		/**
		 * Extend or modify the known space-hog signature list.
		 *
		 * @param array[] $signatures Signature definitions.
		 */
		return apply_filters( 'blt_optimized_space_hog_signatures', $signatures );
	}

	/**
	 * Signature flags matching a relative path.
	 *
	 * @param string $rel_path Relative path.
	 * @return string[] Flag slugs.
	 */
	private function signature_flags( $rel_path ) {
		$flags = array();
		foreach ( $this->get_signatures() as $signature ) {
			if ( preg_match( $signature['pattern'], $rel_path ) ) {
				$flags[] = $signature['slug'];
			}
		}
		return $flags;
	}

	/**
	 * Plugin folders on disk with no matching installed-plugin header —
	 * leftovers from FTP-deleted plugins. Flag only; never auto-delete.
	 *
	 * @return string[] Relative paths.
	 */
	public function orphaned_plugin_dirs() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$known = array();
		foreach ( array_keys( get_plugins() ) as $plugin_file ) {
			$dir = dirname( $plugin_file );
			if ( '.' !== $dir ) {
				$known[ $dir ] = true;
			}
		}

		$plugins_rel = $this->relative_path( WP_PLUGIN_DIR );
		$orphans     = array();
		$entries     = @scandir( WP_PLUGIN_DIR );
		if ( false === $entries ) {
			return $orphans;
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry || ! is_dir( WP_PLUGIN_DIR . '/' . $entry ) ) {
				continue;
			}
			if ( ! isset( $known[ $entry ] ) ) {
				$orphans[] = $plugins_rel . '/' . $entry;
			}
		}
		return $orphans;
	}

	/**
	 * Theme folders that are installed but not the active theme (or its parent).
	 *
	 * @return string[] Relative paths.
	 */
	public function inactive_theme_dirs() {
		$active = array( get_stylesheet(), get_template() );
		$rel    = $this->relative_path( get_theme_root() );
		$dirs   = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			if ( ! in_array( $slug, $active, true ) ) {
				$dirs[] = $rel . '/' . $slug;
			}
		}
		return $dirs;
	}

	/**
	 * Registered image sizes report — the crop multiplier that silently
	 * multiplies every upload. Reported, never auto-deleted.
	 *
	 * @return array
	 */
	public function registered_image_sizes_report() {
		$sizes = function_exists( 'wp_get_registered_image_subsizes' ) ? wp_get_registered_image_subsizes() : array();
		return array(
			'count' => count( $sizes ),
			'sizes' => array_keys( $sizes ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Results access                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Last completed scan summary.
	 *
	 * @return array|null
	 */
	public function get_last_scan() {
		$summary = get_option( self::LAST_SCAN_OPTION );
		return is_array( $summary ) ? $summary : null;
	}

	/**
	 * All rows for the last completed run, for the tree UI / CSV export.
	 *
	 * @return array[]
	 */
	public function get_results() {
		$summary = $this->get_last_scan();
		if ( ! $summary ) {
			return array();
		}

		global $wpdb;
		$table = BLT_Optimized::scans_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT path, parent_path, item_type, depth, size_bytes, file_count, flags, scanned_at FROM {$table} WHERE run_id = %s ORDER BY depth ASC, size_bytes DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$summary['run_id']
			),
			ARRAY_A
		);
	}

	/**
	 * Top N largest items (dirs beyond the top-level roots, plus notable files).
	 *
	 * @param int $limit Number of items.
	 * @return array[]
	 */
	public function get_top_hogs( $limit = 20 ) {
		$summary = $this->get_last_scan();
		if ( ! $summary ) {
			return array();
		}

		global $wpdb;
		$table = BLT_Optimized::scans_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT path, item_type, depth, size_bytes, file_count, flags FROM {$table} WHERE run_id = %s AND depth >= 1 ORDER BY size_bytes DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$summary['run_id'],
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * The largest individual files from the last completed scan, recorded live
	 * during the scan (no separate pass). Largest-first.
	 *
	 * @param int $limit Maximum number of files to return.
	 * @return array[] Each: path, bytes.
	 */
	public function get_top_files( $limit = self::TOP_FILES_LIMIT ) {
		$summary = $this->get_last_scan();
		if ( ! $summary || empty( $summary['top_files'] ) || ! is_array( $summary['top_files'] ) ) {
			return array();
		}
		return array_slice( $summary['top_files'], 0, max( 1, (int) $limit ) );
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Insert a scan row.
	 *
	 * @param string   $run_id    Run identifier.
	 * @param string   $rel_path  Relative path.
	 * @param int      $depth     Depth.
	 * @param int      $bytes     Size in bytes.
	 * @param int      $files     File count.
	 * @param string   $item_type 'dir' or 'file'.
	 * @param string[] $flags     Flag slugs.
	 */
	private function insert_row( $run_id, $rel_path, $depth, $bytes, $files, $item_type = 'dir', $flags = array() ) {
		global $wpdb;

		$parent = ( 0 === $depth ) ? '' : str_replace( '\\', '/', dirname( $rel_path ) );
		if ( '.' === $parent ) {
			$parent = '';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			BLT_Optimized::scans_table(),
			array(
				'run_id'      => $run_id,
				'path'        => $rel_path,
				'parent_path' => $parent,
				'item_type'   => $item_type,
				'depth'       => $depth,
				'size_bytes'  => $bytes,
				'file_count'  => $files,
				'flags'       => implode( ',', $flags ),
				'scanned_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * Delete all rows for a run (used when a scan is cancelled).
	 *
	 * @param string $run_id Run identifier.
	 */
	private function delete_run_rows( $run_id ) {
		global $wpdb;
		$table = BLT_Optimized::scans_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE run_id = %s", $run_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Max tree depth (filterable).
	 *
	 * @return int
	 */
	private function max_depth() {
		return max( 1, (int) apply_filters( 'blt_optimized_max_depth', self::MAX_DEPTH ) );
	}

	/**
	 * Path relative to ABSPATH, normalized to forward slashes.
	 *
	 * @param string $abs_path Absolute path.
	 * @return string
	 */
	public function relative_path( $abs_path ) {
		$abs  = wp_normalize_path( $abs_path );
		$root = untrailingslashit( wp_normalize_path( ABSPATH ) );
		if ( 0 === strpos( $abs, $root . '/' ) ) {
			return substr( $abs, strlen( $root ) + 1 );
		}
		return ltrim( $abs, '/' );
	}

	/**
	 * Absolute path from a relative one.
	 *
	 * @param string $rel_path Relative path.
	 * @return string
	 */
	private function absolute_path( $rel_path ) {
		return untrailingslashit( wp_normalize_path( ABSPATH ) ) . '/' . $rel_path;
	}

	/**
	 * Effective exclusion patterns: admin-editable list + filter.
	 *
	 * @return string[]
	 */
	public function get_exclusions() {
		$settings = BLT_Optimized::get_settings();
		$lines    = preg_split( '/[\r\n]+/', (string) $settings['exclusions'], -1, PREG_SPLIT_NO_EMPTY );
		$lines    = array_filter( array_map( 'trim', (array) $lines ) );

		/**
		 * Extend the paths excluded from scanning.
		 *
		 * @param string[] $lines Relative paths or wildcard patterns.
		 */
		return apply_filters( 'blt_optimized_exclude_paths', array_values( $lines ) );
	}

	/**
	 * Whether a relative path matches an exclusion (exact, prefix, or wildcard).
	 *
	 * @param string   $rel_path   Relative path.
	 * @param string[] $exclusions Patterns.
	 * @return bool
	 */
	private function is_excluded( $rel_path, $exclusions ) {
		foreach ( $exclusions as $pattern ) {
			$pattern = trim( str_replace( '\\', '/', $pattern ), '/' );
			if ( '' === $pattern ) {
				continue;
			}
			if ( $rel_path === $pattern || 0 === strpos( $rel_path . '/', $pattern . '/' ) ) {
				return true;
			}
			if ( ( false !== strpos( $pattern, '*' ) || false !== strpos( $pattern, '?' ) ) && fnmatch( $pattern, $rel_path ) ) {
				return true;
			}
		}
		return false;
	}
}
