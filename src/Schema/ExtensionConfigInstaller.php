<?php

namespace MediaWiki\Extension\SemanticSchemas\Schema;

use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;
use MediaWiki\Extension\SemanticSchemas\Store\WikiSubobjectStore;

/**
 * ExtensionConfigInstaller
 * ------------------------
 * Applies the bundled extension configuration schema
 * (resources/extension-config.json) to the wiki by creating or
 * updating Category:, Property:, Template:, and Subobject: pages.
 *
 * This is a minimal, internal helper that reuses the canonical
 * schema models + Wiki*Store classes, and writes via PageCreator.
 *
 * ## Layer-by-Layer Installation
 *
 * The installer provides methods for layer-by-layer installation to work around
 * SMW's asynchronous property type registration. When called via the API
 * (ApiSemanticSchemasInstall), installation happens in 5 layers:
 *
 *   - Layer 0: applyTemplatesOnly() - Creates property display templates (no SMW dependencies)
 *   - Layer 1: applyPropertiesTypeOnly() - Creates properties with just [[Has type::...]]
 *   - Layer 2: applyPropertiesFull() - Updates properties with all annotations
 *   - Layer 3: applySubobjectsOnly() - Creates subobject definitions
 *   - Layer 4: applyCategoriesOnly() - Creates categories with semantic annotations
 *
 * The UI waits for SMW's job queue to complete between layers, ensuring property
 * types are registered before category annotations reference them.
 *
 * @see ApiSemanticSchemasInstall for detailed explanation of why this is necessary
 */
class ExtensionConfigInstaller {

	private SchemaLoader $loader;
	private SchemaValidator $validator;
	private PageCreator $pageCreator;

	private WikiCategoryStore $categoryStore;
	private WikiPropertyStore $propertyStore;
	private WikiSubobjectStore $subobjectStore;

	public function __construct(
		?SchemaLoader $loader = null,
		?SchemaValidator $validator = null,
		?WikiCategoryStore $categoryStore = null,
		?WikiPropertyStore $propertyStore = null,
		?WikiSubobjectStore $subobjectStore = null,
		?PageCreator $pageCreator = null
	) {
		$this->loader = $loader ?? new SchemaLoader();
		$this->validator = $validator ?? new SchemaValidator();
		$this->pageCreator = $pageCreator ?? new PageCreator();

		$this->categoryStore = $categoryStore ?? new WikiCategoryStore();
		$this->propertyStore = $propertyStore ?? new WikiPropertyStore();
		$this->subobjectStore = $subobjectStore ?? new WikiSubobjectStore();
	}

	/**
	 * Check if the base extension configuration has been fully installed.
	 *
	 * Checks for the existence of key items from each layer to ensure
	 * the full installation completed successfully. This prevents the
	 * install button from disappearing if installation was interrupted.
	 *
	 * @return bool True if the base configuration appears to be fully installed
	 */
	public function isInstalled(): bool {
		$configPath = __DIR__ . '/../../resources/extension-config.json';

		if ( !file_exists( $configPath ) ) {
			return false;
		}

		// Check all layers - templates, properties, subobjects, and categories
		// If any layer is incomplete, we consider installation incomplete
		return $this->areTemplatesInstalled( $configPath )
			&& $this->arePropertiesInstalled( $configPath )
			&& $this->areSubobjectsInstalled( $configPath )
			&& $this->areCategoriesInstalled( $configPath );
	}

	/**
	 * Load and apply an extension config schema from a JSON/YAML file.
	 *
	 * @param string $filePath
	 * @return array{
	 *   errors:array,
	 *   warnings:array,
	 *   created:array{properties:string[],categories:string[],subobjects:string[]},
	 *   updated:array{properties:string[],categories:string[],subobjects:string[]},
	 *   failed:array{properties:string[],categories:string[],subobjects:string[]}
	 * }
	 */
	public function applyFromFile( string $filePath ): array {
		$schema = $this->loader->loadFromFile( $filePath );
		return $this->applySchema( $schema );
	}

