<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Integration\Store;

use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore
 * @group Database
 * @group Broken
 */
class WikiCategoryStoreTest extends MediaWikiIntegrationTestCase {

	private WikiCategoryStore $categoryStore;
	private PageCreator $pageCreator;

	protected function setUp(): void {
		parent::setUp();

		// Skip if SMW is not available
		if ( !defined( 'SMW_NS_PROPERTY' ) ) {
			$this->markTestSkipped( 'Semantic MediaWiki is not installed' );
		}

		$this->pageCreator = new PageCreator( null );
		$propertyStore = new WikiPropertyStore( $this->pageCreator );
		$this->categoryStore = new WikiCategoryStore( $this->pageCreator, $propertyStore );
	}

	/* =========================================================================
	 * WRITE CATEGORY
	 * ========================================================================= */

	public function testWriteCategoryCreatesNewCategory(): void {
		$category = new CategoryModel( 'TestCat ' . uniqid(), [
			'description' => 'A test category',
		] );

		$result = $this->categoryStore->writeCategory( $category );

		$this->assertTrue( $result );
	}

	public function testWriteCategoryWithDescription(): void {
		$name = 'DescribedCat ' . uniqid();
		$description = 'This is a detailed category description';
		$category = new CategoryModel( $name, [
			'description' => $description,
		] );

		$result = $this->categoryStore->writeCategory( $category );

		$this->assertTrue( $result );
		$title = $this->pageCreator->makeTitle( $name, NS_CATEGORY );
		$content = $this->pageCreator->getPageContent( $title );
		$this->assertStringContainsString( "[[Has description::$description]]", $content );
	}

	public function testWriteCategoryWithParentCategories(): void {
		$parentName = 'ParentCat ' . uniqid();
		$childName = 'ChildCat ' . uniqid();

		// Create parent first
		$parent = new CategoryModel( $parentName );
		$this->categoryStore->writeCategory( $parent );

		// Create child with parent reference
		$child = new CategoryModel( $childName, [
			'parents' => [ $parentName ],
		] );
		$result = $this->categoryStore->writeCategory( $child );

		$this->assertTrue( $result );
		$title = $this->pageCreator->makeTitle( $childName, NS_CATEGORY );
		$content = $this->pageCreator->getPageContent( $title );
		$this->assertStringContainsString( "[[Has parent category::Category:$parentName]]", $content );
	}

	public function testWriteCategoryWithRequiredProperties(): void {
		$name = 'PropsCat ' . uniqid();
		$category = new CategoryModel( $name, [
			'properties' => [
				'required' => [ 'Has name', 'Has email' ],
				'optional' => [],
			],
		] );

		$result = $this->categoryStore->writeCategory( $category );

		$this->assertTrue( $result );
		$title = $this->pageCreator->makeTitle( $name, NS_CATEGORY );
		$content = $this->pageCreator->getPageContent( $title );
		$this->assertStringContainsString( '[[Has required property::Property:Has name]]', $content );
		$this->assertStringContainsString( '[[Has required property::Property:Has email]]', $content );
	}

	public function testWriteCategoryWithOptionalProperties(): void {
		$name = 'OptPropsCat ' . uniqid();
		$category = new CategoryModel( $name, [
			'properties' => [
				'required' => [],
				'optional' => [ 'Has phone', 'Has address' ],
			],
		] );

		$result = $this->categoryStore->writeCategory( $category );

		$this->assertTrue( $result );
		$title = $this->pageCreator->makeTitle( $name, NS_CATEGORY );
		$content = $this->pageCreator->getPageContent( $title );
		$this->assertStringContainsString( '[[Has optional property::Property:Has phone]]', $content );
		$this->assertStringContainsString( '[[Has optional property::Property:Has address]]', $content );
	}

