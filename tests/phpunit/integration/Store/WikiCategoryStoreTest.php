<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Integration\Store;

use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore;
use MediaWiki\Extension\SemanticSchemas\Util\Constants;
use MediaWiki\Extension\SemanticSchemas\Tests\SMWIntegrationTestCase;
use MediaWiki\Title\Title;

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore
 * @group Database
 */
class WikiCategoryStoreTest extends SMWIntegrationTestCase {

	private WikiCategoryStore $categoryStore;
	private PageCreator $pageCreator;

	protected function setUp(): void {
		parent::setUp();

		$services = $this->getServiceContainer();
		$this->pageCreator = new PageCreator(
			$services->getWikiPageFactory(),
		);
		$this->categoryStore = new WikiCategoryStore(
			$this->pageCreator,
			$services->getConnectionProvider(),
			$services->getMainConfig()
		);
	}

	/* =========================================================================
	 * READ CATEGORY
	 * ========================================================================= */

	public function testReadCategoryReturnsNullForNonExistent(): void {
		$result = $this->categoryStore->readCategory( 'NonExistentCategory ' . uniqid() );

		$this->assertNull( $result );
	}

	/* =========================================================================
	  * Parents
	  * ========================================================================= */

	public static function parentsProvider(): array {
		$managed = '[[Category:' . Constants::SEMANTICSCHEMAS_MANAGED_CATEGORY . ']]';
		$unmanaged = '[[Category:RandomOtherCategory]]';
		return [
			"multiple managed categories" => [ $managed, $managed, [ "A", "B" ] ],
			"one managed category" => [ $managed, $unmanaged, [ "A" ] ],
			"no managed categories" => [ $unmanaged, $unmanaged, [] ]
		];
	}

	/**
	 * @dataProvider parentsProvider
	 */
	public function testManagedParents( string $acontent, string $bcontent, array $expected ) {
		$atitle = Title::makeTitleSafe( NS_CATEGORY, "A" );
		$btitle = Title::makeTitleSafe( NS_CATEGORY, "B" );
		$testcat = Title::makeTitleSafe( NS_MAIN, "Test Category" );
		$this->pageCreator->createOrUpdatePage( $atitle, $acontent, "a" );
		$this->pageCreator->createOrUpdatePage( $btitle, $bcontent, "b" );
		$this->pageCreator->createOrUpdatePage( $testcat, '[[Category:A]] [[Category:B]]', "c" );

		$this->runSMWUpdates();

		$managed = $this->categoryStore->getManagedParents( $testcat );
		$this->assertArrayEquals( $expected, $managed );
	}

	public function testNamespaceStrippedFromParents() {
		$title = Title::makeTitleSafe( NS_CATEGORY, "ChildCategory" );
		$this->pageCreator->createOrUpdatePage(
			$title,
			"[[Category:ParentCategory]][[Category:" .
			Constants::SEMANTICSCHEMAS_MANAGED_CATEGORY .
			"]]",
			'' );

		$this->runSMWUpdates();

		$cats = $this->categoryStore->getAllCategories();
		$this->assertArrayEquals(
			$cats['ChildCategory']->getParents(),
			[ Constants::SEMANTICSCHEMAS_MANAGED_CATEGORY, 'ParentCategory' ]
		);
	}

	public function testReadCategoryFromSMW()
	{
		$expected = uniqid('Hey');
		$content = "[[Has description::$expected]] " .
			"[[Category:" .
			Constants::SEMANTICSCHEMAS_MANAGED_CATEGORY .
			"]]";
		$title = Title::makeTitleSafe( NS_CATEGORY, "TestCategory" );
		$this->pageCreator->createOrUpdatePage($title, $content, "" );

		$this->runSMWUpdates();

		$catModel = $this->categoryStore->readCategory( "TestCategory" );
		$this->assertEquals( $expected, $catModel->getDescription() );
	}
}
