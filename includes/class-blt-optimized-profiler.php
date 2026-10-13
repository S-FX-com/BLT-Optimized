<?php
/**
 * STUB — replaced by Module A.
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;

class BLT_Optimized_Profiler {
	const QUERY_ARG = 'blt_optimized_profile';
	public static function create_token( array $opts = array() ) { return ''; }
	public static function maybe_start() {}
	public static function is_active() { return false; }
	public static function tweaks_disabled() { return false; }
	public static function get_result( $token ) { return null; }
}
