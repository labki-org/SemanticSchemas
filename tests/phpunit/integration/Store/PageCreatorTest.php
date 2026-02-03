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
		// Use null to let PageCreator create a system user
		$this->pageCreator = new PageCreator( null );
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
	 * PAGE EXISTENCE
	 * ========================================================================= */

	public function testPageExistsReturnsFalseForNonExistentPage(): void {
		$title = Title::makeTitle( NS_MAIN, 'NonExistentPage_' . uniqid() );

		$this->assertFalse( $title->exists(), 'Pre-condition: MW confirms page does not exist' );
		$this->assertFalse( $this->pageCreator->pageExists( $title ) );
	}

	public function testPageExistsReturnsTrueForExistingPage(): void {
		$title = Title::makeTitle( NS_MAIN, 'ExistsPage_' . uniqid() );
		$this->pageCreator->createOrUpdatePage( $title, 'Some content', 'Create' );
		$this->assertTrue( $title->exists(), 'Pre-condition: MW confirms page exists' );

		$this->assertTrue( $this->pageCreator->pageExists( $title ) );
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

	/* =========================================================================
	 * TITLE CREATION
	 * ========================================================================= */

	public function testMakeTitleReturnsValidTitle(): void {
		$title = $this->pageCreator->makeTitle( 'TestPage', NS_MAIN );

		$this->assertInstanceOf( Title::class, $title );
		$this->assertEquals( 'TestPage', $title->getText() );
		$this->assertEquals( NS_MAIN, $title->getNamespace() );
	}

	public function testMakeTitleReturnsNullForEmptyText(): void {
		$title = $this->pageCreator->makeTitle( '', NS_MAIN );

		$this->assertNull( $title );
	}

	public function testMakeTitleTrimsWhitespace(): void {
		$title = $this->pageCreator->makeTitle( '  TestPage  ', NS_MAIN );

		$this->assertInstanceOf( Title::class, $title );
		$this->assertEquals( 'TestPage', $title->getText() );
	}

	public function testMakeTitleReturnsNullForWhitespaceOnly(): void {
		$title = $this->pageCreator->makeTitle( '   ', NS_MAIN );

		$this->assertNull( $title );
	}

	public function testMakeTitleWorksWithCategoryNamespace(): void {
		$title = $this->pageCreator->makeTitle( 'TestCategory', NS_CATEGORY );

		$this->assertInstanceOf( Title::class, $title );
		$this->assertEquals( NS_CATEGORY, $title->getNamespace() );
	}

	public function testMakeTitleWorksWithTemplateNamespace(): void {
		$title = $this->pageCreator->makeTitle( 'TestTemplate', NS_TEMPLATE );

		$this->assertInstanceOf( Title::class, $title );
		$this->assertEquals( NS_TEMPLATE, $title->getNamespace() );
	}

	/* =========================================================================
	 * TITLE FROM PAGE NAME
	 * ========================================================================= */

	public function testTitleFromPageNameWithCategoryPrefix(): void {
		$title = $this->pageCreator->titleFromPageName( 'Category:TestCategory' );

		$this->assertInstanceOf( Title::class, $title );
		$this->assertEquals( NS_CATEGORY, $title->getNamespace() );
		$this->assertEquals( 'TestCategory', $title->getText() );
	}

	public function testTitleFromPageNameWithPropertyPrefix(): void {
		$title = $this->pageCreator->titleFromPageName( 'Property:Has name' );

		$this->assertInstanceOf( Title::class, $title );
		$this->assertEquals( SMW_NS_PROPERTY, $title->getNamespace() );
		$this->assertEquals( 'Has name', $title->getText() );
	}

	public function testTitleFromPageNameWithoutPrefix(): void {
		$title = $this->pageCreator->titleFromPageName( 'SimplePage' );

		$this->assertInstanceOf( Title::class, $title );
		$this->assertEquals( NS_MAIN, $title->getNamespace() );
	}

	public function testTitleFromPageNameReturnsNullForEmpty(): void {
		$title = $this->pageCreator->titleFromPageName( '' );

		$this->assertNull( $title );
	}

	/* =========================================================================
	 * PAGE DELETION
	 * ========================================================================= */

	public function testDeletePageRemovesExistingPage(): void {
		$title = Title::makeTitle( NS_MAIN, 'DeleteMe_' . uniqid() );
		$this->pageCreator->createOrUpdatePage( $title, 'To be deleted', 'Create' );
		$this->assertTrue( $title->exists(), 'Pre-condition: page was created' );

		$result = $this->pageCreator->deletePage( $title, 'Test deletion' );

		$this->assertTrue( $result );
		// Title::exists() caches; re-fetch to get fresh state
		$freshTitle = Title::makeTitle( $title->getNamespace(), $title->getDBkey() );
		$this->assertFalse( $freshTitle->exists() );
	}

	public function testDeletePageReturnsTrueForNonExistentPage(): void {
		$title = Title::makeTitle( NS_MAIN, 'NonExistentPage_' . uniqid() );

		$result = $this->pageCreator->deletePage( $title, 'No-op deletion' );

		$this->assertTrue( $result );
	}

	/* =========================================================================
	 * MARKER-BASED UPDATES
	 * ========================================================================= */

	public function testUpdateWithinMarkersReplacesContent(): void {
		$startMarker = '<!-- START -->';
		$endMarker = '<!-- END -->';
		$existingContent = "Header\n$startMarker\nOld content\n$endMarker\nFooter";
		$newText = 'New content';

		$result = $this->pageCreator->updateWithinMarkers(
			$existingContent,
			$newText,
			$startMarker,
			$endMarker
		);

		$this->assertStringContainsString( 'Header', $result );
		$this->assertStringContainsString( 'Footer', $result );
		$this->assertStringContainsString( 'New content', $result );
		$this->assertStringNotContainsString( 'Old content', $result );
	}

	public function testUpdateWithinMarkersAppendsIfNoMarkers(): void {
		$startMarker = '<!-- START -->';
		$endMarker = '<!-- END -->';
		$existingContent = 'Existing content without markers';
		$newText = 'New content';

		$result = $this->pageCreator->updateWithinMarkers(
			$existingContent,
			$newText,
			$startMarker,
			$endMarker
		);

		$this->assertStringContainsString( 'Existing content without markers', $result );
		$this->assertStringContainsString( $startMarker, $result );
		$this->assertStringContainsString( 'New content', $result );
		$this->assertStringContainsString( $endMarker, $result );
	}

	public function testUpdateWithinMarkersPreservesContentBeforeAndAfter(): void {
		$startMarker = '<!-- SemanticSchemas Start -->';
		$endMarker = '<!-- SemanticSchemas End -->';
		$existingContent = "Before\n$startMarker\nOld\n$endMarker\nAfter";
		$newText = 'Updated';

		$result = $this->pageCreator->updateWithinMarkers(
			$existingContent,
			$newText,
			$startMarker,
			$endMarker
		);

		// Content before and after markers should be preserved
		$this->assertStringStartsWith( 'Before', $result );
		$this->assertStringEndsWith( "After\n", trim( $result ) . "\n" );
	}

	/* =========================================================================
	 * ERROR HANDLING
	 * ========================================================================= */

	public function testGetLastErrorReturnsNullOnSuccess(): void {
		$title = Title::makeTitle( NS_MAIN, 'SuccessPage_' . uniqid() );

		$this->pageCreator->createOrUpdatePage( $title, 'Content', 'Create' );

		$this->assertNull( $this->pageCreator->getLastError() );
	}

	public function testGetLastErrorReturnsMessageOnFailure(): void {
		// NS_SPECIAL pages cannot be created — this should trigger an error
		$title = Title::makeTitle( NS_SPECIAL, 'CannotCreate_' . uniqid() );

		// Suppress the E_USER_WARNING from wfLogWarning so PHPUnit doesn't treat it as an error
		set_error_handler( static function () {
		}, E_USER_WARNING );
		$result = $this->pageCreator->createOrUpdatePage( $title, 'Content', 'Create' );
		restore_error_handler();

		$this->assertFalse( $result );
		$this->assertNotNull( $this->pageCreator->getLastError() );
	}
}
