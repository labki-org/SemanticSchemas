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
		$this->collectPropertiesFromAncestors(
			$resolver->getAncestors( $name ), $all, $output, $seen
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
		$this->collectSubobjectsFromAncestors(
			$resolver->getAncestors( $name ), $all, $output, $seen
		);
		return $output;
	}

	/* =====================================================================
	 * INTERNAL: VIRTUAL-INHERITANCE (FORM PREVIEW)
	 *
	 * "Virtual" means the category does not yet exist in the wiki. When the
	 * user is creating a new category via the UI, these methods resolve what
	 * properties and subobjects the new category *would* inherit from its
	 * chosen parents so the form can preview inherited fields in real time.
	 * ===================================================================== */

	/**
	 * Collect inherited properties for a category that does not yet exist.
	 *
	 * Resolves the full ancestor chain of each selected parent and aggregates
	 * all properties with their required/optional flags and source categories.
	 *
	 * @param string[] $parents Parent category names (no namespace prefix)
	 * @param array<string,\MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel> $all
	 *   All existing CategoryModels keyed by name
	 * @return array List of inherited property descriptors
	 */
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
			$this->collectPropertiesFromAncestors(
				$resolver->getAncestors( $parent ), $all, $output, $seen
			);
		}

		return $output;
	}

	/**
	 * Collect inherited subobjects for a category that does not yet exist.
	 *
	 * Same as extractVirtualInheritedProperties but for subobject definitions.
	 *
	 * @param string[] $parents Parent category names (no namespace prefix)
	 * @param array<string,\MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel> $all
	 *   All existing CategoryModels keyed by name
	 * @return array List of inherited subobject descriptors
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
			$this->collectSubobjectsFromAncestors(
				$resolver->getAncestors( $parent ), $all, $output, $seen
			);
		}

		return $output;
	}

	/* =====================================================================
	 * INTERNAL: ANCESTOR COLLECTION HELPERS
	 * ===================================================================== */

	/**
	 * Iterate ancestors and collect properties with deduplication.
	 *
	 * @param string[] $ancestors Ordered ancestor list
	 * @param array $all All category models keyed by name
	 * @param array &$output Accumulates result entries
	 * @param array &$seen Tracks already-collected item names
	 */
	private function collectPropertiesFromAncestors(
		array $ancestors,
		array $all,
		array &$output,
		array &$seen
	): void {
		foreach ( $ancestors as $ancestor ) {
			$model = $all[$ancestor] ?? null;
			if ( !$model ) {
				continue;
			}

			$source = "Category:$ancestor";

			foreach ( $model->getTaggedProperties() as $tagged ) {
				if ( !isset( $seen[$tagged['name']] ) ) {
					$output[] = [
						'propertyTitle' => 'Property:' . $tagged['name'],
						'sourceCategory' => $source,
						'required' => $tagged['required'],
					];
					$seen[$tagged['name']] = true;
				}
			}
		}
	}

	/**
	 * Iterate ancestors and collect subobjects with deduplication.
	 *
	 * @param string[] $ancestors Ordered ancestor list
	 * @param array $all All category models keyed by name
	 * @param array &$output Accumulates result entries
	 * @param array &$seen Tracks already-collected item names
	 */
	private function collectSubobjectsFromAncestors(
		array $ancestors,
		array $all,
		array &$output,
		array &$seen
	): void {
		foreach ( $ancestors as $ancestor ) {
			$model = $all[$ancestor] ?? null;
			if ( !$model ) {
				continue;
			}

			$source = "Category:$ancestor";

			foreach ( $model->getTaggedSubobjects() as $tagged ) {
				if ( !isset( $seen[$tagged['name']] ) ) {
					$output[] = [
						'subobjectTitle' => 'Subobject:' . $tagged['name'],
						'sourceCategory' => $source,
						'required' => $tagged['required'],
					];
					$seen[$tagged['name']] = true;
				}
			}
		}
	}
}
