<?php

namespace MediaWiki\Extension\StructureSync\Schema;

use MediaWiki\Extension\StructureSync\Store\WikiCategoryStore;
use MediaWiki\Extension\StructureSync\Store\WikiPropertyStore;
use MediaWiki\Extension\StructureSync\Store\StateManager;
use MediaWiki\Extension\StructureSync\Store\PageHashComputer;
use MediaWiki\Extension\StructureSync\Generator\TemplateGenerator;
use MediaWiki\Extension\StructureSync\Generator\FormGenerator;
use MediaWiki\Extension\StructureSync\Generator\DisplayStubGenerator;
use RuntimeException;

/**
 * SchemaImporter
 * --------------
 * Imports a schema array into the wiki.
 *
 * Responsibilities:
 *   - Validate schema structure (SchemaValidator)
 *   - Import properties (before categories)
 *   - Import categories in parent-first order (topological sort with cycle detection)
 *   - Respect options:
 *       * dryRun            (bool) - simulate without writing
 *       * generateArtifacts (bool) - rebuild templates/forms/display after successful import
 *       * overwrite         (bool) - always write pages even if logically unchanged
 *   - Distinguish created / updated / unchanged at the model level
 */
class SchemaImporter {

	/** @var WikiCategoryStore */
	private $categoryStore;

	/** @var WikiPropertyStore */
	private $propertyStore;

	/** @var SchemaValidator */
	private $validator;

	/** @var TemplateGenerator */
	private $templateGenerator;

	/** @var FormGenerator */
	private $formGenerator;

	/** @var DisplayStubGenerator */
	private $displayGenerator;

	/** @var StateManager */
	private $stateManager;

	/** @var PageHashComputer */
	private $hashComputer;

	/**
	 * @param WikiCategoryStore|null    $categoryStore
	 * @param WikiPropertyStore|null    $propertyStore
	 * @param SchemaValidator|null      $validator
	 * @param TemplateGenerator|null    $templateGenerator
	 * @param FormGenerator|null        $formGenerator
	 * @param DisplayStubGenerator|null $displayGenerator
	 * @param StateManager|null         $stateManager
	 * @param PageHashComputer|null     $hashComputer
	 */
	public function __construct(
		WikiCategoryStore $categoryStore = null,
		WikiPropertyStore $propertyStore = null,
		SchemaValidator $validator = null,
		TemplateGenerator $templateGenerator = null,
		FormGenerator $formGenerator = null,
		DisplayStubGenerator $displayGenerator = null,
		StateManager $stateManager = null,
		PageHashComputer $hashComputer = null
	) {
		$this->categoryStore    = $categoryStore    ?? new WikiCategoryStore();
		$this->propertyStore    = $propertyStore    ?? new WikiPropertyStore();
		$this->validator        = $validator        ?? new SchemaValidator();
		$this->templateGenerator = $templateGenerator ?? new TemplateGenerator();
		$this->formGenerator     = $formGenerator     ?? new FormGenerator();
		$this->displayGenerator  = $displayGenerator  ?? new DisplayStubGenerator();
		$this->stateManager      = $stateManager      ?? new StateManager();
		$this->hashComputer      = $hashComputer      ?? new PageHashComputer();
	}