	/**
	 * Preview what installation would do without actually writing.
	 *
	 * @param string $filePath Path to schema file
	 * @return array{
	 *   errors:array,
	 *   warnings:array,
	 *   would_create:array{properties:string[],categories:string[],subobjects:string[]},
	 *   would_update:array{properties:string[],categories:string[],subobjects:string[]}
	 * }
	 */
	public function previewInstallation( string $filePath ): array {
		$schema = $this->loader->loadFromFile( $filePath );
		$validation = $this->validator->validateSchemaWithSeverity( $schema );

		$result = [
			'errors' => $validation['errors'],
			'warnings' => $validation['warnings'],
			'would_create' => [
				'templates' => [],
				'properties' => [],
				'categories' => [],
				'subobjects' => [],
			],
			'would_update' => [
				'templates' => [],
				'properties' => [],
				'categories' => [],
				'subobjects' => [],
			],
		];

		if ( $validation['errors'] ) {
			return $result;
		}

		$templates = $schema['templates'] ?? [];
		$properties = $schema['properties'] ?? [];
		$categories = $schema['categories'] ?? [];
		$subobjects = $schema['subobjects'] ?? [];

		// Check templates
		foreach ( array_keys( $templates ) as $name ) {
			$title = $this->pageCreator->makeTitle( $name, NS_TEMPLATE );
			if ( $title && $this->pageCreator->pageExists( $title ) ) {
				$result['would_update']['templates'][] = $name;
			} else {
				$result['would_create']['templates'][] = $name;
			}
		}

		// Check properties
		foreach ( array_keys( $properties ) as $name ) {
			$title = $this->pageCreator->makeTitle( $name, SMW_NS_PROPERTY );
			if ( $title && $this->pageCreator->pageExists( $title ) ) {
				$result['would_update']['properties'][] = $name;
			} else {
				$result['would_create']['properties'][] = $name;
			}
		}

		// Check subobjects
		foreach ( array_keys( $subobjects ) as $name ) {
			$title = $this->pageCreator->makeTitle( $name, NS_SUBOBJECT );
			if ( $title && $this->pageCreator->pageExists( $title ) ) {
				$result['would_update']['subobjects'][] = $name;
			} else {
				$result['would_create']['subobjects'][] = $name;
			}
		}

		// Check categories (also need to check templates and forms)
		foreach ( array_keys( $categories ) as $name ) {
			$categoryTitle = $this->pageCreator->makeTitle( $name, NS_CATEGORY );
			if ( $categoryTitle && $this->pageCreator->pageExists( $categoryTitle ) ) {
				$result['would_update']['categories'][] = $name;
			} else {
				$result['would_create']['categories'][] = $name;
			}
		}

		return $result;
	}

	/**
	 * Apply only the properties and subobjects from a schema (Step 1 of 2).
	 *
	 * This should be called first. After SMW has processed the property types
	 * (via job queue), call applyCategoriesOnly() to complete installation.
	 *
	 * @param array $schema
	 * @return array
	 */
	public function applyPropertiesOnly( array $schema ): array {
		$validation = $this->validator->validateSchemaWithSeverity( $schema );

		$result = [
			'errors' => $validation['errors'],
			'warnings' => $validation['warnings'],
			'created' => [ 'properties' => [], 'subobjects' => [] ],
			'updated' => [ 'properties' => [], 'subobjects' => [] ],
			'failed' => [ 'properties' => [], 'subobjects' => [] ],
		];

		if ( $validation['errors'] ) {
			return $result;
		}

		$properties = $schema['properties'] ?? [];
		$subobjects = $schema['subobjects'] ?? [];

		// Write properties
		foreach ( $properties as $name => $data ) {
			$title = $this->pageCreator->makeTitle( $name, SMW_NS_PROPERTY );
			$existed = $title && $this->pageCreator->pageExists( $title );

			$model = new PropertyModel( $name, $data ?? [] );
			$ok = $this->propertyStore->writeProperty( $model );

			if ( $ok ) {
				$result[$existed ? 'updated' : 'created']['properties'][] = $name;
			} else {
				$result['failed']['properties'][] = $name;
			}
		}

		// Write subobjects
		foreach ( $subobjects as $name => $data ) {
			$title = $this->pageCreator->makeTitle( $name, NS_SUBOBJECT );
			$existed = $title && $this->pageCreator->pageExists( $title );

			$model = new SubobjectModel( $name, $data ?? [] );
			$ok = $this->subobjectStore->writeSubobject( $model );

			if ( $ok ) {
				$result[$existed ? 'updated' : 'created']['subobjects'][] = $name;
			} else {
				$result['failed']['subobjects'][] = $name;
			}
		}

		return $result;
	}

