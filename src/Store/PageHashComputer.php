<?php

namespace MediaWiki\Extension\SemanticSchemas\Store;

use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\PropertyModel;
use MediaWiki\Title\Title;

/**
 * PageHashComputer
 * ---------------
 * Computes SHA256 hashes for Category and Property pages to detect modifications.
 *
 * Hash Scope:
 * ----------
 * For Categories: Only hashes content between SemanticSchemas markers
 * For Properties: Only hashes content between SemanticSchemas markers
 *
 * This allows users to add custom content outside the markers without
 * triggering "modified outside SemanticSchemas" warnings.
 *
 * Marker Format Requirements:
 * --------------------------
 * - Markers must be HTML comments
 * - Must appear exactly as: <!-- SemanticSchemas Start --> and <!-- SemanticSchemas End -->
 * - Case-sensitive
 * - Markers must not be nested
 * - Content between markers is trimmed before hashing for consistency
 *
 * Hash Format:
 * -----------
 * Hashes are returned with "sha256:" prefix for algorithm identification.
 * This allows future support for other algorithms if needed.
 *
 * Example: "sha256:abc123def456..."
 */
class PageHashComputer {

	private WikiCategoryStore $categoryStore;
	private WikiPropertyStore $propertyStore;

	public function __construct(
		WikiCategoryStore $categoryStore,
		WikiPropertyStore $propertyStore
	) {
		$this->categoryStore = $categoryStore;
		$this->propertyStore = $propertyStore;
	}

	/**
	 * Compute hash for a CategoryModel based on canonical schema fields.
	 *
	 * @param CategoryModel $category
	 * @return string
	 */
	public function computeCategoryModelHash( CategoryModel $category ): string {
		return $this->computeSchemaHash( $category->toArray() );
	}

	/**
	 * Compute hash for a PropertyModel based on canonical schema fields.
	 *
	 * @param PropertyModel $property
	 * @return string
	 */
	public function computePropertyModelHash( PropertyModel $property ): string {
		return $this->computeSchemaHash( $property->toArray() );
	}

	/**
	 * Compute hash for a schema-managed page based on its prefixed name.
	 * Routes to the appropriate model-based hash method.
	 *
	 * @param string $pageName Prefixed page name (e.g., "Category:Name", "Property:Name")
	 * @return string SHA256 hash (with "sha256:" prefix)
	 */
	public function computeHashForPageModel( string $pageName ): string {
		// Extract prefix from page name
		if ( preg_match( '/^([^:]+):/', $pageName, $matches ) ) {
			$prefix = strtolower( $matches[1] );
			$name = substr( $pageName, strlen( $matches[0] ) );

			switch ( $prefix ) {
				case 'category':
					$cat = $this->categoryStore->readCategory( $name );
					return $cat instanceof CategoryModel
						? $this->computeCategoryModelHash( $cat )
						: $this->hashContent( '' );
				case 'property':
					$prop = $this->propertyStore->readProperty( $name );
					return $prop instanceof PropertyModel
						? $this->computePropertyModelHash( $prop )
						: $this->hashContent( '' );
				default:
					// Unknown type, fall through and hash empty
					return $this->hashContent( '' );
			}
		}

		// No prefix found, hash empty
		return $this->hashContent( '' );
	}

	/**
	 * Compute SHA256 hash of content.
	 *
	 * @param string $content
	 * @return string Hash with "sha256:" prefix
	 */
	private function hashContent( string $content ): string {
		$hash = hash( 'sha256', $content );
		return 'sha256:' . $hash;
	}

	/**
	 * Compute hash of schema array (for sourceSchemaHash).
	 *
	 * @param array $schema
	 * @return string SHA256 hash (with "sha256:" prefix)
	 */
	public function computeSchemaHash( array $schema ): string {
		// Normalize schema: sort keys recursively for deterministic hashing
		$normalized = $this->normalizeArray( $schema );
		$json = json_encode(
			$normalized,
			\JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE
		);
		return $this->hashContent( $json );
	}

	/**
	 * Normalize array by sorting keys recursively.
	 *
	 * @param array $array
	 * @return array
	 */
	private function normalizeArray( array $array ): array {
		ksort( $array );
		foreach ( $array as $key => $value ) {
			if ( is_array( $value ) ) {
				$array[$key] = $this->normalizeArray( $value );
			}
		}
		return $array;
	}

	/**
	 * Get page revision info (revId and touched timestamp).
	 * Used as quick filter to skip unchanged pages.
	 *
	 * @param Title $title
	 * @return array|null ['revId' => int, 'touched' => string] or null if page doesn't exist
	 */
	public function getPageRevisionInfo( Title $title ): ?array {
		if ( !$title->exists() ) {
			return null;
		}

		$revId = $title->getLatestRevID();
		$touched = $title->getTouched();

		return [
			'revId' => $revId,
			'touched' => $touched,
		];
	}
}
