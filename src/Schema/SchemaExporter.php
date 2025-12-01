<?php

namespace MediaWiki\Extension\StructureSync\Schema;

use MediaWiki\Extension\StructureSync\Store\WikiCategoryStore;
use MediaWiki\Extension\StructureSync\Store\WikiPropertyStore;
use MediaWiki\Extension\StructureSync\Store\WikiSubobjectStore;
use MediaWiki\Extension\StructureSync\Store\StateManager;
use MediaWiki\Extension\StructureSync\Store\PageHashComputer;
use MediaWiki\Extension\StructureSync\Store\PageCreator;

/**
 * SchemaExporter
 * --------------
 * Exports the current wiki ontology into a canonical StructureSync schema array.
 *
 * Default behavior:
 *   - Raw schema, no inheritance expansion.
 *   - Deterministic key ordering.
 *   - Strict and predictable data structure.
 *
 * Optional:
 *   - Inheritance expansion (debug/generation only).
 */
class SchemaExporter {

    private const SCHEMA_VERSION = '1.0';

    private WikiCategoryStore $categoryStore;
    private WikiPropertyStore $propertyStore;
    private WikiSubobjectStore $subobjectStore;
    private ?InheritanceResolver $inheritanceResolver;

    private StateManager $stateManager;
    private PageHashComputer $hashComputer;
    private PageCreator $pageCreator;

    public function __construct(
        WikiCategoryStore $categoryStore = null,
        WikiPropertyStore $propertyStore = null,
        WikiSubobjectStore $subobjectStore = null,
        InheritanceResolver $inheritanceResolver = null,
        StateManager $stateManager = null,
        PageHashComputer $hashComputer = null,
        PageCreator $pageCreator = null
    ) {
        $this->categoryStore = $categoryStore ?? new WikiCategoryStore();
        $this->propertyStore = $propertyStore ?? new WikiPropertyStore();
        $this->subobjectStore = $subobjectStore ?? new WikiSubobjectStore();
        $this->inheritanceResolver = $inheritanceResolver;

        $this->stateManager = $stateManager ?? new StateManager();
        $this->hashComputer = $hashComputer ?? new PageHashComputer();
        $this->pageCreator = $pageCreator ?? new PageCreator();
    }

	/**
	 * Export the entire wiki schema.
	 *
	 * Options:
	 *   - includeInherited: bool
	 *   - continueOnError: bool
	 *   - errorCallback: callable   (parameters: type, name, exception)
	 */
	public function exportToArray(
		bool $includeInherited = false,
		array $options = []
	): array {


        $continueOnError = $options['continueOnError'] ?? true;
        $errorCallback = $options['errorCallback'] ?? null;

        $categories = $this->categoryStore->getAllCategories();
        $properties = $this->propertyStore->getAllProperties();
        $subobjects = $this->subobjectStore->getAllSubobjects();

        ksort($categories);
        ksort($properties);
        ksort($subobjects);

        $schema = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'categories'    => [],
            'properties'    => [],
            'subobjects'    => [],
        ];

        $errors = [];

        /* -----------------------------------------------------------------
         * Categories
         * ----------------------------------------------------------------- */
        $resolver = null;
        if ( $includeInherited && $categories ) {
            $resolver = $this->inheritanceResolver ?? new InheritanceResolver($categories);
        }

        foreach ( $categories as $name => $model ) {
            try {
                $schema['categories'][$name] =
                    $resolver ? $resolver->getEffectiveCategory($name)->toArray()
                              : $model->toArray();
            } catch ( \Throwable $e ) {
                $errors[] = "Category '$name': " . $e->getMessage();

                if ( is_callable($errorCallback) ) {
                    $errorCallback('category', $name, $e);
                }
                if ( !$continueOnError ) {
                    throw new \RuntimeException("Export failed: $name", 0, $e);
                }

                // Fallback to minimal/raw
                $schema['categories'][$name] = $model->toArray();
            }
        }

        /* -----------------------------------------------------------------
         * Properties
         * ----------------------------------------------------------------- */
        foreach ( $properties as $name => $model ) {
            try {
                $schema['properties'][$name] = $model->toArray();
            } catch ( \Throwable $e ) {
                $errors[] = "Property '$name': " . $e->getMessage();
                if ( is_callable($errorCallback) ) {
                    $errorCallback('property', $name, $e);
                }
                if ( !$continueOnError ) {
                    throw new \RuntimeException("Export failed: $name", 0, $e);
                }
            }
        }

        /* -----------------------------------------------------------------
         * Subobjects
         * ----------------------------------------------------------------- */
        foreach ( $subobjects as $name => $model ) {
            try {
                $schema['subobjects'][$name] = $model->toArray();
            } catch ( \Throwable $e ) {
                $errors[] = "Subobject '$name': " . $e->getMessage();
                if ( !$continueOnError ) {
                    throw new \RuntimeException("Export failed: $name", 0, $e);
                }
            }
        }

        if ( $errors ) {
            $schema['exportErrors'] = $errors;
        }

