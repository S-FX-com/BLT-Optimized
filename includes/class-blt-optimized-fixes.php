<?php
/**
 * STUB — replaced by Module E.
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;

class BLT_Optimized_Fixes {
	public function __construct( BLT_Optimized $plugin ) {}
	public function apply( $action, array $args ) { return new WP_Error( 'stub', 'stub' ); }
	public static function actions() { return array(); }
	public static function is_destructive( $action ) { return true; }
	public static function autoload_changes() { return array(); }
}
