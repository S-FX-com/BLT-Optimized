<?php
/**
 * The shared "BLT" admin screen.
 *
 * @package BLT_Family
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One top-level "BLT" menu, registered exactly once by whichever copy of the
 * library booted, holding the shared connection settings and an overview of
 * every BLT plugin on the site.
 *
 * Registered only when two or more BLT plugins are active (see
 * BLT_Family::boot()), so a single-plugin site never sees it.
 *
 * Built on the family design system: the page is wrapped in .blt-ui and
 * composed from the components in DESIGN.md, so it looks like the plugins it
 * serves. The stylesheet comes from whichever plugin's copy the library was
 * loaded from — every copy is byte-identical.
 */
class BLT_Family_Admin {

	const MENU_SLUG = 'blt-family';
	const NONCE     = 'blt_family_save';

	/**
	 * Prefix for credential inputs. Underscore-separated and distinct from the
	 * menu slug so a field name can never collide with a control name.
	 */
	const FIELD_PREFIX = 'blt_family_field_';

	/**
	 * Hook the screen up.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_post' ) );
		add_action( 'admin_head', array( __CLASS__, 'print_menu_icon_style' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Register the top-level BLT menu.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'BLT', 'blt-family' ),
			__( 'BLT', 'blt-family' ),
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render' ),
			BLT_Family_Brand::menu_icon( self::library_dir() ),
			80
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Shared Settings', 'blt-family' ),
			__( 'Shared Settings', 'blt-family' ),
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Light the menu icon on hover, as core does for dashicons.
	 *
	 * @return void
	 */
	public static function print_menu_icon_style() {
		BLT_Family_Brand::print_menu_icon_style( self::MENU_SLUG );
	}

	/**
	 * Load the design system on this screen only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue( $hook ) {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'blt-family-design-system',
			self::library_url() . 'assets/css/blt-design-system.css',
			array(),
			BLT_Family::VERSION
		);
	}

	/**
	 * Save the form. PRG: redirect so a refresh never re-posts.
	 *
	 * @return void
	 */
	public static function handle_post() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked immediately below.
		if ( empty( $_POST['blt_family_submit'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'blt-family' ) );
		}

		check_admin_referer( self::NONCE );

		$errors = array();

		// --- Credential values, group by group. ---
		foreach ( BLT_Family_Groups::all() as $group => $definition ) {
			$values = array();

			foreach ( array_keys( $definition['fields'] ) as $field ) {
				$input = self::FIELD_PREFIX . $group . '_' . $field;

				if ( ! isset( $_POST[ $input ] ) ) {
					continue;
				}

				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on the next line.
				$raw   = wp_unslash( $_POST[ $input ] );
				$value = is_string( $raw ) ? trim( sanitize_text_field( $raw ) ) : '';

				// A blank secret means "leave the stored value alone", so
				// secrets never have to be rendered back into the page. An
				// explicit clear goes through the per-field Clear checkbox.
				if ( '' === $value && BLT_Family_Groups::is_secret( $group, $field ) ) {
					if ( empty( $_POST[ $input . '_clear' ] ) ) {
						continue;
					}
				}

				$checked = BLT_Family_Groups::validate( $group, $field, $value );

				if ( is_wp_error( $checked ) ) {
					$errors[] = $checked->get_error_message();
					continue;
				}

				$values[ $field ] = $checked;
			}

			if ( ! empty( $values ) ) {
				BLT_Family_Store::set_group( $group, $values );
			}
		}

		// --- Per-plugin opt-ins. ---
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each element sanitized below.
		$submitted = isset( $_POST['blt_family_opt_in'] ) && is_array( $_POST['blt_family_opt_in'] )
			? wp_unslash( $_POST['blt_family_opt_in'] )
			: array();

