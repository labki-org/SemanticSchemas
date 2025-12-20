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
 * 
 * Error Messages:
 * --------------
 * Error messages are designed to be actionable:
 * - Include the entity name (category/property) that failed
 * - Specify the exact field that's invalid
 * - Suggest fixes where possible
 * - Provide context for why the validation failed
 * 
 * Custom Validation Hooks:
 * -----------------------
 * Extensions can register custom validation rules via the
 * 'SemanticSchemasValidateSchema' hook (future enhancement).
 */
class SchemaValidator
{

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
	public function validateSchema(array $schema): array
	{
		$result = $this->validateSchemaWithSeverity($schema);
		return $result['errors'];
	}

	/**
	 * Validate entire schema with severity levels.
	 *
	 * Returns a structured result with both errors and warnings:
	 * [
	 *   'errors' => [...],    // Critical issues that prevent import
	 *   'warnings' => [...],  // Non-critical issues
	 * ]
	 *
	 * @param array $schema
	 * @return array Validation result with 'errors' and 'warnings' keys
	 */
	public function validateSchemaWithSeverity(array $schema): array
	{
		$errors = [];
		$warnings = [];

		// Basic structure
		if (!isset($schema['schemaVersion'])) {
			$errors[] = $this->formatError(
				'schema',
				'',
				'Missing required field: schemaVersion',
				'Add "schemaVersion": "1.0" to the schema root'
			);
		}

		if (!isset($schema['categories']) || !is_array($schema['categories'])) {
			$errors[] = $this->formatError(
				'schema',
				'categories',
				'Missing or invalid field: categories',
				'Ensure "categories" is an object/array at schema root'
			);
			return ['errors' => $errors, 'warnings' => $warnings]; // cannot continue
		}

		if (!isset($schema['properties']) || !is_array($schema['properties'])) {
			$errors[] = $this->formatError(
				'schema',
				'properties',
				'Missing or invalid field: properties',
				'Ensure "properties" is an object/array at schema root'
			);
			return ['errors' => $errors, 'warnings' => $warnings];
		}

		$categories = $schema['categories'];
		$properties = $schema['properties'];
		$subobjects = $schema['subobjects'] ?? [];

		// Validate category definitions
		foreach ($categories as $categoryName => $categoryData) {
			$categoryResult = $this->validateCategory(
				$categoryName,
				$categoryData,
				$categories,
				$properties,
				$subobjects
			);
			$errors = array_merge($errors, $categoryResult['errors']);
			$warnings = array_merge($warnings, $categoryResult['warnings']);
		}

		// Validate property definitions
		foreach ($properties as $propertyName => $propertyData) {
			$propertyResult = $this->validateProperty(
				$propertyName,
				$propertyData,
				$categories
			);
			$errors = array_merge($errors, $propertyResult['errors']);
			$warnings = array_merge($warnings, $propertyResult['warnings']);
		}

		// Validate subobject definitions
		if (isset($schema['subobjects']) && is_array($schema['subobjects'])) {
			foreach ($schema['subobjects'] as $subobjectName => $subobjectData) {
				$subobjectResult = $this->validateSubobject(
					$subobjectName,
					$subobjectData,
					$properties
				);
				$errors = array_merge($errors, $subobjectResult['errors']);
				$warnings = array_merge($warnings, $subobjectResult['warnings']);
			}
		}

		// Detect inheritance cycles
		$errors = array_merge(
			$errors,
			$this->checkCircularDependencies($categories)
		);

		// Generate general warnings
		$warnings = array_merge(
			$warnings,
			$this->generateWarnings($schema)
		);

		// Run custom validators (if any)
		foreach ($this->customValidators as $validator) {
			$customResult = call_user_func($validator, $schema);
			if (isset($customResult['errors'])) {
				$errors = array_merge($errors, $customResult['errors']);
			}
			if (isset($customResult['warnings'])) {
				$warnings = array_merge($warnings, $customResult['warnings']);
			}
		}

		return [
			'errors' => $errors,
			'warnings' => $warnings,
		];
	}

