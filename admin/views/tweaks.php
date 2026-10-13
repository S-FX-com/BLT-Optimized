<?php
/**
 * Speed Tweaks screen.
 *
 * Included from BLT_Optimized_Perf_Admin::render_tweaks_page(), which puts the
 * saved settings in $tweaks. The tweak toggles are a plain options.php form;
 * the asset rules and the reversible autoload changes are driven by
 * assets/performance.js (they save instantly, outside that form).
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;

$blt_tweaks    = isset( $tweaks ) && is_array( $tweaks ) ? $tweaks : array();
$blt_name      = BLT_Optimized_Tweaks::OPTION;
$blt_deferable = version_compare( get_bloginfo( 'version' ), '6.3', '>=' );
$blt_woo       = class_exists( 'WooCommerce' );

/**
 * Current value of one setting.
 *
 * @param string $key     Setting key.
 * @param mixed  $default Fallback.
 * @return mixed
 */
$blt_value = static function ( $key, $default = '' ) use ( $blt_tweaks ) {
	return array_key_exists( $key, $blt_tweaks ) ? $blt_tweaks[ $key ] : $default;
};

/**
 * Count the switched-on toggles in a group, for the card badge.
 *
 * @param string[] $keys Setting keys.
 * @return int
 */
$blt_on_count = static function ( array $keys ) use ( $blt_value ) {
	$count = 0;
	foreach ( $keys as $key ) {
		if ( ! empty( $blt_value( $key, 0 ) ) ) {
			++$count;
		}
	}
	return $count;
};

/**
 * Print one toggle: label, the speed benefit, and the risk.
 *
 * @param string $key      Setting key.
 * @param string $label    Toggle label.
 * @param string $benefit  One line on what it gains.
 * @param string $risk     One line on what could go wrong.
 * @param bool   $disabled Whether the control is unavailable.
 * @return void
 */
