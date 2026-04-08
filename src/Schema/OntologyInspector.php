<?php

namespace MediaWiki\Extension\SemanticSchemas\Schema;

use MediaWiki\Extension\SemanticSchemas\Store\PageHashComputer;
use MediaWiki\Extension\SemanticSchemas\Store\StateManager;
use MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;

/**
 * OntologyInspector
 * -----------------
 * Minimal runtime helper used by:
 *   - Overview tab (statistics)
 *   - Validate tab (schema validation + hash-based dirty detection)
 *
 * All external schema import/export responsibilities are handled by
 * legacy code and will move to a separate extension.
 */
class OntologyInspector {

	private WikiCategoryStore $categoryStore;
	private WikiPropertyStore $propertyStore;

	private StateManager $stateManager;
	private PageHashComputer $hashComputer;
	private SchemaValidator $validator;

	public function __construct(
		WikiCategoryStore $categoryStore,
		WikiPropertyStore $propertyStore,
		StateManager $stateManager,
		PageHashComputer $hashComputer,
		SchemaValidator $validator
	) {
		$this->categoryStore = $categoryStore;
		$this->propertyStore = $propertyStore;
		$this->stateManager = $stateManager;
		$this->hashComputer = $hashComputer;
		$this->validator = $validator;
	}

	/**
	 * Build a plain schema array from the current wiki state.
	 * Used internally by validateWikiState().
	 *
	 * @return array
	 */
	private function buildSchemaArray(): array {
		$categories = $this->categoryStore->getAllCategories();
		$properties = $this->propertyStore->getAllProperties();

		ksort( $categories );
		ksort( $properties );

		$schema = [
			'schemaVersion' => SchemaLoader::SCHEMA_VERSION,
			'categories' => [],
			'properties' => [],
		];

		foreach ( $categories as $name => $model ) {
			$schema['categories'][$name] = $model->toArray();
		}

		foreach ( $properties as $name => $model ) {
			$schema['properties'][$name] = $model->toArray();
		}

		return $schema;
	}

	/**
	 * Gather statistics about the current ontology.
	 *
	 * @return array{
	 *   categoryCount:int,
	 *   propertyCount:int,
	 *   categoriesWithParents:int,
	 *   categoriesWithProperties:int,
	 *   categoriesWithDisplay:int,
	 *   categoriesWithForms:int
	 * }
	 */
	public function getStatistics(): array {
		$categories = $this->categoryStore->getAllCategories();
		$properties = $this->propertyStore->getAllProperties();

		$stats = [
			'categoryCount' => count( $categories ),
			'propertyCount' => count( $properties ),
			'categoriesWithParents' => 0,
			'categoriesWithProperties' => 0,
			'categoriesWithDisplay' => 0,
			'categoriesWithForms' => 0,
		];

		foreach ( $categories as $cat ) {
			if ( $cat->getParents() ) {
				$stats['categoriesWithParents']++;
			}

			if ( $cat->getAllProperties() ) {
				$stats['categoriesWithProperties']++;
			}

			if ( $cat->getDisplayFormat() !== null ) {
				$stats['categoriesWithDisplay']++;
			}

			if ( $cat->getFormConfig() ) {
				$stats['categoriesWithForms']++;
			}
		}

		return $stats;
	}

	/**
	 * Validate wiki ontology via SchemaValidator + hash tracking.
	 *
	 * @return array{errors:array,warnings:array,modifiedPages:array}
	 */
	public function validateWikiState(): array {
		$schema = $this->buildSchemaArray();

		$validation = $this->validator->validateSchemaWithSeverity( $schema );
		$errors = $validation['errors'];
		$warnings = $validation['warnings'];
		$modified = [];

		$stored = $this->stateManager->getPageHashes();
		if ( !$stored ) {
			return [
				'errors' => $errors,
				'warnings' => $warnings,
				'modifiedPages' => [],
			];
		}

		$current = [];
		foreach ( $stored as $pageName => $hashInfo ) {
			// Page names are stored as "Category:Name", "Property:Name"
			if ( !preg_match( '/^([^:]+):(.+)$/', $pageName, $m ) ) {
				$modified[] = $pageName;
				continue;
			}

			$prefix = strtolower( $m[1] );
			$name = $m[2];

			switch ( $prefix ) {
				case 'category':
					$model = $this->categoryStore->readCategory( $name );
					if ( !$model ) {
						$modified[] = $pageName;
						continue 2;
					}
					$current[$pageName] = $this->hashComputer->computeCategoryModelHash( $model );
					break;

				case 'property':
					$model = $this->propertyStore->readProperty( $name );
					if ( !$model ) {
						$modified[] = $pageName;
						continue 2;
					}
					$current[$pageName] = $this->hashComputer->computePropertyModelHash( $model );
					break;

				default:
					$modified[] = $pageName;
					continue 2;
			}
		}

		$modified = array_merge(
			$modified,
			$this->stateManager->comparePageHashes( $current )
		);

		if ( $modified ) {
			$this->stateManager->updateCurrentHashes( $current );
			$this->stateManager->setDirty( true );
			$warnings[] = 'Pages modified outside SemanticSchemas: ' . implode( ', ', $modified );
		}

		return [
			'errors' => $errors,
			'warnings' => $warnings,
			'modifiedPages' => $modified,
		];
	}
}
