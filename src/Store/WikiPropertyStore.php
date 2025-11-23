<?php

namespace MediaWiki\Extension\StructureSync\Store;

use MediaWiki\Extension\StructureSync\Schema\PropertyModel;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * WikiPropertyStore
 * ------------------
 * Responsible for reading/writing SMW Property: pages and reconstructing
 * PropertyModel objects from raw content.
 * 
 * Storage Format:
 * --------------
 * Property metadata is stored within HTML comment markers:
 * <!-- StructureSync Start -->
 * ...metadata and semantic annotations...
 * <!-- StructureSync End -->
 * 
 * Semantic Annotations Used:
 * - [[Has type::DataType]]
 * - [[Display label::Label]]
 * - [[Allows value::EnumValue]]
 * - [[Has domain and range::Category:CategoryName]]
 * - [[Subproperty of::PropertyName]]
 * - [[Allows value from category::CategoryName]]
 * - [[Allows value from namespace::NamespaceName]]
 *
 * Features:
 *   - Description extraction ignores headings (= ... =)
 *   - Ensures consistent metadata keys exist (rangeCategory/subpropertyOf)
 *   - Adds StructureSync markers for hashing and dirty detection
 *   - Normalizes property names (underscores to spaces)
 *   - Supports both PageForms and SMW canonical syntax
 */
class WikiPropertyStore
{

    /** Schema content markers - must match PageHashComputer and WikiCategoryStore */
    private const MARKER_START = '<!-- StructureSync Start -->';
    private const MARKER_END = '<!-- StructureSync End -->';

    /** @var PageCreator */
    private $pageCreator;

    public function __construct(PageCreator $pageCreator = null)
    {
        $this->pageCreator = $pageCreator ?? new PageCreator();
    }

    /* ---------------------------------------------------------------------
     * READ PROPERTY
     * --------------------------------------------------------------------- */

    public function readProperty(string $propertyName): ?PropertyModel
    {

        $canonical = $this->normalizePropertyName($propertyName);

        $title = $this->pageCreator->makeTitle($canonical, \SMW_NS_PROPERTY);
        if ($title === null || !$this->pageCreator->pageExists($title)) {
            return null;
        }

        $content = $this->pageCreator->getPageContent($title);
        if ($content === null) {
            return null;
        }

        $data = $this->parsePropertyContent($content);

        // Ensure existence of keys
        $data += [
            'datatype' => null,
            'allowedValues' => [],
            'rangeCategory' => null,
            'subpropertyOf' => null,
        ];

        if (!isset($data['label'])) {
            $data['label'] = $canonical;
        }

        return new PropertyModel($canonical, $data);
    }

