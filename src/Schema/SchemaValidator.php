<?php

namespace MediaWiki\Extension\SemanticSchemas\Schema;

/**
 * SchemaValidator
 * ----------------
 * Validates category + property definitions for SemanticSchemas.
 *
 * This class performs comprehensive validation of schema structure before
 * import to catch errors early and provide helpful error messages.
 *
 * Validation Levels:
 * -----------------
 * Two severity levels are supported:
 * - **ERROR**: Schema violations that will cause import failure or runtime errors
 * - **WARNING**: Issues that may indicate problems but won't prevent import
 *
 * Validates:
 *   - required top-level fields (schemaVersion, categories, properties)
 *   - category definitions (parents, properties, display, forms)
 *   - property definitions (datatype, allowed values, rangeCategory)
 *   - missing references (properties used by categories but not defined)
 *   - multi-parent inheritance consistency
 *   - circular category dependencies (via InheritanceResolver)
 *   - display/form section structure
 *   - naming conventions and best practices (warnings)
 */
class SchemaValidator {

	/** @var array Custom validation rules registered by extensions */
	private $customValidators = [];

	/**
	 * Validate entire schema (errors only).
	 *
	 * This method returns only errors for compatibility with existing code.
	 * Use validateSchemaWithSeverity() to get both errors and warnings.
	 *
	 * @param array $schema
	 * @return array List of error messages
	 */
	public function validateSchema( array $schema ): array {
		$result = $this->validateSchemaWithSeverity( $schema );
		return $result['errors'];
	}

	/**
	 * Validate entire schema with severity levels.
	 *
	 * @param array $schema
	 * @return array Validation result with 'errors' and 'warnings' keys
	 */
	public function validateSchemaWithSeverity( array $schema ): array {
		$errors = [];
		$warnings = [];

		$structureErrors = $this->validateSchemaStructure( $schema );
		if ( !empty( $structureErrors ) ) {
			return [ 'errors' => $structureErrors, 'warnings' => [] ];
		}

		$categories = $schema['categories'];
		$properties = $schema['properties'];
		$subobjects = $schema['subobjects'] ?? [];

		foreach ( $categories as $categoryName => $categoryData ) {
			$this->mergeResults(
				$errors,
				$warnings,
				$this->validateCategory( $categoryName, $categoryData, $categories, $properties, $subobjects )
			);
		}

		foreach ( $properties as $propertyName => $propertyData ) {
			$this->mergeResults(
				$errors,
				$warnings,
				$this->validateProperty( $propertyName, $propertyData, $categories )
			);
		}

		foreach ( $subobjects as $subobjectName => $subobjectData ) {
			$this->mergeResults(
				$errors,
				$warnings,
				$this->validateSubobject( $subobjectName, $subobjectData, $properties )
			);
		}

		$errors = array_merge( $errors, $this->checkCircularDependencies( $categories ) );
		$warnings = array_merge( $warnings, $this->generateWarnings( $schema ) );

		foreach ( $this->customValidators as $validator ) {
			$customResult = call_user_func( $validator, $schema );
			$this->mergeResults( $errors, $warnings, $customResult );
		}

		return [ 'errors' => $errors, 'warnings' => $warnings ];
	}

	/**
	 * Validate required top-level schema fields.
	 *
	 * @param array $schema
	 * @return array Error messages if structure is invalid
	 */
	private function validateSchemaStructure( array $schema ): array {
		$errors = [];

		if ( !isset( $schema['schemaVersion'] ) ) {
			$errors[] = $this->formatError(
				'schema',
				'',
				'Missing required field: schemaVersion',
				'Add "schemaVersion": "1.0" to the schema root'
			);
		}

		if ( !isset( $schema['categories'] ) || !is_array( $schema['categories'] ) ) {
			$errors[] = $this->formatError(
				'schema',
				'categories',
				'Missing or invalid field: categories',
				'Ensure "categories" is an object/array at schema root'
			);
		}

		if ( !isset( $schema['properties'] ) || !is_array( $schema['properties'] ) ) {
			$errors[] = $this->formatError(
				'schema',
				'properties',
				'Missing or invalid field: properties',
				'Ensure "properties" is an object/array at schema root'
			);
		}

		return $errors;
	}