        return $schema;
    }

    /**
     * Export only a set of categories + their properties + their subobjects.
     */
    public function exportCategories(array $categoryNames, array $options = []): array {

        $continueOnError = $options['continueOnError'] ?? true;
        $errorCallback = $options['errorCallback'] ?? null;

        $schema = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'categories'    => [],
            'properties'    => [],
            'subobjects'    => [],
        ];

        $errors = [];
        $usedProperties = [];
        $usedSubobjects = [];

        /* -----------------------------------------------------------------
         * Categories
         * ----------------------------------------------------------------- */
        foreach ($categoryNames as $name) {
            try {
                $cat = $this->categoryStore->readCategory($name);
                if (!$cat) {
                    $errors[] = "Category '$name' not found";
                    continue;
                }

                $schema['categories'][$name] = $cat->toArray();
                $usedProperties = array_merge($usedProperties, $cat->getAllProperties());
                $usedSubobjects = array_merge(
                    $usedSubobjects,
                    $cat->getRequiredSubgroups(),
                    $cat->getOptionalSubgroups()
                );

            } catch (\Throwable $e) {
                $errors[] = "Category '$name': " . $e->getMessage();
                if ( is_callable($errorCallback) ) {
                    $errorCallback('category', $name, $e);
                }
                if (!$continueOnError) {
                    throw new \RuntimeException("Export failed: $name", 0, $e);
                }
            }
        }

        $usedProperties = array_values(array_unique($usedProperties));
        sort($usedProperties);

        /* -----------------------------------------------------------------
         * Properties
         * ----------------------------------------------------------------- */
        foreach ($usedProperties as $propName) {
            try {
                $prop = $this->propertyStore->readProperty($propName);
                if ($prop) {
                    $schema['properties'][$propName] = $prop->toArray();
                } else {
                    $errors[] = "Property '$propName' not found";
                }
            } catch (\Throwable $e) {
                $errors[] = "Property '$propName': " . $e->getMessage();
                if (!$continueOnError) {
                    throw new \RuntimeException("Export failed: $propName", 0, $e);
                }
            }
        }

        $usedSubobjects = array_values(array_unique(array_filter($usedSubobjects)));
        sort($usedSubobjects);

        /* -----------------------------------------------------------------
         * Subobjects
         * ----------------------------------------------------------------- */
        foreach ($usedSubobjects as $subName) {
            try {
                $sub = $this->subobjectStore->readSubobject($subName);
                if ($sub) {
                    $schema['subobjects'][$subName] = $sub->toArray();
                } else {
                    $errors[] = "Subobject '$subName' not found";
                }
            } catch (\Throwable $e) {
                $errors[] = "Subobject '$subName': " . $e->getMessage();
                if (!$continueOnError) {
                    throw new \RuntimeException("Export failed: $subName", 0, $e);
                }
            }
        }

        if ($errors) {
            $schema['exportErrors'] = $errors;
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
	 *   categoriesWithSubgroups:int
	 * }
	 */
	public function getStatistics(): array {
		$categories = $this->categoryStore->getAllCategories();
		$properties = $this->propertyStore->getAllProperties();
		$subobjects = $this->subobjectStore->getAllSubobjects();

		$stats = [
			'categoryCount'            => count($categories),
			'propertyCount'            => count($properties),
			'subobjectCount'           => count($subobjects),
			'categoriesWithParents'    => 0,
			'categoriesWithProperties' => 0,
			'categoriesWithDisplay'    => 0,
			'categoriesWithForms'      => 0,
			'categoriesWithSubgroups'  => 0,
		];

		foreach ($categories as $cat) {

			// Parents (new inheritance structure)
			if ($cat->getParents()) {
				$stats['categoriesWithParents']++;
			}

			// Properties
			if ($cat->getAllProperties()) {
				$stats['categoriesWithProperties']++;
			}

			// Display config (your new 2025 display spec structure)
			if ($cat->getDisplaySections()) {
				$stats['categoriesWithDisplay']++;
			}

			// Form config (new CategoryModel->getFormConfig or empty array)
			if ($cat->getFormConfig()) {
				$stats['categoriesWithForms']++;
			}

			// Subgroups (new Subobject architecture)
			if ($cat->getRequiredSubgroups() || $cat->getOptionalSubgroups()) {
				$stats['categoriesWithSubgroups']++;
			}
		}

		return $stats;
	}

    /**
     * Validate wiki ontology via SchemaValidator + hash tracking.
     */
    public function validateWikiState(): array {

        $schema = $this->exportToArray(false);
        $validator = new SchemaValidator();

        $errors = $validator->validateSchema($schema);
        $warnings = $validator->generateWarnings($schema);
        $modified = [];

        /* -----------------------------------------------------------------
         * Detect pages modified outside StructureSync
         * ----------------------------------------------------------------- */
        $stored = $this->stateManager->getPageHashes();
        if (!$stored) {
            return [
                'errors'        => $errors,
                'warnings'      => $warnings,
                'modifiedPages' => [],
            ];
        }

        $current = [];
        foreach ($stored as $pageName => $hashInfo) {

            $title = $this->pageCreator->titleFromPageName($pageName);
            if (!$title || !$title->exists()) {
                $modified[] = $pageName;
                continue;
            }

            $content = $this->pageCreator->getPageContent($title);
            if ($content === null) {
                continue;
            }

            $current[$pageName] = $this->hashComputer->computeHashForPageModel(
                $pageName,
                $content
            );
        }

        $modified = array_merge(
            $modified,
            $this->stateManager->comparePageHashes($current)
        );

        if ($modified) {
            $this->stateManager->updateCurrentHashes($current);
            $this->stateManager->setDirty(true);
            $warnings[] = 'Pages modified outside StructureSync: ' . implode(', ', $modified);
        }

        return [
            'errors'        => $errors,
            'warnings'      => $warnings,
            'modifiedPages' => $modified,
        ];
    }
}
