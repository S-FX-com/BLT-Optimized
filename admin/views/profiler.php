<?php
/**
 * Page Profiler screen.
 *
 * Included from BLT_Optimized_Perf_Admin::render_profiler_page(). One URL in,
 * a Query-Monitor-style breakdown out; assets/performance.js fills
 * #blt-profiler-result from the perf_profile / perf_page endpoints.
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;

$blt_settings = BLT_Optimized::get_settings();
$blt_psi_on   = ! empty( $blt_settings['enable_psi'] );
?>
<div class="wrap blt-ui blt-ui-wide blt-optimized-wrap blt-perf" id="blt-profiler">
	<div class="blt-admin-page-header">
		<h1>
			<?php echo BLT_Family_Brand::inline_mark( BLT_OPTIMIZED_DIR ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-built, KSES-sanitized SVG from the shared brand helper. ?>
			<?php esc_html_e( 'BLT Optimized — Page Profiler', 'blt-optimized' ); ?>
		</h1>
	</div>

	<?php BLT_Optimized_Admin::render_tabs( 'blt-optimized-profiler' ); ?>

	<div class="blt-card">
		<div class="blt-card-header">
			<h2><?php esc_html_e( 'Profile a page', 'blt-optimized' ); ?></h2>
			<p><?php esc_html_e( 'Loads the page the way a visitor sees it and records which plugin spent the time, the database queries, scripts, outbound HTTP calls and PHP errors behind it.', 'blt-optimized' ); ?></p>
		</div>
		<div class="blt-card-body">
			<div class="blt-field">
				<div class="blt-field-label"><label for="blt-profiler-url"><?php esc_html_e( 'Page URL', 'blt-optimized' ); ?></label></div>
				<div>
					<div class="blt-profiler-urlrow">
						<input type="url" id="blt-profiler-url" class="regular-text" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" autocomplete="off" />
						<select id="blt-profiler-pick" aria-label="<?php esc_attr_e( 'Choose a page', 'blt-optimized' ); ?>">
							<option value=""><?php esc_html_e( 'Choose a page…', 'blt-optimized' ); ?></option>
						</select>
					</div>
					<p class="blt-field-desc"><?php esc_html_e( 'A page on this site. A path such as /contact/ works too.', 'blt-optimized' ); ?></p>
				</div>
			</div>
			<div class="blt-field">
				<div class="blt-field-label"><?php esc_html_e( 'Options', 'blt-optimized' ); ?></div>
				<div>
					<label class="blt-toggle">
						<input type="checkbox" id="blt-profiler-compare" value="1" />
						<span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span>
						<span class="blt-toggle-text">
							<span class="blt-toggle-label"><?php esc_html_e( 'Also measure with Speed Tweaks off (A/B)', 'blt-optimized' ); ?></span>
							<span class="blt-toggle-desc"><?php esc_html_e( 'Loads the page a second time with every speed tweak and asset rule bypassed, so you see what they actually gain. Takes about twice as long.', 'blt-optimized' ); ?></span>
						</span>
					</label>
				</div>
			</div>
			<div class="blt-profiler-actions">
				<button type="button" class="button button-primary" id="blt-profiler-run"><?php esc_html_e( 'Profile page', 'blt-optimized' ); ?></button>
				<?php if ( $blt_psi_on ) : ?>
					<span class="blt-profiler-psi">
						<label class="screen-reader-text" for="blt-profiler-strategy"><?php esc_html_e( 'PageSpeed Insights device', 'blt-optimized' ); ?></label>
						<select id="blt-profiler-strategy">
							<option value="mobile"><?php esc_html_e( 'Mobile', 'blt-optimized' ); ?></option>
							<option value="desktop"><?php esc_html_e( 'Desktop', 'blt-optimized' ); ?></option>
						</select>
						<button type="button" class="button" id="blt-profiler-psi-run"><?php esc_html_e( 'PageSpeed Insights', 'blt-optimized' ); ?></button>
					</span>
				<?php else : ?>
					<span class="blt-profiler-psi-off">
						<?php
						printf(
							/* translators: %s: link to the settings screen. */
							esc_html__( 'Want Lighthouse lab data (LCP, CLS, TBT)? Turn on PageSpeed Insights in %s.', 'blt-optimized' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=blt-optimized-settings' ) ) . '">' . esc_html__( 'Settings', 'blt-optimized' ) . '</a>'
						);
						?>
					</span>
				<?php endif; ?>
			</div>
			<div id="blt-profiler-status" class="blt-profiler-status" aria-live="polite"></div>
		</div>
	</div>

	<div id="blt-profiler-psi-result"></div>

	<div id="blt-profiler-result">
		<div class="blt-card">
			<div class="blt-card-body">
				<div class="blt-empty">
					<span class="blt-empty-title"><?php esc_html_e( 'Loading…', 'blt-optimized' ); ?></span>
				</div>
			</div>
		</div>
	</div>

	<noscript>
		<div class="blt-callout"><strong><?php esc_html_e( 'JavaScript is required', 'blt-optimized' ); ?></strong> <span><?php esc_html_e( 'The profiler loads the page and renders its report in your browser, so it needs JavaScript enabled.', 'blt-optimized' ); ?></span></div>
	</noscript>
</div>
