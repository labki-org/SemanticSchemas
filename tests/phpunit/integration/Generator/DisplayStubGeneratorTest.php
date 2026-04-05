<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Integration\Generator;

use MediaWiki\Extension\SemanticSchemas\Generator\DisplayStubGenerator;
use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\EffectiveCategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\PropertyModel;
use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Generator\DisplayStubGenerator
 * @group Database
 */
class DisplayStubGeneratorTest extends MediaWikiIntegrationTestCase {

	private PageCreator $pageCreator;

	protected function setUp(): void {
		parent::setUp();
		$services = $this->getServiceContainer();
		$this->pageCreator = new PageCreator(
			$services->getWikiPageFactory(),
			$services->getDeletePageFactory()
		);
	}

	/**
	 * Create a DisplayStubGenerator with a mocked property store.
	 *
	 * @param array<string, PropertyModel> $propertyMap
	 * @return DisplayStubGenerator
	 */
	private function makeGenerator(
		array $propertyMap = [],
		array $categoryMap = []
	): DisplayStubGenerator {
		$propStore = $this->createMock( WikiPropertyStore::class );
		$propStore->method( 'readProperty' )
			->willReturnCallback( static fn ( string $name ) => $propertyMap[$name] ?? null );

		$catStore = $this->createMock( WikiCategoryStore::class );
		$catStore->method( 'getAllCategories' )
			->willReturn( $categoryMap );

		return new DisplayStubGenerator( $this->pageCreator, $propStore, $catStore );
	}

	/**
	 * Generate and retrieve display template content for a category.
	 */
	private function generateAndRead(
		string $categoryName,
		array $requiredProps,
		array $optionalProps = [],
		array $propertyMap = []
	): string {
		$gen = $this->makeGenerator( $propertyMap );
		$category = new EffectiveCategoryModel( $categoryName, [
			'properties' => [
				'required' => $requiredProps,
				'optional' => $optionalProps,
			],
		] );

		$gen->generateOrUpdateDisplayStub( $category );

		$title = $this->pageCreator->makeTitle( "$categoryName/display", NS_TEMPLATE );
		$this->assertNotNull( $title );
		$content = $this->pageCreator->getPageContent( $title );
		$this->assertNotNull( $content, 'Display template should have been created' );

		return $content;
	}

	/* =========================================================================
	 * EMPTY VALUE HIDING
	 * ========================================================================= */

	public function testPropertyRowsWrappedInIfCondition(): void {
		$content = $this->generateAndRead( 'TestCat_' . uniqid(), [ 'Has name' ] );

		$this->assertStringContainsString( '{{#if:{{{has_name|}}}|', $content );
	}

	public function testPropertyRowUsesMagicWordPipeEscape(): void {
		$content = $this->generateAndRead( 'TestCat_' . uniqid(), [ 'Has name' ] );

		$this->assertStringContainsString( '{{!}}-', $content );
		$this->assertStringContainsString( '{{!}} ', $content );
	}

	public function testMultiplePropertiesEachHaveIfCondition(): void {
		$content = $this->generateAndRead(
			'TestCat_' . uniqid(),
			[ 'Has name', 'Has email', 'Has phone' ]
		);

		$this->assertStringContainsString( '{{#if:{{{has_name|}}}|', $content );
		$this->assertStringContainsString( '{{#if:{{{has_email|}}}|', $content );
		$this->assertStringContainsString( '{{#if:{{{has_phone|}}}|', $content );
	}

	public function testOptionalPropertiesAlsoHaveIfCondition(): void {
		$content = $this->generateAndRead(
			'TestCat_' . uniqid(),
			[],
			[ 'Has nickname' ]
		);

		$this->assertStringContainsString( '{{#if:{{{has_nickname|}}}|', $content );
	}

	/* =========================================================================
	 * RENDER TEMPLATES
	 * ========================================================================= */

	public function testDefaultRenderTemplateUsedForUnknownProperties(): void {
		$content = $this->generateAndRead( 'TestCat_' . uniqid(), [ 'Has name' ] );

		$this->assertStringContainsString( 'Property/Default', $content );
	}

	public function testCustomRenderTemplateUsedWhenPropertyHasOne(): void {
		$content = $this->generateAndRead(
			'TestCat_' . uniqid(),
			[ 'Has email' ],
			[],
			[
				'Has email' => new PropertyModel( 'Has email', [
					'datatype' => 'Text',
					'hasTemplate' => 'Template:Property/Email',
				] ),
			]
		);

		$this->assertStringContainsString( 'Property/Email', $content );
		$this->assertStringNotContainsString( 'Property/Default', $content );
	}

	public function testPageTypePropertyUsesPageRenderTemplate(): void {
		$content = $this->generateAndRead(
			'TestCat_' . uniqid(),
			[ 'Has lead' ],
			[],
			[
				'Has lead' => new PropertyModel( 'Has lead', [
					'datatype' => 'Page',
				] ),
			]
		);

		$this->assertStringContainsString( 'Property/Page', $content );
	}

