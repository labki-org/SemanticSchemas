<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Integration\Hooks;

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Extension\SemanticSchemas\Hooks\CategoryPageHooks;
use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;
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
	private WikiCategoryStore $categoryStore;
	private PageCreator $pageCreator;

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

		$this->pageCreator = new PageCreator(
			$wikiPageFactory,
			$services->getDeletePageFactory()
		);
		$propertyStore = new WikiPropertyStore(
			$this->pageCreator,
			$services->getConnectionProvider()
		);
		$this->categoryStore = new WikiCategoryStore(
			$this->pageCreator,
			$propertyStore,
			$services->getConnectionProvider(),
			$services->getMainConfig()
		);
	}

	/* =========================================================================
	 * CATEGORY PAGE ACTIONS
	 * ========================================================================= */

	/**
	 * Rendering works normally even when the category is invalid
	 */
	public function testRenderWhenCategoryInValid(): void {
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
		$hooks = new CategoryPageHooks(
			$this->getServiceContainer()->get( 'SemanticSchemas.WikiCategoryStore' )
		);
		$hooks->onSkinTemplateNavigation__Universal( $this->skinMock, $links );

		$this->assertArrayHasKey( 'actions', $links );
		$this->assertArrayHasKey( 's2-generate-form', $links['actions'] );
	}

	public function testGenerateFormActionRequiresEditinterfacePermission(): void {
		$user = static::getTestUser()->getUser();
		$this->overrideUserPermissions( $user, [ 'read', 'createpage' ] );
		$skinMock = $this->createSkinMock( $user, $this->title );

		$this->savePage( $this->title, '[[Has required property::Property:A]]' );

		$links = [];
		$hooks = $this->makeHooks();
		$hooks->onSkinTemplateNavigation__Universal( $skinMock, $links );

		$this->assertArrayNotHasKey( 's2-generate-form', $links['actions'] ?? [],
			'Users without editinterface should not see Generate Form action' );
	}

	public function testUserWithEditinterfaceSeesGenerateFormAction(): void {
		$user = static::getTestUser()->getUser();
		$this->overrideUserPermissions( $user, [ 'read', 'editinterface' ] );
		$skinMock = $this->createSkinMock( $user, $this->title );

		$this->savePage( $this->title, '[[Has required property::Property:A]]' );

		$links = [];
		$hooks = $this->makeHooks();
		$hooks->onSkinTemplateNavigation__Universal( $skinMock, $links );

		$this->assertArrayHasKey( 'actions', $links );
		$this->assertArrayHasKey( 's2-generate-form', $links['actions'] );
	}

	public function testNewPageActionShownWhenFormExists(): void {
		$catName = 'FormExistsCat' . uniqid();
		$catTitle = Title::makeTitleSafe( NS_CATEGORY, $catName );
		$this->savePage( $catTitle, '[[Has required property::Property:Has name]]' );

		// Create the form page
		$formTitle = Title::makeTitleSafe( PF_NS_FORM, $catName );
		$this->savePage( $formTitle, '<includeonly>form content</includeonly>' );

		$skinMock = $this->createSkinMock( static::getTestSysop()->getUser(), $catTitle );
		$links = [];
		$hooks = $this->makeHooks();
		$hooks->onSkinTemplateNavigation__Universal( $skinMock, $links );

		$this->assertArrayHasKey( 's2-new-page', $links['actions'] ?? [],
			'New page action should appear when form exists' );
	}

	public function testNewPageActionHiddenWhenFormDoesNotExist(): void {
		$catName = 'NoFormCat' . uniqid();
		$catTitle = Title::makeTitleSafe( NS_CATEGORY, $catName );
		$this->savePage( $catTitle, '[[Has required property::Property:Has name]]' );

		$skinMock = $this->createSkinMock( static::getTestSysop()->getUser(), $catTitle );
		$links = [];
		$hooks = $this->makeHooks();
		$hooks->onSkinTemplateNavigation__Universal( $skinMock, $links );

		$this->assertArrayNotHasKey( 's2-new-page', $links['actions'] ?? [],
			'New page action should not appear without form' );
	}

	/* =========================================================================
	 * CONTENT PAGE ACTIONS — COMPOSITE FORM REDIRECT
	 * ========================================================================= */

	public function testMultiCategoryPageRewritesFormeditToCompositeForm(): void {
		$cat1 = 'HookCatA' . uniqid();
		$cat2 = 'HookCatB' . uniqid();
		$this->createManagedCategory( $cat1 );
		$this->createManagedCategory( $cat2 );

		// Create a content page that belongs to both categories
		$pageName = 'MultiCatPage' . uniqid();
		$pageTitle = Title::makeTitleSafe( NS_MAIN, $pageName );
		$this->savePage( $pageTitle,
			"[[Category:" . $cat1 . "]]\n[[Category:" . $cat2 . "]]"
		);

		$this->executeJobs();

		$skinMock = $this->createSkinMock( static::getTestSysop()->getUser(), $pageTitle );
		$links = [
			'views' => [
				'formedit' => [
					'text' => 'Edit with form',
					'href' => '/wiki/Special:FormEdit/' . $cat1 . '/' . $pageName,
				],
			],
		];

		$hooks = $this->makeHooks();
		$hooks->onSkinTemplateNavigation__Universal( $skinMock, $links );

		$this->assertStringContainsString( 'CompositeForm',
			$links['views']['formedit']['href'],
			'Multi-category page should redirect formedit to CompositeForm' );
	}

	public function testSingleManagedCategoryDoesNotRewriteFormedit(): void {
		$cat1 = 'HookSingle' . uniqid();
		$this->createManagedCategory( $cat1 );

		$pageName = 'SingleCatPage' . uniqid();
		$pageTitle = Title::makeTitleSafe( NS_MAIN, $pageName );
		$originalHref = '/wiki/Special:FormEdit/' . $cat1 . '/' . $pageName;
		$this->savePage( $pageTitle,
			"[[Category:" . $cat1 . "]]"
		);

		$this->executeJobs();

		$skinMock = $this->createSkinMock( static::getTestSysop()->getUser(), $pageTitle );
		$links = [
			'views' => [
				'formedit' => [
					'text' => 'Edit with form',
					'href' => $originalHref,
				],
			],
		];

		$hooks = $this->makeHooks();
		$hooks->onSkinTemplateNavigation__Universal( $skinMock, $links );

		$this->assertSame( $originalHref, $links['views']['formedit']['href'],
			'Single-category page should not rewrite formedit link' );
	}

	public function testUnmanagedCategoriesNotCountedForCompositeRedirect(): void {
		$managed = 'HookManaged' . uniqid();
		$this->createManagedCategory( $managed );

		$pageName = 'MixedCatPage' . uniqid();
		$pageTitle = Title::makeTitleSafe( NS_MAIN, $pageName );
		$originalHref = '/wiki/Special:FormEdit/' . $managed . '/' . $pageName;

		// Page has one managed and one unmanaged category
		$this->savePage( $pageTitle,
			"[[Category:" . $managed . "]]\n[[Category:UnmanagedCat]]"
		);

		$this->executeJobs();

		$skinMock = $this->createSkinMock( static::getTestSysop()->getUser(), $pageTitle );
		$links = [
			'views' => [
				'formedit' => [
					'text' => 'Edit with form',
					'href' => $originalHref,
				],
			],
		];

		$hooks = $this->makeHooks();
		$hooks->onSkinTemplateNavigation__Universal( $skinMock, $links );

		$this->assertSame( $originalHref, $links['views']['formedit']['href'],
			'Unmanaged categories should not count toward composite form redirect' );
	}

	public function testAddCategoryActionShownOnContentPageWithManagedCategory(): void {
		$cat1 = 'HookAddCat' . uniqid();
		$this->createManagedCategory( $cat1 );

		$pageName = 'AddCatPage' . uniqid();
		$pageTitle = Title::makeTitleSafe( NS_MAIN, $pageName );
		$this->savePage( $pageTitle,
			"[[Category:" . $cat1 . "]]"
		);

		$this->executeJobs();

		$skinMock = $this->createSkinMock( static::getTestSysop()->getUser(), $pageTitle );
		$links = [];
		$hooks = $this->makeHooks();
		$hooks->onSkinTemplateNavigation__Universal( $skinMock, $links );

		$this->assertArrayHasKey( 's2-add-category', $links['actions'] ?? [],
			'Add category action should be shown on managed content pages' );
	}

	/* =========================================================================
	 * Helpers
	 * ========================================================================= */

	private function makeHooks(): CategoryPageHooks {
		return new CategoryPageHooks(
			$this->getServiceContainer()->get( 'SemanticSchemas.WikiCategoryStore' )
		);
	}

	private function createSkinMock( $user, Title $title ): Skin {
		$skinMock = $this->getMockBuilder( Skin::class )
			->disableOriginalConstructor()
			->getMock();

		$skinMock->method( 'getUser' )->willReturn( $user );
		$skinMock->method( 'getTitle' )->willReturn( $title );

		return $skinMock;
	}

	private function createManagedCategory( string $name ): void {
		$category = new CategoryModel( $name, [
			'properties' => [
				'required' => [ 'Has name' ],
				'optional' => [],
			],
		] );
		$this->categoryStore->writeCategory( $category );
	}

	private function savePage( Title $title, string $content ): void {
		$user = static::getTestSysop()->getUser();
		$wikiPage = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title );
		$updater = $wikiPage->newPageUpdater( $user );
		$updater->setContent(
			SlotRecord::MAIN,
			ContentHandler::makeContent( $content, $title )
		);
		$updater->saveRevision(
			CommentStoreComment::newUnsavedComment( 'Test setup' )
		);
	}

	private function executeJobs(): void {
		$runner = $this->getServiceContainer()->getJobRunner();
		$runner->run( [
			'type' => false,
			'maxJobs' => 100,
			'maxTime' => 30,
		] );
	}
}
