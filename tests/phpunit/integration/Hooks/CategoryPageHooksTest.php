<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Integration\Hooks;

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Extension\SemanticSchemas\Hooks\CategoryPageHooks;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use Skin;

/**
 * @group Database
 * @covers \MediaWiki\Extension\SemanticSchemas\Hooks\CategoryPageHooks
 */
class CategoryPageHooksTest extends MediaWikiIntegrationTestCase {
	private string $categoryName = "Category:TestCategory";
	/** @var \WikiPage */
	private $page;
	private Skin $skinMock;
	private Title $title;

	protected function setUp(): void {
		parent::setUp();

		$services = $this->getServiceContainer();
		$this->title = Title::makeTitleSafe( NS_CATEGORY, $this->categoryName );

		$wikiPageFactory = $services->getWikiPageFactory();
		$this->page = $wikiPageFactory->newFromTitle( $this->title );

		$skinMock = $this->getMockBuilder( Skin::class )
			->disableOriginalConstructor()
			->getMock();

		$skinMock->method( 'getUser' )
			->willReturn( static::getTestSysop()->getUser() );
		$skinMock->method( "getTitle" )
			->willReturn( $this->title );

		$this->skinMock = $skinMock;
	}

	/**
	 * Rendering works normally even when the category is invalid
	 * @group Database
	 */
	public function testRenderWhenCategoryInValid() {
		$updater = $this->page->newPageUpdater( static::getTestSysop()->getUser() );
		$updater->setContent(
			SlotRecord::MAIN,
			ContentHandler::makeContent(
				'[[Has optional property::Property:A]] [[Has required property::Property:A]]',
				$this->title
			)
		);
		$updater->saveRevision( CommentStoreComment::newUnsavedComment( "Made an invalid category schema" ) );
		$links = [];
		( new CategoryPageHooks )->onSkinTemplateNavigation__Universal( $this->skinMock, $links );

		$this->assertArrayHasKey( 'actions', $links );
		$this->assertArrayHasKey( 's2-generate-form', $links['actions'] );
	}
}
