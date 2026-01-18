<?php

namespace MediaWiki\Extension\SemanticSchemas\Schema;

use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\PageHashComputer;
use MediaWiki\Extension\SemanticSchemas\Store\StateManager;
use MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;
use MediaWiki\Extension\SemanticSchemas\Store\WikiSubobjectStore;

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
	private WikiSubobjectStore $subobjectStore;

	private StateManager $stateManager;
	private PageHashComputer $hashComputer;
	private PageCreator $pageCreator;

	public function __construct(
		WikiCategoryStore $categoryStore = null,
		WikiPropertyStore $propertyStore = null,
		WikiSubobjectStore $subobjectStore = null,
		StateManager $stateManager = null,
		PageHashComputer $hashComputer = null,
		PageCreator $pageCreator = null
	) {
		$this->categoryStore = $categoryStore ?? new WikiCategoryStore();
		$this->propertyStore = $propertyStore ?? new WikiPropertyStore();
		$this->subobjectStore = $subobjectStore ?? new WikiSubobjectStore();

		$this->stateManager = $stateManager ?? new StateManager();
		$this->hashComputer = $hashComputer ?? new PageHashComputer();
		$this->pageCreator = $pageCreator ?? new PageCreator();
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
		$subobjects = $this->subobjectStore->getAllSubobjects();

		ksort( $categories );
		ksort( $properties );
		ksort( $subobjects );

		$schema = [
			'categories' => [],
			'properties' => [],
			'subobjects' => [],
		];

		foreach ( $categories as $name => $model ) {
			$schema['categories'][$name] = $model->toArray();
		}

		foreach ( $properties as $name => $model ) {
			$schema['properties'][$name] = $model->toArray();
		}

		foreach ( $subobjects as $name => $model ) {
			$schema['subobjects'][$name] = $model->toArray();
		}

		return $schema;
	}

	/**
	 * Gather statistics about the current ontology.
	 *
	 * @return array{
	 *   categoryCount:int,
	 *   propertyCount:int,
	 *   subobjectCount:int,
	 *   categoriesWithParents:int,
	 *   categoriesWithProperties:int,
	 *   categoriesWithDisplay:int,
	 *   categoriesWithForms:int,
	 *   categoriesWithSubobjects:int
	 * }
	 */
	public function getStatistics(): array {
		$categories = $this->categoryStore->getAllCategories();
		$properties = $this->propertyStore->getAllProperties();
		$subobjects = $this->subobjectStore->getAllSubobjects();

		$stats = [
			'categoryCount' => count( $categories ),
			'propertyCount' => count( $properties ),
			'subobjectCount' => count( $subobjects ),
			'categoriesWithParents' => 0,
			'categoriesWithProperties' => 0,
			'categoriesWithDisplay' => 0,
			'categoriesWithForms' => 0,
			'categoriesWithSubobjects' => 0,
		];

		foreach ( $categories as $cat ) {
			if ( $cat->getParents() ) {
				$stats['categoriesWithParents']++;
			}

			if ( $cat->getAllProperties() ) {
				$stats['categoriesWithProperties']++;
			}

			if ( $cat->getDisplaySections() ) {
				$stats['categoriesWithDisplay']++;
			}

			if ( $cat->getFormConfig() ) {
				$stats['categoriesWithForms']++;
			}

			if ( $cat->getRequiredSubobjects() || $cat->getOptionalSubobjects() ) {
				$stats['categoriesWithSubobjects']++;
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
		$validator = new SchemaValidator();

		$errors = $validator->validateSchema( $schema );
		$warnings = $validator->generateWarnings( $schema );
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
			// Page names are stored as "Category:Name", "Property:Name", "Subobject:Name"
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

				case 'subobject':
					$model = $this->subobjectStore->readSubobject( $name );
					if ( !$model ) {
						$modified[] = $pageName;
						continue 2;
					}
					$current[$pageName] = $this->hashComputer->computeSubobjectModelHash( $model );
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