	/**
	 * Merge validation results into error and warning arrays.
	 *
	 * @param array &$errors
	 * @param array &$warnings
	 * @param array $result
	 */
	private function mergeResults( array &$errors, array &$warnings, array $result ): void {
		if ( isset( $result['errors'] ) ) {
			$errors = array_merge( $errors, $result['errors'] );
		}
		if ( isset( $result['warnings'] ) ) {
			$warnings = array_merge( $warnings, $result['warnings'] );
		}
	}

	/**
	 * Format a validation error message with context and suggestions.
	 *
	 * @param string $entityType 'category', 'property', 'subobject', or 'schema'
	 * @param string $entityName Name of the entity
	 * @param string $issue Description of the issue
	 * @param string $suggestion How to fix it
	 * @return string Formatted error message
	 */
	private function formatError(
		string $entityType,
		string $entityName,
		string $issue,
		string $suggestion
	): string {
		$prefix = $entityType === 'schema' ? 'Schema' : ucfirst( $entityType ) . " '$entityName'";
		return $suggestion ? "$prefix: $issue → Fix: $suggestion" : "$prefix: $issue";
	}

	/**
	 * Format a validation warning message.
	 *
	 * @param string $entityType 'category', 'property', or 'schema'
	 * @param string $entityName Name of the entity
	 * @param string $issue Description of the issue
	 * @return string Formatted warning message
	 */
	private function formatWarning( string $entityType, string $entityName, string $issue ): string {
		$prefix = $entityType === 'schema' ? 'Schema' : ucfirst( $entityType ) . " '$entityName'";
		return "$prefix: $issue";
	}

	/**
	 * Register a custom validation rule.
	 *
	 * @param callable $validator Takes schema array, returns ['errors' => [...], 'warnings' => [...]]
	 */
	public function registerCustomValidator( callable $validator ): void {
		$this->customValidators[] = $validator;
	}

	/* ======================================================================
	 * COMMON VALIDATION HELPERS
	 * ====================================================================== */

	/**
	 * Validate that a field is an array if it exists.
	 *
	 * @param string $entityType
	 * @param string $entityName
	 * @param array $data
	 * @param string $field
	 * @param string $suggestion
	 * @return string|null Error message or null if valid
	 */
	private function requireArrayField(
		string $entityType,
		string $entityName,
		array $data,
		string $field,
		string $suggestion
	): ?string {
		if ( isset( $data[$field] ) && !is_array( $data[$field] ) ) {
			return $this->formatError( $entityType, $entityName, "$field must be an array", $suggestion );
		}
		return null;
	}

	/**
	 * Validate references to items in a lookup array.
	 *
	 * @param string $entityType
	 * @param string $entityName
	 * @param array $references List of names to check
	 * @param array $lookup Valid items indexed by name
	 * @param string $referenceType 'property', 'category', or 'subobject'
	 * @param string $context Additional context for error message
	 * @return array Error messages
	 */
	private function validateReferences(
		string $entityType,
		string $entityName,
		array $references,
		array $lookup,
		string $referenceType,
		string $context = ''
	): array {
		$errors = [];
		foreach ( $references as $ref ) {
			if ( !isset( $lookup[$ref] ) ) {
				$contextPrefix = $context ? "$context " : '';
				$section = str_replace( ' ', '', $referenceType );
				$errors[] = $this->formatError(
					$entityType,
					$entityName,
					"{$contextPrefix}$referenceType '$ref' does not exist in schema",
					"Add '$ref' to the {$section}s section or remove it from this $entityType"
				);
			}
		}
		return $errors;
	}

