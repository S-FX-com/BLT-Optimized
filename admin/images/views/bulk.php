<?php
/**
 * Bulk optimizer view.
 *
 * @package BltImageOptimizer
 *
 * @var array $state Current queue state (from Queue::get_state()).
 * @var array $stats Aggregate stats (from Logger::stats()).
 */

namespace BltImageOptimizer;

defined( 'ABSPATH' ) || exit;

$configured = Settings::is_configured();
$status     = $state['status'] ?? 'idle';
?>
<div class="wrap blt-ui blt-ui-wide blt-optimizer-wrap">
	<div class="blt-admin-page-header">
		<h1>
			<?php
			if ( class_exists( '\\BLT_Family_Brand' ) ) {
				// Pre-built, KSES-sanitized SVG from the shared brand helper.
				echo \BLT_Family_Brand::inline_mark( BLT_OPTIMIZER_DIR ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
			<?php esc_html_e( 'BLT Image Optimizer — Bulk Optimizer', 'blt-image-optimizer' ); ?>
		</h1>
		<div class="blt-admin-page-actions">
			<?php if ( $configured ) : ?>
				<span class="blt-badge blt-badge-on"><?php esc_html_e( 'Worker connected', 'blt-image-optimizer' ); ?></span>
			<?php else : ?>
				<span class="blt-badge blt-badge-off"><?php esc_html_e( 'Worker not configured', 'blt-image-optimizer' ); ?></span>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( class_exists( '\\BLT_Optimized_Admin' ) ) { \BLT_Optimized_Admin::render_tabs( 'blt-optimizer' ); } ?>

	<?php if ( ! $configured ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					/* translators: %s: settings page URL. */
					wp_kses_post( __( 'The Cloudflare Worker is not configured yet. <a href="%s">Open Settings</a> to add your Worker URL and secret.', 'blt-image-optimizer' ) ),
					esc_url( admin_url( 'admin.php?page=blt-optimizer-settings' ) )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<div class="blt-stats">
		<div class="blt-stat">
			<span class="blt-stat-label"><?php esc_html_e( 'Sizes optimized', 'blt-image-optimizer' ); ?></span>
			<span class="blt-stat-value" data-blt-stat="done"><?php echo esc_html( (string) $stats['by_status']['done'] ); ?></span>
		</div>
		<div class="blt-stat">
			<span class="blt-stat-label"><?php esc_html_e( 'Total saved', 'blt-image-optimizer' ); ?></span>
			<span class="blt-stat-value" data-blt-stat="saved_bytes"><?php echo esc_html( size_format( $stats['saved_bytes'], 1 ) ); ?></span>
		</div>
		<div class="blt-stat">
			<span class="blt-stat-label"><?php esc_html_e( 'Average reduction', 'blt-image-optimizer' ); ?></span>
			<span class="blt-stat-value" data-blt-stat="saved_pct"><?php echo esc_html( (string) $stats['saved_pct'] ); ?>%</span>
		</div>
		<div class="blt-stat">
			<span class="blt-stat-label"><?php esc_html_e( 'Errors', 'blt-image-optimizer' ); ?></span>
			<span class="blt-stat-value" data-blt-stat="errors"><?php echo esc_html( (string) $stats['by_status']['error'] ); ?></span>
		</div>
	</div>

	<div class="blt-card blt-stack-top blt-bulk-panel" data-status="<?php echo esc_attr( $status ); ?>">
		<div class="blt-card-header">
			<h2><?php esc_html_e( 'Bulk run', 'blt-image-optimizer' ); ?></h2>
		</div>
		<div class="blt-card-body">
			<div class="blt-progress-wrap">
				<div class="blt-progress"><div class="blt-progress-fill" style="width:0%"></div></div>
				<p class="blt-progress-text" id="blt-progress-text">
					<?php esc_html_e( 'Idle — ready to start.', 'blt-image-optimizer' ); ?>
				</p>
			</div>

			<p class="blt-bulk-controls">
				<button type="button" class="button button-primary" id="blt-start" <?php disabled( ! $configured ); ?>>
					<?php esc_html_e( 'Start Bulk Optimization', 'blt-image-optimizer' ); ?>
				</button>
				<button type="button" class="button" id="blt-pause" hidden><?php esc_html_e( 'Pause', 'blt-image-optimizer' ); ?></button>
				<button type="button" class="button" id="blt-resume" hidden><?php esc_html_e( 'Resume', 'blt-image-optimizer' ); ?></button>
				<button type="button" class="button button-link-delete" id="blt-cancel" hidden><?php esc_html_e( 'Cancel', 'blt-image-optimizer' ); ?></button>
			</p>

			<div id="blt-bulk-message" class="blt-bulk-message" aria-live="polite"></div>
		</div>
	</div>

	<div class="blt-card">
		<div class="blt-card-body">
			<details class="blt-handoff">
				<summary><?php esc_html_e( 'Hand-Off Checklist', 'blt-image-optimizer' ); ?></summary>
				<ul>
					<li><?php esc_html_e( 'Run the bulk optimizer to 100% completion.', 'blt-image-optimizer' ); ?></li>
					<li><?php esc_html_e( 'Confirm zero errors in the Log.', 'blt-image-optimizer' ); ?></li>
					<li><?php esc_html_e( 'Record before/after totals for the client report.', 'blt-image-optimizer' ); ?></li>
					<li><?php esc_html_e( 'Disable auto-optimize or deactivate the plugin.', 'blt-image-optimizer' ); ?></li>
					<li><?php esc_html_e( 'Optimized .webp files remain on disk — the site keeps serving them with no Cloudflare dependency.', 'blt-image-optimizer' ); ?></li>
				</ul>
			</details>
		</div>
	</div>
</div>