	/**
	 * Apply only the categories from a schema (Step 2 of 2).
	 *
	 * This should only be called after applyPropertiesOnly() and after
	 * SMW has finished processing property types (job queue is empty).
	 *
	 * @param array $schema
	 * @return array
	 */
	public function applyCategoriesOnly( array $schema ): array {
		$validation = $this->validator->validateSchemaWithSeverity( $schema );

		$result = [
			'errors' => $validation['errors'],
			'warnings' => $validation['warnings'],
			'created' => [ 'categories' => [] ],
			'updated' => [ 'categories' => [] ],
			'failed' => [ 'categories' => [] ],
		];

		if ( $validation['errors'] ) {
			return $result;
		}

		$categories = $schema['categories'] ?? [];

		foreach ( $categories as $name => $data ) {
			$categoryTitle = $this->pageCreator->makeTitle( $name, NS_CATEGORY );
			$existed = $categoryTitle && $this->pageCreator->pageExists( $categoryTitle );

			$model = new CategoryModel( $name, $data ?? [] );
			$ok = $this->categoryStore->writeCategory( $model );

			if ( $ok ) {
				$result[$existed ? 'updated' : 'created']['categories'][] = $name;
			} else {
				$result['failed']['categories'][] = $name;
			}
		}

		return $result;
	}

