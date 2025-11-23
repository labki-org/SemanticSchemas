<?php

namespace MediaWiki\Extension\StructureSync\Parser;

use MediaWiki\Extension\StructureSync\Display\DisplayRenderer;
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
}