$blt_toggle = static function ( $key, $label, $benefit, $risk, $disabled = false ) use ( $blt_name, $blt_value ) {
	?>
	<div class="blt-tweak">
		<?php if ( $disabled ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $blt_name . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( ! empty( $blt_value( $key, 0 ) ) ? '1' : '0' ); ?>" />
		<?php endif; ?>
		<label class="blt-toggle<?php echo $disabled ? ' is-disabled' : ''; ?>">
			<input type="checkbox" name="<?php echo esc_attr( $disabled ? '' : $blt_name . '[' . $key . ']' ); ?>" value="1" <?php checked( ! empty( $blt_value( $key, 0 ) ) ); ?> <?php disabled( $disabled ); ?> />
			<span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span>
			<span class="blt-toggle-text">
				<span class="blt-toggle-label"><?php echo esc_html( $label ); ?></span>
				<span class="blt-toggle-desc"><?php echo esc_html( $benefit ); ?></span>
				<span class="blt-tweak-risk"><strong><?php esc_html_e( 'Risk:', 'blt-optimized' ); ?></strong> <?php echo esc_html( $risk ); ?></span>
			</span>
		</label>
	</div>
	<?php
};

$blt_bloat_keys = array( 'disable_emojis', 'disable_embeds', 'remove_jquery_migrate', 'dashicons_logged_out', 'disable_self_pingbacks' );
$blt_intervals  = array( 0, 60, 120 );
$blt_interval   = (int) $blt_value( 'heartbeat_interval', 0 );
if ( ! in_array( $blt_interval, $blt_intervals, true ) ) {
	$blt_intervals[] = $blt_interval;
	sort( $blt_intervals );
}
$blt_safe_url = add_query_arg( BLT_Optimized_Tweaks::SAFE_ARG, '1', home_url( '/' ) );
?>
<div class="wrap blt-ui blt-optimized-wrap blt-perf" id="blt-tweaks">
	<div class="blt-admin-page-header">
		<h1>
			<?php echo BLT_Family_Brand::inline_mark( BLT_OPTIMIZED_DIR ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-built, KSES-sanitized SVG from the shared brand helper. ?>
			<?php esc_html_e( 'BLT Optimized — Speed Tweaks', 'blt-optimized' ); ?>
		</h1>
	</div>

	<?php BLT_Optimized_Admin::render_tabs( 'blt-optimized-tweaks' ); ?>

	<?php settings_errors(); ?>

	<p class="description blt-perf-intro">
		<?php esc_html_e( 'One-click fixes for what the Performance Scan finds. Every tweak is reversible, only changes what visitors receive, and never touches your content.', 'blt-optimized' ); ?>
		<a href="#blt-safe-mode"><?php esc_html_e( 'Something look wrong? Use safe mode.', 'blt-optimized' ); ?></a>
	</p>

	<form method="post" action="options.php" id="blt-tweaks-form">
		<?php settings_fields( 'blt_optimized_tweaks_group' ); ?>
		<input type="hidden" name="<?php echo esc_attr( $blt_name ); ?>[_form]" value="1" />

		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Trim WordPress front-end bloat', 'blt-optimized' ); ?></h2>
				<p><?php esc_html_e( 'Extras WordPress adds to every page that most sites never use. Each one removes a request or some inline code from every page view.', 'blt-optimized' ); ?></p>
				<div class="blt-card-header-badges">
					<span class="blt-badge <?php echo $blt_on_count( $blt_bloat_keys ) ? 'blt-badge-on' : 'blt-badge-off'; ?>">
						<?php
						printf(
							/* translators: 1: number of tweaks switched on, 2: number of tweaks in the group. */
							esc_html__( '%1$d of %2$d on', 'blt-optimized' ),
							(int) $blt_on_count( $blt_bloat_keys ),
							count( $blt_bloat_keys )
						);
						?>
					</span>
				</div>
			</div>
			<div class="blt-card-body">
				<div class="blt-toggle-stack">
					<?php
					$blt_toggle(
						'disable_emojis',
						__( 'Disable the emoji script and styles', 'blt-optimized' ),
						__( 'Drops the emoji detection script and styles WordPress prints on every page — less inline code and one less script to run.', 'blt-optimized' ),
						__( 'Emoji still show using each visitor’s own system font; only very old browsers may show a box instead of an emoji.', 'blt-optimized' )
					);
					$blt_toggle(
						'disable_embeds',
						__( 'Disable the WordPress embed script', 'blt-optimized' ),
						__( 'Stops loading wp-embed.min.js and the oEmbed discovery links on every page, saving a request.', 'blt-optimized' ),
						__( 'A post from another WordPress site shows as a plain link instead of a preview card. YouTube, Vimeo and similar embeds are unaffected.', 'blt-optimized' )
					);
					$blt_toggle(
						'remove_jquery_migrate',
						__( 'Remove jQuery Migrate', 'blt-optimized' ),
						__( 'Skips jQuery Migrate (about 10 KB) on the front end — a compatibility shim modern code does not need.', 'blt-optimized' ),
						__( 'An old theme or plugin written for jQuery 1.x can break (menus, sliders, forms). Check the front end afterwards and turn this off if something stops working.', 'blt-optimized' )
					);
					$blt_toggle(
						'dashicons_logged_out',
						__( 'Remove Dashicons for logged-out visitors', 'blt-optimized' ),
						__( 'Skips the Dashicons icon font (about 60 KB) for visitors who are not logged in — WordPress only needs it for the admin bar.', 'blt-optimized' ),
						__( 'A theme or plugin that draws front-end icons with Dashicons (some search boxes, star ratings, sliders) will show empty squares.', 'blt-optimized' )
					);
					$blt_toggle(
						'disable_self_pingbacks',
						__( 'Disable self-pingbacks', 'blt-optimized' ),
						__( 'Stops WordPress pinging your own posts when you link between them, which slows saving a post and fills the comment queue with junk pingbacks.', 'blt-optimized' ),
						__( 'None for most sites. Links to other sites still send pingbacks.', 'blt-optimized' )
					);
					?>
				</div>
			</div>
		</div>

		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Script loading', 'blt-optimized' ); ?></h2>
				<p><?php esc_html_e( 'Control when front-end JavaScript runs relative to the page.', 'blt-optimized' ); ?></p>
				<div class="blt-card-header-badges">
					<span class="blt-badge <?php echo ! empty( $blt_value( 'defer_scripts', 0 ) ) ? 'blt-badge-on' : 'blt-badge-off'; ?>">
						<?php echo ! empty( $blt_value( 'defer_scripts', 0 ) ) ? esc_html__( 'On', 'blt-optimized' ) : esc_html__( 'Off', 'blt-optimized' ); ?>
					</span>
				</div>
			</div>
			<div class="blt-card-body">
				<?php
				$blt_toggle(
					'defer_scripts',
					__( 'Defer JavaScript', 'blt-optimized' ),
					__( 'Adds defer to front-end scripts so the page can paint while they download — often the biggest single gain for Largest Contentful Paint.', 'blt-optimized' ),
					__( 'Code that expects a script to have run immediately can fail. Add that script’s handle below if something breaks. jQuery is never deferred.', 'blt-optimized' ),
					! $blt_deferable
				);
				?>
				<?php if ( ! $blt_deferable ) : ?>
					<p class="blt-field-desc blt-tweak-note">
						<?php
						printf(
							/* translators: %s: installed WordPress version. */
							esc_html__( 'Requires WordPress 6.3 or newer — this site runs %s.', 'blt-optimized' ),
							esc_html( get_bloginfo( 'version' ) )
						);
						?>
					</p>
				<?php endif; ?>
				<div class="blt-field">
					<div class="blt-field-label"><label for="blt-defer-exclude"><?php esc_html_e( 'Never defer these handles', 'blt-optimized' ); ?></label></div>
					<div>
						<textarea id="blt-defer-exclude" name="<?php echo esc_attr( $blt_name ); ?>[defer_exclude]" rows="4" class="large-text code" placeholder="my-plugin-script&#10;another-handle-*"><?php echo esc_textarea( (string) $blt_value( 'defer_exclude', '' ) ); ?></textarea>
						<p class="blt-field-desc">
							<?php esc_html_e( 'Script handles to leave alone, one per line (wildcards such as my-plugin-* work). jQuery is always excluded.', 'blt-optimized' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=blt-optimized-profiler' ) ); ?>"><?php esc_html_e( 'Find handles in the Page Profiler.', 'blt-optimized' ); ?></a>
						</p>
					</div>
				</div>
			</div>
		</div>

		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'WooCommerce', 'blt-optimized' ); ?></h2>
				<p><?php esc_html_e( 'Keep shop scripts off pages that are not part of the shop.', 'blt-optimized' ); ?></p>
				<div class="blt-card-header-badges">
					<?php if ( $blt_woo ) : ?>
						<span class="blt-badge blt-badge-on"><?php esc_html_e( 'WooCommerce active', 'blt-optimized' ); ?></span>
					<?php else : ?>
						<span class="blt-badge blt-badge-off"><?php esc_html_e( 'WooCommerce not active', 'blt-optimized' ); ?></span>
					<?php endif; ?>
				</div>
			</div>
			<div class="blt-card-body">
				<div class="blt-field">
					<div class="blt-field-label"><label for="blt-wc-fragments"><?php esc_html_e( 'Cart fragments', 'blt-optimized' ); ?></label></div>
					<div>
						<select id="blt-wc-fragments" name="<?php echo esc_attr( $blt_name ); ?>[wc_cart_fragments]">
							<option value="default" <?php selected( $blt_value( 'wc_cart_fragments', 'default' ), 'default' ); ?>><?php esc_html_e( 'Load on every page (WooCommerce default)', 'blt-optimized' ); ?></option>
							<option value="woo_pages_only" <?php selected( $blt_value( 'wc_cart_fragments', 'default' ), 'woo_pages_only' ); ?>><?php esc_html_e( 'Only on shop, cart, checkout and account pages', 'blt-optimized' ); ?></option>
						</select>
						<p class="blt-field-desc"><?php esc_html_e( 'Skips the cart-fragments script, which fires an uncached AJAX request on every page view just to refresh the mini-cart.', 'blt-optimized' ); ?></p>
						<p class="blt-field-desc blt-tweak-risk"><strong><?php esc_html_e( 'Risk:', 'blt-optimized' ); ?></strong> <?php esc_html_e( 'A mini-cart in your header or sidebar will not refresh on non-shop pages until the visitor reaches a shop page.', 'blt-optimized' ); ?></p>
						<?php if ( ! $blt_woo ) : ?>
							<p class="blt-field-desc blt-tweak-note"><?php esc_html_e( 'WooCommerce is not active on this site, so this setting has no effect until it is.', 'blt-optimized' ); ?></p>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>

		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Server load', 'blt-optimized' ); ?></h2>
				<p><?php esc_html_e( 'Cut background requests and database growth that cost server time on every visit.', 'blt-optimized' ); ?></p>
			</div>
			<div class="blt-card-body">
				<div class="blt-field">
					<div class="blt-field-label"><label for="blt-heartbeat-frontend"><?php esc_html_e( 'Front-end Heartbeat', 'blt-optimized' ); ?></label></div>
					<div>
						<select id="blt-heartbeat-frontend" name="<?php echo esc_attr( $blt_name ); ?>[heartbeat_frontend]">
							<option value="default" <?php selected( $blt_value( 'heartbeat_frontend', 'default' ), 'default' ); ?>><?php esc_html_e( 'WordPress default', 'blt-optimized' ); ?></option>
							<option value="disable" <?php selected( $blt_value( 'heartbeat_frontend', 'default' ), 'disable' ); ?>><?php esc_html_e( 'Disable on the front end', 'blt-optimized' ); ?></option>
						</select>
						<p class="blt-field-desc"><?php esc_html_e( 'The Heartbeat API pings admin-ajax.php every 15–60 seconds from open pages, and each ping is a full PHP request. Disabling it on the front end removes that background traffic.', 'blt-optimized' ); ?></p>
						<p class="blt-field-desc blt-tweak-risk"><strong><?php esc_html_e( 'Risk:', 'blt-optimized' ); ?></strong> <?php esc_html_e( 'Plugins that use front-end Heartbeat for live features (chat, countdowns, session keep-alive) stop updating.', 'blt-optimized' ); ?></p>
					</div>
				</div>
				<div class="blt-field">
					<div class="blt-field-label"><label for="blt-heartbeat-interval"><?php esc_html_e( 'Admin Heartbeat interval', 'blt-optimized' ); ?></label></div>
					<div>
						<select id="blt-heartbeat-interval" name="<?php echo esc_attr( $blt_name ); ?>[heartbeat_interval]">
							<?php foreach ( $blt_intervals as $blt_seconds ) : ?>
								<option value="<?php echo esc_attr( $blt_seconds ); ?>" <?php selected( $blt_interval, $blt_seconds ); ?>>
									<?php
									if ( 0 === $blt_seconds ) {
										esc_html_e( 'WordPress default', 'blt-optimized' );
									} else {
										/* translators: %d: seconds between Heartbeat pings. */
										echo esc_html( sprintf( __( 'Every %d seconds', 'blt-optimized' ), $blt_seconds ) );
									}
									?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="blt-field-desc"><?php esc_html_e( 'Slows the post-editor autosave and lock checks, cutting admin-ajax load on sites where several people edit at once.', 'blt-optimized' ); ?></p>
						<p class="blt-field-desc blt-tweak-risk"><strong><?php esc_html_e( 'Risk:', 'blt-optimized' ); ?></strong> <?php esc_html_e( 'Post locks and autosave notices update less often, so two editors notice each other later.', 'blt-optimized' ); ?></p>
					</div>
				</div>
				<div class="blt-field">
					<div class="blt-field-label"><label for="blt-revisions-limit"><?php esc_html_e( 'Post revisions kept per post', 'blt-optimized' ); ?></label></div>
					<div>
						<input type="number" id="blt-revisions-limit" name="<?php echo esc_attr( $blt_name ); ?>[revisions_limit]" min="-1" max="1000" step="1" value="<?php echo esc_attr( (int) $blt_value( 'revisions_limit', -1 ) ); ?>" class="small-text" />
						<p class="blt-field-desc"><?php esc_html_e( '-1 keeps the WordPress default (unlimited), 0 turns revisions off, any other number keeps that many. Every save stores a full copy of the post, so a cap keeps the database, backups and queries small.', 'blt-optimized' ); ?></p>
						<p class="blt-field-desc blt-tweak-risk"><strong><?php esc_html_e( 'Risk:', 'blt-optimized' ); ?></strong>
							<?php
							printf(
								/* translators: %s: link to the Database Cleanup screen. */
								esc_html__( 'Edits beyond the limit cannot be restored. Revisions that already exist are not deleted — use %s for that.', 'blt-optimized' ),
								'<a href="' . esc_url( admin_url( 'admin.php?page=blt-optimized-cleanup' ) ) . '">' . esc_html__( 'Database Cleanup', 'blt-optimized' ) . '</a>'
							);
							?>
						</p>
					</div>
				</div>
			</div>
		</div>

		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Resource hints', 'blt-optimized' ); ?></h2>
				<p><?php esc_html_e( 'Tell the browser what it will need before the page asks for it.', 'blt-optimized' ); ?></p>
			</div>
			<div class="blt-card-body">
				<div class="blt-field">
					<div class="blt-field-label"><label for="blt-preconnect-origins"><?php esc_html_e( 'Preconnect origins', 'blt-optimized' ); ?></label></div>
					<div>
						<textarea id="blt-preconnect-origins" name="<?php echo esc_attr( $blt_name ); ?>[preconnect_origins]" rows="3" class="large-text code" placeholder="https://fonts.gstatic.com"><?php echo esc_textarea( (string) $blt_value( 'preconnect_origins', '' ) ); ?></textarea>
						<p class="blt-field-desc"><?php esc_html_e( 'One origin per line, for example https://fonts.gstatic.com. The browser opens the connection early, saving roughly 100–300 ms on the first request to each host (fonts, analytics, CDNs).', 'blt-optimized' ); ?></p>
						<p class="blt-field-desc blt-tweak-risk"><strong><?php esc_html_e( 'Risk:', 'blt-optimized' ); ?></strong> <?php esc_html_e( 'An origin the page never uses wastes a little bandwidth and tells that host about the visit earlier. Only list hosts that load near the top of the page.', 'blt-optimized' ); ?></p>
					</div>
				</div>
			</div>
		</div>

		<div class="blt-settings-footer">
			<?php submit_button( __( 'Save speed tweaks', 'blt-optimized' ), 'primary blt-save-button', 'submit', false ); ?>
		</div>
	</form>

	<div class="blt-card" id="blt-rules-card">
		<div class="blt-card-header">
			<h2><?php esc_html_e( 'Asset rules', 'blt-optimized' ); ?></h2>
			<p>
				<?php esc_html_e( 'Stop a specific script or stylesheet from loading — everywhere, or everywhere except the pages that really use it (for example, load Contact Form 7 only on the contact page). Rules only dequeue, they never deregister, so a plugin that depends on the handle keeps working. Rules save instantly.', 'blt-optimized' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=blt-optimized-profiler' ) ); ?>"><?php esc_html_e( 'Find handles in the Page Profiler → Assets.', 'blt-optimized' ); ?></a>
			</p>
		</div>
		<div id="blt-rules-list" class="blt-card-body blt-rules-list"><p class="description"><?php esc_html_e( 'Loading…', 'blt-optimized' ); ?></p></div>
		<div class="blt-card-body blt-rule-form" id="blt-rule-form" role="group" aria-labelledby="blt-rule-form-title">
			<h3 id="blt-rule-form-title"><?php esc_html_e( 'Add a rule', 'blt-optimized' ); ?></h3>
			<div class="blt-field">
				<div class="blt-field-label"><label for="blt-rule-type"><?php esc_html_e( 'Type', 'blt-optimized' ); ?></label></div>
				<div>
					<select id="blt-rule-type">
						<option value="script"><?php esc_html_e( 'Script (JavaScript)', 'blt-optimized' ); ?></option>
						<option value="style"><?php esc_html_e( 'Style (CSS)', 'blt-optimized' ); ?></option>
					</select>
				</div>
			</div>
			<div class="blt-field">
				<div class="blt-field-label"><label for="blt-rule-handle"><?php esc_html_e( 'Handle', 'blt-optimized' ); ?></label></div>
				<div>
					<input type="text" id="blt-rule-handle" class="regular-text code" placeholder="contact-form-7" autocomplete="off" maxlength="150" />
					<p class="blt-field-desc"><?php esc_html_e( 'The name the asset is registered under, such as contact-form-7 or wc-cart-fragments.', 'blt-optimized' ); ?></p>
				</div>
			</div>
			<div class="blt-field">
				<div class="blt-field-label"><label for="blt-rule-mode"><?php esc_html_e( 'Remove', 'blt-optimized' ); ?></label></div>
				<div>
					<select id="blt-rule-mode">
						<option value="except"><?php esc_html_e( 'On every page except where…', 'blt-optimized' ); ?></option>
						<option value="everywhere"><?php esc_html_e( 'On every page', 'blt-optimized' ); ?></option>
					</select>
				</div>
			</div>
			<div class="blt-field" id="blt-rule-conditions-field">
				<div class="blt-field-label"><?php esc_html_e( 'Keep it loaded when', 'blt-optimized' ); ?></div>
				<div>
					<div id="blt-rule-conditions" class="blt-rule-conditions-rows"></div>
					<button type="button" class="button" id="blt-rule-add-condition"><?php esc_html_e( 'Add condition', 'blt-optimized' ); ?></button>
					<p class="blt-field-desc"><?php esc_html_e( 'The asset still loads when any one of these matches the page being viewed.', 'blt-optimized' ); ?></p>
				</div>
			</div>
			<div class="blt-field">
				<div class="blt-field-label"><label for="blt-rule-note"><?php esc_html_e( 'Note', 'blt-optimized' ); ?></label></div>
				<div>
					<input type="text" id="blt-rule-note" class="regular-text" maxlength="200" placeholder="<?php esc_attr_e( 'Optional — why this rule exists', 'blt-optimized' ); ?>" />
				</div>
			</div>
			<div class="blt-rule-actions">
				<button type="button" class="button button-primary" id="blt-rule-submit"><?php esc_html_e( 'Add rule', 'blt-optimized' ); ?></button>
				<span id="blt-rule-message" class="blt-rule-message" role="status" aria-live="polite"></span>
			</div>
		</div>
	</div>

	<template id="blt-rule-condition-template">
		<div class="blt-cond-row">
			<select class="blt-cond-type" aria-label="<?php esc_attr_e( 'Condition', 'blt-optimized' ); ?>">
				<option value="front_page" data-value="0"><?php esc_html_e( 'Front page', 'blt-optimized' ); ?></option>
				<option value="post_type" data-value="1" data-placeholder="product"><?php esc_html_e( 'Post type', 'blt-optimized' ); ?></option>
				<option value="post_ids" data-value="1" data-placeholder="12, 34"><?php esc_html_e( 'Post IDs', 'blt-optimized' ); ?></option>
				<option value="woocommerce" data-value="0"><?php esc_html_e( 'WooCommerce pages', 'blt-optimized' ); ?></option>
				<option value="content_contains" data-value="1" data-placeholder="[contact-form-7"><?php esc_html_e( 'Content contains', 'blt-optimized' ); ?></option>
				<option value="url_contains" data-value="1" data-placeholder="/contact/"><?php esc_html_e( 'URL contains', 'blt-optimized' ); ?></option>
			</select>
			<input type="text" class="blt-cond-value regular-text" aria-label="<?php esc_attr_e( 'Value', 'blt-optimized' ); ?>" autocomplete="off" maxlength="200" />
			<button type="button" class="button-link blt-cond-remove" aria-label="<?php esc_attr_e( 'Remove condition', 'blt-optimized' ); ?>"><span aria-hidden="true">&times;</span></button>
		</div>
	</template>

	<div class="blt-card" id="blt-changes-card">
		<div class="blt-card-header">
			<h2><?php esc_html_e( 'Reversible changes', 'blt-optimized' ); ?></h2>
			<p><?php esc_html_e( 'Options you stopped from autoloading, with the one-click way back. An option that is not autoloaded is only read when something asks for it, which keeps every request lighter.', 'blt-optimized' ); ?></p>
		</div>
		<div id="blt-changes-list" class="blt-card-body"><p class="description"><?php esc_html_e( 'Loading…', 'blt-optimized' ); ?></p></div>
	</div>

	<div class="blt-callout" id="blt-safe-mode">
		<strong><?php esc_html_e( 'Safe mode', 'blt-optimized' ); ?></strong>
		<span>
			<?php esc_html_e( 'Open the site with every speed tweak and asset rule switched off (only for you).', 'blt-optimized' ); ?>
			<a href="<?php echo esc_url( $blt_safe_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open the site in safe mode', 'blt-optimized' ); ?></a>
		</span>
	</div>
</div>
