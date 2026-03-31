<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit\Generator;

use MediaWiki\Extension\SemanticSchemas\Generator\FormGenerator;
use MediaWiki\Extension\SemanticSchemas\Generator\PropertyInputMapper;
use MediaWiki\Extension\SemanticSchemas\Schema\EffectiveCategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\SubobjectModel;
use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;
use MediaWiki\Extension\SemanticSchemas\Store\WikiSubobjectStore;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Generator\FormGenerator
 */
class FormGeneratorTest extends TestCase {

	private FormGenerator $generator;
	private WikiSubobjectStore $subobjectStore;
	private WikiPropertyStore $propertyStore;

	protected function setUp(): void {
		parent::setUp();

		$this->propertyStore = $this->createMock( WikiPropertyStore::class );
		$this->propertyStore->method( 'readProperty' )->willReturn( null );

		$this->subobjectStore = $this->createMock( WikiSubobjectStore::class );

		$inputMapper = $this->createMock( PropertyInputMapper::class );
		$inputMapper->method( 'generateInputDefinition' )
			->willReturn( 'input type=text' );

		$this->generator = new FormGenerator(
			$this->createMock( PageCreator::class ),
			$this->propertyStore,
			$inputMapper,
			$this->subobjectStore
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

	public function testCompositeFormConvertsSubobjectHeadingsToHtml(): void {
		$subobject = new SubobjectModel( 'Address', [
			'label' => 'Address',
			'properties' => [
				'required' => [ 'Has street' ],
				'optional' => [],
			],
		] );

		$this->subobjectStore->method( 'readSubobject' )
			->willReturn( $subobject );

		$category = new EffectiveCategoryModel( 'Person', [
			'label' => 'Person',
			'properties' => [
				'required' => [],
				'optional' => [],
			],
			'subobjects' => [
				'required' => [ 'Address' ],
				'optional' => [],
			],
		] );

		$result = $this->generator->generateCompositeForm( $category );

		// Subobject heading should be converted from === to <h3>
		$this->assertStringContainsString( '<h3>Address</h3>', $result );
		$this->assertStringNotContainsString( '=== Address ===', $result );
	}

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
	 * Helpers
	 * ========================================================================= */

	private function extractIncludeOnly( string $wikitext ): string {
		if ( preg_match( '/<includeonly>(.*?)<\/includeonly>/s', $wikitext, $m ) ) {
			return $m[1];
		}
		return '';
	}
}
