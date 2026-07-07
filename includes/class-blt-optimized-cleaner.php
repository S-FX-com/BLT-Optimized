<?php
/**
 * Orphaned data cleanup routines — every category is dry-run first.
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;

/**
 * Database-side orphaned data cleanup. Each category supports a preview
 * (count + estimated bytes, no changes) and an execute step. Every execute
 * is capability- and nonce-gated upstream, and logged to the audit log.
 */
class BLT_Optimized_Cleaner {

	/**
	 * Rows deleted per query iteration, to keep individual statements short.
	 */
	const BATCH_SIZE = 5000;

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

	/**
	 * Cleanup category definitions.
	 *
	 * @return array[] Keyed by category id.
	 */
	public function get_categories() {
		$settings = BLT_Optimized::get_settings();

		return array(
			'orphaned_postmeta'       => array(
				'label'       => __( 'Orphaned post meta', 'blt-optimized' ),
				'description' => __( 'Meta rows referencing posts that no longer exist.', 'blt-optimized' ),
			),
			'orphaned_usermeta'       => array(
				'label'       => __( 'Orphaned user meta', 'blt-optimized' ),
				'description' => __( 'Meta rows referencing deleted users.', 'blt-optimized' ),
			),
			'orphaned_commentmeta'    => array(
				'label'       => __( 'Orphaned comment meta', 'blt-optimized' ),
				'description' => __( 'Meta rows referencing deleted comments.', 'blt-optimized' ),
			),
			'orphaned_relationships'  => array(
				'label'       => __( 'Orphaned term relationships', 'blt-optimized' ),
				'description' => __( 'Term relationships pointing at deleted posts or deleted taxonomies.', 'blt-optimized' ),
			),
			'orphaned_terms'          => array(
				'label'       => __( 'Orphaned terms', 'blt-optimized' ),
				'description' => __( 'Term rows with no taxonomy entry.', 'blt-optimized' ),
			),
			'transients'              => array(
				'label'       => __( 'Expired and orphaned transients', 'blt-optimized' ),
				'description' => __( 'Expired transients, timeout rows with no matching value, and value rows with no matching timeout. Transients are cache — WordPress regenerates them on demand.', 'blt-optimized' ),
			),
			'oembed_cache'            => array(
				'label'       => __( 'oEmbed cache', 'blt-optimized' ),
				'description' => __( 'Cached oEmbed responses in post meta. Regenerated automatically, safe to clear.', 'blt-optimized' ),
			),
			'session_tokens'          => array(
				'label'       => __( 'Expired session tokens', 'blt-optimized' ),
				'description' => __( 'Expired login sessions stored in user meta. Active sessions are not touched.', 'blt-optimized' ),
			),
			'revisions'               => array(
				'label'       => __( 'Excess post revisions', 'blt-optimized' ),
				'description' => sprintf(
					/* translators: %d: retention count. */
					__( 'Revisions beyond the newest %d per post (configurable in Settings).', 'blt-optimized' ),
					(int) $settings['revision_retention']
				),
			),
			'trashed_posts'           => array(
				'label'       => __( 'Old trashed posts', 'blt-optimized' ),
				'description' => sprintf(
					/* translators: %d: age in days. */
					__( 'Trashed posts older than %d days (configurable in Settings).', 'blt-optimized' ),
					(int) $settings['trash_age_days']
				),
			),
			'spam_trashed_comments'   => array(
				'label'       => __( 'Old spam and trashed comments', 'blt-optimized' ),
				'description' => sprintf(
					/* translators: %d: age in days. */
					__( 'Spam and trashed comments older than %d days (configurable in Settings).', 'blt-optimized' ),
					(int) $settings['trash_age_days']
				),
			),
			'leftover_tables'         => array(
				'label'       => __( 'Leftover tables from uninstalled plugins', 'blt-optimized' ),
				'description' => __( 'Prefixed tables that do not match WordPress core or any active plugin. Flagged for review only — this plugin never drops tables.', 'blt-optimized' ),
				'flag_only'   => true,
			),
		);
	}

	/**
	 * Dry-run preview of a category.
	 *
	 * @param string $category Category id.
	 * @return array|WP_Error { count, bytes, items? }
	 */
	public function preview( $category ) {
		if ( ! array_key_exists( $category, $this->get_categories() ) ) {
			return new WP_Error( 'blt_unknown_category', __( 'Unknown cleanup category.', 'blt-optimized' ) );
		}
		$method = 'preview_' . $category;
		return $this->$method();
	}

