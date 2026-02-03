<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit\Generator;

use InvalidArgumentException;
use MediaWiki\Extension\SemanticSchemas\Generator\TemplateGenerator;
use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;
use MediaWiki\Extension\SemanticSchemas\Store\WikiSubobjectStore;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Generator\TemplateGenerator
 *
 * Note: Tests for generateAllTemplates() and page persistence require
 * a full MediaWiki environment. These tests focus on template content
 * generation which can be tested in isolation.
 */
class TemplateGeneratorTest extends TestCase {

	private TemplateGenerator $generator;

	protected function setUp(): void {
		parent::setUp();

		$this->generator = new TemplateGenerator(
			$this->createMock( PageCreator::class ),
			$this->createMock( WikiSubobjectStore::class ),
			$this->createMock( WikiPropertyStore::class )
		);
	}

	/* =========================================================================
	 * SEMANTIC TEMPLATE GENERATION
	 * ========================================================================= */

	public function testGenerateSemanticTemplateReturnsString(): void {
		$category = new CategoryModel( 'Person', [
			'properties' => [
				'required' => [ 'Has name' ],
				'optional' => [],
			],
		] );

		$result = $this->generator->generateSemanticTemplate( $category );
		$this->assertIsString( $result );
	}

	public function testGenerateSemanticTemplateContainsNoInclude(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generator->generateSemanticTemplate( $category );

		$this->assertStringContainsString( '<noinclude>', $result );
		$this->assertStringContainsString( '</noinclude>', $result );
	}

	public function testGenerateSemanticTemplateContainsIncludeOnly(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generator->generateSemanticTemplate( $category );

		$this->assertStringContainsString( '<includeonly>', $result );
		$this->assertStringContainsString( '</includeonly>', $result );
	}

	public function testGenerateSemanticTemplateContainsSetParser(): void {
		$category = new CategoryModel( 'Person', [
			'properties' => [
				'required' => [ 'Has name' ],
				'optional' => [],
			],
		] );

		$result = $this->generator->generateSemanticTemplate( $category );
		$this->assertStringContainsString( '{{#set:', $result );
		$this->assertStringContainsString( '}}', $result );
	}

	public function testGenerateSemanticTemplateContainsPropertyMappings(): void {
		$category = new CategoryModel( 'Person', [
			'properties' => [
				'required' => [ 'Has name', 'Has email' ],
				'optional' => [],
			],
		] );

		$result = $this->generator->generateSemanticTemplate( $category );
		// Should contain property to parameter mappings
		$this->assertStringContainsString( 'Has name', $result );
		$this->assertStringContainsString( 'Has email', $result );
		$this->assertStringContainsString( '{{{name|}}}', $result );
		$this->assertStringContainsString( '{{{email|}}}', $result );
		// Properties should be wrapped in #if guards
		$this->assertStringContainsString( '{{#if:{{{name|}}}|{{{name|}}}|}}', $result );
		$this->assertStringContainsString( '{{#if:{{{email|}}}|{{{email|}}}|}}', $result );
	}

	public function testGenerateSemanticTemplateContainsCategoryLink(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generator->generateSemanticTemplate( $category );

		$this->assertStringContainsString( '[[Category:Person]]', $result );
	}

	public function testGenerateSemanticTemplateWithEmptyNameThrowsException(): void {
		$this->expectException( InvalidArgumentException::class );

		// Create a mock that returns empty name
		$category = $this->createMock( CategoryModel::class );
		$category->method( 'getName' )->willReturn( '' );
		$category->method( 'getAllProperties' )->willReturn( [] );

		$this->generator->generateSemanticTemplate( $category );
	}

	public function testGenerateSemanticTemplatePropertiesAreSorted(): void {
		$category = new CategoryModel( 'Person', [
			'properties' => [
				'required' => [ 'Has zoo', 'Has apple', 'Has middle' ],
				'optional' => [],
			],
		] );

		$result = $this->generator->generateSemanticTemplate( $category );

		// Properties should be sorted alphabetically
		$applePos = strpos( $result, 'Has apple' );
		$middlePos = strpos( $result, 'Has middle' );
		$zooPos = strpos( $result, 'Has zoo' );

		$this->assertLessThan( $middlePos, $applePos );
		$this->assertLessThan( $zooPos, $middlePos );
	}

	/* =========================================================================
	 * DISPATCHER TEMPLATE GENERATION
	 * ========================================================================= */

	public function testGenerateDispatcherTemplateReturnsString(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generator->generateDispatcherTemplate( $category );

		$this->assertIsString( $result );
	}

