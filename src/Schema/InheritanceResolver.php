<?php

namespace MediaWiki\Extension\SemanticSchemas\Schema;

use InvalidArgumentException;
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
	 * Return a C3-linearized list including the category itself.
	 *
	 * Index 0 = most specific (the category itself)
	 * Last    = root-most ancestor
	 */
	public function getAncestors( string $categoryName ): array {
		if ( isset( $this->ancestorCache[$categoryName] ) ) {
			return $this->ancestorCache[$categoryName];
		}

		// Unknown → standalone
		if ( !isset( $this->categoryMap[$categoryName] ) ) {
			return [ $categoryName ];
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
	public function getEffectiveCategory( string $categoryName ): CategoryModel {
		if ( !isset( $this->categoryMap[$categoryName] ) ) {
			return new CategoryModel( $categoryName );
		}

		$linear = $this->getAncestors( $categoryName );
		// linear[0] = child, then direct parent, then their parents, etc.

		/** @var CategoryModel|null $effective */
		$effective = null;

		foreach ( $linear as $name ) {
			$current = $this->categoryMap[$name] ?? new CategoryModel( $name );

			if ( $effective === null ) {
				$effective = $current;
			} else {
				// Correct direction:
				// child.mergeWithParent(parent)
				$effective = $effective->mergeWithParent( $current );
			}
		}

		return $effective;
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
		$parents = $category->getParents();

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
