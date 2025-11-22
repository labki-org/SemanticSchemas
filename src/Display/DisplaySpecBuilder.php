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
 */
class DisplaySpecBuilder
{

    /** @var InheritanceResolver */
    private $inheritanceResolver;

    /** @var WikiCategoryStore */
    private $categoryStore;

    /**
     * @param InheritanceResolver|null $inheritanceResolver
     * @param WikiCategoryStore|null $categoryStore
     */
    public function __construct(
        InheritanceResolver $inheritanceResolver = null,
        WikiCategoryStore $categoryStore = null
    ) {
        $this->categoryStore = $categoryStore ?? new WikiCategoryStore();

        if ($inheritanceResolver) {
            $this->inheritanceResolver = $inheritanceResolver;
        } else {
            // If not provided, we need to build one with all categories
            // This might be expensive if done on every request, so ideally it's injected.
            // For now, we'll load all categories if needed.
            $allCategories = $this->categoryStore->getAllCategories();
            $categoryMap = [];
            foreach ($allCategories as $cat) {
                $categoryMap[$cat->getName()] = $cat;
            }
            $this->inheritanceResolver = new InheritanceResolver($categoryMap);
        }
    }

    /**
     * Build the display specification for a category.
     *
     * @param string $categoryName
     * @return array{
     *   sections: array<int,array{
     *     name: string,
     *     category: string,
     *     properties: string[]
     *   }>
     * }
     */
    public function buildSpec(string $categoryName): array
    {
        // 1. Get linearized ancestor list (root -> ... -> leaf)
        // We want to apply parent sections first, then override/append with child sections.
        // InheritanceResolver::getAncestors returns [parent, grandparent...] (immediate parent first)
        // So we need to reverse it to get [grandparent, parent, child] order.

        $ancestors = $this->inheritanceResolver->getAncestors($categoryName);
        // getAncestors returns category name strings, we need to convert to CategoryModel objects
        $chain = [];
        foreach (array_reverse($ancestors) as $ancestorName) {
            $category = $this->categoryStore->readCategory($ancestorName);
            if ($category) {
                $chain[] = $category;
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
                $sectionName = $section['name'] ?? 'Details';
                $properties = $section['properties'] ?? [];

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
            if ($current) {
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
