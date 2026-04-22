<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Integration\Store;

use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Store\PageCreator
 * @group Database
 */
class PageCreatorTest extends MediaWikiIntegrationTestCase {

	private PageCreator $pageCreator;

	protected function setUp(): void {
		parent::setUp();
		$services = $this->getServiceContainer();
		$this->pageCreator = new PageCreator(
			$services->getWikiPageFactory(),
		);
	}

	/* =========================================================================
	 * PAGE CREATION
	 * ========================================================================= */

	public function testCreateOrUpdatePageCreatesNewPage(): void {
		$title = Title::makeTitle( NS_MAIN, 'NewPage_' . uniqid() );
		$this->assertFalse( $title->exists() );

		$result = $this->pageCreator->createOrUpdatePage( $title, 'Test content', 'Test summary' );

		$this->assertTrue( $result );
		$this->assertTrue( $title->exists() );
	}

	public function testCreateOrUpdatePageWithContentVerification(): void {
		$title = Title::makeTitle( NS_MAIN, 'ContentVerify_' . uniqid() );
		$content = 'This is the expected content.';

		$this->pageCreator->createOrUpdatePage( $title, $content, 'Create for verification' );

		$actual = $this->pageCreator->getPageContent( $title );
		$this->assertSame( $content, $actual );
	}

	public function testCreateOrUpdatePageUpdatesExistingPage(): void {
		$title = Title::makeTitle( NS_MAIN, 'UpdatePage_' . uniqid() );

		$this->pageCreator->createOrUpdatePage( $title, 'Original content', 'Create' );
		$this->pageCreator->createOrUpdatePage( $title, 'Updated content', 'Update' );

		$actual = $this->pageCreator->getPageContent( $title );
		$this->assertSame( 'Updated content', $actual );
	}

	public function testCreateOrUpdatePageWithNoChangeReturnsTrue(): void {
		$title = Title::makeTitle( NS_MAIN, 'NoChangePage_' . uniqid() );
		$content = 'Same content both times';

		$this->pageCreator->createOrUpdatePage( $title, $content, 'Create' );
		$result = $this->pageCreator->createOrUpdatePage( $title, $content, 'No-op update' );

		$this->assertTrue( $result );
	}

	public function testCreateOrUpdatePageInCategoryNamespace(): void {
		$title = Title::makeTitle( NS_CATEGORY, 'TestCat_' . uniqid() );

		$result = $this->pageCreator->createOrUpdatePage( $title, 'Category page content', 'Create category' );

		$this->assertTrue( $result );
		$reloaded = Title::makeTitle( NS_CATEGORY, $title->getText() );
		$this->assertTrue( $reloaded->exists() );
	}

	public function testCreateOrUpdatePageInTemplateNamespace(): void {
		$title = Title::makeTitle( NS_TEMPLATE, 'TestTpl_' . uniqid() );

		$result = $this->pageCreator->createOrUpdatePage( $title, 'Template page content', 'Create template' );

		$this->assertTrue( $result );
		$reloaded = Title::makeTitle( NS_TEMPLATE, $title->getText() );
		$this->assertTrue( $reloaded->exists() );
	}

	/* =========================================================================
	 * PAGE CONTENT READING
	 * ========================================================================= */

	public function testGetPageContentReturnsNullForNonExistentPage(): void {
		$title = Title::makeTitle( NS_MAIN, 'NonExistentPage_' . uniqid() );

		$result = $this->pageCreator->getPageContent( $title );

		$this->assertNull( $result );
	}

	public function testGetPageContentReturnsContentForExistingPage(): void {
		$title = Title::makeTitle( NS_MAIN, 'ReadPage_' . uniqid() );
		$content = 'Content to be read back.';
		$this->pageCreator->createOrUpdatePage( $title, $content, 'Create' );

		// Verify via MW directly as ground truth
		$wikiPage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
		$mwContent = $wikiPage->getContent()->getText();
		$this->assertSame( $content, $mwContent, 'MW ground truth: stored content matches' );

		$result = $this->pageCreator->getPageContent( $title );
		$this->assertSame( $content, $result );
	}

	public function testGetPageContentPreservesWikitext(): void {
		$title = Title::makeTitle( NS_MAIN, 'WikitextPage_' . uniqid() );
		$wikitext = "== Section ==\n'''Bold''' and ''italic''\n[[Category:Test]]\n{{#set:|Has name=Foo}}";
		$this->pageCreator->createOrUpdatePage( $title, $wikitext, 'Create with wikitext' );

		$result = $this->pageCreator->getPageContent( $title );

		$this->assertSame( $wikitext, $result );
	}
}