    private function normalizePropertyName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/^Property:/i', '', $name);
        return str_replace('_', ' ', $name);
    }

    /* ---------------------------------------------------------------------
     * PARSE PROPERTY CONTENT
     * --------------------------------------------------------------------- */

    private function parsePropertyContent(string $content): array
    {

        $data = [];

        /* Datatype ------------------------------------------------------ */
        if (preg_match('/\[\[Has type::([^\|\]]+)/i', $content, $m)) {
            $data['datatype'] = trim($m[1]);
        }

        /* Allowed values ------------------------------------------------ */
        preg_match_all('/\[\[Allows value::([^\|\]]+)/i', $content, $m);
        if (!empty($m[1])) {
            $data['allowedValues'] = array_values(
                array_unique(array_map('trim', $m[1]))
            );
        }

        /* Range category ------------------------------------------------ */
        if (
            preg_match(
                '/\[\[Has domain and range::Category:([^\|\]]+)/i',
                $content,
                $m
            )
        ) {
            $data['rangeCategory'] = trim($m[1]);
        }

        /* Subproperty --------------------------------------------------- */
        if (preg_match('/\[\[Subproperty of::([^\|\]]+)/i', $content, $m)) {
            $data['subpropertyOf'] = trim(str_replace('_', ' ', $m[1]));
        }

        /* Display label ------------------------------------------------- */
        if (preg_match('/\[\[Display label::([^\|\]]+)/i', $content, $m)) {
            $data['label'] = trim($m[1]);
        }

        /* Display pattern (property-to-property template reference) ----- */
        // Parse SMW annotation format: [[Has display pattern::Property:Email]]
        // This is stored as displayFromProperty in the schema
        if (preg_match('/\[\[Has display pattern::Property:([^\|\]]+)/i', $content, $m)) {
            $data['displayFromProperty'] = trim($m[1]);
        }

        /* Autocomplete sources (category) ------------------------------- */
        // Support both PageForms and SMW canonical syntax:
        // [[Allows value from category::Department]]
        // [[Allows value from::Category:Department]]
        if (preg_match('/\[\[Allows value from category::([^\|\]]+)/i', $content, $m)) {
            // PageForms syntax: extract category name directly
            $data['allowedCategory'] = trim($m[1]);
        } elseif (preg_match('/\[\[Allows value from::Category:([^\|\]]+)/i', $content, $m)) {
            // SMW canonical syntax: strip "Category:" prefix
            $data['allowedCategory'] = trim($m[1]);
        }

        /* Autocomplete sources (namespace) ------------------------------ */
        // Support both PageForms and SMW canonical syntax:
        // [[Allows value from namespace::Main]]
        // [[Allows value from::Namespace:Main]]
        if (preg_match('/\[\[Allows value from namespace::([^\|\]]+)/i', $content, $m)) {
            // PageForms syntax: extract namespace name directly
            $data['allowedNamespace'] = trim($m[1]);
        } elseif (preg_match('/\[\[Allows value from::Namespace:([^\|\]]+)/i', $content, $m)) {
            // SMW canonical syntax: strip "Namespace:" prefix
            $data['allowedNamespace'] = trim($m[1]);
        }

        /* Description --------------------------------------------------- */
        // Extract from semantic property [[Has description::...]]
        if (preg_match('/\[\[Has description::([^\|\]]+)/i', $content, $m)) {
            $data['description'] = trim($m[1]);
        }

        /* Allows multiple values ---------------------------------------- */
        // Extract from semantic property [[Allows multiple values::true]]
        if (preg_match('/\[\[Allows multiple values::(true|yes|1)\]\]/i', $content, $m)) {
            $data['allowsMultipleValues'] = true;
        }

        /* Display block (new wiki-editable templates) ------------------- */
        $displayData = $this->parseDisplayBlock($content);
        if (!empty($displayData)) {
            $data['display'] = $displayData;
            // If displayFromProperty is in the Display block, extract it
            if (isset($displayData['fromProperty'])) {
                $data['displayFromProperty'] = $displayData['fromProperty'];
            }
        }

        return $data;
    }

    /**
     * Parse the Display block within StructureSync markers
     * 
     * Returns array with:
     *   'template' => wikitext template string (or null)
     *   'type' => display type reference (or null)
     *   'fromProperty' => property name for pattern reference (or null)
     */
    private function parseDisplayBlock(string $content): array
    {
        $blockContent = $this->extractDisplaySection($content, self::MARKER_START, self::MARKER_END);
        
        if ($blockContent === '') {
            return [];
        }

        $result = [];

        // Extract display template section
        // Look for === Display template === followed by content until next === or end
        if (
            preg_match(
                '/===\s*Display template\s*===\s*\n(.*?)(?=(?:\n===|$))/s',
                $blockContent,
                $matches
            )
        ) {
            $template = trim($matches[1]);
            if ($template !== '') {
                // Handle {{#tag:pre|...}} wrapper if present
                if (preg_match('/\{\{#tag:pre\|(.*)\}\}/s', $template, $tagMatch)) {
                    $result['template'] = trim($tagMatch[1]);
                } else {
                    $result['template'] = $template;
                }
            }
        }

        // Extract display type section
        if (
            preg_match(
                '/===\s*Display type\s*===\s*\n([^\n]+)/i',
                $blockContent,
                $matches
            )
        ) {
            $type = trim($matches[1]);
            if ($type !== '' && strtolower($type) !== 'none') {
                $result['type'] = $type;
            }
        }

        // Extract display from property section (property-to-property template reference)
        if (
            preg_match(
                '/===\s*Display from property\s*===\s*\n([^\n]+)/i',
                $blockContent,
                $matches
            )
        ) {
            $fromProperty = trim($matches[1]);
            if ($fromProperty !== '') {
                // Remove "Property:" prefix if present
                $fromProperty = preg_replace('/^Property:/i', '', $fromProperty);
                $result['fromProperty'] = $fromProperty;
            }
        }

        return $result;
    }

    /**
     * Extract content between markers for display parsing
     *
     * @param string $content Full page content
     * @param string $startMarker Start marker
     * @param string $endMarker End marker
     * @return string Content between markers, or empty string
     */
    private function extractDisplaySection(string $content, string $startMarker, string $endMarker): string
    {
        $startPos = strpos($content, $startMarker);
        $endPos = strpos($content, $endMarker);

        if ($startPos === false || $endPos === false || $endPos <= $startPos) {
            return '';
        }

        $blockStart = $startPos + strlen($startMarker);
        return substr($content, $blockStart, $endPos - $blockStart);
    }

    /* ---------------------------------------------------------------------
     * WRITE PROPERTY PAGE CONTENT
     * --------------------------------------------------------------------- */

    public function writeProperty(PropertyModel $property): bool
    {

        $title = $this->pageCreator->makeTitle($property->getName(), \SMW_NS_PROPERTY);
        if ($title === null) {
            return false;
        }

        $existingContent = $this->pageCreator->getPageContent($title) ?? '';

        $schemaBlock = $this->generatePropertySchemaBlock($property);

        $newContent = $this->pageCreator->updateWithinMarkers(
            $existingContent,
            $schemaBlock,
            self::MARKER_START,
            self::MARKER_END
        );

        // Tracking category
        if (strpos($newContent, '[[Category:StructureSync-managed-property]]') === false) {
            $newContent .= "\n[[Category:StructureSync-managed-property]]";
        }

        return $this->pageCreator->createOrUpdatePage(
            $title,
            $newContent,
            "StructureSync: Update property metadata"
        );
    }

    /**
     * Generate ONLY the metadata block inserted inside StructureSync markers
     */
    private function generatePropertySchemaBlock(PropertyModel $property): string
    {

        $lines = [];

        // Datatype
        $lines[] = '[[Has type::' . $property->getSMWType() . ']]';

        // Description
        if ($property->getDescription() !== '') {
            $lines[] = '[[Has description::' . $property->getDescription() . ']]';
        }

        // Allows multiple values
        if ($property->allowsMultipleValues()) {
            $lines[] = '[[Allows multiple values::true]]';
        }

        // Display label (only if different from property name)
        if ($property->getLabel() !== $property->getName()) {
            $lines[] = '[[Display label::' . $property->getLabel() . ']]';
        }

        // Allowed values
        if ($property->hasAllowedValues()) {
            $lines[] = '';
            $lines[] = '== Allowed values ==';
            foreach ($property->getAllowedValues() as $v) {
                $v = str_replace('|', ' ', $v);
                $lines[] = "* [[Allows value::$v]]";
            }
        }

        // Range category
        if ($property->getRangeCategory() !== null) {
            $lines[] = '';
            $lines[] = '[[Has domain and range::Category:' .
                $property->getRangeCategory() . ']]';
        }

        // Subproperty
        if ($property->getSubpropertyOf() !== null) {
            $lines[] = '';
            $lines[] = '[[Subproperty of::' .
                $property->getSubpropertyOf() . ']]';
        }

        return implode("\n", $lines);
    }

    /* ---------------------------------------------------------------------
     * LIST + EXISTENCE
     * --------------------------------------------------------------------- */

    public function getAllProperties(): array
    {

        $properties = [];

        $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbr = $lb->getConnection(DB_REPLICA);

        $res = $dbr->newSelectQueryBuilder()
            ->select('page_title')
            ->from('page')
            ->where(['page_namespace' => \SMW_NS_PROPERTY])
            ->caller(__METHOD__)
            ->fetchResultSet();

        foreach ($res as $row) {
            $name = str_replace('_', ' ', $row->page_title);
            $prop = $this->readProperty($name);
            if ($prop !== null) {
                $properties[$name] = $prop;
            }
        }

        return $properties;
    }

    public function propertyExists(string $propertyName): bool
    {
        $canonical = $this->normalizePropertyName($propertyName);
        $title = $this->pageCreator->makeTitle($canonical, \SMW_NS_PROPERTY);
        return $title !== null && $this->pageCreator->pageExists($title);
    }
}
