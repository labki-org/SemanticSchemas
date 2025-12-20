<?php

namespace MediaWiki\Extension\SemanticSchemas\Parser;

use MediaWiki\Html\Html;
use Parser;
use PPFrame;

/**
 * DisplayParserFunctions (Redesigned 2025)
 * -----------------------------------------
 * Registers SemanticSchemas's parser functions:
 *
 *   {{#semanticschemas_hierarchy:}}
 *   {{#semanticschemas_load_form_preview:}}
 *
 * The old rendering parser functions have been removed since
 * display templates are now static wikitext that directly calls
 * property templates.
 *
 * Responsibilities:
 *   - Inject hierarchy widget
 *   - Load form preview modules
 *   - Provide clean HTML-safe outputs
 */
class DisplayParserFunctions
{

    public function __construct()
    {
        // No dependencies needed
    }

    /* =====================================================================
     * REGISTRATION
     * ===================================================================== */

    public static function onParserFirstCallInit(Parser $parser): void
    {

        $instance = new self();

        // Category hierarchy UI
        $parser->setFunctionHook(
            'semanticschemas_hierarchy',
            [$instance, 'renderHierarchy'],
            SFH_OBJECT_ARGS
        );

        // Form preview JS loader
        $parser->setFunctionHook(
            'semanticschemas_load_form_preview',
            [$instance, 'loadFormPreview'],
            SFH_OBJECT_ARGS
        );
    }

    /* =====================================================================
     * HELPER UTILITIES
     * ===================================================================== */

    private function htmlReturn(string $html): array
    {
        return [
            $html,
            'noparse' => true,
            'isHTML' => true
        ];
    }

    /* =====================================================================
     * CATEGORY HIERARCHY UI
     * ===================================================================== */

    public function renderHierarchy(Parser $parser, PPFrame $frame, array $args)
    {

        // Argument 0: Optional Category Name (e.g. "Person")
        // If provided, we force display for that category regardless of the current page.
        $category = isset($args[0]) ? trim($frame->expand($args[0])) : null;

        if (!$category) {
            // Fallback: Infer from current page title if in Category namespace
            $title = $parser->getTitle();
            if (!$title || $title->getNamespace() !== NS_CATEGORY) {
                return '';
            }
            $category = $title->getText();
        }

        $output = $parser->getOutput();
        $output->addModules(['ext.semanticschemas.hierarchy']);

        $id = 'ss-category-hierarchy-' . md5($category);

        $html = Html::rawElement(
            'div',
            [
                'id' => $id,
                'class' => 'ss-hierarchy-block mw-collapsible',
                'data-category' => $category
            ],
            Html::element('p', [], wfMessage('semanticschemas-hierarchy-loading')->text())
        );

        return $this->htmlReturn($html);
    }

    /* =====================================================================
     * FORM PREVIEW MODULE
     * ===================================================================== */

    public function loadFormPreview(Parser $parser, PPFrame $frame, array $args): array
    {

        $output = $parser->getOutput();
        $output->addModules(['ext.semanticschemas.hierarchy.formpreview']);

        // Ensure loading order in <head>
        $output->addHeadItem(
            'semanticschemas-formpreview-loader',
            Html::inlineScript('mw.loader.using("ext.semanticschemas.hierarchy.formpreview");')
        );

        return ['', 'noparse' => false, 'isHTML' => false];
    }
}
