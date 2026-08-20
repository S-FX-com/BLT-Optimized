<?php
/**
 * Standalone smoke test for the BLT family library.
 *
 * Shims just enough WordPress to exercise the real code paths: version
 * election, registration, the opt-in gate, encrypted round-trips, the store's
 * blank/clear semantics, validation, and the update-policy maths.
 *
 * Run: php blt-family-smoke.php
 */

// CLI only. This file defines ABSPATH itself and runs on include, so without
// this guard a plugin zip that happened to ship it would answer an
// unauthenticated GET with the whole suite — the library's internals, the
// group/field schema and the opt-in semantics — at a guessable URL. Packaging
// exclusions are the other half of the defence; this is the half that holds
// even when a zip is built by hand.
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['opts']    = array();
$GLOBALS['filters'] = array();
$GLOBALS['actions'] = array();
$GLOBALS['trans']   = array();
$GLOBALS['cron']       = array();
$GLOBALS['recurrence'] = array();

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$same                     = array_key_exists( $name, $GLOBALS['opts'] ) && $GLOBALS['opts'][ $name ] === $value;
	$GLOBALS['opts'][ $name ] = $value;
	return ! $same;
}
function delete_option( $name ) {
	unset( $GLOBALS['opts'][ $name ] );
	return true;
}
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['filters'][ $tag ][] = $cb;
	return true;
}
function apply_filters( $tag, $value ) {
	$extra = array_slice( func_get_args(), 2 );
	foreach ( ( isset( $GLOBALS['filters'][ $tag ] ) ? $GLOBALS['filters'][ $tag ] : array() ) as $cb ) {
		$value = call_user_func_array( $cb, array_merge( array( $value ), $extra ) );
	}
	return $value;
}
function add_action( $tag, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['actions'][ $tag ][] = $cb;
	return true;
}
function do_action( $tag ) {
	foreach ( ( isset( $GLOBALS['actions'][ $tag ] ) ? $GLOBALS['actions'][ $tag ] : array() ) as $cb ) {
		call_user_func( $cb );
	}
}
function current_filter() {
	return $GLOBALS['current_filter'];
}
function wp_salt( $scheme = 'auth' ) {
	return 'smoke-salt-' . $scheme;
}
function is_admin() {
	return false;
}
function wp_timezone() {
	return new DateTimeZone( 'America/Chicago' );
}
function set_transient( $k, $v, $t = 0 ) {
	$GLOBALS['trans'][ $k ] = $v;
	return true;
}
function get_transient( $k ) {
	return isset( $GLOBALS['trans'][ $k ] ) ? $GLOBALS['trans'][ $k ] : false;
}
function delete_transient( $k ) {
	unset( $GLOBALS['trans'][ $k ] );
	return true;
}
function __( $s, $d = null ) {
	return $s; }
function esc_html__( $s, $d = null ) {
	return $s; }
function esc_attr__( $s, $d = null ) {
	return $s; }
function _n( $a, $b, $n, $d = null ) {
	return 1 === $n ? $a : $b; }
function trailingslashit( $s ) {
	return rtrim( $s, '/\\' ) . '/'; }
function plugin_dir_url( $f ) {
	return 'https://example.test/wp-content/plugins/thing/'; }
function wp_next_scheduled( $h ) {
	return isset( $GLOBALS['cron'][ $h ] ) ? $GLOBALS['cron'][ $h ] : false; }
function wp_get_schedule( $h ) {
	return isset( $GLOBALS['recurrence'][ $h ] ) ? $GLOBALS['recurrence'][ $h ] : false; }
function wp_unschedule_event( $t, $h ) {
	unset( $GLOBALS['cron'][ $h ], $GLOBALS['recurrence'][ $h ] );
	return true; }
function wp_schedule_event( $t, $r, $h ) {
	$GLOBALS['cron'][ $h ]       = $t;
	$GLOBALS['recurrence'][ $h ] = $r;
	return true; }
function wp_nonce_url( $u, $a ) {
	return $u . '&_wpnonce=x'; }
function add_query_arg( $args, $url = '' ) {
	return $url . '?' . http_build_query( $args ); }
function self_admin_url( $p ) {
	return 'https://example.test/wp-admin/' . $p; }
function sanitize_text_field( $s ) {
	return trim( strip_tags( (string) $s ) ); }
function wp_kses( $s, $allowed ) {
	return $s; }

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message; }
	public function get_error_code() {
		return $this->code; }
}
function is_wp_error( $t ) {
	return $t instanceof WP_Error; }

