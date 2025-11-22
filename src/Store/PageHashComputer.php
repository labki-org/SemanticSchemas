<?php

namespace MediaWiki\Extension\StructureSync\Store;

use MediaWiki\Title\Title;

/**
 * PageHashComputer
 * ---------------
 * Computes SHA256 hashes for Category and Property pages.
 * For Categories: only hashes the StructureSync-generated section (between markers).
 * For Properties: hashes the entire page content.
 */
class PageHashComputer {

	/** @var PageCreator */
	private $pageCreator;

	/** Schema content markers */
	private const MARKER_START = '<!-- StructureSync Schema Start -->';
	private const MARKER_END = '<!-- StructureSync Schema End -->';

	/**
	 * @param PageCreator|null $pageCreator
	 */
	public function __construct( PageCreator $pageCreator = null ) {
		$this->pageCreator = $pageCreator ?? new PageCreator();
	}

	/**
	 * Compute hash for a Category page (only StructureSync section).
	 *
	 * @param string $pageContent Full page content
	 * @return string SHA256 hash (with "sha256:" prefix)
	 */
	public function computeCategoryHash( string $pageContent ): string {
		$section = $this->extractSchemaSection(
			$pageContent,
			self::MARKER_START,
			self::MARKER_END
		);

		// Normalize: trim whitespace for consistent hashing
		$section = trim( $section );
		return $this->hashContent( $section );
	}

	/**
	 * Compute hash for a Property page (entire content).
	 *
	 * @param string $pageContent Full page content
	 * @return string SHA256 hash (with "sha256:" prefix)
	 */
	public function computePropertyHash( string $pageContent ): string {
		// Normalize: trim whitespace for consistent hashing
		$content = trim( $pageContent );
		return $this->hashContent( $content );
	}

	/**
	 * Extract content between markers.
	 *
	 * @param string $content Full page content
	 * @param string $startMarker Start marker
	 * @param string $endMarker End marker
	 * @return string Extracted section, or empty string if markers not found
	 */
	public function extractSchemaSection( string $content, string $startMarker, string $endMarker ): string {
		$startPos = strpos( $content, $startMarker );
		$endPos = strpos( $content, $endMarker );

		if ( $startPos === false || $endPos === false || $endPos <= $startPos ) {
			return '';
		}

		// Extract content between markers (excluding the markers themselves)
		$startPos += strlen( $startMarker );
		$section = substr( $content, $startPos, $endPos - $startPos );

		return $section;
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
		$json = json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_SORT_KEYS );
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

