<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit\Schema;

use MediaWiki\Extension\SemanticSchemas\Schema\SchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Schema\SchemaValidator
 */
class SchemaValidatorTest extends TestCase {

	private SchemaValidator $validator;

	protected function setUp(): void {
		parent::setUp();
		$this->validator = new SchemaValidator();
	}

	/* =========================================================================
	 * BASIC STRUCTURE VALIDATION
	 * ========================================================================= */

	public function testValidSchemaPassesValidation(): void {
		$schema = $this->getValidSchema();
		$errors = $this->validator->validateSchema( $schema );
		$this->assertEmpty( $errors, 'Valid schema should have no errors' );
	}

	public function testMissingSchemaVersionReturnsError(): void {
		$schema = $this->getValidSchema();
		unset( $schema['schemaVersion'] );

		$errors = $this->validator->validateSchema( $schema );
		$this->assertNotEmpty( $errors, 'Missing schemaVersion should produce error' );
		$this->assertStringContainsString( 'schemaVersion', $errors[0] );
	}

	public function testMissingCategoriesReturnsError(): void {
		$schema = [
			'schemaVersion' => '1.0',
			'properties' => [],
		];

		$errors = $this->validator->validateSchema( $schema );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'categories', $errors[0] );
	}

	public function testMissingPropertiesReturnsError(): void {
		$schema = [
			'schemaVersion' => '1.0',
			'categories' => [],
		];

		$errors = $this->validator->validateSchema( $schema );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'properties', $errors[0] );
	}

	/* =========================================================================
	 * CATEGORY VALIDATION
	 * ========================================================================= */

	public function testEmptyCategoryNameReturnsError(): void {
		$schema = $this->getValidSchema();
		$schema['categories'][''] = [ 'properties' => [ 'required' => [] ] ];

		$errors = $this->validator->validateSchema( $schema );
		$this->assertNotEmpty( $errors, 'Empty category name should produce error' );
		// The error comes from CategoryModel constructor via exception
		$foundEmptyError = false;
		foreach ( $errors as $error ) {
			if ( stripos( $error, 'empty' ) !== false || stripos( $error, 'name' ) !== false ) {
				$foundEmptyError = true;
				break;
			}
		}
		$this->assertTrue( $foundEmptyError, 'Should flag empty category name' );
	}

	public function testInvalidParentTypeReturnsError(): void {
		$schema = $this->getValidSchema();
		$schema['categories']['TestCategory']['parents'] = 'NotAnArray';

		$errors = $this->validator->validateSchema( $schema );
		$this->assertNotEmpty( $errors, 'Invalid parent type should produce error' );
		// Look for parent-related error message
		$foundError = false;
		foreach ( $errors as $error ) {
			if ( stripos( $error, 'parent' ) !== false || stripos( $error, 'array' ) !== false ) {
				$foundError = true;
				break;
			}
		}
		$this->assertTrue( $foundError, 'Should flag invalid parent type' );
	}

	public function testNonExistentParentCategoryReturnsError(): void {
		$schema = $this->getValidSchema();
		$schema['categories']['TestCategory']['parents'] = [ 'NonExistentParent' ];

		$errors = $this->validator->validateSchema( $schema );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'NonExistentParent', $errors[0] );
		$this->assertStringContainsString( 'does not exist', $errors[0] );
	}

	public function testNonExistentRequiredPropertyReturnsError(): void {
		$schema = $this->getValidSchema();
		$schema['categories']['TestCategory']['properties']['required'][] = 'Undefined Property';

		$errors = $this->validator->validateSchema( $schema );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'Undefined Property', $errors[0] );
	}

	public function testNonExistentOptionalPropertyReturnsError(): void {
		$schema = $this->getValidSchema();
		$schema['categories']['TestCategory']['properties']['optional'][] = 'Undefined Property';

		$errors = $this->validator->validateSchema( $schema );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'Undefined Property', $errors[0] );
	}

	/* =========================================================================
	 * PROPERTY VALIDATION
	 * ========================================================================= */

	public function testMissingDatatypeReturnsError(): void {
		$schema = $this->getValidSchema();
		unset( $schema['properties']['Has name']['datatype'] );

		$errors = $this->validator->validateSchema( $schema );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'datatype', $errors[0] );
	}

	public function testInvalidAllowedValuesTypeReturnsError(): void {
		$schema = $this->getValidSchema();
		$schema['properties']['Has name']['allowedValues'] = 'NotAnArray';

		$errors = $this->validator->validateSchema( $schema );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'allowedValues', $errors[0] );
		$this->assertStringContainsString( 'array', $errors[0] );
	}

	public function testInvalidRangeCategoryReturnsError(): void {
		$schema = $this->getValidSchema();
		$schema['properties']['Has name']['rangeCategory'] = 'NonExistentCategory';

		$errors = $this->validator->validateSchema( $schema );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'rangeCategory', $errors[0] );
	}

	public function testValidRangeCategoryPassesValidation(): void {
		$schema = $this->getValidSchema();
		$schema['properties']['Has supervisor'] = [
			'datatype' => 'Page',
			'rangeCategory' => 'TestCategory',
		];

		$errors = $this->validator->validateSchema( $schema );
		$this->assertEmpty( $errors );
	}

	/* =========================================================================
	 * CIRCULAR DEPENDENCY DETECTION
	 * ========================================================================= */

	public function testDirectCircularDependencyReturnsError(): void {
		$schema = $this->getValidSchema();
		$schema['categories']['CategoryA'] = [
			'parents' => [ 'CategoryB' ],
			'properties' => [ 'required' => [], 'optional' => [] ],
		];
		$schema['categories']['CategoryB'] = [
			'parents' => [ 'CategoryA' ],
			'properties' => [ 'required' => [], 'optional' => [] ],
		];

		$errors = $this->validator->validateSchema( $schema );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'Circular', $errors[0] );
	}

	public function testIndirectCircularDependencyReturnsError(): void {
		$schema = $this->getValidSchema();
		$schema['categories']['CatA'] = [
			'parents' => [ 'CatB' ],
			'properties' => [ 'required' => [], 'optional' => [] ],
		];
		$schema['categories']['CatB'] = [
			'parents' => [ 'CatC' ],
			'properties' => [ 'required' => [], 'optional' => [] ],
		];
		$schema['categories']['CatC'] = [
			'parents' => [ 'CatA' ],
			'properties' => [ 'required' => [], 'optional' => [] ],
		];

		$errors = $this->validator->validateSchema( $schema );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'Circular', $errors[0] );
	}

	/* =========================================================================
	 * DISPLAY CONFIG VALIDATION
	 * ========================================================================= */

	public function testInvalidDisplayHeaderTypeReturnsError(): void {
		$schema = $this->getValidSchema();
		$schema['categories']['TestCategory']['display'] = [
			'header' => 'NotAnArray',
		];

		$errors = $this->validator->validateSchema( $schema );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'header', $errors[0] );
	}

	public function testUndefinedPropertyInDisplayHeaderReturnsError(): void {
		$schema = $this->getValidSchema();
		$schema['categories']['TestCategory']['display'] = [
			'header' => [ 'Undefined Property' ],
		];

		$errors = $this->validator->validateSchema( $schema );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'Undefined Property', $errors[0] );
	}

	public function testDisplaySectionMissingNameReturnsError(): void {
		$schema = $this->getValidSchema();
		$schema['categories']['TestCategory']['display'] = [
			'sections' => [
				[ 'properties' => [ 'Has name' ] ],
			],
		];

		$errors = $this->validator->validateSchema( $schema );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'name', $errors[0] );
	}

	/* =========================================================================
	 * FORM CONFIG VALIDATION
	 * ========================================================================= */

	public function testFormSectionMissingNameReturnsError(): void {
		$schema = $this->getValidSchema();
		$schema['categories']['TestCategory']['forms'] = [
			'sections' => [
				[ 'properties' => [ 'Has name' ] ],
			],
		];

		$errors = $this->validator->validateSchema( $schema );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'name', $errors[0] );
	}

	public function testUndefinedPropertyInFormSectionReturnsError(): void {
		$schema = $this->getValidSchema();
		$schema['categories']['TestCategory']['forms'] = [
			'sections' => [
				[ 'name' => 'Basic', 'properties' => [ 'NonExistent Property' ] ],
			],
		];

		$errors = $this->validator->validateSchema( $schema );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'NonExistent Property', $errors[0] );
	}

	/* =========================================================================
	 * SUBOBJECT VALIDATION
	 * ========================================================================= */

	public function testUndefinedSubobjectInCategoryReturnsError(): void {
		$schema = $this->getValidSchema();
		$schema['categories']['TestCategory']['subobjects'] = [
			'required' => [ 'UndefinedSubobject' ],
			'optional' => [],
		];

		$errors = $this->validator->validateSchema( $schema );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'UndefinedSubobject', $errors[0] );
	}

	public function testSubobjectWithDuplicatePropertyListsReturnsWarningNotError(): void {
		$schema = $this->getValidSchema();
		$schema['subobjects'] = [
			'TestSubobject' => [
				'properties' => [
					'required' => [ 'Has name' ],
					'optional' => [ 'Has name' ],
				],
			],
		];

		$result = $this->validator->validateSchemaWithSeverity( $schema );
		$this->assertEmpty( $result['errors'], 'Overlap should not produce errors' );

		$hasPromotionWarning = false;
		foreach ( $result['warnings'] as $warning ) {
			if ( stripos( $warning, 'promoted to required' ) !== false ) {
				$hasPromotionWarning = true;
				break;
			}
		}
		$this->assertTrue( $hasPromotionWarning, 'Should warn about promotion to required' );
	}

	/* =========================================================================
	 * REQUIRED/OPTIONAL OVERLAP WARNINGS
	 * ========================================================================= */

	public function testCategoryDuplicateRequiredOptionalPropertyReturnsWarningNotError(): void {
		$schema = $this->getValidSchema();
		$schema['categories']['TestCategory']['properties'] = [
			'required' => [ 'Has name' ],
			'optional' => [ 'Has name', 'Has description' ],
		];

		$result = $this->validator->validateSchemaWithSeverity( $schema );
		$this->assertEmpty( $result['errors'], 'Overlap should not produce errors' );

		$hasPromotionWarning = false;
		foreach ( $result['warnings'] as $warning ) {
			if ( stripos( $warning, 'promoted to required' ) !== false ) {
				$hasPromotionWarning = true;
				break;
			}
		}
		$this->assertTrue( $hasPromotionWarning, 'Should warn about promotion to required' );
	}

	public function testCategoryDuplicateRequiredOptionalSubobjectReturnsWarningNotError(): void {
		$schema = $this->getValidSchema();
		$schema['subobjects'] = [
			'Author' => [
				'properties' => [
					'required' => [ 'Has name' ],
					'optional' => [],
				],
			],
			'Funding' => [
				'properties' => [
					'required' => [ 'Has name' ],
					'optional' => [],
				],
			],
		];
		$schema['categories']['TestCategory']['subobjects'] = [
			'required' => [ 'Author' ],
			'optional' => [ 'Author', 'Funding' ],
		];

		$result = $this->validator->validateSchemaWithSeverity( $schema );
		$this->assertEmpty( $result['errors'], 'Subobject overlap should not produce errors' );

		$hasPromotionWarning = false;
		foreach ( $result['warnings'] as $warning ) {
			if ( stripos( $warning, 'promoted to required' ) !== false ) {
				$hasPromotionWarning = true;
				break;
			}
		}
		$this->assertTrue( $hasPromotionWarning, 'Should warn about subobject promotion' );
	}

	public function testNoOverlapProducesNoPromotionWarning(): void {
		$schema = $this->getValidSchema();

		$result = $this->validator->validateSchemaWithSeverity( $schema );
		$this->assertEmpty( $result['errors'], 'Valid schema should have no errors' );
		foreach ( $result['warnings'] as $warning ) {
			$this->assertStringNotContainsString(
				'promoted to required',
				$warning,
				'No promotion warning should appear when there are no overlaps'
			);
		}
	}

	public function testValidateSchemaErrorsOnlyMethodExcludesWarnings(): void {
		$schema = $this->getValidSchema();
		$schema['categories']['TestCategory']['properties'] = [
			'required' => [ 'Has name' ],
			'optional' => [ 'Has name', 'Has description' ],
		];

		// validateSchemaWithSeverity should have warnings but validateSchema should not
		$result = $this->validator->validateSchemaWithSeverity( $schema );
		$hasPromotionWarning = false;
		foreach ( $result['warnings'] as $warning ) {
			if ( stripos( $warning, 'promoted to required' ) !== false ) {
				$hasPromotionWarning = true;
				break;
			}
		}
		$this->assertTrue( $hasPromotionWarning, 'Severity method should have promotion warning' );

		$errors = $this->validator->validateSchema( $schema );
		foreach ( $errors as $error ) {
			$this->assertStringNotContainsString(
				'promoted to required',
				$error,
				'validateSchema() should not return promotion warnings as errors'
			);
		}
	}

	/* =========================================================================
	 * WARNINGS (SEVERITY LEVELS)
	 * ========================================================================= */

	public function testValidateSchemaWithSeverityReturnsWarnings(): void {
		$schema = $this->getValidSchema();
		// Add an unused property
		$schema['properties']['Unused Property'] = [
			'datatype' => 'Text',
		];

		$result = $this->validator->validateSchemaWithSeverity( $schema );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'warnings', $result );
		$this->assertEmpty( $result['errors'] );
		$this->assertNotEmpty( $result['warnings'] );
	}

	public function testPropertyNamingConventionWarning(): void {
		$schema = $this->getValidSchema();
		$schema['properties']['BadPropertyName'] = [ 'datatype' => 'Text' ];
		$schema['categories']['TestCategory']['properties']['optional'][] = 'BadPropertyName';

		$result = $this->validator->validateSchemaWithSeverity( $schema );
		$hasNamingWarning = false;
		foreach ( $result['warnings'] as $warning ) {
			if ( stripos( $warning, 'Has ' ) !== false ) {
				$hasNamingWarning = true;
				break;
			}
		}
		$this->assertTrue( $hasNamingWarning, 'Should warn about property naming convention' );
	}

	/* =========================================================================
	 * CUSTOM VALIDATORS
	 * ========================================================================= */

	public function testCustomValidatorIsInvoked(): void {
		$customCalled = false;
		$this->validator->registerCustomValidator( static function ( $schema ) use ( &$customCalled ) {
			$customCalled = true;
			return [ 'errors' => [ 'Custom error' ], 'warnings' => [] ];
		} );

		$result = $this->validator->validateSchemaWithSeverity( $this->getValidSchema() );
		$this->assertTrue( $customCalled, 'Custom validator should be called' );
		$this->assertContains( 'Custom error', $result['errors'] );
	}

	/* =========================================================================
	 * HELPER METHODS
	 * ========================================================================= */

	private function getValidSchema(): array {
		return [
			'schemaVersion' => '1.0',
			'categories' => [
				'TestCategory' => [
					'label' => 'Test Category',
					'description' => 'A test category',
					'parents' => [],
					'properties' => [
						'required' => [ 'Has name' ],
						'optional' => [ 'Has description' ],
					],
					'display' => [
						'header' => [ 'Has name' ],
						'sections' => [
							[ 'name' => 'Basic Info', 'properties' => [ 'Has name', 'Has description' ] ],
						],
					],
					'forms' => [
						'sections' => [
							[ 'name' => 'Basic Info', 'properties' => [ 'Has name', 'Has description' ] ],
						],
					],
				],
			],
			'properties' => [
				'Has name' => [
					'datatype' => 'Text',
				],
				'Has description' => [
					'datatype' => 'Text',
				],
			],
		];
	}
}
