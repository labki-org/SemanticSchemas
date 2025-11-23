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
 * Storage Format:
 * --------------
 * Schema metadata is stored within HTML comment markers on Category pages:
 * <!-- StructureSync Start -->
 * ...metadata...
 * <!-- StructureSync End -->
 * 
 * Semantic Annotations Used:
 * - [[Has parent category::Category:ParentName]]
 * - [[Has required property::Property:PropName]]
 * - [[Has optional property::Property:PropName]]
 * - {{#subobject:display_section_N|...}} for display sections
 */
class WikiCategoryStore
{

    /** @var PageCreator */
    private $pageCreator;

    /** Schema content markers - must match PageHashComputer */
    private const MARKER_START = '<!-- StructureSync Start -->';
    private const MARKER_END = '<!-- StructureSync End -->';
    
    /** Regex patterns for parsing semantic annotations */
    private const PATTERN_PARENT = '/\[\[Has parent category::Category:([^\]]+)\]\]/';
    private const PATTERN_REQUIRED = '/\[\[Has required property::Property:([^\]]+)\]\]/';
    private const PATTERN_OPTIONAL = '/\[\[Has optional property::Property:([^\]]+)\]\]/';
    private const PATTERN_SUBOBJECT = '/\{\{#subobject:display_section_(\d+)([^}]*)\}\}/s';

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

        $content = $this->pageCreator->getPageContent($title);
        if ($content === null) {
            return null;
        }

        // Parse structural metadata inside the markers
        $data = $this->parseCategoryContent($content, $categoryName);

        // Could read from SMW later; for now parsing-only
        return new CategoryModel($categoryName, $data);
    }

    /**
     * Parse category metadata from page content
     *
     * NOTE: **Corrected behavior**
     * - DO NOT extract parent categories from [[Category:...]] tags.
     * - ONLY use [[Has parent category::Category:Foo]].
     */
    private function parseCategoryContent(string $content, string $categoryName): array
    {

        $data = [
            'parents' => [],
            'properties' => [
                'required' => [],
                'optional' => [],
            ],
            'display' => [],
            'forms' => [],
        ];

        // Extract ONLY semantic parent categories
        preg_match_all(
            self::PATTERN_PARENT,
            $content,
            $matches
        );
        if (!empty($matches[1])) {
            $data['parents'] = array_map('trim', $matches[1]);
        }

        // Extract required properties
        preg_match_all(
            self::PATTERN_REQUIRED,
            $content,
            $matches
        );
        if (!empty($matches[1])) {
            $data['properties']['required'] = array_map('trim', $matches[1]);
        }

        // Extract optional properties
        preg_match_all(
            self::PATTERN_OPTIONAL,
            $content,
            $matches
        );
        if (!empty($matches[1])) {
            $data['properties']['optional'] = array_map('trim', $matches[1]);
        }

        // Extract display sections from {{#subobject:display_section_*}}
        $sections = [];
        // Match each subobject block
        preg_match_all(
            self::PATTERN_SUBOBJECT,
            $content,
            $subobjectMatches,
            PREG_SET_ORDER
        );

        foreach ($subobjectMatches as $match) {
            $sectionContent = $match[2];
            $section = [];

            // Extract section name
            if (preg_match('/\|Has display section name=([^\|\}]+)/', $sectionContent, $nameMatch)) {
                $section['name'] = trim($nameMatch[1]);
            }

            // Extract section properties
            preg_match_all(
                '/\|Has display section property=Property:([^\|\}]+)/',
                $sectionContent,
                $propMatches
            );
            if (!empty($propMatches[1])) {
                $section['properties'] = array_map('trim', $propMatches[1]);
            }

            if (!empty($section['name']) && !empty($section['properties'])) {
                $sections[] = $section;
            }
        }

        if (!empty($sections)) {
            $data['display']['sections'] = $sections;
        }

        // Description extraction unchanged
        $data['label'] = $categoryName;
        $data['description'] = $this->extractDescription($content);

        return $data;
    }

    /**
     * Extract description from semantic property [[Has description::...]]
     */
    private function extractDescription(string $content): string
    {
        // Extract from semantic property [[Has description::...]]
        if (preg_match('/\[\[Has description::([^\|\]]+)/i', $content, $matches)) {
            return trim($matches[1]);
        }
        
        return '';
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