	/**
	 * Import schema into the wiki.
	 *
	 * Options:
	 *   - dryRun (bool): if true, do not write pages, only count what would happen.
	 *   - generateArtifacts (bool): if true (default), regenerate templates/forms/display
	 *                               after a successful import.
	 *   - overwrite (bool): if true, write pages even when logically unchanged.
	 *
	 * @param array $schema
	 * @param array $options
	 * @return array
	 */
	public function importFromArray( array $schema, array $options = [] ): array {
		$dryRun           = (bool)( $options['dryRun'] ?? false );
		$generateArtifacts = (bool)( $options['generateArtifacts'] ?? true );
		$overwrite        = (bool)( $options['overwrite'] ?? false );

		// 1) Validate schema structure before touching the wiki
		$errors = $this->validator->validateSchema( $schema );
		if ( !empty( $errors ) ) {
			return [
				'success'              => false,
				'errors'               => $errors,
				'categoriesCreated'    => 0,
				'categoriesUpdated'    => 0,
				'categoriesUnchanged'  => 0,
				'propertiesCreated'    => 0,
				'propertiesUpdated'    => 0,
				'propertiesUnchanged'  => 0,
				'templatesCreated'     => 0,
				'templatesUpdated'     => 0,
				'formsCreated'         => 0,
				'formsUpdated'         => 0,
				'displayCreated'       => 0,
			];
		}

		$result = [
			'success'              => true,
			'errors'               => [],
			'categoriesCreated'    => 0,
			'categoriesUpdated'    => 0,
			'categoriesUnchanged'  => 0,
			'propertiesCreated'    => 0,
			'propertiesUpdated'    => 0,
			'propertiesUnchanged'  => 0,
			'templatesCreated'     => 0,
			'templatesUpdated'     => 0,
			'formsCreated'         => 0,
			'formsUpdated'         => 0,
			'displayCreated'       => 0,
		];

		// 2) Import properties first
		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			$propertyResult = $this->importProperties(
				$schema['properties'],
				$dryRun,
				$overwrite
			);

			$result['propertiesCreated']   = $propertyResult['created'];
			$result['propertiesUpdated']   = $propertyResult['updated'];
			$result['propertiesUnchanged'] = $propertyResult['unchanged'];
			$result['errors']              = array_merge( $result['errors'], $propertyResult['errors'] );
		}

		// 3) Import categories
		if ( isset( $schema['categories'] ) && is_array( $schema['categories'] ) ) {
			try {
				$categoryResult = $this->importCategories(
					$schema['categories'],
					$dryRun,
					$overwrite
				);
				$result['categoriesCreated']   = $categoryResult['created'];
				$result['categoriesUpdated']   = $categoryResult['updated'];
				$result['categoriesUnchanged'] = $categoryResult['unchanged'];
				$result['errors']              = array_merge( $result['errors'], $categoryResult['errors'] );
			} catch ( RuntimeException $e ) {
				// Dependency cycles, etc.
				$result['success'] = false;
				$result['errors'][] = 'Category import failed: ' . $e->getMessage();
				return $result;
			}
		}

		if ( !empty( $result['errors'] ) ) {
			$result['success'] = false;
		}

		// 4) Update state tracking after successful import (non-dry-run)
		if ( !$dryRun && $result['success'] ) {
			// Mark system as dirty
			$this->stateManager->setDirty( true );

			// Compute and store schema hash
			$schemaHash = $this->hashComputer->computeSchemaHash( $schema );
			$this->stateManager->setSourceSchemaHash( $schemaHash );
		}

		// 5) Optionally generate artifacts (templates/forms/display) using effective categories
		if ( $generateArtifacts && !$dryRun && $result['success'] ) {
			$artifactResult = $this->generateArtifacts();
			$result['templatesCreated'] += $artifactResult['templatesCreated'];
			$result['templatesUpdated'] += $artifactResult['templatesUpdated'];
			$result['formsCreated']     += $artifactResult['formsCreated'];
			$result['formsUpdated']     += $artifactResult['formsUpdated'];
			$result['displayCreated']   += $artifactResult['displayCreated'];
			$result['errors']           = array_merge( $result['errors'], $artifactResult['errors'] );

			if ( !empty( $artifactResult['errors'] ) ) {
				$result['success'] = false;
			}
		}