	public function testWriteCategoryWithDisplayHeaderProperties(): void {
		$name = 'HeaderCat ' . uniqid();
		$category = new CategoryModel( $name, [
			'display' => [
				'header' => [ 'Has name', 'Has title' ],
			],
		] );

		$result = $this->categoryStore->writeCategory( $category );

		$this->assertTrue( $result );
		$title = $this->pageCreator->makeTitle( $name, NS_CATEGORY );
		$content = $this->pageCreator->getPageContent( $title );
		$this->assertStringContainsString( '[[Has display header property::Property:Has name]]', $content );
		$this->assertStringContainsString( '[[Has display header property::Property:Has title]]', $content );
	}

	public function testWriteCategoryWithDisplaySections(): void {
		$name = 'SectionsCat ' . uniqid();
		$category = new CategoryModel( $name, [
			'display' => [
				'sections' => [
					[ 'name' => 'Basic Info', 'properties' => [ 'Has name' ] ],
					[ 'name' => 'Contact', 'properties' => [ 'Has email', 'Has phone' ] ],
				],
			],
		] );

		$result = $this->categoryStore->writeCategory( $category );

		$this->assertTrue( $result );
		$title = $this->pageCreator->makeTitle( $name, NS_CATEGORY );
		$content = $this->pageCreator->getPageContent( $title );
		$this->assertStringContainsString( '{{#subobject:display_section_0', $content );
		$this->assertStringContainsString( '|Has display section name=Basic Info', $content );
		$this->assertStringContainsString( '{{#subobject:display_section_1', $content );
		$this->assertStringContainsString( '|Has display section name=Contact', $content );
	}

	public function testWriteCategoryWithTargetNamespace(): void {
		$name = 'NamespaceCat ' . uniqid();
		$category = new CategoryModel( $name, [
			'targetNamespace' => 'Project',
		] );

		$result = $this->categoryStore->writeCategory( $category );

		$this->assertTrue( $result );
		$title = $this->pageCreator->makeTitle( $name, NS_CATEGORY );
		$content = $this->pageCreator->getPageContent( $title );
		$this->assertStringContainsString( '[[Has target namespace::Project]]', $content );
	}

	public function testWriteCategoryAddsManagedCategory(): void {
		$name = 'ManagedCat ' . uniqid();
		$category = new CategoryModel( $name );

		$this->categoryStore->writeCategory( $category );

		$title = $this->pageCreator->makeTitle( $name, NS_CATEGORY );
		$content = $this->pageCreator->getPageContent( $title );
		$this->assertStringContainsString( '[[Category:SemanticSchemas-managed]]', $content );
	}

	public function testWriteCategoryPreservesExistingContentOutsideMarkers(): void {
		$name = 'PreservedCat ' . uniqid();
		$title = $this->pageCreator->makeTitle( $name, NS_CATEGORY );

		// Create page with some initial content
		$initialContent = "== User Notes ==\nUser-added content here.";
		$this->pageCreator->createOrUpdatePage(
			$title,
			$initialContent,
			'Initial content'
		);

		// Write category through the store
		$category = new CategoryModel( $name, [
			'description' => 'Category description',
		] );
		$this->categoryStore->writeCategory( $category );

		// User content should be preserved
		$content = $this->pageCreator->getPageContent( $title );
		$this->assertStringContainsString( '== User Notes ==', $content );
		$this->assertStringContainsString( 'User-added content here.', $content );
		$this->assertStringContainsString( '[[Has description::', $content );
	}

	public function testWriteCategoryUpdatesExistingCategory(): void {
		$name = 'UpdatableCat ' . uniqid();

		// Create initial category
		$category1 = new CategoryModel( $name, [
			'description' => 'Initial description',
		] );
		$this->categoryStore->writeCategory( $category1 );

		// Update category
		$category2 = new CategoryModel( $name, [
			'description' => 'Updated description',
		] );
		$result = $this->categoryStore->writeCategory( $category2 );

		$this->assertTrue( $result );
		$title = $this->pageCreator->makeTitle( $name, NS_CATEGORY );
		$content = $this->pageCreator->getPageContent( $title );
		$this->assertStringContainsString( '[[Has description::Updated description]]', $content );
		$this->assertStringNotContainsString( 'Initial description', $content );
	}

