<?php

namespace MediaWiki\Extension\StructureSync\Parser;

use MediaWiki\Extension\StructureSync\Display\DisplayRenderer;
use MediaWiki\Html\Html;
use Parser;
use PPFrame;

/**
 * DisplayParserFunctions (Improved 2025)
 * --------------------------------------
 * Registers and implements StructureSync's display-related parser functions:
 *
 *   {{#StructureSyncRenderAllProperties:Category}}
 *   {{#StructureSyncRenderSection:Category|SectionName}}
 *   {{#structuresync_hierarchy:}}
 *   {{#structuresync_load_form_preview:}}
 *
 * Responsibilities:
 *   - Validate arguments
 *   - Delegate rendering to DisplayRenderer
 *   - Inject required RL modules
 *   - Provide clean HTML-safe outputs
 */
class DisplayParserFunctions {

    private DisplayRenderer $renderer;

    public function __construct(?DisplayRenderer $renderer = null) {
        $this->renderer = $renderer ?? new DisplayRenderer();
    }

    /* =====================================================================
     * REGISTRATION
     * ===================================================================== */

    public static function onParserFirstCallInit(Parser $parser): void {

        $instance = new self();

        // Render all sections
        $parser->setFunctionHook(
            'StructureSyncRenderAllProperties',
            [$instance, 'renderAllProperties'],
            SFH_OBJECT_ARGS
        );

        // Render specific section
        $parser->setFunctionHook(
            'StructureSyncRenderSection',
            [$instance, 'renderSection'],
            SFH_OBJECT_ARGS
        );

        // Category hierarchy UI
        $parser->setFunctionHook(
            'structuresync_hierarchy',
            [$instance, 'renderHierarchy'],
            SFH_OBJECT_ARGS
        );

        // Form preview JS loader
        $parser->setFunctionHook(
            'structuresync_load_form_preview',
            [$instance, 'loadFormPreview'],
            SFH_OBJECT_ARGS
        );
    }

    /* =====================================================================
     * HELPER UTILITIES
     * ===================================================================== */

    private function extractArg(PPFrame $frame, array $args, int $index): ?string {
        if (!isset($args[$index])) {
            return null;
        }
        $expanded = trim($frame->expand($args[$index]));
        return $expanded === '' ? null : $expanded;
    }

    private function validCategoryName(?string $name): bool {
        if ($name === null || $name === '') {
            return false;
        }
        if (preg_match('/[<>{}|#]/', $name)) {
            return false;
        }
        return strlen($name) <= 255;
    }

    private function htmlReturn(string $html): array {
        return [
            $html,
            'noparse' => true,
            'isHTML'  => true
        ];
    }

    /* =====================================================================
     * RENDER: ALL PROPERTIES
     * ===================================================================== */

    public function renderAllProperties(Parser $parser, PPFrame $frame, array $args) {

        $category = $this->extractArg($frame, $args, 0);

        if (!$this->validCategoryName($category)) {
            wfLogWarning("StructureSync: Invalid or empty category in #StructureSyncRenderAllProperties");
            return '';
        }

        try {
            $html = $this->renderer->renderAllSections($category, $frame);
            return $this->htmlReturn($html);

        } catch (\Throwable $e) {
            wfLogWarning("StructureSync: renderAllProperties failed for '$category': " . $e->getMessage());
            return '';
        }
    }

    /* =====================================================================
     * RENDER: SINGLE SECTION
     * ===================================================================== */

    public function renderSection(Parser $parser, PPFrame $frame, array $args) {

        $category = $this->extractArg($frame, $args, 0);
        $section  = $this->extractArg($frame, $args, 1);

        if (!$this->validCategoryName($category)) {
            wfLogWarning("StructureSync: Invalid category in #StructureSyncRenderSection");
            return '';
        }
        if ($section === null) {
            wfLogWarning("StructureSync: Missing section name in #StructureSyncRenderSection");
            return '';
        }

        try {
            $html = $this->renderer->renderSection($category, $section, $frame);
            return $this->htmlReturn($html);

        } catch (\Throwable $e) {
            wfLogWarning("StructureSync: renderSection failed for '$category' → '$section': " . $e->getMessage());
            return '';
        }
    }

    /* =====================================================================
     * CATEGORY HIERARCHY UI
     * ===================================================================== */

    public function renderHierarchy(Parser $parser, PPFrame $frame, array $args) {

        $title = $parser->getTitle();
        if (!$title || $title->getNamespace() !== NS_CATEGORY) {
            return '';
        }

        $category = $title->getText();

        $output = $parser->getOutput();
        $output->addModules(['ext.structuresync.hierarchy']);

        $id = 'ss-category-hierarchy-' . md5($category);

        $html = Html::rawElement(
            'div',
            [
                'id'            => $id,
                'class'         => 'ss-hierarchy-block mw-collapsible',
                'data-category' => $category
            ],
            Html::element('p', [], wfMessage('structuresync-hierarchy-loading')->text())
        );

        return $this->htmlReturn($html);
    }

    /* =====================================================================
     * FORM PREVIEW MODULE
     * ===================================================================== */

    public function loadFormPreview(Parser $parser, PPFrame $frame, array $args): array {

        $output = $parser->getOutput();
        $output->addModules(['ext.structuresync.hierarchy.formpreview']);

        // Ensure loading order in <head>
        $output->addHeadItem(
            'structuresync-formpreview-loader',
            Html::inlineScript('mw.loader.using("ext.structuresync.hierarchy.formpreview");')
        );

        return ['', 'noparse' => false, 'isHTML' => false];
    }
}