	public function testCustomRenderTemplateStillWrappedInIf(): void {
		$content = $this->generateAndRead(
			'TestCat_' . uniqid(),
			[ 'Has email' ],
			[],
			[
				'Has email' => new PropertyModel( 'Has email', [
					'datatype' => 'Text',
					'hasTemplate' => 'Template:Property/Email',
				] ),
			]
		);

		$this->assertStringContainsString( '{{#if:{{{has_email|}}}|', $content );
		$this->assertStringContainsString( 'Property/Email', $content );
	}

	/* =========================================================================
	 * STRUCTURAL INTEGRITY
	 * ========================================================================= */

	public function testContainsAutoRegenerateMarker(): void {
		$content = $this->generateAndRead( 'TestCat_' . uniqid(), [ 'Has name' ] );

		$this->assertStringContainsString(
			DisplayStubGenerator::AUTO_REGENERATE_MARKER,
			$content
		);
	}

	public function testContainsManagedDisplayCategory(): void {
		$content = $this->generateAndRead( 'TestCat_' . uniqid(), [ 'Has name' ] );

		$this->assertStringContainsString(
			'[[Category:SemanticSchemas-managed-display]]',
			$content
		);
	}

	public function testContainsWikitableMarkup(): void {
		$content = $this->generateAndRead( 'TestCat_' . uniqid(), [ 'Has name' ] );

		$this->assertStringContainsString( '{| class="wikitable', $content );
		$this->assertStringContainsString( '|}', $content );
	}

	public function testValueExpressionPassedToRenderTemplate(): void {
		$content = $this->generateAndRead( 'TestCat_' . uniqid(), [ 'Has name' ] );

		$this->assertStringContainsString( 'value={{{has_name|}}}', $content );
	}

	/* =========================================================================
	 * REVERSE RELATIONSHIPS
	 * ========================================================================= */

	public function testReverseRelationshipRowGenerated(): void {
		$hasProject = new PropertyModel( 'Has project', [
			'datatype' => 'Page',
			'allowedCategory' => 'Project',
			'inversePropertyLabel' => 'Components',
		] );

		$componentCat = new CategoryModel( 'Component', [
			'properties' => [ 'required' => [ 'Has project' ], 'optional' => [] ],
		] );

		$gen = $this->makeGenerator(
			[ 'Has project' => $hasProject ],
			[ 'Component' => $componentCat ]
		);

		$catName = 'Project';
		$category = new EffectiveCategoryModel( $catName, [
			'properties' => [ 'required' => [ 'Has name' ], 'optional' => [] ],
		] );

		$gen->generateOrUpdateDisplayStub( $category );

		$title = $this->pageCreator->makeTitle( "$catName/display", NS_TEMPLATE );
		$content = $this->pageCreator->getPageContent( $title );

		// Label with auto badge
		$this->assertStringContainsString( '! Components', $content );
		$this->assertStringContainsString( '(auto)', $content );
		// Ask query with category filter
		$this->assertStringContainsString( '[[Has project::{{FULLPAGENAME}}]]', $content );
		$this->assertStringContainsString( '[[Category:Component]]', $content );
		// Uses format=count for condition, format=list for display
		$this->assertStringContainsString( 'format=count', $content );
		$this->assertStringContainsString( 'format=list', $content );
	}

	public function testNoReverseRelationshipWithoutInverseLabel(): void {
		$hasProject = new PropertyModel( 'Has project', [
			'datatype' => 'Page',
			'allowedCategory' => 'Project',
		] );

		$componentCat = new CategoryModel( 'Component', [
			'properties' => [ 'required' => [ 'Has project' ], 'optional' => [] ],
		] );

		$gen = $this->makeGenerator(
			[ 'Has project' => $hasProject ],
			[ 'Component' => $componentCat ]
		);

		$catName = 'Project_' . uniqid();
		$category = new EffectiveCategoryModel( $catName, [
			'properties' => [ 'required' => [ 'Has name' ], 'optional' => [] ],
		] );

		$gen->generateOrUpdateDisplayStub( $category );

		$title = $this->pageCreator->makeTitle( "$catName/display", NS_TEMPLATE );
		$content = $this->pageCreator->getPageContent( $title );

		$this->assertStringNotContainsString( '{{#ask:', $content );
	}

	public function testNonPageTypePropertyIgnoredForReverseRelationship(): void {
		$textProp = new PropertyModel( 'Has tag', [
			'datatype' => 'Text',
			'inversePropertyLabel' => 'Tagged items',
		] );

		$tagCat = new CategoryModel( 'Tag', [
			'properties' => [ 'required' => [ 'Has tag' ], 'optional' => [] ],
		] );

		$gen = $this->makeGenerator(
			[ 'Has tag' => $textProp ],
			[ 'Tag' => $tagCat ]
		);

		$catName = 'SomeTarget_' . uniqid();
		$category = new EffectiveCategoryModel( $catName, [
			'properties' => [ 'required' => [], 'optional' => [] ],
		] );

		$gen->generateOrUpdateDisplayStub( $category );

		$title = $this->pageCreator->makeTitle( "$catName/display", NS_TEMPLATE );
		$content = $this->pageCreator->getPageContent( $title );

		$this->assertStringNotContainsString( '{{#ask:', $content );
	}
}
