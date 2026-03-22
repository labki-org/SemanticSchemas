<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit\Generator;

use InvalidArgumentException;
use MediaWiki\Extension\SemanticSchemas\Generator\TemplateGenerator;
use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
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
		$this->assertStringContainsString( '{{{name|}}}', $result );
		$this->assertStringContainsString( '{{{email|}}}', $result );
	}

	public function testSemanticTemplateDoesNotContainCategoryStamp(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generator->generateSemanticTemplate( $category );

		// Category stamp moved to dispatcher — semantic template is pure property storage
		$this->assertStringNotContainsString( '[[Category:', $result );
	}

	public function testDispatcherTemplateContainsCategoryStamp(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generateDispatcher( $category );

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

	/**
	 * Helper: generate dispatcher for a single category (no inheritance).
	 */
	private function generateDispatcher( CategoryModel $category ): string {
		return $this->generator->generateDispatcherTemplate( $category, $category );
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
		$this->assertStringContainsString( '| name = {{{name|}}}', $result );
	}

	public function testGenerateDispatcherTemplateWithEmptyNameThrowsException(): void {
		$this->expectException( InvalidArgumentException::class );

		$category = $this->createMock( CategoryModel::class );
		$category->method( 'getName' )->willReturn( '' );
		$category->method( 'getAllProperties' )->willReturn( [] );
		$category->method( 'getRequiredSubobjects' )->willReturn( [] );
		$category->method( 'getOptionalSubobjects' )->willReturn( [] );

		$this->generator->generateDispatcherTemplate( $category, $category );
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
	}

	/* =========================================================================
	 * GENERATE ALL TEMPLATES
	 * ========================================================================= */

	public function testGenerateAllTemplatesReturnsArray(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generator->generateAllTemplates( $category, [ $category ], $category );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'errors', $result );
	}

	public function testGenerateAllTemplatesSuccessWhenNoErrors(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generator->generateAllTemplates( $category, [ $category ], $category );

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

		$this->assertStringContainsString( '[[Category:PhD Student]]', $result );
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
		$this->assertStringContainsString( 'Has tags = {{{tags|}}} |+sep=,', $result );
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
		$this->assertStringContainsString( 'Has title = {{{title|}}}', $result );
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
		$this->assertStringContainsString( 'Has related = {{{related|}}} |+sep=,', $result );
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
		$this->assertStringNotContainsString( '+sep=', $result );
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
		$this->assertStringContainsString( 'Has homepage = {{{homepage|}}}', $result );
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

		$gen->generateAllTemplates( $category, [ $category ], $category );

		// Find the subobject semantic template (contains #subobject:)
		$subobjectContent = null;
		foreach ( $writtenContent as $content ) {
			if ( strpos( $content, '{{#subobject:' ) !== false ) {
				$subobjectContent = $content;
				break;
			}
		}

		$this->assertNotNull( $subobjectContent, 'Subobject semantic template should be generated' );
		$this->assertStringContainsString( 'Has tags = {{{tags|}}} |+sep=,', $subobjectContent );
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

		$effective = $student->mergeWithParent( $person );

		$result = $this->generator->generateDispatcherTemplate( $student, $effective );

		// Dispatcher should only call leaf semantic template (self-chaining handles ancestors)
		$this->assertStringContainsString( '{{Student/semantic', $result );
		$this->assertStringNotContainsString( '{{Person/semantic', $result );
		// And the display
		$this->assertStringContainsString( '{{Student/display', $result );
		// All effective params forwarded to leaf semantic
		$this->assertStringContainsString( 'name', $result );
		$this->assertStringContainsString( 'student_id', $result );
		$this->assertStringContainsString( 'email', $result );
	}

	public function testDispatcherStampsOnlyLeafCategory(): void {
		$person = new CategoryModel( 'Person', [
			'properties' => [ 'required' => [ 'Has name' ], 'optional' => [] ],
		] );
		$student = new CategoryModel( 'Student', [
			'parents' => [ 'Person' ],
			'properties' => [ 'required' => [ 'Has student ID' ], 'optional' => [] ],
		] );

		$effective = $student->mergeWithParent( $person );

		$result = $this->generator->generateDispatcherTemplate( $student, $effective );

		$this->assertStringContainsString( '[[Category:Student]]', $result );
		$this->assertStringNotContainsString( '[[Category:Person]]', $result );
	}

	public function testSemanticTemplateForEachAncestorHasOwnPropertiesOnly(): void {
		$person = new CategoryModel( 'Person', [
			'properties' => [ 'required' => [ 'Has name' ], 'optional' => [] ],
		] );
		$student = new CategoryModel( 'Student', [
			'parents' => [ 'Person' ],
			'properties' => [ 'required' => [ 'Has student ID' ], 'optional' => [] ],
		] );

		$studentEffectiveProps = [ 'Has name', 'Has student ID' ];

		// Build chain effectives map
		$personEffective = $person;
		$studentEffective = $student->mergeWithParent( $person );
		$chainEffectives = [
			'Person' => $personEffective,
			'Student' => $studentEffective,
		];

		// Person/semantic: own props only, no parent calls, no category stamp
		$personSemantic = $this->generator->generateSemanticTemplate( $person, $chainEffectives );
		$this->assertStringContainsString( 'Has name', $personSemantic );
		$this->assertStringNotContainsString( 'Has student ID', $personSemantic );
		$this->assertStringNotContainsString( '[[Category:', $personSemantic );
		$this->assertStringNotContainsString( '/semantic', $personSemantic );

		// Student/semantic: embeds Person/semantic, then #set own props
		$studentSemantic = $this->generator->generateSemanticTemplate( $student, $chainEffectives );
		$this->assertStringContainsString( '{{Person/semantic', $studentSemantic );
		$this->assertStringContainsString( 'Has student ID', $studentSemantic );
		$this->assertStringNotContainsString( '[[Category:', $studentSemantic );
	}

	public function testSemanticTemplateForwardsOnlyParentEffectiveParams(): void {
		$person = new CategoryModel( 'Person', [
			'properties' => [ 'required' => [ 'Has name' ], 'optional' => [] ],
		] );
		$student = new CategoryModel( 'Student', [
			'parents' => [ 'Person' ],
			'properties' => [ 'required' => [ 'Has student ID' ], 'optional' => [] ],
		] );

		$chainEffectives = [
			'Person' => $person,
			'Student' => $student->mergeWithParent( $person ),
		];

		$result = $this->generator->generateSemanticTemplate( $student, $chainEffectives );

		// The Person/semantic call should forward only Person's effective params
		$personCall = $this->extractTemplateCall( $result, 'Person/semantic' );
		$this->assertStringContainsString( 'name', $personCall );
		// Student-only param should NOT be forwarded to Person
		$this->assertStringNotContainsString( 'student_id', $personCall );
	}

	/**
	 * Extract the template call block for a given template name from dispatcher output.
	 */
	private function extractTemplateCall( string $dispatcher, string $templateName ): string {
		$start = strpos( $dispatcher, '{{' . $templateName );
		if ( $start === false ) {
			return '';
		}
		$depth = 0;
		$len = strlen( $dispatcher );
		for ( $i = $start; $i < $len - 1; $i++ ) {
			if ( $dispatcher[$i] === '{' && $dispatcher[$i + 1] === '{' ) {
				$depth++;
				$i++;
			} elseif ( $dispatcher[$i] === '}' && $dispatcher[$i + 1] === '}' ) {
				$depth--;
				$i++;
				if ( $depth === 0 ) {
					return substr( $dispatcher, $start, $i - $start + 1 );
				}
			}
		}
		return substr( $dispatcher, $start );
	}
}
