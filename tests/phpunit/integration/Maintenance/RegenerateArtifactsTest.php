<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Integration\Maintenance;

use MediaWiki\Extension\SemanticSchemas\Maintenance\RegenerateArtifacts;
use MediaWiki\Maintenance\Maintenance;
use MediaWikiIntegrationTestCase;
use Wikimedia\TestingAccessWrapper;

require_once __DIR__ . '/../../../../maintenance/regenerateArtifacts.php';

/**
 * Smoke tests for the regenerateArtifacts.php maintenance script.
 *
 * Verifies that the script can execute without fatal errors
 * (e.g. missing classes, broken method signatures, bad DI wiring).
 *
 * @group Database
 * @covers \MediaWiki\Extension\SemanticSchemas\Maintenance\RegenerateArtifacts
 */
class RegenerateArtifactsTest extends MediaWikiIntegrationTestCase {

	public function testExecutesWithoutFatalError(): void {
		$maintenance = new RegenerateArtifacts();
		$maintenance->setName( 'regenerateArtifacts' );

		// Capture output instead of printing
		ob_start();
		$maintenance->execute();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Regeneration complete', $output );
	}

	public function testHandlesMissingCategory(): void {
		$maintenance = new RegenerateArtifacts();
		$maintenance->setName( 'regenerateArtifacts' );
		// MW 1.46+ only turns fatalError() into a throw when isTesting is
		// set (MaintenanceBaseTestCase does this); otherwise it exit()s and
		// takes the whole PHPUnit run down with it.
		if ( property_exists( Maintenance::class, 'isTesting' ) ) {
			TestingAccessWrapper::newFromObject( $maintenance )->isTesting = true;
		}
		$maintenance->loadParamsAndArgs(
			null, [ 'category' => 'NonExistentCategory12345' ]
		);

		$this->expectException( \Exception::class );

		ob_start();
		try {
			$maintenance->execute();
		} finally {
			ob_end_clean();
		}
	}
}
