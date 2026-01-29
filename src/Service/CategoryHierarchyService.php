<?php

namespace MediaWiki\Extension\SemanticSchemas\Service;

use MediaWiki\Extension\SemanticSchemas\Schema\InheritanceResolver;
use MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore;

/**
 * CategoryHierarchyService
 * -------------------------
 * Builds structured hierarchy data describing:
 *   - Category ancestry (nodes + parents)
 *   - Inherited properties (required/optional, source category)
 *   - Inherited subobjects (required/optional, source category)
 *
 * This data feeds:
 *   - The hierarchy API for frontend visualization
 *   - Parser-function rendering
 *   - Form preview when creating new categories
 */
class CategoryHierarchyService {

	/** @var WikiCategoryStore */
	private WikiCategoryStore $categoryStore;

	public function __construct( ?WikiCategoryStore $categoryStore = null ) {
		$this->categoryStore = $categoryStore ?? new WikiCategoryStore();
	}

	/* =====================================================================
	 * PUBLIC: REAL CATEGORY HIERARCHY
	 * ===================================================================== */

	/**
	 * Hierarchy for an existing category.
	 *
	 * @param string $categoryName Category name (no namespace)
	 * @return array
	 */
	public function getHierarchyData( string $categoryName ): array {
		$fullName = "Category:$categoryName";

		$result = [
			'rootCategory' => $fullName,
			'nodes' => [],
			'inheritedProperties' => [],
			'inheritedSubobjects' => [],
		];

		$allCategories = $this->categoryStore->getAllCategories();
		if ( empty( $allCategories ) ) {
			return $result;
		}

		$category = $allCategories[$categoryName] ?? null;
		if ( $category === null ) {
			return $result; // Category does not exist in wiki
		}

		$resolver = new InheritanceResolver( $allCategories );
		$ancestors = $resolver->getAncestors( $categoryName );

		// Build node graph
		$visited = [];
		foreach ( $ancestors as $ancestor ) {
			$this->buildNodeTree( $ancestor, $allCategories, $result['nodes'], $visited );
		}

		// Collect inheritance metadata
		$result['inheritedProperties'] = $this->extractInheritedProperties(
			$categoryName,
			$resolver,
			$allCategories
		);

		$result['inheritedSubobjects'] = $this->extractInheritedSubobjects(
			$categoryName,
			$resolver,
			$allCategories
		);

		return $result;
	}

	/* =====================================================================
	 * PUBLIC: VIRTUAL CATEGORY (FORM PREVIEW)
	 * ===================================================================== */

	/**
	 * Hierarchy for a category that does not yet exist.
	 *
	 * @param string $categoryName New category name (no namespace)
	 * @param string[] $parentNames Parents (no namespace)
	 * @return array
	 */
	public function getVirtualHierarchyData( string $categoryName, array $parentNames ): array {
		$fullName = "Category:$categoryName";

		$result = [
			'rootCategory' => $fullName,
			'nodes' => [],
			'inheritedProperties' => [],
			'inheritedSubobjects' => [],
		];

		$allCategories = $this->categoryStore->getAllCategories();
		if ( empty( $allCategories ) ) {
			// No existing categories → standalone root
			$result['nodes'][$fullName] = [
				'title' => $fullName,
				'parents' => [],
			];
			return $result;
		}

		// Only include valid parents
		$parents = array_values(
			array_filter( $parentNames, static fn ( $p ) => isset( $allCategories[$p] ) )
		);

		// Virtual root node
		$result['nodes'][$fullName] = [
			'title' => $fullName,
			'parents' => array_map( static fn ( $p ) => "Category:$p", $parents ),
		];

		// Build tree for ancestors of valid parents
		$visited = [];
		foreach ( $parents as $p ) {
			$this->buildNodeTree( $p, $allCategories, $result['nodes'], $visited );
		}

		// Extract inherited properties
		$result['inheritedProperties'] = $this->extractVirtualInheritedProperties(
			$parents,
			$allCategories
		);

		// Extract inherited subobjects
		$result['inheritedSubobjects'] = $this->extractVirtualInheritedSubobjects(
			$parents,
			$allCategories
		);

		return $result;
	}

	/* =====================================================================
	 * INTERNAL: NODE GRAPH CONSTRUCTION
	 * ===================================================================== */

	/**
	 * Add a category and its ancestors into the node graph.
	 */
	private function buildNodeTree(
		string $name,
		array $all,
		array &$nodes,
		array &$visited
	): void {
		if ( isset( $visited[$name] ) ) {
			return;
		}
		$visited[$name] = true;

		$model = $all[$name] ?? null;
		if ( $model === null ) {
			return;
		}

		$full = "Category:$name";
		$parents = array_map(
			static fn ( $p ) => "Category:$p",
			$model->getParents()
		);

		$nodes[$full] = [
			'title' => $full,
			'parents' => $parents,
		];

		foreach ( $model->getParents() as $p ) {
			$this->buildNodeTree( $p, $all, $nodes, $visited );
		}
	}

