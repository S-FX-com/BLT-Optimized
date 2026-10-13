<?php
/**
 * STUB — replaced by Module D.
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;

class BLT_Optimized_Media_Auditor {
	public function __construct( BLT_Optimized_Audit_Log $audit_log ) {}
	public function start() { return array( 'status' => 'completed' ); }
	public function tick( $budget = 15.0 ) { return array( 'status' => 'completed' ); }
	public function get_state() { return array( 'status' => 'idle' ); }
	public function cancel() {}
	public function get_results() { return null; }
	public function findings() { return array(); }
}