/* ------------------------------------------------------------------ */

$pass = 0;
$fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) {
		++$pass;
		echo "  ok   $label\n";
	} else {
		++$fail;
		echo "  FAIL $label\n";
	}
}

echo "== version election + registration ==\n";
require __DIR__ . '/includes/blt-family/bootstrap.php';

ok( class_exists( 'BLT_Family_Brand' ), 'Brand loads eagerly (needed during plugin load)' );
ok( class_exists( 'BLT_Family_Updates' ), 'Updates loads eagerly (needed during plugin load)' );
ok( ! class_exists( 'BLT_Family', false ), 'BLT_Family deferred until the election' );

blt_family_register(
	'/plugins/blt-events/blt-events.php',
	array(
		'name'    => 'BLT Events',
		'slug'    => 'blt-events',
		'groups'  => array( 'stripe', 'surecart', 'microsoft', 'google' ),
		'version' => '2.2.6',
	)
);
blt_family_register(
	'/plugins/blt-secure/blt-secure.php',
	array(
		'name'   => 'BLT Secure',
		'slug'   => 'blt-secure',
		'groups' => array( 'github', 'cloudflare' ),
	)
);
// A duplicate registration must not create a second row.
blt_family_register( '/plugins/blt-secure/blt-secure.php', array( 'name' => 'BLT Secure', 'slug' => 'blt-secure', 'groups' => array( 'github', 'cloudflare' ) ) );

ok( 2 === count( blt_family_plugins() ), 'duplicate registration is idempotent (2 plugins)' );
ok( 'blt-events' === blt_family_plugins()['blt-events']['update_slug'], 'update_slug defaults to slug' );

// Simulate a second, NEWER copy of the library nominating itself.
$GLOBALS['blt_family_lib']['candidates'][] = array(
	'version' => '9.9.9',
	'loader'  => '/does/not/exist/loader.php',
);
blt_family_boot();
ok( class_exists( 'BLT_Family' ), 'unreadable newer candidate is skipped; a readable one still boots' );
ok( 2 === BLT_Family::count(), 'registry handed to BLT_Family' );
ok( BLT_Family::is_multi_plugin(), 'multi-plugin detected' );

echo "\n== the opt-in gate ==\n";
BLT_Family_Store::set_group( 'stripe', array( 'secret_key' => 'sk_live_ABC', 'publishable_key' => 'pk_live_ABC' ) );
ok( 'sk_live_ABC' === BLT_Family_Store::get( 'stripe', 'secret_key' ), 'encrypted secret round-trips through the store' );
ok( 'bf1:' === substr( get_option( 'blt_family_shared' )['stripe']['secret_key'], 0, 4 ), 'secret is enveloped at rest' );
ok( 'pk_live_ABC' === get_option( 'blt_family_shared' )['stripe']['publishable_key'], 'non-secret stored in the clear' );

ok( '' === BLT_Family::get( 'blt-events', 'stripe', 'secret_key' ), 'NOT readable before opt-in' );
BLT_Family::set_opted_in( 'blt-events', array( 'stripe' ) );
ok( 'sk_live_ABC' === BLT_Family::get( 'blt-events', 'stripe', 'secret_key' ), 'readable after opt-in' );
ok( '' === BLT_Family::get( 'blt-secure', 'stripe', 'secret_key' ), 'other plugin still gated (never declared stripe)' );
ok( '' === BLT_Family::get( 'blt-events', 'github', 'token' ), 'undeclared group refused even if opted in elsewhere' );

BLT_Family::set_opted_in( 'blt-events', array( 'github' ) );
ok( array() === BLT_Family::opted_in( 'blt-events' ), 'opting in to an undeclared group is rejected outright' );

echo "\n== store semantics ==\n";
BLT_Family::set_opted_in( 'blt-events', array( 'stripe' ) );
BLT_Family_Store::set_group( 'stripe', array( 'publishable_key' => 'pk_live_XYZ' ) );
ok( 'sk_live_ABC' === BLT_Family_Store::get( 'stripe', 'secret_key' ), 'a field omitted from set_group() is left untouched' );
BLT_Family_Store::set_group( 'stripe', array( 'secret_key' => '' ) );
ok( '' === BLT_Family_Store::get( 'stripe', 'secret_key' ), 'explicit empty clears a field' );
BLT_Family_Store::set_group( 'stripe', array( 'bogus_field' => 'x' ) );
ok( ! isset( get_option( 'blt_family_shared' )['stripe']['bogus_field'] ), 'unknown field is dropped, not persisted' );
ok( false === BLT_Family_Store::group_configured( 'nope' ), 'unknown group is not "configured"' );

