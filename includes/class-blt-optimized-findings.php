<?php
/**
 * Findings — the normalized unit every performance check produces.
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;

/**
 * One schema for every issue the performance scan reports, whichever module
 * found it (server/DB health checks, the front-end profiler, the media audit,
 * PageSpeed Insights). The admin UI renders findings generically, scores are
 * computed from them, and fixes are dispatched from the `fix` block — so a new
 * check only has to emit a well-formed finding to appear everywhere.
 *
 * Finding shape (see make() for defaults):
 *
 *   id        string  Check id, dot-namespaced: 'db.autoload_total'.
 *   key       string  Unique instance key: id plus a subject (e.g. the option
 *                     name or URL). Dismissals are stored by key.
 *   category  string  server|database|assets|content|storage.
 *   severity  string  critical|warning|notice|pass.
 *   title     string  Short, specific: "WooCommerce cart fragments load on 6 non-shop pages".
 *   summary   string  One or two sentences: what it is and why it costs speed or space.
 *   impact    array   { type: ms|bytes|count|score|none, value: number, label: string }.
 *   component array|null  Responsible component (BLT_Optimized_Components shape).
 *   where     string[]   URLs, table names, file paths, handles — the "where to look".
 *   details   array|null { columns: string[], rows: array[] } evidence table, rows
 *                        capped at MAX_ROWS; scalar cells only.
 *   fix       array|null { type: action|link|manual,
 *                          label: string,
 *                          action: string  (fix id for type=action, see BLT_Optimized_Fixes),
 *                          args: array     (arguments for the fix),
 *                          url: string     (for type=link),
 *                          steps: string   (for type=manual: plain-text instructions),
 *                          destructive: bool (deletes data — UI demands backup ack),
 *                          confirm: string (confirmation prompt text) }.
 *   source    string  health|profiler|media|psi|disk.
 */
class BLT_Optimized_Findings {

	const CATEGORIES = array( 'server', 'database', 'assets', 'content', 'storage' );
	const SEVERITIES = array( 'critical', 'warning', 'notice', 'pass' );

	/**
	 * Score deduction per severity (per category, floored at 0).
	 */
	const DEDUCTIONS = array(
		'critical' => 25,
		'warning'  => 10,
		'notice'   => 3,
		'pass'     => 0,
	);

	/**
	 * Category weights in the overall score — what most affects real load
	 * time weighs most.
	 */
	const WEIGHTS = array(
		'server'   => 0.30,
		'assets'   => 0.25,
		'content'  => 0.20,
		'database' => 0.15,
		'storage'  => 0.10,
	);

	/**
	 * Evidence rows kept per finding.
	 */
	const MAX_ROWS = 50;

	/**
	 * Option holding the latest compiled report. Never autoloaded.
	 */
	const REPORT_OPTION = 'blt_optimized_perf_report';

	/**
	 * Option holding dismissed finding keys. Never autoloaded.
	 */
	const DISMISSED_OPTION = 'blt_optimized_dismissed_findings';

	/**
	 * Build a well-formed finding from partial args.
	 *
	 * @param array $args Finding fields (id, category, severity, title required).
	 * @return array
	 */
	public static function make( array $args ) {
		$finding = wp_parse_args(
			$args,
			array(
				'id'        => 'unknown',
				'key'       => '',
				'category'  => 'server',
				'severity'  => 'notice',
				'title'     => '',
				'summary'   => '',
				'impact'    => array(),
				'component' => null,
				'where'     => array(),
				'details'   => null,
				'fix'       => null,
				'source'    => 'health',
			)
		);

		if ( ! in_array( $finding['category'], self::CATEGORIES, true ) ) {
			$finding['category'] = 'server';
		}
		if ( ! in_array( $finding['severity'], self::SEVERITIES, true ) ) {
			$finding['severity'] = 'notice';
		}
		if ( '' === $finding['key'] ) {
			$finding['key'] = $finding['id'];
		}

		$finding['impact'] = wp_parse_args(
			is_array( $finding['impact'] ) ? $finding['impact'] : array(),
			array(
				'type'  => 'none',
				'value' => 0,
				'label' => '',
			)
		);
		if ( '' === $finding['impact']['label'] ) {
			$finding['impact']['label'] = self::impact_label( $finding['impact']['type'], $finding['impact']['value'] );
		}

		$finding['where'] = array_values( array_map( 'strval', (array) $finding['where'] ) );

		if ( is_array( $finding['details'] ) ) {
			$finding['details'] = array(
				'columns' => array_values( array_map( 'strval', isset( $finding['details']['columns'] ) ? (array) $finding['details']['columns'] : array() ) ),
				'rows'    => array_slice( array_values( isset( $finding['details']['rows'] ) ? (array) $finding['details']['rows'] : array() ), 0, self::MAX_ROWS ),
			);
		}

		if ( is_array( $finding['fix'] ) ) {
			$finding['fix'] = wp_parse_args(
				$finding['fix'],
				array(
					'type'        => 'manual',
					'label'       => __( 'How to fix', 'blt-optimized' ),
					'action'      => '',
					'args'        => array(),
					'url'         => '',
					'steps'       => '',
					'destructive' => false,
					'confirm'     => '',
				)
			);
		}

		return $finding;
	}