	/**
	 * Format a validation error message with context and suggestions.
	 *
	 * @param string $entityType 'category', 'property', or 'schema'
	 * @param string $entityName Name of the entity (category/property)
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
		$prefix = $entityType === 'schema' ? 'Schema' : ucfirst($entityType) . " '$entityName'";
		$message = "$prefix: $issue";
		if ($suggestion) {
			$message .= " → Fix: $suggestion";
		}
		return $message;
	}

	/**
	 * Format a validation warning message.
	 *
	 * @param string $entityType 'category', 'property', or 'schema'
	 * @param string $entityName Name of the entity
	 * @param string $issue Description of the issue
	 * @return string Formatted warning message
	 */
	private function formatWarning(
		string $entityType,
		string $entityName,
		string $issue
	): string {
		$prefix = $entityType === 'schema' ? 'Schema' : ucfirst($entityType) . " '$entityName'";
		return "$prefix: $issue";
	}

	/**
	 * Register a custom validation rule.
	 *
	 * Validator should be a callable that takes a schema array and returns:
	 * [ 'errors' => [...], 'warnings' => [...] ]
	 *
	 * @param callable $validator
	 */
	public function registerCustomValidator(callable $validator): void
	{
		$this->customValidators[] = $validator;
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

		// --- Name validation ------------------------------------------------
		if (empty($categoryName) || trim($categoryName) === '') {
			$errors[] = $this->formatError(
				'category',
				$categoryName,
				'Name cannot be empty',
				'Provide a non-empty category name'
			);
			return ['errors' => $errors, 'warnings' => $warnings];
		}

		// Check naming conventions
		if (preg_match('/[_\-]/', $categoryName)) {
			$warnings[] = $this->formatWarning(
				'category',
				$categoryName,
				'Name contains underscores or hyphens; spaces are recommended for category names'
			);
		}

		// --- parents --------------------------------------------------------
		if (isset($categoryData['parents'])) {
			if (!is_array($categoryData['parents'])) {
				$errors[] = $this->formatError(
					'category',
					$categoryName,
					'parents must be an array',
					'Use an array like ["ParentCategory1", "ParentCategory2"]'
				);
			} else {
				foreach ($categoryData['parents'] as $parent) {
					if (!isset($allCategories[$parent])) {
						$errors[] = $this->formatError(
							'category',
							$categoryName,
							"parent category '$parent' does not exist in schema",
							"Add '$parent' to the categories section or remove it from parents"
						);
					}
				}
			}
		}

		// --- properties -----------------------------------------------------
		if (isset($categoryData['properties'])) {
			$propResult = $this->validateCategoryProperties(
				$categoryName,
				$categoryData['properties'],
				$allProperties
			);
			$errors = array_merge($errors, $propResult['errors']);
			$warnings = array_merge($warnings, $propResult['warnings']);
		}

		// --- subobjects ------------------------------------------------------
		if (isset($categoryData['subobjects'])) {
			$subobjectResult = $this->validateCategorySubobjects(
				$categoryName,
				$categoryData['subobjects'],
				$allSubobjects
			);
			$errors = array_merge($errors, $subobjectResult['errors']);
			$warnings = array_merge($warnings, $subobjectResult['warnings']);
		}

		// --- display config -----------------------------------------------
		if (isset($categoryData['display'])) {
			$displayResult = $this->validateDisplayConfig(
				$categoryName,
				$categoryData['display'],
				$allProperties
			);
			$errors = array_merge($errors, $displayResult['errors']);
			$warnings = array_merge($warnings, $displayResult['warnings']);
		}

		// --- form config ---------------------------------------------------
		if (isset($categoryData['forms'])) {
			$formResult = $this->validateFormConfig(
				$categoryName,
				$categoryData['forms'],
				$allProperties
			);
			$errors = array_merge($errors, $formResult['errors']);
			$warnings = array_merge($warnings, $formResult['warnings']);
		}

		return ['errors' => $errors, 'warnings' => $warnings];
	}

