<?php
/**
 * The BLT family update policy.
 *
 * @package BLT_Family
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies one shared policy to every BLT plugin's update checker:
 *
 *   - at most ONE automatic check per day,
 *   - the daily check anchored to 00:00 site time,
 *   - manual checks ("Check for Updates") always allowed, immediately,
 *   - the BLT mark on the plugin's card in the update screens.
 *
 * Why the floor is needed. plugin-update-checker's own scheduler does not
 * respect its checkPeriod on every path: Scheduler::getEffectiveCheckPeriod()
 * shortens the interval to 60 seconds on Dashboard -> Updates and to 1 hour on
 * the Plugins screen, so a plugin configured for "daily" still hits GitHub
 * every time an admin opens those pages. The puc_check_now filter installed
 * here overrides that with a hard 24-hour floor.
 *
 * Three things are deliberately exempt from the floor, because each is either
 * an explicit human request or already rate-limited to once a day:
 *
 *   1. The "Check for Updates" plugin-row link. It calls checkForUpdates()
 *      directly and never reaches this filter at all — noted here so nobody
 *      "fixes" the floor into blocking it.
 *   2. The "Check again" button on Dashboard -> Updates (?force-check=1).
 *   3. The scheduled cron event itself, which fires once a day by definition.
 *      Without this exemption a check that lands a second early against the
 *      previous one would be dropped and the plugin would go 48 hours
 *      between checks.
 *
 * Usage, next to where the checker is built (which MUST pass 24 as
 * buildUpdateChecker()'s $checkPeriod — a checker constructed with 0 registers
 * no scheduler hooks at all and cannot be revived after the fact):
 *
 *     BLT_Family_Updates::apply(
 *         $checker,
 *         array(
 *             'basename'  => BLT_THING_BASENAME,
 *             'icons_url' => BLT_THING_URL . 'assets/img/',
 *         )
 *     );
 */
class BLT_Family_Updates {

	/**
	 * Hours between automatic checks. Also the value each plugin must pass to
	 * buildUpdateChecker(), which maps 24 onto WordPress's own 'daily' cron
	 * schedule.
	 */
	const CHECK_PERIOD_HOURS = 24;

	/**
	 * The WordPress cron recurrence a 24-hour period maps onto. Kept as a
	 * constant because the scheduled event has to be corrected to it on sites
	 * upgrading from a shorter period, not just created with it.
	 */
	const RECURRENCE = 'daily';

	/**
	 * Plugin basenames whose icons we serve, mapped to their assets/img/ URL.
	 *
	 * Collected statically so a site running several BLT plugins adds one
	 * transient filter, not one per plugin.
	 *
	 * @var array<string,string>
	 */
	private static $icon_urls = array();

	/**
	 * Whether the shared transient filter has been hooked.
	 *
	 * @var bool
	 */
	private static $icons_hooked = false;

	/**
	 * Apply the family policy to a plugin-update-checker instance.
	 *
	 * @param object $checker plugin-update-checker instance (any v5 revision).
	 * @param array  $args {
	 *     @type string $basename  Plugin basename, i.e. plugin_basename( __FILE__ ).
	 *     @type string $icons_url URL of the plugin's assets/img/ directory, trailing slash.
	 * }
	 * @return void
	 */
	public static function apply( $checker, array $args = array() ) {
		if ( ! is_object( $checker ) ) {
			return;
		}

		self::enforce_daily_floor( $checker );
		self::anchor_to_midnight( $checker );

		$basename  = isset( $args['basename'] ) ? (string) $args['basename'] : '';
		$icons_url = isset( $args['icons_url'] ) ? (string) $args['icons_url'] : '';

		if ( '' !== $basename && '' !== $icons_url ) {
			self::$icon_urls[ $basename ] = $icons_url;

			if ( ! self::$icons_hooked ) {
				self::$icons_hooked = true;
				add_filter( 'site_transient_update_plugins', array( __CLASS__, 'attach_plugin_icons' ), 20 );
			}
		}
	}

