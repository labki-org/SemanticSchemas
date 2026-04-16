<?php

namespace MediaWiki\Extension\SemanticSchemas\Store;

use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\PropertyModel;

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
}