		return $result;
	}

	/**
	 * Import properties from schema.
	 *
	 * Distinguishes:
	 *   - created: property did not exist
	 *   - updated: existed and model differs or overwrite=true
	 *   - unchanged: existed and model identical, overwrite=false
	 *
	 * @param array $properties
	 * @param bool  $dryRun
	 * @param bool  $overwrite
	 * @return array
	 */
	private function importProperties( array $properties, bool $dryRun, bool $overwrite ): array {
		$result = [
			'created'   => 0,
			'updated'   => 0,
			'unchanged' => 0,
			'errors'    => [],
		];

		foreach ( $properties as $propertyName => $propertyData ) {
			try {
				$exists = $this->propertyStore->propertyExists( $propertyName );
				$newModel = new PropertyModel( $propertyName, $propertyData );

				if ( $exists ) {
					$existing = $this->propertyStore->readProperty( $propertyName );

					// If we can read the existing model, compare semantic equality via toArray()
					if ( $existing instanceof PropertyModel ) {
						$isSame = ( $existing->toArray() === $newModel->toArray() );

						if ( $isSame && !$overwrite ) {
							$result['unchanged']++;
							continue;
						}
					}
				}

				if ( $dryRun ) {
					if ( $exists ) {
						$result['updated']++;
					} else {
						$result['created']++;
					}
					continue;
				}

				$success = $this->propertyStore->writeProperty( $newModel );

				if ( !$success ) {
					$result['errors'][] = "Failed to write property: $propertyName";
					continue;
				}

				if ( $exists ) {
					$result['updated']++;
				} else {
					$result['created']++;
				}
			} catch ( \Exception $e ) {
				$result['errors'][] = "Error importing property '$propertyName': " . $e->getMessage();
			}
		}

		return $result;
	}

	/**
	 * Import categories from schema.
	 *
	 * Categories are imported in parent-first order via topological sort.
	 * Circular dependencies result in a RuntimeException.
	 *
	 * @param array $categories
	 * @param bool  $dryRun
	 * @param bool  $overwrite
	 * @return array
	 */
	private function importCategories( array $categories, bool $dryRun, bool $overwrite ): array {
		$result = [
			'created'   => 0,
			'updated'   => 0,
			'unchanged' => 0,
			'errors'    => [],
		];

		// Sort categories by dependency (parents before children)
		$sortedNames = $this->sortCategoriesByDependency( $categories );

		foreach ( $sortedNames as $categoryName ) {
			$categoryData = $categories[$categoryName] ?? null;
			if ( $categoryData === null ) {
				continue;
			}

			try {
				$exists  = $this->categoryStore->categoryExists( $categoryName );
				$newModel = new CategoryModel( $categoryName, $categoryData );

				if ( $exists ) {
					$existing = $this->categoryStore->readCategory( $categoryName );

					if ( $existing instanceof CategoryModel ) {
						$isSame = ( $existing->toArray() === $newModel->toArray() );

						if ( $isSame && !$overwrite ) {
							$result['unchanged']++;
							continue;
						}
					}
				}

				if ( $dryRun ) {
					if ( $exists ) {
						$result['updated']++;
					} else {
						$result['created']++;
					}
					continue;
				}

				$success = $this->categoryStore->writeCategory( $newModel );

				if ( !$success ) {
					$result['errors'][] = "Failed to write category: $categoryName";
					continue;
				}

				if ( $exists ) {
					$result['updated']++;
				} else {
					$result['created']++;
				}
			} catch ( \Exception $e ) {
				$result['errors'][] = "Error importing category '$categoryName': " . $e->getMessage();
			}
		}

		return $result;
	}

	/**
	 * Sort categories so parents are processed before children.
	 *
	 * Uses a DFS-based topological sort with explicit cycle detection.
	 *
	 * @param array $categories  categoryName => categoryData (must contain 'parents' optionally)
	 * @return string[]          ordered list of category names
	 * @throws RuntimeException  on circular dependency
	 */
	private function sortCategoriesByDependency( array $categories ): array {
		$sorted   = [];
		$visited  = [];
		$visiting = [];

		foreach ( array_keys( $categories ) as $categoryName ) {
			$this->visitCategory( $categoryName, $categories, $visited, $visiting, $sorted );
		}

		return $sorted;
	}

	/**
	 * DFS helper for topological sort.
	 *
	 * @param string   $categoryName
	 * @param array    $categories
	 * @param string[] $visited
	 * @param string[] $visiting
	 * @param string[] $sorted
	 *
	 * @throws RuntimeException on circular dependency
	 */
	private function visitCategory(
		string $categoryName,
		array $categories,
		array &$visited,
		array &$visiting,
		array &$sorted
	): void {
		if ( isset( $visited[$categoryName] ) ) {
			return;
		}

		if ( isset( $visiting[$categoryName] ) ) {
			throw new RuntimeException(
				"Circular category dependency detected involving '$categoryName'"
			);
		}

		$visiting[$categoryName] = true;

		$parents = $categories[$categoryName]['parents'] ?? [];
		foreach ( $parents as $parentName ) {
			if ( isset( $categories[$parentName] ) ) {
				$this->visitCategory( $parentName, $categories, $visited, $visiting, $sorted );
			}
			// If parent not in this import set, we just ignore it here;
			// it may exist already in the wiki, which is fine.
		}

		unset( $visiting[$categoryName] );
		$visited[$categoryName] = true;
		$sorted[] = $categoryName;
	}

	/**
	 * Preview import changes without modifying wiki.
	 *
	 * Uses simple "exists vs not" logic; for a deeper preview of
	 * changed content, rely on SchemaComparer + SchemaExporter.
	 *
	 * @param array $schema
	 * @return array
	 */
	public function previewImport( array $schema ): array {
		$preview = [
			'categories' => [
				'new'      => [],
				'existing' => [],
			],
			'properties' => [
				'new'      => [],
				'existing' => [],
			],
		];

		// Categories
		if ( isset( $schema['categories'] ) && is_array( $schema['categories'] ) ) {
			foreach ( array_keys( $schema['categories'] ) as $name ) {
				if ( $this->categoryStore->categoryExists( $name ) ) {
					$preview['categories']['existing'][] = $name;
				} else {
					$preview['categories']['new'][] = $name;
				}
			}
		}

		// Properties
		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			foreach ( array_keys( $schema['properties'] ) as $name ) {
				if ( $this->propertyStore->propertyExists( $name ) ) {
					$preview['properties']['existing'][] = $name;
				} else {
					$preview['properties']['new'][] = $name;
				}
			}
		}

		return $preview;
	}

	/**
	 * Generate templates/forms/display for all categories using effective models.
	 *
	 * Returns artifact counts + errors.
	 *
	 * @return array
	 */
	private function generateArtifacts(): array {
		$result = [
			'templatesCreated' => 0,
			'templatesUpdated' => 0, // we can't reliably distinguish created vs updated for templates now
			'formsCreated'     => 0,
			'formsUpdated'     => 0,
			'displayCreated'   => 0,
			'errors'           => [],
		];

		$categories = $this->categoryStore->getAllCategories();
		if ( empty( $categories ) ) {
			return $result;
		}

		$resolver = new InheritanceResolver( $categories );

		foreach ( $categories as $name => $category ) {
			try {
				$effective      = $resolver->getEffectiveCategory( $name );
				$ancestorChain  = $resolver->getAncestors( $name );

				// Templates
				$templateRes = $this->templateGenerator->generateAllTemplates( $effective );
				if ( !$templateRes['success'] ) {
					foreach ( $templateRes['errors'] as $err ) {
						$result['errors'][] = "Template generation for '$name': $err";
					}
				}
				// We don't currently know created vs updated history, so we lump under 'templatesUpdated'
				$result['templatesUpdated']++;

				// Forms
				$formExisted = $this->formGenerator->formExists( $name );
				$formOk = $this->formGenerator->generateAndSaveForm( $effective, $ancestorChain );
				if ( !$formOk ) {
					$result['errors'][] = "Form generation failed for '$name'";
				} else {
					if ( $formExisted ) {
						$result['formsUpdated']++;
					} else {
						$result['formsCreated']++;
					}
				}

				// Display
				$displayRes = $this->displayGenerator->generateDisplayStubIfMissing( $effective );
				if ( !empty( $displayRes['error'] ) ) {
					$result['errors'][] = "Display generation for '$name': " . $displayRes['error'];
				} elseif ( !empty( $displayRes['created'] ) ) {
					if ( $displayRes['created'] ) {
						$result['displayCreated']++;
					}
				}
			} catch ( \Exception $e ) {
				$result['errors'][] = "Artifact generation failed for '$name': " . $e->getMessage();
			}
		}

		return $result;
	}
}
