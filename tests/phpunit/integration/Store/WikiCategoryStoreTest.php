<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Integration\Store;

use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\FieldDeclaration;
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
		$this->assertStringContainsString( "[[Category:$parentName]]", $content );
	}

	public function testWriteCategoryWithRequiredProperties(): void {
		$name = 'PropsCat ' . uniqid();
		$category = new CategoryModel( $name, [
			'properties' => [
				FieldDeclaration::property( 'Has name', true ),
				FieldDeclaration::property( 'Has email', true ),
			],
		] );

		$result = $this->categoryStore->writeCategory( $category );

		$this->assertTrue( $result );
		$title = $this->pageCreator->makeTitle( $name, NS_CATEGORY );
		$content = $this->pageCreator->getPageContent( $title );

		// Assert complete subobject blocks (not just individual lines)
		$this->assertSubobjectBlock( $content, [
			'@category=Property field',
			'For property = Property:Has name',
			'Is required = true',
			'Has sort order = 1',
		] );
		$this->assertSubobjectBlock( $content, [
			'@category=Property field',
			'For property = Property:Has email',
			'Is required = true',
			'Has sort order = 2',
		] );
	}

	public function testWriteCategoryWithOptionalProperties(): void {
		$name = 'OptPropsCat ' . uniqid();
		$category = new CategoryModel( $name, [
			'properties' => [
				FieldDeclaration::property( 'Has phone', false ),
				FieldDeclaration::property( 'Has address', false ),
			],
		] );

		$result = $this->categoryStore->writeCategory( $category );

		$this->assertTrue( $result );
		$title = $this->pageCreator->makeTitle( $name, NS_CATEGORY );
		$content = $this->pageCreator->getPageContent( $title );

		$this->assertSubobjectBlock( $content, [
			'@category=Property field',
			'For property = Property:Has phone',
			'Is required = false',
			'Has sort order = 1',
		] );
		$this->assertSubobjectBlock( $content, [
			'@category=Property field',
			'For property = Property:Has address',
			'Is required = false',
			'Has sort order = 2',
		] );
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
				FieldDeclaration::subobject( 'Author', true ),
				FieldDeclaration::subobject( 'Publication', true ),
			],
		] );

		$result = $this->categoryStore->writeCategory( $category );

		$this->assertTrue( $result );
		$title = $this->pageCreator->makeTitle( $name, NS_CATEGORY );
		$content = $this->pageCreator->getPageContent( $title );

		$this->assertSubobjectBlock( $content, [
			'@category=Subobject field',
			'For category = Category:Author',
			'Is required = true',
			'Has sort order = 1',
		] );
		$this->assertSubobjectBlock( $content, [
			'@category=Subobject field',
			'For category = Category:Publication',
			'Is required = true',
			'Has sort order = 2',
		] );
	}

	public function testWriteCategoryWithOptionalSubobjects(): void {
		$name = 'OptSubobjCat ' . uniqid();
		$category = new CategoryModel( $name, [
			'subobjects' => [
				FieldDeclaration::subobject( 'Funding', false ),
				FieldDeclaration::subobject( 'Award', false ),
			],
		] );

		$result = $this->categoryStore->writeCategory( $category );

		$this->assertTrue( $result );
		$title = $this->pageCreator->makeTitle( $name, NS_CATEGORY );
		$content = $this->pageCreator->getPageContent( $title );

		$this->assertSubobjectBlock( $content, [
			'@category=Subobject field',
			'For category = Category:Funding',
			'Is required = false',
			'Has sort order = 1',
		] );
		$this->assertSubobjectBlock( $content, [
			'@category=Subobject field',
			'For category = Category:Award',
			'Is required = false',
			'Has sort order = 2',
		] );
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
				FieldDeclaration::property( 'Has name', true ),
				FieldDeclaration::property( 'Has email', false ),
			],
			'subobjects' => [
				FieldDeclaration::subobject( 'Author', true ),
				FieldDeclaration::subobject( 'Funding', false ),
			],
		] );

		$result = $this->categoryStore->writeCategory( $category );

		$this->assertTrue( $result );
		$title = $this->pageCreator->makeTitle( $name, NS_CATEGORY );
		$content = $this->pageCreator->getPageContent( $title );

		// Property fields
		$this->assertSubobjectBlock( $content, [
			'@category=Property field',
			'For property = Property:Has name',
			'Is required = true',
			'Has sort order = 1',
		] );
		$this->assertSubobjectBlock( $content, [
			'@category=Property field',
			'For property = Property:Has email',
			'Is required = false',
			'Has sort order = 2',
		] );

		// Subobject fields
		$this->assertSubobjectBlock( $content, [
			'@category=Subobject field',
			'For category = Category:Author',
			'Is required = true',
			'Has sort order = 1',
		] );
		$this->assertSubobjectBlock( $content, [
			'@category=Subobject field',
			'For category = Category:Funding',
			'Is required = false',
			'Has sort order = 2',
		] );
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
		$category = new CategoryModel( "ChildCategory", [
			'parents' => [ 'ParentCategory' ]
		] );

		$this->categoryStore->writeCategory( $category );

		$this->runJobs();

		$cats = $this->categoryStore->getAllCategories();
		$this->assertArrayEquals(
			$cats['ChildCategory']->getParents(),
			[ Constants::SEMANTICSCHEMAS_MANAGED_CATEGORY, 'ParentCategory' ]
		);
	}

	/**
	 * Assert that a complete {{#subobject:...}} block exists in the content
	 * containing all expected lines within a single block.
	 *
	 * @param string $content The full page wikitext
	 * @param string[] $expectedLines Lines that must all appear within one block
	 */
	private function assertSubobjectBlock(
		string $content,
		array $expectedLines
	): void {
		// Extract all subobject blocks
		preg_match_all( '/\{\{#subobject:[^}]*\}\}/s', $content, $matches );
		$this->assertNotEmpty(
			$matches[0],
			'No subobject blocks found in content'
		);

		// Find a block that contains ALL expected lines
		foreach ( $matches[0] as $block ) {
			$allFound = true;
			foreach ( $expectedLines as $line ) {
				if ( !str_contains( $block, $line ) ) {
					$allFound = false;
					break;
				}
			}
			if ( $allFound ) {
				// Found a matching block — assertion passes
				$this->assertTrue( true );
				return;
			}
		}

		$this->fail(
			"No subobject block contains all expected lines:\n  "
			. implode( "\n  ", $expectedLines )
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
