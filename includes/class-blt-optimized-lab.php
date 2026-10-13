<?php
/**
 * STUB — replaced by the lab analysis build (see SPEC-LAB.md).
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;

class BLT_Optimized_Lab {
	public static function sanitize( $raw, $url, $viewport ) { return is_array( $raw ) ? $raw : (array) json_decode( (string) $raw, true ); }
	public static function summary( array $lab ) { return array(); }
	public static function findings( array $pages ) { return array(); }
	public static function page_metrics( array $page ) { return array(); }
}
