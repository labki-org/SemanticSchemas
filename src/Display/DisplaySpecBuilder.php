<?php

namespace MediaWiki\Extension\StructureSync\Display;

use MediaWiki\Extension\StructureSync\Schema\CategoryModel;
use MediaWiki\Extension\StructureSync\Schema\InheritanceResolver;
use MediaWiki\Extension\StructureSync\Store\WikiCategoryStore;
use MediaWiki\Extension\StructureSync\Util\NamingHelper;

/**
 * DisplaySpecBuilder
 * ------------------
 * Builds the effective display specification for a category by:
 * 1. Resolving the inheritance chain
 * 2. Merging display sections from ancestors
 * 3. Normalizing property lists
 *
 * This class handles the logic of combining display configurations
 * from parent categories with the current category's configuration,
 * respecting the inheritance hierarchy determined by C3 linearization.
 *
 * Performance Note: The InheritanceResolver should be injected when possible
 * to avoid loading all categories on every request.
 */
class DisplaySpecBuilder {

	/** @var InheritanceResolver */
	private InheritanceResolver $inheritanceResolver;

	/** @var WikiCategoryStore */
	private WikiCategoryStore $categoryStore;

	/**
	 * @param InheritanceResolver|null $inheritanceResolver Optional pre-built resolver (recommended for performance)
	 * @param WikiCategoryStore|null $categoryStore Optional category store
	 */
	public function __construct(
		?InheritanceResolver $inheritanceResolver = null,
		?WikiCategoryStore $categoryStore = null
	) {
		$this->categoryStore = $categoryStore ?? new WikiCategoryStore();
		$this->inheritanceResolver = $inheritanceResolver ?? $this->buildDefaultResolver();
	}

	/**
	 * Build a default InheritanceResolver by loading all categories.
	 *
	 * Note: This is expensive and should be avoided in production by injecting
	 * a pre-built resolver.
	 *
	 * @return InheritanceResolver
	 */
	private function buildDefaultResolver(): InheritanceResolver {
		$allCategories = $this->categoryStore->getAllCategories();

		// Ensure we have a map keyed by category name
		$categoryMap = [];
		foreach ( $allCategories as $cat ) {
			if ( $cat instanceof CategoryModel ) {
				$categoryMap[ $cat->getName() ] = $cat;
			}
		}

		return new InheritanceResolver( $categoryMap );
	}