	public function testGenerateDispatcherTemplateContainsDefaultForm(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generator->generateDispatcherTemplate( $category );

		$this->assertStringContainsString( '{{#default_form:Person}}', $result );
	}

	public function testGenerateDispatcherTemplateCallsSemanticTemplate(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generator->generateDispatcherTemplate( $category );

		$this->assertStringContainsString( '{{Person/semantic', $result );
	}

	public function testGenerateDispatcherTemplateCallsDisplayTemplate(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generator->generateDispatcherTemplate( $category );

		$this->assertStringContainsString( '{{Person/display', $result );
	}

	public function testGenerateDispatcherTemplateDoesNotContainHierarchyWidget(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generator->generateDispatcherTemplate( $category );

		$this->assertStringNotContainsString( '{{#semanticschemas_hierarchy:', $result );
	}

	public function testGenerateDispatcherTemplatePassesParameters(): void {
		$category = new CategoryModel( 'Person', [
			'properties' => [
				'required' => [ 'Has name' ],
				'optional' => [],
			],
		] );

		$result = $this->generator->generateDispatcherTemplate( $category );
		// Should pass parameter to sub-templates
		$this->assertStringContainsString( '| name = {{{name|}}}', $result );
	}

	public function testGenerateDispatcherTemplateWithEmptyNameThrowsException(): void {
		$this->expectException( InvalidArgumentException::class );

		$category = $this->createMock( CategoryModel::class );
		$category->method( 'getName' )->willReturn( '' );
		$category->method( 'getAllProperties' )->willReturn( [] );
		$category->method( 'getRequiredSubobjects' )->willReturn( [] );
		$category->method( 'getOptionalSubobjects' )->willReturn( [] );

		$this->generator->generateDispatcherTemplate( $category );
	}

	/* =========================================================================
	 * PARAMETER NAME CONVERSION
	 * ========================================================================= */

	public function testPropertyToParameterConversionInTemplate(): void {
		$category = new CategoryModel( 'Person', [
			'properties' => [
				'required' => [ 'Has full name' ],
				'optional' => [],
			],
		] );

		$result = $this->generator->generateSemanticTemplate( $category );

		// "Has full name" should convert to "full_name" parameter
		$this->assertStringContainsString( '{{{full_name|}}}', $result );
		// Should be wrapped in #if guard
		$this->assertStringContainsString( '{{#if:{{{full_name|}}}|{{{full_name|}}}|}}', $result );
	}

	public function testMultiplePropertiesConvertedCorrectly(): void {
		$category = new CategoryModel( 'Person', [
			'properties' => [
				'required' => [ 'Has first name', 'Has last name', 'Has email address' ],
				'optional' => [],
			],
		] );

		$result = $this->generator->generateSemanticTemplate( $category );

		$this->assertStringContainsString( '{{{first_name|}}}', $result );
		$this->assertStringContainsString( '{{{last_name|}}}', $result );
		$this->assertStringContainsString( '{{{email_address|}}}', $result );
		// All should be wrapped in #if guards
		$this->assertStringContainsString( '{{#if:{{{first_name|}}}|{{{first_name|}}}|}}', $result );
		$this->assertStringContainsString( '{{#if:{{{last_name|}}}|{{{last_name|}}}|}}', $result );
		$this->assertStringContainsString( '{{#if:{{{email_address|}}}|{{{email_address|}}}|}}', $result );
	}

	/* =========================================================================
	 * GENERATE ALL TEMPLATES
	 * ========================================================================= */