echo "\n== validation ==\n";
ok( is_wp_error( BLT_Family_Groups::validate( 'stripe', 'publishable_key', 'sk_live_OOPS' ) ), 'sk_ in the publishable field is rejected' );
ok( is_wp_error( BLT_Family_Groups::validate( 'stripe', 'secret_key', 'pk_live_OOPS' ) ), 'pk_ in the secret field is rejected' );
ok( 'pk_live_OK' === BLT_Family_Groups::validate( 'stripe', 'publishable_key', 'pk_live_OK' ), 'correct prefix passes' );
ok( true === BLT_Family_Groups::is_secret( 'stripe', 'typo_field' ), 'unknown field fails closed (treated as secret)' );
ok( false === BLT_Family_Groups::is_secret( 'stripe', 'publishable_key' ), 'publishable key is not a secret' );

echo "\n== crypto ==\n";
$round = BLT_Family_Crypto::decrypt( BLT_Family_Crypto::encrypt( 'hunter2' ) );
ok( 'hunter2' === $round, 'encrypt/decrypt round-trip' );
ok( '' === BLT_Family_Crypto::encrypt( '' ), 'empty input yields empty envelope' );
ok( 'legacy-plain' === BLT_Family_Crypto::decrypt( 'legacy-plain' ), 'unrecognized value passes through (legacy plaintext)' );
ok( BLT_Family_Crypto::is_strong(), 'an AEAD backend is available here' );

echo "\n== the documents/image-worker trap ==\n";
$groups = BLT_Family_Groups::all();
ok( ! isset( $groups['worker'] ), 'no generic worker group exists' );
ok( isset( $groups['image_worker']['fields']['worker_url'] ), 'the worker pair lives only under image_worker' );
ok( array() === BLT_Family::consumers( 'image_worker' ), 'no registered plugin here consumes it, so it renders nowhere' );

echo "\n== update policy ==\n";
$next = BLT_Family_Updates::next_midnight();
$dt   = ( new DateTimeImmutable( '@' . $next ) )->setTimezone( wp_timezone() );
ok( '00:00:00' === $dt->format( 'H:i:s' ), 'next_midnight() lands on 00:00:00 site time (got ' . $dt->format( 'Y-m-d H:i:s T' ) . ')' );
ok( $next > time(), 'next_midnight() is in the future' );

class Fake_Scheduler {
	public $checkPeriod = 12; // phpcs:ignore
}
class Fake_Checker {
	public $scheduler;
	public $filters = array();
	public function __construct() {
		$this->scheduler = new Fake_Scheduler(); }
	public function getUniqueName( $tag ) {
		return 'puc_' . $tag . '-blt-thing'; }
	public function addFilter( $tag, $cb, $p = 10, $a = 1 ) {
		add_filter( $this->getUniqueName( $tag ), $cb, $p, $a );
		$this->filters[] = $tag; }
	public function getUpdateState() {
		return null; }
}

$GLOBALS['cron']['puc_cron_check_updates-blt-thing']       = strtotime( '2026-08-21 14:37:00 UTC' );
$GLOBALS['recurrence']['puc_cron_check_updates-blt-thing'] = 'daily';
$checker                                            = new Fake_Checker();
BLT_Family_Updates::apply( $checker, array( 'basename' => 'blt-thing/blt-thing.php', 'icons_url' => 'https://example.test/img/' ) );

ok( 24 === $checker->scheduler->checkPeriod, 'checkPeriod forced to 24h' );
ok( in_array( 'check_now', $checker->filters, true ), 'check_now filter installed' );
ok( in_array( 'first_check_time', $checker->filters, true ), 'first_check_time filter installed' );

// The floor.
$hook                      = 'puc_check_now-blt-thing';
$GLOBALS['current_filter'] = 'admin_init';
ok( false === apply_filters( $hook, true, time() - 3600, 24 ), 'blocks an opportunistic check 1h after the last one' );
ok( true === apply_filters( $hook, true, time() - 90000, 24 ), 'allows it once 24h have elapsed' );
ok( false === apply_filters( $hook, false, 0, 24 ), 'never widens a false decision' );

