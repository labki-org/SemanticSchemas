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
		$errors = $this->validator->validateSchemaWithSeverity( $schema )['errors'];
		$this->assertEmpty( $errors, 'Valid schema should have no errors' );
	}

	public function testMissingSchemaVersionReturnsError(): void {
		$schema = $this->getValidSchema();
		unset( $schema['schemaVersion'] );

		$errors = $this->validator->validateSchemaWithSeverity( $schema )['errors'];
		$this->assertNotEmpty( $errors, 'Missing schemaVersion should produce error' );
		$this->assertStringContainsString( 'schemaVersion', $errors[0] );
	}

	public function testMissingCategoriesReturnsError(): void {
		$schema = [
			'schemaVersion' => '1.0',
			'properties' => [],
		];

		$errors = $this->validator->validateSchemaWithSeverity( $schema )['errors'];
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'categories', $errors[0] );
	}

	public function testMissingPropertiesReturnsError(): void {
		$schema = [
			'schemaVersion' => '1.0',
			'categories' => [],
		];

		$errors = $this->validator->validateSchemaWithSeverity( $schema )['errors'];
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'properties', $errors[0] );
	}

	/* =========================================================================
	 * CATEGORY VALIDATION
	 * ========================================================================= */

	public function testEmptyCategoryNameReturnsError(): void {
		$schema = $this->getValidSchema();
		$schema['categories'][''] = [ 'properties' => [ 'required' => [] ] ];

		$errors = $this->validator->validateSchemaWithSeverity( $schema )['errors'];
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

		$errors = $this->validator->validateSchemaWithSeverity( $schema )['errors'];
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

		$errors = $this->validator->validateSchemaWithSeverity( $schema )['errors'];
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'NonExistentParent', $errors[0] );
		$this->assertStringContainsString( 'does not exist', $errors[0] );
	}

	public function testNonExistentRequiredPropertyReturnsError(): void {
		$schema = $this->getValidSchema();
		$schema['categories']['TestCategory']['properties']['required'][] = 'Undefined Property';

		$errors = $this->validator->validateSchemaWithSeverity( $schema )['errors'];
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'Undefined Property', $errors[0] );
	}

	public function testNonExistentOptionalPropertyReturnsError(): void {
		$schema = $this->getValidSchema();
		$schema['categories']['TestCategory']['properties']['optional'][] = 'Undefined Property';

		$errors = $this->validator->validateSchemaWithSeverity( $schema )['errors'];
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'Undefined Property', $errors[0] );
	}

	/* =========================================================================
	 * PROPERTY VALIDATION
	 * ========================================================================= */

	public function testMissingDatatypeReturnsError(): void {
		$schema = $this->getValidSchema();
		unset( $schema['properties']['Has name']['datatype'] );

		$errors = $this->validator->validateSchemaWithSeverity( $schema )['errors'];
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'datatype', $errors[0] );
	}

	public function testInvalidAllowedValuesTypeReturnsError(): void {
		$schema = $this->getValidSchema();
		$schema['properties']['Has name']['allowedValues'] = 'NotAnArray';

		$errors = $this->validator->validateSchemaWithSeverity( $schema )['errors'];
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'allowedValues', $errors[0] );
		$this->assertStringContainsString( 'array', $errors[0] );
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

		$errors = $this->validator->validateSchemaWithSeverity( $schema )['errors'];
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

		$errors = $this->validator->validateSchemaWithSeverity( $schema )['errors'];
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'Circular', $errors[0] );
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

		$errors = $this->validator->validateSchemaWithSeverity( $schema )['errors'];
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

		$errors = $this->validator->validateSchemaWithSeverity( $schema )['errors'];
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'NonExistent Property', $errors[0] );
	}

	/* =========================================================================
	 * SUBOBJECT VALIDATION
	 * ========================================================================= */

	public function testUndefinedSubobjectCategoryReturnsError(): void {
		$schema = $this->getValidSchema();
		$schema['categories']['TestCategory']['subobjects'] = [
			'required' => [ 'NonexistentCategory' ],
			'optional' => [],
		];

		$errors = $this->validator->validateSchemaWithSeverity( $schema )['errors'];
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'NonexistentCategory', $errors[0] );
	}

	public function testDefinedSubobjectCategoryPassesValidation(): void {
		$schema = $this->getValidSchema();
		$schema['categories']['Address'] = [
			'properties' => [
				'required' => [ 'Has name' ],
				'optional' => [],
			],
		];
		$schema['categories']['TestCategory']['subobjects'] = [
			'required' => [ 'Address' ],
			'optional' => [],
		];

		$errors = $this->validator->validateSchemaWithSeverity( $schema )['errors'];
		$this->assertEmpty( $errors );
	}

	/* =========================================================================
	 * WARNINGS (SEVERITY LEVELS)
	 * ========================================================================= */

	public function testValidateSchemaWithSeverityReturnsWarnings(): void {
		$schema = $this->getValidSchema();
		// Add a category without display/forms config to trigger warnings
		$schema['categories']['BareBones'] = [
			'properties' => [
				'required' => [ 'Has name' ],
				'optional' => [],
			],
		];

		$result = $this->validator->validateSchemaWithSeverity( $schema );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'warnings', $result );
		$this->assertEmpty( $result['errors'] );
		$this->assertNotEmpty( $result['warnings'] );
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
	 * META-CATEGORY WARNING SUPPRESSION
	 * ========================================================================= */

	public function testMetaCategoryDoesNotTriggerMissingDisplayFormWarnings(): void {
		$schema = [
			'schemaVersion' => SchemaValidator::SCHEMA_VERSION,
			'categories' => [
				'Category' => [
					'targetNamespace' => 'Category',
					'properties' => [
						'required' => [ 'Has name' ],
						'optional' => [],
					],
				],
			],
			'properties' => [
				'Has name' => [ 'datatype' => 'Text' ],
			],
		];

		$result = $this->validator->validateSchemaWithSeverity( $schema );
		$metaCatWarnings = array_filter( $result['warnings'], static function ( $w ) {
			return str_contains( $w, "Category 'Category'" );
		} );
		$this->assertEmpty(
			$metaCatWarnings,
			'Meta-category should not trigger missing display/form warnings'
		);
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
