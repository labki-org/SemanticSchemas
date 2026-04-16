<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit\Generator;

use MediaWiki\Extension\SemanticSchemas\Generator\FormGenerator;
use MediaWiki\Extension\SemanticSchemas\Generator\PropertyInputMapper;
use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\EffectiveCategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\FieldModel;
use MediaWiki\Extension\SemanticSchemas\Schema\InheritanceResolver;
use MediaWiki\Extension\SemanticSchemas\Schema\PropertyModel;
use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Generator\FormGenerator
 */
class FormGeneratorTest extends TestCase {

	private FormGenerator $generator;
	private WikiPropertyStore $propertyStore;

	protected function setUp(): void {
		parent::setUp();

		$this->propertyStore = $this->createMock( WikiPropertyStore::class );
		$this->propertyStore->method( 'readProperty' )->willReturn( null );

		$inputMapper = $this->createMock( PropertyInputMapper::class );
		$inputMapper->method( 'generateInputDefinition' )
			->willReturn( 'input type=text' );

		$this->generator = new FormGenerator(
			$this->createMock( PageCreator::class ),
			$this->propertyStore,
			$inputMapper,
			$this->createMock( WikiCategoryStore::class )
		);
	}

	/* =========================================================================
	 * COMPOSITE SLOT — nowiki wrapping
	 * ========================================================================= */

	public function testCompositeFormWrapsPageFormsDirectivesInNowiki(): void {
		$category = new EffectiveCategoryModel( 'Person', [
			'label' => 'Person',
			'properties' => [
				FieldModel::property( 'Has name', true ),
			],
		] );

		$result = $this->generator->generateCompositeForm( $category, new InheritanceResolver( [] ) );

		// {{{for template|...}}} and {{{end template}}} should be wrapped
		$this->assertStringContainsString( '<nowiki>{{{for template|Person}}}</nowiki>', $result );
		$this->assertStringContainsString( '<nowiki>{{{end template}}}</nowiki>', $result );

		// {{{field|...}}} directives should be wrapped
		$this->assertMatchesRegularExpression( '/<nowiki>\{\{\{field\|[^}]+\}\}\}<\/nowiki>/', $result );
	}

	/* =========================================================================
	 * COMPOSITE SLOT — heading conversion
	 * ========================================================================= */

	public function testCompositeFormHeadingIsConditionalOnMode(): void {
		$category = new EffectiveCategoryModel( 'Person', [
			'label' => 'Person',
			'properties' => [],
		] );

		$result = $this->generator->generateCompositeForm( $category, new InheritanceResolver( [] ) );

		// Standalone mode gets <h2>, subobject mode gets <h3>
		$this->assertStringContainsString( '<h2>Person</h2>', $result );
		$this->assertStringContainsString( '<h3>Person</h3>', $result );
		$this->assertStringContainsString( '{{{subobject|}}}', $result );
	}

	/* =========================================================================
	 * COMPOSITE SLOT — structure
	 * ========================================================================= */

	public function testCompositeFormContainsForTemplateAndEndTemplate(): void {
		$category = new EffectiveCategoryModel( 'Animal', [
			'label' => 'Animal',
			'properties' => [
				FieldModel::property( 'Has species', true ),
			],
		] );

		$result = $this->generator->generateCompositeForm( $category, new InheritanceResolver( [] ) );

		$this->assertStringContainsString( '{{{for template|Animal}}}', $result );
		$this->assertStringContainsString( '{{{end template}}}', $result );
	}

	public function testCompositeFormIncludesFieldForEachProperty(): void {
		$category = new EffectiveCategoryModel( 'Thing', [
			'label' => 'Thing',
			'properties' => [
				FieldModel::property( 'Has color', true ),
				FieldModel::property( 'Has weight', false ),
			],
		] );

		$result = $this->generator->generateCompositeForm( $category, new InheritanceResolver( [] ) );

		$this->assertStringContainsString( 'field|has_color', $result );
		$this->assertStringContainsString( 'field|has_weight', $result );
	}

	public function testCompositeFormWrappedInNoincludeAndIncludeonly(): void {
		$category = new EffectiveCategoryModel( 'Item', [
			'label' => 'Item',
			'properties' => [],
		] );

		$result = $this->generator->generateCompositeForm( $category, new InheritanceResolver( [] ) );

		$this->assertStringContainsString( '<noinclude>', $result );
		$this->assertStringContainsString( '</noinclude><includeonly>', $result );
		$this->assertStringContainsString( '</includeonly>', $result );
	}

	/* =========================================================================
	 * REGULAR FORM — transcludes composite slot
	 * ========================================================================= */

	public function testRegularFormTranscludesCompositeSlot(): void {
		$category = new EffectiveCategoryModel( 'Person', [
			'label' => 'Person',
			'properties' => [
				FieldModel::property( 'Has name', true ),
			],
		] );

		$result = $this->generator->generateForm( $category );

		$this->assertStringContainsString( '{{Form:Person/composite}}', $result );
	}

	public function testRegularFormIncludesStandardInputs(): void {
		$category = new EffectiveCategoryModel( 'Person', [
			'label' => 'Person',
			'properties' => [],
		] );

		$result = $this->generator->generateForm( $category );

		$this->assertStringContainsString( '{{{standard input|free text', $result );
		$this->assertStringContainsString( '{{{standard input|save}}}', $result );
		$this->assertStringContainsString( '{{{standard input|cancel}}}', $result );
	}

