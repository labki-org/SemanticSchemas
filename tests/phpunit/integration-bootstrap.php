<?php

/**
 * PHPUnit bootstrap for SemanticSchemas integration tests.
 *
 * Resolves the MediaWiki install path from environment or filesystem,
 * then loads MW's integration test bootstrap.
 *
 * Run via: ./tests/scripts/run-docker-tests.sh integration
 */

require_once __DIR__ . '/Traits/GenerationHelper.php';

if ( defined( 'MEDIAWIKI' ) ) {
	return;
}

// Skip the composer.lock check since we're running in Docker or CI
putenv( 'MW_SKIP_EXTERNAL_DEPENDENCIES=1' );

// Resolve MW install path
$mwPath = getenv( 'MW_INSTALL_PATH' );

if ( !$mwPath || !is_dir( $mwPath ) ) {
	// Relative to file: extensions/SemanticSchemas/tests/phpunit/ → MW root
	$mwPath = __DIR__ . '/../../../../';
}

if ( !is_dir( $mwPath ) ) {
	$mwPath = '/var/www/html';
}

$bootstrap = $mwPath . '/tests/phpunit/bootstrap.integration.php';

if ( !file_exists( $bootstrap ) ) {
	echo "ERROR: Could not find MediaWiki test bootstrap at: $bootstrap\n";
	echo "Set MW_INSTALL_PATH to your MediaWiki installation directory.\n";
	exit( 1 );
}

require_once $bootstrap;
