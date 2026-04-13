<?php

namespace MediaWiki\Extension\SemanticSchemas\Schema;

use InvalidArgumentException;
use MediaWiki\Extension\SemanticSchemas\Util\Constants;
use RuntimeException;

/**
 * Resolves inheritance for categories using C3 linearization.
 *
 * Responsibilities:
 *   - Compute deterministic ancestor chains
 *   - Detect circular inheritance
 *   - Merge CategoryModels in correct order (child → parent → ... → root)
 *
 * This class never mutates CategoryModel instances; it only constructs
 * new combined models.
 */
class InheritanceResolver {

	/** @var array<string,CategoryModel> */
	private array $categoryMap;

	/** @var array<string,string[]> */
	private array $ancestorCache = [];

	/** @var array<string,EffectiveCategoryModel> */
	private array $effectiveCache = [];

	/**
	 * @param array<string,CategoryModel> $categoryMap
	 */
	public function __construct( array $categoryMap ) {
		foreach ( $categoryMap as $name => $model ) {
			if ( !$model instanceof CategoryModel ) {
				throw new InvalidArgumentException(
					"CategoryMap['{$name}'] must contain CategoryModel instances."
				);
			}
		}
		$this->categoryMap = $categoryMap;
	}

	/* -------------------------------------------------------------------------
	 * PUBLIC API
	 * ---------------------------------------------------------------------- */

	/**
	 * Check if a category exists in the resolver's category map.
	 */
	public function hasCategory( string $categoryName ): bool {
		return isset( $this->categoryMap[$categoryName] );
	}

	/**
	 * Return a C3-linearized list including the category itself.
	 *
	 * Index 0 = most specific (the category itself)
	 * Last    = root-most ancestor
	 */
	public function getAncestors( string $categoryName ): array {
		if ( isset( $this->ancestorCache[$categoryName] ) ) {
			return $this->ancestorCache[$categoryName];
		}

		if ( !isset( $this->categoryMap[$categoryName] ) ) {
			throw new RuntimeException( "Unknown category: $categoryName" );
		}

		$linear = $this->c3Linearization( $categoryName, [] );

		// Cache only valid linearizations
		$this->ancestorCache[$categoryName] = $linear;
		return $linear;
	}

	/**
	 * Return a merged CategoryModel representing the fully inherited category.
	 *
	 * Merge order:
	 *   child.mergeWithParent(parent)
	 *   Then merge the result with the next parent in lineage.
	 */
	public function getEffectiveCategory( string $categoryName ): EffectiveCategoryModel {
		if ( isset( $this->effectiveCache[$categoryName] ) ) {
			return $this->effectiveCache[$categoryName];
		}

		$linear = $this->getAncestors( $categoryName );

		/** @var CategoryModel|null $merged */
		$merged = null;

		foreach ( $linear as $name ) {
			if ( !array_key_exists( $name, $this->categoryMap ) ) {
				// Fine - parent is declared, but category page doesn't exist.
				continue;
			}
			$current = $this->categoryMap[$name];

			if ( $merged === null ) {
				$merged = $current;
			} else {
				$merged = $merged->mergeWithParent( $current );
			}
		}

		// Single-category chain: mergeWithParent was never called, wrap as effective
		if ( !( $merged instanceof EffectiveCategoryModel ) ) {
			$merged = new EffectiveCategoryModel( $merged->getName(), $merged->toArray() );
		}

		$this->effectiveCache[$categoryName] = $merged;
		return $merged;
	}

	/**
	 * Return effective models for each direct parent of a category.
	 *
	 * @param string $categoryName
	 * @return array<string,EffectiveCategoryModel> Parent name → effective model
	 */
	public function getParentEffectiveModels( string $categoryName ): array {
		$category = $this->categoryMap[$categoryName]
			?? throw new RuntimeException( "Unknown category: $categoryName" );
		$result = [];
		foreach ( $category->getParents() as $parentName ) {
			if ( !array_key_exists( $parentName, $this->categoryMap ) ) {
				continue;
			}
			$result[$parentName] = $this->getEffectiveCategory( $parentName );
		}
		return $result;
	}

	/**
	 * Return the C3-linearized list of raw (unmerged) CategoryModel objects.
	 *
	 * Order: [child, parent1, parent2, ..., root]
	 * Each model contains only its own declared properties.
	 *
	 * @param string $categoryName
	 * @return CategoryModel[]
	 */
	public function getInheritanceChain( string $categoryName ): array {
		$ancestors = $this->getAncestors( $categoryName );
		return array_map(
			fn ( $name ) => $this->categoryMap[$name],
			$ancestors
		);
	}

	/**
	 * Validate inheritance and return error messages.
	 */
	public function validateInheritance(): array {
		$errors = [];

		foreach ( array_keys( $this->categoryMap ) as $name ) {
			try {
				// Force computation → detect cycles
				$this->getAncestors( $name );
			} catch ( RuntimeException $e ) {
				$errors[] = $e->getMessage();
			}
		}

		return $errors;
	}

	/* -------------------------------------------------------------------------
	 * C3 LINEARIZATION
	 * ---------------------------------------------------------------------- */

	private function c3Linearization( string $categoryName, array $visiting ): array {
		// Cycle detection
		if ( in_array( $categoryName, $visiting, true ) ) {
			throw new RuntimeException(
				"Circular inheritance detected: " .
				implode( " → ", $visiting ) . " → {$categoryName}"
			);
		}

		if ( !isset( $this->categoryMap[$categoryName] ) ) {
			return [ $categoryName ];
		}

		$category = $this->categoryMap[$categoryName];
		// the SemanticSchemas-managed category is just a marker
		$parents = array_filter(
			$category->getParents(),
			static fn ( $parent ) => $parent != Constants::SEMANTICSCHEMAS_MANAGED_CATEGORY
		);

		if ( $parents === [] ) {
			return [ $categoryName ];
		}

		$visiting[] = $categoryName;

		$linearizations = [];
		foreach ( $parents as $p ) {
			$linearizations[] = $this->c3Linearization( $p, $visiting );
		}

		$merged = $this->c3Merge( array_merge( $linearizations, [ $parents ] ) );

		array_unshift( $merged, $categoryName );
		return $merged;
	}

	/**
	 * C3 merge operation.
	 *
	 * @param array<int,string[]> $sequences
	 */
	private function c3Merge( array $sequences ): array {
		$output = [];

		while ( !$this->allEmpty( $sequences ) ) {

			$candidate = $this->findC3Head( $sequences );
			if ( $candidate === null ) {
				throw new RuntimeException( "C3 merge failed: inconsistent parent ordering." );
			}

			$output[] = $candidate;

			// Remove from sequences
			foreach ( $sequences as $i => $seq ) {
				$sequences[$i] = array_values(
					array_filter( $seq, static fn ( $x ) => $x !== $candidate )
				);
			}
		}

		return $output;
	}

	/* -------------------------------------------------------------------------
	 * UTILITIES
	 * ---------------------------------------------------------------------- */

	private function allEmpty( array $sequences ): bool {
		foreach ( $sequences as $seq ) {
			if ( $seq !== [] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A valid head must not appear in any tail position of any sequence.
	 */
	private function findC3Head( array $sequences ): ?string {
		foreach ( $sequences as $seq ) {
			if ( $seq === [] ) {
				continue;
			}

			$head = $seq[0];
			$valid = true;

			foreach ( $sequences as $other ) {
				if ( count( $other ) > 1 && in_array( $head, array_slice( $other, 1 ), true ) ) {
					$valid = false;
					break;
				}
			}

			if ( $valid ) {
				return $head;
			}
		}

		return null;
	}
}
