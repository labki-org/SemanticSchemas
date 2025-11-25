<?php

namespace MediaWiki\Extension\StructureSync\Store;

use MediaWiki\Extension\StructureSync\Schema\PropertyModel;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * WikiPropertyStore
 * ------------------
 * Responsible for reading/writing SMW Property: pages and reconstructing
 * PropertyModel objects from SMW semantic data.
 * 
 * Reading Strategy:
 * -----------------
 * Queries Semantic MediaWiki's data store directly for property metadata.
 * Display templates are still parsed from wikitext since they contain
 * actual template content (not semantic data).
 * 
 * Writing Strategy:
 * -----------------
 * Property metadata is written within HTML comment markers:
 * <!-- StructureSync Start -->
 * ...semantic annotations...
 * <!-- StructureSync End -->
 * 
 * Semantic Properties Read:
 * - Has type (→ datatype)
 * - Display label (→ label)
 * - Allows value (→ allowedValues array)
 * - Has domain and range (→ rangeCategory)
 * - Subproperty of (→ subpropertyOf)
 * - Allows value from category (→ allowedCategory)
 * - Allows value from namespace (→ allowedNamespace)
 * - Has description (→ description)
 * - Has display pattern (→ displayFromProperty)
 * - Allows multiple values (→ allowsMultipleValues)
 * 
 * Semantic Annotations Written:
 * - [[Has type::DataType]]
 * - [[Display label::Label]]
 * - [[Allows value::EnumValue]]
 * - [[Has domain and range::Category:CategoryName]]
 * - [[Subproperty of::PropertyName]]
 * - [[Allows value from category::CategoryName]]
 * - [[Allows value from namespace::NamespaceName]]
 * - [[Has description::...]]
 * - [[Has display pattern::Property:...]]
 * - [[Allows multiple values::true]]
 *
 * Features:
 * - Normalizes property names (underscores to spaces)
 * - Ensures consistent metadata keys exist (rangeCategory/subpropertyOf)
 * - Adds StructureSync markers for hashing and dirty detection
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

        // Query SMW for semantic data instead of parsing wikitext
        $data = $this->queryPropertyFromSMW($title, $canonical);

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
     * QUERY PROPERTY FROM SMW
     * --------------------------------------------------------------------- */

    /**
     * Query property metadata from SMW
     *
     * Reads semantic properties directly from SMW store instead of parsing wikitext.
     *
     * @param \MediaWiki\Title\Title $title Property title
     * @param string $canonical Canonical property name
     * @return array Property data array
     */
    private function queryPropertyFromSMW(\MediaWiki\Title\Title $title, string $canonical): array
    {
        $data = [];

        // Get SMW semantic data for this property page
        $store = \SMW\StoreFactory::getStore();
        $subject = \SMW\DIWikiPage::newFromTitle($title);
        $semanticData = $store->getSemanticData($subject);

        /* Datatype ------------------------------------------------------ */
        $types = $this->getSMWPropertyValues($semanticData, 'Has type', 'text');
        if (!empty($types)) {
            $data['datatype'] = $types[0];
        }

        /* Allowed values ------------------------------------------------ */
        $data['allowedValues'] = $this->getSMWPropertyValues($semanticData, 'Allows value', 'text');

        /* Range category ------------------------------------------------ */
        $ranges = $this->getSMWPropertyValues($semanticData, 'Has domain and range', 'category');
        if (!empty($ranges)) {
            $data['rangeCategory'] = $ranges[0];
        }

        /* Subproperty --------------------------------------------------- */
        $subprops = $this->getSMWPropertyValues($semanticData, 'Subproperty of', 'property');
        if (!empty($subprops)) {
            $data['subpropertyOf'] = $subprops[0];
        }

        /* Display label ------------------------------------------------- */
        $labels = $this->getSMWPropertyValues($semanticData, 'Display label', 'text');
        if (!empty($labels)) {
            $data['label'] = $labels[0];
        }

        /* Display pattern (property-to-property template reference) ----- */
        $patterns = $this->getSMWPropertyValues($semanticData, 'Has display pattern', 'property');
        if (!empty($patterns)) {
            $data['displayFromProperty'] = $patterns[0];
        }

        /* Autocomplete sources (category) ------------------------------- */
        // Try both property names
        $allowedCats = $this->getSMWPropertyValues($semanticData, 'Allows value from category', 'text');
        if (empty($allowedCats)) {
            $allowedCats = $this->getSMWPropertyValues($semanticData, 'Allows value from', 'category');
        }
        if (!empty($allowedCats)) {
            $data['allowedCategory'] = $allowedCats[0];
        }

        /* Autocomplete sources (namespace) ------------------------------ */
        $allowedNS = $this->getSMWPropertyValues($semanticData, 'Allows value from namespace', 'text');
        if (!empty($allowedNS)) {
            $data['allowedNamespace'] = $allowedNS[0];
        }

        /* Description --------------------------------------------------- */
        $descriptions = $this->getSMWPropertyValues($semanticData, 'Has description', 'text');
        if (!empty($descriptions)) {
            $data['description'] = $descriptions[0];
        }

        /* Allows multiple values ---------------------------------------- */
        $multiValues = $this->getSMWPropertyValues($semanticData, 'Allows multiple values', 'text');
        if (!empty($multiValues) && in_array(strtolower($multiValues[0]), ['true', 'yes', '1'])) {
            $data['allowsMultipleValues'] = true;
        }

        /* Display block (new wiki-editable templates) ------------------- */
        // For display template, we still need to read from wikitext
        // as it contains actual template content, not just semantic annotations
        $content = $this->pageCreator->getPageContent($title);
        if ($content !== null) {
            $displayData = $this->parseDisplayBlock($content);
            if (!empty($displayData)) {
                $data['display'] = $displayData;
                // If displayFromProperty is in the Display block, extract it
                if (isset($displayData['fromProperty'])) {
                    $data['displayFromProperty'] = $displayData['fromProperty'];
                }
            }
        }

        return $data;
    }

    /**
     * Get property values from SMW semantic data
     *
     * @param \SMW\SemanticData $semanticData
     * @param string $propertyName
     * @param string $type Expected type: 'text', 'property', 'category', or 'page'
     * @return array
     */
    private function getSMWPropertyValues(\SMW\SemanticData $semanticData, string $propertyName, string $type = 'text'): array
    {
        try {
            $property = \SMW\DIProperty::newFromUserLabel($propertyName);
            $propertyValues = $semanticData->getPropertyValues($property);

            $values = [];
            foreach ($propertyValues as $dataItem) {
                $value = $this->extractSMWValue($dataItem, $type);
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
     * Extract value from SMW DataItem
     *
     * @param \SMWDataItem $dataItem
     * @param string $type Expected type
     * @return string|null
     */
    private function extractSMWValue(\SMWDataItem $dataItem, string $type): ?string
    {
        if ($dataItem instanceof \SMW\DIWikiPage) {
            $title = $dataItem->getTitle();
            if ($title === null) {
                return null;
            }

            switch ($type) {
                case 'property':
                    return $title->getNamespace() === \SMW_NS_PROPERTY 
                        ? $title->getText() 
                        : null;
                
                case 'category':
                    return $title->getNamespace() === NS_CATEGORY 
                        ? $title->getText() 
                        : null;
                
                case 'page':
                    return $title->getPrefixedText();
                
                default:
                    return $title->getText();
            }
        } elseif ($dataItem instanceof \SMWDIBlob) {
            return $dataItem->getString();
        } elseif ($dataItem instanceof \SMWDIString) {
            return $dataItem->getString();
        }

        return null;
    }

    /* ---------------------------------------------------------------------
     * DISPLAY TEMPLATE PARSING
     * 
     * Note: Display templates contain actual wikitext content (not semantic data),
     * so they must still be parsed from the page source. This is the only part
     * that still requires wikitext parsing.
     * --------------------------------------------------------------------- */

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
