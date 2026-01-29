<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Integration\Store;

use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
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
		$this->markTestSkipped( 'Parsoid compatibility issue in Docker image' );
	}

	public function testCreateOrUpdatePageWithContentVerification(): void {
		$this->markTestSkipped( 'Parsoid compatibility issue in Docker image' );
	}

	public function testCreateOrUpdatePageUpdatesExistingPage(): void {
		$this->markTestSkipped( 'Parsoid compatibility issue in Docker image' );
	}

	public function testCreateOrUpdatePageWithNoChangeReturnsTrue(): void {
		$this->markTestSkipped( 'Parsoid compatibility issue in Docker image' );
	}

	public function testCreateOrUpdatePageInCategoryNamespace(): void {
		$this->markTestSkipped( 'Parsoid compatibility issue in Docker image' );
	}

	public function testCreateOrUpdatePageInTemplateNamespace(): void {
		$this->markTestSkipped( 'Parsoid compatibility issue in Docker image' );
	}

	/* =========================================================================
	 * PAGE EXISTENCE
	 * ========================================================================= */

	public function testPageExistsReturnsFalseForNonExistentPage(): void {
		$title = Title::makeTitle( NS_MAIN, 'NonExistentPage_' . uniqid() );

		$result = $this->pageCreator->pageExists( $title );

		$this->assertFalse( $result );
	}

	public function testPageExistsReturnsTrueForExistingPage(): void {
		$this->markTestSkipped( 'Parsoid compatibility issue in Docker image' );
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
		$this->markTestSkipped( 'Parsoid compatibility issue in Docker image' );
	}

	public function testGetPageContentPreservesWikitext(): void {
		$this->markTestSkipped( 'Parsoid compatibility issue in Docker image' );
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
		$this->markTestSkipped( 'Parsoid compatibility issue in Docker image' );
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
		$this->markTestSkipped( 'Parsoid compatibility issue in Docker image' );
	}
}
