<?php

namespace MediaWiki\Extension\StructureSync\Parser;

use MediaWiki\Extension\StructureSync\Display\DisplayRenderer;
use MediaWiki\Html\Html;
use Parser;
use PPFrame;

/**
 * DisplayParserFunctions
 * ----------------------
 * Registers and handles parser functions for rendering category displays:
 * - #StructureSyncRenderAllProperties - Renders all sections for a category
 * - #StructureSyncRenderSection - Renders a specific section for a category
 * 
 * Parser Hook Integration:
 * -----------------------
 * These functions must be registered in extension.json:
 * 
 * "Hooks": {
 *     "ParserFirstCallInit": "MediaWiki\\Extension\\StructureSync\\Parser\\DisplayParserFunctions::onParserFirstCallInit"
 * }
 * 
 * Usage in Templates:
 * ------------------
 * {{#StructureSyncRenderAllProperties:CategoryName}}
 * {{#StructureSyncRenderSection:CategoryName|SectionName}}
 * 
 * These functions are typically called from display template stubs
 * (Template:<Category>/display) to automatically render property values
 * based on the current schema definition.
 * 
 * Performance Considerations:
 * --------------------------
 * - Category inheritance is resolved on each call
 * - Display sections are built dynamically
 * - Consider caching for high-traffic pages
 */
class DisplayParserFunctions
{

    /** @var DisplayRenderer */
    private $renderer;

    public function __construct(DisplayRenderer $renderer = null)
    {
        $this->renderer = $renderer ?? new DisplayRenderer();
    }

    /**
     * Register parser functions.
     * 
     * This static method is called by MediaWiki's ParserFirstCallInit hook
     * to register our custom parser functions.
     *
     * @param Parser $parser MediaWiki parser instance
     */
    public static function onParserFirstCallInit(Parser $parser)
    {
        $instance = new self();

        $parser->setFunctionHook(
            'StructureSyncRenderAllProperties',
            [$instance, 'renderAllProperties'],
            SFH_OBJECT_ARGS
        );

        $parser->setFunctionHook(
            'StructureSyncRenderSection',
            [$instance, 'renderSection'],
            SFH_OBJECT_ARGS
        );

        $parser->setFunctionHook(
            'structuresync_hierarchy',
            [$instance, 'renderHierarchy'],
            SFH_OBJECT_ARGS
        );

        $parser->setFunctionHook(
            'structuresync_load_form_preview',
            [$instance, 'loadFormPreview'],
            SFH_OBJECT_ARGS
        );
    }

    /**
     * Handle #StructureSyncRenderAllProperties:CategoryName
     * 
     * Renders all display sections for the specified category based on
     * the current schema configuration.
     * 
     * Syntax: {{#StructureSyncRenderAllProperties:CategoryName}}
     * 
     * Error Handling:
     * - Empty category name: Returns empty string
     * - Invalid category: Logs warning and returns empty string
     * - Rendering failure: Logs error and returns empty string
     * 
     * @param Parser $parser MediaWiki parser instance
     * @param PPFrame $frame Parser frame with template arguments
     * @param array $args Function arguments
     * @return array|string Parser function return value [html, flags] or empty string on error
     */
    public function renderAllProperties(Parser $parser, PPFrame $frame, array $args)
    {
        // Validate arguments
        if (empty($args[0])) {
            wfLogWarning('StructureSync: #StructureSyncRenderAllProperties called without category name');
            return '';
        }

        $categoryName = trim($frame->expand($args[0]));

        if ($categoryName === '') {
            wfLogWarning('StructureSync: #StructureSyncRenderAllProperties called with empty category name');
            return '';
        }

        // Validate category name format (basic sanitization)
        if (!$this->isValidCategoryName($categoryName)) {
            wfLogWarning("StructureSync: Invalid category name '$categoryName' in #StructureSyncRenderAllProperties");
            return '';
        }

        try {
            $html = $this->renderer->renderAllSections($categoryName, $frame);
            return [$html, 'noparse' => true, 'isHTML' => true];
        } catch (\Exception $e) {
            wfLogWarning("StructureSync: Failed to render properties for category '$categoryName': " . $e->getMessage());
            return '';
        }
    }

