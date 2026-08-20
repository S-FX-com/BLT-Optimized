<?php
/**
 * Loads the elected copy of the BLT family library.
 *
 * Required exactly once, by blt_family_boot() in bootstrap.php, from whichever
 * copy on the site won the version election. Never require this directly — go
 * through bootstrap.php, or two plugins will race to define these classes.
 *
 * BLT_Family_Brand and BLT_Family_Updates are deliberately absent here:
 * bootstrap.php loads those two eagerly because plugins need them while still
 * loading. See the note at the top of that file.
 *
 * @package BLT_Family
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// class_exists guards, not require_once alone: on a site with several BLT
// plugins, an older copy of the library may already have declared these before
// the election ran (for instance from a plugin that boots outside the normal
// order), and redeclaring a class is a fatal error, not a warning.
if ( ! class_exists( 'BLT_Family_Crypto' ) ) {
	require_once __DIR__ . '/class-blt-family-crypto.php';
}

if ( ! class_exists( 'BLT_Family_Groups' ) ) {
	require_once __DIR__ . '/class-blt-family-groups.php';
}

if ( ! class_exists( 'BLT_Family_Store' ) ) {
	require_once __DIR__ . '/class-blt-family-store.php';
}

if ( ! class_exists( 'BLT_Family_Admin' ) ) {
	require_once __DIR__ . '/class-blt-family-admin.php';
}

if ( ! class_exists( 'BLT_Family' ) ) {
	require_once __DIR__ . '/class-blt-family.php';
}
