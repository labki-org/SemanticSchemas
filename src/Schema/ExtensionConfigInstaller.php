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
 * ## Two-Pass Installation with Direct SMW Store Writes
 *
 * SMW has a chicken-and-egg problem: when updateData() writes both a
 * property's _TYPE declaration and annotations referencing that property
 * in the same call, the type isn't in the store yet when SMW determines
 * which table to write values to. This causes text values to land in
 * smw_di_wikipage (Page table) instead of smw_di_blob (Text table).
 *
 * Additionally, in web mode (MW 1.44), LinksUpdate is dispatched via
 * domain events → job queue, so types are never indexed without a
 * running job runner.
 *
 * The installer solves both issues with a two-pass approach:
 * 1. Register property types via updateData() with ONLY _TYPE values
 * 2. Re-parse pages and write full semantic data via updateData()
 *
 * This ensures all types are in the store before any annotation data
 * is written, so SMW maps values to the correct storage tables.
 */
class ExtensionConfigInstaller {

	private SchemaLoader $loader;
	private SchemaValidator $validator;
	private PageCreator $pageCreator;

	private WikiCategoryStore $categoryStore;
	private WikiPropertyStore $propertyStore;
	private WikiSubobjectStore $subobjectStore;

	public function __construct(
		SchemaLoader $loader,
		SchemaValidator $validator,
		WikiCategoryStore $categoryStore,
		WikiPropertyStore $propertyStore,
		WikiSubobjectStore $subobjectStore,
		PageCreator $pageCreator
	) {
		$this->loader = $loader;
		$this->validator = $validator;
		$this->categoryStore = $categoryStore;
		$this->propertyStore = $propertyStore;
		$this->subobjectStore = $subobjectStore;
		$this->pageCreator = $pageCreator;
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

		// Load schema once and check all layers
		$schema = $this->loader->loadFromFile( $configPath );

		return $this->areSchemaEntitiesInstalled( $schema, 'templates', NS_TEMPLATE )
			&& $this->areSchemaEntitiesInstalled( $schema, 'properties', SMW_NS_PROPERTY )
			&& $this->areSchemaEntitiesInstalled( $schema, 'subobjects', NS_SUBOBJECT )
			&& $this->areSchemaEntitiesInstalled( $schema, 'categories', NS_CATEGORY );
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

		$this->previewEntities( array_keys( $templates ), NS_TEMPLATE, 'templates', $result );
		$this->previewEntities( array_keys( $properties ), SMW_NS_PROPERTY, 'properties', $result );
		$this->previewEntities( array_keys( $subobjects ), NS_SUBOBJECT, 'subobjects', $result );
		$this->previewEntities( array_keys( $categories ), NS_CATEGORY, 'categories', $result );

		return $result;
	}

	/**
	 * Check installation status of all entity types from a parsed schema.
	 *
	 * @param array $schema Parsed schema array
	 * @return array{
	 *   templatesInstalled: bool,
	 *   propertiesInstalled: bool,
	 *   subobjectsInstalled: bool,
	 *   categoriesInstalled: bool
	 * }
	 */
	public function getInstallationStatus( array $schema ): array {
		return [
			'templatesInstalled' => $this->areSchemaEntitiesInstalled( $schema, 'templates', NS_TEMPLATE ),
			'propertiesInstalled' => $this->areSchemaEntitiesInstalled( $schema, 'properties', SMW_NS_PROPERTY ),
			'subobjectsInstalled' => $this->areSchemaEntitiesInstalled( $schema, 'subobjects', NS_SUBOBJECT ),
			'categoriesInstalled' => $this->areSchemaEntitiesInstalled( $schema, 'categories', NS_CATEGORY ),
		];
	}

	/**
	 * Classify entity names as would_create or would_update based on page existence.
	 *
	 * @param string[] $names Entity names to check
	 * @param int $namespace MediaWiki namespace constant
	 * @param string $key Result key (e.g. 'templates', 'properties')
	 * @param array &$result Preview result array to populate
	 */
	private function previewEntities( array $names, int $namespace, string $key, array &$result ): void {
		foreach ( $names as $name ) {
			$title = $this->pageCreator->makeTitle( $name, $namespace );
			if ( $title && $this->pageCreator->pageExists( $title ) ) {
				$result['would_update'][$key][] = $name;
			} else {
				$result['would_create'][$key][] = $name;
			}
		}
	}

