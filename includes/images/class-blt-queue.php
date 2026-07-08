<?php
/**
 * Bulk optimization queue built on Action Scheduler.
 *
 * @package BltImageOptimizer
 */

namespace BltImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Manages scanning the media library and dispatching batches of attachments
 * to the Worker via Action Scheduler. Falls back gracefully when Action
 * Scheduler is unavailable.
 */
class Queue {

	/**
	 * Action hook for processing a batch of attachments.
	 */
	const BATCH_HOOK = 'blt_optimizer_process_batch';

	/**
	 * Action hook for processing a single attachment (auto-optimize path).
	 */
	const SINGLE_HOOK = 'blt_optimizer_process_single';

	/**
	 * Action Scheduler group name.
	 */
	const GROUP = 'blt-optimizer';

	/**
	 * Option storing queue run state.
	 */
	const STATE_OPTION = 'blt_optimizer_queue_state';

	/**
	 * Register Action Scheduler callbacks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( self::BATCH_HOOK, array( $this, 'process_batch' ), 10, 1 );
		add_action( self::SINGLE_HOOK, array( $this, 'process_single' ), 10, 1 );
	}

	/**
	 * Whether Action Scheduler is available.
	 *
	 * @return bool
	 */
	public function scheduler_available() {
		return function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_has_scheduled_action' );
	}

	/**
	 * Find all attachment IDs that are images and not yet optimized.
	 *
	 * @return int[]
	 */
	public function find_unoptimized() {
		$mimes = array( 'image/jpeg', 'image/png', 'image/gif' );

		if ( Settings::get( 'convert_gifs' ) ) {
			$mimes[] = 'image/gif';
		}

		$query = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array_unique( $mimes ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => Attachment_Meta::OPTIMIZED_META,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Start a bulk run: scan, store state, and enqueue batches.
	 *
	 * @return array{queued:int,batches:int} Summary.
	 */
	public function start() {
		$ids        = $this->find_unoptimized();
		$batch_size = max( 1, (int) Settings::get( 'batch_size', 10 ) );
		$batches    = array_chunk( $ids, $batch_size );

		$this->set_state(
			array(
				'status'        => 'running',
				'total'         => count( $ids ),
				'processed'     => 0,
				'errors'        => 0,
				'skipped'       => 0,
				'started_at'    => current_time( 'mysql' ),
				'total_batches' => count( $batches ),
				'batches_done'  => 0,
			)
		);

		if ( $this->scheduler_available() ) {
			foreach ( $batches as $index => $chunk ) {
				as_enqueue_async_action(
					self::BATCH_HOOK,
					array( 'ids' => $chunk ),
					self::GROUP
				);
			}
		}

		return array(
			'queued'  => count( $ids ),
			'batches' => count( $batches ),
		);
	}

	/**
	 * Process a batch of attachment IDs.
	 *
	 * @param int[] $ids Attachment IDs (passed as the single AS arg).
	 * @return void
	 */
	public function process_batch( $ids ) {
		$state = $this->get_state();

		if ( 'paused' === ( $state['status'] ?? '' ) || 'cancelled' === ( $state['status'] ?? '' ) ) {
			return;
		}

		$ids      = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
		$uploader = new Uploader();

		foreach ( $ids as $attachment_id ) {
			$summary = $uploader->optimize_attachment( $attachment_id );

			$state['processed'] = ( $state['processed'] ?? 0 ) + $summary['processed'];
			$state['errors']    = ( $state['errors'] ?? 0 ) + $summary['errors'];
			$state['skipped']   = ( $state['skipped'] ?? 0 ) + $summary['skipped'];
		}

		$state['batches_done'] = ( $state['batches_done'] ?? 0 ) + 1;

		if ( $state['batches_done'] >= ( $state['total_batches'] ?? 0 ) ) {
			$state['status']      = 'done';
			$state['finished_at'] = current_time( 'mysql' );
		}

		$this->set_state( $state );
	}

	/**
	 * Process a single attachment (auto-optimize async path).
	 *
	 * @param int $attachment_id Attachment ID (passed as the single AS arg).
	 * @return void
	 */
	public function process_single( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( $attachment_id ) {
			( new Uploader() )->optimize_attachment( $attachment_id );
		}
	}

	/**
	 * Pause the current run.
	 *
	 * @return void
	 */
	public function pause() {
		$state           = $this->get_state();
		$state['status'] = 'paused';
		$this->set_state( $state );
	}

	/**
	 * Resume a paused run by re-enqueueing remaining unoptimized items.
	 *
	 * @return void
	 */
	public function resume() {
		$state = $this->get_state();

		if ( 'paused' !== ( $state['status'] ?? '' ) ) {
			return;
		}

		$ids        = $this->find_unoptimized();
		$batch_size = max( 1, (int) Settings::get( 'batch_size', 10 ) );
		$batches    = array_chunk( $ids, $batch_size );

		$state['status']        = 'running';
		$state['total_batches'] = ( $state['batches_done'] ?? 0 ) + count( $batches );
		$this->set_state( $state );

		if ( $this->scheduler_available() ) {
			foreach ( $batches as $chunk ) {
				as_enqueue_async_action( self::BATCH_HOOK, array( 'ids' => $chunk ), self::GROUP );
			}
		}
	}

	/**
	 * Cancel the run and clear scheduled batches.
	 *
	 * @return void
	 */
	public function cancel() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::BATCH_HOOK, array(), self::GROUP );
		}

		$state           = $this->get_state();
		$state['status'] = 'cancelled';
		$this->set_state( $state );
	}

	/**
	 * Get the current queue state with sane defaults.
	 *
	 * @return array<string,mixed>
	 */
	public function get_state() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Persist queue state.
	 *
	 * @param array<string,mixed> $state State to store.
	 * @return void
	 */
	private function set_state( array $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Reset queue state entirely.
	 *
	 * @return void
	 */
	public function reset() {
		delete_option( self::STATE_OPTION );
	}
}
