<?php

namespace MediaWiki\Extension\StructureSync\Generator;

use MediaWiki\Extension\StructureSync\Schema\CategoryModel;
use MediaWiki\Extension\StructureSync\Store\PageCreator;
use MediaWiki\Extension\StructureSync\Store\WikiPropertyStore;
use MediaWiki\Extension\StructureSync\Util\NamingHelper;

/**
 * DisplayStubGenerator
 * --------------------
 * Generates human-editable display templates in:
 *     Template:<Category>/display
 *
 * IMPORTANT: Stub vs Generated Templates
 * ---------------------------------------
 * Display templates are "stubs" - they are created once but NOT regenerated
 * on subsequent schema imports. This is intentional to preserve user customizations.
 * 
 * Users can freely edit these templates to customize the display layout without
 * fear of their changes being overwritten.
 * 
 * The stub delegates rendering to parser functions that read the current schema:
 *   - {{#StructureSyncRenderAllProperties:CategoryName}} - Renders all sections
 *   - {{#StructureSyncRenderSection:CategoryName|SectionName}} - Renders specific section
 * 
 * This approach balances automatic updates (via parser functions) with
 * customization freedom (by not overwriting the stub template itself).
 *
 * This generator:
 *   - Uses inherited display sections/header from CategoryModel
 *   - Falls back to a generic "Details" block listing all properties
 *   - Wraps each property in a safe #if so hidden = not set
 *   - Provides consistent HTML/CSS structure for easy styling
 *   - Normalizes property → parameter conversions identically to other generators
 */
class DisplayStubGenerator
{

    /** @var PageCreator */
    private $pageCreator;

    /** @var WikiPropertyStore */
    private $propertyStore;

    public function __construct(
        PageCreator $pageCreator = null,
        WikiPropertyStore $propertyStore = null
    ) {
        $this->pageCreator = $pageCreator ?? new PageCreator();
        $this->propertyStore = $propertyStore ?? new WikiPropertyStore();
    }

    /**
     * Sanitize a value to ensure MediaWiki never encounters null.
     * 
     * This is a safety wrapper that converts null to empty string.
     *
     * @param string|null $value Value to sanitize
     * @return string The value or empty string if null
     */
    private function sanitize(?string $value): string
    {
        return $value ?? '';
    }

    /* =====================================================================
     * MAIN GENERATION
     * ===================================================================== */

    /**
     * Generate display template stub content.
     * 
     * Creates a minimal stub template that delegates rendering to parser functions.
     * This approach allows the schema to be updated without regenerating the template,
     * preserving any user customizations.
     *
     * @param CategoryModel $category Effective category (inherited)
     * @return string Wikitext content for the display template
     */
    public function generateDisplayStub(CategoryModel $category): string
    {

        $lines = [];

        /* ------------------------------------------------------------------
         * NOINCLUDE header
         * ------------------------------------------------------------------ */
        $lines[] = '<noinclude>';
        $lines[] = '<!-- DISPLAY TEMPLATE STUB (AUTO-CREATED by StructureSync) -->';
        $lines[] = '<!-- This template is SAFE TO EDIT. -->';
        $lines[] = '<!-- By default, it renders all properties defined in the category schema. -->';
        $lines[] = '<!-- You can replace the content below with custom layout using: -->';
        $lines[] = '<!-- {{#StructureSyncRenderSection:' . $this->sanitize($category->getName()) . '|Section Name}} -->';
        $lines[] = '</noinclude>';
        $lines[] = '<includeonly>';
        $lines[] = '{{#StructureSyncRenderAllProperties:' . $this->sanitize($category->getName()) . '}}';
        $lines[] = '</includeonly>';

        // We no longer generate the verbose default content here because the renderer handles it.
        // This keeps the template clean and updates automatically if the schema changes.

        return implode("\n", $lines);
    }

    /* =====================================================================
     * CREATION WRAPPERS
     * ===================================================================== */