	/**
	 * Validate schema and apply entities using a write callable.
	 *
	 * Handles the common pattern: validate → init result → iterate entities →
	 * check existence → call writer → classify as created/updated/failed.
	 *
	 * @param array $schema Full schema array
	 * @param string $entityKey Schema key (e.g. 'properties', 'categories')
	 * @param int $namespace MediaWiki namespace constant
	 * @param string $resultKey Result array key (e.g. 'properties', 'categories')
	 * @param callable $writeEntity fn(string $name, array $data): bool
	 * @return array
	 */
	private function applyValidatedEntities(
		array $schema,
		string $entityKey,
		int $namespace,
		string $resultKey,
		callable $writeEntity
	): array {
		$validation = $this->validator->validateSchemaWithSeverity( $schema );

		$result = [
			'errors' => $validation['errors'],
			'warnings' => $validation['warnings'],
			'created' => [ $resultKey => [] ],
			'updated' => [ $resultKey => [] ],
			'failed' => [ $resultKey => [] ],
		];

		if ( $validation['errors'] ) {
			return $result;
		}

		$entities = $schema[$entityKey] ?? [];

		foreach ( $entities as $name => $data ) {
			$title = $this->pageCreator->makeTitle( $name, $namespace );
			$existed = $title && $this->pageCreator->pageExists( $title );

			$ok = $writeEntity( $name, $data ?? [] );

			if ( $ok ) {
				$result[$existed ? 'updated' : 'created'][$resultKey][] = $name;
			} else {
				$result['failed'][$resultKey][] = $name;
			}
		}

		return $result;
	}