	/**
	 * Build the display specification for a category.
	 *
	 * This method:
	 * 1. Resolves the category's inheritance chain
	 * 2. Collects display sections from all ancestors (root-first order)
	 * 3. Merges sections with the same name
	 * 4. Generates a default section if no sections are defined
	 * 5. Computes inherited subgroups (required/optional) along the chain
	 *
	 * Section merging strategy:
	 * - Sections with the same name are merged
	 * - Properties are appended (no duplicates, after normalization)
	 * - The most specific category defining a section wins for metadata
	 *
	 * Return shape:
	 * [
	 *   'sections' => [
	 *     [
	 *       'name'       => string,
	 *       'category'   => string,          // defining category name
	 *       'properties' => string[]         // normalized property names
	 *     ],
	 *     ...
	 *   ],
	 *   'subgroups' => [
	 *     'required' => string[],            // subgroup names (without prefix)
	 *     'optional' => string[]
	 *   ],
	 * ]
	 *
	 * @param string $categoryName The category name to build spec for (without "Category:" prefix)
	 * @return array
	 * @throws \RuntimeException If inheritance resolution fails
	 */
	public function buildSpec( string $categoryName ): array {
		$categoryName = trim( $categoryName );
		if ( $categoryName === '' ) {
			throw new \InvalidArgumentException( 'Category name cannot be empty' );
		}

		// 1. Get linearized ancestor list.
		// InheritanceResolver::getAncestors() returns the C3-linearized chain:
		// [ self, parent, grandparent, ... root ]
		// For merging display sections we want to apply ancestors root → ... → self,
		// so we process the reversed list when building the section chain.
		try {
			$ancestors = $this->inheritanceResolver->getAncestors( $categoryName );
		} catch ( \RuntimeException $e ) {
			throw new \RuntimeException(
				"Failed to resolve inheritance for category '$categoryName': " . $e->getMessage(),
				0,
				$e
			);
		}

		// Build CategoryModel chain in root → ... → self order for section merging
		$chain = [];
		foreach ( array_reverse( $ancestors ) as $ancestorName ) {
			$category = $this->categoryStore->readCategory( $ancestorName );
			if ( $category !== null ) {
				$chain[] = $category;
			} else {
				wfLogWarning( "StructureSync: Category '$ancestorName' in inheritance chain not found" );
			}
		}

		$mergedSections = [];

		// 2. Merge sections across the chain
		foreach ( $chain as $category ) {
			if ( !$category instanceof CategoryModel ) {
				continue;
			}

			$catSections = $category->getDisplaySections();
			if ( empty( $catSections ) ) {
				continue;
			}

			foreach ( $catSections as $section ) {
				// Validate section structure
				if ( !isset( $section['name'] ) || !is_string( $section['name'] ) ) {
					wfLogWarning(
						"StructureSync: Malformed display section in category '{$category->getName()}': missing or invalid 'name'"
					);
					continue;
				}

				$sectionName = $section['name'];
				$properties = $section['properties'] ?? [];

				if ( !is_array( $properties ) ) {
					wfLogWarning(
						"StructureSync: Malformed display section '$sectionName' in category '{$category->getName()}': 'properties' must be array"
					);
					continue;
				}

				// Normalize property names to canonical form to avoid duplicates
				$properties = array_map(
					static fn( $p ) => NamingHelper::normalizePropertyName( (string)$p ),
					$properties
				);

				// Remove empty names and deduplicate within this section
				$properties = array_values(
					array_unique(
						array_filter(
							$properties,
							static fn( $p ) => trim( $p ) !== ''
						)
					)
				);

				// Skip sections that end up with no properties – prevents empty overrides
				if ( empty( $properties ) ) {
					continue;
				}

				// Find if section already exists
				$foundIndex = -1;
				foreach ( $mergedSections as $idx => $existing ) {
					if ( $existing['name'] === $sectionName ) {
						$foundIndex = $idx;
						break;
					}
				}

				if ( $foundIndex !== -1 ) {
					// Merge into existing section: append new, non-duplicate properties
					$existingProps = $mergedSections[$foundIndex]['properties'];
					foreach ( $properties as $prop ) {
						if ( !in_array( $prop, $existingProps, true ) ) {
							$existingProps[] = $prop;
						}
					}
					$mergedSections[$foundIndex]['properties'] = $existingProps;

					// The most specific category defining this section wins
					$mergedSections[$foundIndex]['category'] = $category->getName();
				} else {
					// Add new section
					$mergedSections[] = [
						'name'       => $sectionName,
						'category'   => $category->getName(),
						'properties' => $properties,
					];
				}
			}
		}

		// Determine the most specific category (leaf) for defaulting logic
		$currentCategory =
			!empty( $chain )
				? end( $chain )
				: $this->categoryStore->readCategory( $categoryName );

		// 3. If no sections defined anywhere, create a default section
		if ( empty( $mergedSections ) && $currentCategory instanceof CategoryModel ) {
			$allProps = $currentCategory->getAllProperties();

			// Normalize and deduplicate
			$allProps = array_map(
				static fn( $p ) => NamingHelper::normalizePropertyName( (string)$p ),
				$allProps
			);
			$allProps = array_values(
				array_unique(
					array_filter(
						$allProps,
						static fn( $p ) => trim( $p ) !== ''
					)
				)
			);

			if ( !empty( $allProps ) ) {
				$labelBase = $currentCategory->getLabel() ?: $currentCategory->getName();

				$mergedSections[] = [
					'name'       => $labelBase . ' Details',
					'category'   => $currentCategory->getName(),
					'properties' => $allProps,
				];
			}
		}

		// 4. Compute inherited subgroups (required/optional) across the ancestor chain
		$allCategories = $this->categoryStore->getAllCategories();
		$requiredSubgroups = [];
		$optionalSubgroups = [];
		$seenSubgroups = [];

		foreach ( $ancestors as $ancestorName ) {
			/** @var CategoryModel|null $ancestor */
			$ancestor = $allCategories[$ancestorName] ?? null;
			if ( !$ancestor instanceof CategoryModel ) {
				continue;
			}

			// Required subgroups
			foreach ( $ancestor->getRequiredSubgroups() as $subgroup ) {
				$subgroup = trim( (string)$subgroup );
				if ( $subgroup === '' || isset( $seenSubgroups[$subgroup] ) ) {
					continue;
				}
				$seenSubgroups[$subgroup] = true;
				$requiredSubgroups[] = $subgroup;
			}

			// Optional subgroups
			foreach ( $ancestor->getOptionalSubgroups() as $subgroup ) {
				$subgroup = trim( (string)$subgroup );
				if ( $subgroup === '' || isset( $seenSubgroups[$subgroup] ) ) {
					continue;
				}
				$seenSubgroups[$subgroup] = true;
				$optionalSubgroups[] = $subgroup;
			}
		}

		return [
			'sections'  => $mergedSections,
			'subgroups' => [
				'required' => $requiredSubgroups,
				'optional' => $optionalSubgroups,
			],
		];
	}
}