	/**
	 * Never check automatically more than once a day.
	 *
	 * @param object $checker plugin-update-checker instance.
	 * @return void
	 */
	private static function enforce_daily_floor( $checker ) {
		if ( ! method_exists( $checker, 'addFilter' ) || ! method_exists( $checker, 'getUniqueName' ) ) {
			return;
		}

		// Keep the scheduler's own idea of the period in step with the policy,
		// for the paths that read it directly.
		if ( isset( $checker->scheduler ) && is_object( $checker->scheduler ) ) {
			$checker->scheduler->checkPeriod = self::CHECK_PERIOD_HOURS;
		}

		$cron_hook = $checker->getUniqueName( 'cron_check_updates' );

		$checker->addFilter(
			'check_now',
			static function ( $should_check, $last_check, $check_period ) use ( $cron_hook ) {
				unset( $check_period );

				if ( ! $should_check ) {
					return $should_check;
				}

				// The scheduled daily event. Exempt: it fires once a day by
				// definition, and holding it to a strict 24h-since-last-check
				// would silently turn a slightly-early run into a 48h gap —
				// WP-Cron fires late, so the run after a late one lands inside
				// 24 hours of it and would be dropped.
				//
				// doing_action(), NOT current_filter(). PUC calls this filter
				// from inside Scheduler::maybeCheckForUpdates(), so by the time
				// the callback runs, current_filter() is the inner
				// puc_check_now-* filter and never the outer cron action.
				// doing_action() asks whether the action is anywhere on the
				// current stack, which is the question being asked here.
				if ( doing_action( $cron_hook ) ) {
					return true;
				}

				// "Check again" on Dashboard -> Updates: an explicit request.
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; core owns this request's nonce.
				if ( ! empty( $_GET['force-check'] ) ) {
					return true;
				}

				// Everything else is opportunistic (admin_init, the Plugins
				// screen, a completed bulk update). Matters most where WP-Cron
				// is disabled, since then this is the only path that ever runs.
				return ( time() - (int) $last_check ) >= DAY_IN_SECONDS;
			},
			10,
			3
		);
	}

	/**
	 * Anchor the daily check to 00:00 site time.
	 *
	 * plugin-update-checker deliberately scatters its first check at a random
	 * offset to spread load on the update server. Ours is GitHub, and the
	 * house policy is a predictable midnight check, so the offset is replaced
	 * with the next local midnight — both for a first schedule (via PUC's own
	 * filter) and for events already on a random offset from a previous
	 * install (by re-scheduling them).
	 *
	 * @param object $checker plugin-update-checker instance.
	 * @return void
	 */
	private static function anchor_to_midnight( $checker ) {
		if ( ! method_exists( $checker, 'getUniqueName' ) ) {
			return;
		}

		// For any LATER (re)schedule. It cannot help the first one: PUC applies
		// this filter inside the Scheduler constructor, which has already run
		// by the time buildUpdateChecker() returns and apply() is called — so
		// on a fresh activation the event is already on the library's random
		// past offset. The immediate re-anchor below is what corrects that.
		$checker->addFilter(
			'first_check_time',
			static function () {
				return self::next_midnight();
			}
		);

		$cron_hook = $checker->getUniqueName( 'cron_check_updates' );

		// Correct the event now, in the same request that created it. Cheap:
		// wp_next_scheduled() reads the autoloaded 'cron' option, already in
		// memory, and nothing is written unless the event is genuinely
		// misaligned. Without this, a site activated outside wp-admin keeps
		// PUC's random offset until an admin happens to load a screen.
		self::reanchor_event( $cron_hook );

		// And again on admin requests, to pick up a site timezone change (or an
		// event some other code rescheduled). Admin-only: front-end page loads
		// must stay free of cron-array writes.
		add_action(
			'admin_init',
			static function () use ( $cron_hook ) {
				self::reanchor_event( $cron_hook );
			},
			20
		);
	}

	/**
	 * Put an already-scheduled check on a daily recurrence at midnight.
	 *
	 * Two separate corrections, and BOTH are needed on an upgrading site:
	 *
	 *   - the time, because plugin-update-checker scatters its first check at a
	 *     random offset;
	 *   - the recurrence, because a plugin that previously ran on the default
	 *     12-hour period has a 'twicedaily' event stored, and raising
	 *     buildUpdateChecker()'s $checkPeriod to 24 does NOT migrate it. PUC
	 *     only ever schedules when nothing is scheduled (Scheduler's
	 *     `!wp_next_scheduled()` guard), so on every existing install the old
	 *     recurrence survives untouched. Re-anchoring while preserving it would
	 *     leave the plugin checking at 00:00 AND 12:00 — twice a day, while the
	 *     policy claims once.
	 *
	 * @param string $cron_hook The checker's cron hook name.
	 * @return void
	 */
	private static function reanchor_event( $cron_hook ) {
		$next = wp_next_scheduled( $cron_hook );

		if ( ! $next ) {
			return;
		}

		// Within a minute counts as anchored: WP-Cron fires late, not early, and
		// re-scheduling on every admin load would be churn for nothing.
		$offset      = self::seconds_past_midnight( (int) $next );
		$at_midnight = ( $offset < MINUTE_IN_SECONDS || $offset > ( DAY_IN_SECONDS - MINUTE_IN_SECONDS ) );
		$is_daily    = ( self::RECURRENCE === wp_get_schedule( $cron_hook ) );

		if ( $at_midnight && $is_daily ) {
			return;
		}

		wp_unschedule_event( (int) $next, $cron_hook );
		wp_schedule_event( self::next_midnight(), self::RECURRENCE, $cron_hook );
	}

