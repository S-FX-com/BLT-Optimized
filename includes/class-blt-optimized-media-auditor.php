<?php
/**
 * Media / storage auditor — finds uploads that nothing uses.
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;

/**
 * Walks the dated uploads tree (uploads/YYYY/MM) folder by folder, in
 * time-boxed resumable ticks, looking for storage that nothing uses: orphan
 * files that belong to no attachment, thumbnails of image sizes the theme no
 * longer registers, pre-scaled originals, unattached and unreferenced media,
 * duplicate uploads, and the heaviest images.
 *
 * Why it is so conservative: a false positive here deletes a client's media,
 * and unlike a database row a file cannot be recovered. So:
 *
 *  - Everything WordPress or a common optimization plugin might associate with
 *    an attachment counts as "known" (main file, every size, original_image,
 *    image-editor backups, WebP/AVIF copies in both naming styles, anything
 *    mentioned anywhere in the attachment's metadata). When in doubt, a file
 *    is known.
 *  - Only uploads/YYYY/MM folders are scanned, never recursively, never through
 *    symlinks, and every path is resolved with realpath() and required to sit
 *    in that exact folder under the uploads basedir before it is touched.
 *  - Files are only deletable if their extension is a media extension, they
 *    are older than a few minutes (so an upload in flight is never caught) and
 *    no post, post meta, option, term meta or user meta mentions their name.
 *  - The three destructive fixes act only on items present in the stored audit
 *    results, and re-verify existence, ownership and "unreferenced" right
 *    before deleting.
 *
 * Unattached media and duplicates are flag-only — deleting a Media Library item
 * is a human decision.
 *
 * Hooks for site owners / integrations:
 *  - blt_optimized_media_registered_sizes (filter) extra image size names to treat as registered.
 *  - blt_optimized_media_is_referenced (filter) return true to veto deleting file name(s) (custom tables).
 *  - blt_optimized_media_min_file_age (filter) seconds a file must be untouched before it can be an orphan.
 *  - blt_optimized_media_referenced_ids (filter) attachment IDs to treat as in use.
 */
class BLT_Optimized_Media_Auditor {

	const STATE_OPTION   = 'blt_optimized_media_audit_state';
	const RESULTS_OPTION = 'blt_optimized_media_audit';
	const LOCK_TRANSIENT = 'blt_optimized_media_audit_lock';

	/**
	 * Default seconds of work per tick.
	 */
	const TICK_BUDGET = 15;

	/**
	 * Safety caps. Hitting any of them sets `truncated` on the results.
	 */
	const MAX_FOLDERS             = 600;
	const MAX_FILES_PER_FOLDER    = 20000;
	const MAX_REF_CHECKS          = 4000;
	const MAX_STORED_ORPHANS      = 5000;
	const MAX_STORED_STALE        = 3000;
	const MAX_STORED_ORIGINALS    = 3000;
	const MAX_UNATTACHED_CHECKED  = 2000;
	const MAX_DUP_INDEX           = 30000;
	const MAX_DUP_HASHES          = 4000;
	const MAX_STORED_DUP_GROUPS   = 500;
	const MAX_CONTENT_POSTS       = 50000;
	const MAX_REFERENCED_IDS      = 300000;
	const UNATTACHED_BATCH        = 50;
	const UNATTACHED_PER_STEP     = 1;
	const REF_CHUNK               = 10;
	const ATTACHMENT_CHUNK        = 150;
	const LARGEST_LIMIT           = 20;
	const FIX_MAX_ITEMS           = 2000;
	const FINDING_MAX_ARGS        = 500;
	const MIN_FILE_AGE            = 300;
	const DUP_MIN_BYTES           = 51200;
	const DUP_MAX_BYTES           = 20971520;
	const LARGE_IMAGE_BYTES       = 1048576;
	const MAX_METADATA_BYTES      = 400000;