	public function testRegularFormDoesNotContainDirectFieldDefinitions(): void {
		$category = new EffectiveCategoryModel( 'Person', [
			'label' => 'Person',
			'properties' => [
				FieldModel::property( 'Has name', true ),
			],
		] );

		$result = $this->generator->generateForm( $category );

		// Field definitions live in the composite slot, not the main form
		$this->assertStringNotContainsString( '{{{field|', $result );
		$this->assertStringNotContainsString( '{{{for template|', $result );
	}

	/* =========================================================================
	 * COMPOSITE SLOT — subobject sections
	 * ========================================================================= */

	public function testCompositeFormTranscludesRequiredSubobjectComposite(): void {
		$subCategory = new CategoryModel( 'Address', [
			'properties' => [
				FieldModel::property( 'Has street', true ),
			],
		] );

		$category = new EffectiveCategoryModel( 'Person', [
			'properties' => [
				FieldModel::property( 'Has name', true ),
			],
			'subobjects' => [
				FieldModel::subobject( 'Address', true ),
			],
		] );

		$resolver = new InheritanceResolver( [
			'Person' => new CategoryModel( 'Person', $category->toArray() ),
			'Address' => $subCategory,
		] );

		$result = $this->generator->generateCompositeForm( $category, $resolver );

		// Transcludes subobject's composite form instead of duplicating fields
		$this->assertStringContainsString(
			'{{Form:Address/composite|subobject=true|required=true}}',
			$result
		);
		// Should NOT contain inline subobject field definitions
		$this->assertStringNotContainsString( 'has_street', $result );
	}

	public function testCompositeFormTranscludesOptionalSubobjectComposite(): void {
		$subCategory = new CategoryModel( 'Phone', [
			'properties' => [
				FieldModel::property( 'Has phone number', true ),
			],
		] );

		$category = new EffectiveCategoryModel( 'Person', [
			'properties' => [],
			'subobjects' => [
				FieldModel::subobject( 'Phone', false ),
			],
		] );

		$resolver = new InheritanceResolver( [
			'Person' => new CategoryModel( 'Person', $category->toArray() ),
			'Phone' => $subCategory,
		] );

		$result = $this->generator->generateCompositeForm( $category, $resolver );

		// Optional subobject: subobject=true but no required param
		$this->assertStringContainsString(
			'{{Form:Phone/composite|subobject=true}}',
			$result
		);
		$this->assertStringNotContainsString( 'required=true', $result );
	}

	public function testCompositeFormSubobjectModeHasForTemplateVariants(): void {
		$category = new EffectiveCategoryModel( 'Shape', [
			'label' => 'Shape',
			'properties' => [
				FieldModel::property( 'Has width', true ),
			],
		] );

		$result = $this->generator->generateCompositeForm(
			$category, new InheritanceResolver( [] )
		);

		// Standalone mode
		$this->assertStringContainsString(
			'{{{for template|Shape}}}', $result
		);
		// Subobject mode variants
		$this->assertStringContainsString(
			'{{{for template|Shape/subobject|multiple}}}', $result
		);
		$this->assertStringContainsString(
			'{{{for template|Shape/subobject|multiple|minimum instances=1}}}', $result
		);
	}

	/* =========================================================================
	 * HIDDEN PROPERTIES
	 * ========================================================================= */

	/**
	 * Build a FormGenerator whose property store returns specific PropertyModel instances.
	 */
	private function generatorWithProperties( array $propertyMap ): FormGenerator {
		$propStore = $this->createMock( WikiPropertyStore::class );
		$propStore->method( 'readProperty' )
			->willReturnCallback(
				static fn ( string $name ) => $propertyMap[$name] ?? null
			);

		$inputMapper = $this->createMock( PropertyInputMapper::class );
		$inputMapper->method( 'generateInputDefinition' )
			->willReturn( 'input type=text' );

		return new FormGenerator(
			$this->createMock( PageCreator::class ),
			$propStore,
			$inputMapper,
			$this->createMock( WikiCategoryStore::class )
		);
	}

	public function testHiddenPropertyExcludedFromCompositeForm(): void {
		$gen = $this->generatorWithProperties( [
			'Has sort order' => new PropertyModel( 'Has sort order', [
				'datatype' => 'Number',
				'hidden' => true,
			] ),
		] );

		$category = new EffectiveCategoryModel( 'Thing', [
			'label' => 'Thing',
			'properties' => [
				FieldModel::property( 'Has name', true ),
				FieldModel::property( 'Has sort order', false ),
			],
		] );

		$result = $gen->generateCompositeForm(
			$category, new InheritanceResolver( [] )
		);

		$this->assertStringContainsString( 'has_name', $result );
		$this->assertStringNotContainsString( 'has_sort_order', $result );
	}

	public function testNonHiddenPropertyIncludedInCompositeForm(): void {
		$gen = $this->generatorWithProperties( [
			'Has weight' => new PropertyModel( 'Has weight', [
				'datatype' => 'Number',
				'hidden' => false,
			] ),
		] );

		$category = new EffectiveCategoryModel( 'Thing', [
			'label' => 'Thing',
			'properties' => [
				FieldModel::property( 'Has weight', true ),
			],
		] );

		$result = $gen->generateCompositeForm(
			$category, new InheritanceResolver( [] )
		);

		$this->assertStringContainsString( 'has_weight', $result );
	}

}
