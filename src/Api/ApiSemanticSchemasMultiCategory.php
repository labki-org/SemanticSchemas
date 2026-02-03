<?php

namespace MediaWiki\Extension\SemanticSchemas\Api;

use ApiBase;
use MediaWiki\Extension\SemanticSchemas\Schema\InheritanceResolver;
use MediaWiki\Extension\SemanticSchemas\Schema\MultiCategoryResolver;
use MediaWiki\Extension\SemanticSchemas\Schema\ResolvedPropertySet;
use MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;

/**
 * ApiSemanticSchemasMultiCategory
 * --------------------------------
 * Returns resolved properties and subobjects for one or more categories.
 *
 * Endpoint:
 *   api.php?action=semanticschemas-multicategory&categories=Person|Employee
 *
 * Delegates to MultiCategoryResolver for property resolution across categories.
 *
 * Security:
 *   - Requires 'edit' permission to call this API
 */
class ApiSemanticSchemasMultiCategory extends ApiBase {

	/**
	 * Execute the API request.
	 */
	public function execute() {
		// Require edit permission
		$this->checkUserRightsAny( 'edit' );

		$params = $this->extractRequestParams();

		// Normalize category names: strip "Category:" prefix
		$categoryNames = array_map( [ $this, 'stripPrefix' ], $params['categories'] );

		// Load all categories for validation
		$categoryStore = new WikiCategoryStore();
		$allCategories = $categoryStore->getAllCategories();

		// Validate all categories exist
		$this->validateCategories( $categoryNames, $allCategories );

		// Resolve properties and subobjects
		$inheritanceResolver = new InheritanceResolver( $allCategories );
		$multiResolver = new MultiCategoryResolver( $inheritanceResolver );
		$resolved = $multiResolver->resolve( $categoryNames );

		// Load property datatypes
		$propertyStore = new WikiPropertyStore();
		$allProperties = $propertyStore->getAllProperties();
		$datatypeMap = [];
		foreach ( $allProperties as $property ) {
			$datatypeMap[$property->getName()] = $property->getDatatype();
		}

		// Build response
		$response = [
			'categories' => $this->formatCategories( $categoryNames, $allCategories ),
			'properties' => $this->formatProperties( $resolved, $datatypeMap ),
			'subobjects' => $this->formatSubobjects( $resolved ),
		];

		// Add result
		$this->getResult()->addValue(
			null,
			$this->getModuleName(),
			$response
		);
	}

	/* =====================================================================
	 * INPUT SANITIZATION
	 * ===================================================================== */

	/**
	 * Strip "Category:" prefix if present (case-insensitive).
	 *
	 * @param string $name Category name with or without prefix
	 * @return string Normalized category name
	 */
	private function stripPrefix( string $name ): string {
		return preg_replace( '/^Category:/i', '', trim( $name ) );
	}

	/**
	 * Validate that all requested categories exist.
	 *
	 * @param string[] $categoryNames Normalized category names
	 * @param array $allCategories Map of category name => CategoryModel
	 */
	private function validateCategories( array $categoryNames, array $allCategories ): void {
		$invalidCategories = [];

		foreach ( $categoryNames as $name ) {
			if ( !isset( $allCategories[$name] ) ) {
				$invalidCategories[] = $name;
			}
		}

		if ( $invalidCategories !== [] ) {
			$this->dieWithError(
				[ 'apierror-semanticschemas-invalidcategories', implode( ', ', $invalidCategories ) ],
				'invalidcategories'
			);
		}
	}

	/* =====================================================================
	 * RESPONSE FORMATTING
	 * ===================================================================== */

	/**
	 * Format categories for API response.
	 *
	 * @param string[] $categoryNames Normalized category names
	 * @param array $allCategories Map of category name => CategoryModel
	 * @return array[] Array of category entries with name and targetNamespace
	 */
	private function formatCategories( array $categoryNames, array $allCategories ): array {
		$formatted = [];

		foreach ( $categoryNames as $name ) {
			$formatted[] = [
				'name' => $name,
				'targetNamespace' => $allCategories[$name]->getTargetNamespace(),
			];
		}

		return $formatted;
	}

	/**
	 * Format properties for API response.
	 *
	 * @param ResolvedPropertySet $resolved Resolution result
	 * @param array $datatypeMap Map of property name => datatype
	 * @return array[] Array of property entries
	 */
	protected function formatProperties( ResolvedPropertySet $resolved, array $datatypeMap ): array {
		$formatted = [];

		// Required properties
		foreach ( $resolved->getRequiredProperties() as $property ) {
			$sources = $resolved->getPropertySources( $property );
			$formatted[] = [
				'name' => $property,
				'title' => "Property:$property",
				'required' => 1,
				'shared' => count( $sources ) > 1 ? 1 : 0,
				'sources' => $sources,
				'datatype' => $datatypeMap[$property] ?? 'Page',
			];
		}

		// Optional properties
		foreach ( $resolved->getOptionalProperties() as $property ) {
			$sources = $resolved->getPropertySources( $property );
			$formatted[] = [
				'name' => $property,
				'title' => "Property:$property",
				'required' => 0,
				'shared' => count( $sources ) > 1 ? 1 : 0,
				'sources' => $sources,
				'datatype' => $datatypeMap[$property] ?? 'Page',
			];
		}

		return $formatted;
	}

	/**
	 * Format subobjects for API response.
	 *
	 * @param ResolvedPropertySet $resolved Resolution result
	 * @return array[] Array of subobject entries
	 */
	protected function formatSubobjects( ResolvedPropertySet $resolved ): array {
		$formatted = [];

		// Required subobjects
		foreach ( $resolved->getRequiredSubobjects() as $subobject ) {
			$sources = $resolved->getSubobjectSources( $subobject );
			$formatted[] = [
				'name' => $subobject,
				'title' => "Subobject:$subobject",
				'required' => 1,
				'shared' => count( $sources ) > 1 ? 1 : 0,
				'sources' => $sources,
			];
		}

		// Optional subobjects
		foreach ( $resolved->getOptionalSubobjects() as $subobject ) {
			$sources = $resolved->getSubobjectSources( $subobject );
			$formatted[] = [
				'name' => $subobject,
				'title' => "Subobject:$subobject",
				'required' => 0,
				'shared' => count( $sources ) > 1 ? 1 : 0,
				'sources' => $sources,
			];
		}

		return $formatted;
	}

	/* =====================================================================
	 * API METADATA
	 * ===================================================================== */

	public function getAllowedParams() {
		return [
			'categories' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_ISMULTI => true,
				self::PARAM_REQUIRED => true,
				self::PARAM_ISMULTI_LIMIT1 => 10,
				self::PARAM_ISMULTI_LIMIT2 => 20,
				self::PARAM_HELP_MSG => 'semanticschemas-api-param-categories',
			],
		];
	}

	protected function getExamplesMessages() {
		return [
			'action=semanticschemas-multicategory&categories=Person'
			=> 'apihelp-semanticschemas-multicategory-example-1',
			'action=semanticschemas-multicategory&categories=Person|Employee'
			=> 'apihelp-semanticschemas-multicategory-example-2',
			'action=semanticschemas-multicategory&categories=Category:Person|Employee'
			=> 'apihelp-semanticschemas-multicategory-example-3',
		];
	}

	public function needsToken() {
		// No CSRF token needed - read-only API
		return false;
	}

	public function isReadMode() {
		return true;
	}
}