	private function validateCategorySubobjects(
		string $categoryName,
		array $subobjectLists,
		array $allSubobjects
	): array {
		$errors = [];
		$warnings = [];

		$required = $subobjectLists['required'] ?? [];
		$optional = $subobjectLists['optional'] ?? [];

		if (!is_array($required)) {
			$errors[] = $this->formatError(
				'category',
				$categoryName,
				'subobjects.required must be an array',
				'Use an array like ["PublicationAuthor", ...]'
			);
		}

		if (!is_array($optional)) {
			$errors[] = $this->formatError(
				'category',
				$categoryName,
				'subobjects.optional must be an array',
				'Use an array like ["FundingLine", ...]'
			);
		}

		$required = is_array($required) ? $required : [];
		$optional = is_array($optional) ? $optional : [];

		$duplicates = array_intersect(
			array_map('strval', $required),
			array_map('strval', $optional)
		);
		if (!empty($duplicates)) {
			$errors[] = $this->formatError(
				'category',
				$categoryName,
				'Subobjects cannot be both required and optional: ' . implode(', ', $duplicates),
				'Remove duplicates from either list'
			);
		}

		foreach ($required as $subobjectName) {
			if (!isset($allSubobjects[$subobjectName])) {
				$errors[] = $this->formatError(
					'category',
					$categoryName,
					"required subobject '$subobjectName' is not defined in schema",
					"Add '$subobjectName' to the subobjects section or remove it from this category"
				);
			}
		}

		foreach ($optional as $subobjectName) {
			if (!isset($allSubobjects[$subobjectName])) {
				$errors[] = $this->formatError(
					'category',
					$categoryName,
					"optional subobject '$subobjectName' is not defined in schema",
					"Add '$subobjectName' to the subobjects section or remove it from this category"
				);
			}
		}

		return ['errors' => $errors, 'warnings' => $warnings];
	}

	private function validateSubobject(
		string $subobjectName,
		array $subobjectData,
		array $allProperties
	): array {
		$errors = [];
		$warnings = [];

		if (trim($subobjectName) === '') {
			$errors[] = $this->formatError(
				'subobject',
				$subobjectName,
				'Name cannot be empty',
				'Provide a non-empty subobject name'
			);
			return ['errors' => $errors, 'warnings' => $warnings];
		}

		if (isset($subobjectData['properties'])) {
			$props = $subobjectData['properties'];
			if (!is_array($props)) {
				$errors[] = $this->formatError(
					'subobject',
					$subobjectName,
					'properties must be an array with required/optional keys',
					'Use {"required": [...], "optional": [...]}'
				);
			} else {
				foreach (['required', 'optional'] as $bucket) {
					if (isset($props[$bucket]) && !is_array($props[$bucket])) {
						$errors[] = $this->formatError(
							'subobject',
							$subobjectName,
							"properties.$bucket must be an array",
							'Use an array like ["Property:Has author", ...]'
						);
					}
				}

				$required = $props['required'] ?? [];
				$optional = $props['optional'] ?? [];
				$duplicates = array_intersect(
					array_map('strval', $required),
					array_map('strval', $optional)
				);
				if (!empty($duplicates)) {
					$errors[] = $this->formatError(
						'subobject',
						$subobjectName,
						'Properties cannot be both required and optional: ' . implode(', ', $duplicates),
						'Remove duplicates from either list'
					);
				}

				foreach ($required as $propertyName) {
					if (!isset($allProperties[$propertyName])) {
						$errors[] = $this->formatError(
							'subobject',
							$subobjectName,
							"required property '$propertyName' is not defined in schema",
							"Add '$propertyName' to the properties section or remove it from this subobject"
						);
					}
				}

				foreach ($optional as $propertyName) {
					if (!isset($allProperties[$propertyName])) {
						$errors[] = $this->formatError(
							'subobject',
							$subobjectName,
							"optional property '$propertyName' is not defined in schema",
							"Add '$propertyName' to the properties section or remove it from this subobject"
						);
					}
				}
			}
		}

		return ['errors' => $errors, 'warnings' => $warnings];
	}

