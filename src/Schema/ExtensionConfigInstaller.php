<?php

namespace MediaWiki\Extension\SemanticSchemas\Schema;

use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;

/**
 * Installs pre-compiled base configuration pages from static .wikitext files.
 *
 * Reads from resources/base-config/{templates,properties,subobjects,categories}/
 * and writes each file as a wiki page via PageCreator.
 *
 * Processing order matters: properties must be created before categories/subobjects
 * so SMW registers types in CLI mode (LinksUpdate is synchronous in CLI).
 */
class ExtensionConfigInstaller {

	private const BASE_CONFIG_DIR = __DIR__ . '/../../resources/base-config';

	private const TEMPLATES_DIR = 'templates';

	/** @var array<string,int> Directory name → MediaWiki namespace constant */
	private const ENTITY_DIRS = [
		self::TEMPLATES_DIR => NS_TEMPLATE,
		'properties'        => SMW_NS_PROPERTY,
		'subobjects'        => NS_SUBOBJECT,
		'categories'        => NS_CATEGORY,
	];

	/**
	 * Human-readable namespace prefix for each entity directory.
	 * @var array<string,string>
	 */
	public const ENTITY_LABELS = [
		self::TEMPLATES_DIR => 'Template',
		'properties'        => 'Property',
		'subobjects'        => 'Subobject',
		'categories'        => 'Category',
	];

	private PageCreator $pageCreator;
	private ?array $cachedEntries = null;

	public function __construct( PageCreator $pageCreator ) {
		$this->pageCreator = $pageCreator;
	}

	/**
	 * Check if all base configuration pages exist.
	 *
	 * @return bool True if every .wikitext file has a corresponding wiki page
	 */
	public function isInstalled(): bool {
		foreach ( $this->getBaseConfigEntries() as [ $type, $namespace, $pageName, $filePath ] ) {
			$title = $this->pageCreator->makeTitle( $pageName, $namespace );
			if ( !$title || !$this->pageCreator->pageExists( $title ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Install or update all base configuration pages.
	 *
	 * @return array{
	 *   created: array<string, string[]>,
	 *   updated: array<string, string[]>,
	 *   failed: array<string, string[]>,
	 *   errors: string[]
	 * }
	 */
	public function install(): array {
		$result = $this->initResult();

		foreach ( $this->getBaseConfigEntries() as [ $type, $namespace, $pageName, $filePath ] ) {
			$content = file_get_contents( $filePath );
			if ( $content === false ) {
				$result['errors'][] = "Failed to read: $filePath";
				$result['failed'][$type][] = $pageName;
				continue;
			}

			$title = $this->pageCreator->makeTitle( $pageName, $namespace );
			if ( !$title ) {
				$result['errors'][] = "Invalid title: $pageName (ns=$namespace)";
				$result['failed'][$type][] = $pageName;
				continue;
			}

			$existed = $this->pageCreator->pageExists( $title );
			$ok = $this->pageCreator->createOrUpdatePage(
				$title,
				$content,
				'SemanticSchemas: Install base configuration'
			);

			if ( $ok ) {
				$result[$existed ? 'updated' : 'created'][$type][] = $pageName;
			} else {
				$result['failed'][$type][] = $pageName;
			}
		}

		return $result;
	}

	/**
	 * Preview what install() would do without writing.
	 *
	 * @return array{
	 *   would_create: array<string, string[]>,
	 *   would_update: array<string, string[]>
	 * }
	 */
	public function preview(): array {
		$result = [
			'would_create' => $this->initEntityArrays(),
			'would_update' => $this->initEntityArrays(),
		];

		foreach ( $this->getBaseConfigEntries() as [ $type, $namespace, $pageName, $filePath ] ) {
			$title = $this->pageCreator->makeTitle( $pageName, $namespace );
			if ( $title && $this->pageCreator->pageExists( $title ) ) {
				$result['would_update'][$type][] = $pageName;
			} else {
				$result['would_create'][$type][] = $pageName;
			}
		}

		return $result;
	}

	/**
	 * Scan base-config directories and return entry tuples.
	 *
	 * @return array<array{string, int, string, string}> [type, namespace, pageName, filePath]
	 */
	private function getBaseConfigEntries(): array {
		if ( $this->cachedEntries !== null ) {
			return $this->cachedEntries;
		}

		$entries = [];
		$baseDir = self::BASE_CONFIG_DIR;

		foreach ( self::ENTITY_DIRS as $dir => $namespace ) {
			$dirPath = "$baseDir/$dir";
			if ( !is_dir( $dirPath ) ) {
				continue;
			}

			$files = $this->scanWikitextFiles( $dirPath );
			foreach ( $files as $filePath ) {
				$pageName = $this->fileToPageName( $filePath, $dirPath, $dir );
				$entries[] = [ $dir, $namespace, $pageName, $filePath ];
			}
		}

		$this->cachedEntries = $entries;
		return $entries;
	}

	/**
	 * Recursively find all .wikitext files in a directory.
	 *
	 * @param string $dir
	 * @return string[]
	 */
	private function scanWikitextFiles( string $dir ): array {
		$files = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && $file->getExtension() === 'wikitext' ) {
				$files[] = $file->getPathname();
			}
		}

		sort( $files );
		return $files;
	}

	/**
	 * Convert a file path to a wiki page name.
	 *
	 * Templates preserve subdirectory structure (Property/Default.wikitext → Property/Default).
	 * Other types convert underscores to spaces (Has_description.wikitext → Has description).
	 *
	 * @param string $filePath Absolute path to the .wikitext file
	 * @param string $dirPath Absolute path to the entity type directory
	 * @param string $entityType Key from ENTITY_DIRS (e.g., 'templates', 'properties')
	 * @return string
	 */
	private function fileToPageName( string $filePath, string $dirPath, string $entityType ): string {
		// Get relative path from the entity directory
		$relative = substr( $filePath, strlen( $dirPath ) + 1 );
		// Strip .wikitext extension
		$name = preg_replace( '/\.wikitext$/', '', $relative );

		// Templates: preserve subdirectory slashes, no underscore conversion
		// (Property/Default stays as Property/Default)
		if ( $entityType === self::TEMPLATES_DIR ) {
			return $name;
		}

		// Others: underscores → spaces
		return str_replace( '_', ' ', $name );
	}

	/**
	 * @return array<string, string[]>
	 */
	private function initEntityArrays(): array {
		$arrays = [];
		foreach ( array_keys( self::ENTITY_DIRS ) as $dir ) {
			$arrays[$dir] = [];
		}
		return $arrays;
	}

	/**
	 * @return array{
	 *   created: array<string, string[]>,
	 *   updated: array<string, string[]>,
	 *   failed: array<string, string[]>,
	 *   errors: string[]
	 * }
	 */
	private function initResult(): array {
		return [
			'created' => $this->initEntityArrays(),
			'updated' => $this->initEntityArrays(),
			'failed' => $this->initEntityArrays(),
			'errors' => [],
		];
	}
}
