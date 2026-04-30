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

	/* =========================================================================
	 * Permission gating
	 * ========================================================================= */

	public function testRestrictionIsManageRight(): void {
		$page = $this->getServiceContainer()
			->getSpecialPageFactory()
			->getPage( 'SemanticSchemas' );

		$this->assertSame( 'semanticschemas-manage', $page->getRestriction() );
	}

	public function testSysopHasManageRightByDefault(): void {
		$page = $this->getServiceContainer()
			->getSpecialPageFactory()
			->getPage( 'SemanticSchemas' );

		$this->assertTrue(
			$page->userCanExecute( static::getTestSysop()->getUser() ),
			'Sysops should be able to execute Special:SemanticSchemas by default'
		);
	}

	public function testRegularUserCannotExecuteWithoutManageRight(): void {
		// Make sure no implicit group grants the right.
		$this->setGroupPermissions( '*', 'semanticschemas-manage', false );
		$this->setGroupPermissions( 'user', 'semanticschemas-manage', false );

		$page = $this->getServiceContainer()
			->getSpecialPageFactory()
			->getPage( 'SemanticSchemas' );

		$this->assertFalse(
			$page->userCanExecute( static::getTestUser()->getUser() ),
			'Regular users without the manage right should be denied'
		);
	}

	public function testGrantingManageRightToCustomGroupAllowsAccess(): void {
		$this->setGroupPermissions( 'schema-editor', 'semanticschemas-manage', true );

		$page = $this->getServiceContainer()
			->getSpecialPageFactory()
			->getPage( 'SemanticSchemas' );

		$user = static::getTestUser( [ 'schema-editor' ] )->getUser();

		$this->assertTrue(
			$page->userCanExecute( $user ),
			'Users in a group granted semanticschemas-manage should be allowed'
		);
	}

	public function testExecuteThrowsPermissionsErrorForUnprivilegedUser(): void {
		$this->setGroupPermissions( '*', 'semanticschemas-manage', false );
		$this->setGroupPermissions( 'user', 'semanticschemas-manage', false );

		$page = $this->getServiceContainer()
			->getSpecialPageFactory()
			->getPage( 'SemanticSchemas' );

		$context = new RequestContext();
		$context->setUser( static::getTestUser()->getUser() );
		$context->setTitle( $page->getPageTitle() );
		$page->setContext( $context );

		$this->expectException( \PermissionsError::class );
		$page->execute( '' );
	}
}