	/**
	 * Extensions that may be reported as deletable orphans. Anything else in a
	 * dated folder is only counted in `other_unknown`.
	 */
	const ORPHAN_EXTS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'tif', 'tiff', 'svg', 'ico', 'heic', 'pdf', 'mp4', 'mov', 'webm', 'mp3', 'm4a', 'wav', 'zip' );

	/**
	 * Extensions treated as images for the "largest images" list.
	 */
	const IMAGE_EXTS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'tif', 'tiff', 'heic' );

	/**
	 * Attachment meta keys that describe an attachment's own files.
	 */
	const ATTACHMENT_FILE_KEYS = array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes', '_blt_webp_sizes' );

	/**
	 * Audit log.
	 *
	 * @var BLT_Optimized_Audit_Log
	 */
	private $audit_log;

	/**
	 * Memoised registered image size names (name => true).
	 *
	 * @var array|null
	 */
	private $registered = null;

	/**
	 * Constructor.
	 *
	 * @param BLT_Optimized_Audit_Log $audit_log Audit log instance.
	 */
	public function __construct( BLT_Optimized_Audit_Log $audit_log ) {
		$this->audit_log = $audit_log;
	}

	/* ------------------------------------------------------------------ */
	/* Lifecycle                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Start a fresh audit. Only lists the dated folders; the work happens in
	 * tick(). Previous results stay available until the new run completes.
	 *
	 * @return array Public state.
	 */
	public function start() {
		$this->release_lock();

		$uploads   = wp_get_upload_dir();
		$truncated = false;
		$folders   = array();
		if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
			$folders = $this->discover_folders( $uploads['basedir'], $truncated );
		}

		$state                     = self::default_state();
		$state['status']           = 'running';
		$state['run_id']           = 'media_' . gmdate( 'YmdHis' ) . '_' . wp_generate_password( 6, false );
		$state['started_at']       = time();
		$state['queue']            = $folders;
		$state['folders_total']    = count( $folders );
		$state['stage']            = $folders ? 'folders' : 'dups';
		$state['work']['truncated'] = $truncated;
		$this->save_state( $state );

		return $this->public_state( $state );
	}

	/**
	 * Run one time-boxed batch of work.
	 *
	 * @param float $budget Seconds to work for.
	 * @return array Public state (status running|completed|...).
	 */
	public function tick( $budget = 15.0 ) {
		$state = $this->load_state();
		if ( 'running' !== $state['status'] ) {
			return $this->public_state( $state );
		}

		$budget = max( 0.5, min( 120.0, (float) $budget ) );
		if ( ! $this->acquire_lock( $budget ) ) {
			// Another request is mid-tick on this audit; let it finish.
			return $this->public_state( $state );
		}

		try {
			$deadline = microtime( true ) + $budget;
			do {
				$this->step( $state, $deadline );
			} while ( 'running' === $state['status'] && microtime( true ) < $deadline );
			$this->save_state( $state );
		} finally {
			$this->release_lock();
		}

		return $this->public_state( $state );
	}

	/**
	 * Current state, without the bulky working data.
	 *
	 * @return array
	 */
	public function get_state() {
		return $this->public_state( $this->load_state() );
	}

	/**
	 * Abort a running audit. Stored results from earlier runs are kept.
	 *
	 * @return void
	 */
	public function cancel() {
		$state = $this->load_state();
		if ( 'running' === $state['status'] ) {
			$state['status']      = 'cancelled';
			$state['queue']       = array();
			$state['cursor']      = array();
			$state['work']        = self::default_work();
			$state['finished_at'] = time();
			$this->save_state( $state );
		}
		$this->release_lock();
	}

	/**
	 * Latest completed audit results.
	 *
	 * @return array|null
	 */
	public function get_results() {
		$results = get_option( self::RESULTS_OPTION, null );
		return is_array( $results ) ? $results : null;
	}

	/* ------------------------------------------------------------------ */
	/* State                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Default (idle) state.
	 *
	 * @return array
	 */
	private static function default_state() {
		return array(
			'status'        => 'idle', // idle|running|completed|cancelled.
			'run_id'        => '',
			'stage'         => '', // folders|dups|refs|unattached|finalize.
			'started_at'    => 0,
			'finished_at'   => 0,
			'queue'         => array(),
			'folders_total' => 0,
			'folders_done'  => 0,
			'files_checked' => 0,
			'cursor'        => array(),
			'work'          => self::default_work(),
		);
	}

	/**
	 * Default working data (partial results between ticks).
	 *
	 * @return array
	 */
	private static function default_work() {
		return array(
			'files_checked'      => 0,
			'ref_checks'         => 0,
			'orphans'            => array(),
			'orphans_referenced' => 0,
			'other_unknown'      => array(
				'count' => 0,
				'bytes' => 0,
			),
			'stale'              => array(),
			'originals'          => array(),
			'largest'            => array(),
			'dup_index'          => array(),
			'dup_entries'        => 0,
			'dup_groups'         => array(),
			'dup_hashed'         => 0,
			'duplicates'         => array(),
			'ref_init'           => false,
			'ref_ids'            => array(),
			'ref_last_id'        => 0,
			'ref_posts'          => 0,
			'ref_incomplete'     => false,
			'un_last_id'         => 0,
			'un_checked'         => 0,
			'un_pending'         => array(),
			'unattached'         => array(),
			'truncated'          => false,
		);
	}

	/**
	 * Load the full stored state.
	 *
	 * @return array
	 */
	private function load_state() {
		$stored = get_option( self::STATE_OPTION, array() );
		$state  = wp_parse_args( is_array( $stored ) ? $stored : array(), self::default_state() );

		$state['work'] = wp_parse_args( is_array( $state['work'] ) ? $state['work'] : array(), self::default_work() );
		foreach ( array( 'queue', 'cursor' ) as $key ) {
			if ( ! is_array( $state[ $key ] ) ) {
				$state[ $key ] = array();
			}
		}
		return $state;
	}

	/**
	 * Persist state (never autoloaded — it can be large).
	 *
	 * @param array $state State.
	 * @return void
	 */
	private function save_state( array $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * State as exposed to callers: progress only, no working data.
	 *
	 * @param array $state Full state.
	 * @return array
	 */
	private function public_state( array $state ) {
		$total = (int) $state['folders_total'];
		$done  = (int) $state['folders_done'];

		switch ( $state['stage'] ) {
			case 'folders':
				$progress = $total > 0 ? (int) floor( 60 * min( 1, $done / $total ) ) : 0;
				/* translators: %s: uploads folder such as 2026/10. */
				$message = ! empty( $state['queue'] ) ? sprintf( __( 'Scanning uploads/%s', 'blt-optimized' ), $state['queue'][0] ) : __( 'Scanning uploads folders', 'blt-optimized' );
				break;
			case 'dups':
				$progress = 65;
				$message  = __( 'Comparing files to find duplicates', 'blt-optimized' );
				break;
			case 'refs':
				$progress = 75;
				$message  = __( 'Reading where media is used in content', 'blt-optimized' );
				break;
			case 'unattached':
				$progress = 85;
				$message  = __( 'Checking unattached media', 'blt-optimized' );
				break;
			default:
				$progress = 'completed' === $state['status'] ? 100 : 0;
				$message  = 'completed' === $state['status'] ? __( 'Media audit complete', 'blt-optimized' ) : '';
		}

		return array(
			'status'        => $state['status'],
			'run_id'        => $state['run_id'],
			'stage'         => $state['stage'],
			'started_at'    => (int) $state['started_at'],
			'finished_at'   => (int) $state['finished_at'],
			'folders_total' => $total,
			'folders_done'  => $done,
			'files_checked' => max( (int) $state['work']['files_checked'], (int) $state['files_checked'] ),
			'progress'      => $progress,
			'message'       => $message,
		);
	}

	/**
	 * Take the tick lock (best effort — prevents two overlapping ticks from
	 * clobbering each other's saved state).
	 *
	 * @param float $budget Tick budget in seconds.
	 * @return bool
	 */
	private function acquire_lock( $budget ) {
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return false;
		}
		set_transient( self::LOCK_TRANSIENT, time(), (int) ceil( $budget ) + 60 );
		return true;
	}

	/**
	 * Release the tick lock.
	 *
	 * @return void
	 */
	private function release_lock() {
		delete_transient( self::LOCK_TRANSIENT );
	}

	/**
	 * List uploads/YYYY/MM folders, newest first. Symlinks are never followed.
	 *
	 * @param string $basedir   Uploads basedir.
	 * @param bool   $truncated Set true when the folder cap is hit.
	 * @return string[]
	 */
	private function discover_folders( $basedir, &$truncated ) {
		$folders = array();
		$years   = @scandir( $basedir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $years ) ) {
			return $folders;
		}

		foreach ( $years as $year ) {
			if ( ! preg_match( '/^\d{4}$/', $year ) ) {
				continue;
			}
			$year_dir = $basedir . '/' . $year;
			if ( is_link( $year_dir ) || ! is_dir( $year_dir ) ) {
				continue;
			}
			$months = @scandir( $year_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! is_array( $months ) ) {
				continue;
			}
			foreach ( $months as $month ) {
				if ( ! preg_match( '/^(0[1-9]|1[0-2])$/', $month ) ) {
					continue;
				}
				$month_dir = $year_dir . '/' . $month;
				if ( is_link( $month_dir ) || ! is_dir( $month_dir ) ) {
					continue;
				}
				if ( count( $folders ) >= self::MAX_FOLDERS ) {
					$truncated = true;
					break 2;
				}
				$folders[] = $year . '/' . $month;
			}
		}

		rsort( $folders );
		return $folders;
	}

	/* ------------------------------------------------------------------ */
	/* Stage dispatcher                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Do one unit of work for the current stage.
	 *
	 * @param array $state    State (by reference).
	 * @param float $deadline Microtime deadline.
	 * @return void
	 */
	private function step( array &$state, $deadline ) {
		switch ( $state['stage'] ) {
			case 'folders':
				if ( ! empty( $state['cursor'] ) ) {
					$this->step_cursor( $state, $deadline );
					return;
				}
				if ( empty( $state['queue'] ) ) {
					$this->prepare_dups( $state );
					$state['stage'] = 'dups';
					return;
				}
				$folder = array_shift( $state['queue'] );
				$this->scan_folder( $state, (string) $folder );
				return;

			case 'dups':
				$this->step_dups( $state, $deadline );
				return;

			case 'refs':
				$this->step_refs( $state );
				return;

			case 'unattached':
				$this->step_unattached( $state );
				return;

			default:
				$this->finalize( $state );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Stage 1: dated folders — orphans, stale sizes, originals            */
	/* ------------------------------------------------------------------ */

	/**
	 * Scan one dated folder: list its files, build the known-file set from the
	 * attachments that live there, and queue unknown files / stale sizes /
	 * originals for reference checks (done in step_cursor()).
	 *
	 * @param array  $state  State (by reference).
	 * @param string $folder Folder such as 2026/10.
	 * @return void
	 */
	private function scan_folder( array &$state, $folder ) {
		$w   =& $state['work'];
		$dir = $this->verified_dir( $folder );
		if ( false === $dir ) {
			++$state['folders_done'];
			return;
		}

		$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $entries ) ) {
			++$state['folders_done'];
			return;
		}

		// Regular files only: no directories, no symlinks.
		$present = array();
		$count   = 0;
		foreach ( $entries as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			if ( $count >= self::MAX_FILES_PER_FOLDER ) {
				$w['truncated'] = true;
				break;
			}
			$path = $dir . '/' . $name;
			if ( is_link( $path ) || ! is_file( $path ) ) {
				continue;
			}
			$present[ (string) $name ] = true;
			++$count;
		}

		$w['files_checked'] += $count;
		if ( 0 === $count ) {
			++$state['folders_done'];
			return;
		}

		$registered = $this->registered_sizes();
		$stale_ok   = isset( $registered['thumbnail'] ); // Registry looks sane.
		$stale_c    = array();
		$orig_c     = array();

		$callback = function ( $id, $rel, $meta, $backup_names ) use ( &$stale_c, &$orig_c, &$w, $present, $registered, $stale_ok, $dir, $folder ) {
			$main = basename( $rel );

			// Largest images and duplicate candidates come from the main file.
			if ( isset( $present[ $main ] ) ) {
				$bytes = (int) @filesize( $dir . '/' . $main ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$ext   = strtolower( pathinfo( $main, PATHINFO_EXTENSION ) );
				if ( in_array( $ext, self::IMAGE_EXTS, true ) ) {
					$this->track_largest( $w, $id, $folder . '/' . $main, $bytes, $meta );
				}
				if ( $bytes > self::DUP_MIN_BYTES && $bytes <= self::DUP_MAX_BYTES ) {
					if ( $w['dup_entries'] < self::MAX_DUP_INDEX ) {
						$w['dup_index'][ $bytes ][] = array( $folder . '/' . $main, $id );
						++$w['dup_entries'];
					} else {
						$w['truncated'] = true;
					}
				}
			}

			if ( ! is_array( $meta ) ) {
				return;
			}

			// File names this attachment legitimately uses: never stale / original-eligible.
			$protect = array( strtolower( $main ) => true );
			foreach ( $backup_names as $backup_name ) {
				$protect[ strtolower( $backup_name ) ] = true;
			}
			$kept          = $meta;
			$kept['sizes'] = array();
			$unregistered  = array();
			if ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size_name => $size ) {
					if ( ! is_array( $size ) ) {
						continue;
					}
					if ( isset( $registered[ (string) $size_name ] ) ) {
						$kept['sizes'][ $size_name ] = $size;
					} else {
						$unregistered[ (string) $size_name ] = $size;
					}
				}
			}
			$kept_names = array();
			$this->collect_names( $kept, $kept_names );
			foreach ( $kept_names as $kept_name ) {
				$protect[ strtolower( $kept_name ) ] = true;
			}

			// Stale sizes.
			if ( $stale_ok ) {
				$seen = array();
				foreach ( $unregistered as $size_name => $size ) {
					$file = isset( $size['file'] ) ? $size['file'] : '';
					if ( ! $this->is_plain_media_name( $file ) ) {
						continue;
					}
					$lower = strtolower( $file );
					if ( isset( $protect[ $lower ] ) || isset( $seen[ $lower ] ) || ! isset( $present[ $file ] ) ) {
						continue;
					}
					$seen[ $lower ] = true;
					$stale_c[]      = array(
						'k' => 'r',
						'i' => (int) $id,
						's' => $size_name,
						'n' => $file,
						'f' => $folder,
					);
				}
			}

			// Pre-scaled original.
			$orig       = isset( $meta['original_image'] ) ? $meta['original_image'] : '';
			$backups_lc = array_flip( array_map( 'strtolower', $backup_names ) );
			if ( $this->is_plain_media_name( $orig )
				&& strtolower( $orig ) !== strtolower( $main )
				&& isset( $present[ $orig ], $present[ $main ] )
				&& ! isset( $backups_lc[ strtolower( $orig ) ] )
			) {
				$orig_c[] = array(
					'k' => 'g',
					'i' => (int) $id,
					'n' => $orig,
					'm' => $main,
					'f' => $folder,
				);
			}
		};

		$index = $this->index_folder( $folder, $callback );
		if ( ! $index['complete'] ) {
			// Could not hold the full known-file set in memory: report nothing for this folder.
			$w['truncated'] = true;
			++$state['folders_done'];
			return;
		}

		$known  = $index['known'];
		$stems  = $index['stems'];
		$owners = $index['owners'];
		$min_age = (int) apply_filters( 'blt_optimized_media_min_file_age', self::MIN_FILE_AGE );
		$now     = time();
		$cands   = array();

		// Orphan candidates: files nothing in this folder's attachments accounts for.
		foreach ( $present as $name => $unused ) {
			$name = (string) $name;
			if ( $this->is_ignored_name( $name ) ) {
				continue;
			}
			if ( $this->is_known( strtolower( $name ), $known, $stems ) ) {
				continue;
			}
			$stat = @stat( $dir . '/' . $name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! is_array( $stat ) ) {
				continue;
			}
			$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, self::ORPHAN_EXTS, true ) ) {
				++$w['other_unknown']['count'];
				$w['other_unknown']['bytes'] += (int) $stat['size'];
				continue;
			}
			if ( $now - (int) $stat['mtime'] < $min_age ) {
				continue; // Possibly an upload still in flight.
			}
			$cands[] = array(
				'k' => 'o',
				'n' => $name,
				'b' => (int) $stat['size'],
				'f' => $folder,
			);
		}

		// Stale sizes: only when this attachment is the sole owner of the file name.
		foreach ( $stale_c as $c ) {
			$lower = strtolower( $c['n'] );
			if ( ! isset( $owners[ $lower ] ) || (int) $owners[ $lower ] !== $c['i'] ) {
				continue;
			}
			$c['b'] = (int) @filesize( $dir . '/' . $c['n'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$cands[] = $c;
		}

		// Originals likewise.
		foreach ( $orig_c as $c ) {
			$lower = strtolower( $c['n'] );
			if ( ! isset( $owners[ $lower ] ) || (int) $owners[ $lower ] !== $c['i'] ) {
				continue;
			}
			$c['b'] = (int) @filesize( $dir . '/' . $c['n'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$cands[] = $c;
		}

		if ( empty( $cands ) ) {
			++$state['folders_done'];
			return;
		}

		$state['cursor'] = array(
			'folder' => $folder,
			'cands'  => $cands,
		);
	}

	/**
	 * Reference-check a chunk of queued candidates; resumable between ticks.
	 *
	 * @param array $state    State (by reference).
	 * @param float $deadline Microtime deadline.
	 * @return void
	 */
	private function step_cursor( array &$state, $deadline ) {
		$w =& $state['work'];

		while ( ! empty( $state['cursor']['cands'] ) ) {
			$cands = $state['cursor']['cands'];
			$kind  = $cands[0]['k'];

			// Longest run (≤ REF_CHUNK) of the same kind at the head of the queue.
			$run = 0;
			foreach ( $cands as $c ) {
				if ( $c['k'] !== $kind || $run >= self::REF_CHUNK ) {
					break;
				}
				++$run;
			}
			$chunk = array_splice( $cands, 0, $run );
			$state['cursor']['cands'] = $cands;

			$over_cap = false;
			if ( 'o' === $kind ) {
				$over_cap = $w['ref_checks'] >= self::MAX_REF_CHECKS;
			} elseif ( 'r' === $kind ) {
				$over_cap = count( $w['stale'] ) >= self::MAX_STORED_STALE;
			} else {
				$over_cap = count( $w['originals'] ) >= self::MAX_STORED_ORIGINALS;
			}
			if ( $over_cap ) {
				// Skip the rest of this kind in this folder, but say so.
				$w['truncated'] = true;
				$state['cursor']['cands'] = array_values(
					array_filter(
						$state['cursor']['cands'],
						static function ( $c ) use ( $kind ) {
							return $c['k'] !== $kind;
						}
					)
				);
				continue;
			}

			$referenced = $this->find_referenced( wp_list_pluck( $chunk, 'n' ), 'o' !== $kind );

			foreach ( $chunk as $c ) {
				if ( 'o' === $kind ) {
					++$w['ref_checks'];
					if ( isset( $referenced[ $c['n'] ] ) ) {
						++$w['orphans_referenced'];
						continue;
					}
					if ( count( $w['orphans'] ) >= self::MAX_STORED_ORPHANS ) {
						$w['truncated'] = true;
						continue;
					}
					$w['orphans'][] = array(
						'path'  => $c['f'] . '/' . $c['n'],
						'bytes' => (int) $c['b'],
					);
				} elseif ( 'r' === $kind ) {
					if ( isset( $referenced[ $c['n'] ] ) ) {
						continue;
					}
					$w['stale'][] = array(
						'attachment_id' => (int) $c['i'],
						'size'          => (string) $c['s'],
						'path'          => $c['f'] . '/' . $c['n'],
						'bytes'         => (int) $c['b'],
					);
				} else {
					if ( isset( $referenced[ $c['n'] ] ) ) {
						continue;
					}
					$w['originals'][] = array(
						'attachment_id' => (int) $c['i'],
						'path'          => $c['f'] . '/' . $c['n'],
						'bytes'         => (int) $c['b'],
						'scaled_path'   => $c['f'] . '/' . $c['m'],
					);
				}
			}

			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		if ( empty( $state['cursor']['cands'] ) ) {
			$state['cursor'] = array();
			++$state['folders_done'];
		}
	}

	/* ------------------------------------------------------------------ */
	/* Stage 2: duplicates                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Turn the size index into the list of same-size groups to hash.
	 *
	 * @param array $state State (by reference).
	 * @return void
	 */
	private function prepare_dups( array &$state ) {
		$w      = &$state['work'];
		$groups = array();
		foreach ( $w['dup_index'] as $size => $entries ) {
			// Several attachments can point at one file; that is not a duplicate.
			$unique = array();
			foreach ( $entries as $entry ) {
				$unique[ $entry[0] ][] = (int) $entry[1];
			}
			if ( count( $unique ) < 2 ) {
				continue;
			}
			$groups[] = array(
				'size'  => (int) $size,
				'files' => $unique,
			);
		}
		// Ascending by size: array_pop() then takes the biggest (most to reclaim) first.
		usort(
			$groups,
			static function ( $a, $b ) {
				return $a['size'] <=> $b['size'];
			}
		);
		$w['dup_groups'] = $groups;
		$w['dup_index']  = array();
	}

	/**
	 * Hash same-size groups and record true duplicates.
	 *
	 * @param array $state    State (by reference).
	 * @param float $deadline Microtime deadline.
	 * @return void
	 */
	private function step_dups( array &$state, $deadline ) {
		$w = &$state['work'];

		while ( ! empty( $w['dup_groups'] ) ) {
			$group = array_pop( $w['dup_groups'] );

			if ( $w['dup_hashed'] + count( $group['files'] ) > self::MAX_DUP_HASHES ) {
				$w['truncated']  = true;
				$w['dup_groups'] = array();
				break;
			}

			$by_hash = array();
			foreach ( $group['files'] as $path => $ids ) {
				$abs = $this->safe_file_path( (string) $path );
				if ( false === $abs || (int) @filesize( $abs ) !== $group['size'] ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					continue;
				}
				$hash = @md5_file( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( false === $hash ) {
					continue;
				}
				$by_hash[ $hash ][ (string) $path ] = $ids;
			}
			$w['dup_hashed'] += count( $group['files'] );

			foreach ( $by_hash as $files ) {
				if ( count( $files ) < 2 ) {
					continue;
				}
				if ( count( $w['duplicates'] ) >= self::MAX_STORED_DUP_GROUPS ) {
					$w['truncated'] = true;
					break;
				}
				$attachment_ids = array();
				foreach ( $files as $ids ) {
					$attachment_ids = array_merge( $attachment_ids, $ids );
				}
				$w['duplicates'][] = array(
					'bytes'          => $group['size'],
					'paths'          => array_keys( $files ),
					'attachment_ids' => array_values( array_unique( array_map( 'intval', $attachment_ids ) ) ),
				);
			}

			if ( microtime( true ) >= $deadline ) {
				return;
			}
		}

		$state['stage'] = 'refs';
	}

	/* ------------------------------------------------------------------ */
	/* Stage 3: which attachment IDs are used in content                   */
	/* ------------------------------------------------------------------ */

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

	/**
	 * Collect attachment IDs referenced from post content (wp-image-ID, block
	 * "id":ID, gallery ids="…", …), theme mods and widgets, one page of posts
	 * per call. The unattached-media check needs the complete set: if it
	 * cannot be built, that check is skipped rather than guessed.
	 *
	 * @param array $state State (by reference).
	 * @return void
	 */
	private function step_refs( array &$state ) {
		global $wpdb;
		$w = &$state['work'];

		if ( ! $w['ref_init'] ) {
			$this->collect_option_ids( $w );
			$w['ref_init'] = true;
			return;
		}

		$needles = array( 'wp-image-', 'id":', 'Id":', 'ID":', 'ids":', 'Ids":', 'IDs":', 'ids=', 'include=', 'attachment_', 'data-id=' );
		$likes   = array();
		foreach ( $needles as $needle ) {
			$likes[] = '%' . $wpdb->esc_like( $needle ) . '%';
		}
		$cond = implode( ' OR ', array_fill( 0, count( $likes ), 'post_content LIKE %s' ) );
		$args = array_merge( array( (int) $w['ref_last_id'] ), $likes, array( 100 ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_content FROM {$wpdb->posts}
				 WHERE ID > %d AND post_type <> 'attachment' AND ( {$cond} )
				 ORDER BY ID ASC LIMIT %d",
				$args
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$this->extract_content_ids( (string) $row['post_content'], $w['ref_ids'] );
			$w['ref_last_id'] = (int) $row['ID'];
			++$w['ref_posts'];
		}

		if ( count( $w['ref_ids'] ) > self::MAX_REFERENCED_IDS || $w['ref_posts'] > self::MAX_CONTENT_POSTS ) {
			$w['ref_incomplete'] = true;
			$state['stage']      = 'unattached';
			return;
		}

		if ( count( (array) $rows ) < 100 ) {
			$state['stage'] = 'unattached';
		}
	}

	/**
	 * Pull attachment IDs out of a content string into a set.
	 *
	 * @param string $content Post content (or any string).
	 * @param array  $set     ID set (id => 1), by reference.
	 * @return void
	 */
	private function extract_content_ids( $content, array &$set ) {
		if ( '' === $content ) {
			return;
		}
		$patterns = array(
			'/wp-image-(\d+)/',
			'/attachment_(?:id=)?(\d+)/i',
			'/data-id=\\\\?["\']?(\d+)/i',
			'/[A-Za-z_]*id\\\\?"\s*:\s*\\\\?"?(\d+)/i',
		);
		foreach ( $patterns as $pattern ) {
			if ( preg_match_all( $pattern, $content, $m ) ) {
				foreach ( $m[1] as $id ) {
					$set[ (int) $id ] = 1;
				}
			}
		}

		// Lists: "ids":[1,2,3], ids="1,2,3", include="1,2,3".
		$lists = array(
			'/[A-Za-z_]*ids\\\\?"\s*:\s*\[([^\]]*)\]/i',
			'/\b(?:ids|include)\s*=\s*\\\\?["\']?([0-9][0-9,\s]*)/i',
			'/\[(?:gallery|playlist|audio|video|caption)\b[^\]]*?\bid\s*=\s*["\']?(\d+)/i',
		);
		foreach ( $lists as $pattern ) {
			if ( preg_match_all( $pattern, $content, $m ) ) {
				foreach ( $m[1] as $chunk ) {
					if ( preg_match_all( '/\d+/', $chunk, $n ) ) {
						foreach ( $n[0] as $id ) {
							$set[ (int) $id ] = 1;
						}
					}
				}
			}
		}
	}

	/**
	 * IDs held by theme mods (custom logo, header image…), widgets and the
	 * Woo gallery meta, plus whatever integrations add via the filter.
	 *
	 * @param array $w Working data (by reference).
	 * @return void
	 */
	private function collect_option_ids( array &$w ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options}
			 WHERE option_name LIKE 'theme\\_mods\\_%' OR option_name LIKE 'widget\\_%'
			 OR option_name IN ( 'site_icon', 'site_logo', 'custom_logo', 'header_image_data', 'woocommerce_placeholder_image' )
			 LIMIT 500",
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$value = $this->safe_unserialize( $row['option_value'] );
			$this->walk_ids( $value, $w['ref_ids'], 0 );
		}

		$gallery = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND meta_value <> '' LIMIT 50000" );
		foreach ( (array) $gallery as $list ) {
			foreach ( explode( ',', (string) $list ) as $id ) {
				if ( (int) $id > 0 ) {
					$w['ref_ids'][ (int) $id ] = 1;
				}
			}
		}

		$extra = apply_filters( 'blt_optimized_media_referenced_ids', array() );
		if ( is_array( $extra ) ) {
			foreach ( $extra as $id ) {
				if ( (int) $id > 0 ) {
					$w['ref_ids'][ (int) $id ] = 1;
				}
			}
		}
	}

	/**
	 * Collect every integer-looking leaf (and any IDs inside HTML strings).
	 * Deliberately over-inclusive: the unattached check is flag-only and
	 * errs towards "in use".
	 *
	 * @param mixed $value Value.
	 * @param array $set   ID set, by reference.
	 * @param int   $depth Recursion depth.
	 * @return void
	 */
	private function walk_ids( $value, array &$set, $depth ) {
		if ( $depth > 8 ) {
			return;
		}
		if ( is_object( $value ) ) {
			$value = (array) $value;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				$this->walk_ids( $item, $set, $depth + 1 );
			}
		} elseif ( is_int( $value ) ) {
			if ( $value > 0 ) {
				$set[ $value ] = 1;
			}
		} elseif ( is_string( $value ) && '' !== $value ) {
			if ( ctype_digit( $value ) && strlen( $value ) < 12 ) {
				$set[ (int) $value ] = 1;
			} elseif ( strlen( $value ) > 8 && strlen( $value ) < 200000 ) {
				$this->extract_content_ids( $value, $set );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Stage 4: unattached + unreferenced attachments                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Check unattached attachments in batches (flag only).
	 *
	 * @param array $state State (by reference).
	 * @return void
	 */
	private function step_unattached( array &$state ) {
		global $wpdb;
		$w = &$state['work'];

		if ( $w['ref_incomplete'] ) {
			// The referenced-ID set is incomplete: flagging anything would be a guess.
			$w['truncated'] = true;
			$w['un_pending'] = array();
			$state['stage']  = 'finalize';
			return;
		}

		// Work through the pending queue a few at a time (each needs file stats and LIKE queries).
		if ( ! empty( $w['un_pending'] ) ) {
			$take = array_splice( $w['un_pending'], 0, self::UNATTACHED_PER_STEP );
			$this->check_unattached( $take, $w );
			return;
		}

		if ( $w['un_checked'] >= self::MAX_UNATTACHED_CHECKED ) {
			$more = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_parent = 0 AND ID > %d LIMIT 1",
					(int) $w['un_last_id']
				)
			);
			if ( $more ) {
				$w['truncated'] = true;
			}
			$state['stage'] = 'finalize';
			return;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title FROM {$wpdb->posts}
				 WHERE post_type = 'attachment' AND post_parent = 0 AND ID > %d
				 ORDER BY ID ASC LIMIT %d",
				(int) $w['un_last_id'],
				self::UNATTACHED_BATCH
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			$state['stage'] = 'finalize';
			return;
		}

		$ids = array();
		foreach ( $rows as $row ) {
			$ids[]            = (int) $row['ID'];
			$w['un_last_id']  = max( (int) $w['un_last_id'], (int) $row['ID'] );
		}
		$w['un_checked'] += count( $rows );

		$used = $this->ids_used_elsewhere( $ids );
		foreach ( $rows as $row ) {
			$id = (int) $row['ID'];
			if ( isset( $w['ref_ids'][ $id ] ) || isset( $used[ $id ] ) ) {
				continue;
			}
			$w['un_pending'][] = array( $id, (string) $row['post_title'] );
		}
	}

	/**
	 * Which of these attachment IDs are used as a featured image, option
	 * value (site icon, logo, placeholder…), term/user meta value or other
	 * post meta value. Numeric equality is a coarse signal on purpose.
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return array Set (id => true).
	 */
	private function ids_used_elsewhere( array $ids ) {
		global $wpdb;

		$used = array();
		if ( empty( $ids ) ) {
			return $used;
		}
		$strings      = array_map( 'strval', $ids );
		$placeholders = implode( ',', array_fill( 0, count( $strings ), '%s' ) );

		// Column LIKE-any helper: returns "( col LIKE %s OR … )" and appends the bound patterns.
		$like_any = static function ( $column, array $needles, array &$args ) use ( $wpdb ) {
			$parts = array();
			foreach ( $needles as $needle ) {
				$parts[] = $column . ' LIKE %s';
				$args[]  = '%' . $wpdb->esc_like( $needle ) . '%';
			}
			return '( ' . implode( ' OR ', $parts ) . ' )';
		};

		// Featured images, ACF image fields, SEO plugin image IDs, product variation images…
		$queries   = array();
		$queries[] = array(
			"SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
			 WHERE meta_value IN ( {$placeholders} )
			 AND meta_key NOT IN ( '_edit_last', '_price', '_regular_price', '_sale_price', '_stock', 'total_sales', '_wc_review_count', '_wp_attached_file' )",
			$strings,
		);

		// Category / product-category images.
		$queries[] = array(
			"SELECT DISTINCT meta_value FROM {$wpdb->termmeta} WHERE meta_value IN ( {$placeholders} )",
			$strings,
		);

		// Avatars and cover photos.
		$args      = $strings;
		$cond      = $like_any( 'meta_key', array( 'avatar', 'image', 'photo', 'picture', 'cover', 'logo' ), $args );
		$queries[] = array(
			"SELECT DISTINCT meta_value FROM {$wpdb->usermeta} WHERE meta_value IN ( {$placeholders} ) AND {$cond}",
			$args,
		);

		// Site icon, logo, header/background images, placeholders, email headers…
		$args      = $strings;
		$cond      = $like_any( 'option_name', array( 'logo', 'icon', 'image', 'media', 'attachment', 'background', 'banner', 'header', 'placeholder', 'avatar' ), $args );
		$args[]    = '%' . $wpdb->esc_like( 'blt_optimized_' ) . '%';
		$queries[] = array(
			"SELECT DISTINCT option_value FROM {$wpdb->options} WHERE option_value IN ( {$placeholders} ) AND {$cond} AND option_name NOT LIKE %s",
			$args,
		);

		foreach ( $queries as $query ) {
			$values = $wpdb->get_col( $wpdb->prepare( $query[0], $query[1] ) );
			foreach ( (array) $values as $value ) {
				$used[ (int) $value ] = true;
			}
		}

		return $used;
	}

	/**
	 * Final checks for a few unattached candidates: does anything mention
	 * their file names, and how much space do they take.
	 *
	 * @param array $take Pending items: array( id, title ).
	 * @param array $w    Working data (by reference).
	 * @return void
	 */
	private function check_unattached( array $take, array &$w ) {
		global $wpdb;

		$ids = array();
		foreach ( $take as $item ) {
			$ids[] = (int) $item[0];
		}
		if ( empty( $ids ) ) {
			return;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
				 WHERE post_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ")
				 AND meta_key IN ( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes' )",
				$ids
			),
			ARRAY_A
		);
		$by = array();
		foreach ( (array) $rows as $row ) {
			$by[ (int) $row['post_id'] ][ $row['meta_key'] ] = $row['meta_value'];
		}

		$uploads = wp_get_upload_dir();
		$base    = ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) ? $uploads['basedir'] : '';

		foreach ( $take as $item ) {
			list( $id, $title ) = $item;
			$rel = isset( $by[ $id ]['_wp_attached_file'] ) ? (string) $by[ $id ]['_wp_attached_file'] : '';
			if ( '' === $rel || '' === $base || ! $this->is_relative_upload_path( $rel ) ) {
				continue;
			}

			$names = array( basename( $rel ) );
			$files = array();
			$meta  = isset( $by[ $id ]['_wp_attachment_metadata'] ) ? $this->safe_unserialize( $by[ $id ]['_wp_attachment_metadata'] ) : null;
			if ( is_array( $meta ) ) {
				$file_names = array();
				$this->collect_names( $meta, $file_names );
				$names = array_merge( $names, $file_names );
			}
			$backup = isset( $by[ $id ]['_wp_attachment_backup_sizes'] ) ? $this->safe_unserialize( $by[ $id ]['_wp_attachment_backup_sizes'] ) : null;
			if ( is_array( $backup ) ) {
				$file_names = array();
				$this->collect_names( $backup, $file_names );
				$names = array_merge( $names, $file_names );
			}

			// File footprint: main + sizes + original + editor backups, in the attachment's folder.
			$dir   = dirname( $rel );
			$bytes = 0;
			foreach ( array_unique( $names ) as $name ) {
				if ( ! $this->is_plain_media_name( $name ) ) {
					continue;
				}
				$abs = $base . '/' . ( '.' === $dir ? '' : $dir . '/' ) . $name;
				if ( is_file( $abs ) && ! is_link( $abs ) ) {
					$bytes += (int) @filesize( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
			if ( $bytes <= 0 ) {
				continue; // Nothing on disk to reclaim.
			}

			// What to look for: the shared stem of the main file and original when it is
			// distinctive (it also matches every thumbnail), otherwise every file name.
			$stems = array();
			foreach ( array( basename( $rel ), is_array( $meta ) && isset( $meta['original_image'] ) ? $meta['original_image'] : '' ) as $candidate ) {
				if ( is_string( $candidate ) && '' !== $candidate ) {
					$stems[] = preg_replace( '/\.[A-Za-z0-9]{2,5}$/', '', $candidate );
				}
			}
			$stems = array_values( array_unique( $stems ) );
			$check = array();
			foreach ( $stems as $stem ) {
				if ( strlen( $stem ) < 8 ) {
					$check = array();
					break;
				}
				$check[] = $stem;
			}
			if ( empty( $check ) ) {
				foreach ( array_unique( $names ) as $name ) {
					if ( $this->is_plain_media_name( $name ) ) {
						$check[] = $name;
					}
				}
				$check = array_slice( array_values( array_unique( $check ) ), 0, 16 );
			}
			if ( ! empty( $check ) && ! empty( $this->find_referenced( $check, true ) ) ) {
				continue;
			}

			$edit = function_exists( 'get_edit_post_link' ) ? get_edit_post_link( $id, 'raw' ) : '';
			if ( ! $edit ) {
				$edit = admin_url( 'post.php?post=' . $id . '&action=edit' );
			}

			$w['unattached'][] = array(
				'attachment_id' => (int) $id,
				'path'          => $rel,
				'bytes'         => $bytes,
				'title'         => $title,
				'edit_url'      => $edit,
			);
		}
	}

	/* ------------------------------------------------------------------ */
	/* Finalize                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Compile and store the results; reset working data.
	 *
	 * @param array $state State (by reference).
	 * @return void
	 */
	private function finalize( array &$state ) {
		$w = $state['work'];

		$by_bytes = static function ( $a, $b ) {
			return $b['bytes'] <=> $a['bytes'];
		};

		$orphans    = $w['orphans'];
		$stale      = $w['stale'];
		$originals  = $w['originals'];
		$unattached = $w['unattached'];
		$duplicates = $w['duplicates'];
		$largest    = $w['largest'];
		usort( $orphans, $by_bytes );
		usort( $stale, $by_bytes );
		usort( $originals, $by_bytes );
		usort( $unattached, $by_bytes );
		usort( $largest, $by_bytes );
		usort(
			$duplicates,
			static function ( $a, $b ) {
				return ( $b['bytes'] * ( count( $b['paths'] ) - 1 ) ) <=> ( $a['bytes'] * ( count( $a['paths'] ) - 1 ) );
			}
		);

		$results = array(
			'completed_at'       => time(),
			'folders'            => (int) $state['folders_total'],
			'files_checked'      => (int) $w['files_checked'],
			'orphans'            => $orphans,
			'orphans_referenced' => (int) $w['orphans_referenced'],
			'other_unknown'      => array(
				'count' => (int) $w['other_unknown']['count'],
				'bytes' => (int) $w['other_unknown']['bytes'],
			),
			'stale_sizes'        => $stale,
			'originals'          => $originals,
			'unattached'         => $unattached,
			'duplicates'         => $duplicates,
			'largest'            => array_slice( $largest, 0, self::LARGEST_LIMIT ),
			'totals'             => array(),
			'truncated'          => (bool) $w['truncated'],
		);
		$results['totals'] = $this->compute_totals( $results );
		update_option( self::RESULTS_OPTION, $results, false );

		$this->audit_log->log(
			'media_audit',
			sprintf(
				/* translators: 1: orphan count, 2: stale thumbnail count, 3: original count, 4: unattached count, 5: duplicate group count. */
				__( 'Media audit completed: %1$d orphan file(s), %2$d stale thumbnail(s), %3$d pre-scaled original(s), %4$d unattached item(s), %5$d duplicate group(s).', 'blt-optimized' ),
				count( $orphans ),
				count( $stale ),
				count( $originals ),
				count( $unattached ),
				count( $duplicates )
			),
			0,
			0
		);

		$state['status']        = 'completed';
		$state['stage']         = '';
		$state['finished_at']   = time();
		$state['files_checked'] = (int) $w['files_checked'];
		$state['queue']       = array();
		$state['cursor']      = array();
		$state['work']        = self::default_work();
	}

	/**
	 * Per-kind byte totals for a results array.
	 *
	 * @param array $results Results.
	 * @return array
	 */
	private function compute_totals( array $results ) {
		$sum = static function ( $items ) {
			$total = 0;
			foreach ( (array) $items as $item ) {
				$total += isset( $item['bytes'] ) ? (int) $item['bytes'] : 0;
			}
			return $total;
		};

		// Duplicates: what deleting every extra copy would free.
		$duplicates = 0;
		foreach ( (array) ( isset( $results['duplicates'] ) ? $results['duplicates'] : array() ) as $group ) {
			$duplicates += (int) $group['bytes'] * max( 0, count( $group['paths'] ) - 1 );
		}

		return array(
			'orphans_bytes'    => $sum( isset( $results['orphans'] ) ? $results['orphans'] : array() ),
			'stale_bytes'      => $sum( isset( $results['stale_sizes'] ) ? $results['stale_sizes'] : array() ),
			'originals_bytes'  => $sum( isset( $results['originals'] ) ? $results['originals'] : array() ),
			'unattached_bytes' => $sum( isset( $results['unattached'] ) ? $results['unattached'] : array() ),
			'duplicates_bytes' => $duplicates,
		);
	}

	/**
	 * Keep the top N images by size.
	 *
	 * @param array $w     Working data (by reference).
	 * @param int   $id    Attachment ID.
	 * @param string $path Relative path.
	 * @param int   $bytes File size.
	 * @param mixed $meta  Attachment metadata.
	 * @return void
	 */
	private function track_largest( array &$w, $id, $path, $bytes, $meta ) {
		if ( $bytes <= 0 ) {
			return;
		}
		$list = $w['largest'];
		if ( count( $list ) >= self::LARGEST_LIMIT ) {
			$last = end( $list );
			if ( $bytes <= (int) $last['bytes'] ) {
				return;
			}
		}
		$list[] = array(
			'attachment_id' => (int) $id,
			'path'          => $path,
			'bytes'         => (int) $bytes,
			'width'         => ( is_array( $meta ) && isset( $meta['width'] ) ) ? (int) $meta['width'] : 0,
			'height'        => ( is_array( $meta ) && isset( $meta['height'] ) ) ? (int) $meta['height'] : 0,
		);
		usort(
			$list,
			static function ( $a, $b ) {
				return $b['bytes'] <=> $a['bytes'];
			}
		);
		$w['largest'] = array_slice( $list, 0, self::LARGEST_LIMIT );
	}

	/* ------------------------------------------------------------------ */
	/* Known files: what each attachment owns                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Attachments whose main file lives directly in a dated folder:
	 * attachment ID => `_wp_attached_file` value.
	 *
	 * @param string $folder Folder such as 2026/10.
	 * @return array
	 */
	private function folder_attachment_ids( $folder ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta}
				 WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
				$wpdb->esc_like( $folder . '/' ) . '%'
			),
			ARRAY_A
		);

		$files = array();
		foreach ( (array) $rows as $row ) {
			$value = (string) $row['meta_value'];
			if ( dirname( $value ) === $folder ) {
				$files[ (int) $row['post_id'] ] = $value;
			}
		}
		return $files;
	}

	/**
	 * Build the known-file set for a dated folder.
	 *
	 * For every attachment whose file is in the folder: the main file, every
	 * `sizes[*]` file, `original_image`, every `_wp_attachment_backup_sizes`
	 * file, BLT's `_blt_webp_sizes`, and — by walking each metadata array and
	 * harvesting file-name-looking strings from the raw serialized value —
	 * anything else those records mention (WP 6.5 `sources`, PDF previews,
	 * third-party plugin keys). Sidecar copies (name.webp, name.jpg.webp, avif,
	 * name.bak.jpg …) are matched through `stems`, see is_known().
	 *
	 * @param string        $folder   Folder such as 2026/10.
	 * @param callable|null $callback Called per attachment: ( id, rel, meta|null, backup_names[] ).
	 * @return array { known: set, stems: set, owners: lowercase name => id (0 = several), complete: bool }
	 */
	private function index_folder( $folder, $callback = null ) {
		global $wpdb;

		$known    = array();
		$stems    = array();
		$owners   = array();
		$complete = true;
		$limit    = $this->memory_limit_bytes();

		foreach ( array_chunk( $this->folder_attachment_ids( $folder ), self::ATTACHMENT_CHUNK, true ) as $chunk ) {
			if ( $limit > 0 && memory_get_usage( true ) > $limit * 0.8 ) {
				$complete = false;
				break;
			}

			$ids  = array_keys( $chunk );
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
					 WHERE post_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ")
					 AND meta_key IN ( '_wp_attachment_metadata', '_wp_attachment_backup_sizes', '_blt_webp_sizes' )",
					$ids
				),
				ARRAY_A
			);
			$by = array();
			foreach ( (array) $rows as $row ) {
				$by[ (int) $row['post_id'] ][ $row['meta_key'] ] = $row['meta_value'];
			}

			foreach ( $chunk as $id => $rel ) {
				$names = array( basename( $rel ) );
				$meta  = null;
				$backs = array();

				if ( isset( $by[ $id ]['_wp_attachment_metadata'] ) ) {
					$meta = $this->decode_meta( $by[ $id ]['_wp_attachment_metadata'], $names );
				}
				if ( isset( $by[ $id ]['_wp_attachment_backup_sizes'] ) ) {
					$this->decode_meta( $by[ $id ]['_wp_attachment_backup_sizes'], $backs );
					$names = array_merge( $names, $backs );
				}
				if ( isset( $by[ $id ]['_blt_webp_sizes'] ) ) {
					$this->decode_meta( $by[ $id ]['_blt_webp_sizes'], $names );
				}

				foreach ( $names as $name ) {
					$lower = strtolower( $name );
					if ( '' === $lower ) {
						continue;
					}
					$known[ $lower ] = true;
					$stems[ $lower ] = true; // name.jpg.webp style: prefix up to a dot.
					$dot             = strrpos( $lower, '.' );
					if ( false !== $dot && $dot > 0 ) {
						$stems[ substr( $lower, 0, $dot ) ] = true; // name.webp style.
					}
					$owners[ $lower ] = ( isset( $owners[ $lower ] ) && (int) $owners[ $lower ] !== (int) $id ) ? 0 : (int) $id;
				}

				if ( null !== $callback ) {
					call_user_func( $callback, (int) $id, $rel, $meta, array_values( array_unique( $backs ) ) );
				}
			}
		}

		return array(
			'known'    => $known,
			'stems'    => $stems,
			'owners'   => $owners,
			'complete' => $complete,
		);
	}

	/**
	 * Is a (lowercased) file name known to some attachment?
	 *
	 * Beyond an exact match, a file counts as known when any dot-bounded prefix
	 * of its name equals a known file's full name or stem — which covers
	 * name.webp, name.avif, name.jpg.webp, name.jpg.avif, name.bak.jpg and the
	 * like. Over-inclusive on purpose.
	 *
	 * @param string $lower Lowercased file name.
	 * @param array  $known Known names set.
	 * @param array  $stems Known stems set.
	 * @return bool
	 */
	private function is_known( $lower, array $known, array $stems ) {
		if ( isset( $known[ $lower ] ) ) {
			return true;
		}
		$pos = 0;
		while ( false !== ( $pos = strpos( $lower, '.', $pos + 1 ) ) ) {
			if ( isset( $stems[ substr( $lower, 0, $pos ) ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Decode a serialized meta value and harvest every file name in it.
	 * Falls back to a raw-string harvest so nothing is lost on odd data.
	 *
	 * @param mixed $raw   Raw meta value.
	 * @param array $names File names, by reference.
	 * @return array|null Decoded array, or null.
	 */
	private function decode_meta( $raw, array &$names ) {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$data = $this->safe_unserialize( $raw );
		if ( is_array( $data ) ) {
			$this->collect_names( $data, $names );
		}
		$this->harvest_names( $raw, $names );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Unserialize without instantiating classes.
	 *
	 * @param mixed $raw Raw value.
	 * @return mixed Decoded value, or the raw value when not serialized/corrupt.
	 */
	private function safe_unserialize( $raw ) {
		if ( ! is_string( $raw ) || ! is_serialized( $raw ) ) {
			return $raw;
		}
		$trimmed = trim( $raw );
		$data    = @unserialize( $trimmed, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		if ( false === $data && 'b:0;' !== $trimmed ) {
			return $raw;
		}
		return $data;
	}

	/**
	 * Recursively collect basenames of every string leaf that looks like a
	 * file name (ends in .ext).
	 *
	 * @param mixed $value Value.
	 * @param array $names Names, by reference.
	 * @param int   $depth Recursion depth.
	 * @return void
	 */
	private function collect_names( $value, array &$names, $depth = 0 ) {
		if ( $depth > 10 ) {
			return;
		}
		if ( is_object( $value ) ) {
			$value = (array) $value;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				$this->collect_names( $item, $names, $depth + 1 );
			}
			return;
		}
		if ( is_string( $value ) && strlen( $value ) < 600 && preg_match( '/\.[A-Za-z0-9]{2,5}$/', $value ) ) {
			$base = basename( str_replace( '\\', '/', $value ) );
			if ( '' !== $base ) {
				$names[] = $base;
			}
		}
	}

	/**
	 * Harvest file-name-looking tokens from a raw string (serialized meta).
	 *
	 * @param string $raw   Raw string.
	 * @param array  $names Names, by reference.
	 * @return void
	 */
	private function harvest_names( $raw, array &$names ) {
		if ( strlen( $raw ) > self::MAX_METADATA_BYTES ) {
			$raw = substr( $raw, 0, self::MAX_METADATA_BYTES );
		}
		if ( preg_match_all( '/[^"\';{}\/\\\\\x00-\x1f]{1,300}\.[A-Za-z0-9]{2,5}(?![A-Za-z0-9])/', $raw, $m ) ) {
			foreach ( $m[0] as $token ) {
				$names[] = $token;
			}
		}
	}

	/**
	 * Registered image sub-size names (plus `full`, which PDF previews use).
	 *
	 * @return array name => true
	 */
	private function registered_sizes() {
		if ( null === $this->registered ) {
			$names = array( 'full' );
			if ( function_exists( 'wp_get_registered_image_subsizes' ) ) {
				$names = array_merge( $names, array_keys( wp_get_registered_image_subsizes() ) );
			}
			$names = array_merge( $names, get_intermediate_image_sizes() );

			/**
			 * Filter the image size names the media audit treats as registered
			 * (their thumbnails are never reported as stale).
			 *
			 * @param string[] $names Size names.
			 */
			$names            = (array) apply_filters( 'blt_optimized_media_registered_sizes', $names );
			$this->registered = array_flip( array_map( 'strval', $names ) );
		}
		return $this->registered;
	}

	/**
	 * Memory limit in bytes (0 = unlimited).
	 *
	 * @return int
	 */
	private function memory_limit_bytes() {
		$limit = ini_get( 'memory_limit' );
		if ( false === $limit || '' === $limit || '-1' === (string) $limit ) {
			return 0;
		}
		return max( 0, (int) wp_convert_hr_to_bytes( $limit ) );
	}

	/* ------------------------------------------------------------------ */
	/* References: does anything mention this file name?                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Which of these file names are mentioned anywhere: post content/excerpt
	 * (any post type and status, revisions included), post meta, options
	 * (other than this plugin's own), term meta or user meta. Combined
	 * existence queries first; only when a chunk hits does it test names one
	 * by one to find out which.
	 *
	 * @param string[] $names                  File names (basenames).
	 * @param bool     $ignore_attachment_meta True to disregard attachments' own file metadata
	 *                                         (an attachment's metadata always mentions its own sizes).
	 * @return array name => true for referenced names.
	 */
	private function find_referenced( array $names, $ignore_attachment_meta = false ) {
		$found = array();
		$names = array_values( array_unique( array_map( 'strval', $names ) ) );

		foreach ( array_chunk( $names, self::REF_CHUNK ) as $chunk ) {
			$this->locate_references( $chunk, $ignore_attachment_meta, $found );
		}

		return $found;
	}

	/**
	 * Bisect a chunk that has at least one hit down to the referenced names.
	 *
	 * @param string[] $names                  File names.
	 * @param bool     $ignore_attachment_meta See find_referenced().
	 * @param array    $found                  Referenced names (name => true), by reference.
	 * @return void
	 */
	private function locate_references( array $names, $ignore_attachment_meta, array &$found ) {
		if ( empty( $names ) || ! $this->any_reference( $names, $ignore_attachment_meta ) ) {
			return;
		}
		if ( 1 === count( $names ) ) {
			$found[ $names[0] ] = true;
			return;
		}
		$half = (int) ceil( count( $names ) / 2 );
		$this->locate_references( array_slice( $names, 0, $half ), $ignore_attachment_meta, $found );
		$this->locate_references( array_slice( $names, $half ), $ignore_attachment_meta, $found );
	}

	/**
	 * Is any of these file names mentioned? All LIKE patterns are escaped
	 * with esc_like() and passed through prepare(). Also checks the
	 * url-encoded form of each name.
	 *
	 * @param string[] $names                  File names.
	 * @param bool     $ignore_attachment_meta See find_referenced().
	 * @return bool
	 */
	private function any_reference( array $names, $ignore_attachment_meta ) {
		global $wpdb;

		if ( empty( $names ) ) {
			return false;
		}

		$likes = array();
		foreach ( $names as $name ) {
			$variants = array( $name );
			$encoded  = rawurlencode( $name );
			if ( $encoded !== $name ) {
				$variants[] = $encoded;
			}
			foreach ( $variants as $variant ) {
				$likes[] = '%' . $wpdb->esc_like( $variant ) . '%';
			}
		}
		$n = count( $likes );

		// Posts: content and excerpt.
		$cond = implode( ' OR ', array_merge( array_fill( 0, $n, 'post_content LIKE %s' ), array_fill( 0, $n, 'post_excerpt LIKE %s' ) ) );
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->posts} WHERE {$cond} LIMIT 1", array_merge( $likes, $likes ) ) ) ) {
			return true;
		}

		// Post meta (page builders, ACF, product galleries, SEO plugins…).
		$cond = implode( ' OR ', array_fill( 0, $n, 'meta_value LIKE %s' ) );
		$sql  = "SELECT 1 FROM {$wpdb->postmeta} WHERE ( {$cond} )";
		if ( $ignore_attachment_meta ) {
			$sql .= " AND meta_key NOT IN ( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes', '_blt_webp_sizes' )";
		}
		if ( $wpdb->get_var( $wpdb->prepare( $sql . ' LIMIT 1', $likes ) ) ) {
			return true;
		}

		// Options (widgets, theme mods, customizer CSS, plugin settings). Our own results are excluded.
		$cond = implode( ' OR ', array_fill( 0, $n, 'option_value LIKE %s' ) );
		$args = array_merge( $likes, array( '%' . $wpdb->esc_like( 'blt_optimized_' ) . '%' ) );
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->options} WHERE ( {$cond} ) AND option_name NOT LIKE %s LIMIT 1", $args ) ) ) {
			return true;
		}

		// Term meta and user meta (category images, avatars).
		$cond = implode( ' OR ', array_fill( 0, $n, 'meta_value LIKE %s' ) );
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->termmeta} WHERE {$cond} LIMIT 1", $likes ) ) ) {
			return true;
		}
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->usermeta} WHERE {$cond} LIMIT 1", $likes ) ) ) {
			return true;
		}

		/**
		 * Let integrations veto deletion for names mentioned somewhere the
		 * audit cannot see (custom tables, external stores).
		 *
		 * @param bool     $referenced Whether the name(s) are referenced.
		 * @param string[] $names      File names being checked.
		 */
		return (bool) apply_filters( 'blt_optimized_media_is_referenced', false, $names );
	}

	/**
	 * Does another attachment's file metadata mention this file name?
	 *
	 * @param string $name          File name.
	 * @param int    $attachment_id The attachment that is allowed to own it.
	 * @return bool
	 */
	private function used_by_other_attachment( $name, $attachment_id ) {
		global $wpdb;

		$keys = "'" . implode( "','", self::ATTACHMENT_FILE_KEYS ) . "'";
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->postmeta}
				 WHERE post_id <> %d AND meta_key IN ( {$keys} ) AND meta_value LIKE %s LIMIT 1",
				(int) $attachment_id,
				'%' . $wpdb->esc_like( $name ) . '%'
			)
		);
	}

	// phpcs:enable

	/* ------------------------------------------------------------------ */
	/* Path safety                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Case-aware path comparison (Windows paths are case-insensitive).
	 *
	 * @param string $a Path.
	 * @param string $b Path.
	 * @return bool
	 */
	private function same_path( $a, $b ) {
		return '\\' === DIRECTORY_SEPARATOR ? 0 === strcasecmp( $a, $b ) : $a === $b;
	}

	/**
	 * Resolve a dated folder to a verified real path: must be uploads/YYYY/MM
	 * under the real uploads basedir, with no symlink anywhere on the way.
	 *
	 * @param string $folder Folder such as 2026/10.
	 * @return string|false Normalised real path, or false.
	 */
	private function verified_dir( $folder ) {
		if ( ! is_string( $folder ) || ! preg_match( '#^\d{4}/(0[1-9]|1[0-2])$#', $folder ) ) {
			return false;
		}
		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return false;
		}
		$base = realpath( $uploads['basedir'] );
		if ( false === $base ) {
			return false;
		}

		$abs = $uploads['basedir'] . '/' . $folder;
		if ( is_link( $abs ) || is_link( dirname( $abs ) ) ) {
			return false;
		}
		$real = realpath( $abs );
		if ( false === $real || ! is_dir( $real ) ) {
			return false;
		}

		$expected = untrailingslashit( wp_normalize_path( $base ) ) . '/' . $folder;
		$real     = wp_normalize_path( $real );
		if ( ! $this->same_path( $real, $expected ) ) {
			return false;
		}
		return $real;
	}

	/**
	 * Resolve `YYYY/MM/name.ext` (relative to the uploads basedir) to a
	 * verified real path of an existing regular file that sits directly in
	 * that dated folder. Anything else — `..`, absolute paths, symlinks,
	 * sub-folders, dotfiles, missing files — returns false.
	 *
	 * @param mixed $relative Relative path.
	 * @return string|false Normalised real path, or false.
	 */
	private function safe_file_path( $relative ) {
		if ( ! is_string( $relative ) || strlen( $relative ) > 600 || false !== strpbrk( $relative, "\0\\" ) ) {
			return false;
		}
		if ( ! preg_match( '#^(\d{4}/(?:0[1-9]|1[0-2]))/([^/]+)$#', $relative, $m ) ) {
			return false;
		}
		if ( '.' === $m[2][0] ) {
			return false;
		}
		$dir = $this->verified_dir( $m[1] );
		if ( false === $dir ) {
			return false;
		}

		$abs = $dir . '/' . $m[2];
		if ( is_link( $abs ) ) {
			return false;
		}
		$real = realpath( $abs );
		if ( false === $real || ! is_file( $real ) ) {
			return false;
		}
		$real = wp_normalize_path( $real );
		if ( ! $this->same_path( dirname( $real ), $dir ) || ! $this->same_path( basename( $real ), $m[2] ) ) {
			return false;
		}
		return $real;
	}

	/**
	 * Is this a bare file name safe to treat as a media file in a folder?
	 *
	 * @param mixed $name Candidate.
	 * @return bool
	 */
	private function is_plain_media_name( $name ) {
		if ( ! is_string( $name ) || '' === $name || strlen( $name ) > 255 ) {
			return false;
		}
		if ( basename( $name ) !== $name || false !== strpbrk( $name, "/\\\0" ) || '.' === $name[0] ) {
			return false;
		}
		return in_array( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ), self::ORPHAN_EXTS, true );
	}

	/**
	 * Files that are never reported (WordPress / server housekeeping).
	 *
	 * @param string $name File name.
	 * @return bool
	 */
	private function is_ignored_name( $name ) {
		if ( '' === $name || '.' === $name[0] ) {
			return true;
		}
		return in_array( strtolower( $name ), array( 'index.php', 'index.html', 'index.htm', 'web.config' ), true );
	}

	/**
	 * Is this a plausible `_wp_attached_file` value to stat (relative, no
	 * traversal)? Only used for read-only size lookups.
	 *
	 * @param string $rel Relative path.
	 * @return bool
	 */
	private function is_relative_upload_path( $rel ) {
		if ( '' === $rel || strlen( $rel ) > 600 || false !== strpbrk( $rel, "\0\\" ) || '/' === $rel[0] || false !== strpos( $rel, '://' ) ) {
			return false;
		}
		foreach ( explode( '/', $rel ) as $part ) {
			if ( '..' === $part ) {
				return false;
			}
		}
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Fixes                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Delete orphan files that are listed in the stored audit results.
	 *
	 * Every requested path must be in the stored results (otherwise nothing
	 * is deleted and a WP_Error is returned). Each file is then re-verified
	 * right before deletion: still there, still unknown to every attachment
	 * in its folder, still unreferenced, an eligible media extension, not
	 * freshly modified, and a real path inside its uploads/YYYY/MM folder.
	 * Files that fail re-verification are skipped (and dropped from the
	 * results when they are no longer orphans).
	 *
	 * @param string[] $paths Paths relative to the uploads basedir (as in the results).
	 * @return array|WP_Error { files: int, bytes: int, skipped: int }
	 */
	public function delete_orphans( array $paths ) {
		$results = $this->get_results();
		if ( null === $results ) {
			return new WP_Error( 'blt_media_no_results', __( 'Run the media audit first — there are no stored results to act on.', 'blt-optimized' ) );
		}

		$requested = array();
		foreach ( $paths as $path ) {
			if ( ! is_string( $path ) || '' === $path ) {
				return new WP_Error( 'blt_media_invalid_path', __( 'One of the requested paths is not valid.', 'blt-optimized' ) );
			}
			$requested[ $path ] = true;
		}
		$requested = array_keys( $requested );
		if ( empty( $requested ) ) {
			return new WP_Error( 'blt_media_nothing_selected', __( 'No files were selected.', 'blt-optimized' ) );
		}
		if ( count( $requested ) > self::FIX_MAX_ITEMS ) {
			return new WP_Error( 'blt_media_too_many', __( 'Too many files in one request. Delete them in smaller batches.', 'blt-optimized' ) );
		}

		$stored = array();
		foreach ( (array) $results['orphans'] as $orphan ) {
			$stored[ (string) $orphan['path'] ] = (int) $orphan['bytes'];
		}
		foreach ( $requested as $path ) {
			if ( ! isset( $stored[ $path ] ) || false === $this->safe_relative_form( $path ) ) {
				return new WP_Error( 'blt_media_unknown_item', __( 'One or more files are not in the latest media audit results, so nothing was deleted. Run the audit again.', 'blt-optimized' ) );
			}
		}

		$uploads = wp_get_upload_dir();
		$min_age = (int) apply_filters( 'blt_optimized_media_min_file_age', self::MIN_FILE_AGE );
		$now     = time();
		$indexes = array();
		$handled = array(); // Paths to drop from the stored results.
		$skipped = 0;
		$verify  = array(); // path => array( abs, name, bytes ).

		foreach ( $requested as $path ) {
			$abs = $this->safe_file_path( $path );
			if ( false === $abs ) {
				// Gone for good → no longer an orphan. Otherwise leave it alone.
				if ( ! empty( $uploads['basedir'] ) && ! file_exists( $uploads['basedir'] . '/' . $path ) && ! is_link( $uploads['basedir'] . '/' . $path ) ) {
					$handled[ $path ] = true;
				} else {
					++$skipped;
				}
				continue;
			}

			$name = basename( $path );
			if ( $this->is_ignored_name( $name ) || ! $this->is_plain_media_name( $name ) ) {
				++$skipped;
				continue;
			}

			$stat = @stat( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! is_array( $stat ) || $now - (int) $stat['mtime'] < $min_age ) {
				++$skipped;
				continue;
			}

			$folder = dirname( $path );
			if ( ! isset( $indexes[ $folder ] ) ) {
				$indexes[ $folder ] = $this->index_folder( $folder );
			}
			if ( ! $indexes[ $folder ]['complete'] ) {
				++$skipped;
				continue;
			}
			if ( $this->is_known( strtolower( $name ), $indexes[ $folder ]['known'], $indexes[ $folder ]['stems'] ) ) {
				$handled[ $path ] = true; // An attachment claims it now: not an orphan any more.
				continue;
			}

			$verify[ $path ] = array( $abs, $name, (int) $stat['size'] );
		}

		// One batched reference check for everything that survived.
		$names = array();
		foreach ( $verify as $info ) {
			$names[] = $info[1];
		}
		$referenced = ! empty( $names ) ? $this->find_referenced( $names, false ) : array();

		$files   = 0;
		$bytes   = 0;
		$deleted = array();
		foreach ( $verify as $path => $info ) {
			if ( isset( $referenced[ $info[1] ] ) ) {
				$handled[ $path ] = true; // Referenced → keep the file, drop from the list.
				continue;
			}
			wp_delete_file( $info[0] );
			clearstatcache( true, $info[0] );
			if ( file_exists( $info[0] ) ) {
				++$skipped;
				continue;
			}
			++$files;
			$bytes           += $info[2];
			$handled[ $path ] = true;
			$deleted[]        = $path;
		}

		$this->drop_results_items(
			'orphans',
			static function ( $item ) use ( $handled ) {
				return isset( $handled[ $item['path'] ] );
			}
		);

		if ( $files > 0 ) {
			$this->audit_log->log(
				'media_delete_orphans',
				sprintf(
					/* translators: 1: file count, 2: list of files. */
					_n( 'Deleted %1$d orphaned upload file: %2$s', 'Deleted %1$d orphaned upload files: %2$s', $files, 'blt-optimized' ),
					$files,
					$this->summarize_list( $deleted )
				),
				$bytes,
				$files
			);
		}

		return array(
			'files'   => $files,
			'bytes'   => $bytes,
			'skipped' => $skipped,
		);
	}

	/**
	 * Delete stale thumbnails (image sizes no longer registered) and remove
	 * their entries from the attachment metadata.
	 *
	 * @param array $items Each array( 'attachment_id' => int, 'size' => string ); must all be in the stored results.
	 * @return array|WP_Error { files: int, bytes: int, skipped: int }
	 */
	public function delete_stale_sizes( array $items ) {
		$results = $this->get_results();
		if ( null === $results ) {
			return new WP_Error( 'blt_media_no_results', __( 'Run the media audit first — there are no stored results to act on.', 'blt-optimized' ) );
		}

		$stored = array();
		foreach ( (array) $results['stale_sizes'] as $stale ) {
			$stored[ (int) $stale['attachment_id'] . '|' . $stale['size'] ] = $stale;
		}

		$by_attachment = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['attachment_id'], $item['size'] ) || ! is_scalar( $item['attachment_id'] ) || ! is_string( $item['size'] ) || '' === $item['size'] ) {
				return new WP_Error( 'blt_media_invalid_item', __( 'One of the requested items is not valid.', 'blt-optimized' ) );
			}
			$id = (int) $item['attachment_id'];
			$key = $id . '|' . $item['size'];
			if ( $id <= 0 || ! isset( $stored[ $key ] ) ) {
				return new WP_Error( 'blt_media_unknown_item', __( 'One or more items are not in the latest media audit results, so nothing was deleted. Run the audit again.', 'blt-optimized' ) );
			}
			$by_attachment[ $id ][ $item['size'] ] = $stored[ $key ];
		}
		if ( empty( $by_attachment ) ) {
			return new WP_Error( 'blt_media_nothing_selected', __( 'No thumbnails were selected.', 'blt-optimized' ) );
		}
		$total = 0;
		foreach ( $by_attachment as $sizes ) {
			$total += count( $sizes );
		}
		if ( $total > self::FIX_MAX_ITEMS ) {
			return new WP_Error( 'blt_media_too_many', __( 'Too many items in one request. Delete them in smaller batches.', 'blt-optimized' ) );
		}

		$registered = $this->registered_sizes();
		if ( ! isset( $registered['thumbnail'] ) ) {
			return new WP_Error( 'blt_media_sizes_unavailable', __( 'The registered image sizes could not be determined, so no thumbnails were deleted.', 'blt-optimized' ) );
		}

		$handled = array(); // "id|size" keys to drop from the results.
		$skipped = 0;
		$files   = 0;
		$bytes   = 0;
		$deleted = array();
		$sizes_l = array();

		foreach ( $by_attachment as $id => $requested ) {
			$rel = get_post_meta( $id, '_wp_attached_file', true );
			if ( ! is_string( $rel ) || ! preg_match( '#^(\d{4}/(?:0[1-9]|1[0-2]))/([^/]+)$#', $rel, $m ) ) {
				$skipped += count( $requested );
				continue;
			}
			$folder = $m[1];
			$main   = $m[2];
			$meta   = get_post_meta( $id, '_wp_attachment_metadata', true );
			if ( ! is_array( $meta ) || empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
				// Metadata no longer lists any sizes: nothing stale is left to handle.
				foreach ( $requested as $size => $unused ) {
					$handled[ $id . '|' . $size ] = true;
				}
				continue;
			}

			$backup_names = array();
			$this->collect_names( get_post_meta( $id, '_wp_attachment_backup_sizes', true ), $backup_names );

			// Names that must survive: main, backups, and every registered size's files.
			$protect = array( strtolower( $main ) => true );
			foreach ( $backup_names as $backup_name ) {
				$protect[ strtolower( $backup_name ) ] = true;
			}
			$kept          = $meta;
			$kept['sizes'] = array();
			foreach ( $meta['sizes'] as $size_name => $size ) {
				if ( isset( $registered[ (string) $size_name ] ) ) {
					$kept['sizes'][ $size_name ] = $size;
				}
			}
			$kept_names = array();
			$this->collect_names( $kept, $kept_names );
			foreach ( $kept_names as $kept_name ) {
				$protect[ strtolower( $kept_name ) ] = true;
			}

			$approved = array(); // size => array( file, abs, bytes ).
			foreach ( $requested as $size => $stale ) {
				if ( isset( $registered[ $size ] ) || ! isset( $meta['sizes'][ $size ]['file'] ) ) {
					$handled[ $id . '|' . $size ] = true; // Registered now, or gone from metadata.
					continue;
				}
				$file = $meta['sizes'][ $size ]['file'];
				if ( ! $this->is_plain_media_name( $file ) || $folder . '/' . $file !== $stale['path'] ) {
					++$skipped;
					continue;
				}
				if ( isset( $protect[ strtolower( $file ) ] ) ) {
					$handled[ $id . '|' . $size ] = true; // Used by a registered size / backup / main: not stale.
					continue;
				}
				$abs = $this->safe_file_path( $folder . '/' . $file );
				if ( false === $abs ) {
					if ( ! file_exists( $this->upload_base() . '/' . $folder . '/' . $file ) ) {
						$handled[ $id . '|' . $size ] = true; // Already gone.
					} else {
						++$skipped;
					}
					continue;
				}
				if ( $this->used_by_other_attachment( $file, $id ) ) {
					++$skipped;
					continue;
				}
				$approved[ $size ] = array( $file, $abs, (int) @filesize( $abs ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			if ( empty( $approved ) ) {
				continue;
			}

			// Anything still mentioned in content/options is left alone.
			$names      = array_values( array_unique( wp_list_pluck( $approved, 0 ) ) );
			$referenced = $this->find_referenced( $names, true );
			foreach ( $approved as $size => $info ) {
				if ( isset( $referenced[ $info[0] ] ) ) {
					unset( $approved[ $size ] );
					$handled[ $id . '|' . $size ] = true;
				}
			}
			if ( empty( $approved ) ) {
				continue;
			}

			// Remove the metadata entries first: a failure after this leaves an orphan file
			// (reported next audit), never metadata pointing at a missing file.
			$new_meta = $meta;
			foreach ( $approved as $size => $info ) {
				unset( $new_meta['sizes'][ $size ] );
			}
			if ( empty( $new_meta['sizes'] ) ) {
				$new_meta['sizes'] = array();
			}
			if ( ! $this->save_attachment_meta( $id, $new_meta ) ) {
				$skipped += count( $approved );
				continue;
			}

			// Delete each file once, and only if nothing left in the metadata still uses it.
			$remaining = array();
			$this->collect_names( $new_meta, $remaining );
			$remaining_l = array_flip( array_map( 'strtolower', $remaining ) );
			$done_files  = array();
			foreach ( $approved as $size => $info ) {
				$sizes_l[ $size ]             = true;
				$handled[ $id . '|' . $size ] = true;
				$lower                        = strtolower( $info[0] );
				if ( isset( $done_files[ $lower ] ) || isset( $remaining_l[ $lower ] ) ) {
					continue;
				}
				$done_files[ $lower ] = true;
				wp_delete_file( $info[1] );
				clearstatcache( true, $info[1] );
				if ( file_exists( $info[1] ) ) {
					++$skipped;
					continue;
				}
				++$files;
				$bytes    += $info[2];
				$deleted[] = $folder . '/' . $info[0];
			}
		}

		$this->drop_results_items(
			'stale_sizes',
			static function ( $item ) use ( $handled ) {
				return isset( $handled[ (int) $item['attachment_id'] . '|' . $item['size'] ] );
			}
		);

		if ( $files > 0 ) {
			$this->audit_log->log(
				'media_delete_stale_sizes',
				sprintf(
					/* translators: 1: file count, 2: image size names, 3: list of files. */
					_n( 'Deleted %1$d stale thumbnail (sizes: %2$s): %3$s', 'Deleted %1$d stale thumbnails (sizes: %2$s): %3$s', $files, 'blt-optimized' ),
					$files,
					$this->summarize_list( array_keys( $sizes_l ) ),
					$this->summarize_list( $deleted )
				),
				$bytes,
				$files
			);
		}

		return array(
			'files'   => $files,
			'bytes'   => $bytes,
			'skipped' => $skipped,
		);
	}

	/**
	 * Delete the pre-scaled `original_image` files of the given attachments
	 * and unset `original_image` in their metadata. Only when the scaled
	 * (main) file still exists.
	 *
	 * @param int[] $attachment_ids Attachment IDs present in the stored results.
	 * @return array|WP_Error { files: int, bytes: int, skipped: int }
	 */
	public function delete_originals( array $attachment_ids ) {
		$results = $this->get_results();
		if ( null === $results ) {
			return new WP_Error( 'blt_media_no_results', __( 'Run the media audit first — there are no stored results to act on.', 'blt-optimized' ) );
		}

		$stored = array();
		foreach ( (array) $results['originals'] as $original ) {
			$stored[ (int) $original['attachment_id'] ] = $original;
		}

		$ids = array();
		foreach ( $attachment_ids as $id ) {
			if ( ! is_scalar( $id ) || ! ctype_digit( (string) $id ) || (int) $id <= 0 ) {
				return new WP_Error( 'blt_media_invalid_item', __( 'One of the requested attachment IDs is not valid.', 'blt-optimized' ) );
			}
			if ( ! isset( $stored[ (int) $id ] ) ) {
				return new WP_Error( 'blt_media_unknown_item', __( 'One or more items are not in the latest media audit results, so nothing was deleted. Run the audit again.', 'blt-optimized' ) );
			}
			$ids[ (int) $id ] = true;
		}
		$ids = array_keys( $ids );
		if ( empty( $ids ) ) {
			return new WP_Error( 'blt_media_nothing_selected', __( 'No images were selected.', 'blt-optimized' ) );
		}
		if ( count( $ids ) > self::FIX_MAX_ITEMS ) {
			return new WP_Error( 'blt_media_too_many', __( 'Too many items in one request. Delete them in smaller batches.', 'blt-optimized' ) );
		}

		$handled = array();
		$skipped = 0;
		$files   = 0;
		$bytes   = 0;
		$deleted = array();

		foreach ( $ids as $id ) {
			$item = $stored[ $id ];
			$rel  = get_post_meta( $id, '_wp_attached_file', true );
			if ( ! is_string( $rel ) || ! preg_match( '#^(\d{4}/(?:0[1-9]|1[0-2]))/([^/]+)$#', $rel, $m ) ) {
				++$skipped;
				continue;
			}
			$folder = $m[1];
			$main   = $m[2];
			$meta   = get_post_meta( $id, '_wp_attachment_metadata', true );
			if ( ! is_array( $meta ) || ! isset( $meta['original_image'] ) ) {
				$handled[ $id ] = true; // No original recorded any more.
				continue;
			}
			$original = $meta['original_image'];
			if ( ! $this->is_plain_media_name( $original ) || $folder . '/' . $original !== $item['path'] || strtolower( $original ) === strtolower( $main ) ) {
				++$skipped;
				continue;
			}

			// The scaled file must exist; the original must be a safe real file.
			if ( false === $this->safe_file_path( $folder . '/' . $main ) ) {
				++$skipped;
				continue;
			}
			$abs = $this->safe_file_path( $folder . '/' . $original );
			if ( false === $abs ) {
				if ( ! file_exists( $this->upload_base() . '/' . $folder . '/' . $original ) ) {
					$handled[ $id ] = true; // Already gone.
				} else {
					++$skipped;
				}
				continue;
			}

			// Never delete a file the attachment also uses as a size or an editor backup.
			$backup_names = array();
			$this->collect_names( get_post_meta( $id, '_wp_attachment_backup_sizes', true ), $backup_names );
			$kept = $meta;
			unset( $kept['original_image'] );
			$kept_names = array();
			$this->collect_names( $kept, $kept_names );
			$protect = array_flip( array_map( 'strtolower', array_merge( $backup_names, $kept_names ) ) );
			if ( isset( $protect[ strtolower( $original ) ] ) || $this->used_by_other_attachment( $original, $id ) ) {
				++$skipped;
				continue;
			}
			if ( ! empty( $this->find_referenced( array( $original ), true ) ) ) {
				$handled[ $id ] = true; // Content links to the original: keep it, drop from the list.
				continue;
			}

			$size = (int) @filesize( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			// Metadata first (see delete_stale_sizes()).
			unset( $meta['original_image'] );
			if ( ! $this->save_attachment_meta( $id, $meta ) ) {
				++$skipped;
				continue;
			}
			$handled[ $id ] = true;

			wp_delete_file( $abs );
			clearstatcache( true, $abs );
			if ( file_exists( $abs ) ) {
				++$skipped;
				continue;
			}
			++$files;
			$bytes    += $size;
			$deleted[] = $folder . '/' . $original;
		}

		$this->drop_results_items(
			'originals',
			static function ( $item ) use ( $handled ) {
				return isset( $handled[ (int) $item['attachment_id'] ] );
			}
		);

		if ( $files > 0 ) {
			$this->audit_log->log(
				'media_delete_originals',
				sprintf(
					/* translators: 1: file count, 2: list of files. */
					_n( 'Deleted %1$d pre-scaled original image: %2$s', 'Deleted %1$d pre-scaled original images: %2$s', $files, 'blt-optimized' ),
					$files,
					$this->summarize_list( $deleted )
				),
				$bytes,
				$files
			);
		}

		return array(
			'files'   => $files,
			'bytes'   => $bytes,
			'skipped' => $skipped,
		);
	}

	/**
	 * Write attachment metadata through WordPress and report success.
	 *
	 * @param int   $id   Attachment ID.
	 * @param array $meta New metadata.
	 * @return bool
	 */
	private function save_attachment_meta( $id, array $meta ) {
		$saved = wp_update_attachment_metadata( $id, $meta );
		return false !== $saved;
	}

	/**
	 * Remove items from one list of the stored results, refresh totals, save.
	 *
	 * @param string   $list      Results key (orphans|stale_sizes|originals).
	 * @param callable $is_handled Receives an item; true to drop it.
	 * @return void
	 */
	private function drop_results_items( $list, $is_handled ) {
		$results = $this->get_results();
		if ( null === $results || empty( $results[ $list ] ) ) {
			return;
		}
		$results[ $list ] = array_values( array_filter( $results[ $list ], static function ( $item ) use ( $is_handled ) {
			return ! $is_handled( $item );
		} ) );
		$results['totals'] = $this->compute_totals( $results );
		update_option( self::RESULTS_OPTION, $results, false );
	}

	/**
	 * Validate the shape of a relative uploads path from the results.
	 *
	 * @param string $path Relative path.
	 * @return string|false The path, or false.
	 */
	private function safe_relative_form( $path ) {
		if ( ! is_string( $path ) || strlen( $path ) > 600 || false !== strpbrk( $path, "\0\\" ) ) {
			return false;
		}
		if ( ! preg_match( '#^\d{4}/(?:0[1-9]|1[0-2])/[^/]+$#', $path ) || '.' === basename( $path )[0] ) {
			return false;
		}
		return $path;
	}

	/**
	 * Uploads basedir ('' when unavailable).
	 *
	 * @return string
	 */
	private function upload_base() {
		$uploads = wp_get_upload_dir();
		return ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) ? untrailingslashit( $uploads['basedir'] ) : '';
	}

	/**
	 * "a, b, c … and N more" for audit-log details.
	 *
	 * @param string[] $items Items.
	 * @param int      $max   Items to show.
	 * @return string
	 */
	private function summarize_list( array $items, $max = 20 ) {
		$items = array_values( array_map( 'strval', $items ) );
		$shown = array_slice( $items, 0, $max );
		$text  = implode( ', ', $shown );
		if ( count( $items ) > $max ) {
			/* translators: %d: number of additional items. */
			$text .= ' ' . sprintf( __( '… and %d more', 'blt-optimized' ), count( $items ) - $max );
		}
		return $text;
	}

	/* ------------------------------------------------------------------ */
	/* Findings                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Findings from the stored results (empty when there are none).
	 *
	 * @return array[]
	 */
	public function findings() {
		$r = $this->get_results();
		if ( null === $r ) {
			return array();
		}

		$totals    = isset( $r['totals'] ) ? $r['totals'] : array();
		$findings  = array();
		$truncated = ! empty( $r['truncated'] );
		$partial   = $truncated ? ' ' . __( 'The audit hit its safety limits on a very large library, so this list may be incomplete.', 'blt-optimized' ) : '';
		$backup_note = __( 'Take a backup first — deleted files cannot be recovered.', 'blt-optimized' );

		// Orphan files.
		if ( ! empty( $r['orphans'] ) ) {
			$count = count( $r['orphans'] );
			$bytes = (int) $totals['orphans_bytes'];
			$batch = array_slice( $r['orphans'], 0, self::FINDING_MAX_ARGS );
			$bsize = $this->sum_bytes( $batch );

			$summary = __( 'These files sit in uploads/YYYY/MM folders but belong to no Media Library item — not as an original, thumbnail, edited-image backup or WebP/AVIF copy — and no post, widget, option or meta value mentions their file name. They are usually leftovers from deleted media, failed imports or removed plugins, and deleting them frees disk space and shrinks backups.', 'blt-optimized' );
			if ( ! empty( $r['orphans_referenced'] ) ) {
				/* translators: %s: number of files. */
				$summary .= ' ' . sprintf( _n( '%s more file was left alone because content still mentions it.', '%s more files were left alone because content still mentions them.', (int) $r['orphans_referenced'], 'blt-optimized' ), number_format_i18n( (int) $r['orphans_referenced'] ) );
			}
			if ( ! empty( $r['other_unknown']['count'] ) ) {
				$summary .= ' ' . sprintf(
					/* translators: 1: number of files, 2: size. */
					_n( '%1$s other unrecognised file (%2$s) was not touched.', '%1$s other unrecognised files (%2$s) were not touched.', (int) $r['other_unknown']['count'], 'blt-optimized' ),
					number_format_i18n( (int) $r['other_unknown']['count'] ),
					size_format( (int) $r['other_unknown']['bytes'], 1 )
				);
			}

			$rows = array();
			foreach ( array_slice( $r['orphans'], 0, 50 ) as $o ) {
				$rows[] = array( $o['path'], size_format( (int) $o['bytes'], 1 ) );
			}

			$findings[] = BLT_Optimized_Findings::make(
				array(
					'id'       => 'storage.media_orphans',
					'key'      => 'storage.media_orphans',
					'category' => 'storage',
					'severity' => $this->severity_for_bytes( $bytes ),
					'title'    => sprintf(
						/* translators: 1: number of files, 2: size. */
						_n( '%1$s orphaned file in uploads wastes %2$s', '%1$s orphaned files in uploads waste %2$s', $count, 'blt-optimized' ),
						number_format_i18n( $count ),
						size_format( $bytes, 1 )
					),
					'summary'  => $summary . $partial,
					'impact'   => array(
						'type'  => 'bytes',
						'value' => $bytes,
					),
					'where'    => $this->top_folders( $r['orphans'] ),
					'details'  => array(
						'columns' => array( __( 'File (in uploads)', 'blt-optimized' ), __( 'Size', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => array(
						'type'        => 'action',
						/* translators: %s: number of files. */
						'label'       => sprintf( _n( 'Delete %s orphaned file', 'Delete %s orphaned files', count( $batch ), 'blt-optimized' ), number_format_i18n( count( $batch ) ) ),
						'action'      => 'delete_orphan_files',
						'args'        => array( 'paths' => wp_list_pluck( $batch, 'path' ) ),
						'destructive' => true,
						'confirm'     => sprintf(
							/* translators: 1: number of files, 2: size, 3: backup reminder. */
							_n( 'Permanently delete %1$s orphaned file (%2$s)? It is not used by any media item or content. %3$s', 'Permanently delete %1$s orphaned files (%2$s)? They are not used by any media item or content. %3$s', count( $batch ), 'blt-optimized' ),
							number_format_i18n( count( $batch ) ),
							size_format( $bsize, 1 ),
							$backup_note
						),
					),
					'source'   => 'media',
				)
			);
		}

		// Stale thumbnails.
		if ( ! empty( $r['stale_sizes'] ) ) {
			$count = count( $r['stale_sizes'] );
			$bytes = (int) $totals['stale_bytes'];
			$batch = array_slice( $r['stale_sizes'], 0, self::FINDING_MAX_ARGS );
			$bsize = $this->sum_bytes( $batch );
			$names = array();
			foreach ( $r['stale_sizes'] as $s ) {
				$names[ $s['size'] ] = ( isset( $names[ $s['size'] ] ) ? $names[ $s['size'] ] : 0 ) + 1;
			}
			arsort( $names );

			$ids = wp_list_pluck( array_slice( $r['stale_sizes'], 0, 50 ), 'attachment_id' );
			if ( function_exists( '_prime_post_caches' ) ) {
				_prime_post_caches( array_map( 'intval', $ids ), false, false );
			}
			$rows = array();
			foreach ( array_slice( $r['stale_sizes'], 0, 50 ) as $s ) {
				$title  = get_the_title( (int) $s['attachment_id'] );
				$rows[] = array(
					( '' !== $title ? $title : __( '(no title)', 'blt-optimized' ) ) . ' (#' . (int) $s['attachment_id'] . ')',
					$s['size'],
					$s['path'],
					size_format( (int) $s['bytes'], 1 ),
				);
			}

			$findings[] = BLT_Optimized_Findings::make(
				array(
					'id'       => 'storage.media_stale_sizes',
					'key'      => 'storage.media_stale_sizes',
					'category' => 'storage',
					'severity' => $this->severity_for_bytes( $bytes ),
					'title'    => sprintf(
						/* translators: 1: number of files, 2: size, 3: number of image sizes. */
						_n( '%1$s stale thumbnail (%2$s) from %3$s image size that is no longer registered', '%1$s stale thumbnails (%2$s) from %3$s image sizes that are no longer registered', $count, 'blt-optimized' ),
						number_format_i18n( $count ),
						size_format( $bytes, 1 ),
						number_format_i18n( count( $names ) )
					),
					'summary'  => __( 'A past theme or plugin registered these image sizes, but nothing registers them now, so WordPress no longer uses or regenerates the files — yet every upload still carries them and lists them in its metadata. Deleting them removes the files and the metadata entries; no post, widget or option mentions them.', 'blt-optimized' ) . $partial,
					'impact'   => array(
						'type'  => 'bytes',
						'value' => $bytes,
					),
					'where'    => array_slice( array_keys( $names ), 0, 10 ),
					'details'  => array(
						'columns' => array( __( 'Media item', 'blt-optimized' ), __( 'Unregistered size', 'blt-optimized' ), __( 'File (in uploads)', 'blt-optimized' ), __( 'Size', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => array(
						'type'        => 'action',
						/* translators: %s: number of files. */
						'label'       => sprintf( _n( 'Delete %s stale thumbnail', 'Delete %s stale thumbnails', count( $batch ), 'blt-optimized' ), number_format_i18n( count( $batch ) ) ),
						'action'      => 'delete_stale_sizes',
						'args'        => array(
							'items' => array_map(
								static function ( $s ) {
									return array(
										'attachment_id' => (int) $s['attachment_id'],
										'size'          => (string) $s['size'],
									);
								},
								$batch
							),
						),
						'destructive' => true,
						'confirm'     => sprintf(
							/* translators: 1: number of files, 2: size, 3: backup reminder. */
							__( 'Permanently delete %1$s thumbnail file(s) (%2$s) for image sizes that no theme or plugin registers, and remove them from the media metadata? If a theme or plugin that uses one of these sizes is only temporarily disabled, it would have to regenerate them. %3$s', 'blt-optimized' ),
							number_format_i18n( count( $batch ) ),
							size_format( $bsize, 1 ),
							$backup_note
						),
					),
					'source'   => 'media',
				)
			);
		}

		// Pre-scaled originals.
		if ( ! empty( $r['originals'] ) ) {
			$count = count( $r['originals'] );
			$bytes = (int) $totals['originals_bytes'];
			$batch = array_slice( $r['originals'], 0, self::FINDING_MAX_ARGS );
			$bsize = $this->sum_bytes( $batch );

			$rows = array();
			foreach ( array_slice( $r['originals'], 0, 50 ) as $o ) {
				$rows[] = array( $o['path'], $o['scaled_path'], size_format( (int) $o['bytes'], 1 ) );
			}

			$findings[] = BLT_Optimized_Findings::make(
				array(
					'id'       => 'storage.media_originals',
					'key'      => 'storage.media_originals',
					'category' => 'storage',
					'severity' => $this->severity_for_bytes( $bytes ),
					'title'    => sprintf(
						/* translators: 1: number of images, 2: size. */
						_n( '%1$s full-size original (%2$s) kept after WordPress scaled the image down', '%1$s full-size originals (%2$s) kept after WordPress scaled the images down', $count, 'blt-optimized' ),
						number_format_i18n( $count ),
						size_format( $bytes, 1 )
					),
					'summary'  => __( 'When a very large image is uploaded, WordPress makes a scaled copy (…-scaled.jpg) for the site to use but keeps the untouched original on disk. The original is only needed to re-crop from the full-size file or to restore it after editing. Deleting it keeps the scaled image and all thumbnails working.', 'blt-optimized' ) . $partial,
					'impact'   => array(
						'type'  => 'bytes',
						'value' => $bytes,
					),
					'where'    => $this->top_folders( $r['originals'] ),
					'details'  => array(
						'columns' => array( __( 'Original (in uploads)', 'blt-optimized' ), __( 'Scaled version kept', 'blt-optimized' ), __( 'Original size', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => array(
						'type'        => 'action',
						/* translators: %s: number of images. */
						'label'       => sprintf( _n( 'Delete %s original image', 'Delete %s original images', count( $batch ), 'blt-optimized' ), number_format_i18n( count( $batch ) ) ),
						'action'      => 'delete_original_images',
						'args'        => array( 'attachment_ids' => array_map( 'intval', wp_list_pluck( $batch, 'attachment_id' ) ) ),
						'destructive' => true,
						'confirm'     => sprintf(
							/* translators: 1: number of images, 2: size, 3: backup reminder. */
							__( 'Permanently delete %1$s full-size original image(s) (%2$s)? The scaled versions stay in use, but WordPress can no longer regenerate thumbnails from, or restore, the unscaled original. %3$s', 'blt-optimized' ),
							number_format_i18n( count( $batch ) ),
							size_format( $bsize, 1 ),
							$backup_note
						),
					),
					'source'   => 'media',
				)
			);
		}

		// Unattached + unreferenced (flag only).
		if ( ! empty( $r['unattached'] ) ) {
			$count = count( $r['unattached'] );
			$bytes = (int) $totals['unattached_bytes'];
			$rows  = array();
			foreach ( array_slice( $r['unattached'], 0, 50 ) as $u ) {
				$rows[] = array(
					( '' !== $u['title'] ? $u['title'] : __( '(no title)', 'blt-optimized' ) ) . ' (#' . (int) $u['attachment_id'] . ')',
					$u['path'],
					size_format( (int) $u['bytes'], 1 ),
				);
			}

			$findings[] = BLT_Optimized_Findings::make(
				array(
					'id'       => 'storage.media_unattached',
					'key'      => 'storage.media_unattached',
					'category' => 'storage',
					'severity' => $this->severity_for_bytes( $bytes ),
					'title'    => sprintf(
						/* translators: 1: number of media items, 2: size. */
						_n( '%1$s media item (%2$s) is attached to no post and nothing seems to use it', '%1$s media items (%2$s) are attached to no post and nothing seems to use them', $count, 'blt-optimized' ),
						number_format_i18n( $count ),
						size_format( $bytes, 1 )
					),
					'summary'  => __( 'These uploads have no parent post, are not a featured image, site icon, logo or header, and neither their IDs nor their file names turn up in post content, widgets or settings. They may still be used in a way the audit cannot see (page-builder fields, custom code), so review them in the Media Library before deleting anything.', 'blt-optimized' ) . $partial,
					'impact'   => array(
						'type'  => 'bytes',
						'value' => $bytes,
					),
					'where'    => array_slice( wp_list_pluck( $r['unattached'], 'path' ), 0, 10 ),
					'details'  => array(
						'columns' => array( __( 'Media item', 'blt-optimized' ), __( 'File (in uploads)', 'blt-optimized' ), __( 'Size on disk', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => array(
						'type'  => 'link',
						'label' => __( 'Review unattached media', 'blt-optimized' ),
						'url'   => admin_url( 'upload.php?mode=list&attachment-filter=detached' ),
					),
					'source'   => 'media',
				)
			);
		}

		// Duplicates (flag only).
		if ( ! empty( $r['duplicates'] ) ) {
			$count = count( $r['duplicates'] );
			$bytes = (int) $totals['duplicates_bytes'];
			$rows  = array();

			$dup_where = array();
			foreach ( array_slice( $r['duplicates'], 0, 10 ) as $d ) {
				$dup_where[] = (string) reset( $d['paths'] );
			}
			foreach ( array_slice( $r['duplicates'], 0, 50 ) as $d ) {
				$copies = count( $d['paths'] );
				$list   = implode( ', ', array_slice( $d['paths'], 0, 3 ) );
				if ( $copies > 3 ) {
					/* translators: %d: number of additional copies. */
					$list .= ' ' . sprintf( __( '… +%d more', 'blt-optimized' ), $copies - 3 );
				}
				$rows[] = array( size_format( (int) $d['bytes'], 1 ), $copies, $list );
			}

			$findings[] = BLT_Optimized_Findings::make(
				array(
					'id'       => 'storage.media_duplicates',
					'key'      => 'storage.media_duplicates',
					'category' => 'storage',
					'severity' => $this->severity_for_bytes( $bytes ),
					'title'    => sprintf(
						/* translators: 1: number of groups, 2: size. */
						_n( '%1$s file was uploaded more than once, wasting %2$s', '%1$s files were uploaded more than once, wasting %2$s', $count, 'blt-optimized' ),
						number_format_i18n( $count ),
						size_format( $bytes, 1 )
					),
					'summary'  => __( 'Byte-for-byte identical copies of the same file are stored separately in the Media Library, each with its own thumbnails. Keeping one copy and re-pointing the posts that use the others frees the space. This needs a human decision, so nothing is deleted automatically.', 'blt-optimized' ) . $partial,
					'impact'   => array(
						'type'  => 'bytes',
						'value' => $bytes,
					),
					'where'    => $dup_where,
					'details'  => array(
						'columns' => array( __( 'Size of each copy', 'blt-optimized' ), __( 'Copies', 'blt-optimized' ), __( 'Files (in uploads)', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => array(
						'type'  => 'manual',
						'label' => __( 'How to remove duplicates', 'blt-optimized' ),
						'steps' => __( "1. In Media > Library (list view), search for the file name from the table and open each copy to see where it is used.\n2. Pick one copy to keep and replace the others in the posts or pages that use them.\n3. Delete the redundant copies from the Media Library once nothing uses them.", 'blt-optimized' ),
					),
					'source'   => 'media',
				)
			);
		}

		// Largest images.
		$large = array();
		foreach ( (array) $r['largest'] as $image ) {
			if ( (int) $image['bytes'] >= self::LARGE_IMAGE_BYTES ) {
				$large[] = $image;
			}
		}
		if ( ! empty( $large ) ) {
			$count    = count( $large );
			$bytes    = $this->sum_bytes( $large );
			$settings = class_exists( 'BLT_Optimized' ) ? BLT_Optimized::get_settings() : array();
			$enabled  = ! empty( $settings['enable_images'] );

			$rows = array();
			foreach ( $large as $image ) {
				$rows[] = array(
					$image['path'],
					( $image['width'] && $image['height'] ) ? (int) $image['width'] . ' × ' . (int) $image['height'] : '—',
					size_format( (int) $image['bytes'], 1 ),
				);
			}

			$findings[] = BLT_Optimized_Findings::make(
				array(
					'id'       => 'storage.media_largest',
					'key'      => 'storage.media_largest',
					'category' => 'storage',
					'severity' => 'warning',
					'title'    => sprintf(
						/* translators: 1: number of images, 2: biggest file name, 3: biggest file size. */
						_n( '%1$s of your largest images is over 1 MB (%2$s, %3$s)', '%1$s of your largest images are over 1 MB (biggest: %2$s, %3$s)', $count, 'blt-optimized' ),
						number_format_i18n( $count ),
						basename( $large[0]['path'] ),
						size_format( (int) $large[0]['bytes'], 1 )
					),
					'summary'  => __( 'Very large image files slow down pages that show them and use a lot of disk space. Compressing them and serving WebP typically shrinks them by half or more without a visible difference.', 'blt-optimized' ) . $partial,
					'impact'   => array(
						'type'  => 'bytes',
						'value' => $bytes,
					),
					'where'    => array_slice( wp_list_pluck( $large, 'path' ), 0, 10 ),
					'details'  => array(
						'columns' => array( __( 'Image (in uploads)', 'blt-optimized' ), __( 'Dimensions', 'blt-optimized' ), __( 'Size', 'blt-optimized' ) ),
						'rows'    => $rows,
					),
					'fix'      => array(
						'type'  => 'link',
						'label' => $enabled ? __( 'Optimize images', 'blt-optimized' ) : __( 'Enable image optimization', 'blt-optimized' ),
						'url'   => admin_url( $enabled ? 'admin.php?page=blt-optimizer' : 'admin.php?page=blt-optimized-settings' ),
					),
					'source'   => 'media',
				)
			);
		}

		// Nothing wasteful found.
		$wasteful = false;
		foreach ( array( 'orphans', 'stale_sizes', 'originals', 'unattached', 'duplicates' ) as $kind ) {
			if ( ! empty( $r[ $kind ] ) ) {
				$wasteful = true;
			}
		}
		if ( ! $wasteful ) {
			if ( $truncated ) {
				$findings[] = BLT_Optimized_Findings::make(
					array(
						'id'       => 'storage.media_partial',
						'key'      => 'storage.media_partial',
						'category' => 'storage',
						'severity' => 'notice',
						'title'    => __( 'The media audit only covered part of a very large uploads library', 'blt-optimized' ),
						'summary'  => __( 'It found no wasted files in what it examined, but it stopped at its safety limits (file, folder or comparison caps) before covering everything.', 'blt-optimized' ),
						'source'   => 'media',
					)
				);
			} else {
				$findings[] = BLT_Optimized_Findings::make(
					array(
						'id'       => 'storage.media_clean',
						'key'      => 'storage.media_clean',
						'category' => 'storage',
						'severity' => 'pass',
						'title'    => __( 'No orphaned, stale or duplicate media files found', 'blt-optimized' ),
						'summary'  => __( 'Every file in the dated uploads folders belongs to a Media Library item, and no unused thumbnails, leftover originals or duplicates were found.', 'blt-optimized' ),
						'source'   => 'media',
					)
				);
			}
		}

		return $findings;
	}

	/**
	 * Severity by reclaimable bytes: ≥ 500 MB critical, ≥ 50 MB warning, else notice.
	 *
	 * @param int $bytes Bytes.
	 * @return string
	 */
	private function severity_for_bytes( $bytes ) {
		if ( $bytes >= 500 * MB_IN_BYTES ) {
			return 'critical';
		}
		if ( $bytes >= 50 * MB_IN_BYTES ) {
			return 'warning';
		}
		return 'notice';
	}

	/**
	 * Sum the `bytes` of a list of items.
	 *
	 * @param array[] $items Items.
	 * @return int
	 */
	private function sum_bytes( array $items ) {
		$total = 0;
		foreach ( $items as $item ) {
			$total += isset( $item['bytes'] ) ? (int) $item['bytes'] : 0;
		}
		return $total;
	}

	/**
	 * The folders holding most of a list of items, as `uploads/YYYY/MM`.
	 *
	 * @param array[] $items Items with a `path`.
	 * @return string[]
	 */
	private function top_folders( array $items ) {
		$by_folder = array();
		foreach ( $items as $item ) {
			$folder = 'uploads/' . dirname( (string) $item['path'] );
			$by_folder[ $folder ] = ( isset( $by_folder[ $folder ] ) ? $by_folder[ $folder ] : 0 ) + ( isset( $item['bytes'] ) ? (int) $item['bytes'] : 0 );
		}
		arsort( $by_folder );
		return array_slice( array_keys( $by_folder ), 0, 5 );
	}
}
