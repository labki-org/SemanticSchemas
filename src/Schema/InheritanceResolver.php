<?php

namespace MediaWiki\Extension\StructureSync\Schema;

/**
 * InheritanceResolver
 * --------------------
 * Resolves multiple inheritance for categories (C3 linearization) and produces
 * fully merged, "effective" CategoryModel instances.
 *
 * This class is responsible for:
 *   - computing deterministic ancestor chains
 *   - providing topologically sorted inheritance order
 *   - merging CategoryModel objects in the correct order (root → leaf)
 *
 * C3 linearization ensures:
 *   - monotonicity
 *   - local precedence order preservation
 *   - consistency under multiple inheritance
 */
class InheritanceResolver {

    /** @var array<string,CategoryModel> */
    private $categoryMap;

    /** @var array<string,string[]> Memoized ancestor chains */
    private $ancestorCache = [];

    /**
     * @param array<string,CategoryModel> $categoryMap
     */
    public function __construct( array $categoryMap ) {
        $this->categoryMap = $categoryMap;
    }

    /* =========================================================================
     * Public API
     * ========================================================================= */

    /**
     * Return a C3-linearized list of ancestors including the category itself.
     * Example: ["PhDStudent", "GraduateStudent", "Person", "Entity"]
     * Most specific (category itself) is FIRST, root-most ancestor is LAST.
     *
     * @param string $categoryName
     * @return string[]
     */
    public function getAncestors( string $categoryName ): array {
        if ( isset( $this->ancestorCache[$categoryName] ) ) {
            return $this->ancestorCache[$categoryName];
        }

        if ( !isset( $this->categoryMap[$categoryName] ) ) {
            // Unknown category still returns itself
            return [ $categoryName ];
        }

        $ancestors = $this->c3Linearization( $categoryName, [] );

        // Cache canonical order
        $this->ancestorCache[$categoryName] = $ancestors;
        return $ancestors;
    }

    /**
     * Produce the fully merged effective category model:
     *   Start with the most specific category, then merge each ancestor into it.
     *
     * @param string $categoryName
     * @return CategoryModel
     */
    public function getEffectiveCategory( string $categoryName ): CategoryModel {

        // If category not defined, produce an empty one
        if ( !isset( $this->categoryMap[$categoryName] ) ) {
            return new CategoryModel( $categoryName );
        }

        $linear = $this->getAncestors( $categoryName );

        // $linear is e.g. ["GraduateStudent", "Person", "LabMember"]
        // The *first* one is the most specific (the category itself).
        // Merge in correct order: start with most specific, then merge parents into it.

        $effective = null;
        foreach ( $linear as $name ) {
            $current = $this->categoryMap[$name] ?? new CategoryModel( $name );
            if ( $effective === null ) {
                // First (most specific) category becomes starting point
                $effective = $current;
            } else {
                // Merge parent into child (parent properties merged into effective/child)
                $effective = $effective->mergeWithParent( $current );
            }
        }

        return $effective;
    }

    /**
     * Validate all categories and detect circular inheritance.
     *
     * @return array<string> list of error messages
     */
    public function validateInheritance(): array {
        $errors = [];

        foreach ( array_keys( $this->categoryMap ) as $categoryName ) {
            try {
                $this->getAncestors( $categoryName );
            }
            catch ( \RuntimeException $e ) {
                $errors[] = $e->getMessage();
            }
        }

        return $errors;
    }

    /**
     * Whether A is in B’s ancestor list.
     *
     * @param string $categoryA
     * @param string $categoryB
     * @return bool
     */
    public function isAncestorOf( string $categoryA, string $categoryB ): bool {
        $anc = $this->getAncestors( $categoryB );
        return in_array( $categoryA, $anc, true );
    }

    /* =========================================================================
     * C3 Linearization
     * ========================================================================= */

    /**
     * Compute C3 linearization for a category.
     *
     * @param string $categoryName
     * @param string[] $visiting stack for cycle detection
     * @return string[]
     */
    private function c3Linearization( string $categoryName, array $visiting ): array {

        // Cycle detection
        if ( in_array( $categoryName, $visiting, true ) ) {
            throw new \RuntimeException(
                "Circular inheritance detected: " . implode( " → ", $visiting ) . " → $categoryName"
            );
        }

        if ( !isset( $this->categoryMap[$categoryName] ) ) {
            return [ $categoryName ];
        }

        $category = $this->categoryMap[$categoryName];
        $parents = $category->getParents();

        // Base case
        if ( empty( $parents ) ) {
            return [ $categoryName ];
        }

        $visiting[] = $categoryName;

        // Recursively linearize parents
        $linearizations = [];
        foreach ( $parents as $p ) {
            $linearizations[] = $this->c3Linearization( $p, $visiting );
        }

        // Merge parent lists + direct parent list
        $merged = $this->c3Merge( array_merge( $linearizations, [ $parents ] ) );

        // Prepend this category (most specific at the beginning)
        array_unshift( $merged, $categoryName );

        return $merged;
    }

    /**
     * C3 merge step.
     *
     * @param array<int,string[]> $sequences
     * @return string[]
     */
    private function c3Merge( array $sequences ): array {

        $output = [];

        while ( !$this->allEmpty( $sequences ) ) {

            $candidate = $this->findC3Head( $sequences );

            if ( $candidate === null ) {
                // This should never happen for a consistent hierarchy
                throw new \RuntimeException(
                    "C3 merge failed: inconsistent parent ordering."
                );
            }

            $output[] = $candidate;

            // Remove candidate from sequences
            foreach ( $sequences as $i => $seq ) {
                $sequences[$i] = array_values(
                    array_filter(
                        $seq,
                        static fn ( $x ) => $x !== $candidate
                    )
                );
            }
        }

        return $output;
    }

    /* =========================================================================
     * Helpers
     * ========================================================================= */

    private function allEmpty( array $sequences ): bool {
        foreach ( $sequences as $seq ) {
            if ( !empty( $seq ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Return the first head element that does NOT appear in the tail of any sequence.
     * This is the fundamental C3 constraint.
     *
     * @param array<int,array> $sequences
     * @return string|null
     */
    private function findC3Head( array $sequences ): ?string {
        foreach ( $sequences as $seq ) {
            if ( empty( $seq ) ) {
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
