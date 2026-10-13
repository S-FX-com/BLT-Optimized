<?php
/**
 * STUB — replaced by Module G.
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;

class BLT_Optimized_PSI {
	public function is_enabled() { return false; }
	public function run( $url, $strategy = 'mobile' ) { return new WP_Error( 'stub', 'stub' ); }
	public static function findings( array $results ) { return array(); }
}
