<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Integration\Special;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWikiIntegrationTestCase;

/**
 * Smoke tests for Special:SemanticSchemas.
 *
 * Verifies that the extension's Special pages can be loaded without
 * fatal errors (e.g. missing classes, broken DI wiring).
 *
 * @group Database
 * @covers \MediaWiki\Extension\SemanticSchemas\Special\SpecialSemanticSchemas
 */
class SpecialSemanticSchemasTest extends MediaWikiIntegrationTestCase {

	public function testSpecialPageCanBeLoaded(): void {
		$page = $this->getServiceContainer()
			->getSpecialPageFactory()
			->getPage( 'SemanticSchemas' );

		$this->assertNotNull( $page, 'Special:SemanticSchemas should be registered' );
		$this->assertInstanceOf( SpecialPage::class, $page );
	}

	public function testSpecialPageExecutesWithoutFatalError(): void {
		[ $html, ] = $this->executeSpecialPage(
			'',
			null,
			null,
			static::getTestSysop()->getAuthority()
		);

		$this->assertIsString( $html );
		$this->assertStringNotContainsString( 'Fatal error', $html );
	}

	public function testSpecialPagesListIncludesSemanticSchemas(): void {
		$list = $this->getServiceContainer()
			->getSpecialPageFactory()
			->getNames();

		$this->assertContains( 'SemanticSchemas', $list );
	}
}