	/**
	 * The next 00:00:00 in the site's timezone, as a Unix timestamp.
	 *
	 * @return int
	 */
	public static function next_midnight() {
		try {
			$now = new DateTimeImmutable( 'now', self::timezone() );

			return $now->setTime( 0, 0, 0 )->modify( '+1 day' )->getTimestamp();
		} catch ( Exception $e ) {
			return time() + DAY_IN_SECONDS;
		}
	}

	/**
	 * The site's timezone.
	 *
	 * wp_timezone() only exists from WordPress 5.3; the oldest plugin in the
	 * family still declares support below that, so fall back to the raw
	 * offset rather than fataling on an old install.
	 *
	 * @return DateTimeZone
	 */
	private static function timezone() {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}

		$offset  = (float) get_option( 'gmt_offset', 0 );
		$hours   = (int) $offset;
		$minutes = (int) round( abs( $offset - $hours ) * 60 );

		return new DateTimeZone( sprintf( '%+03d:%02d', $hours, $minutes ) );
	}

	/**
	 * How far past local midnight a timestamp falls, in seconds.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return int 0..DAY_IN_SECONDS-1
	 */
	private static function seconds_past_midnight( $timestamp ) {
		try {
			$moment   = ( new DateTimeImmutable( '@' . (int) $timestamp ) )->setTimezone( self::timezone() );
			$midnight = $moment->setTime( 0, 0, 0 );

			return $moment->getTimestamp() - $midnight->getTimestamp();
		} catch ( Exception $e ) {
			return 0;
		}
	}

	/**
	 * The nonced URL behind plugin-update-checker's "Check for Updates" link.
	 *
	 * Lets a plugin offer the same action from its own settings screen instead
	 * of sending the site owner to the Plugins page to find it. The request is
	 * handled by PUC's own Ui::handleManualCheck(), which checks the nonce and
	 * the user's capability, runs the check immediately regardless of the daily
	 * floor, and redirects to the Plugins page with the result notice.
	 *
	 * @param string $slug The checker's slug (the 3rd buildUpdateChecker() argument).
	 * @return string
	 */
	public static function check_now_url( $slug ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'puc_check_for_updates' => 1,
					'puc_slug'              => $slug,
				),
				self_admin_url( 'plugins.php' )
			),
			'puc_check_for_updates'
		);
	}

	/**
	 * When the last update check ran, as a Unix timestamp (0 if never).
	 *
	 * @param object $checker plugin-update-checker instance.
	 * @return int
	 */
	public static function last_check_time( $checker ) {
		if ( ! is_object( $checker ) || ! method_exists( $checker, 'getUpdateState' ) ) {
			return 0;
		}

		$state = $checker->getUpdateState();

		if ( ! is_object( $state ) || ! method_exists( $state, 'getLastCheck' ) ) {
			return 0;
		}

		return (int) $state->getLastCheck();
	}

	/**
	 * Show the BLT mark on BLT plugin cards in the update screens.
	 *
	 * @param mixed $transient The update_plugins site transient.
	 * @return mixed
	 */
	public static function attach_plugin_icons( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		// `response` holds plugins with an update pending, `no_update` the
		// rest; both are rendered with an icon in one screen or another.
		foreach ( array( 'response', 'no_update' ) as $bucket ) {
			if ( empty( $transient->{$bucket} ) || ! is_array( $transient->{$bucket} ) ) {
				continue;
			}

			foreach ( self::$icon_urls as $basename => $assets_url ) {
				if ( ! isset( $transient->{$bucket}[ $basename ] ) || ! is_object( $transient->{$bucket}[ $basename ] ) ) {
					continue;
				}

				$transient->{$bucket}[ $basename ]->icons = BLT_Family_Brand::icon_set( $assets_url );
			}
		}

		return $transient;
	}
}
