<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Integration\Maintenance;

use MediaWiki\Extension\SemanticSchemas\Schema\InheritanceResolver;
use MediaWiki\Extension\SemanticSchemas\SemanticSchemasServices;
use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * @group Database
 * @covers \MediaWiki\Extension\SemanticSchemas\Schema\InheritanceResolver
 */
class InheritanceResolverTest extends MediaWikiIntegrationTestCase {
	private WikiCategoryStore $categoryStore;
	private PageCreator $pageCreator;

	public function setUp(): void {
		parent::setUp();
		$services = $this->getServiceContainer();
		$this->categoryStore = SemanticSchemasServices::getWikiCategoryStore( $services );
		$this->pageCreator = SemanticSchemasServices::getPageCreator( $services );
	}

	/**
	 * Category hierarchy is correctly inferred from category tags,
	 * even when the names of the categories are weird.
	 * Uses the canonical string form of the name
	 * https://www.mediawiki.org/wiki/Manual:Page_title#Canonical_forms
	 *
	 * Tests the integration between categoryStore->getAllCategories
	 * and Inheritance resolver, since they are obligately used together.
	 *
	 * @return void
	 */
	public function testInheritanceResolvedByCategoryTag(): void {
		$this->pageCreator->createOrUpdatePage(
			Title::makeTitleSafe( NS_CATEGORY, "Category! A" ),
			"", ""
		);
		$this->pageCreator->createOrUpdatePage(
			Title::makeTitleSafe( NS_CATEGORY, "Category:Category? B" ),
			"[[Category:Category! A]]",
			""
		);
		$this->pageCreator->createOrUpdatePage(
			Title::makeTitleSafe( NS_CATEGORY, "Category_C" ),
			"[[Category:Category:Category? B]]",
			""
		);
		$this->pageCreator->createOrUpdatePage(
			Title::makeTitleSafe( NS_CATEGORY, "CategoryD" ),
			"[[Category:Category_C]]",
			""
		);

		$this->runJobs();

		$cats = $this->categoryStore->getAllCategories();
		$resolver = new InheritanceResolver( $cats );
		$ancestors = $resolver->getAncestors( "CategoryD" );
		$this->assertArrayEquals( [ 'Category! A', 'Category:Category? B', 'Category C', 'CategoryD' ], $ancestors );
	}
}
