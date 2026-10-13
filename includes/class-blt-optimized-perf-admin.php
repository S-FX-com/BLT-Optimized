<?php
/**
 * Admin surface for the performance scan: Performance Scan, Page Profiler
 * and Speed Tweaks screens, their AJAX endpoints and the findings export.
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;

/**
 * Kept apart from BLT_Optimized_Admin (disk/DB screens) so each stays a
 * readable size. Shares its capability, nonce and backup-acknowledgement
 * gate: every endpoint checks manage_options + nonce, and every fix that
 * deletes data requires the same once-per-session backup acknowledgement as
 * the database cleanup screen.
 */
class BLT_Optimized_Perf_Admin {

	/**
	 * Plugin core.
	 *
	 * @var BLT_Optimized
	 */
	private $plugin;

	/**
	 * Main admin (capability/nonce/backup-ack helpers).
	 *
	 * @var BLT_Optimized_Admin
	 */
	private $admin;

	/**
	 * Constructor.
	 *
	 * @param BLT_Optimized       $plugin Plugin core.
	 * @param BLT_Optimized_Admin $admin  Main admin.
	 */
	public function __construct( BLT_Optimized $plugin, BLT_Optimized_Admin $admin ) {
		$this->plugin = $plugin;
		$this->admin  = $admin;

		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		$ajax = array(
			'perf_start'    => 'ajax_start',
			'perf_tick'     => 'ajax_tick',
			'perf_status'   => 'ajax_status',
			'perf_cancel'   => 'ajax_cancel',
			'perf_report'   => 'ajax_report',
			'perf_fix'      => 'ajax_fix',
			'perf_dismiss'  => 'ajax_dismiss',
			'perf_profile'  => 'ajax_profile',
			'perf_pages'    => 'ajax_pages',
			'perf_page'     => 'ajax_page',
			'perf_psi'      => 'ajax_psi',
			'perf_lab_token' => 'ajax_lab_token',
			'perf_lab_store' => 'ajax_lab_store',
			'perf_lab_done'  => 'ajax_lab_done',
			'rule_add'      => 'ajax_rule_add',
			'rule_toggle'   => 'ajax_rule_toggle',
			'rule_delete'   => 'ajax_rule_delete',
			'tweaks_status' => 'ajax_tweaks_status',
		);
		foreach ( $ajax as $action => $method ) {
			add_action( 'wp_ajax_blt_optimized_' . $action, array( $this, $method ) );
		}

		add_action( 'admin_post_blt_optimized_export_perf_csv', array( $this, 'export_csv' ) );
	}

	/**
	 * The three screens this class renders.
	 *
	 * @return string[]
	 */
	public static function page_slugs() {
		return array( 'blt-optimized', 'blt-optimized-profiler', 'blt-optimized-tweaks' );
	}

