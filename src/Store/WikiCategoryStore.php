<?php

namespace MediaWiki\Extension\StructureSync\Store;

use MediaWiki\Extension\StructureSync\Schema\CategoryModel;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * WikiCategoryStore
 * ----------------
 * Handles reading and writing Category pages with schema metadata.
 * 
 * This class manages the bidirectional mapping between:
 * - Wiki Category pages (with semantic annotations)
 * - CategoryModel objects (schema representation)
 * 
 * Reading Strategy:
 * -----------------
 * Queries Semantic MediaWiki's data store directly instead of parsing wikitext.
 * This ensures we always work with rendered semantic data after template expansion.
 * 
 * Writing Strategy:
 * -----------------
 * Schema metadata is written within HTML comment markers on Category pages:
 * <!-- StructureSync Start -->
 * ...semantic annotations...
 * <!-- StructureSync End -->
 * 
 * Semantic Properties Read:
 * - Has parent category (→ parents)
 * - Has required property (→ required properties list)
 * - Has optional property (→ optional properties list)
 * - Has description (→ description)
 * - Has target namespace (→ target namespace)
 * - Subobjects with "Has display section name" and "Has display section property" (→ display sections)
 * 
 * Semantic Annotations Written:
 * - [[Has parent category::Category:ParentName]]
 * - [[Has required property::Property:PropName]]
 * - [[Has optional property::Property:PropName]]
 * - [[Has description::...]]
 * - [[Has target namespace::...]]
 * - {{#subobject:display_section_N|Has display section name=...|Has display section property=Property:...}}
 */
class WikiCategoryStore
{

    /** @var PageCreator */
    private $pageCreator;

    /** Schema content markers - must match PageHashComputer */
    private const MARKER_START = '<!-- StructureSync Start -->';
    private const MARKER_END = '<!-- StructureSync End -->';

    public function __construct(PageCreator $pageCreator = null)
    {
        $this->pageCreator = $pageCreator ?? new PageCreator();
    }

    /**
     * Read a category from the wiki
     *
     * @param string $categoryName Category name (without "Category:" prefix)
     * @return CategoryModel|null
     */
    public function readCategory(string $categoryName): ?CategoryModel
    {
        $title = $this->pageCreator->makeTitle($categoryName, NS_CATEGORY);
        if ($title === null || !$this->pageCreator->pageExists($title)) {
            return null;
        }

        // Query SMW for semantic data instead of parsing wikitext
        $data = $this->queryCategoryFromSMW($title, $categoryName);

        return new CategoryModel($categoryName, $data);
    }

    /**
     * Query category metadata from SMW
     *
     * Reads semantic properties directly from SMW store instead of parsing wikitext.
     * This ensures we always work with the rendered/stored semantic data.
     *
     * @param Title $title Category title
     * @param string $categoryName Category name (without prefix)
     * @return array Category data array
     */
    private function queryCategoryFromSMW(\MediaWiki\Title\Title $title, string $categoryName): array
    {
        $data = [
            'parents' => [],
            'properties' => [
                'required' => [],
                'optional' => [],
            ],
            'display' => [],
            'forms' => [],
            'label' => $categoryName,
            'description' => '',
        ];

        // Get SMW semantic data for this page
        /** @var object $store */
        $store = \SMW\StoreFactory::getStore();
        /** @var object $subject */
        $subject = \SMW\DIWikiPage::newFromTitle($title);
        $semanticData = $store->getSemanticData($subject);

        // Extract parent categories from "Has parent category" property
        $data['parents'] = $this->getPropertyValues($semanticData, 'Has parent category', 'category');

        // Extract required properties from "Has required property" property
        $data['properties']['required'] = $this->getPropertyValues($semanticData, 'Has required property', 'property');

        // Extract optional properties from "Has optional property" property
        $data['properties']['optional'] = $this->getPropertyValues($semanticData, 'Has optional property', 'property');

        // Extract description from "Has description" property
        $descriptions = $this->getPropertyValues($semanticData, 'Has description', 'text');
        if (!empty($descriptions)) {
            $data['description'] = $descriptions[0];
        }

        // Extract target namespace from "Has target namespace" property
        $namespaces = $this->getPropertyValues($semanticData, 'Has target namespace', 'text');
        if (!empty($namespaces)) {
            $data['targetNamespace'] = $namespaces[0];
        }

		// Extract required / optional subgroups
		$data['subgroups']['required'] = $this->getPropertyValues(
			$semanticData,
			'Has required subgroup',
			'subobject'
		);
		$data['subgroups']['optional'] = $this->getPropertyValues(
			$semanticData,
			'Has optional subgroup',
			'subobject'
		);

        // Extract display sections from subobjects
        $data['display']['sections'] = $this->extractDisplaySections($semanticData);

        return $data;
    }

    /**
     * Get property values from semantic data
     *
     * @param \SMW\SemanticData $semanticData
     * @param string $propertyName Property name (e.g., "Has required property")
     * @param string $type Expected type: 'text', 'property', 'category', or 'page'
     * @return array Array of values
     */
    private function getPropertyValues($semanticData, string $propertyName, string $type = 'text'): array
    {
        try {
            $property = \SMW\DIProperty::newFromUserLabel($propertyName);
            $propertyValues = $semanticData->getPropertyValues($property);

            $values = [];
            foreach ($propertyValues as $dataItem) {
                $value = $this->extractValueFromDataItem($dataItem, $type);
                if ($value !== null) {
                    $values[] = $value;
                }
            }

            return $values;
        } catch (\Exception $e) {
            // Property doesn't exist or other error
            return [];
        }
    }

    /**
     * Extract value from SMW DataItem based on expected type
     *
     * @param \SMWDataItem $dataItem
     * @param string $type Expected type: 'text', 'property', 'category', or 'page'
     * @return string|null
     */
    private function extractValueFromDataItem($dataItem, string $type): ?string
    {
        if ($dataItem instanceof \SMW\DIWikiPage) {
            $title = $dataItem->getTitle();
            if ($title === null) {
                return null;
            }

            // Extract based on expected type
            switch ($type) {
                case 'property':
                    // Remove "Property:" prefix
                    return $title->getNamespace() === \SMW_NS_PROPERTY 
                        ? $title->getText() 
                        : null;
                
                case 'category':
                    // Remove "Category:" prefix
                    return $title->getNamespace() === NS_CATEGORY 
                        ? $title->getText() 
                        : null;
                
                case 'page':
                    // Return full page name
                    return $title->getPrefixedText();

                case 'subobject':
                    return $title->getNamespace() === NS_SUBOBJECT
                        ? $title->getText()
                        : null;
                
                default:
                    return $title->getText();
            }
        } elseif ($dataItem instanceof \SMWDIBlob) {
            // Text/string value
            return $dataItem->getString();
        } elseif ($dataItem instanceof \SMWDIString) {
            return $dataItem->getString();
        }

        return null;
    }

    /**
     * Extract display sections from subobjects
     *
     * @param \SMW\SemanticData $semanticData
     * @return array Array of display sections
     */
    private function extractDisplaySections($semanticData): array
    {
        $sections = [];
        $subobjects = $semanticData->getSubSemanticData();

        foreach ($subobjects as $subobjectData) {
            $subobjectName = $subobjectData->getSubject()->getSubobjectName();
            
            // Only process display_section_* subobjects
            if (strpos($subobjectName, 'display_section_') !== 0) {
                continue;
            }

            $section = [];

            // Get section name
            $names = $this->getPropertyValues($subobjectData, 'Has display section name', 'text');
            if (!empty($names)) {
                $section['name'] = $names[0];
            }

            // Get section properties
            $section['properties'] = $this->getPropertyValues($subobjectData, 'Has display section property', 'property');

            if (!empty($section['name']) && !empty($section['properties'])) {
                $sections[] = $section;
            }
        }

        return $sections;
    }


    /**
     * Write a category to the wiki
     */
    public function writeCategory(CategoryModel $category): bool
    {

        $title = $this->pageCreator->makeTitle($category->getName(), NS_CATEGORY);
        if ($title === null) {
            return false;
        }

        $existingContent = $this->pageCreator->getPageContent($title) ?? '';

        $schemaContent = $this->generateSchemaMetadata($category);

        // Write inside markers
        $newContent = $this->pageCreator->updateWithinMarkers(
            $existingContent,
            $schemaContent,
            self::MARKER_START,
            self::MARKER_END
        );

        // Add tracking category
        $tracking = '[[Category:StructureSync-managed]]';
        if (strpos($newContent, $tracking) === false) {
            $newContent .= "\n$tracking";
        }

        $summary = "StructureSync: Update category schema metadata";

        return $this->pageCreator->createOrUpdatePage($title, $newContent, $summary);
    }

    /**
     * Generate schema metadata
     *
     * NOTE: **Corrected behavior**
     * - DO NOT write `[[Category:Parent]]` into the categories.
     * - Only emit semantic metadata.
     */
    private function generateSchemaMetadata(CategoryModel $category): string
    {

        $lines = [];

        // Description (optional)
        if ($category->getDescription() !== '') {
            $lines[] = '[[Has description::' . $category->getDescription() . ']]';
        }

        // Target namespace (optional)
        if ($category->getTargetNamespace() !== null) {
            $lines[] = '[[Has target namespace::' . $category->getTargetNamespace() . ']]';
        }

        // Parents
        foreach ($category->getParents() as $parent) {
            $lines[] = "[[Has parent category::Category:$parent]]";
        }
        if (!empty($category->getParents())) {
            $lines[] = '';
        }

        // Required props
        if ($req = $category->getRequiredProperties()) {
            $lines[] = '=== Required Properties ===';
            foreach ($req as $prop) {
                $lines[] = "[[Has required property::Property:$prop]]";
            }
            $lines[] = '';
        }

        // Optional props
        if ($opt = $category->getOptionalProperties()) {
            $lines[] = '=== Optional Properties ===';
            foreach ($opt as $prop) {
                $lines[] = "[[Has optional property::Property:$prop]]";
            }

		// Subgroups
		if ( $req = $category->getRequiredSubgroups() ) {
			foreach ( $req as $subgroup ) {
				$lines[] = "[[Has required subgroup::Subobject:$subgroup]]";
			}
			$lines[] = '';
		}

		if ( $opt = $category->getOptionalSubgroups() ) {
			foreach ( $opt as $subgroup ) {
				$lines[] = "[[Has optional subgroup::Subobject:$subgroup]]";
			}
			$lines[] = '';
            }
            $lines[] = '';
        }

        // Display sections
        $sections = $category->getDisplaySections();
        if (!empty($sections)) {
            $lines[] = '=== Display Configuration ===';

            foreach ($sections as $idx => $sec) {
                $lines[] = "{{#subobject:display_section_$idx";
                $name = $sec['name'] ?? '';
                $lines[] = "|Has display section name=" . ($name !== null ? (string) $name : '');

                if (!empty($sec['properties'])) {
                    foreach ($sec['properties'] as $p) {
                        $pSafe = $p !== null ? (string) $p : '';
                        if ($pSafe !== '') {
                            $lines[] = "|Has display section property=Property:$pSafe";
                        }
                    }
                }

                $lines[] = "}}";
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Get all Category: pages
     */
    public function getAllCategories(): array
    {

        $categories = [];
        $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbr = $lb->getConnection(DB_REPLICA);

        $res = $dbr->newSelectQueryBuilder()
            ->select('page_title')
            ->from('page')
            ->where(['page_namespace' => NS_CATEGORY])
            ->caller(__METHOD__)
            ->fetchResultSet();

        foreach ($res as $row) {
            $name = str_replace('_', ' ', $row->page_title);
            $cat = $this->readCategory($name);
            if ($cat !== null) {
                $categories[$name] = $cat;
            }
        }

        return $categories;
    }

    public function categoryExists(string $categoryName): bool
    {
        $title = $this->pageCreator->makeTitle($categoryName, NS_CATEGORY);
        return $title !== null && $this->pageCreator->pageExists($title);
    }
}