	/* =========================================================================
	 * READ CATEGORY
	 * ========================================================================= */

	public function testReadCategoryReturnsNullForNonExistent(): void {
		$result = $this->categoryStore->readCategory( 'NonExistentCategory ' . uniqid() );

		$this->assertNull( $result );
	}

	public function testReadCategoryReturnsCategoryModel(): void {
		$name = 'ReadableCat ' . uniqid();
		$category = new CategoryModel( $name, [
			'description' => 'Readable category',
		] );
		$this->categoryStore->writeCategory( $category );

		// SMW needs to process the page
		$this->executeJobs();

		$result = $this->categoryStore->readCategory( $name );

		$this->assertInstanceOf( CategoryModel::class, $result );
		$this->assertEquals( $name, $result->getName() );
	}

	/* =========================================================================
	 * SUBOBJECTS
	 * ========================================================================= */

	public function testWriteCategoryWithRequiredSubobjects(): void {
		$name = 'SubobjCat ' . uniqid();
		$category = new CategoryModel( $name, [
			'subobjects' => [
				'required' => [ 'Author', 'Publication' ],
				'optional' => [],
			],
		] );

		$result = $this->categoryStore->writeCategory( $category );

		$this->assertTrue( $result );
		$title = $this->pageCreator->makeTitle( $name, NS_CATEGORY );
		$content = $this->pageCreator->getPageContent( $title );
		$this->assertStringContainsString( '[[Has required subobject::Subobject:Author]]', $content );
		$this->assertStringContainsString( '[[Has required subobject::Subobject:Publication]]', $content );
	}

	public function testWriteCategoryWithOptionalSubobjects(): void {
		$name = 'OptSubobjCat ' . uniqid();
		$category = new CategoryModel( $name, [
			'subobjects' => [
				'required' => [],
				'optional' => [ 'Funding', 'Award' ],
			],
		] );

		$result = $this->categoryStore->writeCategory( $category );

		$this->assertTrue( $result );
		$title = $this->pageCreator->makeTitle( $name, NS_CATEGORY );
		$content = $this->pageCreator->getPageContent( $title );
		$this->assertStringContainsString( '[[Has optional subobject::Subobject:Funding]]', $content );
		$this->assertStringContainsString( '[[Has optional subobject::Subobject:Award]]', $content );
	}

	/* =========================================================================
	 * EDGE CASES
	 * ========================================================================= */

	public function testWriteCategoryWithSpecialCharactersInName(): void {
		// MediaWiki allows some special characters in category names
		$name = 'Test Category ' . uniqid();
		$category = new CategoryModel( $name );

		$result = $this->categoryStore->writeCategory( $category );

		$this->assertTrue( $result );
	}

	public function testWriteCategoryWithMixedPropertiesAndSubobjects(): void {
		$name = 'MixedCat ' . uniqid();
		$category = new CategoryModel( $name, [
			'properties' => [
				'required' => [ 'Has name' ],
				'optional' => [ 'Has email' ],
			],
			'subobjects' => [
				'required' => [ 'Author' ],
				'optional' => [ 'Funding' ],
			],
		] );

		$result = $this->categoryStore->writeCategory( $category );

		$this->assertTrue( $result );
		$title = $this->pageCreator->makeTitle( $name, NS_CATEGORY );
		$content = $this->pageCreator->getPageContent( $title );
		$this->assertStringContainsString( '[[Has required property::Property:Has name]]', $content );
		$this->assertStringContainsString( '[[Has optional property::Property:Has email]]', $content );
		$this->assertStringContainsString( '[[Has required subobject::Subobject:Author]]', $content );
		$this->assertStringContainsString( '[[Has optional subobject::Subobject:Funding]]', $content );
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