$GLOBALS['current_filter'] = 'puc_cron_check_updates-blt-thing';
ok( true === apply_filters( $hook, true, time() - 86399, 24 ), 'the daily cron event is exempt (no 48h gap)' );

$GLOBALS['current_filter'] = 'load-update-core.php';
$_GET['force-check']       = '1';
ok( true === apply_filters( $hook, true, time() - 5, 24 ), '"Check again" (force-check) bypasses the floor' );
unset( $_GET['force-check'] );
ok( false === apply_filters( $hook, true, time() - 5, 24 ), 'merely opening Dashboard -> Updates does not' );

// Re-anchoring an existing randomly-offset event.
$GLOBALS['current_filter'] = 'admin_init';
do_action( 'admin_init' );
$anchored = ( new DateTimeImmutable( '@' . $GLOBALS['cron']['puc_cron_check_updates-blt-thing'] ) )->setTimezone( wp_timezone() );
ok( '00:00:00' === $anchored->format( 'H:i:s' ), 're-anchored an existing off-midnight event (now ' . $anchored->format( 'Y-m-d H:i:s' ) . ')' );
ok( 'daily' === $GLOBALS['recurrence']['puc_cron_check_updates-blt-thing'], 're-anchored event recurs daily' );

$before = $GLOBALS['cron']['puc_cron_check_updates-blt-thing'];
do_action( 'admin_init' );
ok( $before === $GLOBALS['cron']['puc_cron_check_updates-blt-thing'], 'an already-anchored event is left alone (no churn)' );

// A site upgrading from a 12-hour check period already has a 'twicedaily'
// event. Passing 24 to buildUpdateChecker() does NOT migrate it — PUC only
// schedules when nothing is scheduled — so the recurrence must be corrected
// here or the plugin keeps checking twice a day while claiming once.
$GLOBALS['cron']['puc_cron_check_updates-blt-thing']       = strtotime( '2026-08-21 00:00:00 America/Chicago' );
$GLOBALS['recurrence']['puc_cron_check_updates-blt-thing'] = 'twicedaily';
do_action( 'admin_init' );
ok(
	'daily' === $GLOBALS['recurrence']['puc_cron_check_updates-blt-thing'],
	'a legacy twicedaily event is migrated to daily even when already at midnight'
);
$still = ( new DateTimeImmutable( '@' . $GLOBALS['cron']['puc_cron_check_updates-blt-thing'] ) )->setTimezone( wp_timezone() );
ok( '00:00:00' === $still->format( 'H:i:s' ), 'and stays anchored to midnight' );

// Icons.
$transient            = new stdClass();
$transient->no_update = array( 'blt-thing/blt-thing.php' => new stdClass() );
$transient->response  = array();
$out                  = BLT_Family_Updates::attach_plugin_icons( $transient );
ok( isset( $out->no_update['blt-thing/blt-thing.php']->icons['2x'] ), 'BLT icons attached to the update transient' );
ok( 'not an object' === BLT_Family_Updates::attach_plugin_icons( 'not an object' ), 'non-object transient passed through' );

ok( false !== strpos( BLT_Family_Updates::check_now_url( 'blt-thing' ), 'puc_check_for_updates=1' ), 'manual-check URL carries PUC arg' );
ok( false !== strpos( BLT_Family_Updates::check_now_url( 'blt-thing' ), '_wpnonce' ), 'manual-check URL is nonced' );

echo "\n== deactivating a sibling plugin ==\n";
BLT_Family_Store::set_group( 'stripe', array( 'secret_key' => 'sk_live_ABC' ) );
$GLOBALS['blt_family_lib']['plugins'] = array( 'blt-events' => blt_family_plugins()['blt-events'] );
$r                                    = new ReflectionClass( 'BLT_Family' );
$prop                                 = $r->getProperty( 'booted' );
$prop->setAccessible( true );
$prop->setValue( null, false );
BLT_Family::boot( $GLOBALS['blt_family_lib']['plugins'] );
ok( ! BLT_Family::is_multi_plugin(), 'a lone BLT plugin is not "multi" (so the shared screen disappears)' );
ok(
	'sk_live_ABC' === BLT_Family::get( 'blt-events', 'stripe', 'secret_key' ),
	'an opt-in already granted survives a sibling being deactivated'
);
ok( '' === BLT_Family::get( 'blt-secure', 'stripe', 'secret_key' ), 'an unregistered plugin still resolves to nothing' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
