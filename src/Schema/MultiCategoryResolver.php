<?php

namespace MediaWiki\Extension\SemanticSchemas\Schema;

/**
 * Resolves properties and subobjects across multiple categories.
 *
 * Composes InheritanceResolver to get fully-inherited CategoryModels, then merges
 * and deduplicates properties/subobjects across all input categories.
 *
 * Key behaviors:
 * - Shared items (same name) appear once, attributed to all source categories
 * - Required promotion: if any category requires an item, the merged result requires it
 * - Ordering: first-seen across categories (C3 accumulation order within each)
 * - Properties are wiki-global entities: datatype conflicts are impossible by design
 *   (a property page can only have one datatype declaration)
 *
 * This is the universal entry point for property resolution. Use it for:
 * - Multi-category pages (the primary use case)
 * - Single-category pages (wrapped in ResolvedPropertySet for consistent API)
 * - Form generation, API responses, UI rendering
 */
class MultiCategoryResolver {

	private InheritanceResolver $inheritanceResolver;

	/**
	 * @param InheritanceResolver $inheritanceResolver Resolver for single-category inheritance
	 */
	public function __construct( InheritanceResolver $inheritanceResolver ) {
		$this->inheritanceResolver = $inheritanceResolver;
	}

	/**
	 * Resolve properties and subobjects for one or more categories.
	 *
	 * Algorithm:
	 * 1. For each category, get its fully-inherited properties via InheritanceResolver
	 * 2. Merge across categories, deduplicating shared items
	 * 3. Promote optional→required if any category requires the item
	 * 4. Track source categories for each item
	 *
	 * @param string[] $categoryNames Category names to resolve
	 * @return ResolvedPropertySet Merged resolution result
	 */
	public function resolve( array $categoryNames ): ResolvedPropertySet {
		if ( $categoryNames === [] ) {
			return ResolvedPropertySet::empty();
		}

		// Initialize accumulators
		$allRequired = [];
		$allOptional = [];
		$propertySources = [];

		$allRequiredSub = [];
		$allOptionalSub = [];
		$subobjectSources = [];

		// Process each category
		foreach ( $categoryNames as $categoryName ) {
			$effective = $this->inheritanceResolver->getEffectiveCategory( $categoryName );

			// Accumulate required properties
			foreach ( $effective->getRequiredProperties() as $prop ) {
				if ( !in_array( $prop, $allRequired, true ) ) {
					$allRequired[] = $prop;
				}
				if ( !isset( $propertySources[$prop] ) ) {
					$propertySources[$prop] = [];
				}
				$propertySources[$prop][] = $categoryName;
			}

			// Accumulate optional properties (exclude already-required)
			foreach ( $effective->getOptionalProperties() as $prop ) {
				if ( !in_array( $prop, $allRequired, true ) && !in_array( $prop, $allOptional, true ) ) {
					$allOptional[] = $prop;
				}
				if ( !isset( $propertySources[$prop] ) ) {
					$propertySources[$prop] = [];
				}
				$propertySources[$prop][] = $categoryName;
			}

			// Accumulate required subobjects
			foreach ( $effective->getRequiredSubobjects() as $sub ) {
				if ( !in_array( $sub, $allRequiredSub, true ) ) {
					$allRequiredSub[] = $sub;
				}
				if ( !isset( $subobjectSources[$sub] ) ) {
					$subobjectSources[$sub] = [];
				}
				$subobjectSources[$sub][] = $categoryName;
			}

			// Accumulate optional subobjects (exclude already-required)
			foreach ( $effective->getOptionalSubobjects() as $sub ) {
				if ( !in_array( $sub, $allRequiredSub, true ) && !in_array( $sub, $allOptionalSub, true ) ) {
					$allOptionalSub[] = $sub;
				}
				if ( !isset( $subobjectSources[$sub] ) ) {
					$subobjectSources[$sub] = [];
				}
				$subobjectSources[$sub][] = $categoryName;
			}
		}

		// Silent promotion: remove from optional any that became required
		$allOptional = array_values( array_diff( $allOptional, $allRequired ) );
		$allOptionalSub = array_values( array_diff( $allOptionalSub, $allRequiredSub ) );

		// Deduplicate sources
		foreach ( $propertySources as $prop => $sources ) {
			$propertySources[$prop] = array_values( array_unique( $sources ) );
		}
		foreach ( $subobjectSources as $sub => $sources ) {
			$subobjectSources[$sub] = array_values( array_unique( $sources ) );
		}

		return new ResolvedPropertySet(
			$allRequired,
			$allOptional,
			$propertySources,
			$allRequiredSub,
			$allOptionalSub,
			$subobjectSources,
			$categoryNames
		);
	}
}