	/* =====================================================================
	 * INTERNAL: INHERITED PROPERTIES
	 * ===================================================================== */

	private function extractInheritedProperties(
		string $name,
		InheritanceResolver $resolver,
		array $all
	): array {
		$output = [];
		$seen = [];

		foreach ( $resolver->getAncestors( $name ) as $ancestor ) {
			$model = $all[$ancestor] ?? null;
			if ( !$model ) {
				continue;
			}

			$source = "Category:$ancestor";

			foreach ( $model->getRequiredProperties() as $p ) {
				if ( !isset( $seen[$p] ) ) {
					$output[] = [
						'propertyTitle' => "Property:$p",
						'sourceCategory' => $source,
						'required' => true,
					];
					$seen[$p] = true;
				}
			}

			foreach ( $model->getOptionalProperties() as $p ) {
				if ( !isset( $seen[$p] ) ) {
					$output[] = [
						'propertyTitle' => "Property:$p",
						'sourceCategory' => $source,
						'required' => false,
					];
					$seen[$p] = true;
				}
			}
		}

		return $output;
	}

	/* =====================================================================
	 * INTERNAL: INHERITED SUBGROUPS
	 * ===================================================================== */

	private function extractInheritedSubobjects(
		string $name,
		InheritanceResolver $resolver,
		array $all
	): array {
		$output = [];
		$seen = [];

		foreach ( $resolver->getAncestors( $name ) as $ancestor ) {
			$model = $all[$ancestor] ?? null;
			if ( !$model ) {
				continue;
			}

			$source = "Category:$ancestor";

			foreach ( $model->getRequiredSubobjects() as $sg ) {
				if ( !isset( $seen[$sg] ) ) {
					$output[] = [
						'subobjectTitle' => "Subobject:$sg",
						'sourceCategory' => $source,
						'required' => 1,
					];
					$seen[$sg] = true;
				}
			}

			foreach ( $model->getOptionalSubobjects() as $sg ) {
				if ( !isset( $seen[$sg] ) ) {
					$output[] = [
						'subobjectTitle' => "Subobject:$sg",
						'sourceCategory' => $source,
						'required' => 0,
					];
					$seen[$sg] = true;
				}
			}
		}

		return $output;
	}

	/* =====================================================================
	 * INTERNAL: VIRTUAL-INHERITANCE (FORM PREVIEW)
	 * ===================================================================== */

	private function extractVirtualInheritedProperties(
		array $parents,
		array $all
	): array {
		if ( empty( $parents ) ) {
			return [];
		}

		$output = [];
		$seen = [];

		$resolver = new InheritanceResolver( $all );

		foreach ( $parents as $parent ) {
			foreach ( $resolver->getAncestors( $parent ) as $ancestor ) {
				$model = $all[$ancestor] ?? null;
				if ( !$model ) {
					continue;
				}

				$source = "Category:$ancestor";

				foreach ( $model->getRequiredProperties() as $p ) {
					if ( !isset( $seen[$p] ) ) {
						$output[] = [
							'propertyTitle' => "Property:$p",
							'sourceCategory' => $source,
							'required' => true,
						];
						$seen[$p] = true;
					}
				}

				foreach ( $model->getOptionalProperties() as $p ) {
					if ( !isset( $seen[$p] ) ) {
						$output[] = [
							'propertyTitle' => "Property:$p",
							'sourceCategory' => $source,
							'required' => false,
						];
						$seen[$p] = true;
					}
				}
			}
		}

		return $output;
	}

	/**
	 * Extract inherited subobjects for virtual category (form preview).
	 *
	 * Similar to extractVirtualInheritedProperties but for subobjects.
	 *
	 * @param array $parents Array of parent category names (no namespace)
	 * @param array $all All category models
	 * @return array Array of subobject entries with subobjectTitle, sourceCategory, required
	 */
	private function extractVirtualInheritedSubobjects(
		array $parents,
		array $all
	): array {
		if ( empty( $parents ) ) {
			return [];
		}

		$output = [];
		$seen = [];

		$resolver = new InheritanceResolver( $all );

		foreach ( $parents as $parent ) {
			foreach ( $resolver->getAncestors( $parent ) as $ancestor ) {
				$model = $all[$ancestor] ?? null;
				if ( !$model ) {
					continue;
				}

				$source = "Category:$ancestor";

				foreach ( $model->getRequiredSubobjects() as $sg ) {
					if ( !isset( $seen[$sg] ) ) {
						$output[] = [
							'subobjectTitle' => "Subobject:$sg",
							'sourceCategory' => $source,
							'required' => 1,
						];
						$seen[$sg] = true;
					}
				}

				foreach ( $model->getOptionalSubobjects() as $sg ) {
					if ( !isset( $seen[$sg] ) ) {
						$output[] = [
							'subobjectTitle' => "Subobject:$sg",
							'sourceCategory' => $source,
							'required' => 0,
						];
						$seen[$sg] = true;
					}
				}
			}
		}

		return $output;
	}
}
