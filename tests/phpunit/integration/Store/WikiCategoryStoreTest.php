<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Integration\Store;

use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;
use MediaWiki\Extension\SemanticSchemas\Util\Constants;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore
 * @group Database
 */
class WikiCategoryStoreTest extends MediaWikiIntegrationTestCase {

	private WikiCategoryStore $categoryStore;
	private PageCreator $pageCreator;

	protected function setUp(): void {
		parent::setUp();

		$services = $this->getServiceContainer();
		$this->pageCreator = new PageCreator(
			$services->getWikiPageFactory(),
			$services->getDeletePageFactory()
		);
		$propertyStore = new WikiPropertyStore(
			$this->pageCreator,
			$services->getConnectionProvider(),
			$services->getContentLanguage()
		);
		$this->categoryStore = new WikiCategoryStore(
			$this->pageCreator,
			$propertyStore,
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

		$this->runJobs();

		$managed = $this->categoryStore->getManagedParents( $testcat );
		$this->assertArrayEquals( $expected, $managed );
	}

	public function testNamespaceStrippedFromParents() {
		$title = Title::makeTitleSafe( NS_CATEGORY, "ChildCategory" );
		$this->pageCreator->createOrUpdatePage( $title, "[[Category:ParentCategory]][[Category:" . Constants::SEMANTICSCHEMAS_MANAGED_CATEGORY . "]]", '' );

		$this->runJobs();

		$cats = $this->categoryStore->getAllCategories();
		$this->assertArrayEquals(
			$cats['ChildCategory']->getParents(),
			[ Constants::SEMANTICSCHEMAS_MANAGED_CATEGORY, 'ParentCategory' ]
		);
	}

	/**
	 * Helper to run any pending MediaWiki jobs.
	 */
	private function executeJobs(): void {
		$runner = $this->getServiceContainer()->getJobRunner();
		$runner->run( [
			'type' => false,
			'maxJobs' => 100,
			'maxTime' => 30,
		] );
	}
}
