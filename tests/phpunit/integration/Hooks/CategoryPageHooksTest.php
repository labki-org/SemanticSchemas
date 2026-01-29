<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Integration\Hooks;

use MediaWiki\Content\ContentHandler;
use MediaWiki\Page\WikiPage;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\Skin\Skin;

use MediaWiki\Extension\SemanticSchemas\Hooks\CategoryPageHooks;
use MediaWikiIntegrationTestCase;

/**
 * @group Database
 */
class CategoryPageHooksTest extends MediaWikiIntegrationTestCase {
    private string $categoryName = "Category:TestCategory";
    private WikiPage $page;
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

        $skinMock->method('getUser')
            ->willReturn(static::getTestSysop()->getUser());
        $skinMock->method("getTitle")
            ->willReturn($this->title);

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
        $updater->saveRevision("Made an invalid category schema");
        $links = [];
        ( new CategoryPageHooks )->onSkinTemplateNavigation($this->skinMock, $links);

        $this->assertArrayHasKey('actions', $links);
        $this->assertArrayHasKey('ss-generate-form', $links['actions']);
    }
}