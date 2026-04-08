<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit\Generator;

use MediaWiki\Extension\SemanticSchemas\Generator\FormGenerator;
use MediaWiki\Extension\SemanticSchemas\Generator\PropertyInputMapper;
use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\EffectiveCategoryModel;
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

	public function testCompositeFormWrapsTripleBraceFieldsInNowiki(): void {
		$category = new EffectiveCategoryModel( 'Person', [
			'label' => 'Person',
			'properties' => [
				'required' => [ 'Has name' ],
				'optional' => [],
			],
		] );

		$result = $this->generator->generateCompositeForm( $category );

		// {{{for template|...}}} and {{{end template}}} should be wrapped
		$this->assertStringContainsString( '<nowiki>{{{for template|Person}}}</nowiki>', $result );
		$this->assertStringContainsString( '<nowiki>{{{end template}}}</nowiki>', $result );

		// {{{field|...}}} directives should be wrapped
		$this->assertMatchesRegularExpression( '/<nowiki>\{\{\{field\|[^}]+\}\}\}<\/nowiki>/', $result );

		// No unwrapped triple-brace directives in the <includeonly> section
		$includeOnly = $this->extractIncludeOnly( $result );
		$this->assertDoesNotMatchRegularExpression(
			'/(?<!<nowiki>)\{\{\{[^}]+\}\}\}(?!<\/nowiki>)/',
			$includeOnly,
			'All {{{...}}} directives in <includeonly> must be wrapped in <nowiki> tags'
		);
	}

	/* =========================================================================
	 * COMPOSITE SLOT — heading conversion
	 * ========================================================================= */

	public function testCompositeFormCategoryHeadingIsHtmlH2(): void {
		$category = new EffectiveCategoryModel( 'Person', [
			'label' => 'Person',
			'properties' => [
				'required' => [],
				'optional' => [],
			],
		] );

		$result = $this->generator->generateCompositeForm( $category );

		$this->assertStringContainsString( '<h2>Person</h2>', $result );
	}

	/* =========================================================================
	 * COMPOSITE SLOT — structure
	 * ========================================================================= */

	public function testCompositeFormContainsForTemplateAndEndTemplate(): void {
		$category = new EffectiveCategoryModel( 'Animal', [
			'label' => 'Animal',
			'properties' => [
				'required' => [ 'Has species' ],
				'optional' => [],
			],
		] );

		$result = $this->generator->generateCompositeForm( $category );

		$this->assertStringContainsString( '{{{for template|Animal}}}', $result );
		$this->assertStringContainsString( '{{{end template}}}', $result );
	}

	public function testCompositeFormIncludesFieldForEachProperty(): void {
		$category = new EffectiveCategoryModel( 'Thing', [
			'label' => 'Thing',
			'properties' => [
				'required' => [ 'Has color' ],
				'optional' => [ 'Has weight' ],
			],
		] );

		$result = $this->generator->generateCompositeForm( $category );

		$this->assertStringContainsString( 'field|has_color', $result );
		$this->assertStringContainsString( 'field|has_weight', $result );
	}

	public function testCompositeFormWrappedInNoincludeAndIncludeonly(): void {
		$category = new EffectiveCategoryModel( 'Item', [
			'label' => 'Item',
			'properties' => [
				'required' => [],
				'optional' => [],
			],
		] );

		$result = $this->generator->generateCompositeForm( $category );

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
				'required' => [ 'Has name' ],
				'optional' => [],
			],
		] );

		$result = $this->generator->generateForm( $category );

		$this->assertStringContainsString( '{{Form:Person/composite}}', $result );
	}

	public function testRegularFormIncludesStandardInputs(): void {
		$category = new EffectiveCategoryModel( 'Person', [
			'label' => 'Person',
			'properties' => [
				'required' => [],
				'optional' => [],
			],
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
				'required' => [ 'Has name' ],
				'optional' => [],
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

	public function testCompositeFormConvertsSubobjectHeadingsToHtml(): void {
		$catStore = $this->createMock( WikiCategoryStore::class );
		$subCategory = new CategoryModel( 'Address', [
			'properties' => [
				'required' => [ 'Has street' ],
				'optional' => [ 'Has city' ],
			],
		] );

		$catStore->method( 'readCategory' )
			->with( 'Address' )
			->willReturn( $subCategory );

		$inputMapper = $this->createMock( PropertyInputMapper::class );
		$inputMapper->method( 'generateInputDefinition' )
			->willReturn( 'input type=text' );

		$gen = new FormGenerator(
			$this->createMock( PageCreator::class ),
			$this->propertyStore,
			$inputMapper,
			$catStore
		);

		$category = new EffectiveCategoryModel( 'Person', [
			'properties' => [ 'required' => [ 'Has name' ], 'optional' => [] ],
			'subobjects' => [ 'required' => [ 'Address' ], 'optional' => [] ],
		] );

		$result = $gen->generateCompositeForm( $category );

		// Wiki === headings should be converted to <h3> for transclusion
		$this->assertStringContainsString( '<h3>Address</h3>', $result );
		$this->assertStringNotContainsString( '=== Address ===', $result );
	}

	public function testCompositeFormIncludesSubobjectFields(): void {
		$catStore = $this->createMock( WikiCategoryStore::class );
		$subCategory = new CategoryModel( 'Phone', [
			'label' => 'Phone Numbers',
			'properties' => [
				'required' => [ 'Has phone number' ],
				'optional' => [ 'Has phone type' ],
			],
		] );

		$catStore->method( 'readCategory' )
			->with( 'Phone' )
			->willReturn( $subCategory );

		$inputMapper = $this->createMock( PropertyInputMapper::class );
		$inputMapper->method( 'generateInputDefinition' )
			->willReturn( 'input type=text' );

		$gen = new FormGenerator(
			$this->createMock( PageCreator::class ),
			$this->propertyStore,
			$inputMapper,
			$catStore
		);

		$category = new EffectiveCategoryModel( 'Person', [
			'properties' => [ 'required' => [], 'optional' => [] ],
			'subobjects' => [ 'required' => [], 'optional' => [ 'Phone' ] ],
		] );

		$result = $gen->generateCompositeForm( $category );

		$this->assertStringContainsString( 'Subobject/Phone', $result );
		$this->assertStringContainsString( 'has_phone_number', $result );
	}

	/* =========================================================================
	 * Helpers
	 * ========================================================================= */

	private function extractIncludeOnly( string $wikitext ): string {
		if ( preg_match( '/<includeonly>(.*?)<\/includeonly>/s', $wikitext, $m ) ) {
			return $m[1];
		}
		return '';
	}
}