	/**
	 * Check if entities of a given type from a parsed schema are installed.
	 *
	 * @param array $schema Parsed schema array
	 * @param string $entityType Schema key: 'properties', 'categories', 'templates', or 'subobjects'
	 * @param int $namespace MediaWiki namespace constant
	 * @return bool
	 */
	private function areSchemaEntitiesInstalled( array $schema, string $entityType, int $namespace ): bool {
		$entities = $schema[$entityType] ?? [];

		if ( empty( $entities ) ) {
			return true;
		}

		foreach ( array_keys( $entities ) as $name ) {
			$title = $this->pageCreator->makeTitle( $name, $namespace );
			if ( !$title || !$this->pageCreator->pageExists( $title ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Apply a parsed extension config schema array.
	 *
	 * If validation errors are present, no pages are written and the
	 * errors are returned to the caller.
	 *
	 * Uses a two-pass approach for properties to solve SMW's chicken-and-egg
	 * type registration problem:
	 * - Pass 1: Create property pages, then register ONLY _TYPE in SMW's store
	 * - Pass 2: Re-parse property pages and write full semantic data
	 * - Then create subobjects and categories which reference correctly-typed properties
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
		// Templates - No SMW dependencies
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
		// Properties: Create pages with full annotations in one pass.
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

		// Commit page writes, then register property types directly in SMW's
		// store so they are immediately available for subsequent lookups.
		// Then parse property pages to write their full semantic data (inputType,
		// allowedNamespace, etc.) — types must be registered first so SMW can
		// correctly interpret referenced properties during parsing.
		$this->commitAndRegisterPropertyTypes( $properties );
		$this->commitAndUpdateSMWData( $properties, [], SMW_NS_PROPERTY );

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

		// Commit all remaining writes (subobjects, categories) and write
		// their semantic data directly to SMW's store so it is immediately
		// available for artifact generation.
		$this->commitAndUpdateSMWData( $subobjects, $categories, NS_SUBOBJECT, NS_CATEGORY );

		return $result;
	}

	/**
	 * Register property types directly in SMW's store tables.
	 *
	 * SMW has a chicken-and-egg problem: when updateData() writes both
	 * _TYPE and annotation values in the same call, it determines the
	 * target table (smw_di_blob vs smw_di_wikipage) by looking up the
	 * property's type in the store — but the type hasn't been written
	 * yet. This causes text values to land in smw_di_wikipage.
	 *
	 * This method solves it by writing ONLY _TYPE for each property in
	 * a separate pass, ensuring all types are in the store before the
	 * full semantic data is written in commitAndUpdateSMWData().
	 *
	 * Works in both CLI and web mode. In web mode, also commits pending
	 * page writes since LinksUpdate is dispatched via job queue.
	 *
	 * @param array $properties Property definitions from the schema
	 */
	private function commitAndRegisterPropertyTypes( array $properties ): void {
		if ( !class_exists( \SMW\StoreFactory::class ) ) {
			return;
		}

		$isWeb = !defined( 'MW_ENTRY_POINT' ) || MW_ENTRY_POINT !== 'cli';
		$lbFactory = \MediaWiki\MediaWikiServices::getInstance()->getDBLoadBalancerFactory();

		// In web mode, commit pending page writes so pages exist in the DB
		// before we write SMW data for them. In CLI mode, all writes are
		// visible within the same auto-transaction and committing would
		// conflict with PageUpdater's atomic sections.
		if ( $isWeb ) {
			$lbFactory->commitPrimaryChanges( __METHOD__ );
		}

		$store = \SMW\StoreFactory::getStore();

		// Canonical datatype → SMW internal type ID mapping
		$typeMap = [
			'Text' => '_txt', 'Page' => '_wpg', 'Date' => '_dat',
			'Number' => '_num', 'Boolean' => '_boo', 'URL' => '_uri',
			'Email' => '_ema', 'Telephone number' => '_tel', 'Code' => '_cod',
			'Geographic coordinate' => '_geo', 'Quantity' => '_qty',
			'Temperature' => '_tem', 'Annotation URI' => '_anu',
			'External identifier' => '_eid', 'Keyword' => '_key',
			'Monolingual text' => '_mlt_rec', 'Record' => '_rec',
			'Reference' => '_ref_rec',
		];

		foreach ( $properties as $name => $data ) {
			$datatype = $data['datatype'] ?? 'Page';
			$smwTypeId = $typeMap[$datatype] ?? '_wpg';

			$title = $this->pageCreator->makeTitle( $name, SMW_NS_PROPERTY );
			if ( !$title ) {
				continue;
			}

			$subject = \SMW\DIWikiPage::newFromTitle( $title );
			$semanticData = new \SMW\SemanticData( $subject );

			$typeURI = \SMWDIUri::doUnserialize(
				'http://semantic-mediawiki.org/swivt/1.0#' . $smwTypeId
			);
			$semanticData->addPropertyObjectValue(
				new \SMW\DIProperty( '_TYPE' ),
				$typeURI
			);

			$this->safeUpdateData( $store, $semanticData );
		}

		if ( $isWeb ) {
			$lbFactory->commitPrimaryChanges( __METHOD__ );
			// Flush the replica DB snapshot so subsequent reads (e.g., SMW's
			// SpecificationLookup) see the types we just committed on PRIMARY.
			$lbFactory->flushReplicaSnapshots( __METHOD__ );
		}

		$this->clearSMWCaches();

		// Invalidate SMW's SpecificationLookup cache for each property.
		// This cache (backed by EntityCache with TTL_WEEK) is NOT cleared
		// by $store->clear() and would return stale type lookups during
		// the subsequent re-parse step.
		if ( class_exists( \SMW\Services\ServicesFactory::class ) ) {
			$lookup = \SMW\Services\ServicesFactory::getInstance()
				->getPropertySpecificationLookup();
			foreach ( $properties as $name => $data ) {
				$title = $this->pageCreator->makeTitle( $name, SMW_NS_PROPERTY );
				if ( $title ) {
					$lookup->invalidateCache( \SMW\DIWikiPage::newFromTitle( $title ) );
				}
			}
		}
	}

	/**
	 * Write semantic data directly to SMW's store via fresh re-parse.
	 *
	 * Property types must already be registered (via commitAndRegisterPropertyTypes)
	 * before calling this method — otherwise SMW maps values to wrong tables.
	 *
	 * Works in both CLI and web mode.
	 *
	 * @param array $group1 Entity definitions from the schema
	 * @param array $group2 Entity definitions from the schema
	 * @param int $ns1 Namespace for group1
	 * @param int $ns2 Namespace for group2 (ignored if group2 is empty)
	 */
	private function commitAndUpdateSMWData(
		array $group1,
		array $group2 = [],
		int $ns1 = NS_SUBOBJECT,
		int $ns2 = NS_CATEGORY
	): void {
		if ( !class_exists( \SMW\StoreFactory::class ) ) {
			return;
		}

		$isWeb = !defined( 'MW_ENTRY_POINT' ) || MW_ENTRY_POINT !== 'cli';
		$lbFactory = \MediaWiki\MediaWikiServices::getInstance()->getDBLoadBalancerFactory();

		if ( $isWeb ) {
			$lbFactory->commitPrimaryChanges( __METHOD__ );

			// Flush ALL pending DeferredUpdates (PRESEND + POSTSEND) BEFORE
			// our re-parse. During page creation, MW/SMW queue deferred updates
			// carrying stale semantic data parsed before types were registered.
			// These must be flushed first so our subsequent updateData() calls
			// have the final word on what's stored in SMW's tables.
			\MediaWiki\Deferred\DeferredUpdates::doUpdates(
				\MediaWiki\Deferred\DeferredUpdates::ALL
			);
		}

		$store = \SMW\StoreFactory::getStore();

		// Clear the LinkCache so Title::exists() sees pages created
		// earlier in this request.
		\MediaWiki\MediaWikiServices::getInstance()->getLinkCache()->clear();

		foreach ( [ [ $group1, $ns1 ], [ $group2, $ns2 ] ] as [ $entities, $ns ] ) {
			foreach ( $entities as $name => $data ) {
				$title = $this->pageCreator->makeTitle( $name, $ns );
				if ( !$title || !$this->pageCreator->pageExists( $title ) ) {
					continue;
				}
				$this->updateSMWFromPage( $store, $title );
			}
		}

		if ( $isWeb ) {
			$lbFactory->commitPrimaryChanges( __METHOD__ );
		}

		$this->clearSMWCaches();
	}

	/**
	 * Force a fresh parse of a wiki page and write its semantic data to SMW.
	 *
	 * We must re-parse rather than use the cached ParserOutput from page
	 * creation, because that cache was built BEFORE property types were
	 * registered in the store. A stale parse treats all custom property
	 * values as Page references (the default) instead of their real types.
	 *
	 * @param \SMW\Store $store
	 * @param \MediaWiki\Title\Title $title
	 */
	private function updateSMWFromPage( $store, $title ): void {
		$services = \MediaWiki\MediaWikiServices::getInstance();
		$wikiPage = $services->getWikiPageFactory()->newFromTitle( $title );

		$content = $wikiPage->getContent();
		if ( !$content instanceof \MediaWiki\Content\TextContent ) {
			return;
		}

		// Force a completely fresh parse so SMW's hooks re-process all
		// inline annotations with the now-registered property types.
		$parser = $services->getParserFactory()->getInstance();
		$parserOptions = \MediaWiki\Parser\ParserOptions::newFromAnon();
		$parserOutput = $parser->parse( $content->getText(), $title, $parserOptions );

		$smwData = $parserOutput->getExtensionData( \SMW\ParserData::DATA_ID );
		if ( $smwData instanceof \SMW\SemanticData ) {
			$this->safeUpdateData( $store, $smwData );
		}
	}

	/**
	 * Clear SMW's in-memory caches so subsequent lookups re-read from DB.
	 */
	private function clearSMWCaches(): void {
		if ( class_exists( \SMW\StoreFactory::class ) ) {
			\SMW\StoreFactory::getStore()->clear();
		}
		if ( class_exists( \SMW\Services\ServicesFactory::class ) ) {
			\SMW\Services\ServicesFactory::clear();
		}
	}

	/**
	 * Call $store->updateData() with deadlock retry and section transaction recovery.
	 *
	 * On a fresh environment, SMW's section transactions can be left open
	 * by failed LinksUpdate processing. Deadlocks can also occur if a job
	 * runner is processing the same pages concurrently.
	 *
	 * @param \SMW\Store $store
	 * @param \SMW\SemanticData $semanticData
	 */
	private function safeUpdateData( $store, $semanticData ): void {
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$this->clearStaleSMWSectionTransaction( $store );
			try {
				$store->updateData( $semanticData );
				return;
			} catch ( \Wikimedia\Rdbms\DBQueryError $e ) {
				if ( stripos( $e->getMessage(), 'Deadlock' ) !== false && $attempt < 2 ) {
					usleep( 200000 );
					continue;
				}
				throw $e;
			} catch ( \RuntimeException $e ) {
				if ( stripos( $e->getMessage(), 'section transaction' ) !== false && $attempt < 2 ) {
					continue;
				}
				throw $e;
			}
		}
	}

	/**
	 * Clear any stale SMW section transaction left by a failed updateData().
	 *
	 * During page creation in CLI mode, SMW's LinksUpdate processing can
	 * open a 'sql/transaction/update' section transaction. If that processing
	 * throws (e.g., on first run with freshly-initialised SMW tables), the
	 * section transaction is left open and blocks all subsequent updateData()
	 * calls. This method detects and clears that stale state.
	 *
	 * @param \SMW\Store $store
	 */
	private function clearStaleSMWSectionTransaction( $store ): void {
		try {
			$connection = $store->getConnection( 'mw.db' );
			if ( method_exists( $connection, 'inSectionTransaction' )
				&& $connection->inSectionTransaction( 'sql/transaction/update' )
			) {
				$connection->endSectionTransaction( 'sql/transaction/update' );
			}
		} catch ( \Exception $e ) {
			// The MW atomic section may be in an inconsistent state,
			// but the TransactionHandler marker has been cleared
			// (detachSectionTransaction runs first). Proceeding with
			// a fresh updateData() call should work.
		}
	}
}
