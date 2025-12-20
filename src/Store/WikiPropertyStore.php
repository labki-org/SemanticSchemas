<?php

namespace MediaWiki\Extension\SemanticSchemas\Store;

use MediaWiki\Extension\SemanticSchemas\Schema\PropertyModel;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * WikiPropertyStore
 * ------------------
 * Reads and writes Property: pages as PropertyModel objects.
 *
 * Fully semantic: NO wikitext parsing.
 */
class WikiPropertyStore
{

    private const MARKER_START = '<!-- SemanticSchemas Start -->';
    private const MARKER_END = '<!-- SemanticSchemas End -->';

    private PageCreator $pageCreator;

    public function __construct(PageCreator $pageCreator = null)
    {
        $this->pageCreator = $pageCreator ?? new PageCreator();
    }

    /* -------------------------------------------------------------------------
     * PUBLIC API — READ
     * ------------------------------------------------------------------------- */

    public function readProperty(string $propertyName): ?PropertyModel
    {

        $canonical = $this->canonicalize($propertyName);

        $title = $this->pageCreator->makeTitle($canonical, SMW_NS_PROPERTY);
        if (!$title || !$this->pageCreator->pageExists($title)) {
            return null;
        }

        $data = $this->loadFromSMW($title);

        // Ensure canonical minimal fields
        $data += [
            'datatype' => 'Text',
            'label' => $canonical,
            'description' => '',
            'allowedValues' => [],
            'rangeCategory' => null,
            'subpropertyOf' => null,
            'allowedCategory' => null,
            'allowedNamespace' => null,
            'allowsMultipleValues' => false,
            'hasTemplate' => null,
        ];

        return new PropertyModel($canonical, $data);
    }

    /* -------------------------------------------------------------------------
     * PUBLIC API — WRITE
     * ------------------------------------------------------------------------- */

    public function writeProperty(PropertyModel $property): bool
    {

        $title = $this->pageCreator->makeTitle($property->getName(), SMW_NS_PROPERTY);
        if (!$title) {
            return false;
        }

        $existing = $this->pageCreator->getPageContent($title) ?? '';

        $semanticBlock = $this->buildSemanticBlock($property);

        $newContent = $this->pageCreator->updateWithinMarkers(
            $existing,
            $semanticBlock,
            self::MARKER_START,
            self::MARKER_END
        );

        if (!str_contains($newContent, '[[Category:SemanticSchemas-managed-property]]')) {
            $newContent .= "\n[[Category:SemanticSchemas-managed-property]]";
        }

        return $this->pageCreator->createOrUpdatePage(
            $title,
            $newContent,
            "SemanticSchemas: Update property metadata"
        );
    }

    public function propertyExists(string $propertyName): bool
    {
        $canonical = $this->canonicalize($propertyName);
        $t = $this->pageCreator->makeTitle($canonical, SMW_NS_PROPERTY);
        return $t && $this->pageCreator->pageExists($t);
    }

    public function getAllProperties(): array
    {

        $out = [];

        $dbr = MediaWikiServices::getInstance()
            ->getDBLoadBalancer()
            ->getConnection(DB_REPLICA);

        $res = $dbr->newSelectQueryBuilder()
            ->select('page_title')
            ->from('page')
            ->where(['page_namespace' => SMW_NS_PROPERTY])
            ->caller(__METHOD__)
            ->fetchResultSet();

        foreach ($res as $row) {
            $name = str_replace('_', ' ', $row->page_title);

            $pm = $this->readProperty($name);
            if ($pm) {
                $out[$name] = $pm;
            }
        }

        return $out;
    }

    /* -------------------------------------------------------------------------
     * INTERNAL — LOAD FROM SMW
     * ------------------------------------------------------------------------- */

    private function loadFromSMW(Title $title): array
    {

        $store = \SMW\StoreFactory::getStore();
        $subject = \SMW\DIWikiPage::newFromTitle($title);
        $sdata = $store->getSemanticData($subject);

        $out = [];

        // Get datatype from SMW's internal property type API
        // Note: SMW stores datatypes internally, not as semantic annotations
        try {
            $prop = \SMW\DIProperty::newFromUserLabel($title->getText());
            if ($prop !== null) {
                $internalTypeId = $prop->findPropertyTypeID();
                if ($internalTypeId !== null) {
                    $out['datatype'] = $this->convertSMWTypeIdToCanonical($internalTypeId);
                }
            }
        } catch (\Throwable $e) {
            // If property creation fails, datatype will default to 'Text' in readProperty()
        }

        $out['label'] = $this->fetchOne($sdata, 'Display label');
        $out['description'] = $this->fetchOne($sdata, 'Has description');

        $out['allowedValues'] =
            $this->fetchMany($sdata, 'Allows value', 'text');

        $out['rangeCategory'] =
            $this->fetchOne($sdata, 'Has domain and range', 'category');

        $out['subpropertyOf'] =
            $this->fetchOne($sdata, 'Subproperty of', 'property');

        $out['allowedCategory'] =
            $this->fetchOne($sdata, 'Allows value from category', 'text');

        $out['allowedNamespace'] =
            $this->fetchOne($sdata, 'Allows value from namespace', 'text');

        $out['allowsMultipleValues'] =
            $this->fetchBoolean($sdata, 'Allows multiple values');

        /* -------------------- Template Configuration -------------------- */
        $hasTemplate = $this->fetchOne($sdata, 'Has template', 'page');

        if ($hasTemplate) {
            $out['hasTemplate'] = $hasTemplate;
            $out['templateSource'] = $hasTemplate;
        }


        // Clean null/empty

        // Clean null/empty
        return array_filter(
            $out,
            fn($v) => $v !== null && $v !== []
        );
    }

