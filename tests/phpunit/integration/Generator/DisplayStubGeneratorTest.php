<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Integration\Generator;

use MediaWiki\Extension\SemanticSchemas\Generator\DisplayStubGenerator;
use MediaWiki\Extension\SemanticSchemas\Schema\EffectiveCategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\PropertyModel;
use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
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
			$services->getDeletePageFactory(),
		);
	}

	/**
	 * Create a DisplayStubGenerator with a mocked property store.
	 *
	 * @param array<string, PropertyModel> $propertyMap
	 * @return DisplayStubGenerator
	 */
	private function makeGenerator(
		array $propertyMap = []
	): DisplayStubGenerator {
		$propStore = $this->createMock( WikiPropertyStore::class );
		$propStore->method( 'readProperty' )
			->willReturnCallback( static fn ( string $name ) => $propertyMap[$name] ?? null );
		$language = $this->getServiceContainer()->getContentLanguage();

		return new DisplayStubGenerator( $this->pageCreator, $propStore, $language );
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
		$props = [];
		foreach ( $requiredProps as $name ) {
			$props[] = [ 'name' => $name, 'required' => true ];
		}
		foreach ( $optionalProps as $name ) {
			$props[] = [ 'name' => $name, 'required' => false ];
		}
		$category = new EffectiveCategoryModel( $categoryName, [
			'properties' => $props,
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

		$this->assertStringContainsString( '{{#if: {{{has_name|}}} |', $content );
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

		$this->assertStringContainsString( '{{#if: {{{has_name|}}} |', $content );
		$this->assertStringContainsString( '{{#if: {{{has_email|}}} |', $content );
		$this->assertStringContainsString( '{{#if: {{{has_phone|}}} |', $content );
	}

	public function testOptionalPropertiesAlsoHaveIfCondition(): void {
		$content = $this->generateAndRead(
			'TestCat_' . uniqid(),
			[],
			[ 'Has nickname' ]
		);

		$this->assertStringContainsString( '{{#if: {{{has_nickname|}}} |', $content );
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

		$this->assertStringContainsString( '{{#if: {{{has_email|}}} |', $content );
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

	public function testBacklinkRowWithReverseLabel(): void {
		$hasProject = new PropertyModel( 'Has project', [
			'datatype' => 'Page',
			'allowedCategory' => 'Project',
			'inverseLabel' => 'Components',
		] );

		$gen = $this->makeGenerator(
			[ 'Has project' => $hasProject ]
		);

		$catName = 'Project';
		$category = new EffectiveCategoryModel( $catName, [
			'properties' => [
				[ 'name' => 'Has name', 'required' => true ],
			],
			'backlinksFor' => [ 'Has project' ],
		] );

		$gen->generateOrUpdateDisplayStub( $category );

		$title = $this->pageCreator->makeTitle( "$catName/display", NS_TEMPLATE );
		$content = $this->pageCreator->getPageContent( $title );

		// Backlinks header
		$this->assertStringContainsString( 'Backlinks', $content );
		// Row labeled with the reverse label
		$this->assertStringContainsString( '! Components', $content );
		// Ask query finds all pages linking here via this property
		$this->assertStringContainsString( '[[Has project::{{FULLPAGENAME}}]]', $content );
		$this->assertStringContainsString( 'format=list', $content );
	}

	public function testBacklinkRowFallsBackToPropertyLabel(): void {
		$hasProject = new PropertyModel( 'Has project', [
			'datatype' => 'Page',
			'allowedCategory' => 'Project',
		] );

		$gen = $this->makeGenerator(
			[ 'Has project' => $hasProject ]
		);

		$catName = 'Project';
		$category = new EffectiveCategoryModel( $catName, [
			'properties' => [],
			'backlinksFor' => [ 'Has project' ],
		] );

		$gen->generateOrUpdateDisplayStub( $category );

		$title = $this->pageCreator->makeTitle( "$catName/display", NS_TEMPLATE );
		$content = $this->pageCreator->getPageContent( $title );

		// Falls back to property name when no inverse label set
		$this->assertStringContainsString( '! Has project', $content );
	}

	public function testNoBacklinkRowsWithoutDeclaration(): void {
		$hasProject = new PropertyModel( 'Has project', [
			'datatype' => 'Page',
			'allowedCategory' => 'Project',
		] );

		$gen = $this->makeGenerator(
			[ 'Has project' => $hasProject ]
		);

		// Category does NOT declare backlinksFor — no rows should appear
		$catName = 'Project';
		$category = new EffectiveCategoryModel( $catName, [
			'properties' => [],
		] );

		$gen->generateOrUpdateDisplayStub( $category );

		$title = $this->pageCreator->makeTitle( "$catName/display", NS_TEMPLATE );
		$content = $this->pageCreator->getPageContent( $title );

		$this->assertStringNotContainsString( '{{#ask:', $content );
		$this->assertStringNotContainsString( 'Backlinks', $content );
	}

	public function testNonPageTypeBacklinkPropertyIgnored(): void {
		$textProp = new PropertyModel( 'Has tag', [
			'datatype' => 'Text',
			'reverseLabel' => 'Tagged items',
		] );

		$gen = $this->makeGenerator(
			[ 'Has tag' => $textProp ]
		);

		$catName = 'SomeTarget_' . uniqid();
		$category = new EffectiveCategoryModel( $catName, [
			'properties' => [],
			'backlinksFor' => [ 'Has tag' ],
		] );

		$gen->generateOrUpdateDisplayStub( $category );

		$title = $this->pageCreator->makeTitle( "$catName/display", NS_TEMPLATE );
		$content = $this->pageCreator->getPageContent( $title );

		$this->assertStringNotContainsString( '{{#ask:', $content );
	}
}
