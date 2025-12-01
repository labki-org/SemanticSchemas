<?php

namespace MediaWiki\Extension\StructureSync\Generator;

use MediaWiki\Extension\StructureSync\Schema\CategoryModel;
use MediaWiki\Extension\StructureSync\Store\PageCreator;
use MediaWiki\Extension\StructureSync\Store\WikiPropertyStore;
use MediaWiki\Extension\StructureSync\Util\NamingHelper;

/**
 * DisplayStubGenerator (Improved 2025)
 * -------------------------------------
 * Creates Template:<Category>/display, a *user-editable* display template.
 *
 * Design rules:
 *   - Created ONCE and never overwritten unless explicitly requested.
 *   - Rendering is delegated to StructureSync parser functions:
 *       {{#StructureSyncRenderAllProperties:Category}}
 *       {{#StructureSyncRenderSection:Category|SectionName}}
 *   - Stub also includes hierarchy UI:
 *       {{#structuresync_hierarchy:}}
 *
 * The stub stays tiny. The parser functions always reflect the current schema,
 * allowing schema updates without touching the template.
 */
class DisplayStubGenerator {

    private PageCreator $pageCreator;
    private WikiPropertyStore $propertyStore;

    public function __construct(
        ?PageCreator $pageCreator = null,
        ?WikiPropertyStore $propertyStore = null
    ) {
        $this->pageCreator   = $pageCreator   ?? new PageCreator();
        $this->propertyStore = $propertyStore ?? new WikiPropertyStore();
    }

    /* =====================================================================
     * INTERNAL UTILS
     * ===================================================================== */

    private function sanitize(?string $value): string {
        return $value ?? '';
    }

    private function makeDisplayTitle(string $categoryName) {
        return $this->pageCreator->makeTitle(
            $categoryName . '/display',
            NS_TEMPLATE
        );
    }

    /* =====================================================================
     * MAIN GENERATION
     * ===================================================================== */

    /**
     * Generates the *content* of the display template stub.
     *
     * IMPORTANT:
     *   This stub is intentionally minimal.
     *   Schema-driven rendering occurs via parser functions.
     */
    public function generateDisplayStub(CategoryModel $category): string {

        $cat = $this->sanitize($category->getName());

        $lines = [];

        /* ------------------------------------------------------------------
         * NOINCLUDE header
         * ------------------------------------------------------------------ */
        $lines[] = '<noinclude>';
        $lines[] = '<!-- DISPLAY TEMPLATE STUB (AUTO-CREATED by StructureSync) -->';
        $lines[] = '<!-- SAFE TO EDIT. Your changes will never be overwritten. -->';
        $lines[] = '<!-- Default rendering: all properties from the StructureSync schema. -->';
        $lines[] = '<!-- To customize sections, you may use: -->';
        $lines[] = '<!--   {{#StructureSyncRenderSection:' . $cat . '|Section Name}} -->';
        $lines[] = '</noinclude>';

        /* ------------------------------------------------------------------
         * DELEGATED RENDERING
         * ------------------------------------------------------------------ */
        $lines[] = '<includeonly>';
        $lines[] = '{{#StructureSyncRenderAllProperties:' . $cat . '}}';
        $lines[] = '{{#structuresync_hierarchy:}}';
        $lines[] = '</includeonly>';

        return implode("\n", $lines);
    }

    /* =====================================================================
     * STUB CREATION LOGIC
     * ===================================================================== */

    /**
     * True if Template:<Category>/display exists.
     */
    public function displayStubExists(string $categoryName): bool {
        $title = $this->makeDisplayTitle($categoryName);
        return $title && $this->pageCreator->pageExists($title);
    }

    /**
     * Creates the stub ONLY if missing.
     *
     * Returns:
     *   [
     *     'created' => bool,
     *     'message' => string,
     *     'error'   => string (optional)
     *   ]
     */
    public function generateDisplayStubIfMissing(CategoryModel $category): array {

        $name = $category->getName();

        if ($this->displayStubExists($name)) {
            return [
                'created' => false,
                'message' => 'Display template already exists; not overwritten.'
            ];
        }

        $content = $this->generateDisplayStub($category);
        $title   = $this->makeDisplayTitle($name);

        if (!$title) {
            return [
                'created' => false,
                'error'   => 'Failed to create Title object for display template.'
            ];
        }

        $ok = $this->pageCreator->createOrUpdatePage(
            $title,
            $content,
            'StructureSync: Initial display stub (safe to edit)'
        );

        if (!$ok) {
            return [
                'created' => false,
                'error'   => 'Failed to write display template.'
            ];
        }

        return [
            'created' => true,
            'message' => 'Display template stub created.'
        ];
    }

    /**
     * FORCED update — overwrites user customizations.
     * Use ONLY manually.
     *
     * Returns:
     *   [
     *     'created' => bool,
     *     'updated' => bool,
     *     'message' => string,
     *     'error'   => string (optional)
     *   ]
     */
    public function generateOrUpdateDisplayStub(CategoryModel $category): array {

        $name    = $category->getName();
        $existed = $this->displayStubExists($name);

        $content = $this->generateDisplayStub($category);
        $title   = $this->makeDisplayTitle($name);

        if (!$title) {
            return [
                'created' => false,
                'updated' => false,
                'error'   => 'Failed to create Title object for display template.'
            ];
        }

        $summary = $existed
            ? 'StructureSync: Updated display template'
            : 'StructureSync: Initial display stub (safe to edit)';

        $ok = $this->pageCreator->createOrUpdatePage($title, $content, $summary);

        if (!$ok) {
            return [
                'created' => false,
                'updated' => false,
                'error'   => 'Failed to write display template.'
            ];
        }

        return [
            'created' => !$existed,
            'updated' => $existed,
            'message' => $existed ? 'Display template updated.' : 'Display template stub created.'
        ];
    }

    /* =====================================================================
     * LABEL / PARAM NAME HELPERS (unused by stub but preserved)
     * ===================================================================== */

    private function propertyToParameter(string $propertyName): string {
        return NamingHelper::propertyToParameter($propertyName);
    }

    private function propertyToLabel(string $propertyName): string {
        $propertyName = $this->sanitize($propertyName);

        $model = $this->propertyStore->readProperty($propertyName);
        if ($model) {
            return $model->getLabel();
        }

        return NamingHelper::generatePropertyLabel($propertyName);
    }
}