	/**
	 * Execute a cleanup category.
	 *
	 * @param string $category Category id.
	 * @return array|WP_Error { rows, bytes }
	 */
	public function execute( $category ) {
		$categories = $this->get_categories();
		if ( ! array_key_exists( $category, $categories ) ) {
			return new WP_Error( 'blt_unknown_category', __( 'Unknown cleanup category.', 'blt-optimized' ) );
		}
		if ( ! empty( $categories[ $category ]['flag_only'] ) ) {
			return new WP_Error( 'blt_flag_only', __( 'This category is flag-only and cannot be executed.', 'blt-optimized' ) );
		}

		/**
		 * Fires before a cleanup category runs.
		 *
		 * @param string $category Category id.
		 */
		do_action( 'blt_optimized_before_cleanup', $category );

		$method = 'execute_' . $category;
		$result = $this->$method();

		$this->audit_log->log(
			'cleanup_' . $category,
			sprintf(
				/* translators: 1: category label, 2: row count, 3: human-readable size. */
				__( 'Cleanup "%1$s": %2$d rows removed, ~%3$s reclaimed', 'blt-optimized' ),
				$categories[ $category ]['label'],
				$result['rows'],
				size_format( $result['bytes'] )
			),
			$result['bytes'],
			$result['rows']
		);

		/**
		 * Fires after a cleanup category runs.
		 *
		 * @param string $category Category id.
		 * @param array  $result   { rows, bytes }.
		 */
		do_action( 'blt_optimized_after_cleanup', $category, $result );

		return $result;
	}

	/* ------------------------------------------------------------------ */
	/* Orphaned meta                                                       */
	/* ------------------------------------------------------------------ */

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	/**
	 * Preview orphaned postmeta.
	 *
	 * @return array
	 */
	private function preview_orphaned_postmeta() {
		global $wpdb;
		$row = $wpdb->get_row(
			"SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(pm.meta_key) + LENGTH(COALESCE(pm.meta_value,''))),0) AS bytes
			 FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL",
			ARRAY_A
		);
		return $this->preview_result( $row );
	}

	/**
	 * Delete orphaned postmeta.
	 *
	 * @return array
	 */
	private function execute_orphaned_postmeta() {
		global $wpdb;
		$preview = $this->preview_orphaned_postmeta();
		$total   = $this->batched_delete_by_ids(
			"SELECT pm.meta_id FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL",
			$wpdb->postmeta,
			'meta_id'
		);
		return array(
			'rows'  => $total,
			'bytes' => $preview['bytes'],
		);
	}

	/**
	 * Preview orphaned usermeta.
	 *
	 * @return array
	 */
	private function preview_orphaned_usermeta() {
		global $wpdb;
		$row = $wpdb->get_row(
			"SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(um.meta_key) + LENGTH(COALESCE(um.meta_value,''))),0) AS bytes
			 FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID WHERE u.ID IS NULL",
			ARRAY_A
		);
		return $this->preview_result( $row );
	}

	/**
	 * Delete orphaned usermeta.
	 *
	 * @return array
	 */
	private function execute_orphaned_usermeta() {
		global $wpdb;
		$preview = $this->preview_orphaned_usermeta();
		$total   = $this->batched_delete_by_ids(
			"SELECT um.umeta_id FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID WHERE u.ID IS NULL",
			$wpdb->usermeta,
			'umeta_id'
		);
		return array(
			'rows'  => $total,
			'bytes' => $preview['bytes'],
		);
	}

	/**
	 * Preview orphaned commentmeta.
	 *
	 * @return array
	 */
	private function preview_orphaned_commentmeta() {
		global $wpdb;
		$row = $wpdb->get_row(
			"SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(cm.meta_key) + LENGTH(COALESCE(cm.meta_value,''))),0) AS bytes
			 FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID WHERE c.comment_ID IS NULL",
			ARRAY_A
		);
		return $this->preview_result( $row );
	}

	/**
	 * Delete orphaned commentmeta.
	 *
	 * @return array
	 */
	private function execute_orphaned_commentmeta() {
		global $wpdb;
		$preview = $this->preview_orphaned_commentmeta();
		$total   = $this->batched_delete_by_ids(
			"SELECT cm.meta_id FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID WHERE c.comment_ID IS NULL",
			$wpdb->commentmeta,
			'meta_id'
		);
		return array(
			'rows'  => $total,
			'bytes' => $preview['bytes'],
		);
	}

