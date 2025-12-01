<?php

namespace MediaWiki\Extension\StructureSync\Store;

use MediaWiki\Extension\StructureSync\Schema\CategoryModel;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * WikiCategoryStore
 * -----------------
 * Reads/writes Category pages as CategoryModel objects.
 *
 * Fully symmetric with CategoryModel->toArray() structure.
 */
class WikiCategoryStore {

    private const MARKER_START = '<!-- StructureSync Start -->';
    private const MARKER_END   = '<!-- StructureSync End -->';

    private PageCreator $pageCreator;

    public function __construct(PageCreator $pageCreator = null) {
        $this->pageCreator = $pageCreator ?? new PageCreator();
    }

    /* -------------------------------------------------------------------------
     * READ PUBLIC
     * ------------------------------------------------------------------------- */

    public function readCategory(string $categoryName): ?CategoryModel {

        $title = $this->pageCreator->makeTitle($categoryName, NS_CATEGORY);
        if (!$title || !$this->pageCreator->pageExists($title)) {
            return null;
        }

        $data = $this->loadFromSMW($title, $categoryName);
        return new CategoryModel($categoryName, $data);
    }

    /* -------------------------------------------------------------------------
     * WRITE PUBLIC
     * ------------------------------------------------------------------------- */

    public function writeCategory(CategoryModel $category): bool {

        $title = $this->pageCreator->makeTitle($category->getName(), NS_CATEGORY);
        if (!$title) {
            return false;
        }

        $existing = $this->pageCreator->getPageContent($title) ?? '';

        $metadata = $this->generateSemanticBlock($category);

        $newContent = $this->pageCreator->updateWithinMarkers(
            $existing,
            $metadata,
            self::MARKER_START,
            self::MARKER_END
        );

        if (!str_contains($newContent, '[[Category:StructureSync-managed]]')) {
            $newContent .= "\n[[Category:StructureSync-managed]]";
        }

        return $this->pageCreator->createOrUpdatePage(
            $title,
            $newContent,
            'StructureSync: Update category schema metadata'
        );
    }

    /* -------------------------------------------------------------------------
     * ENUMERATION
     * ------------------------------------------------------------------------- */

    public function getAllCategories(): array {

        $out = [];

        $dbr = MediaWikiServices::getInstance()
            ->getDBLoadBalancer()
            ->getConnection(DB_REPLICA);

        $res = $dbr->newSelectQueryBuilder()
            ->select('page_title')
            ->from('page')
            ->where(['page_namespace' => NS_CATEGORY])
            ->caller(__METHOD__)
            ->fetchResultSet();

        foreach ($res as $row) {
            $name = str_replace('_', ' ', $row->page_title);
            $cat = $this->readCategory($name);
            if ($cat) {
                $out[$name] = $cat;
            }
        }

        return $out;
    }

    public function categoryExists(string $categoryName): bool {
        $t = $this->pageCreator->makeTitle($categoryName, NS_CATEGORY);
        return $t && $this->pageCreator->pageExists($t);
    }

    /* -------------------------------------------------------------------------
     * SMW LOADING
     * ------------------------------------------------------------------------- */

    private function loadFromSMW(Title $title, string $categoryName): array {

        $store   = \SMW\StoreFactory::getStore();
        $subject = \SMW\DIWikiPage::newFromTitle($title);
        $sdata   = $store->getSemanticData($subject);

        return [
            'label'           => $this->fetchOne($sdata, 'Display label') ?? $categoryName,
            'description'     => $this->fetchOne($sdata, 'Has description') ?? '',
            'targetNamespace' => $this->fetchOne($sdata, 'Has target namespace') ?? null,

            'parents'     => $this->fetchList($sdata, 'Has parent category', 'category'),

            'properties'  => [
                'required' => $this->fetchList($sdata, 'Has required property', 'property'),
                'optional' => $this->fetchList($sdata, 'Has optional property', 'property'),
            ],

            'subgroups' => [
                'required' => $this->fetchList($sdata, 'Has required subgroup', 'subobject'),
                'optional' => $this->fetchList($sdata, 'Has optional subgroup', 'subobject'),
            ],

            'display' => $this->loadDisplayConfig($sdata),
        ];
    }

