<?php
/**
 * Settings view.
 *
 * @package BltImageOptimizer
 *
 * @var array $settings Current settings (from Settings::all()).
 * @var bool  $saved    Whether settings were just saved.
 */

namespace BltImageOptimizer;

defined( 'ABSPATH' ) || exit;

$has_secret = '' !== ( $settings['worker_secret'] ?? '' );

$toggles = array(
	'auto_optimize'           => array(
		__( 'Automatically optimize new uploads', 'blt-image-optimizer' ),
		'',
	),
	'optimize_existing_sizes' => array(
		__( 'Optimize all WordPress-generated sizes', 'blt-image-optimizer' ),
		__( 'Not just the full-size image.', 'blt-image-optimizer' ),
	),
	'keep_originals'          => array(
		__( 'Keep original files alongside the .webp', 'blt-image-optimizer' ),
		__( 'Recommended.', 'blt-image-optimizer' ),
	),
	'convert_gifs'            => array(
		__( 'Convert GIFs to WebP', 'blt-image-optimizer' ),
		__( 'Lossy for complex animations.', 'blt-image-optimizer' ),
	),
	'rewrite_content'         => array(
		__( 'Rewrite hardcoded <img> tags in post content', 'blt-image-optimizer' ),
		__( 'Fallback for non-standard themes.', 'blt-image-optimizer' ),
	),
);
?>
<div class="wrap blt-ui blt-optimizer-wrap">
	<div class="blt-admin-page-header">
		<h1>
			<?php
			if ( class_exists( '\\BLT_Family_Brand' ) ) {
				// Pre-built, KSES-sanitized SVG from the shared brand helper.
				echo \BLT_Family_Brand::inline_mark( BLT_OPTIMIZER_DIR ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
			<?php esc_html_e( 'BLT Image Optimizer — Settings', 'blt-image-optimizer' ); ?>
		</h1>
	</div>

	<?php if ( class_exists( '\\BLT_Optimized_Admin' ) ) { \BLT_Optimized_Admin::render_tabs( 'blt-optimizer-settings' ); } ?>

	<?php if ( ! empty( $saved ) ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Settings saved.', 'blt-image-optimizer' ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=blt-optimizer-settings' ) ); ?>">
		<?php wp_nonce_field( 'blt_save_settings', 'blt_settings_nonce' ); ?>

		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Cloudflare Worker', 'blt-image-optimizer' ); ?></h2>
				<div class="blt-card-header-badges">
					<?php if ( Settings::is_configured() ) : ?>
						<span class="blt-badge blt-badge-on"><?php esc_html_e( 'Configured', 'blt-image-optimizer' ); ?></span>
					<?php else : ?>
						<span class="blt-badge blt-badge-off"><?php esc_html_e( 'Not configured', 'blt-image-optimizer' ); ?></span>
					<?php endif; ?>
				</div>
			</div>
			<div class="blt-card-body">
				<div class="blt-field">
					<div class="blt-field-label">
						<label for="blt_worker_url"><?php esc_html_e( 'Worker URL', 'blt-image-optimizer' ); ?></label>
					</div>
					<div>
						<input type="url" class="regular-text code" id="blt_worker_url"
							name="blt_settings[worker_url]"
							value="<?php echo esc_attr( $settings['worker_url'] ); ?>"
							placeholder="https://img-optimizer.s-fx.com/optimize" />
						<p class="blt-field-desc">
							<?php esc_html_e( 'The /optimize endpoint of your Cloudflare Worker. Must be deployed to a Cloudflare zone route — cf.image transforms do NOT work on workers.dev subdomains.', 'blt-image-optimizer' ); ?>
						</p>
					</div>
				</div>
				<div class="blt-field">
					<div class="blt-field-label">
						<label for="blt_worker_secret"><?php esc_html_e( 'Worker Secret', 'blt-image-optimizer' ); ?></label>
					</div>
					<div>
						<input type="password" class="regular-text code" id="blt_worker_secret"
							name="blt_settings[worker_secret]" autocomplete="new-password"
							placeholder="<?php echo $has_secret ? esc_attr__( '•••••••• (leave blank to keep current)', 'blt-image-optimizer' ) : ''; ?>" />
						<p class="blt-field-desc">
							<?php esc_html_e( 'Shared bearer secret. Sent as Authorization: Bearer header. Stored encrypted at rest. Leave blank to keep the current secret.', 'blt-image-optimizer' ); ?>
						</p>
						<?php if ( $has_secret ) : ?>
							<p class="blt-field-desc">
								<label>
									<input type="checkbox" name="blt_settings[worker_secret_clear]" value="1" />
									<?php esc_html_e( 'Clear the saved secret', 'blt-image-optimizer' ); ?>
								</label>
								<br />
								<?php esc_html_e( 'Only needed to hand this over to a shared BLT credential: this plugin\'s own secret always wins, so the shared one applies only once nothing is stored here.', 'blt-image-optimizer' ); ?>
							</p>
						<?php endif; ?>
						<p class="blt-stack-top">
							<button type="button" class="button" id="blt-test-connection"><?php esc_html_e( 'Test Connection', 'blt-image-optimizer' ); ?></button>
							<span id="blt-test-result" class="blt-test-result"></span>
						</p>
					</div>
				</div>
			</div>
		</div>

		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Optimization', 'blt-image-optimizer' ); ?></h2>
			</div>
			<div class="blt-card-body">
				<div class="blt-field">
					<div class="blt-field-label">
						<label for="blt_webp_quality"><?php esc_html_e( 'WebP Quality', 'blt-image-optimizer' ); ?></label>
					</div>
					<div>
						<input type="number" min="1" max="100" id="blt_webp_quality"
							name="blt_settings[webp_quality]"
							value="<?php echo esc_attr( $settings['webp_quality'] ); ?>" />
						<p class="blt-field-desc"><?php esc_html_e( '1–100. Default 82.', 'blt-image-optimizer' ); ?></p>
					</div>
				</div>
				<div class="blt-field">
					<div class="blt-field-label">
						<label for="blt_max_width"><?php esc_html_e( 'Max Width (px)', 'blt-image-optimizer' ); ?></label>
					</div>
					<div>
						<input type="number" min="0" id="blt_max_width"
							name="blt_settings[max_width]"
							value="<?php echo esc_attr( $settings['max_width'] ); ?>" />
						<p class="blt-field-desc"><?php esc_html_e( 'Images wider than this are scaled down. 0 = no limit. Default 2400.', 'blt-image-optimizer' ); ?></p>
					</div>
				</div>
				<div class="blt-field">
					<div class="blt-field-label">
						<label for="blt_batch_size"><?php esc_html_e( 'Batch Size', 'blt-image-optimizer' ); ?></label>
					</div>
					<div>
						<input type="number" min="1" max="100" id="blt_batch_size"
							name="blt_settings[batch_size]"
							value="<?php echo esc_attr( $settings['batch_size'] ); ?>" />
						<p class="blt-field-desc"><?php esc_html_e( 'Attachments processed per bulk batch. Default 10.', 'blt-image-optimizer' ); ?></p>
					</div>
				</div>
			</div>
		</div>

		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Behavior', 'blt-image-optimizer' ); ?></h2>
			</div>
			<div class="blt-card-body">
				<div class="blt-toggle-stack">
					<?php foreach ( $toggles as $key => $labels ) : ?>
						<label class="blt-toggle">
							<input type="checkbox" name="blt_settings[<?php echo esc_attr( $key ); ?>]" value="1"
								<?php checked( ! empty( $settings[ $key ] ) ); ?> />
							<span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span>
							<span class="blt-toggle-text">
								<span class="blt-toggle-label"><?php echo esc_html( $labels[0] ); ?></span>
								<?php if ( '' !== $labels[1] ) : ?>
									<span class="blt-toggle-desc"><?php echo esc_html( $labels[1] ); ?></span>
								<?php endif; ?>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>
		</div>

		<div class="blt-settings-footer">
			<?php submit_button( __( 'Save Settings', 'blt-image-optimizer' ), 'primary blt-save-button', 'blt_settings_submit', false ); ?>
		</div>
	</form>
</div>
