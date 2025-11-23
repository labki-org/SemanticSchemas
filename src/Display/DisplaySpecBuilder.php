<?php

namespace MediaWiki\Extension\StructureSync\Display;

use MediaWiki\Extension\StructureSync\Schema\InheritanceResolver;
use MediaWiki\Extension\StructureSync\Store\WikiCategoryStore;
use MediaWiki\Extension\StructureSync\Schema\CategoryModel;

/**
 * DisplaySpecBuilder
 * ------------------
 * Builds the effective display specification for a category by:
 * 1. Resolving the inheritance chain
 * 2. Merging display sections from ancestors
 * 3. Normalizing property lists
 * 
 * This class handles the complex logic of combining display configurations
 * from parent categories with the current category's configuration, respecting
 * the inheritance hierarchy determined by C3 linearization.
 * 
 * Performance Note: The InheritanceResolver should be injected when possible
 * to avoid loading all categories on every request.
 */
class DisplaySpecBuilder
{

    /** @var InheritanceResolver */
    private $inheritanceResolver;

    /** @var WikiCategoryStore */
    private $categoryStore;

    /**
     * @param InheritanceResolver|null $inheritanceResolver Optional pre-built resolver (recommended for performance)
     * @param WikiCategoryStore|null $categoryStore Optional category store
     */
    public function __construct(
        InheritanceResolver $inheritanceResolver = null,
        WikiCategoryStore $categoryStore = null
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
    private function buildDefaultResolver(): InheritanceResolver
    {
        $allCategories = $this->categoryStore->getAllCategories();
        $categoryMap = [];
        foreach ($allCategories as $cat) {
            $categoryMap[$cat->getName()] = $cat;
        }
        return new InheritanceResolver($categoryMap);
    }

    /**
     * Build the display specification for a category.
     * 
     * This method:
     * 1. Resolves the category's inheritance chain
     * 2. Collects display sections from all ancestors (root-first order)
     * 3. Merges sections with the same name
     * 4. Generates a default section if no sections are defined
     * 
     * Section merging strategy:
     * - Sections with the same name are merged
     * - Properties are appended (no duplicates)
     * - The most specific category defining a section wins for metadata
     *
     * @param string $categoryName The category name to build spec for
     * @return array{
     *   sections: array<int,array{
     *     name: string,
     *     category: string,
     *     properties: string[]
     *   }>
     * }
     * @throws \RuntimeException If inheritance resolution fails
     */
    public function buildSpec(string $categoryName): array
    {
        if (trim($categoryName) === '') {
            throw new \InvalidArgumentException('Category name cannot be empty');
        }

        // 1. Get linearized ancestor list (root -> ... -> leaf)
        // We want to apply parent sections first, then override/append with child sections.
        // InheritanceResolver::getAncestors returns [parent, grandparent...] (immediate parent first)
        // So we need to reverse it to get [grandparent, parent, child] order.
        
        try {
            $ancestors = $this->inheritanceResolver->getAncestors($categoryName);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(
                "Failed to resolve inheritance for category '$categoryName': " . $e->getMessage(),
                0,
                $e
            );
        }

        // getAncestors returns category name strings, we need to convert to CategoryModel objects
        $chain = [];
        foreach (array_reverse($ancestors) as $ancestorName) {
            $category = $this->categoryStore->readCategory($ancestorName);
            if ($category !== null) {
                $chain[] = $category;
            } else {
                wfLogWarning("StructureSync: Category '$ancestorName' in inheritance chain not found");
            }
        }

        $mergedSections = [];

        foreach ($chain as $category) {
            if (!$category instanceof CategoryModel) {
                continue;
            }

            $catSections = $category->getDisplaySections();
            if (empty($catSections)) {
                continue;
            }

            foreach ($catSections as $section) {
                // Validate section structure
                if (!isset($section['name']) || !is_string($section['name'])) {
                    wfLogWarning("StructureSync: Malformed display section in category '{$category->getName()}': missing or invalid 'name'");
                    continue;
                }
                
                $sectionName = $section['name'];
                $properties = $section['properties'] ?? [];
                
                if (!is_array($properties)) {
                    wfLogWarning("StructureSync: Malformed display section '$sectionName' in category '{$category->getName()}': 'properties' must be array");
                    continue;
                }

                // Find if section already exists
                $foundIndex = -1;
                foreach ($mergedSections as $idx => $existing) {
                    if ($existing['name'] === $sectionName) {
                        $foundIndex = $idx;
                        break;
                    }
                }

                if ($foundIndex !== -1) {
                    // Merge properties into existing section
                    // Append new properties that aren't duplicates
                    $existingProps = $mergedSections[$foundIndex]['properties'];
                    foreach ($properties as $prop) {
                        if (!in_array($prop, $existingProps, true)) {
                            $existingProps[] = $prop;
                        }
                    }
                    $mergedSections[$foundIndex]['properties'] = $existingProps;
                    // Update source category to most specific one defining this section
                    $mergedSections[$foundIndex]['category'] = $category->getName();
                } else {
                    // Add new section
                    $mergedSections[] = [
                        'name' => $sectionName,
                        'category' => $category->getName(),
                        'properties' => $properties
                    ];
                }
            }
        }

        // If no sections defined anywhere, create a default one with all properties
        if (empty($mergedSections)) {
            // Get the most specific category (last in chain, or read it if chain is empty)
            $current = !empty($chain) ? end($chain) : $this->categoryStore->readCategory($categoryName);
            if ($current !== null && $current !== false) {
                $allProps = $current->getAllProperties();
                if (!empty($allProps)) {
                    $mergedSections[] = [
                        'name' => $current->getLabel() . ' Details',
                        'category' => $current->getName(),
                        'properties' => $allProps
                    ];
                }
            }
        }

        return [
            'sections' => $mergedSections
        ];
    }
}
