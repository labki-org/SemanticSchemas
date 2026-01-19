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
 * updating Category:, Property:, and Subobject: pages.
 *
 * This is a minimal, internal helper that reuses the canonical
 * schema models + Wiki*Store classes, and writes via PageCreator.
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
	 * Check if the base extension configuration has been installed.
	 *
	 * Checks for the existence of a key property from extension-config.json
	 * to determine if installation has occurred.
	 *
	 * @return bool True if the base configuration appears to be installed
	 */
	public function isInstalled(): bool {
		// Check if a core property from extension-config.json exists
		// Property:Has type is a fundamental property defined in the base config
		$title = $this->pageCreator->makeTitle( 'Has type', SMW_NS_PROPERTY );
		return $title && $this->pageCreator->pageExists( $title );
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
				'properties' => [],
				'categories' => [],
				'subobjects' => [],
			],
			'would_update' => [
				'properties' => [],
				'categories' => [],
				'subobjects' => [],
			],
		];

		if ( $validation['errors'] ) {
			return $result;
		}

		$properties = $schema['properties'] ?? [];
		$categories = $schema['categories'] ?? [];
		$subobjects = $schema['subobjects'] ?? [];

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
	 * Apply a parsed extension config schema array.
	 *
	 * If validation errors are present, no pages are written and the
	 * errors are returned to the caller.
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
				'properties' => [],
				'categories' => [],
				'subobjects' => [],
			],
			'updated' => [
				'properties' => [],
				'categories' => [],
				'subobjects' => [],
			],
			'failed' => [
				'properties' => [],
				'categories' => [],
				'subobjects' => [],
			],
		];

		// Do not write anything if the schema is invalid.
		if ( $validation['errors'] ) {
			return $result;
		}

		$categories = $schema['categories'] ?? [];
		$properties = $schema['properties'] ?? [];
		$subobjects = $schema['subobjects'] ?? [];

		// 1. Properties
		foreach ( $properties as $name => $data ) {
			$title = $this->pageCreator->makeTitle( $name, SMW_NS_PROPERTY );
			$existed = $title && $this->pageCreator->pageExists( $title );

			$model = new PropertyModel( $name, $data ?? [] );
			$ok = $this->propertyStore->writeProperty( $model );

			if ( $ok ) {
				if ( $existed ) {
					$result['updated']['properties'][] = $name;
				} else {
					$result['created']['properties'][] = $name;
				}
			} else {
				$result['failed']['properties'][] = $name;
			}
		}

		// 2. Subobjects
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

		// Force SMW to rebuild semantic data for all properties before creating categories.
		// This ensures SMW knows the property datatypes before categories reference them.
		$this->rebuildSMWDataForProperties( array_keys( $properties ) );

		// 3. Categories
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
	 * @param string[] $propertyNames
	 */
	private function rebuildSMWDataForProperties( array $propertyNames ): void {
		if ( !class_exists( \SMW\StoreFactory::class ) ) {
			return;
		}

		$store = \SMW\StoreFactory::getStore();

		foreach ( $propertyNames as $name ) {
			$title = $this->pageCreator->makeTitle( $name, SMW_NS_PROPERTY );
			if ( !$title || !$title->exists() ) {
				continue;
			}

			try {
				// Create a DIWikiPage for the property
				$subject = \SMW\DIWikiPage::newFromTitle( $title );

				// Get the WikiPage and force a re-parse to extract semantic data
				$wikiPage = \MediaWiki\MediaWikiServices::getInstance()
					->getWikiPageFactory()
					->newFromTitle( $title );

				$content = $wikiPage->getContent();
				if ( !$content ) {
					continue;
				}

				// Parse the page content to extract semantic data
				$parserOutput = $wikiPage->getParserOutput(
					\MediaWiki\MediaWikiServices::getInstance()->getParserFactory()->getInstance()
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
}