	/**
	 * Validate required/optional bucket structure.
	 *
	 * This pattern is used for properties and subobjects in categories,
	 * and for properties in subobjects.
	 *
	 * @param string $entityType
	 * @param string $entityName
	 * @param array $buckets Array with 'required' and/or 'optional' keys
	 * @param array $lookup Valid items to reference
	 * @param string $referenceType 'property' or 'subobject'
	 * @param string $fieldPrefix Field path prefix for error messages
	 * @return array ['errors' => [...], 'warnings' => [...]]
	 */
	private function validateRequiredOptionalBuckets(
		string $entityType,
		string $entityName,
		array $buckets,
		array $lookup,
		string $referenceType,
		string $fieldPrefix = ''
	): array {
		$errors = [];
		$warnings = [];
		$prefix = $fieldPrefix ? "$fieldPrefix." : '';

		foreach ( [ 'required', 'optional' ] as $bucket ) {
			$error = $this->requireArrayField(
				$entityType,
				$entityName,
				$buckets,
				$bucket,
				"Use an array like [\"Item1\", \"Item2\"]"
			);
			if ( $error ) {
				$errors[] = str_replace( "$bucket must be", "{$prefix}$bucket must be", $error );
			}
		}

		$required = is_array( $buckets['required'] ?? null ) ? $buckets['required'] : [];
		$optional = is_array( $buckets['optional'] ?? null ) ? $buckets['optional'] : [];

		$duplicates = array_intersect(
			array_map( 'strval', $required ),
			array_map( 'strval', $optional )
		);
		if ( !empty( $duplicates ) ) {
			$itemType = ucfirst( $referenceType ) . 's';
			$warnings[] = $this->formatWarning(
				$entityType,
				$entityName,
				"$itemType listed as both required and optional will be promoted to required: "
				. implode( ', ', $duplicates )
			);
		}

		$errors = array_merge(
			$errors,
			$this->validateReferences( $entityType, $entityName, $required, $lookup, $referenceType, 'required' ),
			$this->validateReferences( $entityType, $entityName, $optional, $lookup, $referenceType, 'optional' )
		);

		return [ 'errors' => $errors, 'warnings' => $warnings ];
	}

	/**
	 * Validate sections array (used by both display and forms).
	 *
	 * @param string $categoryName
	 * @param array $sections
	 * @param array $allProperties
	 * @param string $configType 'display' or 'forms'
	 * @return array ['errors' => [...], 'warnings' => [...]]
	 */
	private function validateSections(
		string $categoryName,
		array $sections,
		array $allProperties,
		string $configType
	): array {
		$errors = [];
		$warnings = [];

		foreach ( $sections as $i => $section ) {
			if ( !isset( $section['name'] ) ) {
				$errors[] = $this->formatError(
					'category',
					$categoryName,
					"$configType.sections[$i] missing 'name' field",
					"Add a 'name' field to section $i"
				);
			}

			if ( !isset( $section['properties'] ) ) {
				continue;
			}

			if ( !is_array( $section['properties'] ) ) {
				$errors[] = $this->formatError(
					'category',
					$categoryName,
					"$configType.sections[$i].properties must be an array",
					'Use an array of property names'
				);
				continue;
			}

			$errors = array_merge(
				$errors,
				$this->validateReferences(
					'category',
					$categoryName,
					$section['properties'],
					$allProperties,
					'property',
					"$configType section"
				)
			);
		}

		return [ 'errors' => $errors, 'warnings' => $warnings ];
	}

	/* ======================================================================
	 * CATEGORY VALIDATION
	 * ====================================================================== */

	private function validateCategory(
		string $categoryName,
		array $categoryData,
		array $allCategories,
		array $allProperties,
		array $allSubobjects
	): array {
		$errors = [];
		$warnings = [];

		if ( trim( $categoryName ) === '' ) {
			$errors[] = $this->formatError(
				'category',
				$categoryName,
				'Name cannot be empty',
				'Provide a non-empty category name'
			);
			return [ 'errors' => $errors, 'warnings' => $warnings ];
		}

		if ( preg_match( '/[_\-]/', $categoryName ) ) {
			$warnings[] = $this->formatWarning(
				'category',
				$categoryName,
				'Name contains underscores or hyphens; spaces are recommended for category names'
			);
		}

		if ( isset( $categoryData['parents'] ) ) {
			$this->mergeResults(
				$errors,
				$warnings,
				$this->validateCategoryParents( $categoryName, $categoryData['parents'], $allCategories )
			);
		}

		if ( isset( $categoryData['properties'] ) ) {
			$this->mergeResults(
				$errors,
				$warnings,
				$this->validateRequiredOptionalBuckets(
					'category',
					$categoryName,
					$categoryData['properties'],
					$allProperties,
					'property',
					'properties'
				)
			);
		}

		if ( isset( $categoryData['subobjects'] ) ) {
			$this->mergeResults(
				$errors,
				$warnings,
				$this->validateRequiredOptionalBuckets(
					'category',
					$categoryName,
					$categoryData['subobjects'],
					$allSubobjects,
					'subobject',
					'subobjects'
				)
			);
		}

		if ( isset( $categoryData['display'] ) ) {
			$this->mergeResults(
				$errors,
				$warnings,
				$this->validateDisplayConfig( $categoryName, $categoryData['display'], $allProperties )
			);
		}

		if ( isset( $categoryData['forms'] ) ) {
			$this->mergeResults(
				$errors,
				$warnings,
				$this->validateFormConfig( $categoryName, $categoryData['forms'], $allProperties )
			);
		}

		return [ 'errors' => $errors, 'warnings' => $warnings ];
	}

