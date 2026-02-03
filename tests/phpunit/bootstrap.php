<?php

/**
 * PHPUnit bootstrap file for SemanticSchemas tests.
 *
 * This file sets up the autoloader for running unit tests outside of MediaWiki.
 * These tests are designed to be self-contained and not require a full MW installation.
 */

// Load Composer autoloader
require_once __DIR__ . '/../../vendor/autoload.php';

// Define MediaWiki constants that tests might need
if ( !defined( 'NS_TEMPLATE' ) ) {
	define( 'NS_TEMPLATE', 10 );
}
if ( !defined( 'NS_MEDIAWIKI' ) ) {
	define( 'NS_MEDIAWIKI', 8 );
}
if ( !defined( 'NS_CATEGORY' ) ) {
	define( 'NS_CATEGORY', 14 );
}
if ( !defined( 'NS_SUBOBJECT' ) ) {
	define( 'NS_SUBOBJECT', 3300 );
}
if ( !defined( 'TS_ISO_8601' ) ) {
	define( 'TS_ISO_8601', 4 );
}

// Mock wfLogWarning if not defined (used by some classes)
if ( !function_exists( 'wfLogWarning' ) ) {
	function wfLogWarning( $msg ) {
		// Silent in tests
	}
}

// Stub Title class for mock creation in unit tests.
// This bootstrap only runs in the standalone unit test environment,
// so the real MW Title class will never be loaded.
if ( !class_exists( 'MediaWiki\\Title\\Title', false ) ) {
	require_once __DIR__ . '/stubs/Title.php';
}

// Mock wfTimestamp if not defined (used by StateManager)
if ( !function_exists( 'wfTimestamp' ) ) {
	function wfTimestamp( $type, $ts = null ) {
		if ( $ts === null ) {
			$ts = time();
		}
		// TS_ISO_8601 format
		return gmdate( 'Y-m-d\TH:i:s\Z', $ts );
	}
}