	private function validateCategoryProperties(
		string $categoryName,
		array $propertyLists,
		array $allProperties
	): array {
		$errors = [];
		$warnings = [];

		// Required
		if (isset($propertyLists['required'])) {
			if (!is_array($propertyLists['required'])) {
				$errors[] = $this->formatError(
					'category',
					$categoryName,
					'properties.required must be an array',
					'Use an array like ["Property1", "Property2"]'
				);
			} else {
				foreach ($propertyLists['required'] as $p) {
					if (!isset($allProperties[$p])) {
						$errors[] = $this->formatError(
							'category',
							$categoryName,
							"required property '$p' is not defined in schema",
							"Add '$p' to the properties section or remove it from this category"
						);
					}
				}
			}
		}

		// Optional
		if (isset($propertyLists['optional'])) {
			if (!is_array($propertyLists['optional'])) {
				$errors[] = $this->formatError(
					'category',
					$categoryName,
					'properties.optional must be an array',
					'Use an array like ["Property1", "Property2"]'
				);
			} else {
				foreach ($propertyLists['optional'] as $p) {
					if (!isset($allProperties[$p])) {
						$errors[] = $this->formatError(
							'category',
							$categoryName,
							"optional property '$p' is not defined in schema",
							"Add '$p' to the properties section or remove it from this category"
						);
					}
				}
			}
		}

		return ['errors' => $errors, 'warnings' => $warnings];
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

		// header
		if (isset($config['header'])) {
			if (!is_array($config['header'])) {
				$errors[] = $this->formatError(
					'category',
					$categoryName,
					'display.header must be an array',
					'Use an array of property names like ["Property1", "Property2"]'
				);
			} else {
				foreach ($config['header'] as $prop) {
					if (!isset($allProperties[$prop])) {
						$errors[] = $this->formatError(
							'category',
							$categoryName,
							"display header property '$prop' is not defined in schema",
							"Add '$prop' to properties or remove it from display header"
						);
					}
				}
			}
		}

		// sections
		if (isset($config['sections'])) {
			if (!is_array($config['sections'])) {
				$errors[] = $this->formatError(
					'category',
					$categoryName,
					'display.sections must be an array',
					'Use an array of section objects'
				);
			} else {
				foreach ($config['sections'] as $i => $section) {
					if (!isset($section['name'])) {
						$errors[] = $this->formatError(
							'category',
							$categoryName,
							"display.sections[$i] missing 'name' field",
							"Add a 'name' field to section $i"
						);
					}

					if (isset($section['properties'])) {
						if (!is_array($section['properties'])) {
							$errors[] = $this->formatError(
								'category',
								$categoryName,
								"display.sections[$i].properties must be an array",
								'Use an array of property names'
							);
						} else {
							foreach ($section['properties'] as $prop) {
								if (!isset($allProperties[$prop])) {
									$errors[] = $this->formatError(
										'category',
										$categoryName,
										"display section property '$prop' is not defined in schema",
										"Add '$prop' to properties or remove it from this display section"
									);
								}
							}
						}
					}
				}
			}
		}

		return ['errors' => $errors, 'warnings' => $warnings];
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

		if (isset($config['sections'])) {
			if (!is_array($config['sections'])) {
				$errors[] = $this->formatError(
					'category',
					$categoryName,
					'forms.sections must be an array',
					'Use an array of section objects'
				);
			} else {
				foreach ($config['sections'] as $i => $section) {

					if (!isset($section['name'])) {
						$errors[] = $this->formatError(
							'category',
							$categoryName,
							"forms.sections[$i] missing 'name' field",
							"Add a 'name' field to form section $i"
						);
					}

					if (isset($section['properties'])) {
						if (!is_array($section['properties'])) {
							$errors[] = $this->formatError(
								'category',
								$categoryName,
								"forms.sections[$i].properties must be an array",
								'Use an array of property names'
							);
						} else {
							foreach ($section['properties'] as $prop) {
								if (!isset($allProperties[$prop])) {
									$errors[] = $this->formatError(
										'category',
										$categoryName,
										"form section property '$prop' is not defined in schema",
										"Add '$prop' to properties or remove it from this form section"
									);
								}
							}
						}
					}
				}
			}
		}

		return ['errors' => $errors, 'warnings' => $warnings];
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

		// Name validation
		if (empty($propertyName) || trim($propertyName) === '') {
			$errors[] = $this->formatError(
				'property',
				$propertyName,
				'Name cannot be empty',
				'Provide a non-empty property name'
			);
			return ['errors' => $errors, 'warnings' => $warnings];
		}

		// Check naming conventions
		if (!str_starts_with($propertyName, 'Has ')) {
			$warnings[] = $this->formatWarning(
				'property',
				$propertyName,
				'Property names should start with "Has " by SMW convention'
			);
		}

		// datatype required
		if (!isset($propertyData['datatype'])) {
			$errors[] = $this->formatError(
				'property',
				$propertyName,
				'missing required field: datatype',
				'Add a datatype field (e.g., "Text", "Page", "Number", "Date")'
			);
		}

		// allowedValues must be array
		if (
			isset($propertyData['allowedValues']) &&
			!is_array($propertyData['allowedValues'])
		) {

			$errors[] = $this->formatError(
				'property',
				$propertyName,
				'allowedValues must be an array',
				'Use an array of allowed values like ["Value1", "Value2"]'
			);
		}

		// rangeCategory must exist
		if (isset($propertyData['rangeCategory'])) {
			$range = $propertyData['rangeCategory'];
			if (!isset($allCategories[$range])) {
				$errors[] = $this->formatError(
					'property',
					$propertyName,
					"rangeCategory '$range' is not defined in schema",
					"Add '$range' to the categories section or remove rangeCategory"
				);
			}
		}

		return ['errors' => $errors, 'warnings' => $warnings];
	}