	public function testGenerateAllTemplatesReturnsArray(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generator->generateAllTemplates( $category );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'errors', $result );
	}

	public function testGenerateAllTemplatesSuccessWhenNoErrors(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generator->generateAllTemplates( $category );

		$this->assertTrue( $result['success'] );
		$this->assertEmpty( $result['errors'] );
	}

	/* =========================================================================
	 * AUTO-GENERATED COMMENTS
	 * ========================================================================= */

	public function testSemanticTemplateContainsAutoGeneratedComment(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generator->generateSemanticTemplate( $category );

		$this->assertStringContainsString( 'AUTO-GENERATED by SemanticSchemas', $result );
		$this->assertStringContainsString( 'DO NOT EDIT MANUALLY', $result );
	}

	public function testDispatcherTemplateContainsAutoGeneratedComment(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generator->generateDispatcherTemplate( $category );

		$this->assertStringContainsString( 'AUTO-GENERATED by SemanticSchemas', $result );
		$this->assertStringContainsString( 'DO NOT EDIT MANUALLY', $result );
	}

	/* =========================================================================
	 * EDGE CASES
	 * ========================================================================= */

	public function testGenerateSemanticTemplateWithNoProperties(): void {
		$category = new CategoryModel( 'EmptyCategory' );
		$result = $this->generator->generateSemanticTemplate( $category );

		// Should still generate valid template structure
		$this->assertStringContainsString( '{{#set:', $result );
		$this->assertStringContainsString( '[[Category:EmptyCategory]]', $result );
	}

	public function testCategoryNameWithSpacesHandledCorrectly(): void {
		$category = new CategoryModel( 'PhD Student' );
		$result = $this->generator->generateSemanticTemplate( $category );

		$this->assertStringContainsString( '[[Category:PhD Student]]', $result );
	}

	/* =========================================================================
	 * CONDITIONAL #IF GUARDS AND MULTI-VALUE +sep
	 * ========================================================================= */

	public function testGenerateSemanticTemplateWrapsPropertiesInIfGuard(): void {
		$category = new CategoryModel( 'Person', [
			'properties' => [
				'required' => [ 'Has name' ],
				'optional' => [],
			],
		] );

		$result = $this->generator->generateSemanticTemplate( $category );

		// Core requirement: all properties wrapped in #if guard
		$this->assertStringContainsString( '{{#if:{{{name|}}}|{{{name|}}}|}}', $result );
	}

	public function testGenerateSemanticTemplateUsePlusSepForMultiValue(): void {
		// Mock WikiPropertyStore to return a multi-value property
		$propModel = $this->createMock( \MediaWiki\Extension\SemanticSchemas\Schema\PropertyModel::class );
		$propModel->method( 'isPageType' )->willReturn( false );
		$propModel->method( 'getAllowedNamespace' )->willReturn( null );
		$propModel->method( 'allowsMultipleValues' )->willReturn( true );

		$propertyStore = $this->createMock( \MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore::class );
		$propertyStore->method( 'readProperty' )->willReturn( $propModel );

		$generator = new TemplateGenerator( null, null, $propertyStore );

		$category = new CategoryModel( 'Person', [
			'properties' => [
				'required' => [ 'Has keywords' ],
				'optional' => [],
			],
		] );

		$result = $generator->generateSemanticTemplate( $category );

		// Multi-value properties should include |+sep=,
		$this->assertStringContainsString( '|+sep=,', $result );
		// Should also have #if guard
		$this->assertStringContainsString( '{{#if:{{{keywords|}}}|{{{keywords|}}}|}}', $result );
	}

	public function testGenerateSemanticTemplateIfGuardForPageTypeWithNamespace(): void {
		// Mock WikiPropertyStore to return a single-value Page property with namespace
		$propModel = $this->createMock( \MediaWiki\Extension\SemanticSchemas\Schema\PropertyModel::class );
		$propModel->method( 'isPageType' )->willReturn( true );
		$propModel->method( 'getAllowedNamespace' )->willReturn( 'Property' );
		$propModel->method( 'allowsMultipleValues' )->willReturn( false );

		$propertyStore = $this->createMock( \MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore::class );
		$propertyStore->method( 'readProperty' )->willReturn( $propModel );

		$generator = new TemplateGenerator( null, null, $propertyStore );

		$category = new CategoryModel( 'Person', [
			'properties' => [
				'required' => [ 'Has property' ],
				'optional' => [],
			],
		] );

		$result = $generator->generateSemanticTemplate( $category );

		// Should contain conditional namespace prefix pattern (already has #if)
		$this->assertStringContainsString( '{{#if:{{{property|}}}|Property:{{{property|}}}|}}', $result );
	}

	public function testGenerateSemanticTemplateInlineAnnotationWrappedInIf(): void {
		// Mock WikiPropertyStore to return a multi-value Page property with namespace
		$propModel = $this->createMock( \MediaWiki\Extension\SemanticSchemas\Schema\PropertyModel::class );
		$propModel->method( 'isPageType' )->willReturn( true );
		$propModel->method( 'getAllowedNamespace' )->willReturn( 'Subobject' );
		$propModel->method( 'allowsMultipleValues' )->willReturn( true );

		$propertyStore = $this->createMock( \MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore::class );
		$propertyStore->method( 'readProperty' )->willReturn( $propModel );

		$generator = new TemplateGenerator( null, null, $propertyStore );

		$category = new CategoryModel( 'Person', [
			'properties' => [
				'required' => [ 'Has subobjects' ],
				'optional' => [],
			],
		] );

		$result = $generator->generateSemanticTemplate( $category );

		// Should contain #if-wrapped #arraymap
		$this->assertStringContainsString( '{{#if:{{{subobjects|}}}|{{#arraymap:', $result );
		// Should contain the property annotation with namespace
		$this->assertStringContainsString( '[[Has subobjects::Subobject:', $result );
	}
}