		foreach ( BLT_Family::plugins() as $slug => $plugin ) {
			$groups = isset( $submitted[ $slug ] ) && is_array( $submitted[ $slug ] )
				? array_map( 'sanitize_key', $submitted[ $slug ] )
				: array();

			BLT_Family::set_opted_in( $slug, $groups );
		}

		if ( ! empty( $errors ) ) {
			set_transient( 'blt_family_errors', $errors, 60 );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => self::MENU_SLUG,
					'blt-family-said' => empty( $errors ) ? 'saved' : 'partial',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$plugins = BLT_Family::plugins();
		$groups  = BLT_Family_Groups::all();

		// Only render a group at least one active plugin actually consumes —
		// an empty field nothing reads is just a place to leak a credential.
		$relevant = array();

		foreach ( $groups as $group => $definition ) {
			$consumers = BLT_Family::consumers( $group );

			if ( ! empty( $consumers ) ) {
				$relevant[ $group ] = array(
					'definition' => $definition,
					'consumers'  => $consumers,
				);
			}
		}

		?>
		<div class="wrap blt-ui blt-family-settings">
			<div class="blt-admin-page-header">
				<h1>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG, run through wp_kses.
					echo BLT_Family_Brand::inline_mark( self::library_dir() );
					?>
					<?php esc_html_e( 'BLT Shared Settings', 'blt-family' ); ?>
					<span class="blt-admin-page-header-sub">
						<?php
						printf(
							/* translators: %d: number of active BLT plugins. */
							esc_html( _n( '%d BLT plugin active', '%d BLT plugins active', count( $plugins ), 'blt-family' ) ),
							count( $plugins )
						);
						?>
					</span>
				</h1>
			</div>

			<?php self::render_notices(); ?>

			<div class="blt-callout">
				<strong><?php esc_html_e( 'Enter a connection once, use it in every BLT plugin.', 'blt-family' ); ?></strong>
				<span>
					<?php esc_html_e( 'A plugin only ever reads a value from here when its own setting is empty AND you have ticked it below. Nothing you have already configured changes, and turning everything off restores the exact behaviour of each plugin on its own.', 'blt-family' ); ?>
				</span>
			</div>

			<?php if ( BLT_Family::utilities_are_stale() ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php
						printf(
							/* translators: 1: older library version in use, 2: newest installed library version. */
							esc_html__( 'Your BLT plugins ship different versions of the shared library. The update policy currently in force comes from version %1$s, because that plugin loads first, while the newest installed copy is %2$s. Update every BLT plugin so they match.', 'blt-family' ),
							esc_html( BLT_Family::utilities_version() ),
							esc_html( BLT_Family::VERSION )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( ! BLT_Family_Crypto::is_strong() ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php esc_html_e( 'Neither libsodium nor OpenSSL AES-GCM is available on this server, so shared secrets can only be obfuscated, not encrypted. Ask your host to enable one of them before storing credentials here.', 'blt-family' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="blt_family_submit" value="1" />

				<?php self::render_plugins_card( $plugins ); ?>

				<?php
				foreach ( $relevant as $group => $data ) {
					self::render_group_card( $group, $data['definition'], $data['consumers'] );
				}
				?>

				<div class="blt-settings-footer">
					<button type="submit" class="button button-primary blt-save-button">
						<?php esc_html_e( 'Save changes', 'blt-family' ); ?>
					</button>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * The overview card: which BLT plugins are here, and their update actions.
	 *
	 * @param array<string,array> $plugins Registered plugins.
	 * @return void
	 */
	private static function render_plugins_card( array $plugins ) {
		ksort( $plugins );
		?>
		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'BLT plugins on this site', 'blt-family' ); ?></h2>
				<p><?php esc_html_e( 'Every plugin registered with the shared layer, and the update check each one offers.', 'blt-family' ); ?></p>
			</div>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Plugin', 'blt-family' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Version', 'blt-family' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Shared groups used', 'blt-family' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Updates', 'blt-family' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $plugins as $slug => $plugin ) : ?>
						<?php $enabled = BLT_Family::opted_in( $slug ); ?>
						<tr>
							<td>
								<strong>
									<?php if ( '' !== $plugin['menu'] ) : ?>
										<a href="<?php echo esc_url( self::plugin_page_url( $plugin['menu'] ) ); ?>">
											<?php echo esc_html( $plugin['name'] ); ?>
										</a>
									<?php else : ?>
										<?php echo esc_html( $plugin['name'] ); ?>
									<?php endif; ?>
								</strong>
							</td>
							<td><?php echo '' !== $plugin['version'] ? esc_html( $plugin['version'] ) : '&mdash;'; ?></td>
							<td>
								<?php if ( empty( $enabled ) ) : ?>
									<span class="blt-badge blt-badge-off"><?php esc_html_e( 'None', 'blt-family' ); ?></span>
								<?php else : ?>
									<?php foreach ( $enabled as $group ) : ?>
										<span class="blt-badge blt-badge-on"><?php echo esc_html( self::group_label( $group ) ); ?></span>
									<?php endforeach; ?>
								<?php endif; ?>
							</td>
							<td>
								<a href="<?php echo esc_url( BLT_Family_Updates::check_now_url( $plugin['update_slug'] ) ); ?>">
									<?php esc_html_e( 'Check for Updates', 'blt-family' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<div class="blt-card-body">
				<p class="blt-field-desc">
					<?php esc_html_e( 'BLT plugins check GitHub for updates once a day, at midnight site time. "Check for Updates" runs a check immediately.', 'blt-family' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * One credential group: its fields, then the plugins that may read them.
	 *
	 * @param string              $group      Group key.
	 * @param array               $definition Group definition.
	 * @param array<string,array> $consumers  Plugins declaring this group.
	 * @return void
	 */
	private static function render_group_card( $group, array $definition, array $consumers ) {
		$configured = BLT_Family_Store::group_configured( $group );
		?>
		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php echo esc_html( $definition['label'] ); ?></h2>
				<p><?php echo esc_html( $definition['description'] ); ?></p>
				<div class="blt-card-header-badges">
					<span class="blt-badge <?php echo $configured ? 'blt-badge-on' : 'blt-badge-off'; ?>">
						<?php echo $configured ? esc_html__( 'Configured', 'blt-family' ) : esc_html__( 'Empty', 'blt-family' ); ?>
					</span>
				</div>
			</div>
			<div class="blt-card-body">
				<?php foreach ( $definition['fields'] as $field => $spec ) : ?>
					<?php
					$input     = self::FIELD_PREFIX . $group . '_' . $field;
					$is_secret = ! empty( $spec['secret'] );
					$stored    = BLT_Family_Store::get( $group, $field );
					$has_value = '' !== $stored;
					?>
					<div class="blt-field">
						<div class="blt-field-label">
							<label for="<?php echo esc_attr( $input ); ?>"><?php echo esc_html( $spec['label'] ); ?></label>
						</div>
						<div>
							<?php if ( $is_secret ) : ?>
								<input
									type="password"
									id="<?php echo esc_attr( $input ); ?>"
									name="<?php echo esc_attr( $input ); ?>"
									value=""
									class="regular-text"
									autocomplete="new-password"
									placeholder="<?php echo $has_value ? esc_attr__( 'Saved — leave blank to keep it', 'blt-family' ) : esc_attr__( 'Not set', 'blt-family' ); ?>"
								/>
								<?php if ( $has_value ) : ?>
									<label class="blt-field-desc">
										<input type="checkbox" name="<?php echo esc_attr( $input ); ?>_clear" value="1" />
										<?php esc_html_e( 'Clear this value', 'blt-family' ); ?>
									</label>
								<?php endif; ?>
							<?php else : ?>
								<input
									type="text"
									id="<?php echo esc_attr( $input ); ?>"
									name="<?php echo esc_attr( $input ); ?>"
									value="<?php echo esc_attr( $stored ); ?>"
									class="regular-text"
								/>
							<?php endif; ?>

							<?php if ( '' !== $spec['help'] ) : ?>
								<p class="blt-field-desc"><?php echo wp_kses( $spec['help'], array( 'code' => array() ) ); ?></p>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<div class="blt-card-body">
				<div class="blt-field-label"><?php esc_html_e( 'Let these plugins use the values above', 'blt-family' ); ?></div>
				<p class="blt-field-desc">
					<?php esc_html_e( 'Only when the plugin\'s own setting for the same thing is empty. Off by default, so enabling a plugin here is always a deliberate choice.', 'blt-family' ); ?>
				</p>
				<div class="blt-toggle-stack blt-stack-top">
					<?php foreach ( $consumers as $slug => $plugin ) : ?>
						<label class="blt-toggle">
							<input
								type="checkbox"
								name="blt_family_opt_in[<?php echo esc_attr( $slug ); ?>][]"
								value="<?php echo esc_attr( $group ); ?>"
								<?php checked( in_array( $group, BLT_Family::opted_in( $slug ), true ) ); ?>
							/>
							<span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span>
							<span class="blt-toggle-text">
								<span class="blt-toggle-label"><?php echo esc_html( $plugin['name'] ); ?></span>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Success / error notices after a save.
	 *
	 * @return void
	 */
	private static function render_notices() {
		$errors = get_transient( 'blt_family_errors' );

		if ( is_array( $errors ) && ! empty( $errors ) ) {
			delete_transient( 'blt_family_errors' );

			echo '<div class="notice notice-error inline"><ul class="blt-error-list">';
			foreach ( $errors as $error ) {
				echo '<li>' . esc_html( $error ) . '</li>';
			}
			echo '</ul></div>';

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only, after our own redirect.
		$said = isset( $_GET['blt-family-said'] ) ? sanitize_key( wp_unslash( $_GET['blt-family-said'] ) ) : '';

		if ( 'saved' === $said ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Shared settings saved.', 'blt-family' ) . '</p></div>';
		}
	}

	/**
	 * The admin URL for a registered plugin's page.
	 *
	 * A bare slug resolves against admin.php, which is right for a page added
	 * with add_menu_page() or a submenu of one. It is wrong for a submenu whose
	 * parent is something else — WordPress dispatches a submenu callback through
	 * its parent, so 'edit.php?post_type=event&page=blt-events-settings' loaded
	 * as 'admin.php?page=blt-events-settings' cannot reach the callback at all.
	 * Those plugins register the relative URL instead of a slug, and it is
	 * passed through.
	 *
	 * @param string $menu Registered slug, or a relative admin URL.
	 * @return string
	 */
	private static function plugin_page_url( $menu ) {
		$menu = (string) $menu;

		if ( false !== strpos( $menu, '?' ) || false !== strpos( $menu, '.php' ) ) {
			return admin_url( ltrim( $menu, '/' ) );
		}

		return add_query_arg( array( 'page' => $menu ), admin_url( 'admin.php' ) );
	}

	/**
	 * A group's human label, falling back to its key.
	 *
	 * @param string $group Group key.
	 * @return string
	 */
	private static function group_label( $group ) {
		$definition = BLT_Family_Groups::get( $group );

		return null === $definition ? $group : $definition['label'];
	}

	/**
	 * Directory of the library copy that booted (trailing slash).
	 *
	 * The library lives at <plugin>/includes/blt-family/, so the plugin root is
	 * two levels up — that is where assets/ sits.
	 *
	 * @return string
	 */
	private static function library_dir() {
		return trailingslashit( dirname( __DIR__, 2 ) );
	}

	/**
	 * URL of the plugin whose library copy booted (trailing slash).
	 *
	 * @return string
	 */
	private static function library_url() {
		return trailingslashit( plugin_dir_url( dirname( __DIR__ ) ) );
	}
}