	/* ======================================================================
	 * CIRCULAR DEPENDENCY DETECTION
	 * ====================================================================== */

	private function checkCircularDependencies(array $categories): array
	{
		$categoryModels = [];

		foreach ($categories as $name => $data) {
			$categoryModels[$name] = new CategoryModel($name, $data);
		}

		$resolver = new InheritanceResolver($categoryModels);
		return $resolver->validateInheritance();
	}


	/* ======================================================================
	 * WARNINGS (non-fatal)
	 * ====================================================================== */

	public function generateWarnings(array $schema): array
	{
		$warnings = [];

		if (!isset($schema['categories'], $schema['properties'])) {
			return $warnings;
		}

		$categories = $schema['categories'];
		$properties = $schema['properties'];

		// Category warnings
		foreach ($categories as $name => $data) {
			$req = $data['properties']['required'] ?? [];
			$opt = $data['properties']['optional'] ?? [];

			if (empty($req) && empty($opt)) {
				$warnings[] = "Category '$name': no properties defined";
			}

			if (empty($data['display'] ?? [])) {
				$warnings[] = "Category '$name': missing display configuration";
			}

			if (empty($data['forms'] ?? [])) {
				$warnings[] = "Category '$name': missing form configuration";
			}
		}

		// Unused property warnings
		$used = [];
		foreach ($categories as $cat) {
			$used = array_merge(
				$used,
				$cat['properties']['required'] ?? [],
				$cat['properties']['optional'] ?? []
			);
		}
		$used = array_unique($used);

		foreach (array_keys($properties) as $p) {
			if (!in_array($p, $used, true)) {
				$warnings[] = "Property '$p': not used by any category";
			}
		}

		return $warnings;
	}
}