	/**
	 * Check if properties from the schema are installed.
	 *
	 * @param string $filePath
	 * @return bool
	 */
	public function arePropertiesInstalled( string $filePath ): bool {
		$schema = $this->loader->loadFromFile( $filePath );
		$properties = $schema['properties'] ?? [];

		if ( empty( $properties ) ) {
			return true;
		}

		// Check if at least the first few key properties exist
		foreach ( array_keys( $properties ) as $name ) {
			$title = $this->pageCreator->makeTitle( $name, SMW_NS_PROPERTY );
			if ( !$title || !$this->pageCreator->pageExists( $title ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if categories from the schema are installed.
	 *
	 * @param string $filePath
	 * @return bool
	 */
	public function areCategoriesInstalled( string $filePath ): bool {
		$schema = $this->loader->loadFromFile( $filePath );
		$categories = $schema['categories'] ?? [];

		if ( empty( $categories ) ) {
			return true;
		}

		foreach ( array_keys( $categories ) as $name ) {
			$title = $this->pageCreator->makeTitle( $name, NS_CATEGORY );
			if ( !$title || !$this->pageCreator->pageExists( $title ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if templates from the schema are installed.
	 *
	 * @param string $filePath
	 * @return bool
	 */
	public function areTemplatesInstalled( string $filePath ): bool {
		$schema = $this->loader->loadFromFile( $filePath );
		$templates = $schema['templates'] ?? [];

		if ( empty( $templates ) ) {
			return true;
		}

		foreach ( array_keys( $templates ) as $name ) {
			$title = $this->pageCreator->makeTitle( $name, NS_TEMPLATE );
			if ( !$title || !$this->pageCreator->pageExists( $title ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if subobjects from the schema are installed.
	 *
	 * @param string $filePath
	 * @return bool
	 */
	public function areSubobjectsInstalled( string $filePath ): bool {
		$schema = $this->loader->loadFromFile( $filePath );
		$subobjects = $schema['subobjects'] ?? [];

		if ( empty( $subobjects ) ) {
			return true;
		}

		foreach ( array_keys( $subobjects ) as $name ) {
			$title = $this->pageCreator->makeTitle( $name, NS_SUBOBJECT );
			if ( !$title || !$this->pageCreator->pageExists( $title ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get the count of pending jobs (all types).
	 *
	 * @return int
	 */
	public function getPendingJobCount(): int {
		$jobQueueGroup = \MediaWiki\MediaWikiServices::getInstance()->getJobQueueGroup();

		try {
			$queuesWithJobs = $jobQueueGroup->getQueuesWithJobs();
			$count = 0;
			foreach ( $queuesWithJobs as $type ) {
				$queue = $jobQueueGroup->get( $type );
				$count += $queue->getSize();
			}
			return $count;
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/**
	 * Apply templates only (Layer 0).
	 * This creates property display template pages with no SMW dependencies.
	 *
	 * @param array $schema
	 * @return array
	 */
	public function applyTemplatesOnly( array $schema ): array {
		$result = [
			'errors' => [],
			'warnings' => [],
			'created' => [ 'templates' => [] ],
			'updated' => [ 'templates' => [] ],
			'failed' => [ 'templates' => [] ],
		];

		$templates = $schema['templates'] ?? [];

		foreach ( $templates as $name => $data ) {
			$title = $this->pageCreator->makeTitle( $name, NS_TEMPLATE );
			$existed = $title && $this->pageCreator->pageExists( $title );

			$content = $data['content'] ?? '';
			$ok = $this->pageCreator->createOrUpdatePage(
				$title,
				$content,
				'SemanticSchemas: Install property template'
			);

			if ( $ok ) {
				$result[$existed ? 'updated' : 'created']['templates'][] = $name;
			} else {
				$result['failed']['templates'][] = $name;
			}
		}

		return $result;
	}

	/**
	 * Apply properties with TYPE ONLY (Layer 1).
	 * This creates property pages with just [[Has type::...]] to register types in SMW.
	 *
	 * @param array $schema
	 * @return array
	 */
	public function applyPropertiesTypeOnly( array $schema ): array {
		$validation = $this->validator->validateSchemaWithSeverity( $schema );

		$result = [
			'errors' => $validation['errors'],
			'warnings' => $validation['warnings'],
			'created' => [ 'properties' => [] ],
			'updated' => [ 'properties' => [] ],
			'failed' => [ 'properties' => [] ],
		];

		if ( $validation['errors'] ) {
			return $result;
		}

		$properties = $schema['properties'] ?? [];

		foreach ( $properties as $name => $data ) {
			$title = $this->pageCreator->makeTitle( $name, SMW_NS_PROPERTY );
			$existed = $title && $this->pageCreator->pageExists( $title );

			$model = new PropertyModel( $name, $data ?? [] );
			$ok = $this->propertyStore->writePropertyTypeOnly( $model );

			if ( $ok ) {
				$result[$existed ? 'updated' : 'created']['properties'][] = $name;
			} else {
				$result['failed']['properties'][] = $name;
			}
		}

		return $result;
	}

	/**
	 * Apply properties with FULL annotations (Layer 2).
	 * This updates property pages with all semantic annotations.
	 *
	 * @param array $schema
	 * @return array
	 */
	public function applyPropertiesFull( array $schema ): array {
		$validation = $this->validator->validateSchemaWithSeverity( $schema );

		$result = [
			'errors' => $validation['errors'],
			'warnings' => $validation['warnings'],
			'created' => [ 'properties' => [] ],
			'updated' => [ 'properties' => [] ],
			'failed' => [ 'properties' => [] ],
		];

		if ( $validation['errors'] ) {
			return $result;
		}

		$properties = $schema['properties'] ?? [];

		foreach ( $properties as $name => $data ) {
			$title = $this->pageCreator->makeTitle( $name, SMW_NS_PROPERTY );
			$existed = $title && $this->pageCreator->pageExists( $title );

			$model = new PropertyModel( $name, $data ?? [] );
			$ok = $this->propertyStore->writeProperty( $model );

			if ( $ok ) {
				$result[$existed ? 'updated' : 'created']['properties'][] = $name;
			} else {
				$result['failed']['properties'][] = $name;
			}
		}

		return $result;
	}

	/**
	 * Apply subobjects only (Layer 3).
	 *
	 * @param array $schema
	 * @return array
	 */
	public function applySubobjectsOnly( array $schema ): array {
		$validation = $this->validator->validateSchemaWithSeverity( $schema );

		$result = [
			'errors' => $validation['errors'],
			'warnings' => $validation['warnings'],
			'created' => [ 'subobjects' => [] ],
			'updated' => [ 'subobjects' => [] ],
			'failed' => [ 'subobjects' => [] ],
		];

		if ( $validation['errors'] ) {
			return $result;
		}

		$subobjects = $schema['subobjects'] ?? [];

		foreach ( $subobjects as $name => $data ) {
			$title = $this->pageCreator->makeTitle( $name, NS_SUBOBJECT );
			$existed = $title && $this->pageCreator->pageExists( $title );

			$model = new SubobjectModel( $name, $data ?? [] );
			$ok = $this->subobjectStore->writeSubobject( $model );

			if ( $ok ) {
				$result[$existed ? 'updated' : 'created']['subobjects'][] = $name;
			} else {
				$result['failed']['subobjects'][] = $name;
			}
		}

		return $result;
	}

	/**
	 * Apply a parsed extension config schema array.
	 *
	 * If validation errors are present, no pages are written and the
	 * errors are returned to the caller.
	 *
	 * Uses a two-pass approach for properties to avoid circular dependencies:
	 * - Pass A: Create properties with ONLY [[Has type::...]] declarations
	 * - Rebuild SMW data so property types are indexed
	 * - Pass B: Update properties with all other annotations
	 * - Then create categories which can now reference correctly-typed properties
	 *
	 * @param array $schema
	 * @return array See applyFromFile()
	 */
	public function applySchema( array $schema ): array {
		$validation = $this->validator->validateSchemaWithSeverity( $schema );

		$result = [
			'errors' => $validation['errors'],
			'warnings' => $validation['warnings'],
			'created' => [
				'templates' => [],
				'properties' => [],
				'categories' => [],
				'subobjects' => [],
			],
			'updated' => [
				'templates' => [],
				'properties' => [],
				'categories' => [],
				'subobjects' => [],
			],
			'failed' => [
				'templates' => [],
				'properties' => [],
				'categories' => [],
				'subobjects' => [],
			],
		];

		// Do not write anything if the schema is invalid.
		if ( $validation['errors'] ) {
			return $result;
		}

		$templates = $schema['templates'] ?? [];
		$categories = $schema['categories'] ?? [];
		$properties = $schema['properties'] ?? [];
		$subobjects = $schema['subobjects'] ?? [];

		// =====================================================================
		// Templates (Layer 0) - No SMW dependencies
		// Create property display templates before anything else.
		// =====================================================================
		foreach ( $templates as $name => $data ) {
			$title = $this->pageCreator->makeTitle( $name, NS_TEMPLATE );
			$existed = $title && $this->pageCreator->pageExists( $title );

			$content = $data['content'] ?? '';
			$ok = $this->pageCreator->createOrUpdatePage(
				$title,
				$content,
				'SemanticSchemas: Install property template'
			);

			if ( $ok ) {
				$result[$existed ? 'updated' : 'created']['templates'][] = $name;
			} else {
				$result['failed']['templates'][] = $name;
			}
		}

		// Track which properties existed before installation
		$propertyExisted = [];
		foreach ( $properties as $name => $data ) {
			$title = $this->pageCreator->makeTitle( $name, SMW_NS_PROPERTY );
			$propertyExisted[$name] = $title && $this->pageCreator->pageExists( $title );
		}

		// =====================================================================
		// PASS A: Create properties with ONLY type declarations
		// This ensures SMW knows the datatypes before any other annotations are added.
		// =====================================================================
		foreach ( $properties as $name => $data ) {
			$model = new PropertyModel( $name, $data ?? [] );
			$this->propertyStore->writePropertyTypeOnly( $model );
		}

		// =====================================================================
		// PASS B: Update properties with full annotations
		// Now that SMW knows the types, we can safely add [[Has description::...]] etc.
		// =====================================================================
		foreach ( $properties as $name => $data ) {
			$model = new PropertyModel( $name, $data ?? [] );
			$ok = $this->propertyStore->writeProperty( $model );

			if ( $ok ) {
				if ( $propertyExisted[$name] ) {
					$result['updated']['properties'][] = $name;
				} else {
					$result['created']['properties'][] = $name;
				}
			} else {
				$result['failed']['properties'][] = $name;
			}
		}

		// =====================================================================
		// Subobjects
		// =====================================================================
		foreach ( $subobjects as $name => $data ) {
			$title = $this->pageCreator->makeTitle( $name, NS_SUBOBJECT );
			$existed = $title && $this->pageCreator->pageExists( $title );

			$model = new SubobjectModel( $name, $data ?? [] );
			$ok = $this->subobjectStore->writeSubobject( $model );

			if ( $ok ) {
				if ( $existed ) {
					$result['updated']['subobjects'][] = $name;
				} else {
					$result['created']['subobjects'][] = $name;
				}
			} else {
				$result['failed']['subobjects'][] = $name;
			}
		}

		// =====================================================================
		// Categories
		// Now that all property types are correctly indexed, categories can
		// use properties like [[Has target namespace::Category]] correctly.
		// =====================================================================
		foreach ( $categories as $name => $data ) {
			$categoryTitle = $this->pageCreator->makeTitle( $name, NS_CATEGORY );
			$existed = $categoryTitle && $this->pageCreator->pageExists( $categoryTitle );

			$model = new CategoryModel( $name, $data ?? [] );
			$ok = $this->categoryStore->writeCategory( $model );

			if ( $ok ) {
				if ( $existed ) {
					$result['updated']['categories'][] = $name;
				} else {
					$result['created']['categories'][] = $name;
				}
			} else {
				$result['failed']['categories'][] = $name;
			}
		}

		return $result;
	}

	/**
	 * Force SMW to rebuild semantic data for specific property pages.
	 *
	 * This is necessary because SMW may not have processed property type definitions
	 * before categories try to use them, resulting in "improper value" errors.
	 *
	 * We force a fresh parse (bypassing cache) to ensure SMW processes the property
	 * type definitions before categories reference them.
	 *
	 * @param string[] $propertyNames
	 */
	private function rebuildSMWDataForProperties( array $propertyNames ): void {
		if ( !class_exists( \SMW\StoreFactory::class ) ) {
			return;
		}

		$store = \SMW\StoreFactory::getStore();
		$services = \MediaWiki\MediaWikiServices::getInstance();

		foreach ( $propertyNames as $name ) {
			$title = $this->pageCreator->makeTitle( $name, SMW_NS_PROPERTY );
			if ( !$title || !$title->exists() ) {
				continue;
			}

			try {
				$wikiPage = $services->getWikiPageFactory()->newFromTitle( $title );
				$content = $wikiPage->getContent();
				if ( !$content ) {
					continue;
				}

				// Force a fresh parse using ContentRenderer, bypassing parser cache.
				// This ensures SMW's parser hooks run and process property type definitions.
				$parserOptions = \ParserOptions::newFromAnon();
				$parserOptions->setOption( 'enableLimitReport', false );

				$contentRenderer = $services->getContentRenderer();
				$parserOutput = $contentRenderer->getParserOutput(
					$content,
					$title,
					null, // revision
					$parserOptions
				);

				if ( $parserOutput && method_exists( \SMW\ParserData::class, 'newFromParserOutput' ) ) {
					$parserData = \SMW\ParserData::newFromParserOutput( $parserOutput, $title );
					if ( $parserData ) {
						$store->updateData( $parserData->getSemanticData() );
					}
				}
			} catch ( \Throwable $e ) {
				wfDebugLog( 'semanticschemas', "Failed to rebuild SMW data for Property:$name: " . $e->getMessage() );
			}
		}
	}

	/**
	 * Run any pending MediaWiki jobs to ensure SMW data is fully processed.
	 *
	 * This is critical for ensuring SMW's property type registry is updated
	 * before categories reference those properties.
	 */
	private function runPendingJobs(): void {
		try {
			$runner = \MediaWiki\MediaWikiServices::getInstance()->getJobRunner();
			$runner->run( [
				'type' => false,
				'maxJobs' => 100,
				'maxTime' => 30,
			] );
		} catch ( \Throwable $e ) {
			wfDebugLog( 'semanticschemas', "Failed to run pending jobs: " . $e->getMessage() );
		}
	}

	/**
	 * Clear SMW's in-memory caches to ensure fresh property type lookups.
	 *
	 * SMW caches property type information in memory via ServicesFactory singleton.
	 * After creating/updating properties, we must clear this cache so that subsequent
	 * page parses will see the correct property types. Without this, SMW may cache
	 * stale type information and misinterpret property values (e.g., treating text
	 * values as wiki page references).
	 */
	private function clearSMWCaches(): void {
		// Clear SMW's ServicesFactory singleton to force fresh service instances.
		// This resets all in-memory caches including property type lookups.
		if ( class_exists( \SMW\ServicesFactory::class ) ) {
			\SMW\ServicesFactory::clear();
		}

		// Also clear the StoreFactory cache.
		if ( class_exists( \SMW\StoreFactory::class ) ) {
			\SMW\StoreFactory::clear();
		}
	}

	/**
	 * Force SMW to rebuild semantic data for specific category pages.
	 *
	 * This is necessary because SMW may not have processed category semantic data
	 * (like Has target namespace) before form generation tries to read it.
	 *
	 * We force a fresh parse (bypassing cache) to ensure SMW sees the current
	 * property type definitions.
	 *
	 * @param string[] $categoryNames
	 */
	private function rebuildSMWDataForCategories( array $categoryNames ): void {
		if ( !class_exists( \SMW\StoreFactory::class ) ) {
			return;
		}

		$store = \SMW\StoreFactory::getStore();
		$services = \MediaWiki\MediaWikiServices::getInstance();

		foreach ( $categoryNames as $name ) {
			$title = $this->pageCreator->makeTitle( $name, NS_CATEGORY );
			if ( !$title || !$title->exists() ) {
				continue;
			}

			try {
				$wikiPage = $services->getWikiPageFactory()->newFromTitle( $title );
				$content = $wikiPage->getContent();
				if ( !$content ) {
					continue;
				}

				// Force a fresh parse using ContentRenderer, bypassing parser cache.
				// This ensures SMW's parser hooks run with current property type knowledge.
				$parserOptions = \ParserOptions::newFromAnon();
				$parserOptions->setOption( 'enableLimitReport', false );

				$contentRenderer = $services->getContentRenderer();
				$parserOutput = $contentRenderer->getParserOutput(
					$content,
					$title,
					null, // revision
					$parserOptions
				);

				if ( $parserOutput && method_exists( \SMW\ParserData::class, 'newFromParserOutput' ) ) {
					$parserData = \SMW\ParserData::newFromParserOutput( $parserOutput, $title );
					if ( $parserData ) {
						$store->updateData( $parserData->getSemanticData() );
					}
				}
			} catch ( \Throwable $e ) {
				wfDebugLog( 'semanticschemas', "Failed to rebuild SMW data for Category:$name: " . $e->getMessage() );
			}
		}
	}
}