	/**
	 * Validate category parents field.
	 *
	 * @param string $categoryName
	 * @param mixed $parents
	 * @param array $allCategories
	 * @return array ['errors' => [...], 'warnings' => [...]]
	 */
	private function validateCategoryParents(
		string $categoryName,
		$parents,
		array $allCategories
	): array {
		$errors = [];
		$warnings = [];

		if ( !is_array( $parents ) ) {
			$errors[] = $this->formatError(
				'category',
				$categoryName,
				'parents must be an array',
				'Use an array like ["ParentCategory1", "ParentCategory2"]'
			);
			return [ 'errors' => $errors, 'warnings' => $warnings ];
		}

		$errors = array_merge(
			$errors,
			$this->validateReferences( 'category', $categoryName, $parents, $allCategories, 'parent category' )
		);

		return [ 'errors' => $errors, 'warnings' => $warnings ];
	}

	/* ======================================================================
	 * SUBOBJECT VALIDATION
	 * ====================================================================== */

	private function validateSubobject(
		string $subobjectName,
		array $subobjectData,
		array $allProperties
	): array {
		$errors = [];
		$warnings = [];

		if ( trim( $subobjectName ) === '' ) {
			$errors[] = $this->formatError(
				'subobject',
				$subobjectName,
				'Name cannot be empty',
				'Provide a non-empty subobject name'
			);
			return [ 'errors' => $errors, 'warnings' => $warnings ];
		}

		if ( !isset( $subobjectData['properties'] ) ) {
			return [ 'errors' => $errors, 'warnings' => $warnings ];
		}

		$props = $subobjectData['properties'];
		if ( !is_array( $props ) ) {
			$errors[] = $this->formatError(
				'subobject',
				$subobjectName,
				'properties must be an array with required/optional keys',
				'Use {"required": [...], "optional": [...]}'
			);
			return [ 'errors' => $errors, 'warnings' => $warnings ];
		}

		$this->mergeResults(
			$errors,
			$warnings,
			$this->validateRequiredOptionalBuckets(
				'subobject',
				$subobjectName,
				$props,
				$allProperties,
				'property',
				'properties'
			)
		);

		return [ 'errors' => $errors, 'warnings' => $warnings ];
	}

	/* ======================================================================
	 * DISPLAY VALIDATION
	 * ====================================================================== */

	private function validateDisplayConfig(
		string $categoryName,
		array $config,
		array $allProperties
	): array {
		$errors = [];
		$warnings = [];

		if ( isset( $config['header'] ) ) {
			if ( !is_array( $config['header'] ) ) {
				$errors[] = $this->formatError(
					'category',
					$categoryName,
					'display.header must be an array',
					'Use an array of property names like ["Property1", "Property2"]'
				);
			} else {
				$errors = array_merge(
					$errors,
					$this->validateReferences(
						'category',
						$categoryName,
						$config['header'],
						$allProperties,
						'property',
						'display header'
					)
				);
			}
		}

		if ( isset( $config['sections'] ) ) {
			if ( !is_array( $config['sections'] ) ) {
				$errors[] = $this->formatError(
					'category',
					$categoryName,
					'display.sections must be an array',
					'Use an array of section objects'
				);
			} else {
				$this->mergeResults(
					$errors,
					$warnings,
					$this->validateSections( $categoryName, $config['sections'], $allProperties, 'display' )
				);
			}
		}

		return [ 'errors' => $errors, 'warnings' => $warnings ];
	}

	/* ======================================================================
	 * FORM VALIDATION
	 * ====================================================================== */

