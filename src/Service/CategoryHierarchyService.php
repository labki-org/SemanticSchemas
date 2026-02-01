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
	 * INTERNAL: INHERITED PROPERTIES & SUBOBJECTS
	 * ===================================================================== */

	private function extractInheritedProperties(
		string $name,
		InheritanceResolver $resolver,
		array $all
	): array {
		$output = [];
		$seen = [];
		$this->collectFromAncestors(
			$resolver->getAncestors( $name ), $all, $output, $seen,
			'propertyTitle', 'Property:',
			'getRequiredProperties', 'getOptionalProperties'
		);
		return $output;
	}

	private function extractInheritedSubobjects(
		string $name,
		InheritanceResolver $resolver,
		array $all
	): array {
		$output = [];
		$seen = [];
		$this->collectFromAncestors(
			$resolver->getAncestors( $name ), $all, $output, $seen,
			'subobjectTitle', 'Subobject:',
			'getRequiredSubobjects', 'getOptionalSubobjects'
		);
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
			$this->collectFromAncestors(
				$resolver->getAncestors( $parent ), $all, $output, $seen,
				'propertyTitle', 'Property:',
				'getRequiredProperties', 'getOptionalProperties'
			);
		}

		return $output;
	}

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
			$this->collectFromAncestors(
				$resolver->getAncestors( $parent ), $all, $output, $seen,
				'subobjectTitle', 'Subobject:',
				'getRequiredSubobjects', 'getOptionalSubobjects'
			);
		}

		return $output;
	}

	/* =====================================================================
	 * INTERNAL: SHARED COLLECTION HELPER
	 * ===================================================================== */

	/**
	 * Iterate ancestors and collect required/optional items with deduplication.
	 *
	 * @param string[] $ancestors Ordered ancestor list
	 * @param array $all All category models keyed by name
	 * @param array &$output Accumulates result entries
	 * @param array &$seen Tracks already-collected item names
	 * @param string $titleKey Key name in output entries (e.g. 'propertyTitle')
	 * @param string $titlePrefix Namespace prefix (e.g. 'Property:')
	 * @param string $requiredGetter Method name for required items
	 * @param string $optionalGetter Method name for optional items
	 */
	private function collectFromAncestors(
		array $ancestors,
		array $all,
		array &$output,
		array &$seen,
		string $titleKey,
		string $titlePrefix,
		string $requiredGetter,
		string $optionalGetter
	): void {
		foreach ( $ancestors as $ancestor ) {
			$model = $all[$ancestor] ?? null;
			if ( !$model ) {
				continue;
			}

			$source = "Category:$ancestor";

			foreach ( $model->$requiredGetter() as $item ) {
				if ( !isset( $seen[$item] ) ) {
					$output[] = [
						$titleKey => $titlePrefix . $item,
						'sourceCategory' => $source,
						'required' => true,
					];
					$seen[$item] = true;
				}
			}

			foreach ( $model->$optionalGetter() as $item ) {
				if ( !isset( $seen[$item] ) ) {
					$output[] = [
						$titleKey => $titlePrefix . $item,
						'sourceCategory' => $source,
						'required' => false,
					];
					$seen[$item] = true;
				}
			}
		}
	}
}