	/* ------------------------------------------------------------------ */
	/* Terms                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Preview orphaned term relationships.
	 *
	 * @return array
	 */
	private function preview_orphaned_relationships() {
		global $wpdb;
		$missing_tt = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
			 LEFT JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			 WHERE tt.term_taxonomy_id IS NULL"
		);
		$missing_post = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			 LEFT JOIN {$wpdb->posts} p ON tr.object_id = p.ID
			 WHERE p.ID IS NULL AND tt.taxonomy NOT IN ('link_category')"
		);
		$count = $missing_tt + $missing_post;
		return array(
			'count' => $count,
			'bytes' => $count * 20, // Two BIGINTs plus index overhead, rough.
		);
	}

	/**
	 * Delete orphaned term relationships.
	 *
	 * @return array
	 */
	private function execute_orphaned_relationships() {
		global $wpdb;
		$preview = $this->preview_orphaned_relationships();

		$total = (int) $wpdb->query(
			"DELETE tr FROM {$wpdb->term_relationships} tr
			 LEFT JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			 WHERE tt.term_taxonomy_id IS NULL"
		);
		$total += (int) $wpdb->query(
			"DELETE tr FROM {$wpdb->term_relationships} tr
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			 LEFT JOIN {$wpdb->posts} p ON tr.object_id = p.ID
			 WHERE p.ID IS NULL AND tt.taxonomy NOT IN ('link_category')"
		);

		// Recount affected taxonomies so term counts stay accurate.
		$tt_ids = $wpdb->get_col( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy}" );
		if ( $tt_ids ) {
			wp_update_term_count_now( array_map( 'intval', array_slice( $tt_ids, 0, 500 ) ), '' );
		}

		return array(
			'rows'  => $total,
			'bytes' => $preview['bytes'],
		);
	}

	/**
	 * Preview orphaned terms (terms with no term_taxonomy row).
	 *
	 * @return array
	 */
	private function preview_orphaned_terms() {
		global $wpdb;
		$row = $wpdb->get_row(
			"SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(t.name) + LENGTH(t.slug) + 24),0) AS bytes
			 FROM {$wpdb->terms} t LEFT JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
			 WHERE tt.term_id IS NULL",
			ARRAY_A
		);
		return $this->preview_result( $row );
	}

	/**
	 * Delete orphaned terms (and their now-orphaned termmeta).
	 *
	 * @return array
	 */
	private function execute_orphaned_terms() {
		global $wpdb;
		$preview = $this->preview_orphaned_terms();

		$total = (int) $wpdb->query(
			"DELETE t FROM {$wpdb->terms} t LEFT JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
			 WHERE tt.term_id IS NULL"
		);
		$total += (int) $wpdb->query(
			"DELETE tm FROM {$wpdb->termmeta} tm LEFT JOIN {$wpdb->terms} t ON tm.term_id = t.term_id
			 WHERE t.term_id IS NULL"
		);

		return array(
			'rows'  => $total,
			'bytes' => $preview['bytes'],
		);
	}

	/* ------------------------------------------------------------------ */
	/* Transients                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Preview expired + orphaned transients (options table; multisite
	 * site transients in sitemeta are out of scope for v1).
	 *
	 * @return array
	 */
	private function preview_transients() {
		global $wpdb;
		$now = time();

		$expired = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(option_name) + LENGTH(option_value)),0) AS bytes
				 FROM {$wpdb->options}
				 WHERE (option_name LIKE %s OR option_name LIKE %s) AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
				$now
			),
			ARRAY_A
		);

		$orphaned = $wpdb->get_row(
			"SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(o.option_name) + LENGTH(o.option_value)),0) AS bytes
			 FROM {$wpdb->options} o
			 LEFT JOIN {$wpdb->options} pair ON pair.option_name = CONCAT('_transient_', SUBSTRING(o.option_name, 20))
			 WHERE o.option_name LIKE '\\_transient\\_timeout\\_%' AND pair.option_id IS NULL"
		, ARRAY_A );

		return array(
			'count' => (int) $expired['cnt'] + (int) $orphaned['cnt'],
			'bytes' => (int) $expired['bytes'] + (int) $orphaned['bytes'],
			'notes' => __( 'Expired transient values are removed along with their timeout rows.', 'blt-optimized' ),
		);
	}

	/**
	 * Delete expired and orphaned transients via the API where possible.
	 *
	 * @return array
	 */
	private function execute_transients() {
		global $wpdb;
		$preview = $this->preview_transients();
		$now     = time();
		$total   = 0;

		// Expired transients: delete value + timeout pairs.
		$expired = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options}
				 WHERE option_name LIKE %s AND option_value < %d LIMIT 10000",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$now
			)
		);
		foreach ( $expired as $timeout_option ) {
			$key = substr( $timeout_option, strlen( '_transient_timeout_' ) );
			if ( delete_transient( $key ) ) {
				$total += 2;
			} else {
				$total += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name IN (%s, %s)", $timeout_option, '_transient_' . $key ) );
			}
		}

		$expired_site = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options}
				 WHERE option_name LIKE %s AND option_value < %d LIMIT 10000",
				$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
				$now
			)
		);
		foreach ( $expired_site as $timeout_option ) {
			$key = substr( $timeout_option, strlen( '_site_transient_timeout_' ) );
			if ( delete_site_transient( $key ) ) {
				$total += 2;
			}
		}

		// Orphaned timeout rows (no matching value row).
		$total += (int) $wpdb->query(
			"DELETE o FROM {$wpdb->options} o
			 LEFT JOIN {$wpdb->options} pair ON pair.option_name = CONCAT('_transient_', SUBSTRING(o.option_name, 20))
			 WHERE o.option_name LIKE '\\_transient\\_timeout\\_%' AND pair.option_id IS NULL"
		);

		return array(
			'rows'  => $total,
			'bytes' => $preview['bytes'],
		);
	}

	/* ------------------------------------------------------------------ */
	/* oEmbed cache                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Preview oEmbed cache postmeta.
	 *
	 * @return array
	 */
	private function preview_oembed_cache() {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(meta_key) + LENGTH(COALESCE(meta_value,''))),0) AS bytes
				 FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
				$wpdb->esc_like( '_oembed_' ) . '%'
			),
			ARRAY_A
		);
		return $this->preview_result( $row );
	}

	/**
	 * Delete oEmbed cache postmeta.
	 *
	 * @return array
	 */
	private function execute_oembed_cache() {
		global $wpdb;
		$preview = $this->preview_oembed_cache();
		$total   = 0;
		do {
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s LIMIT %d",
					$wpdb->esc_like( '_oembed_' ) . '%',
					self::BATCH_SIZE
				)
			);
			$total  += (int) $deleted;
		} while ( self::BATCH_SIZE === (int) $deleted );
		return array(
			'rows'  => $total,
			'bytes' => $preview['bytes'],
		);
	}

	/* ------------------------------------------------------------------ */
	/* Session tokens                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Preview expired session tokens. Counts users with at least one
	 * expired session; sizing is approximate.
	 *
	 * @return array
	 */
	private function preview_session_tokens() {
		global $wpdb;
		$rows  = $wpdb->get_results(
			"SELECT umeta_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'session_tokens'",
			ARRAY_A
		);
		$count = 0;
		$bytes = 0;
		$now   = time();
		foreach ( $rows as $row ) {
			$sessions = maybe_unserialize( $row['meta_value'] );
			if ( ! is_array( $sessions ) ) {
				continue;
			}
			foreach ( $sessions as $session ) {
				if ( isset( $session['expiration'] ) && $session['expiration'] < $now ) {
					$count++;
					$bytes += 200; // Approximate serialized session size.
				}
			}
		}
		return array(
			'count' => $count,
			'bytes' => $bytes,
		);
	}

	/**
	 * Remove expired session tokens, preserving active sessions.
	 *
	 * @return array
	 */
	private function execute_session_tokens() {
		global $wpdb;
		$rows  = $wpdb->get_results(
			"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'session_tokens'",
			ARRAY_A
		);
		$total = 0;
		$bytes = 0;
		$now   = time();
		foreach ( $rows as $row ) {
			$sessions = maybe_unserialize( $row['meta_value'] );
			if ( ! is_array( $sessions ) ) {
				continue;
			}
			$active = array_filter(
				$sessions,
				static function ( $session ) use ( $now ) {
					return isset( $session['expiration'] ) && $session['expiration'] >= $now;
				}
			);
			$removed = count( $sessions ) - count( $active );
			if ( $removed > 0 ) {
				if ( empty( $active ) ) {
					delete_user_meta( (int) $row['user_id'], 'session_tokens' );
				} else {
					update_user_meta( (int) $row['user_id'], 'session_tokens', $active );
				}
				$total += $removed;
				$bytes += $removed * 200;
			}
		}
		return array(
			'rows'  => $total,
			'bytes' => $bytes,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Revisions                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Revision IDs beyond the configured retention per post.
	 *
	 * @param int $limit Max IDs to return (0 = all).
	 * @return int[]
	 */
	private function excess_revision_ids( $limit = 0 ) {
		global $wpdb;
		$keep = max( 0, (int) BLT_Optimized::get_settings()['revision_retention'] );

		$parents = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_parent FROM {$wpdb->posts} WHERE post_type = 'revision'
				 GROUP BY post_parent HAVING COUNT(*) > %d",
				$keep
			)
		);

		$ids = array();
		foreach ( $parents as $parent ) {
			$excess = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent = %d
					 ORDER BY post_date DESC LIMIT %d, 999999",
					(int) $parent,
					$keep
				)
			);
			$ids    = array_merge( $ids, array_map( 'intval', $excess ) );
			if ( $limit && count( $ids ) >= $limit ) {
				return array_slice( $ids, 0, $limit );
			}
		}
		return $ids;
	}

	/**
	 * Preview excess revisions.
	 *
	 * @return array
	 */
	private function preview_revisions() {
		global $wpdb;
		$ids = $this->excess_revision_ids();
		if ( empty( $ids ) ) {
			return array(
				'count' => 0,
				'bytes' => 0,
			);
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$bytes        = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(LENGTH(post_content) + LENGTH(post_title) + LENGTH(post_excerpt)),0) FROM {$wpdb->posts} WHERE ID IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
				$ids
			)
		);
		return array(
			'count' => count( $ids ),
			'bytes' => $bytes,
		);
	}

	/**
	 * Delete excess revisions through the WordPress API (cleans meta too).
	 *
	 * @return array
	 */
	private function execute_revisions() {
		$preview = $this->preview_revisions();
		$total   = 0;
		foreach ( $this->excess_revision_ids() as $id ) {
			if ( wp_delete_post_revision( $id ) ) {
				$total++;
			}
		}
		return array(
			'rows'  => $total,
			'bytes' => $preview['bytes'],
		);
	}

	/* ------------------------------------------------------------------ */
	/* Trash                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Cutoff datetime for trash age.
	 *
	 * @return string MySQL datetime (GMT).
	 */
	private function trash_cutoff() {
		$days = max( 0, (int) BLT_Optimized::get_settings()['trash_age_days'] );
		return gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	}

	/**
	 * Preview old trashed posts.
	 *
	 * @return array
	 */
	private function preview_trashed_posts() {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(post_content) + LENGTH(post_title)),0) AS bytes
				 FROM {$wpdb->posts} WHERE post_status = 'trash' AND post_modified_gmt < %s",
				$this->trash_cutoff()
			),
			ARRAY_A
		);
		return $this->preview_result( $row );
	}

	/**
	 * Permanently delete old trashed posts through the API.
	 *
	 * @return array
	 */
	private function execute_trashed_posts() {
		global $wpdb;
		$preview = $this->preview_trashed_posts();
		$ids     = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash' AND post_modified_gmt < %s LIMIT 2000",
				$this->trash_cutoff()
			)
		);
		$total = 0;
		foreach ( $ids as $id ) {
			if ( wp_delete_post( (int) $id, true ) ) {
				$total++;
			}
		}
		return array(
			'rows'  => $total,
			'bytes' => $preview['bytes'],
		);
	}

	/**
	 * Preview old spam/trashed comments.
	 *
	 * @return array
	 */
	private function preview_spam_trashed_comments() {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(comment_content)),0) AS bytes
				 FROM {$wpdb->comments} WHERE comment_approved IN ('spam','trash') AND comment_date_gmt < %s",
				$this->trash_cutoff()
			),
			ARRAY_A
		);
		return $this->preview_result( $row );
	}

	/**
	 * Permanently delete old spam/trashed comments through the API.
	 *
	 * @return array
	 */
	private function execute_spam_trashed_comments() {
		global $wpdb;
		$preview = $this->preview_spam_trashed_comments();
		$ids     = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved IN ('spam','trash') AND comment_date_gmt < %s LIMIT 5000",
				$this->trash_cutoff()
			)
		);
		$total = 0;
		foreach ( $ids as $id ) {
			if ( wp_delete_comment( (int) $id, true ) ) {
				$total++;
			}
		}
		return array(
			'rows'  => $total,
			'bytes' => $preview['bytes'],
		);
	}

	/* ------------------------------------------------------------------ */
	/* Leftover tables (flag only)                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Preview leftover tables: prefixed tables that are not WordPress core,
	 * not this plugin's, and don't fuzzy-match any active plugin slug.
	 * Flag only — execution is intentionally not implemented.
	 *
	 * @return array
	 */
	private function preview_leftover_tables() {
		global $wpdb;

		$core = array(
			'commentmeta', 'comments', 'links', 'options', 'postmeta', 'posts',
			'term_relationships', 'term_taxonomy', 'termmeta', 'terms',
			'usermeta', 'users',
			// Multisite.
			'blogmeta', 'blogs', 'registration_log', 'signups', 'site', 'sitemeta',
		);
		$own  = array( BLT_Optimized::SCANS_TABLE, BLT_Optimized::AUDIT_TABLE );

		$active_tokens = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin_file ) {
			$slug = dirname( $plugin_file );
			if ( '.' === $slug ) {
				$slug = basename( $plugin_file, '.php' );
			}
			foreach ( preg_split( '/[-_]+/', strtolower( $slug ) ) as $token ) {
				if ( strlen( $token ) >= 3 ) {
					$active_tokens[ $token ] = true;
				}
			}
		}
		// Common table-name aliases for widespread plugins.
		foreach ( array( 'woocommerce' => 'wc', 'actionscheduler' => 'actionscheduler' ) as $token => $alias ) {
			if ( isset( $active_tokens[ $token ] ) ) {
				$active_tokens[ $alias ] = true;
			}
		}

		$tables = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT table_name AS name, (data_length + index_length) AS bytes, table_rows AS rows_est
				 FROM information_schema.TABLES WHERE table_schema = %s AND table_name LIKE %s',
				DB_NAME,
				$wpdb->esc_like( $wpdb->prefix ) . '%'
			),
			ARRAY_A
		);

		$items = array();
		$bytes = 0;
		foreach ( (array) $tables as $table ) {
			$suffix = substr( $table['name'], strlen( $wpdb->prefix ) );
			if ( in_array( $suffix, $core, true ) || in_array( $suffix, $own, true ) ) {
				continue;
			}
			// Multisite sub-site core tables (wp_2_posts etc.).
			if ( preg_match( '/^\d+_/', $suffix ) ) {
				continue;
			}
			$matched = false;
			foreach ( preg_split( '/_+/', strtolower( $suffix ) ) as $part ) {
				if ( isset( $active_tokens[ $part ] ) ) {
					$matched = true;
					break;
				}
			}
			if ( $matched ) {
				continue;
			}
			$items[] = array(
				'name'     => $table['name'],
				'bytes'    => (int) $table['bytes'],
				'rows_est' => (int) $table['rows_est'],
			);
			$bytes  += (int) $table['bytes'];
		}

		return array(
			'count' => count( $items ),
			'bytes' => $bytes,
			'items' => $items,
			'notes' => __( 'Review these manually. Table-to-plugin matching is heuristic — a listed table may belong to an active plugin using an unrelated table name.', 'blt-optimized' ),
		);
	}

	// phpcs:enable

	/**
	 * Delete rows in batches: select matching primary keys, delete them by
	 * ID, repeat. Avoids multi-table DELETE ... LIMIT (unsupported by MySQL)
	 * and keeps each statement short on shared hosting.
	 *
	 * @param string $select_sql SELECT returning primary key values (no LIMIT).
	 * @param string $table      Table to delete from.
	 * @param string $pk         Primary key column.
	 * @return int Rows deleted.
	 */
	private function batched_delete_by_ids( $select_sql, $table, $pk ) {
		global $wpdb;
		$total = 0;
		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = $wpdb->get_col( $select_sql . ' LIMIT ' . (int) self::BATCH_SIZE );
			if ( empty( $ids ) ) {
				break;
			}
			$id_list = implode( ',', array_map( 'intval', $ids ) );
			$deleted = (int) $wpdb->query( "DELETE FROM {$table} WHERE {$pk} IN ({$id_list})" );
			// phpcs:enable
			$total += $deleted;
		} while ( count( $ids ) === self::BATCH_SIZE && $deleted > 0 );
		return $total;
	}

	/**
	 * Normalize a count/bytes row into a preview result.
	 *
	 * @param array|null $row DB row with cnt/bytes.
	 * @return array
	 */
	private function preview_result( $row ) {
		return array(
			'count' => isset( $row['cnt'] ) ? (int) $row['cnt'] : 0,
			'bytes' => isset( $row['bytes'] ) ? (int) $row['bytes'] : 0,
		);
	}
}
