<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit\Generator;

use MediaWiki\Extension\SemanticSchemas\Generator\FormGenerator;
use MediaWiki\Extension\SemanticSchemas\Generator\PropertyInputMapper;
use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\EffectiveCategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\InheritanceResolver;
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
				[ 'name' => 'Has name', 'required' => true ],
			],
		] );

		$result = $this->generator->generateCompositeForm( $category, new InheritanceResolver( [] ) );

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
			'properties' => [],
		] );

		$result = $this->generator->generateCompositeForm( $category, new InheritanceResolver( [] ) );

		$this->assertStringContainsString( '<h2>Person</h2>', $result );
	}

	/* =========================================================================
	 * COMPOSITE SLOT — structure
	 * ========================================================================= */

	public function testCompositeFormContainsForTemplateAndEndTemplate(): void {
		$category = new EffectiveCategoryModel( 'Animal', [
			'label' => 'Animal',
			'properties' => [
				[ 'name' => 'Has species', 'required' => true ],
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
				[ 'name' => 'Has color', 'required' => true ],
				[ 'name' => 'Has weight', 'required' => false ],
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
				[ 'name' => 'Has name', 'required' => true ],
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
				[ 'name' => 'Has name', 'required' => true ],
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
		$subCategory = new CategoryModel( 'Address', [
			'properties' => [
				[ 'name' => 'Has street', 'required' => true ],
				[ 'name' => 'Has city', 'required' => false ],
			],
		] );

		$category = new EffectiveCategoryModel( 'Person', [
			'properties' => [
				[ 'name' => 'Has name', 'required' => true ],
			],
			'subobjects' => [
				[ 'name' => 'Address', 'required' => true ],
			],
		] );

		$resolver = new InheritanceResolver( [
			'Person' => new CategoryModel( 'Person', $category->toArray() ),
			'Address' => $subCategory,
		] );

		$result = $this->generator->generateCompositeForm( $category, $resolver );

		// Wiki === headings should be converted to <h3> for transclusion
		$this->assertStringContainsString( '<h3>Address</h3>', $result );
		$this->assertStringNotContainsString( '=== Address ===', $result );
	}

	public function testCompositeFormIncludesSubobjectFields(): void {
		$subCategory = new CategoryModel( 'Phone', [
			'label' => 'Phone Numbers',
			'properties' => [
				[ 'name' => 'Has phone number', 'required' => true ],
				[ 'name' => 'Has phone type', 'required' => false ],
			],
		] );

		$category = new EffectiveCategoryModel( 'Person', [
			'properties' => [],
			'subobjects' => [
				[ 'name' => 'Phone', 'required' => false ],
			],
		] );

		$resolver = new InheritanceResolver( [
			'Person' => new CategoryModel( 'Person', $category->toArray() ),
			'Phone' => $subCategory,
		] );

		$result = $this->generator->generateCompositeForm( $category, $resolver );

		$this->assertStringContainsString( 'Phone/subobject', $result );
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