    /**
     * Check if a display stub already exists for a category.
     * 
     * @param string $categoryName Category name (without namespace)
     * @return bool True if the display template exists
     */
    public function displayStubExists(string $categoryName): bool
    {
        $title = $this->pageCreator
            ->makeTitle($categoryName . '/display', NS_TEMPLATE);
        return $title && $this->pageCreator->pageExists($title);
    }

    /**
     * Create the stub if missing (never overwrite existing display templates).
     * 
     * This method respects the principle that display templates are user-editable
     * and should not be regenerated once created.
     *
     * @param CategoryModel $category Category to generate stub for
     * @return array{created:bool,message?:string,error?:string} Result with status and message
     */
    public function generateDisplayStubIfMissing(CategoryModel $category): array
    {

        $name = $category->getName();

        if ($this->displayStubExists($name)) {
            return [
                'created' => false,
                'message' => 'Display template already exists; not overwriting.'
            ];
        }

        $content = $this->generateDisplayStub($category);

        $title = $this->pageCreator->makeTitle(
            $name . '/display',
            NS_TEMPLATE
        );

        if (!$title) {
            return [
                'created' => false,
                'error' => 'Failed to create Title object.'
            ];
        }

        $summary = 'StructureSync: Initial display template stub (safe to edit)';
        $success = $this->pageCreator->createOrUpdatePage($title, $content, $summary);

        if (!$success) {
            return [
                'created' => false,
                'error' => 'Failed to write display template.'
            ];
        }

        return [
            'created' => true,
            'message' => 'Display template stub created.'
        ];
    }

    /**
     * Create or update the display template stub.
     * 
     * WARNING: This will overwrite existing templates, potentially destroying
     * user customizations. Use generateDisplayStubIfMissing() in normal workflows.
     * 
     * This method is primarily for:
     * - Initial setup
     * - Explicit regeneration requests
     * - Recovery from corrupted templates
     *
     * @param CategoryModel $category Category to generate/update stub for
     * @return array{created:bool,updated:bool,message?:string,error?:string} Result with status
     */
    public function generateOrUpdateDisplayStub(CategoryModel $category): array
    {

        $name = $category->getName();
        $existed = $this->displayStubExists($name);

        $content = $this->generateDisplayStub($category);

        $title = $this->pageCreator->makeTitle(
            $name . '/display',
            NS_TEMPLATE
        );

        if (!$title) {
            return [
                'created' => false,
                'updated' => false,
                'error' => 'Failed to create Title object.'
            ];
        }

        $summary = $existed
            ? 'StructureSync: Updated display template'
            : 'StructureSync: Initial display template stub (safe to edit)';
        $success = $this->pageCreator->createOrUpdatePage($title, $content, $summary);

        if (!$success) {
            return [
                'created' => false,
                'updated' => false,
                'error' => 'Failed to write display template.'
            ];
        }

        return [
            'created' => !$existed,
            'updated' => $existed,
            'message' => $existed ? 'Display template updated.' : 'Display template stub created.'
        ];
    }

    /* =====================================================================
     * PROPERTY NAME HELPERS
     * ===================================================================== */

    /**
     * Convert property name → template parameter name (consistent with Forms + Templates).
     * 
     * Delegates to NamingHelper for consistent transformation across all generators.
     *
     * @param string $propertyName SMW property name
     * @return string Normalized parameter name for use in templates
     */
    private function propertyToParameter(string $propertyName): string
    {
        return NamingHelper::propertyToParameter($propertyName);
    }

    /**
     * Convert a property name into a human-readable label for display.
     *
     * Uses PropertyModel label if available, otherwise falls back to
     * auto-generated label using NamingHelper.
     *
     * @param string $propertyName SMW property name
     * @return string Human-readable label
     */
    private function propertyToLabel(string $propertyName): string
    {
        $propertyName = $this->sanitize($propertyName);

        // Try to get property model and use its label
        $property = $this->propertyStore->readProperty($propertyName);
        if ($property !== null) {
            return $property->getLabel();
        }

        // Fallback: auto-generate label from property name using NamingHelper
        return NamingHelper::generatePropertyLabel($propertyName);
    }
}
