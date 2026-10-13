<?php
/**
 * STUB — replaced by Module E.
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;

class BLT_Optimized_Tweaks {
	const OPTION = 'blt_optimized_tweaks';
	public function __construct() {}
	public static function defaults() { return array( 'rules' => array() ); }
	public static function get() { return self::defaults(); }
	public static function sanitize( $input ) { return self::defaults(); }
	public static function rules() { return array(); }
	public static function bypassed() { return false; }
}