    private function loadDisplayConfig($semanticData): array {

        $header = $this->fetchList($semanticData, 'Has display header property', 'property');
        $sections = $this->fetchDisplaySections($semanticData);

        $out = [];
        if ($header !== []) {
            $out['header'] = $header;
        }
        if ($sections !== []) {
            $out['sections'] = $sections;
        }

        return $out;
    }

    /* -------------------------------------------------------------------------
     * SMW extraction helpers
     * ------------------------------------------------------------------------- */

    private function fetchOne($semanticData, string $propName): ?string {
        $vals = $this->fetchList($semanticData, $propName, 'text');
        return $vals[0] ?? null;
    }

    private function fetchList($semanticData, string $propName, string $type): array {

        try {
            $prop = \SMW\DIProperty::newFromUserLabel($propName);
            $items = $semanticData->getPropertyValues($prop);
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

    private function extractValue($di, string $type): ?string {

        if ($di instanceof \SMWDIBlob || $di instanceof \SMWDIString) {
            return trim($di->getString());
        }

        if ($di instanceof \SMW\DIWikiPage) {
            $t = $di->getTitle();
            if (!$t) {
                return null;
            }

            $text = str_replace('_', ' ', $t->getText());

            switch ($type) {
                case 'property':
                    return $t->getNamespace() === SMW_NS_PROPERTY ? $text : null;

                case 'category':
                    return $t->getNamespace() === NS_CATEGORY ? $text : null;

                case 'subobject':
                    return $t->getNamespace() === NS_SUBOBJECT ? $text : null;

                case 'page':
                    return $t->getPrefixedText();

                default:
                    return $text;
            }
        }

        return null;
    }

    private function fetchDisplaySections($semanticData): array {

        $sections = [];

        foreach ($semanticData->getSubSemanticData() as $subSD) {

            $name = $subSD->getSubject()->getSubobjectName();
            if (!str_starts_with($name, 'display_section_')) {
                continue;
            }

            $secName = $this->fetchOne($subSD, 'Has display section name');
            $props   = $this->fetchList($subSD, 'Has display section property', 'property');

            if ($secName !== null && $props !== []) {
                $sections[] = [
                    'name' => $secName,
                    'properties' => $props,
                ];
            }
        }

        return $sections;
    }

    /* -------------------------------------------------------------------------
     * WRITE: semantic block
     * ------------------------------------------------------------------------- */

    private function generateSemanticBlock(CategoryModel $cat): string {

        $lines = [];

        if ($cat->getDescription() !== '') {
            $lines[] = '[[Has description::' . $cat->getDescription() . ']]';
        }

        if ($cat->getTargetNamespace() !== null) {
            $lines[] = '[[Has target namespace::' . $cat->getTargetNamespace() . ']]';
        }

        // Parent categories
        foreach ($cat->getParents() as $p) {
            $lines[] = "[[Has parent category::Category:$p]]";
        }

        // Header properties
        foreach ($cat->getDisplayHeaderProperties() as $h) {
            $lines[] = "[[Has display header property::Property:$h]]";
        }

        // Required/optional properties
        foreach ($cat->getRequiredProperties() as $prop) {
            $lines[] = "[[Has required property::Property:$prop]]";
        }

        foreach ($cat->getOptionalProperties() as $prop) {
            $lines[] = "[[Has optional property::Property:$prop]]";
        }

        // Subgroups
        foreach ($cat->getRequiredSubgroups() as $sg) {
            $lines[] = "[[Has required subgroup::Subobject:$sg]]";
        }

        foreach ($cat->getOptionalSubgroups() as $sg) {
            $lines[] = "[[Has optional subgroup::Subobject:$sg]]";
        }

        // Display sections
        foreach ($cat->getDisplaySections() as $i => $sec) {
            $lines[] = "{{#subobject:display_section_$i";
            $lines[] = "|Has display section name=" . $sec['name'];
            foreach ($sec['properties'] as $p) {
                $lines[] = "|Has display section property=Property:$p";
            }
            $lines[] = "}}";
        }

        return implode("\n", $lines);
    }
}