    private function fetchOne($sd, string $p, string $type = 'text'): ?string
    {
        $vals = $this->fetchMany($sd, $p, $type);
        return $vals[0] ?? null;
    }

    private function fetchBoolean($sd, string $prop): bool
    {

        try {
            $p = \SMW\DIProperty::newFromUserLabel($prop);
            $items = $sd->getPropertyValues($p);
        } catch (\Throwable $e) {
            return false;
        }

        foreach ($items as $di) {
            if ($di instanceof \SMWDIBoolean) {
                return $di->getBoolean();
            }
            if ($di instanceof \SMWDINumber) {
                return $di->getNumber() > 0;
            }
            if ($di instanceof \SMWDIBlob || $di instanceof \SMWDIString) {
                $v = strtolower(trim($di->getString()));
                if (in_array($v, ['1', 'true', 'yes', 'y', 't'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function fetchMany($sd, string $p, string $type = 'text'): array
    {

        try {
            $prop = \SMW\DIProperty::newFromUserLabel($p);
            $items = $sd->getPropertyValues($prop);
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];

        foreach ($items as $di) {
            $v = $this->extractValue($di, $type);
            if ($v !== null) {
                $out[] = $v;
            }
        }

        return $out;
    }

    private function extractValue($di, string $type): ?string
    {

        if ($di instanceof \SMW\DIWikiPage) {
            $t = $di->getTitle();
            if (!$t) {
                return null;
            }

            $text = str_replace('_', ' ', $t->getText());

            switch ($type) {
                case 'property':
                    return ($t->getNamespace() === 102) ? $text : null;
                case 'category':
                    return ($t->getNamespace() === 14) ? $text : null;
                case 'page':
                    return $t->getPrefixedText();
                default:
                    return $text;
            }
        }

        if ($di instanceof \SMWDIBlob || $di instanceof \SMWDIString) {
            return trim($di->getString());
        }

        return null;
    }

    /* -------------------------------------------------------------------------
     * INTERNAL — WRITE SEMANTIC BLOCK
     * ------------------------------------------------------------------------- */

    private function buildSemanticBlock(PropertyModel $p): string
    {

        $lines = [];

        // Datatype (required)
        $lines[] = '[[Has type::' . $p->getSMWType() . ']]';

        if ($p->getDescription() !== '') {
            $lines[] = '[[Has description::' . $p->getDescription() . ']]';
        }

        if ($p->allowsMultipleValues()) {
            $lines[] = '[[Allows multiple values::true]]';
        }

        // Display label only if differs from canonical name
        if ($p->getLabel() !== $p->getName()) {
            $lines[] = '[[Display label::' . $p->getLabel() . ']]';
        }

        foreach ($p->getAllowedValues() as $v) {
            $lines[] = '[[Allows value::' . str_replace('|', ' ', $v) . ']]';
        }

        if ($p->getRangeCategory() !== null) {
            $lines[] = '[[Has domain and range::Category:' . $p->getRangeCategory() . ']]';
        }

        if ($p->getSubpropertyOf() !== null) {
            $lines[] = '[[Subproperty of::' . $p->getSubpropertyOf() . ']]';
        }

        if ($p->getAllowedCategory() !== null) {
            $lines[] = '[[Allows value from category::' . $p->getAllowedCategory() . ']]';
        }

        if ($p->getAllowedNamespace() !== null) {
            $lines[] = '[[Allows value from namespace::' . $p->getAllowedNamespace() . ']]';
        }

        // Template reference (or source)
        if ($p->getTemplateSource() !== null) {
            $lines[] = '[[Has template::' . $p->getTemplateSource() . ']]';
        } elseif ($p->getHasTemplate() !== null) {
            $lines[] = '[[Has template::' . $p->getHasTemplate() . ']]';
        }

        return implode("\n", $lines);
    }

    /* -------------------------------------------------------------------------
     * TYPE ID CONVERSION
     * ------------------------------------------------------------------------- */

    /**
     * Convert SMW's internal type ID (e.g., '_txt', '_wpg') to canonical datatype name.
     *
     * @param string $typeId SMW internal type ID
     * @return string Canonical datatype name
     */
    private function convertSMWTypeIdToCanonical(string $typeId): string
    {
        // Mapping from SMW internal type IDs to canonical names
        static $typeMap = [
        '_txt' => 'Text',
        '_wpg' => 'Page',
        '_dat' => 'Date',
        '_num' => 'Number',
        '_boo' => 'Boolean',
        '_uri' => 'URL',
        '_ema' => 'Email',
        '_tel' => 'Telephone number',
        '_cod' => 'Code',
        '_geo' => 'Geographic coordinate',
        '_qty' => 'Quantity',
        '_tem' => 'Temperature',
        '_anu' => 'Annotation URI',
        '_eid' => 'External identifier',
        '_key' => 'Keyword',
        '_mlt_rec' => 'Monolingual text',
        '_rec' => 'Record',
        '_ref_rec' => 'Reference',
        ];

        // If it's already a canonical name, return as-is
        if (substr($typeId, 0, 1) !== '_') {
            return $typeId;
        }

        return $typeMap[$typeId] ?? 'Text';
    }

    /* -------------------------------------------------------------------------
     * CANONICALIZATION
     * ------------------------------------------------------------------------- */

    private function canonicalize(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/^Property:/i', '', $name);
        return str_replace('_', ' ', $name);
    }
}
