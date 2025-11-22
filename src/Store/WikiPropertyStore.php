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
 * Fully corrected version:
 *   - Description extraction ignores headings (= ... =)
 *   - Ensures consistent metadata keys exist (rangeCategory/subpropertyOf)
 *   - Adds StructureSync markers for hashing and dirty detection
 *   - Normalizes property names
 */
class WikiPropertyStore
{

    private const MARKER_START = '<!-- StructureSync Schema Start -->';
    private const MARKER_END = '<!-- StructureSync Schema End -->';

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

        /* Display type (legacy annotation) ------------------------------ */
        if (preg_match('/\[\[Has display type::([^\|\]]+)/i', $content, $m)) {
            $data['displayType'] = trim($m[1]);
        }

        /* Display pattern (property-to-property template reference) ----- */
        if (preg_match('/\[\[Has display pattern::Property:([^\|\]]+)/i', $content, $m)) {
            $data['displayPattern'] = trim($m[1]);
        }

        /* Description --------------------------------------------------- */
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            $line = trim($line);

            if (
                $line !== '' &&
                !str_starts_with($line, '[[') &&
                !str_starts_with($line, '{{') &&
                !str_starts_with($line, '<!') &&
                !str_starts_with($line, '=')       // fix: ignore headings
            ) {
                $data['description'] = $line;
                break;
            }
        }

        /* Display block (new wiki-editable templates) ------------------- */
        $displayData = $this->parseDisplayBlock($content);
        if (!empty($displayData)) {
            $data['display'] = $displayData;
        }

        return $data;
    }

    /**
     * Parse the <!-- StructureSync Display Start/End --> block
     * 
     * Returns array with:
     *   'template' => wikitext template string (or null)
     *   'type' => display type reference (or null)
     */
    private function parseDisplayBlock(string $content): array
    {
        $start = '<!-- StructureSync Display Start -->';
        $end = '<!-- StructureSync Display End -->';

        // Find markers
        $startPos = strpos($content, $start);
        $endPos = strpos($content, $end);

        if ($startPos === false || $endPos === false || $endPos <= $startPos) {
            return [];
        }

        // Extract block content
        $blockStart = $startPos + strlen($start);
        $blockContent = substr($content, $blockStart, $endPos - $blockStart);

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

        return $result;
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

        if ($property->getDescription() !== '') {
            $lines[] = $property->getDescription();
            $lines[] = '';
        }

        // Datatype
        $lines[] = '[[Has type::' . $property->getSMWType() . ']]';

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