	private function validateFormConfig(
		string $categoryName,
		array $config,
		array $allProperties
	): array {
		$errors = [];
		$warnings = [];

		if ( !isset( $config['sections'] ) ) {
			return [ 'errors' => $errors, 'warnings' => $warnings ];
		}

		if ( !is_array( $config['sections'] ) ) {
			$errors[] = $this->formatError(
				'category',
				$categoryName,
				'forms.sections must be an array',
				'Use an array of section objects'
			);
			return [ 'errors' => $errors, 'warnings' => $warnings ];
		}

		$this->mergeResults(
			$errors,
			$warnings,
			$this->validateSections( $categoryName, $config['sections'], $allProperties, 'forms' )
		);

		return [ 'errors' => $errors, 'warnings' => $warnings ];
	}

	/* ======================================================================
	 * PROPERTY VALIDATION
	 * ====================================================================== */

	private function validateProperty(
		string $propertyName,
		array $propertyData,
		array $allCategories
	): array {
		$errors = [];
		$warnings = [];

		if ( trim( $propertyName ) === '' ) {
			$errors[] = $this->formatError(
				'property',
				$propertyName,
				'Name cannot be empty',
				'Provide a non-empty property name'
			);
			return [ 'errors' => $errors, 'warnings' => $warnings ];
		}

		if ( !str_starts_with( $propertyName, 'Has ' ) ) {
			$warnings[] = $this->formatWarning(
				'property',
				$propertyName,
				'Property names should start with "Has " by SMW convention'
			);
		}

		if ( !isset( $propertyData['datatype'] ) ) {
			$errors[] = $this->formatError(
				'property',
				$propertyName,
				'missing required field: datatype',
				'Add a datatype field (e.g., "Text", "Page", "Number", "Date")'
			);
		}

		if ( isset( $propertyData['allowedValues'] ) && !is_array( $propertyData['allowedValues'] ) ) {
			$errors[] = $this->formatError(
				'property',
				$propertyName,
				'allowedValues must be an array',
				'Use an array of allowed values like ["Value1", "Value2"]'
			);
		}

		if ( isset( $propertyData['rangeCategory'] ) ) {
			$range = $propertyData['rangeCategory'];
			if ( !isset( $allCategories[$range] ) ) {
				$errors[] = $this->formatError(
					'property',
					$propertyName,
					"rangeCategory '$range' is not defined in schema",
					"Add '$range' to the categories section or remove rangeCategory"
				);
			}
		}

		return [ 'errors' => $errors, 'warnings' => $warnings ];
	}

	/* ======================================================================
	 * CIRCULAR DEPENDENCY DETECTION
	 * ====================================================================== */

	private function checkCircularDependencies( array $categories ): array {
		$categoryModels = [];

		foreach ( $categories as $name => $data ) {
			try {
				$categoryModels[$name] = new CategoryModel( $name, $data );
			} catch ( \InvalidArgumentException | \TypeError $e ) {
				continue;
			}
		}

		if ( empty( $categoryModels ) ) {
			return [];
		}

		$resolver = new InheritanceResolver( $categoryModels );
		return $resolver->validateInheritance();
	}

	/* ======================================================================
	 * WARNINGS (non-fatal)
	 * ====================================================================== */

	public function generateWarnings( array $schema ): array {
		$warnings = [];

		if ( !isset( $schema['categories'] ) || !isset( $schema['properties'] ) ) {
			return $warnings;
		}

		$categories = $schema['categories'];
		$properties = $schema['properties'];

		foreach ( $categories as $name => $data ) {
			$req = $data['properties']['required'] ?? [];
			$opt = $data['properties']['optional'] ?? [];

			if ( empty( $req ) && empty( $opt ) ) {
				$warnings[] = "Category '$name': no properties defined";
			}

			if ( empty( $data['display'] ?? [] ) ) {
				$warnings[] = "Category '$name': missing display configuration";
			}

			if ( empty( $data['forms'] ?? [] ) ) {
				$warnings[] = "Category '$name': missing form configuration";
			}
		}

		$used = [];
		foreach ( $categories as $cat ) {
			$used = array_merge(
				$used,
				$cat['properties']['required'] ?? [],
				$cat['properties']['optional'] ?? []
			);
		}
		$used = array_unique( $used );

		foreach ( array_keys( $properties ) as $p ) {
			if ( !in_array( $p, $used, true ) ) {
				$warnings[] = "Property '$p': not used by any category";
			}
		}

		return $warnings;
	}
}
