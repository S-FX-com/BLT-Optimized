<?php
/**
 * Performance Scan screen (the plugin's landing page).
 *
 * Included from BLT_Optimized_Perf_Admin::render_perf_page(). The page is a
 * thin shell: assets/performance.js drives the scan (perf_status / perf_tick)
 * and renders the report into #blt-perf-results.
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap blt-ui blt-ui-wide blt-optimized-wrap blt-perf" id="blt-perf-scan">
	<div class="blt-admin-page-header">
		<h1>
			<?php echo BLT_Family_Brand::inline_mark( BLT_OPTIMIZED_DIR ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-built, KSES-sanitized SVG from the shared brand helper. ?>
			<?php esc_html_e( 'BLT Optimized — Performance Scan', 'blt-optimized' ); ?>
		</h1>
		<div class="blt-admin-page-actions">
			<a class="button" id="blt-perf-export" href="#" hidden><?php esc_html_e( 'Export CSV', 'blt-optimized' ); ?></a>
			<button type="button" class="button" id="blt-perf-cancel" hidden><?php esc_html_e( 'Cancel', 'blt-optimized' ); ?></button>
			<button type="button" class="button button-primary" id="blt-perf-start"><?php esc_html_e( 'Run full scan', 'blt-optimized' ); ?></button>
		</div>
	</div>

	<?php BLT_Optimized_Admin::render_tabs( 'blt-optimized' ); ?>

	<p class="description blt-perf-intro">
		<?php esc_html_e( 'Finds the specific plugins, scripts, queries, images and files that slow this site down — and fixes the safe ones in one click. A scan profiles a sample of your pages the way a visitor sees them, then checks the server, the database and the media library.', 'blt-optimized' ); ?>
		<span id="blt-perf-meta" class="blt-perf-meta"></span>
	</p>

	<div id="blt-perf-notices" aria-live="polite"></div>

	<div class="blt-card blt-perf-progress" id="blt-perf-progress" hidden>
		<div class="blt-card-body">
			<div class="blt-perf-progress-head">
				<strong id="blt-perf-label" role="status"><?php esc_html_e( 'Scanning…', 'blt-optimized' ); ?></strong>
				<span id="blt-perf-percent" class="blt-perf-percent">0%</span>
			</div>
			<div class="blt-progress"><div class="blt-progress-fill" id="blt-perf-bar"></div></div>
			<ol class="blt-perf-steps" id="blt-perf-steps"></ol>
			<div class="blt-lab-panel" id="blt-perf-lab-stage" hidden></div>
			<button type="button" class="button" id="blt-perf-resume" hidden><?php esc_html_e( 'Resume scan', 'blt-optimized' ); ?></button>
		</div>
	</div>

	<div id="blt-perf-results">
		<div class="blt-card">
			<div class="blt-card-body">
				<div class="blt-empty">
					<span class="blt-empty-title"><?php esc_html_e( 'Loading…', 'blt-optimized' ); ?></span>
				</div>
			</div>
		</div>
	</div>

	<noscript>
		<div class="blt-callout"><strong><?php esc_html_e( 'JavaScript is required', 'blt-optimized' ); ?></strong> <span><?php esc_html_e( 'The performance scan runs in your browser in short steps, so it needs JavaScript enabled.', 'blt-optimized' ); ?></span></div>
	</noscript>
</div>