	/* ------------------------------------------------------------------ */
	/* Settings + assets                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Register the Speed Tweaks option for the options.php form.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'blt_optimized_tweaks_group',
			BLT_Optimized_Tweaks::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'BLT_Optimized_Tweaks', 'sanitize' ),
			)
		);
	}

	/**
	 * Enqueue the performance UI on its three screens only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
		if ( false === strpos( $hook_suffix, 'blt-optimized' ) || ! in_array( $page, self::page_slugs(), true ) ) {
			return;
		}

		wp_enqueue_style( 'blt-optimized-performance', BLT_OPTIMIZED_URL . 'assets/performance.css', array( 'blt-optimized-admin' ), BLT_OPTIMIZED_VERSION );
		wp_enqueue_script( 'blt-optimized-performance', BLT_OPTIMIZED_URL . 'assets/performance.js', array( 'jquery', 'blt-optimized-admin' ), BLT_OPTIMIZED_VERSION, true );

		wp_localize_script(
			'blt-optimized-performance',
			'bltPerf',
			array(
				'page'        => $page,
				'backupAcked' => $this->admin->backup_acked(),
				'profilerUrl' => admin_url( 'admin.php?page=blt-optimized-profiler' ),
				'tweaksUrl'   => admin_url( 'admin.php?page=blt-optimized-tweaks' ),
				'homeUrl'     => home_url( '/' ),
				'deferable'   => version_compare( get_bloginfo( 'version' ), '6.3', '>=' ),
				'i18n'        => array(
					'confirmFix'      => __( 'Apply this fix?', 'blt-optimized' ),
					'confirmDelete'   => __( 'This permanently deletes data. Continue?', 'blt-optimized' ),
					'applied'         => __( 'Applied — run the scan again to confirm the improvement.', 'blt-optimized' ),
					'dismissed'       => __( 'Dismissed', 'blt-optimized' ),
					'noReport'        => __( 'Click “Run full scan” to analyse this site. A scan usually takes a minute or two.', 'blt-optimized' ),
					'profiling'       => __( 'Loading the page and collecting measurements…', 'blt-optimized' ),
					'psiRunning'      => __( 'Asking Google PageSpeed Insights (this can take up to a minute)…', 'blt-optimized' ),
					'error'           => __( 'Request failed. Please try again.', 'blt-optimized' ),
					'scanComplete'    => __( 'Scan complete.', 'blt-optimized' ),
					'scanCancelled'   => __( 'Scan cancelled.', 'blt-optimized' ),
					'confirmRuleDel'  => __( 'Delete this asset rule?', 'blt-optimized' ),
					'safeModeHint'    => __( 'Open the site with every speed tweak and asset rule switched off (only for you).', 'blt-optimized' ),
						// Shared.
						'scanning'                 => __( 'Scanning…', 'blt-optimized' ),
						/* translators: %d: score. */
						'scoreLabel'               => __( 'Score %d out of 100', 'blt-optimized' ),
						'sev_critical'             => __( 'Critical', 'blt-optimized' ),
						'sev_warning'              => __( 'Warning', 'blt-optimized' ),
						'sev_notice'               => __( 'Notice', 'blt-optimized' ),
						'sev_pass'                 => __( 'Pass', 'blt-optimized' ),
						'cat_server'               => __( 'Server & PHP', 'blt-optimized' ),
						'cat_database'             => __( 'Database', 'blt-optimized' ),
						'cat_assets'               => __( 'Scripts & styles', 'blt-optimized' ),
						'cat_content'              => __( 'Content', 'blt-optimized' ),
						'cat_storage'              => __( 'Storage', 'blt-optimized' ),
						'compPlugin'               => __( 'plugin', 'blt-optimized' ),
						'compMuPlugin'             => __( 'must-use plugin', 'blt-optimized' ),
						'compTheme'                => __( 'theme', 'blt-optimized' ),
						'compDropin'               => __( 'drop-in', 'blt-optimized' ),
						'compExternal'             => __( 'third party', 'blt-optimized' ),
						'yes'                      => __( 'Yes', 'blt-optimized' ),
						'no'                       => __( 'No', 'blt-optimized' ),
						'on'                       => __( 'On', 'blt-optimized' ),
						'off'                      => __( 'Off', 'blt-optimized' ),
						'delete'                   => __( 'Delete', 'blt-optimized' ),
						// Findings.
						'dismiss'                  => __( 'Dismiss', 'blt-optimized' ),
						'restore'                  => __( 'Restore', 'blt-optimized' ),
						'dismissHint'              => __( 'Dismissed findings are left out of your scores.', 'blt-optimized' ),
						/* translators: %d: number of hidden findings. */
						'dismissedHidden'          => __( 'Dismissed findings hidden: %d', 'blt-optimized' ),
						/* translators: %d: number of dismissed findings. */
						'showDismissed'            => __( 'Show dismissed (%d)', 'blt-optimized' ),
						'where'                    => __( 'Where', 'blt-optimized' ),
						'evidence'                 => __( 'Evidence', 'blt-optimized' ),
						'fix'                      => __( 'Fix', 'blt-optimized' ),
						'applyFix'                 => __( 'Apply fix', 'blt-optimized' ),
						'applying'                 => __( 'Applying…', 'blt-optimized' ),
						'openFix'                  => __( 'Open', 'blt-optimized' ),
						'howToFix'                 => __( 'How to fix', 'blt-optimized' ),
						'deletesData'              => __( 'Deletes data', 'blt-optimized' ),
						'fixedRescan'              => __( 'Fixed — re-scan to verify', 'blt-optimized' ),
						'fixedReprofile'           => __( 'Fixed — profile again to verify', 'blt-optimized' ),
						/* translators: %d: number of hidden entries. */
						'showMore'                 => __( 'Show %d more', 'blt-optimized' ),
						'noIssues'                 => __( 'No issues found', 'blt-optimized' ),
						'noIssuesHint'             => __( 'Nothing to fix here — every check that ran passed.', 'blt-optimized' ),
						'noMatches'                => __( 'No findings match these filters', 'blt-optimized' ),
						/* translators: %d: number of passed checks. */
						'passedChecks'             => __( 'Passed checks (%d)', 'blt-optimized' ),
						// PageSpeed Insights.
						'psiTitle'                 => __( 'PageSpeed Insights', 'blt-optimized' ),
						'psiHint'                  => __( 'Lab data from Google Lighthouse, measured on the live site.', 'blt-optimized' ),
						'psiFindings'              => __( 'PageSpeed findings', 'blt-optimized' ),
						'psiGood'                  => __( 'Good', 'blt-optimized' ),
						'psiMid'                   => __( 'Needs improvement', 'blt-optimized' ),
						'psiPoor'                  => __( 'Poor', 'blt-optimized' ),
						'strategyMobile'           => __( 'Mobile', 'blt-optimized' ),
						'strategyDesktop'          => __( 'Desktop', 'blt-optimized' ),
						'lcpElement'               => __( 'LCP element', 'blt-optimized' ),
						'fieldData'                => __( 'Real-user data', 'blt-optimized' ),
						'metric_lcp'               => __( 'Largest Contentful Paint', 'blt-optimized' ),
						'metric_cls'               => __( 'Cumulative Layout Shift', 'blt-optimized' ),
						'metric_tbt'               => __( 'Total Blocking Time', 'blt-optimized' ),
						'metric_fcp'               => __( 'First Contentful Paint', 'blt-optimized' ),
						'metric_si'                => __( 'Speed Index', 'blt-optimized' ),
						'metric_ttfb'              => __( 'Time to First Byte', 'blt-optimized' ),
						'metric_inp'               => __( 'Interaction to Next Paint', 'blt-optimized' ),
						// Performance Scan.
						'scanStalled'              => __( 'The scan was interrupted.', 'blt-optimized' ),
						/* translators: %d: seconds. */
						'retrying'                 => __( 'Connection problem — retrying in %d s…', 'blt-optimized' ),
						'scanErrors'               => __( 'Some checks could not finish', 'blt-optimized' ),
						'step_health'              => __( 'Server & database', 'blt-optimized' ),
						'step_media'               => __( 'Media library', 'blt-optimized' ),
						'step_pages'               => __( 'Pages', 'blt-optimized' ),
						'step_psi'                 => __( 'PageSpeed', 'blt-optimized' ),
						'step_compile'             => __( 'Report', 'blt-optimized' ),
						'noReportTitle'            => __( 'No performance scan yet', 'blt-optimized' ),
						'runScan'                  => __( 'Run full scan', 'blt-optimized' ),
						/* translators: %s: human time difference, e.g. "5 minutes ago". */
						'lastScan'                 => __( 'Last scan %s', 'blt-optimized' ),
						/* translators: %d: seconds. */
						'tookSeconds'              => __( 'took %d s', 'blt-optimized' ),
						/* translators: %s: number of pages. */
						'pageProfiledOne'          => __( '%s page profiled', 'blt-optimized' ),
						/* translators: %s: number of pages. */
						'pageProfiledMany'         => __( '%s pages profiled', 'blt-optimized' ),
						'overall'                  => __( 'Overall score', 'blt-optimized' ),
						'filterCategory'           => __( 'Filter by category', 'blt-optimized' ),
						'filterSeverity'           => __( 'Filter by severity', 'blt-optimized' ),
						'allCategories'            => __( 'All', 'blt-optimized' ),
						'allSeverities'            => __( 'All', 'blt-optimized' ),
						'noIssuesShort'            => __( 'No issues', 'blt-optimized' ),
						'topFixes'                 => __( 'Top fixes', 'blt-optimized' ),
						'topFixesHint'             => __( 'Sorted by severity, then by how much each costs you. Click a row for the evidence and the fix.', 'blt-optimized' ),
						'whoSlow'                  => __( 'Who’s slowing the site down', 'blt-optimized' ),
						'whoSlowHint'              => __( 'Average per page: time spent loading each plugin or theme, running its database queries and waiting on its outbound HTTP calls. JS and CSS show the largest weight seen on any scanned page.', 'blt-optimized' ),
						'noComponents'             => __( 'No component data', 'blt-optimized' ),
						'noComponentsHint'         => __( 'Component timings need at least one page profiled with the PHP profile received.', 'blt-optimized' ),
						'pagesScanned'             => __( 'Pages scanned', 'blt-optimized' ),
						'pagesScannedHint'         => __( 'Each page was loaded the way a visitor sees it and profiled on the server. Open one in the Page Profiler for the full breakdown.', 'blt-optimized' ),
						'noPages'                  => __( 'No pages were profiled.', 'blt-optimized' ),
						'errPageCache'             => __( 'Served from page cache', 'blt-optimized' ),
						'errNotReceived'           => __( 'Profile not received', 'blt-optimized' ),
						'errHttp'                  => __( 'HTTP error', 'blt-optimized' ),
						'noResponse'               => __( 'No response', 'blt-optimized' ),
						'profile'                  => __( 'Profile', 'blt-optimized' ),
						// Shared column labels.
						'colComponent'             => __( 'Component', 'blt-optimized' ),
						'colTotalTime'             => __( 'Total time', 'blt-optimized' ),
						'colPluginLoad'            => __( 'Load', 'blt-optimized' ),
						'colQueries'               => __( 'Queries', 'blt-optimized' ),
						'colHttp'                  => __( 'HTTP', 'blt-optimized' ),
						'colErrors'                => __( 'Errors', 'blt-optimized' ),
						'colPages'                 => __( 'Pages', 'blt-optimized' ),
						'colPage'                  => __( 'Page', 'blt-optimized' ),
						'colStatus'                => __( 'Status', 'blt-optimized' ),
						'colPhp'                   => __( 'PHP', 'blt-optimized' ),
						'colImages'                => __( 'Images', 'blt-optimized' ),
						'colDom'                   => __( 'DOM nodes', 'blt-optimized' ),
						'colRequests'              => __( 'Requests', 'blt-optimized' ),
						'colTime'                  => __( 'Time', 'blt-optimized' ),
						'colCaller'                => __( 'Caller', 'blt-optimized' ),
						'colSql'                   => __( 'SQL', 'blt-optimized' ),
						'colRepeats'               => __( 'Repeats', 'blt-optimized' ),
						'colName'                  => __( 'Name', 'blt-optimized' ),
						'colExpiry'                => __( 'Expires', 'blt-optimized' ),
						'colHandle'                => __( 'Handle', 'blt-optimized' ),
						'colLocation'              => __( 'Location', 'blt-optimized' ),
						'colStrategy'              => __( 'Loading', 'blt-optimized' ),
						'colSize'                  => __( 'Size', 'blt-optimized' ),
						'colInline'                => __( 'Inline', 'blt-optimized' ),
						'colMedia'                 => __( 'Media', 'blt-optimized' ),
						'colFile'                  => __( 'File', 'blt-optimized' ),
						'colHost'                  => __( 'Host', 'blt-optimized' ),
						'colMethod'                => __( 'Method', 'blt-optimized' ),
						'colLevel'                 => __( 'Level', 'blt-optimized' ),
						'colMessage'               => __( 'Message', 'blt-optimized' ),
						'colImage'                 => __( 'Image', 'blt-optimized' ),
						'colNatural'               => __( 'Natural size', 'blt-optimized' ),
						'colDisplayed'             => __( 'Displayed', 'blt-optimized' ),
						'colFormat'                => __( 'Format', 'blt-optimized' ),
						'colLoading'               => __( 'Lazy-load', 'blt-optimized' ),
						'colSrcset'                => __( 'Srcset', 'blt-optimized' ),
						'colInContent'             => __( 'In content', 'blt-optimized' ),
						'colKind'                  => __( 'Kind', 'blt-optimized' ),
						'colKnownAs'               => __( 'Known as', 'blt-optimized' ),
						'colKinds'                 => __( 'Used for', 'blt-optimized' ),
						'colType'                  => __( 'Type', 'blt-optimized' ),
						'colValue'                 => __( 'Value', 'blt-optimized' ),
						'colAssets'                => __( 'Assets', 'blt-optimized' ),
						'colMetric'                => __( 'Metric', 'blt-optimized' ),
						'colTweaksOn'              => __( 'Tweaks on', 'blt-optimized' ),
						'colTweaksOff'             => __( 'Tweaks off', 'blt-optimized' ),
						'colChange'                => __( 'Change', 'blt-optimized' ),
						'colRule'                  => __( 'Rule', 'blt-optimized' ),
						'colNote'                  => __( 'Note', 'blt-optimized' ),
						'colCreated'               => __( 'Added', 'blt-optimized' ),
						'colEnabled'               => __( 'Enabled', 'blt-optimized' ),
						'colOption'                => __( 'Option', 'blt-optimized' ),
						'colChanged'               => __( 'Changed', 'blt-optimized' ),
						'colActions'               => __( 'Actions', 'blt-optimized' ),
						'colFlags'                 => __( 'Flags', 'blt-optimized' ),
						/* translators: %s: number of database queries. */
						'queryOne'                 => __( '%s query', 'blt-optimized' ),
						/* translators: %s: number of database queries. */
						'queryMany'                => __( '%s queries', 'blt-optimized' ),
						/* translators: %s: number of HTTP calls. */
						'callOne'                  => __( '%s call', 'blt-optimized' ),
						/* translators: %s: number of HTTP calls. */
						'callMany'                 => __( '%s calls', 'blt-optimized' ),
						/* translators: %s: number of files. */
						'fileOne'                  => __( '%s file', 'blt-optimized' ),
						/* translators: %s: number of files. */
						'fileMany'                 => __( '%s files', 'blt-optimized' ),
						/* translators: %s: number of images. */
						'imageOne'                 => __( '%s image', 'blt-optimized' ),
						/* translators: %s: number of images. */
						'imageMany'                => __( '%s images', 'blt-optimized' ),
						/* translators: %s: number of inline blocks. */
						'blockOne'                 => __( '%s block', 'blt-optimized' ),
						/* translators: %s: number of inline blocks. */
						'blockMany'                => __( '%s blocks', 'blt-optimized' ),
						/* translators: %s: size, e.g. "12 KB". */
						'inlineBytes'              => __( '%s inline', 'blt-optimized' ),
						// Page Profiler.
						'profilerEmptyTitle'       => __( 'Profile a page', 'blt-optimized' ),
						'profilerEmpty'            => __( 'Enter a URL on this site and click “Profile page” to see where the time goes.', 'blt-optimized' ),
						'pickPage'                 => __( 'Choose a page…', 'blt-optimized' ),
						'groupProfiled'            => __( 'Already profiled', 'blt-optimized' ),
						'groupSamples'             => __( 'Sample pages', 'blt-optimized' ),
						'loadingStored'            => __( 'Loading the stored report…', 'blt-optimized' ),
						'enterUrl'                 => __( 'Enter a URL on this site.', 'blt-optimized' ),
						'profilingCompare'         => __( 'Profiling the page twice — with and without your speed tweaks…', 'blt-optimized' ),
						'compareFailed'            => __( 'The comparison run without speed tweaks failed, so there is nothing to compare against.', 'blt-optimized' ),
						'pageFindings'             => __( 'Findings for this page', 'blt-optimized' ),
						'pageFindingsHint'         => __( 'Everything the analysis found on this URL. Expand a row for the evidence and the fix.', 'blt-optimized' ),
						/* translators: %s: template file. */
						'templateIs'               => __( 'Template: %s', 'blt-optimized' ),
						/* translators: %s: date and time. */
						'profiledAt'               => __( 'Profiled %s', 'blt-optimized' ),
						'tweaksOff'                => __( 'Speed tweaks off', 'blt-optimized' ),
						'editPost'                 => __( 'Edit post', 'blt-optimized' ),
						'profileUnavailable'       => __( 'PHP profile unavailable', 'blt-optimized' ),
						'profileErrCache'          => __( 'This page was served from a page cache, so WordPress never ran for the profiled request. Server-side tabs (queries, plugins, timings) are empty. Purge or bypass the cache for this URL and profile it again. The HTML analysis is still valid.', 'blt-optimized' ),
						'profileErrNotReceived'    => __( 'The page loaded, but the profiler’s data never came back. A caching layer, a security plugin or a redirect can block it. The HTML analysis is still valid.', 'blt-optimized' ),
						'profileErrHttp'           => __( 'The page returned an HTTP error, so no PHP profile was collected.', 'blt-optimized' ),
						'profileErrUnknown'        => __( 'No PHP profile was collected for this page.', 'blt-optimized' ),
						'requestFailed'            => __( 'The request failed', 'blt-optimized' ),
						'pageCacheOn'              => __( 'A page cache is serving this page', 'blt-optimized' ),
						/* translators: %s: header or comment that revealed the cache. */
						'pageCacheEvidence'        => __( 'Detected from: %s.', 'blt-optimized' ),
						/* translators: %s: time. */
						'pageCacheTtfb'            => __( 'Cached response: %s to first byte.', 'blt-optimized' ),
						'tileTtfb'                 => __( 'Server response', 'blt-optimized' ),
						'tileTtfbSub'              => __( 'time to first byte', 'blt-optimized' ),
						/* translators: %s: time. */
						'cachedTtfb'               => __( 'cached copy: %s', 'blt-optimized' ),
						'tilePhp'                  => __( 'PHP time', 'blt-optimized' ),
						/* translators: %s: time. */
						'bootstrapSub'             => __( 'boot %s', 'blt-optimized' ),
						'tileQueries'              => __( 'DB queries', 'blt-optimized' ),
						/* translators: %s: time. */
						'queryTimeSub'             => __( '%s in the database', 'blt-optimized' ),
						'tileMemory'               => __( 'Peak memory', 'blt-optimized' ),
						/* translators: %s: memory limit. */
						'ofLimit'                  => __( 'of %s', 'blt-optimized' ),
						'tileHtml'                 => __( 'HTML', 'blt-optimized' ),
						/* translators: %s: encoding, e.g. gzip. */
						'encodingSub'              => __( 'compressed (%s)', 'blt-optimized' ),
						'notCompressed'            => __( 'not compressed', 'blt-optimized' ),
						'tileJs'                   => __( 'JavaScript', 'blt-optimized' ),
						'tileCss'                  => __( 'CSS', 'blt-optimized' ),
						'tileImages'               => __( 'Images', 'blt-optimized' ),
						'tileDom'                  => __( 'DOM nodes', 'blt-optimized' ),
						/* translators: %d: nesting depth. */
						'depthSub'                 => __( 'depth %d', 'blt-optimized' ),
						'tileRequests'             => __( 'Requests', 'blt-optimized' ),
						'requestsSub'              => __( 'scripts, styles, images, frames', 'blt-optimized' ),
						'abTitle'                  => __( 'Speed tweaks: before and after', 'blt-optimized' ),
						'abHint'                   => __( 'The page was loaded twice: as visitors see it now, and with every speed tweak and asset rule switched off. Lower is better; a single run varies by a few percent.', 'blt-optimized' ),
						'noChange'                 => __( 'no change', 'blt-optimized' ),
						'detailsLabel'             => __( 'Page details', 'blt-optimized' ),
						'tab_components'           => __( 'Components', 'blt-optimized' ),
						'tab_queries'              => __( 'Queries', 'blt-optimized' ),
						'tab_assets'               => __( 'Assets', 'blt-optimized' ),
						'tab_http'                 => __( 'HTTP calls', 'blt-optimized' ),
						'tab_errors'               => __( 'PHP errors', 'blt-optimized' ),
						'tab_content'              => __( 'Content', 'blt-optimized' ),
						'tab_timeline'             => __( 'Timeline', 'blt-optimized' ),
						'tab_raw'                  => __( 'Raw', 'blt-optimized' ),
						'componentsHint'           => __( 'What each plugin and theme costs on this page: load, database and outbound HTTP time are added together.', 'blt-optimized' ),
						'queriesUntracked'         => __( 'Query timing is unavailable', 'blt-optimized' ),
						'queriesUntrackedHint'     => __( 'SAVEQUERIES is switched off in wp-config.php, so only query counts were collected.', 'blt-optimized' ),
						'queriesByComponent'       => __( 'By component', 'blt-optimized' ),
						'queriesSlowest'           => __( 'Slowest queries', 'blt-optimized' ),
						'queriesDuplicates'        => __( 'Duplicate queries', 'blt-optimized' ),
						'queriesDuplicatesHint'    => __( 'The same SQL ran more than once on this page — a candidate for caching.', 'blt-optimized' ),
						'transientsSet'            => __( 'Transients written', 'blt-optimized' ),
						'transientsSetHint'        => __( 'Writing a transient is a database write during a page view; on a cached or busy site that is wasted work.', 'blt-optimized' ),
						/* translators: %d: seconds. */
						'expiresIn'                => __( 'in %d s', 'blt-optimized' ),
						'noExpiry'                 => __( 'never', 'blt-optimized' ),
						'renderBlockingScripts'    => __( 'Render-blocking scripts', 'blt-optimized' ),
						'renderBlockingHint'       => __( 'in the head, not deferred', 'blt-optimized' ),
						'noneBlocking'             => __( 'None', 'blt-optimized' ),
						'renderBlocking'           => __( 'Render-blocking', 'blt-optimized' ),
						'external'                 => __( 'External', 'blt-optimized' ),
						'footer'                   => __( 'Footer', 'blt-optimized' ),
						'head'                     => __( 'Head', 'blt-optimized' ),
						'inlineOnly'               => __( 'Inline only', 'blt-optimized' ),
						'scripts'                  => __( 'Scripts', 'blt-optimized' ),
						'styles'                   => __( 'Stylesheets', 'blt-optimized' ),
						'stylesHint'               => __( 'Stylesheets block rendering until they download, except those limited to print.', 'blt-optimized' ),
						'noScripts'                => __( 'No scripts were loaded.', 'blt-optimized' ),
						'noStyles'                 => __( 'No stylesheets were loaded.', 'blt-optimized' ),
						'assetsFromHtml'           => __( 'Showing what the page’s HTML references instead.', 'blt-optimized' ),
						'noHttp'                   => __( 'No outbound HTTP calls', 'blt-optimized' ),
						'noHttpHint'               => __( 'This page did not wait on any remote server while rendering.', 'blt-optimized' ),
						/* translators: 1: number of calls, 2: total time. */
						'httpSummary'              => __( '%1$d calls took %2$s in total.', 'blt-optimized' ),
						'httpHint'                 => __( 'Each call holds up the page until the remote server answers.', 'blt-optimized' ),
						'failed'                   => __( 'Failed', 'blt-optimized' ),
						'noErrors'                 => __( 'No PHP errors', 'blt-optimized' ),
						'noErrorsHint'             => __( 'No warnings, notices or deprecations were raised while the page rendered.', 'blt-optimized' ),
						'errorsHint'               => __( 'PHP messages raised while the page rendered, grouped by file and line.', 'blt-optimized' ),
						'level_warning'            => __( 'Warning', 'blt-optimized' ),
						'level_notice'             => __( 'Notice', 'blt-optimized' ),
						'level_deprecated'         => __( 'Deprecated', 'blt-optimized' ),
						'level_strict'             => __( 'Strict', 'blt-optimized' ),
						'level_other'              => __( 'Error', 'blt-optimized' ),
						'images'                   => __( 'Images', 'blt-optimized' ),
						'warnNoDims'               => __( 'No width/height', 'blt-optimized' ),
						'warnOversized'            => __( 'Oversized', 'blt-optimized' ),
						'warnLazyHero'             => __( 'Lazy-loaded hero', 'blt-optimized' ),
						'warnHeavy'                => __( 'Heavy file', 'blt-optimized' ),
						'noImages'                 => __( 'No images on this page.', 'blt-optimized' ),
						'iframes'                  => __( 'Embedded frames', 'blt-optimized' ),
						'thirdParty'               => __( 'Third-party hosts', 'blt-optimized' ),
						'noThirdParty'             => __( 'This page loads nothing from third-party hosts.', 'blt-optimized' ),
						'hintPreconnect'           => __( 'preconnect', 'blt-optimized' ),
						'hintDns'                  => __( 'dns-prefetch', 'blt-optimized' ),
						'hintPreload'              => __( 'preload', 'blt-optimized' ),
						'resourceHints'            => __( 'Resource hints', 'blt-optimized' ),
						'noHints'                  => __( 'No resource hints on this page.', 'blt-optimized' ),
						'googleFonts'              => __( 'Google Fonts are loaded from fonts.googleapis.com.', 'blt-optimized' ),
						'inlineCode'               => __( 'Inline code', 'blt-optimized' ),
						'inlineScripts'            => __( 'Inline JavaScript', 'blt-optimized' ),
						'inlineStyles'             => __( 'Inline CSS', 'blt-optimized' ),
						/* translators: %s: element id. */
						'largestIs'                => __( 'largest: %s', 'blt-optimized' ),
						'timelineHint'             => __( 'Where the server spent its time, from the first line of PHP to the last byte of HTML.', 'blt-optimized' ),
						/* translators: %s: time. */
						'timelineLabel'            => __( 'Server timeline, %s in total', 'blt-optimized' ),
						'phase_bootstrap'          => __( 'Core boot', 'blt-optimized' ),
						'phase_plugins_loaded'     => __( 'Plugins load', 'blt-optimized' ),
						'phase_wp_loaded'          => __( 'WordPress init', 'blt-optimized' ),
						'phase_template_redirect'  => __( 'Query & routing', 'blt-optimized' ),
						'phase_render'             => __( 'Template render', 'blt-optimized' ),
						'environment'              => __( 'Environment', 'blt-optimized' ),
						'factTotal'                => __( 'Total server time', 'blt-optimized' ),
						'factPhp'                  => __( 'PHP', 'blt-optimized' ),
						'factWp'                   => __( 'WordPress', 'blt-optimized' ),
						'factOpcache'              => __( 'OPcache', 'blt-optimized' ),
						'factObjectCache'          => __( 'Persistent object cache', 'blt-optimized' ),
						'factTemplate'             => __( 'Template', 'blt-optimized' ),
						'factType'                 => __( 'Page type', 'blt-optimized' ),
						'factPeakMemory'           => __( 'Peak memory', 'blt-optimized' ),
						'factCache'                => __( 'Object cache', 'blt-optimized' ),
						/* translators: 1: cache hits, 2: cache misses. */
						'cacheHitsMisses'          => __( '%1$s hits, %2$s misses', 'blt-optimized' ),
						'rawHint'                  => __( 'The complete page report as collected, for bug reports or your own tooling.', 'blt-optimized' ),
						'downloadJson'             => __( 'Download JSON', 'blt-optimized' ),
						'rawTruncated'             => __( 'Preview truncated — download the file for everything.', 'blt-optimized' ),
						// Speed Tweaks.
						'modeEverywhereShort'      => __( 'Removed on every page', 'blt-optimized' ),
						'modeExceptShort'          => __( 'Removed everywhere except:', 'blt-optimized' ),
						'noRules'                  => __( 'No asset rules yet', 'blt-optimized' ),
						'noRulesHint'              => __( 'Add a rule below to stop a script or stylesheet loading where it is not needed.', 'blt-optimized' ),
						'typeScript'               => __( 'Script', 'blt-optimized' ),
						'typeStyle'                => __( 'Style', 'blt-optimized' ),
						/* translators: %s: script or style handle. */
						'ruleEnabledFor'           => __( 'Rule for %s is enabled', 'blt-optimized' ),
						'ruleBadHandle'            => __( 'Enter a valid handle: letters, numbers, dashes, underscores, dots and slashes only.', 'blt-optimized' ),
						'ruleNeedsValue'           => __( 'Fill in a value for every condition.', 'blt-optimized' ),
						'ruleNeedsCondition'       => __( 'Add at least one condition, or choose “Remove on every page”.', 'blt-optimized' ),
						'ruleAdded'                => __( 'Rule added.', 'blt-optimized' ),
						'noChanges'                => __( 'No autoload changes to undo', 'blt-optimized' ),
						'noChangesHint'            => __( 'When you stop an option autoloading from a Performance Scan fix, it is listed here so you can undo it.', 'blt-optimized' ),
						'restoreAutoload'          => __( 'Restore autoload', 'blt-optimized' ),
						'confirmRestore'           => __( 'Make this option load on every request again?', 'blt-optimized' ),
						// Lab test (built-in, measured in this browser).
						'step_lab'                 => __( 'Lab test', 'blt-optimized' ),
						'tab_lab'                  => __( 'Lab (Core Web Vitals)', 'blt-optimized' ),
						'labResultsTitle'          => __( 'Lab results (measured in your browser)', 'blt-optimized' ),
						/* translators: %s: "N pages". */
						'labResultsHint'           => __( 'Median of %s on a mobile viewport, measured in this browser without throttling. Open a page in the Page Profiler for the full breakdown.', 'blt-optimized' ),
						/* translators: %s: number of pages. */
						'labPageOne'               => __( '%s page', 'blt-optimized' ),
						/* translators: %s: number of pages. */
						'labPageMany'              => __( '%s pages', 'blt-optimized' ),
						'labTableNote'             => __( 'LCP, CLS and TBT were measured in this browser on a mobile viewport, without throttling.', 'blt-optimized' ),
						'labColMobile'             => __( 'Mobile viewport', 'blt-optimized' ),
						'labBlockedShort'          => __( 'Blocked', 'blt-optimized' ),
						'labCaveat'                => __( 'Measured in your own browser with no CPU or network throttling, so numbers are usually better than PageSpeed Insights’ simulated mid-range phone on 4G. Interaction to Next Paint needs real clicks, so Total Blocking Time stands in for it. Unused JavaScript is not measured; main-thread time per script is shown instead. Images may come from your browser cache on repeat runs.', 'blt-optimized' ),
						// Lab test: running it.
						/* translators: %s: device name (Mobile or Desktop). */
						'labMeasuring'             => __( 'Measuring %s', 'blt-optimized' ),
						/* translators: 1: current step, 2: number of steps. */
						'labStepOf'                => __( '(%1$d of %2$d)', 'blt-optimized' ),
						/* translators: 1: page number, 2: number of pages. */
						'labScanPage'              => __( 'Measuring page %1$d of %2$d in your browser…', 'blt-optimized' ),
						/* translators: 1: page, 2: error message. */
						'labScanSkipped'           => __( 'The lab test was skipped for %1$s: %2$s', 'blt-optimized' ),
						/* translators: %s: error message. */
						'labFailed'                => __( 'The lab test failed: %s', 'blt-optimized' ),
						/* translators: 1: frame width, 2: frame height. */
						'labFrameNote'             => __( 'The page runs live in a %1$d × %2$d px frame, scaled down to fit. Keep this tab in front and the frame in view until it finishes.', 'blt-optimized' ),
						/* translators: %s: page URL. */
						'labFrameTitle'            => __( 'Lab test of %s', 'blt-optimized' ),
						'labPhase_token'           => __( 'Preparing the test…', 'blt-optimized' ),
						'labPhase_loading'         => __( 'Loading the page…', 'blt-optimized' ),
						'labPhase_settling'        => __( 'Waiting for the page to settle…', 'blt-optimized' ),
						'labPhase_collecting'      => __( 'Collecting measurements…', 'blt-optimized' ),
						'labPhase_saving'          => __( 'Saving the result…', 'blt-optimized' ),
						'labPhase_hidden'          => __( 'Paused: bring this browser tab back to the front to continue.', 'blt-optimized' ),
						'labPhase_retry'           => __( 'The first attempt was unreliable (tab in the background or frame out of view): starting over…', 'blt-optimized' ),
						// Lab test: results.
						'labCouldNotRun'           => __( 'The lab test could not run on this page', 'blt-optimized' ),
						'labWarn_hidden_tab'       => __( 'This browser tab was in the background during the test, so the browser may not have painted the page normally. Run the test again and keep this tab in front.', 'blt-optimized' ),
						'labWarn_framed_blocked'   => __( 'This page cannot be shown in a frame. A security header sent by the web server (X-Frame-Options, or a Content-Security-Policy frame-ancestors rule) forbids it and WordPress cannot override it. Ask your host to allow framing from this site, or check the page with PageSpeed Insights.', 'blt-optimized' ),
						'labWarn_no_observer'      => __( 'The measuring script did not run on this page. It may not be a WordPress page, or a Content-Security-Policy header blocks inline scripts.', 'blt-optimized' ),
						'labWarn_timeout'          => __( 'The page was still loading or busy when the test stopped waiting (20 seconds after load, 30 overall), so late activity may be missing.', 'blt-optimized' ),
						'labWarn_no_lcp'           => __( 'The browser reported no Largest Contentful Paint. It may not support it, or the page was not in view while it painted.', 'blt-optimized' ),
						'labWarn_no_loaf'          => __( 'This browser does not report long animation frames (Chrome and Edge 123 or later do), so main-thread time per script is unavailable.', 'blt-optimized' ),
						'labWarn_no_longtask'      => __( 'This browser does not report long tasks, so Total Blocking Time could not be measured.', 'blt-optimized' ),
						'labWarn_css_truncated'    => __( 'This page has so much CSS that the unused-CSS check stopped after 0.8 seconds; the figures cover only part of it.', 'blt-optimized' ),
						'labEmptyTitle'            => __( 'No lab test for this page yet', 'blt-optimized' ),
						'labEmptyHint'             => __( 'Pick Mobile, Desktop or Both next to “Run lab test” above to measure Core Web Vitals in your own browser.', 'blt-optimized' ),
						'labOnlyTitle'             => __( 'Lab test only', 'blt-optimized' ),
						'labOnlyHint'              => __( 'Only the lab test has run for this page. Click “Profile page” to add the server-side breakdown: plugins, database queries and outbound HTTP calls.', 'blt-optimized' ),
						/* translators: %s: date and time. */
						'labMeasuredAt'            => __( 'Measured %s', 'blt-optimized' ),
						'labDevice'                => __( 'Device', 'blt-optimized' ),
						'labLcpElement'            => __( 'Largest Contentful Paint element', 'blt-optimized' ),
						'labNoLcp'                 => __( 'The browser reported no Largest Contentful Paint element for this page.', 'blt-optimized' ),
						'labFactElement'           => __( 'Element', 'blt-optimized' ),
						'labFactType'              => __( 'Type', 'blt-optimized' ),
						'labFactRendered'          => __( 'Painted at', 'blt-optimized' ),
						'labFactImage'             => __( 'Image', 'blt-optimized' ),
						'labFactSize'              => __( 'Image size', 'blt-optimized' ),
						'labFactAttrs'             => __( 'Loading hints', 'blt-optimized' ),
						'labFactText'              => __( 'Text', 'blt-optimized' ),
						'labKindImage'             => __( 'Image', 'blt-optimized' ),
						'labKindBackground'        => __( 'CSS background image', 'blt-optimized' ),
						'labKindText'              => __( 'Text', 'blt-optimized' ),
						/* translators: %s: number of pixels. */
						'labPixels'                => __( '%s px²', 'blt-optimized' ),
						'labCached'                => __( 'from cache', 'blt-optimized' ),
						/* translators: 1: file size in pixels, 2: displayed size, 3: size needed, 4: device pixel ratio. */
						'labSizeCompare'           => __( '%1$s px file, shown at %2$s px (about %3$s px needed at a pixel ratio of %4$s)', 'blt-optimized' ),
						'labBreakdown'             => __( 'Where the LCP time went', 'blt-optimized' ),
						/* translators: %s: time. */
						'labBreakdownLabel'        => __( 'Largest Contentful Paint breakdown, %s in total', 'blt-optimized' ),
						'labPartTtfb'              => __( 'Server response', 'blt-optimized' ),
						'labPartDelay'             => __( 'Finding the image', 'blt-optimized' ),
						'labPartLoad'              => __( 'Downloading the image', 'blt-optimized' ),
						'labPartRender'            => __( 'Rendering', 'blt-optimized' ),
						'labPartRenderText'        => __( 'Rendering the text', 'blt-optimized' ),
						'labCauses'                => __( 'Likely causes', 'blt-optimized' ),
						/* translators: %s: time. */
						'labCauseTtfb'             => __( 'The server took %s to answer, which delays everything after it.', 'blt-optimized' ),
						'labCauseLazy'             => __( 'The LCP image is lazy-loaded (loading="lazy"), which delays the most important image on the page. Remove lazy loading from it.', 'blt-optimized' ),
						'labCausePriority'         => __( 'The LCP image has no fetchpriority="high", so the browser may fetch it after less important files.', 'blt-optimized' ),
						'labCauseBackground'       => __( 'The LCP image is a CSS background image, so the browser only finds it after the stylesheet has loaded. An img tag, or a preload, lets it start earlier.', 'blt-optimized' ),
						/* translators: 1: natural image size, 2: size needed. */
						'labCauseOversized'        => __( 'The image file is %1$s pixels but only about %2$s are needed at the size it is shown.', 'blt-optimized' ),
						/* translators: %s: time. */
						'labCauseLate'             => __( 'The image request only started %s after the first byte, so the browser found it late.', 'blt-optimized' ),
						/* translators: %s: number of resources. */
						'labCauseBlocking'         => __( '%s render-blocking resources finished before the LCP element could be painted.', 'blt-optimized' ),
						/* translators: %s: Bricks element label. */
						'labBricksElement'         => __( 'Bricks element %s', 'blt-optimized' ),
						/* translators: %s: template or page title. */
						'labBricksIn'              => __( 'in “%s”', 'blt-optimized' ),
						'labEditBricks'            => __( 'Edit in Bricks', 'blt-optimized' ),
						'labShifts'                => __( 'Layout shifts', 'blt-optimized' ),
						/* translators: %s: Cumulative Layout Shift value. */
						'labShiftsHint'            => __( 'Elements that moved after they were first painted. Cumulative Layout Shift is %s (the worst burst of shifts).', 'blt-optimized' ),
						'labNoShifts'              => __( 'No layout shifts were recorded.', 'blt-optimized' ),
						'labColShift'              => __( 'Shift', 'blt-optimized' ),
						'labColElement'            => __( 'Element', 'blt-optimized' ),
						'labColMoved'              => __( 'Moved', 'blt-optimized' ),
						'labRemovedNode'           => __( 'An element that has since been removed', 'blt-optimized' ),
						'labBlocking'              => __( 'Render-blocking resources', 'blt-optimized' ),
						'labBlockingTile'          => __( 'Render-blocking', 'blt-optimized' ),
						/* translators: 1: number of resources, 2: time. */
						'labBlockingHint'          => __( '%1$s resources held back the first paint; the last one finished at %2$s.', 'blt-optimized' ),
						'labNoBlocking'            => __( 'Nothing blocked rendering.', 'blt-optimized' ),
						'labColWindow'             => __( 'Loaded between', 'blt-optimized' ),
						'labUnusedCss'             => __( 'Unused CSS', 'blt-optimized' ),
						'labUnusedTile'            => __( 'Unused CSS', 'blt-optimized' ),
						'labUnusedSub'             => __( 'styles nothing here', 'blt-optimized' ),
						/* translators: 1: unused size, 2: percent unused, 3: total CSS size. */
						'labUnusedHint'            => __( '%1$s of %3$s of CSS (%2$d%%) styles nothing on this page at first load. Rules for other screen sizes and for hover or focus states are not counted.', 'blt-optimized' ),
						'labNoCss'                 => __( 'No stylesheets were found.', 'blt-optimized' ),
						'labByStylesheet'          => __( 'By stylesheet', 'blt-optimized' ),
						'labColStylesheet'         => __( 'Stylesheet', 'blt-optimized' ),
						'labColSheets'             => __( 'Stylesheets', 'blt-optimized' ),
						'labColUnused'             => __( 'Unused', 'blt-optimized' ),
						'labColRules'              => __( 'Unused rules', 'blt-optimized' ),
						'labInlineStyle'           => __( 'Inline style', 'blt-optimized' ),
						'labCrossOrigin'           => __( 'From another site: its rules can’t be inspected', 'blt-optimized' ),
						/* translators: %s: number of CSS rules. */
						'labOtherMediaOne'         => __( '%s rule for another screen size is not counted as unused', 'blt-optimized' ),
						/* translators: %s: number of CSS rules. */
						'labOtherMediaMany'        => __( '%s rules for other screen sizes are not counted as unused', 'blt-optimized' ),
						'labNoAttrs'               => __( 'none set', 'blt-optimized' ),
						/* translators: %s: number of stylesheets. */
						'labMoreSheets'            => __( '%s more stylesheets are not shown.', 'blt-optimized' ),
						'labMainThread'            => __( 'Main-thread time', 'blt-optimized' ),
						'labLongTasks'             => __( 'Long tasks', 'blt-optimized' ),
						/* translators: %s: time. */
						'labLongest'               => __( 'longest %s', 'blt-optimized' ),
						'labFrames'                => __( 'Slow frames', 'blt-optimized' ),
						/* translators: %s: time. */
						'labFramesBlocking'        => __( '%s blocking in total', 'blt-optimized' ),
						'labNoScriptTime'          => __( 'No script ran long enough to be recorded: nothing blocked the main thread for more than 50 ms.', 'blt-optimized' ),
						'labScriptTimeHint'        => __( 'Script time inside slow animation frames (over 50 ms), grouped by the plugin or theme that owns the file.', 'blt-optimized' ),
						'labColScriptTime'         => __( 'Script time', 'blt-optimized' ),
						'labColForced'             => __( 'Forced layout', 'blt-optimized' ),
						'labColCalls'              => __( 'Calls', 'blt-optimized' ),
						'labByScript'              => __( 'Busiest scripts', 'blt-optimized' ),
						'labColScript'             => __( 'Script', 'blt-optimized' ),
						'labColTrigger'            => __( 'Started by', 'blt-optimized' ),
						'labLongTaskList'          => __( 'Longest tasks', 'blt-optimized' ),
						'labColStart'              => __( 'Start', 'blt-optimized' ),
						'labColBlocking'           => __( 'Blocking', 'blt-optimized' ),
						'labInline'                => __( 'Inline script', 'blt-optimized' ),
						'labCore'                  => __( 'WordPress core', 'blt-optimized' ),
						'labSite'                  => __( 'This site', 'blt-optimized' ),
						'labImages'                => __( 'Images', 'blt-optimized' ),
						'labImagesHint'            => __( 'Images that are much larger than they are shown, that load before they are needed, or that are lazy-loaded where visitors look first.', 'blt-optimized' ),
						'labImagesOk'              => __( 'No oversized, offscreen or wrongly lazy-loaded images found.', 'blt-optimized' ),
						'labColShownAt'            => __( 'Shown at', 'blt-optimized' ),
						/* translators: %s: wasted size, e.g. "120 KB". */
						'labFlagOversized'         => __( 'Oversized, about %s wasted', 'blt-optimized' ),
						'labFlagOffscreen'         => __( 'Offscreen, loaded early', 'blt-optimized' ),
						'labFlagLazyAbove'         => __( 'Lazy-loaded above the fold', 'blt-optimized' ),
						'labResources'             => __( 'Requests', 'blt-optimized' ),
						'labNoResources'           => __( 'No requests were recorded.', 'blt-optimized' ),
						'labWeight'                => __( 'Page weight', 'blt-optimized' ),
						'labFonts'                 => __( 'Fonts', 'blt-optimized' ),
						/* translators: %s: number of requests. */
						'labRequestOne'            => __( '%s request', 'blt-optimized' ),
						/* translators: %s: number of requests. */
						'labRequestMany'           => __( '%s requests', 'blt-optimized' ),
						'labByComponent'           => __( 'By plugin and theme', 'blt-optimized' ),
						'labColTransferred'        => __( 'Transferred', 'blt-optimized' ),
						'labColKinds'              => __( 'Made up of', 'blt-optimized' ),
						'labKind_js'               => __( 'JS', 'blt-optimized' ),
						'labKind_css'              => __( 'CSS', 'blt-optimized' ),
						'labKind_img'              => __( 'Images', 'blt-optimized' ),
						'labKind_font'             => __( 'Fonts', 'blt-optimized' ),
						'labKind_other'            => __( 'Other', 'blt-optimized' ),
						'labWaterfall'             => __( 'Request waterfall', 'blt-optimized' ),
						'labWaterfallHint'         => __( 'The 40 slowest or largest requests on one time axis, with first paint, largest paint and load marked.', 'blt-optimized' ),
						'labLegendNormal'          => __( 'Request', 'blt-optimized' ),
						'labLegendBlocking'        => __( 'Render-blocking', 'blt-optimized' ),
						'labLegendCached'          => __( 'From cache', 'blt-optimized' ),
						'labMarkLoad'              => __( 'Load', 'blt-optimized' ),
				),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: full scan                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Start a scan.
	 *
	 * @return void
	 */
	public function ajax_start() {
		$this->admin->verify_ajax();
		$settings = BLT_Optimized::get_settings();
		$state    = $this->plugin->site_audit->start( array( 'lab' => ! empty( $settings['enable_lab'] ) ) );
		if ( is_wp_error( $state ) ) {
			wp_send_json_error( array( 'message' => $state->get_error_message() ) );
		}
		wp_send_json_success( $this->progress( $state ) );
	}

	/**
	 * Advance the scan by one time-boxed slice.
	 *
	 * @return void
	 */
	public function ajax_tick() {
		$this->admin->verify_ajax();
		wp_send_json_success( $this->progress( $this->plugin->site_audit->tick() ) );
	}

	/**
	 * Current scan status.
	 *
	 * @return void
	 */
	public function ajax_status() {
		$this->admin->verify_ajax();
		wp_send_json_success( $this->progress( $this->plugin->site_audit->get_state() ) );
	}

	/**
	 * Cancel the scan.
	 *
	 * @return void
	 */
	public function ajax_cancel() {
		$this->admin->verify_ajax();
		wp_send_json_success( $this->progress( $this->plugin->site_audit->cancel() ) );
	}

	/**
	 * Compact progress payload.
	 *
	 * @param array $state Scan state.
	 * @return array
	 */
	private function progress( array $state ) {
		$steps   = (array) $state['steps'];
		$total   = max( 1, count( $steps ) );
		$step    = min( (int) $state['step'], $total );
		$urls    = count( (array) $state['urls'] );
		$percent = (int) floor( 100 * $step / $total );

		// Within the pages step, creep forward per page profiled.
		if ( isset( $steps[ $step ] ) && 'pages' === $steps[ $step ] && $urls ) {
			$percent += (int) floor( ( 100 / $total ) * min( 1, $state['url_index'] / $urls ) );
		}

		return array(
			'status'     => $state['status'],
			'label'      => $state['label'],
			'step'       => isset( $steps[ $step ] ) ? $steps[ $step ] : 'done',
			'percent'    => 'completed' === $state['status'] ? 100 : min( 99, $percent ),
			'pagesDone'  => (int) $state['url_index'],
			'pagesTotal' => $urls,
			'errors'     => array_values( (array) $state['errors'] ),
			'startedAt'  => (int) $state['started_at'],
			'labQueue'   => ( 'running' === $state['status'] && isset( $steps[ $step ] ) && 'lab' === $steps[ $step ] )
				? $this->plugin->site_audit->lab_queue( $state )
				: array(),
		);
	}

	/**
	 * The stored report, with dismissals and live fix state applied.
	 *
	 * @return void
	 */
	public function ajax_report() {
		$this->admin->verify_ajax();
		wp_send_json_success( $this->report_payload() );
	}

	/**
	 * Report payload shared by the report and dismiss endpoints.
	 *
	 * @return array
	 */
	private function report_payload() {
		$report    = BLT_Optimized_Findings::get_report();
		$dismissed = BLT_Optimized_Findings::get_dismissed();

		if ( $report ) {
			// Scores are recomputed so dismissing a finding updates them
			// without a re-scan.
			$report['scores']        = BLT_Optimized_Findings::score( (array) $report['findings'], $dismissed );
			$report['generatedHuman'] = sprintf(
				/* translators: %s: human time difference. */
				__( '%s ago', 'blt-optimized' ),
				human_time_diff( (int) $report['generated_at'] )
			);
		}

		return array(
			'report'      => $report,
			'dismissed'   => $dismissed,
			'categories'  => array(
				'server'   => __( 'Server & PHP', 'blt-optimized' ),
				'database' => __( 'Database', 'blt-optimized' ),
				'assets'   => __( 'Scripts & styles', 'blt-optimized' ),
				'content'  => __( 'Content', 'blt-optimized' ),
				'storage'  => __( 'Storage', 'blt-optimized' ),
			),
			'backupAcked' => $this->admin->backup_acked(),
			'exportUrl'   => wp_nonce_url( admin_url( 'admin-post.php?action=blt_optimized_export_perf_csv' ), BLT_Optimized_Admin::NONCE_ACTION ),
		);
	}

	/**
	 * Apply a fix from a finding.
	 *
	 * POST: fix_action (string), args (JSON object), backup_ack (bool).
	 *
	 * @return void
	 */
	public function ajax_fix() {
		$this->admin->verify_ajax();

		$action = isset( $_POST['fix_action'] ) ? sanitize_key( wp_unslash( $_POST['fix_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_ajax().
		$args   = $this->json_arg( 'args' );

		if ( '' === $action || ! array_key_exists( $action, BLT_Optimized_Fixes::actions() ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown fix.', 'blt-optimized' ) ) );
		}

		if ( BLT_Optimized_Fixes::is_destructive( $action ) ) {
			$this->admin->ensure_backup_ack();
		}

		$result = $this->plugin->fixes->apply( $action, $args );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Dismiss or restore a finding.
	 *
	 * @return void
	 */
	public function ajax_dismiss() {
		$this->admin->verify_ajax();
		$key       = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_ajax().
		$dismissed = ! empty( $_POST['dismissed'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_ajax().
		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'Missing finding.', 'blt-optimized' ) ) );
		}
		BLT_Optimized_Findings::set_dismissed( $key, $dismissed );
		wp_send_json_success( $this->report_payload() );
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: page profiler                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Profile one URL now. With compare=1, also profile it with every speed
	 * tweak and asset rule bypassed, so the gain is measured, not assumed.
	 *
	 * @return void
	 */
	public function ajax_profile() {
		$this->admin->verify_ajax();

		$url     = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_ajax().
		$compare = ! empty( $_POST['compare'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_ajax().

		if ( '' === $url ) {
			wp_send_json_error( array( 'message' => __( 'Enter a URL on this site.', 'blt-optimized' ) ) );
		}

		$page = $this->plugin->page_auditor->audit( $url, array( 'check_cache' => true ) );
		if ( is_wp_error( $page ) ) {
			wp_send_json_error( array( 'message' => $page->get_error_message() ) );
		}
		$this->plugin->site_audit->remember_page( $page );

		$payload = array(
			'page'     => $page,
			'summary'  => $this->plugin->site_audit->page_summary( $page ),
			'findings' => BLT_Optimized_Findings::sort( BLT_Optimized_Page_Findings::analyze( array( $page ) ) ),
		);

		if ( $compare ) {
			$baseline = $this->plugin->page_auditor->audit( $url, array( 'tweaks_off' => true ) );
			if ( ! is_wp_error( $baseline ) ) {
				$payload['baseline'] = $this->plugin->site_audit->page_summary( $baseline );
			}
		}

		wp_send_json_success( $payload );
	}

	/**
	 * Sample URLs plus pages from the last scan (for the profiler picker).
	 *
	 * @return void
	 */
	public function ajax_pages() {
		$this->admin->verify_ajax();

		$stored = array();
		foreach ( $this->plugin->site_audit->get_pages() as $key => $page ) {
			$summary         = $this->plugin->site_audit->page_summary( $page );
			$summary['key']  = $key;
			$summary['when'] = isset( $page['fetched_at'] ) ? (int) $page['fetched_at'] : 0;
			$stored[]        = $summary;
		}

		wp_send_json_success(
			array(
				'samples' => $this->plugin->page_auditor->get_sample_urls(),
				'stored'  => $stored,
			)
		);
	}

	/**
	 * One stored page report (full detail) by key.
	 *
	 * @return void
	 */
	public function ajax_page() {
		$this->admin->verify_ajax();
		$key   = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_ajax().
		$pages = $this->plugin->site_audit->get_pages();
		if ( ! isset( $pages[ $key ] ) ) {
			wp_send_json_error( array( 'message' => __( 'That page report is no longer stored. Profile the page again.', 'blt-optimized' ) ) );
		}
		$page = $pages[ $key ];
		wp_send_json_success(
			array(
				'page'     => $page,
				'summary'  => $this->plugin->site_audit->page_summary( $page ),
				'findings' => BLT_Optimized_Findings::sort( BLT_Optimized_Page_Findings::analyze( array( $page ) ) ),
			)
		);
	}

	/**
	 * Run PageSpeed Insights for one URL on demand.
	 *
	 * @return void
	 */
	public function ajax_psi() {
		$this->admin->verify_ajax();

		if ( ! $this->plugin->psi->is_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'PageSpeed Insights is off. Enable it under Settings → Performance scan.', 'blt-optimized' ) ) );
		}

		$url      = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_ajax().
		$strategy = isset( $_POST['strategy'] ) && 'desktop' === $_POST['strategy'] ? 'desktop' : 'mobile'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_ajax().

		$result = $this->plugin->psi->run( $url, $strategy );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'psi'      => $result,
				'findings' => BLT_Optimized_Findings::sort( BLT_Optimized_PSI::findings( array( $result ) ) ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: built-in lab test                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Issue a lab token for a page and return the URL to load in the iframe.
	 *
	 * @return void
	 */
	public function ajax_lab_token() {
		$this->admin->verify_ajax();
		$url = $this->lab_url_arg();
		wp_send_json_success(
			array(
				'url' => $url,
				'src' => BLT_Optimized_Lab_Mode::create_url( $url ),
			)
		);
	}

	/**
	 * Store a lab measurement posted by the browser.
	 *
	 * POST: url, viewport (mobile|desktop), payload (JSON), scan (0|1).
	 *
	 * @return void
	 */
	public function ajax_lab_store() {
		$this->admin->verify_ajax();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verify_ajax().
		$url      = $this->lab_url_arg();
		$viewport = isset( $_POST['viewport'] ) && 'desktop' === $_POST['viewport'] ? 'desktop' : 'mobile';
		$scan     = ! empty( $_POST['scan'] );
		$raw      = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded and validated field by field by BLT_Optimized_Lab::sanitize().
		// phpcs:enable

		$lab = BLT_Optimized_Lab::sanitize( $raw, $url, $viewport );
		if ( is_wp_error( $lab ) ) {
			wp_send_json_error( array( 'message' => $lab->get_error_message() ) );
		}

		$page = $scan ? $this->plugin->site_audit->lab_store( $url, $viewport, $lab ) : null;
		if ( null === $page ) {
			$page = $this->plugin->site_audit->remember_lab( $url, $viewport, $lab );
		}

		wp_send_json_success(
			array(
				'lab'      => $lab,
				'summary'  => BLT_Optimized_Lab::summary( $lab ),
				'findings' => BLT_Optimized_Findings::sort( BLT_Optimized_Lab::findings( array( $page ) ) ),
			)
		);
	}

	/**
	 * The browser finished the scan's lab step.
	 *
	 * @return void
	 */
	public function ajax_lab_done() {
		$this->admin->verify_ajax();
		wp_send_json_success( $this->progress( $this->plugin->site_audit->lab_done() ) );
	}

	/**
	 * The posted page URL, which must be on this site.
	 *
	 * @return string
	 */
	private function lab_url_arg() {
		$url  = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ), array( 'http', 'https' ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_ajax() by every caller.
		$host = BLT_Optimized_Components::url_host( $url );
		if ( '' === $url || '' === $host || ! BLT_Optimized_Components::is_local_host( $host ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter a URL on this site.', 'blt-optimized' ) ) );
		}
		return remove_query_arg( array( BLT_Optimized_Lab_Mode::QUERY_ARG, BLT_Optimized_Profiler::QUERY_ARG ), $url );
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: asset rules + tweak state                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Add an asset rule. POST: type, handle, mode, conditions (JSON array), note.
	 *
	 * @return void
	 */
	public function ajax_rule_add() {
		$this->admin->verify_ajax();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verify_ajax().
		$rule = array(
			'type'       => isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '',
			'handle'     => isset( $_POST['handle'] ) ? sanitize_text_field( wp_unslash( $_POST['handle'] ) ) : '',
			'mode'       => isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '',
			'conditions' => $this->json_arg( 'conditions' ),
			'note'       => isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '',
		);
		// phpcs:enable

		$result = $this->plugin->fixes->apply( 'asset_rule', $rule );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $this->tweaks_payload() );
	}

	/**
	 * Enable/disable an asset rule. POST: id, enabled.
	 *
	 * @return void
	 */
	public function ajax_rule_toggle() {
		$this->admin->verify_ajax();
		$id      = $this->rule_id_arg(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_ajax().
		$enabled = ! empty( $_POST['enabled'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_ajax().
		if ( ! BLT_Optimized_Tweaks::set_rule_enabled( $id, $enabled ) ) {
			wp_send_json_error( array( 'message' => __( 'Rule not found.', 'blt-optimized' ) ) );
		}
		$this->plugin->audit_log->log( 'asset_rule_toggle', sprintf( '%s %s', $id, $enabled ? 'enabled' : 'disabled' ) );
		wp_send_json_success( $this->tweaks_payload() );
	}

	/**
	 * Delete an asset rule. POST: id.
	 *
	 * @return void
	 */
	public function ajax_rule_delete() {
		$this->admin->verify_ajax();
		$id = $this->rule_id_arg(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_ajax().
		if ( ! BLT_Optimized_Tweaks::remove_rule( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Rule not found.', 'blt-optimized' ) ) );
		}
		$this->plugin->audit_log->log( 'asset_rule_delete', $id );
		wp_send_json_success( $this->tweaks_payload() );
	}

	/**
	 * Rules + reversible autoload changes, for the Speed Tweaks screen.
	 *
	 * @return void
	 */
	public function ajax_tweaks_status() {
		$this->admin->verify_ajax();
		wp_send_json_success( $this->tweaks_payload() );
	}

	/**
	 * Speed Tweaks screen data.
	 *
	 * @return array
	 */
	private function tweaks_payload() {
		$changes = array();
		foreach ( BLT_Optimized_Fixes::autoload_changes() as $option => $change ) {
			$changes[] = array(
				'option'     => $option,
				'bytes'      => isset( $change['bytes'] ) ? (int) $change['bytes'] : 0,
				'bytesHuman' => size_format( isset( $change['bytes'] ) ? (int) $change['bytes'] : 0 ),
				'changedAt'  => isset( $change['changed_at'] ) ? (int) $change['changed_at'] : 0,
			);
		}

		return array(
			'rules'           => array_values( BLT_Optimized_Tweaks::rules() ),
			'autoloadChanges' => $changes,
		);
	}

	/**
	 * The posted asset-rule id. Ids are mixed-case (`r_1usLtbi1`), so this
	 * strips rather than lowercases like sanitize_key() would.
	 *
	 * @return string
	 */
	private function rule_id_arg() {
		$id = isset( $_POST['id'] ) ? wp_unslash( $_POST['id'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified by caller; stripped to [A-Za-z0-9_] below.
		return is_string( $id ) ? preg_replace( '/[^A-Za-z0-9_]/', '', $id ) : '';
	}

	/**
	 * Decode a JSON-encoded POST field into an array.
	 *
	 * @param string $field POST field.
	 * @return array
	 */
	private function json_arg( $field ) {
		if ( ! isset( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by every caller.
			return array();
		}
		$raw = wp_unslash( $_POST[ $field ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified by caller; decoded then validated field-by-field by the fix/rule handler.
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/* ------------------------------------------------------------------ */
	/* Export                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Export the last report's findings as CSV (agency hand-off).
	 *
	 * @return void
	 */
	public function export_csv() {
		if ( ! current_user_can( BLT_Optimized_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'blt-optimized' ) );
		}
		check_admin_referer( BLT_Optimized_Admin::NONCE_ACTION );

		$report    = BLT_Optimized_Findings::get_report();
		$dismissed = array_flip( BLT_Optimized_Findings::get_dismissed() );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="blt-optimized-performance-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, array( 'severity', 'category', 'title', 'impact', 'component', 'where', 'summary', 'fix', 'dismissed', 'id' ), ',', '"', '\\' );
		foreach ( $report ? (array) $report['findings'] : array() as $finding ) {
			$row = array(
				$finding['severity'],
				$finding['category'],
				$finding['title'],
				$finding['impact']['label'],
				$finding['component'] ? BLT_Optimized_Components::label( $finding['component'] ) : '',
				implode( ' | ', array_slice( (array) $finding['where'], 0, 10 ) ),
				$finding['summary'],
				is_array( $finding['fix'] ) ? $finding['fix']['label'] : '',
				isset( $dismissed[ $finding['key'] ] ) ? 'yes' : '',
				$finding['id'],
			);
			fputcsv( $out, array_map( array( $this, 'csv_cell' ), $row ), ',', '"', '\\' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Neutralise spreadsheet formulas: a cell starting with = + - @ (or a
	 * tab/CR) is prefixed with a quote so Excel/Sheets show it as text.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	private function csv_cell( $value ) {
		$value = (string) $value;
		return ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) ? "'" . $value : $value;
	}

	/* ------------------------------------------------------------------ */
	/* Screens                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Performance Scan screen.
	 *
	 * @return void
	 */
	public function render_perf_page() {
		include BLT_OPTIMIZED_DIR . 'admin/views/performance.php';
	}

	/**
	 * Page Profiler screen.
	 *
	 * @return void
	 */
	public function render_profiler_page() {
		include BLT_OPTIMIZED_DIR . 'admin/views/profiler.php';
	}

	/**
	 * Speed Tweaks screen.
	 *
	 * @return void
	 */
	public function render_tweaks_page() {
		$tweaks = BLT_Optimized_Tweaks::get();
		include BLT_OPTIMIZED_DIR . 'admin/views/tweaks.php';
	}
}
