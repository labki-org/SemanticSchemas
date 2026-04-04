<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit\Generator;

use InvalidArgumentException;
use MediaWiki\Extension\SemanticSchemas\Generator\TemplateGenerator;
use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\EffectiveCategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\InheritanceResolver;
use MediaWiki\Extension\SemanticSchemas\Schema\PropertyModel;
use MediaWiki\Extension\SemanticSchemas\Schema\SubobjectModel;
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
		$this->assertStringContainsString( '{{{has_name|}}}', $result );
		$this->assertStringContainsString( '{{{has_email|}}}', $result );
	}

	public function testSemanticTemplateDoesNotContainCategoryStamp(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generator->generateSemanticTemplate( $category );

		$this->assertStringNotContainsString( '[[Category:', $result );
	}

	public function testDispatcherTemplateDoesNotContainCategoryStamp(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generateDispatcher( $category );

		$this->assertStringNotContainsString( '[[Category:', $result );
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

	/**
	 * Helper: generate dispatcher for a single category (no inheritance).
	 */
	private function generateDispatcher( CategoryModel $category ): string {
		$effective = new EffectiveCategoryModel( $category->getName(), $category->toArray() );
		return $this->generator->generateDispatcherTemplate( $effective );
	}

	public function testGenerateDispatcherTemplateReturnsString(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generateDispatcher( $category );

		$this->assertIsString( $result );
	}

	public function testGenerateDispatcherTemplateContainsDefaultForm(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generateDispatcher( $category );

		$this->assertStringContainsString( '{{#default_form:Person}}', $result );
	}

	public function testGenerateDispatcherTemplateCallsSemanticTemplate(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generateDispatcher( $category );

		$this->assertStringContainsString( '{{Person/semantic', $result );
	}

	public function testGenerateDispatcherTemplateCallsDisplayTemplate(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generateDispatcher( $category );

		$this->assertStringContainsString( '{{Person/display', $result );
	}

	public function testGenerateDispatcherTemplatePassesParameters(): void {
		$category = new CategoryModel( 'Person', [
			'properties' => [
				'required' => [ 'Has name' ],
				'optional' => [],
			],
		] );

		$result = $this->generateDispatcher( $category );
		// Should pass parameter to sub-templates
		$this->assertStringContainsString( '| has_name = {{{has_name|}}}', $result );
	}

	public function testGenerateDispatcherTemplateWithEmptyNameThrowsException(): void {
		$this->expectException( InvalidArgumentException::class );

		$category = $this->createMock( EffectiveCategoryModel::class );
		$category->method( 'getName' )->willReturn( '' );

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

		// "Has full name" preserves prefix → "has_full_name" parameter
		$this->assertStringContainsString( '{{{has_full_name|}}}', $result );
	}

	public function testMultiplePropertiesConvertedCorrectly(): void {
		$category = new CategoryModel( 'Person', [
			'properties' => [
				'required' => [ 'Has first name', 'Has last name', 'Has email address' ],
				'optional' => [],
			],
		] );

		$result = $this->generator->generateSemanticTemplate( $category );

		$this->assertStringContainsString( '{{{has_first_name|}}}', $result );
		$this->assertStringContainsString( '{{{has_last_name|}}}', $result );
		$this->assertStringContainsString( '{{{has_email_address|}}}', $result );
	}

	/* =========================================================================
	 * GENERATE ALL TEMPLATES
	 * ========================================================================= */

	public function testGenerateAllTemplatesReturnsArray(): void {
		$category = new CategoryModel( 'Person' );
		$resolver = new InheritanceResolver( [ 'Person' => $category ] );
		$result = $this->generator->generateAllTemplates( $category, $resolver );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'errors', $result );
	}

	public function testGenerateAllTemplatesSuccessWhenNoErrors(): void {
		$category = new CategoryModel( 'Person' );
		$resolver = new InheritanceResolver( [ 'Person' => $category ] );
		$result = $this->generator->generateAllTemplates( $category, $resolver );

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
		$result = $this->generateDispatcher( $category );

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
	}

	public function testCategoryNameWithSpacesInDispatcher(): void {
		$category = new CategoryModel( 'PhD Student' );
		$result = $this->generateDispatcher( $category );

		$this->assertStringContainsString( '{{#default_form:PhD Student}}', $result );
		$this->assertStringContainsString( '{{PhD Student/semantic', $result );
	}

	/* =========================================================================
	 * MULTI-VALUE PROPERTY HANDLING
	 * ========================================================================= */

	/**
	 * Create a TemplateGenerator whose WikiPropertyStore returns specific PropertyModel instances.
	 *
	 * @param array<string, PropertyModel> $propertyMap property name => PropertyModel
	 * @return TemplateGenerator
	 */
	private function generatorWithProperties( array $propertyMap ): TemplateGenerator {
		$propStore = $this->createMock( WikiPropertyStore::class );
		$propStore->method( 'readProperty' )
			->willReturnCallback( static fn ( string $name ) => $propertyMap[$name] ?? null );

		return new TemplateGenerator(
			$this->createMock( PageCreator::class ),
			$this->createMock( WikiSubobjectStore::class ),
			$propStore
		);
	}

	public function testMultiValueTextPropertyUsesSepSyntax(): void {
		$gen = $this->generatorWithProperties( [
			'Has tags' => new PropertyModel( 'Has tags', [
				'datatype' => 'Text',
				'allowsMultipleValues' => true,
			] ),
		] );

		$category = new CategoryModel( 'Article', [
			'properties' => [
				'required' => [ 'Has tags' ],
				'optional' => [],
			],
		] );

		$result = $gen->generateSemanticTemplate( $category );
		$this->assertStringContainsString( 'Has tags = {{{has_tags|}}} |+sep=,', $result );
	}

	public function testSingleValueTextPropertyDoesNotUseSep(): void {
		$gen = $this->generatorWithProperties( [
			'Has title' => new PropertyModel( 'Has title', [
				'datatype' => 'Text',
				'allowsMultipleValues' => false,
			] ),
		] );

		$category = new CategoryModel( 'Article', [
			'properties' => [
				'required' => [ 'Has title' ],
				'optional' => [],
			],
		] );

		$result = $gen->generateSemanticTemplate( $category );
		$this->assertStringContainsString( 'Has title = {{{has_title|}}}', $result );
		$this->assertStringNotContainsString( '+sep=', $result );
	}

	public function testMultiValuePagePropertyWithoutNamespaceUsesSep(): void {
		$gen = $this->generatorWithProperties( [
			'Has related' => new PropertyModel( 'Has related', [
				'datatype' => 'Page',
				'allowsMultipleValues' => true,
			] ),
		] );

		$category = new CategoryModel( 'Article', [
			'properties' => [
				'required' => [ 'Has related' ],
				'optional' => [],
			],
		] );

		$result = $gen->generateSemanticTemplate( $category );
		$this->assertStringContainsString( 'Has related = {{{has_related|}}} |+sep=,', $result );
	}

	public function testMultiValuePagePropertyWithNamespaceUsesArraymap(): void {
		$gen = $this->generatorWithProperties( [
			'Has author' => new PropertyModel( 'Has author', [
				'datatype' => 'Page',
				'allowsMultipleValues' => true,
				'allowedNamespace' => 'User',
			] ),
		] );

		$category = new CategoryModel( 'Article', [
			'properties' => [
				'required' => [ 'Has author' ],
				'optional' => [],
			],
		] );

		$result = $gen->generateSemanticTemplate( $category );
		$this->assertStringContainsString( '#arraymap', $result );
		$this->assertStringContainsString( '|+sep=,', $result );
	}

	public function testSingleValuePagePropertyWithoutNamespaceDoesNotUseSep(): void {
		$gen = $this->generatorWithProperties( [
			'Has homepage' => new PropertyModel( 'Has homepage', [
				'datatype' => 'Page',
				'allowsMultipleValues' => false,
			] ),
		] );

		$category = new CategoryModel( 'Article', [
			'properties' => [
				'required' => [ 'Has homepage' ],
				'optional' => [],
			],
		] );

		$result = $gen->generateSemanticTemplate( $category );
		$this->assertStringContainsString( 'Has homepage = {{{has_homepage|}}}', $result );
		$this->assertStringNotContainsString( '+sep=', $result );
	}

	public function testSubobjectMultiValuePropertyUsesSep(): void {
		$propertyMap = [
			'Has tags' => new PropertyModel( 'Has tags', [
				'datatype' => 'Text',
				'allowsMultipleValues' => true,
			] ),
		];

		$propStore = $this->createMock( WikiPropertyStore::class );
		$propStore->method( 'readProperty' )
			->willReturnCallback( static fn ( string $name ) => $propertyMap[$name] ?? null );

		$subStore = $this->createMock( WikiSubobjectStore::class );
		$subStore->method( 'readSubobject' )
			->with( 'Metadata' )
			->willReturn( new SubobjectModel( 'Metadata', [
				'properties' => [
					'required' => [ 'Has tags' ],
					'optional' => [],
				],
			] ) );

		// Capture content written to the subobject semantic template
		$writtenContent = [];
		$pageCreator = $this->createMock( PageCreator::class );
		$pageCreator->method( 'makeTitle' )
			->willReturn( $this->createMock( \MediaWiki\Title\Title::class ) );
		$pageCreator->method( 'createOrUpdatePage' )
			->willReturnCallback( static function ( $title, $content ) use ( &$writtenContent ) {
				$writtenContent[] = $content;
				return true;
			} );

		$gen = new TemplateGenerator( $pageCreator, $subStore, $propStore );

		$category = new CategoryModel( 'Article', [
			'properties' => [
				'required' => [],
				'optional' => [],
			],
			'subobjects' => [
				'required' => [ 'Metadata' ],
				'optional' => [],
			],
		] );

		$resolver = new InheritanceResolver( [ 'Article' => $category ] );
		$gen->generateAllTemplates( $category, $resolver );

		// Find the subobject semantic template (contains #subobject:)
		$subobjectContent = null;
		foreach ( $writtenContent as $content ) {
			if ( strpos( $content, '{{#subobject:' ) !== false ) {
				$subobjectContent = $content;
				break;
			}
		}

		$this->assertNotNull( $subobjectContent, 'Subobject semantic template should be generated' );
		$this->assertStringContainsString( 'Has tags = {{{has_tags|}}} |+sep=,', $subobjectContent );
	}

	/* =========================================================================
	 * MODULAR TEMPLATES — INHERITANCE CHAIN
	 * ========================================================================= */

	public function testDispatcherCallsOnlyLeafSemanticTemplate(): void {
		$person = new CategoryModel( 'Person', [
			'properties' => [
				'required' => [ 'Has name' ],
				'optional' => [ 'Has email' ],
			],
		] );
		$student = new CategoryModel( 'Student', [
			'parents' => [ 'Person' ],
			'properties' => [
				'required' => [ 'Has student ID' ],
				'optional' => [],
			],
		] );

		$categoryMap = [ 'Person' => $person, 'Student' => $student ];
		$resolver = new InheritanceResolver( $categoryMap );
		$effective = $resolver->getEffectiveCategory( 'Student' );

		$result = $this->generator->generateDispatcherTemplate( $effective );

		$this->assertStringContainsString( '{{Student/semantic', $result );
		$this->assertStringNotContainsString( '{{Person/semantic', $result );
		$this->assertStringContainsString( '{{Student/display', $result );
		// All effective params forwarded
		$this->assertStringContainsString( 'name', $result );
		$this->assertStringContainsString( 'student_id', $result );
		$this->assertStringContainsString( 'email', $result );
		// No category stamp in dispatcher
		$this->assertStringNotContainsString( '[[Category:', $result );
	}

	public function testSemanticTemplateForEachAncestorHasOwnPropertiesOnly(): void {
		$person = new CategoryModel( 'Person', [
			'properties' => [ 'required' => [ 'Has name' ], 'optional' => [] ],
		] );
		$student = new CategoryModel( 'Student', [
			'parents' => [ 'Person' ],
			'properties' => [ 'required' => [ 'Has student ID' ], 'optional' => [] ],
		] );

		$resolver = new InheritanceResolver( [ 'Person' => $person, 'Student' => $student ] );

		// Person/semantic: own props only, no parent calls, no category stamp
		$personSemantic = $this->generator->generateSemanticTemplate( $person );
		$this->assertStringContainsString( 'Has name', $personSemantic );
		$this->assertStringNotContainsString( 'Has student ID', $personSemantic );
		$this->assertStringNotContainsString( '[[Category:', $personSemantic );
		$this->assertStringNotContainsString( '/semantic', $personSemantic );

		// Student/semantic: embeds Person/semantic, then #set own props
		$parentEffectives = $resolver->getParentEffectiveModels( 'Student' );
		$studentSemantic = $this->generator->generateSemanticTemplate( $student, $parentEffectives );
		$this->assertStringContainsString( '{{Person/semantic', $studentSemantic );
		$this->assertStringContainsString( 'Has student ID', $studentSemantic );
		$this->assertStringNotContainsString( '[[Category:', $studentSemantic );
		// Person's property is forwarded to the parent semantic call, not set directly
		$parentCall = strstr( $studentSemantic, '{{#set:', true );
		$this->assertStringContainsString( 'name', $parentCall );
		// Student's own #set should not repeat Person's properties
		$this->assertStringNotContainsString( 'Has name = ', $studentSemantic );
	}

	public function testSemanticTemplateForwardsOnlyParentEffectiveParams(): void {
		$person = new CategoryModel( 'Person', [
			'properties' => [ 'required' => [ 'Has name' ], 'optional' => [] ],
		] );
		$student = new CategoryModel( 'Student', [
			'parents' => [ 'Person' ],
			'properties' => [ 'required' => [ 'Has student ID' ], 'optional' => [] ],
		] );

		$resolver = new InheritanceResolver( [ 'Person' => $person, 'Student' => $student ] );
		$parentEffectives = $resolver->getParentEffectiveModels( 'Student' );

		$result = $this->generator->generateSemanticTemplate( $student, $parentEffectives );

		// Everything before {{#set: is the parent template calls section
		$parentSection = strstr( $result, '{{#set:', true );
		$this->assertStringContainsString( '{{Person/semantic', $parentSection );
		$this->assertStringContainsString( 'name', $parentSection );
		// Student-only param should NOT be forwarded to Person
		$this->assertStringNotContainsString( 'student_id', $parentSection );
	}
}