    /**
     * Handle #StructureSyncRenderSection:CategoryName|SectionName
     * 
     * Renders a specific display section for the specified category.
     * 
     * Syntax: {{#StructureSyncRenderSection:CategoryName|SectionName}}
     * 
     * Error Handling:
     * - Missing arguments: Logs warning and returns empty string
     * - Invalid category/section: Logs warning and returns empty string
     * - Rendering failure: Logs error and returns empty string
     * 
     * @param Parser $parser MediaWiki parser instance
     * @param PPFrame $frame Parser frame with template arguments
     * @param array $args Function arguments [0]=CategoryName, [1]=SectionName
     * @return array|string Parser function return value [html, flags] or empty string on error
     */
    public function renderSection(Parser $parser, PPFrame $frame, array $args)
    {
        // Validate arguments
        if (empty($args[0]) || empty($args[1])) {
            wfLogWarning('StructureSync: #StructureSyncRenderSection called with missing arguments');
            return '';
        }

        $categoryName = trim($frame->expand($args[0]));
        $sectionName = trim($frame->expand($args[1]));

        if ($categoryName === '') {
            wfLogWarning('StructureSync: #StructureSyncRenderSection called with empty category name');
            return '';
        }

        if ($sectionName === '') {
            wfLogWarning('StructureSync: #StructureSyncRenderSection called with empty section name');
            return '';
        }

        // Validate category name format
        if (!$this->isValidCategoryName($categoryName)) {
            wfLogWarning("StructureSync: Invalid category name '$categoryName' in #StructureSyncRenderSection");
            return '';
        }

        try {
            $html = $this->renderer->renderSection($categoryName, $sectionName, $frame);
            return [$html, 'noparse' => true, 'isHTML' => true];
        } catch (\Exception $e) {
            wfLogWarning("StructureSync: Failed to render section '$sectionName' for category '$categoryName': " . $e->getMessage());
            return '';
        }
    }

    /**
     * Handle {{#structuresync_hierarchy:}} parser function.
     * 
     * Renders category hierarchy visualization on category pages.
     * Shows inheritance tree and inherited properties.
     * 
     * Syntax: {{#structuresync_hierarchy:}}
     * 
     * Note: Automatically detects the current category from parser context.
     * Only works on Category namespace pages.
     * 
     * @param Parser $parser MediaWiki parser instance
     * @param PPFrame $frame Parser frame with template arguments
     * @param array $args Function arguments (not used)
     * @return array|string Parser function return value [html, flags] or empty string
     */
    public function renderHierarchy(Parser $parser, PPFrame $frame, array $args)
    {
        // Get the current page title
        $title = $parser->getTitle();
        
        // Only work on Category namespace pages
        if ($title === null || $title->getNamespace() !== NS_CATEGORY) {
            return '';
        }

        // Get category name without namespace prefix
        $categoryName = $title->getText();

        // Add the hierarchy ResourceLoader module
        $parser->getOutput()->addModules(['ext.structuresync.hierarchy']);

        // Create unique container ID
        $containerId = 'ss-category-hierarchy-' . md5($categoryName);

        // Build HTML structure with data-category attribute for auto-initialization
        $html = Html::rawElement(
            'div',
            [
                'id' => $containerId,
                'class' => 'ss-hierarchy-block mw-collapsible',
                'data-category' => $categoryName,
            ],
            Html::element(
                'p',
                [],
                wfMessage('structuresync-hierarchy-loading')->text()
            )
        );

        return [$html, 'noparse' => false, 'isHTML' => true];
    }

    /**
     * Validate category name format.
     * 
     * Performs basic validation to prevent obviously invalid category names.
     * 
     * @param string $categoryName Category name to validate
     * @return bool True if valid, false otherwise
     */
    private function isValidCategoryName(string $categoryName): bool
    {
        // Empty check
        if (trim($categoryName) === '') {
            return false;
        }

        // Check for obviously invalid characters
        // MediaWiki title validation is complex, so we just do basic checks
        if (preg_match('/[<>{}|#]/', $categoryName)) {
            return false;
        }

        // Length check (MediaWiki max title length is 255 bytes)
        if (strlen($categoryName) > 255) {
            return false;
        }

        return true;
    }

    /**
     * Parser function to load the form preview ResourceLoader module.
     * 
     * Usage: {{#structuresync_load_form_preview:}}
     * 
     * This is a convenience function for forms to load the hierarchy preview
     * module without needing inline scripts.
     * 
     * @param Parser $parser MediaWiki parser instance
     * @param PPFrame $frame Parser frame
     * @param array $args Function arguments (unused)
     * @return array Parser function return value
     */
    public function loadFormPreview(Parser $parser, PPFrame $frame, array $args): array
    {
        // Add the ResourceLoader module to the page output
        $output = $parser->getOutput();
        $output->addModules(['ext.structuresync.hierarchy.formpreview']);
        
        // Force the module to load with dependencies
        // mw.loader.using() ensures dependencies are loaded first
        $output->addHeadItem(
            'structuresync-form-preview-loader',
            Html::inlineScript('mw.loader.using("ext.structuresync.hierarchy.formpreview");')
        );
        
        // Return empty string - this function just loads the module
        return ['', 'noparse' => false, 'isHTML' => false];
    }
}
