<?php
/**
 * One-click fixes offered by the Performance Scan.
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;

/**
 * The only door through which a scan finding changes the site.
 *
 * A finding never carries code: it names an action id from the registry below
 * plus arguments, and this dispatcher re-validates both on every call, because
 * the request arrives from the browser and must not be trusted. Every fix
 * returns a plain `{ message, changed }` result or a WP_Error, and every
 * success is written to the audit log as `fix_<action>`, so there is always an
 * answer to "what exactly did the scan change". Capability and nonce checks
 * belong to the caller (the admin AJAX layer); nothing here is reachable
 * without one.
 *
 * Anything that deletes data is flagged destructive so the UI can demand a
 * confirmation; the reversible ones (speed tweaks, asset rules, autoload
 * changes) are not.
 */
class BLT_Optimized_Fixes {

	/**
	 * Option recording autoload changes so they can be undone (autoload off).
	 */
	const AUTOLOAD_CHANGES_OPTION = 'blt_optimized_autoload_changes';

	/**
	 * Maximum entries in the autoload change registry.
	 */
	const MAX_AUTOLOAD_CHANGES = 500;

	/**
	 * Maximum paths, items or ids accepted by one media fix call.
	 */
	const MAX_MEDIA_ITEMS = 500;

	/**
	 * Plugin instance.
	 *
	 * @var BLT_Optimized
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param BLT_Optimized $plugin Plugin instance (modules are read lazily).
	 */
	public function __construct( BLT_Optimized $plugin ) {
		$this->plugin = $plugin;
	}

	/* ------------------------------------------------------------------ */
	/* Registry                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Action registry.
	 *
	 * @return array[] Action id => { label: string, destructive: bool }.
	 */
	public static function actions() {
		return array(
			'tweak'                   => array(
				'label'       => __( 'Change a speed tweak', 'blt-optimized' ),
				'destructive' => false,
			),
			'asset_rule'              => array(
				'label'       => __( 'Add an asset rule', 'blt-optimized' ),
				'destructive' => false,
			),
			'disable_autoload'        => array(
				'label'       => __( 'Stop autoloading an option', 'blt-optimized' ),
				'destructive' => false,
			),
			'restore_autoload'        => array(
				'label'       => __( 'Restore autoloading for an option', 'blt-optimized' ),
				'destructive' => false,
			),
			'delete_cron_hook'        => array(
				'label'       => __( 'Delete orphaned scheduled events', 'blt-optimized' ),
				'destructive' => true,
			),
			'cleanup'                 => array(
				'label'       => __( 'Run a database cleanup', 'blt-optimized' ),
				'destructive' => true,
			),
			'delete_orphan_files'     => array(
				'label'       => __( 'Delete orphaned upload files', 'blt-optimized' ),
				'destructive' => true,
			),
			'delete_stale_sizes'      => array(
				'label'       => __( 'Delete unused image sizes', 'blt-optimized' ),
				'destructive' => true,
			),
			'delete_original_images'  => array(
				'label'       => __( 'Delete pre-scaled original images', 'blt-optimized' ),
				'destructive' => true,
			),
			'bricks_delete_css_files' => array(
				'label'       => __( 'Delete orphaned Bricks CSS files', 'blt-optimized' ),
				'destructive' => true,
			),
		);
	}

	/**
	 * Whether an action deletes data. Unknown actions count as destructive so a
	 * caller that asks about one errs on the side of confirming.
	 *
	 * @param string $action Action id.
	 * @return bool
	 */
	public static function is_destructive( $action ) {
		$actions = self::actions();
		if ( ! is_string( $action ) || ! isset( $actions[ $action ] ) ) {
			return true;
		}
		return (bool) $actions[ $action ]['destructive'];
	}

