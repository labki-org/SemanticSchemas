<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Integration\Special;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
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
	 * Permission gating — page-level (semanticschemas-view)
	 * ========================================================================= */

	public function testPageRestrictionIsViewRight(): void {
		$page = $this->getServiceContainer()
			->getSpecialPageFactory()
			->getPage( 'SemanticSchemas' );

		$this->assertSame( 'semanticschemas-view', $page->getRestriction() );
	}

	public function testLoggedInUserCanViewByDefault(): void {
		$page = $this->getServiceContainer()
			->getSpecialPageFactory()
			->getPage( 'SemanticSchemas' );

		$this->assertTrue(
			$page->userCanExecute( static::getTestUser()->getUser() ),
			"Logged-in users should be able to view Special:SemanticSchemas by default"
		);
	}

	public function testUserWithoutViewRightIsDenied(): void {
		$this->setGroupPermissions( '*', 'semanticschemas-view', false );
		$this->setGroupPermissions( 'user', 'semanticschemas-view', false );

		$page = $this->getServiceContainer()
			->getSpecialPageFactory()
			->getPage( 'SemanticSchemas' );

		$this->assertFalse(
			$page->userCanExecute( static::getTestUser()->getUser() ),
			'Users stripped of semanticschemas-view should be denied'
		);
	}

	public function testExecuteThrowsPermissionsErrorWithoutViewRight(): void {
		$this->setGroupPermissions( '*', 'semanticschemas-view', false );
		$this->setGroupPermissions( 'user', 'semanticschemas-view', false );

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

	/* =========================================================================
	 * Permission gating — generate (semanticschemas-generate)
	 * ========================================================================= */

	public function testGenerateTabBlocksUsersWithoutGenerateRight(): void {
		$this->seedSentinelProperty();

		// Keep view, drop generate, so the user reaches the tab but cannot run it.
		$this->setGroupPermissions( '*', 'semanticschemas-generate', false );
		$this->setGroupPermissions( 'user', 'semanticschemas-generate', false );

		$page = $this->getServiceContainer()
			->getSpecialPageFactory()
			->getPage( 'SemanticSchemas' );

		$context = new RequestContext();
		$context->setUser( static::getTestUser()->getUser() );
		$context->setTitle( $page->getPageTitle() );
		$page->setContext( $context );
		$page->execute( 'generate' );

		$html = $context->getOutput()->getHTML();
		$this->assertStringContainsString(
			'permission',
			strtolower( $html ),
			'Generate tab should render a permission-denied notice for users without semanticschemas-generate'
		);
		$this->assertStringNotContainsString(
			'semski-generate-form',
			$html,
			'Generate form should not be rendered for users without the right'
		);
	}

	public function testGenerateTabRendersFormForUsersWithGenerateRight(): void {
		$this->seedSentinelProperty();

		$page = $this->getServiceContainer()
			->getSpecialPageFactory()
			->getPage( 'SemanticSchemas' );

		$context = new RequestContext();
		$context->setUser( static::getTestUser()->getUser() );
		$context->setTitle( $page->getPageTitle() );
		$page->setContext( $context );
		$page->execute( 'generate' );

		$html = $context->getOutput()->getHTML();
		$this->assertStringContainsString(
			'semski-generate-form',
			$html,
			'Generate tab should render the form for users with the right'
		);
	}

	public function testRestrictingGenerateToCustomGroupBlocksDefaultUsers(): void {
		// Take the right away from 'user' and grant it to a dedicated group.
		$this->setGroupPermissions( 'user', 'semanticschemas-generate', false );
		$this->setGroupPermissions( 'schema-editor', 'semanticschemas-generate', true );

		$plain = static::getTestUser()->getUser();
		$editor = static::getTestUser( [ 'schema-editor' ] )->getUser();

		$this->assertFalse(
			$plain->isAllowed( 'semanticschemas-generate' ),
			'Plain user should lose generate when right is revoked from "user"'
		);
		$this->assertTrue(
			$editor->isAllowed( 'semanticschemas-generate' ),
			'Members of schema-editor should retain generate'
		);
	}

	/**
	 * The Special page short-circuits on an "install base config" banner if
	 * Property:Has type does not exist. Tests that need to reach the tab
	 * dispatch must seed it.
	 */
	private function seedSentinelProperty(): void {
		$title = Title::makeTitleSafe( SMW_NS_PROPERTY, 'Has type' );
		if ( $title && !$title->exists() ) {
			$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title );
			$content = $page->getContentHandler()->makeContent( '[[Has type::Text]]', $title );
			$page->doUserEditContent(
				$content,
				static::getTestSysop()->getUser(),
				'Test fixture: seed sentinel property'
			);
		}
	}
}
