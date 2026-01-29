<?php

namespace MediaWiki\Extension\SemanticSchemas\Store;

/**
 * StateManager
 * ------------
 * Manages the out-of-sync state tracking for SemanticSchemas.
 * Stores state in MediaWiki:SemanticSchemasState.json page.
 */
class StateManager {

	/** @var PageCreator */
	private $pageCreator;

	/** @var string */
	private const STATE_PAGE = 'SemanticSchemasState.json';

	/**
	 * @param PageCreator|null $pageCreator
	 */
	public function __construct( ?PageCreator $pageCreator = null ) {
		$this->pageCreator = $pageCreator ?? new PageCreator();
	}

	/**
	 * Get the current state from the JSON page.
	 *
	 * @return array
	 */
	private function getState(): array {
		$title = $this->pageCreator->makeTitle( self::STATE_PAGE, NS_MEDIAWIKI );
		if ( $title === null || !$this->pageCreator->pageExists( $title ) ) {
			return $this->getDefaultState();
		}

		$content = $this->pageCreator->getPageContent( $title );
		if ( $content === null ) {
			return $this->getDefaultState();
		}

		$state = json_decode( $content, true );
		if ( !is_array( $state ) ) {
			return $this->getDefaultState();
		}

		// Merge with defaults to ensure all keys exist
		return array_merge( $this->getDefaultState(), $state );
	}

	/**
	 * Get default state structure.
	 *
	 * @return array
	 */
	private function getDefaultState(): array {
		return [
			'dirty' => false,
			'lastChangeTimestamp' => null,
			'generated' => null,
			'sourceSchemaHash' => null,
			'pageHashes' => [],
		];
	}

	/**
	 * Save state to the JSON page.
	 *
	 * @param array $state
	 * @return bool
	 */
	private function saveState( array $state ): bool {
		$title = $this->pageCreator->makeTitle( self::STATE_PAGE, NS_MEDIAWIKI );
		if ( $title === null ) {
			return false;
		}

		$json = json_encode( $state, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE );
		$summary = 'SemanticSchemas: Update state tracking';

		return $this->pageCreator->createOrUpdatePage( $title, $json, $summary );
	}

	/**
	 * Mark system as dirty or clean.
	 *
	 * @param bool $dirty
	 * @return bool
	 */
	public function setDirty( bool $dirty ): bool {
		$state = $this->getState();
		$state['dirty'] = $dirty;
		if ( $dirty ) {
			$state['lastChangeTimestamp'] = wfTimestamp( TS_ISO_8601 );
		}
		return $this->saveState( $state );
	}

	/**
	 * Clear dirty flag.
	 *
	 * @return bool
	 */
	public function clearDirty(): bool {
		return $this->setDirty( false );
	}

	/**
	 * Check if system is dirty.
	 *
	 * @return bool
	 */
	public function isDirty(): bool {
		$state = $this->getState();
		return (bool)( $state['dirty'] ?? false );
	}

	/**
	 * Get timestamp of last change.
	 *
	 * @return string|null
	 */
	public function getLastChangeTimestamp(): ?string {
		$state = $this->getState();
		return $state['lastChangeTimestamp'] ?? null;
	}

	/**
	 * Get stored page hashes.
	 *
	 * @return array
	 */
	public function getPageHashes(): array {
		$state = $this->getState();
		return $state['pageHashes'] ?? [];
	}

	/**
	 * Store page hashes (sets both generated and current).
	 *
	 * @param array $hashes Map of page name => hash string
	 * @return bool
	 */
	public function setPageHashes( array $hashes ): bool {
		$state = $this->getState();
		$pageHashes = $state['pageHashes'] ?? [];

		foreach ( $hashes as $pageName => $hash ) {
			// If hash is a string, convert to structure with both generated and current
			if ( is_string( $hash ) ) {
				$pageHashes[$pageName] = [
					'generated' => $hash,
					'current' => $hash,
				];
			} elseif ( is_array( $hash ) ) {
				// If already a structure, preserve it but ensure both keys exist
				$pageHashes[$pageName] = [
					'generated' => $hash['generated'] ?? $hash['current'] ?? '',
					'current' => $hash['current'] ?? $hash['generated'] ?? '',
				];
			}
		}

		$state['pageHashes'] = $pageHashes;
		$state['generated'] = wfTimestamp( TS_ISO_8601 );
		return $this->saveState( $state );
	}

	/**
	 * Update current hash values after validation.
	 *
	 * @param array $currentHashes Map of page name => hash string
	 * @return bool
	 */
	public function updateCurrentHashes( array $currentHashes ): bool {
		$state = $this->getState();
		$pageHashes = $state['pageHashes'] ?? [];

		foreach ( $currentHashes as $pageName => $hash ) {
			if ( isset( $pageHashes[$pageName] ) ) {
				$pageHashes[$pageName]['current'] = $hash;
			} else {
				// If page not in stored hashes, initialize it
				$pageHashes[$pageName] = [
					'generated' => $hash,
					'current' => $hash,
				];
			}
		}

		$state['pageHashes'] = $pageHashes;
		return $this->saveState( $state );
	}

	/**
	 * Compare current hashes with stored generated hashes.
	 *
	 * @param array $currentHashes Map of page name => hash string
	 * @return array List of modified page names
	 */
	public function comparePageHashes( array $currentHashes ): array {
		$state = $this->getState();
		$pageHashes = $state['pageHashes'] ?? [];
		$modified = [];

		foreach ( $currentHashes as $pageName => $currentHash ) {
			$stored = $pageHashes[$pageName] ?? null;
			if ( $stored === null ) {
				// Page not in stored hashes, consider it modified
				$modified[] = $pageName;
				continue;
			}

			$generatedHash = $stored['generated'] ?? '';
			if ( $currentHash !== $generatedHash ) {
				$modified[] = $pageName;
			}
		}

		// Also check for pages that were generated but no longer exist
		foreach ( $pageHashes as $pageName => $stored ) {
			if ( !isset( $currentHashes[$pageName] ) ) {
				$modified[] = $pageName;
			}
		}

		return $modified;
	}

	/**
	 * Get list of pages where generated != current.
	 *
	 * @return array
	 */
	public function getModifiedPages(): array {
		$state = $this->getState();
		$pageHashes = $state['pageHashes'] ?? [];
		$modified = [];

		foreach ( $pageHashes as $pageName => $hashes ) {
			$generated = $hashes['generated'] ?? '';
			$current = $hashes['current'] ?? '';
			if ( $generated !== $current && $generated !== '' && $current !== '' ) {
				$modified[] = $pageName;
			}
		}

		return $modified;
	}

	/**
	 * Get full state object (for debugging/testing).
	 *
	 * @return array
	 */
	public function getFullState(): array {
		return $this->getState();
	}
}