	/**
	 * Apply a fix.
	 *
	 * @param string $action Action id from actions().
	 * @param array  $args   Action arguments.
	 * @return array|WP_Error { message: string, changed: bool }
	 */
	public function apply( $action, array $args ) {
		$action = is_string( $action ) ? $action : '';

		if ( ! isset( self::actions()[ $action ] ) || ! method_exists( $this, 'fix_' . $action ) ) {
			return new WP_Error( 'blt_unknown_fix', __( 'Unknown fix.', 'blt-optimized' ) );
		}

		$result = $this->{'fix_' . $action}( $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Cleanup categories and file deletions write their own detailed audit
		// entry (with the files and bytes); logging them here too would
		// count the reclaimed space twice.
		$self_logging = array( 'cleanup', 'delete_orphan_files', 'delete_stale_sizes', 'delete_original_images', 'bricks_delete_css_files' );
		if ( ! in_array( $action, $self_logging, true ) && isset( $this->plugin->audit_log ) && is_object( $this->plugin->audit_log ) ) {
			$this->plugin->audit_log->log(
				'fix_' . $action,
				$result['message'],
				isset( $result['bytes'] ) ? (int) $result['bytes'] : 0,
				isset( $result['rows'] ) ? (int) $result['rows'] : 0
			);
		}

		return array(
			'message' => $result['message'],
			'changed' => (bool) $result['changed'],
		);
	}

	/* ------------------------------------------------------------------ */
	/* Speed tweaks and asset rules                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Change one speed tweak.
	 *
	 * List settings (preconnect origins, defer exclusions) are appended to, not
	 * replaced: a suggested fix must not wipe entries the user added.
	 *
	 * @param array $args { key, value }.
	 * @return array|WP_Error
	 */
	private function fix_tweak( array $args ) {
		$key = isset( $args['key'] ) ? $args['key'] : null;
		if ( ! is_string( $key ) || 'rules' === $key || ! array_key_exists( $key, BLT_Optimized_Tweaks::defaults() ) ) {
			return $this->invalid( __( 'Unknown speed tweak.', 'blt-optimized' ) );
		}
		if ( ! array_key_exists( 'value', $args ) ) {
			return $this->invalid( __( 'A value is required.', 'blt-optimized' ) );
		}

		$value   = $args['value'];
		$current = BLT_Optimized_Tweaks::get();

		if ( in_array( $key, array( 'preconnect_origins', 'defer_exclude' ), true ) && is_string( $value ) ) {
			$value = trim( $current[ $key ] . "\n" . $value );
		}

		$valid = BLT_Optimized_Tweaks::validate( $key, $value );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$saved = BLT_Optimized_Tweaks::update( array( $key => $valid ) );
		if ( (string) $saved[ $key ] !== (string) $valid ) {
			return new WP_Error( 'blt_fix_failed', __( 'The setting could not be saved.', 'blt-optimized' ) );
		}

		$labels  = BLT_Optimized_Tweaks::labels();
		$changed = (string) $current[ $key ] !== (string) $saved[ $key ];
		$shown   = $this->describe_value( $key, $saved[ $key ] );

		if ( $changed ) {
			/* translators: 1: setting label, 2: new value. */
			$message = __( 'Speed tweak "%1$s" set to: %2$s', 'blt-optimized' );
		} else {
			/* translators: 1: setting label, 2: current value. */
			$message = __( 'Speed tweak "%1$s" was already set to: %2$s', 'blt-optimized' );
		}

		return array(
			'message' => sprintf( $message, $labels[ $key ], $shown ),
			'changed' => $changed,
		);
	}

	/**
	 * Human-readable form of a stored tweak value, for messages.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Stored value.
	 * @return string
	 */
	private function describe_value( $key, $value ) {
		if ( in_array( $key, array( 'disable_emojis', 'disable_embeds', 'remove_jquery_migrate', 'dashicons_logged_out', 'defer_scripts', 'disable_self_pingbacks' ), true ) ) {
			return $value ? __( 'on', 'blt-optimized' ) : __( 'off', 'blt-optimized' );
		}
		$text = is_string( $value ) ? str_replace( "\n", ', ', $value ) : (string) $value;
		return '' === $text ? __( '(empty)', 'blt-optimized' ) : $text;
	}

	/**
	 * Add (or replace) asset rules.
	 *
	 * A finding about one asset family (Contact Form 7's scripts and its
	 * stylesheet, say) carries every handle in `rules`, so one click removes
	 * the whole family; the top-level fields describe the primary handle and
	 * are used alone when `rules` is absent. Every rule is validated before
	 * any is saved, so a family is never left half-applied.
	 *
	 * @param array $args { type, handle, mode, conditions[], note?, rules?: array[] }.
	 * @return array|WP_Error
	 */
	private function fix_asset_rule( array $args ) {
		$specs = ( isset( $args['rules'] ) && is_array( $args['rules'] ) && $args['rules'] ) ? array_values( $args['rules'] ) : array( $args );
		if ( count( $specs ) > 20 ) {
			return $this->invalid( __( 'Too many asset rules in one fix.', 'blt-optimized' ) );
		}

		$note  = ( isset( $args['note'] ) && is_string( $args['note'] ) && '' !== trim( $args['note'] ) )
			? sanitize_text_field( $args['note'] )
			: __( 'Added by the Performance Scan.', 'blt-optimized' );
		$clean = array();

		foreach ( $specs as $spec ) {
			if ( ! is_array( $spec ) ) {
				return $this->invalid( __( 'An asset rule needs a type, handle and mode.', 'blt-optimized' ) );
			}
			foreach ( array( 'type', 'handle', 'mode' ) as $required ) {
				if ( ! isset( $spec[ $required ] ) || ! is_string( $spec[ $required ] ) ) {
					return $this->invalid( __( 'An asset rule needs a type, handle and mode.', 'blt-optimized' ) );
				}
			}
			if ( isset( $spec['conditions'] ) && ! is_array( $spec['conditions'] ) ) {
				return $this->invalid( __( 'Asset rule conditions must be a list.', 'blt-optimized' ) );
			}

			$candidate = array(
				'type'       => $spec['type'],
				'handle'     => $spec['handle'],
				'mode'       => $spec['mode'],
				'conditions' => isset( $spec['conditions'] ) ? $spec['conditions'] : array(),
				'note'       => $note,
			);
			$valid     = BLT_Optimized_Tweaks::check_rule( $candidate );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			$clean[] = $candidate;
		}

		$saved = array();
		foreach ( $clean as $candidate ) {
			$stored = BLT_Optimized_Tweaks::add_rule( $candidate );
			if ( is_wp_error( $stored ) ) {
				// Only the rule cap can fail here; report what was saved.
				if ( $saved ) {
					return array(
						'message' => sprintf(
							/* translators: 1: number of rules saved, 2: error message. */
							__( 'Saved %1$d asset rules, then stopped: %2$s', 'blt-optimized' ),
							count( $saved ),
							$stored->get_error_message()
						),
						'changed' => true,
					);
				}
				return $stored;
			}
			$saved[] = $stored;
		}

		if ( count( $saved ) > 1 ) {
			return array(
				'message' => sprintf(
					/* translators: 1: number of rules, 2: comma-separated handles. */
					__( 'Saved %1$d asset rules (%2$s). Speed Tweaks → Asset rules lists them; switch any off there.', 'blt-optimized' ),
					count( $saved ),
					implode( ', ', wp_list_pluck( $saved, 'handle' ) )
				),
				'changed' => true,
			);
		}

		$rule = $saved[0];

		$where = '';
		if ( 'except' === $rule['mode'] ) {
			$parts = array();
			foreach ( $rule['conditions'] as $condition ) {
				$parts[] = '' === $condition['value'] ? $condition['type'] : $condition['type'] . ' "' . $condition['value'] . '"';
			}
			$where = implode( ', ', $parts );
		}

		return array(
			'message' => ( 'everywhere' === $rule['mode'] )
				? sprintf(
					/* translators: 1: "script" or "style", 2: handle. */
					__( 'Asset rule saved: the %1$s "%2$s" is removed on every front-end page.', 'blt-optimized' ),
					$rule['type'],
					$rule['handle']
				)
				: sprintf(
					/* translators: 1: "script" or "style", 2: handle, 3: list of conditions. */
					__( 'Asset rule saved: the %1$s "%2$s" only loads where one of these matches: %3$s.', 'blt-optimized' ),
					$rule['type'],
					$rule['handle'],
					$where
				),
			'changed' => true,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Autoload                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Options that must never stop autoloading: WordPress core settings read on
	 * every request, plus this plugin's own. Entries containing `*` are
	 * fnmatch patterns; everything else is an exact name. Compare
	 * case-insensitively (see is_protected()) because MySQL matches option
	 * names that way.
	 *
	 * @return string[]
	 */
	public static function protected_options() {
		global $wpdb;
		$prefix = ( isset( $wpdb ) && ! empty( $wpdb->prefix ) ) ? $wpdb->prefix : 'wp_';

		$options = array(
			// Special rows WordPress itself stores under these names.
			'alloptions',
			'notoptions',
			// Core.
			'cron',
			'rewrite_rules',
			'wp_user_roles',
			$prefix . 'user_roles',
			'active_plugins',
			'sidebars_widgets',
			'widget_*',
			'theme_mods_*',
			// Bricks Builder reads its global settings, classes, styles and
			// palettes on every page; out of autoload they'd cost a query each.
			'bricks_*',
			'template',
			'stylesheet',
			'siteurl',
			'home',
			'blogname',
			'uninstall_plugins',
			// Settings core reads on nearly every request.
			'blogdescription',
			'admin_email',
			'permalink_structure',
			'category_base',
			'tag_base',
			'db_version',
			'initial_db_version',
			'WPLANG',
			'timezone_string',
			'gmt_offset',
			'date_format',
			'time_format',
			'show_on_front',
			'page_on_front',
			'page_for_posts',
			'blog_public',
			'default_role',
			'users_can_register',
			'upload_path',
			'upload_url_path',
			'current_theme',
			'recently_activated',
			'active_sitewide_plugins',
			'fresh_site',
			// This plugin.
			'blt_optimized_*',
		);

		/**
		 * Filters the options that "Stop autoloading" refuses to touch.
		 *
		 * @param string[] $options Exact names and `*` patterns.
		 */
		$filtered = apply_filters( 'blt_optimized_protected_options', $options );
		return is_array( $filtered ) ? array_values( array_filter( $filtered, 'is_string' ) ) : $options;
	}

	/**
	 * Whether an option name is on the protected list.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	public static function is_protected( $option ) {
		$option = strtolower( (string) $option );
		foreach ( self::protected_options() as $entry ) {
			$entry = strtolower( $entry );
			if ( false !== strpos( $entry, '*' ) ? fnmatch( $entry, $option ) : $entry === $option ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Autoload changes made through the scan, for the "restore" UI.
	 *
	 * @return array[] option_name => { previous: string, changed_at: int, bytes: int }
	 */
	public static function autoload_changes() {
		$stored = get_option( self::AUTOLOAD_CHANGES_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$out = array();
		foreach ( $stored as $name => $change ) {
			if ( ! is_string( $name ) || ! is_array( $change ) ) {
				continue;
			}
			$out[ $name ] = array(
				'previous'   => isset( $change['previous'] ) ? (string) $change['previous'] : 'yes',
				'changed_at' => isset( $change['changed_at'] ) ? (int) $change['changed_at'] : 0,
				'bytes'      => isset( $change['bytes'] ) ? (int) $change['bytes'] : 0,
			);
		}
		return $out;
	}

	/**
	 * Stop autoloading an option, recording how it was so it can be restored.
	 *
	 * @param array $args { option }.
	 * @return array|WP_Error
	 */
	private function fix_disable_autoload( array $args ) {
		$name = $this->option_arg( $args );
		if ( is_wp_error( $name ) ) {
			return $name;
		}

		// Check the name as given first: wp_set_option_autoload() wp_die()s on a
		// couple of special names, so those must never get as far as a lookup.
		if ( self::is_protected( $name ) ) {
			return $this->protected_error( $name );
		}

		$row = $this->option_row( $name );
		if ( ! $row ) {
			return new WP_Error( 'blt_fix_missing', __( 'That option does not exist.', 'blt-optimized' ) );
		}

		// The name WordPress will actually use (MySQL matches names case-insensitively).
		$name = $row['option_name'];
		if ( self::is_protected( $name ) ) {
			return $this->protected_error( $name );
		}

		if ( ! self::autoload_is_on( $row['autoload'] ) ) {
			return array(
				'message' => sprintf(
					/* translators: %s: option name. */
					__( 'The option "%s" is already not autoloaded.', 'blt-optimized' ),
					$name
				),
				'changed' => false,
			);
		}

		if ( ! $this->set_autoload( $name, false ) ) {
			return new WP_Error( 'blt_fix_failed', __( 'The autoload flag could not be changed.', 'blt-optimized' ) );
		}

		$after = $this->option_row( $name );
		if ( ! $after || self::autoload_is_on( $after['autoload'] ) ) {
			return new WP_Error( 'blt_fix_failed', __( 'The autoload flag could not be changed.', 'blt-optimized' ) );
		}

		$changes          = self::autoload_changes();
		$changes[ $name ] = array(
			'previous'   => $row['autoload'],
			'changed_at' => time(),
			'bytes'      => (int) $row['bytes'],
		);
		if ( count( $changes ) > self::MAX_AUTOLOAD_CHANGES ) {
			$changes = array_slice( $changes, -self::MAX_AUTOLOAD_CHANGES, null, true );
		}
		update_option( self::AUTOLOAD_CHANGES_OPTION, $changes, false );

		return array(
			'message' => sprintf(
				/* translators: 1: option name, 2: option size. */
				__( 'The option "%1$s" (%2$s) no longer loads on every request. It is loaded on demand instead, and you can restore it from Speed Tweaks.', 'blt-optimized' ),
				$name,
				size_format( (int) $row['bytes'] )
			),
			'changed' => true,
		);
	}

	/**
	 * Error for an option that must keep autoloading.
	 *
	 * @param string $name Option name.
	 * @return WP_Error
	 */
	private function protected_error( $name ) {
		return new WP_Error(
			'blt_fix_refused',
			sprintf(
				/* translators: %s: option name. */
				__( 'The option "%s" is needed on every request and cannot be excluded from autoload.', 'blt-optimized' ),
				$name
			)
		);
	}

	/**
	 * Restore the autoload flag recorded by disable_autoload.
	 *
	 * @param array $args { option }.
	 * @return array|WP_Error
	 */
	private function fix_restore_autoload( array $args ) {
		$name = $this->option_arg( $args );
		if ( is_wp_error( $name ) ) {
			return $name;
		}

		$changes = self::autoload_changes();
		if ( ! isset( $changes[ $name ] ) ) {
			return new WP_Error( 'blt_fix_missing', __( 'No recorded autoload change for that option, so there is nothing to restore.', 'blt-optimized' ) );
		}

		$row = $this->option_row( $name );
		unset( $changes[ $name ] );

		if ( ! $row ) {
			$this->save_autoload_changes( $changes );
			return array(
				'message' => sprintf(
					/* translators: %s: option name. */
					__( 'The option "%s" no longer exists, so the record was cleared.', 'blt-optimized' ),
					$name
				),
				'changed' => false,
			);
		}

		if ( self::autoload_is_on( $row['autoload'] ) ) {
			$this->save_autoload_changes( $changes );
			return array(
				'message' => sprintf(
					/* translators: %s: option name. */
					__( 'The option "%s" already autoloads.', 'blt-optimized' ),
					$row['option_name']
				),
				'changed' => false,
			);
		}

		if ( ! $this->set_autoload( $row['option_name'], true ) ) {
			return new WP_Error( 'blt_fix_failed', __( 'The autoload flag could not be changed.', 'blt-optimized' ) );
		}

		$after = $this->option_row( $row['option_name'] );
		if ( ! $after || ! self::autoload_is_on( $after['autoload'] ) ) {
			return new WP_Error( 'blt_fix_failed', __( 'The autoload flag could not be changed.', 'blt-optimized' ) );
		}

		$this->save_autoload_changes( $changes );

		return array(
			'message' => sprintf(
				/* translators: %s: option name. */
				__( 'The option "%s" loads on every request again.', 'blt-optimized' ),
				$row['option_name']
			),
			'changed' => true,
		);
	}

	/**
	 * Persist the autoload change registry (never autoloaded itself).
	 *
	 * @param array $changes Registry.
	 * @return void
	 */
	private function save_autoload_changes( array $changes ) {
		if ( empty( $changes ) ) {
			delete_option( self::AUTOLOAD_CHANGES_OPTION );
			return;
		}
		update_option( self::AUTOLOAD_CHANGES_OPTION, $changes, false );
	}

	/**
	 * Validate the `option` argument.
	 *
	 * @param array $args Arguments.
	 * @return string|WP_Error
	 */
	private function option_arg( array $args ) {
		$option = isset( $args['option'] ) ? $args['option'] : null;
		if ( ! is_string( $option ) || '' === $option || strlen( $option ) > 191 || preg_match( '/[\x00-\x1F\x7F]/', $option ) ) {
			return $this->invalid( __( 'A valid option name is required.', 'blt-optimized' ) );
		}
		return $option;
	}

	/**
	 * Look an option up in the options table.
	 *
	 * @param string $name Option name.
	 * @return array|null { option_name, autoload, bytes }
	 */
	private function option_row( $name ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT option_name, autoload, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$name
			),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Whether a stored autoload value means "load on every request". WP 6.6+
	 * uses on/off/auto/auto-on/auto-off beside the legacy yes/no.
	 *
	 * @param string $value Stored autoload value.
	 * @return bool
	 */
	private static function autoload_is_on( $value ) {
		return in_array( (string) $value, array( 'yes', 'on', 'auto', 'auto-on' ), true );
	}

	/**
	 * Change an option's autoload flag, on any supported WordPress version.
	 *
	 * @param string $name Option name (never a protected one).
	 * @param bool   $on   Whether it should autoload.
	 * @return bool
	 */
	private function set_autoload( $name, $on ) {
		// wp_set_option_autoload() wp_die()s on these; they are internal cache rows.
		if ( in_array( strtolower( $name ), array( 'alloptions', 'notoptions' ), true ) ) {
			return false;
		}
		if ( function_exists( 'wp_set_option_autoload' ) ) {
			wp_set_option_autoload( $name, $on );
			return true;
		}
		return $this->set_autoload_direct( $name, $on );
	}

	/**
	 * WordPress 6.0-6.3 path: wp_set_option_autoload() does not exist yet, so
	 * update the row directly and flush the option caches.
	 *
	 * @param string $name Option name.
	 * @param bool   $on   Whether it should autoload.
	 * @return bool
	 */
	private function set_autoload_direct( $name, $on ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$wpdb->options,
			array( 'autoload' => $on ? 'yes' : 'no' ),
			array( 'option_name' => $name ),
			array( '%s' ),
			array( '%s' )
		);
		// phpcs:enable

		if ( false === $updated ) {
			return false;
		}

		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( $name, 'options' );
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Cron                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Unschedule every event of a hook that nothing listens to any more.
	 *
	 * @param array $args { hook }.
	 * @return array|WP_Error
	 */
	private function fix_delete_cron_hook( array $args ) {
		$hook = isset( $args['hook'] ) ? $args['hook'] : null;
		if ( ! is_string( $hook ) || ! preg_match( '/^[\x20-\x7E]{1,191}$/', $hook ) ) {
			return $this->invalid( __( 'A valid hook name is required.', 'blt-optimized' ) );
		}

		$count = 0;
		foreach ( (array) _get_cron_array() as $events ) {
			if ( is_array( $events ) && isset( $events[ $hook ] ) ) {
				$count += count( (array) $events[ $hook ] );
			}
		}
		if ( 0 === $count ) {
			return new WP_Error( 'blt_fix_missing', __( 'No scheduled events use that hook.', 'blt-optimized' ) );
		}

		if ( has_action( $hook ) ) {
			return new WP_Error( 'blt_fix_refused', __( 'Something is still listening to that hook, so its scheduled events are not orphaned.', 'blt-optimized' ) );
		}
		if ( $this->plugin->health->cron_hook_owner_active( $hook ) ) {
			return new WP_Error( 'blt_fix_refused', __( 'That hook appears to belong to an active plugin, which may register its handler only while WP-Cron runs. It was left alone.', 'blt-optimized' ) );
		}

		$core_hooks = array(
			'delete_expired_transients',
			'publish_future_post',
			'importer_scheduled_cleanup',
			'recovery_mode_clean_expired_keys',
			'action_scheduler_run_queue',
		);
		if ( 0 === strpos( $hook, 'wp_' ) || in_array( $hook, $core_hooks, true ) ) {
			return new WP_Error( 'blt_fix_refused', __( 'That is a WordPress core scheduled event and cannot be deleted here.', 'blt-optimized' ) );
		}

		$removed = wp_unschedule_hook( $hook );
		if ( is_wp_error( $removed ) ) {
			return $removed;
		}
		if ( false === $removed ) {
			return new WP_Error( 'blt_fix_failed', __( 'The scheduled events could not be removed.', 'blt-optimized' ) );
		}

		$removed = (int) $removed;
		return array(
			'message' => sprintf(
				/* translators: 1: number of events, 2: hook name. */
				_n( 'Removed %1$d scheduled event for the orphaned hook "%2$s".', 'Removed %1$d scheduled events for the orphaned hook "%2$s".', $removed, 'blt-optimized' ),
				$removed,
				$hook
			),
			'changed' => $removed > 0,
			'rows'    => $removed,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Cleanup                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Run one database cleanup category.
	 *
	 * @param array $args { category }.
	 * @return array|WP_Error
	 */
	private function fix_cleanup( array $args ) {
		$category = isset( $args['category'] ) ? $args['category'] : null;
		if ( ! is_string( $category ) || '' === $category ) {
			return $this->invalid( __( 'A cleanup category is required.', 'blt-optimized' ) );
		}

		$cleaner = isset( $this->plugin->cleaner ) ? $this->plugin->cleaner : null;
		if ( ! is_object( $cleaner ) || ! method_exists( $cleaner, 'get_categories' ) || ! method_exists( $cleaner, 'execute' ) ) {
			return $this->unavailable();
		}

		$categories = $cleaner->get_categories();
		if ( ! is_array( $categories ) || ! array_key_exists( $category, $categories ) ) {
			return new WP_Error( 'blt_unknown_category', __( 'Unknown cleanup category.', 'blt-optimized' ) );
		}
		if ( ! empty( $categories[ $category ]['flag_only'] ) ) {
			return new WP_Error( 'blt_flag_only', __( 'This category is flag-only and cannot be executed.', 'blt-optimized' ) );
		}

		$result = $cleaner->execute( $category );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$rows  = isset( $result['rows'] ) ? (int) $result['rows'] : 0;
		$bytes = isset( $result['bytes'] ) ? (int) $result['bytes'] : 0;

		return array(
			'message' => sprintf(
				/* translators: 1: cleanup label, 2: number of rows, 3: human-readable size. */
				__( 'Cleanup "%1$s": %2$s rows removed, about %3$s reclaimed.', 'blt-optimized' ),
				isset( $categories[ $category ]['label'] ) ? $categories[ $category ]['label'] : $category,
				number_format_i18n( $rows ),
				size_format( $bytes )
			),
			'changed' => $rows > 0,
			'rows'    => $rows,
			'bytes'   => $bytes,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Media                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Delete orphaned upload files. The media auditor re-validates every path
	 * against its stored audit results; this only checks the argument shape.
	 *
	 * @param array $args { paths: string[] }.
	 * @return array|WP_Error
	 */
	private function fix_delete_orphan_files( array $args ) {
		$paths = isset( $args['paths'] ) ? $args['paths'] : null;
		if ( ! is_array( $paths ) || empty( $paths ) || count( $paths ) > self::MAX_MEDIA_ITEMS ) {
			return $this->invalid( __( 'Provide between 1 and 500 file paths.', 'blt-optimized' ) );
		}

		$clean = array();
		foreach ( $paths as $path ) {
			if ( ! is_string( $path ) || '' === $path || strlen( $path ) > 500 || preg_match( '/[\x00-\x1F\x7F]/', $path ) ) {
				return $this->invalid( __( 'Every path must be a plain file path.', 'blt-optimized' ) );
			}
			$clean[ $path ] = $path;
		}

		return $this->run_media( 'delete_orphans', array_values( $clean ) );
	}

	/**
	 * Delete unused registered-size thumbnails.
	 *
	 * @param array $args { items: array( { attachment_id, size } ) }.
	 * @return array|WP_Error
	 */
	private function fix_delete_stale_sizes( array $args ) {
		$items = isset( $args['items'] ) ? $args['items'] : null;
		if ( ! is_array( $items ) || empty( $items ) || count( $items ) > self::MAX_MEDIA_ITEMS ) {
			return $this->invalid( __( 'Provide between 1 and 500 image sizes.', 'blt-optimized' ) );
		}

		$clean = array();
		foreach ( $items as $item ) {
			$id   = ( is_array( $item ) && isset( $item['attachment_id'] ) ) ? $this->positive_int( $item['attachment_id'] ) : null;
			$size = ( is_array( $item ) && isset( $item['size'] ) && is_string( $item['size'] ) ) ? $item['size'] : '';
			if ( null === $id || '' === $size || strlen( $size ) > 100 || preg_match( '/[\x00-\x1F\x7F]/', $size ) ) {
				return $this->invalid( __( 'Every item needs an attachment ID and an image size name.', 'blt-optimized' ) );
			}
			$clean[ $id . '|' . $size ] = array(
				'attachment_id' => $id,
				'size'          => $size,
			);
		}

		return $this->run_media( 'delete_stale_sizes', array_values( $clean ) );
	}

	/**
	 * Delete the pre-scaled original of big images.
	 *
	 * @param array $args { attachment_ids: int[] }.
	 * @return array|WP_Error
	 */
	private function fix_delete_original_images( array $args ) {
		$ids = isset( $args['attachment_ids'] ) ? $args['attachment_ids'] : null;
		if ( ! is_array( $ids ) || empty( $ids ) || count( $ids ) > self::MAX_MEDIA_ITEMS ) {
			return $this->invalid( __( 'Provide between 1 and 500 attachment IDs.', 'blt-optimized' ) );
		}

		$clean = array();
		foreach ( $ids as $id ) {
			$id = $this->positive_int( $id );
			if ( null === $id ) {
				return $this->invalid( __( 'Attachment IDs must be positive whole numbers.', 'blt-optimized' ) );
			}
			$clean[ $id ] = $id;
		}

		return $this->run_media( 'delete_originals', array_values( $clean ) );
	}

	/* ------------------------------------------------------------------ */
	/* Bricks                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Delete orphaned Bricks CSS files (`uploads/bricks/css/post-{ID}.min.css`
	 * whose post is gone or no longer uses Bricks). The Bricks module checks every
	 * name against the list the last scan stored, resolves it inside the Bricks CSS
	 * folder and re-checks the post right before deleting; this only checks the
	 * argument shape.
	 *
	 * @param array $args { files: string[] }.
	 * @return array|WP_Error
	 */
	private function fix_bricks_delete_css_files( array $args ) {
		$files = isset( $args['files'] ) ? $args['files'] : null;
		if ( ! is_array( $files ) || empty( $files ) || count( $files ) > self::MAX_MEDIA_ITEMS ) {
			return $this->invalid( __( 'Provide between 1 and 500 CSS file names.', 'blt-optimized' ) );
		}

		$clean = array();
		foreach ( $files as $file ) {
			if ( ! is_string( $file ) || '' === $file || strlen( $file ) > 100 || preg_match( '/[\x00-\x1F\x7F]/', $file ) ) {
				return $this->invalid( __( 'Every file must be a plain CSS file name.', 'blt-optimized' ) );
			}
			$clean[ $file ] = $file;
		}

		if ( ! class_exists( 'BLT_Optimized_Bricks' ) ) {
			return $this->unavailable();
		}

		$bricks = new BLT_Optimized_Bricks( isset( $this->plugin->audit_log ) ? $this->plugin->audit_log : null );
		$result = $bricks->delete_css_files( array_values( $clean ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$count   = (int) $result['files'];
		$bytes   = (int) $result['bytes'];
		$skipped = (int) $result['skipped'];

		$message = sprintf(
			/* translators: 1: number of files, 2: human-readable size. */
			_n( 'Deleted %1$s orphaned Bricks CSS file, freeing %2$s.', 'Deleted %1$s orphaned Bricks CSS files, freeing %2$s.', $count, 'blt-optimized' ),
			number_format_i18n( $count ),
			size_format( $bytes )
		);
		if ( $skipped > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %s: number of items. */
				_n( '%s file was skipped because it changed or is now in use.', '%s files were skipped because they changed or are now in use.', $skipped, 'blt-optimized' ),
				number_format_i18n( $skipped )
			);
		}

		return array(
			'message' => $message,
			'changed' => $count > 0,
			'rows'    => $count,
			'bytes'   => $bytes,
		);
	}

	/**
	 * Call a media auditor deletion method and format its result.
	 *
	 * @param string $method Media auditor method.
	 * @param array  $items  Validated items.
	 * @return array|WP_Error
	 */
	private function run_media( $method, array $items ) {
		$auditor = isset( $this->plugin->media_auditor ) ? $this->plugin->media_auditor : null;
		if ( ! is_object( $auditor ) || ! method_exists( $auditor, $method ) ) {
			return $this->unavailable();
		}

		$result = $auditor->$method( $items );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_array( $result ) ) {
			return new WP_Error( 'blt_fix_failed', __( 'The media cleanup returned an unexpected result.', 'blt-optimized' ) );
		}

		$files = 0;
		foreach ( array( 'files', 'deleted', 'count' ) as $key ) {
			if ( isset( $result[ $key ] ) ) {
				$files = (int) $result[ $key ];
				break;
			}
		}
		$bytes   = isset( $result['bytes'] ) ? (int) $result['bytes'] : 0;
		$skipped = isset( $result['skipped'] ) ? (int) $result['skipped'] : 0;

		$message = sprintf(
			/* translators: 1: number of files, 2: human-readable size. */
			_n( 'Deleted %1$s file, freeing %2$s.', 'Deleted %1$s files, freeing %2$s.', $files, 'blt-optimized' ),
			number_format_i18n( $files ),
			size_format( $bytes )
		);
		if ( $skipped > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %s: number of items. */
				_n( '%s item was skipped because it changed or is now in use.', '%s items were skipped because they changed or are now in use.', $skipped, 'blt-optimized' ),
				number_format_i18n( $skipped )
			);
		}

		return array(
			'message' => $message,
			'changed' => $files > 0,
			'rows'    => $files,
			'bytes'   => $bytes,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Strictly read a positive integer (int, or a string of digits).
	 *
	 * @param mixed $value Raw value.
	 * @return int|null
	 */
	private function positive_int( $value ) {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}
		if ( is_string( $value ) && ctype_digit( $value ) && (int) $value > 0 ) {
			return (int) $value;
		}
		return null;
	}

	/**
	 * Invalid-arguments error.
	 *
	 * @param string $message Message.
	 * @return WP_Error
	 */
	private function invalid( $message ) {
		return new WP_Error( 'blt_fix_invalid_args', $message );
	}

	/**
	 * Error for a module that is not available in this build.
	 *
	 * @return WP_Error
	 */
	private function unavailable() {
		return new WP_Error( 'blt_fix_unavailable', __( 'That fix is not available because a required part of the plugin is missing.', 'blt-optimized' ) );
	}
}
