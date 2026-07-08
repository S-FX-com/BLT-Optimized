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
?>
<div class="wrap blt-optimizer-wrap">
	<h1><?php esc_html_e( 'Blt Image Optimizer — Settings', 'blt-image-optimizer' ); ?></h1>

	<?php if ( ! empty( $saved ) ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Settings saved.', 'blt-image-optimizer' ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=blt-optimizer-settings' ) ); ?>">
		<?php wp_nonce_field( 'blt_save_settings', 'blt_settings_nonce' ); ?>

		<h2 class="title"><?php esc_html_e( 'Cloudflare Worker', 'blt-image-optimizer' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="blt_worker_url"><?php esc_html_e( 'Worker URL', 'blt-image-optimizer' ); ?></label>
				</th>
				<td>
					<input type="url" class="regular-text code" id="blt_worker_url"
						name="blt_settings[worker_url]"
						value="<?php echo esc_attr( $settings['worker_url'] ); ?>"
						placeholder="https://img-optimizer.s-fx.com/optimize" />
					<p class="description">
						<?php esc_html_e( 'The /optimize endpoint of your Cloudflare Worker. Must be deployed to a Cloudflare zone route — cf.image transforms do NOT work on workers.dev subdomains.', 'blt-image-optimizer' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="blt_worker_secret"><?php esc_html_e( 'Worker Secret', 'blt-image-optimizer' ); ?></label>
				</th>
				<td>
					<input type="password" class="regular-text code" id="blt_worker_secret"
						name="blt_settings[worker_secret]" autocomplete="new-password"
						placeholder="<?php echo $has_secret ? esc_attr__( '•••••••• (leave blank to keep current)', 'blt-image-optimizer' ) : ''; ?>" />
					<p class="description">
						<?php esc_html_e( 'Shared bearer secret. Sent as Authorization: Bearer header. Stored encrypted at rest. Leave blank to keep the current secret.', 'blt-image-optimizer' ); ?>
					</p>
					<p>
						<button type="button" class="button" id="blt-test-connection"><?php esc_html_e( 'Test Connection', 'blt-image-optimizer' ); ?></button>
						<span id="blt-test-result" class="blt-test-result"></span>
					</p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Optimization', 'blt-image-optimizer' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="blt_webp_quality"><?php esc_html_e( 'WebP Quality', 'blt-image-optimizer' ); ?></label>
				</th>
				<td>
					<input type="number" min="1" max="100" id="blt_webp_quality"
						name="blt_settings[webp_quality]"
						value="<?php echo esc_attr( $settings['webp_quality'] ); ?>" />
					<p class="description"><?php esc_html_e( '1–100. Default 82.', 'blt-image-optimizer' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="blt_max_width"><?php esc_html_e( 'Max Width (px)', 'blt-image-optimizer' ); ?></label>
				</th>
				<td>
					<input type="number" min="0" id="blt_max_width"
						name="blt_settings[max_width]"
						value="<?php echo esc_attr( $settings['max_width'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Images wider than this are scaled down. 0 = no limit. Default 2400.', 'blt-image-optimizer' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="blt_batch_size"><?php esc_html_e( 'Batch Size', 'blt-image-optimizer' ); ?></label>
				</th>
				<td>
					<input type="number" min="1" max="100" id="blt_batch_size"
						name="blt_settings[batch_size]"
						value="<?php echo esc_attr( $settings['batch_size'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Attachments processed per bulk batch. Default 10.', 'blt-image-optimizer' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Behavior', 'blt-image-optimizer' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php
			$checkboxes = array(
				'auto_optimize'           => __( 'Automatically optimize new uploads', 'blt-image-optimizer' ),
				'optimize_existing_sizes' => __( 'Optimize all WordPress-generated sizes (not just the full image)', 'blt-image-optimizer' ),
				'keep_originals'          => __( 'Keep original files alongside the .webp (recommended)', 'blt-image-optimizer' ),
				'convert_gifs'            => __( 'Convert GIFs to WebP (lossy for complex animations)', 'blt-image-optimizer' ),
				'rewrite_content'         => __( 'Rewrite hardcoded <img> tags in post content (fallback for non-standard themes)', 'blt-image-optimizer' ),
			);
			foreach ( $checkboxes as $key => $label ) :
				?>
				<tr>
					<th scope="row"><?php echo esc_html( ucwords( str_replace( '_', ' ', $key ) ) ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="blt_settings[<?php echo esc_attr( $key ); ?>]" value="1"
								<?php checked( ! empty( $settings[ $key ] ) ); ?> />
							<?php echo esc_html( $label ); ?>
						</label>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>

		<?php submit_button( __( 'Save Settings', 'blt-image-optimizer' ), 'primary', 'blt_settings_submit' ); ?>
	</form>
</div>
