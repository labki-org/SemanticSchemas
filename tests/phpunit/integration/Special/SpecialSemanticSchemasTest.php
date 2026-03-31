<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Integration\Special;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWikiIntegrationTestCase;
use RequestContext;

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

	public function testSpecialPageExecutesWithoutError(): void {
		$page = $this->getServiceContainer()
			->getSpecialPageFactory()
			->getPage( 'SemanticSchemas' );

		$context = new RequestContext();
		$context->setUser( static::getTestSysop()->getUser() );
		$context->setTitle( $page->getPageTitle() );
		$page->setContext( $context );

		// If execute() throws, PHPUnit catches it as a test failure.
		$page->execute( '' );

		$html = $context->getOutput()->getHTML();
		$this->assertIsString( $html );
	}

	public function testSpecialPagesListIncludesSemanticSchemas(): void {
		$list = $this->getServiceContainer()
			->getSpecialPageFactory()
			->getNames();

		$this->assertContains( 'SemanticSchemas', $list );
	}
}