	/**
	 * Human label for an impact value.
	 *
	 * @param string    $type  ms|bytes|count|score|none.
	 * @param int|float $value Value.
	 * @return string
	 */
	public static function impact_label( $type, $value ) {
		switch ( $type ) {
			case 'ms':
				return $value >= 1000
					/* translators: %s: seconds. */
					? sprintf( __( '~%s s', 'blt-optimized' ), number_format_i18n( $value / 1000, 1 ) )
					/* translators: %s: milliseconds. */
					: sprintf( __( '~%s ms', 'blt-optimized' ), number_format_i18n( $value ) );
			case 'bytes':
				return size_format( max( 0, (int) $value ), 1 );
			case 'count':
				return number_format_i18n( $value );
			case 'score':
				return number_format_i18n( $value ) . '/100';
		}
		return '';
	}

	/**
	 * Sort: severity first, then impact (normalised so ms and bytes compare
	 * sensibly: 100 ms ≈ 100 KB).
	 *
	 * @param array[] $findings Findings.
	 * @return array[]
	 */
	public static function sort( array $findings ) {
		$rank = array_flip( self::SEVERITIES );
		usort(
			$findings,
			static function ( $a, $b ) use ( $rank ) {
				$sa = isset( $rank[ $a['severity'] ] ) ? $rank[ $a['severity'] ] : 9;
				$sb = isset( $rank[ $b['severity'] ] ) ? $rank[ $b['severity'] ] : 9;
				if ( $sa !== $sb ) {
					return $sa - $sb;
				}
				return self::impact_weight( $b ) <=> self::impact_weight( $a );
			}
		);
		return $findings;
	}

	/**
	 * Comparable weight of a finding's impact.
	 *
	 * @param array $finding Finding.
	 * @return float
	 */
	private static function impact_weight( $finding ) {
		$impact = isset( $finding['impact'] ) ? $finding['impact'] : array();
		$value  = isset( $impact['value'] ) ? (float) $impact['value'] : 0.0;
		switch ( isset( $impact['type'] ) ? $impact['type'] : 'none' ) {
			case 'ms':
				return $value;
			case 'bytes':
				return $value / 1024;
			case 'count':
				return $value;
		}
		return 0.0;
	}

	/**
	 * Scores per category (0–100) and overall, ignoring dismissed findings.
	 *
	 * @param array[] $findings  Findings.
	 * @param array   $dismissed Dismissed keys.
	 * @return array { overall: int, categories: { category: { score, critical, warning, notice } } }
	 */
	public static function score( array $findings, array $dismissed = array() ) {
		$dismissed  = array_flip( $dismissed );
		$categories = array();
		foreach ( self::CATEGORIES as $category ) {
			$categories[ $category ] = array(
				'score'    => 100,
				'critical' => 0,
				'warning'  => 0,
				'notice'   => 0,
			);
		}

		foreach ( $findings as $finding ) {
			if ( isset( $dismissed[ $finding['key'] ] ) || 'pass' === $finding['severity'] ) {
				continue;
			}
			$category = $finding['category'];
			if ( ! isset( $categories[ $category ] ) ) {
				continue;
			}
			++$categories[ $category ][ $finding['severity'] ];
			$categories[ $category ]['score'] -= self::DEDUCTIONS[ $finding['severity'] ];
		}

		$overall = 0.0;
		foreach ( $categories as $category => &$data ) {
			$data['score'] = max( 0, $data['score'] );
			$overall      += $data['score'] * self::WEIGHTS[ $category ];
		}
		unset( $data );

		return array(
			'overall'    => (int) round( $overall ),
			'categories' => $categories,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Persistence                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Latest compiled report, or null.
	 *
	 * @return array|null
	 */
	public static function get_report() {
		$report = get_option( self::REPORT_OPTION, null );
		return is_array( $report ) ? $report : null;
	}

	/**
	 * Store the compiled report (never autoloaded — it can be large).
	 *
	 * @param array $report Report.
	 * @return void
	 */
	public static function save_report( array $report ) {
		update_option( self::REPORT_OPTION, $report, false );
	}

	/**
	 * Dismissed finding keys.
	 *
	 * @return string[]
	 */
	public static function get_dismissed() {
		$keys = get_option( self::DISMISSED_OPTION, array() );
		return is_array( $keys ) ? array_values( array_map( 'strval', $keys ) ) : array();
	}

	/**
	 * Dismiss or restore a finding.
	 *
	 * @param string $key       Finding key.
	 * @param bool   $dismissed True to dismiss, false to restore.
	 * @return void
	 */
	public static function set_dismissed( $key, $dismissed = true ) {
		$keys = self::get_dismissed();
		$key  = (string) $key;
		if ( $dismissed ) {
			$keys[] = $key;
		} else {
			$keys = array_diff( $keys, array( $key ) );
		}
		update_option( self::DISMISSED_OPTION, array_values( array_unique( $keys ) ), false );
	}
}
