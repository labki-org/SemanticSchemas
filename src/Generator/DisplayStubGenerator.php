<?php

namespace MediaWiki\Extension\StructureSync\Generator;

use MediaWiki\Extension\StructureSync\Schema\CategoryModel;
use MediaWiki\Extension\StructureSync\Store\PageCreator;
use MediaWiki\Extension\StructureSync\Store\WikiPropertyStore;

/**
 * DisplayStubGenerator
 * --------------------
 * Generates human-editable display templates in:
 *     Template:<Category>/display
 *
 * These templates are intentionally NOT overwritten after creation.
 * Regenerating them could destroy user customizations.
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
     * @param string|null $value
     * @return string
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
     * @param CategoryModel $category Effective category (inherited)
     * @return string
     */
    public function generateDisplayStub(CategoryModel $category): string
    {

        $lines = [];

        /* ------------------------------------------------------------------
         * NOINCLUDE header
         * ------------------------------------------------------------------ */
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
     * SECTION GENERATORS
     * ===================================================================== */

    /**
     * Generate a structured section defined by schema "display.sections".
     *
     * @param array<string,mixed> $section
     * @return string[]
     */
    private function generateDisplaySection(array $section): array
    {
        $lines = [];

        $name = $this->sanitize($section['name'] ?? 'Section');
        $properties = $section['properties'] ?? [];

        // Sort for stable regeneration
        $properties = array_values(array_unique(array_map('strval', $properties)));
        sort($properties);

        $lines[] = '== ' . $name . ' ==';
        $lines[] = '<div class="ss-section">';

        foreach ($properties as $propertyName) {
            $param = $this->sanitize($this->propertyToParameter($propertyName));
            $label = $this->sanitize($this->propertyToLabel($propertyName));

            $lines[] = '  {{#if:{{{' . $param . '|}}}|';
            $lines[] = '    <div class="ss-row">';
            $lines[] = '      <span class="ss-label">\'\'\'' . $label . ':\'\'\'</span>';
            $lines[] = '      <span class="ss-value">{{{' . $param . '}}}</span>';
            $lines[] = '    </div>';
            $lines[] = '  }}';
        }

        $lines[] = '</div>';
        $lines[] = '';

        return $lines;
    }

    /**
     * Default fallback display (if no display config exists).
     *
     * @param CategoryModel $category
     * @return array
     */
    private function generateDefaultDisplaySection(CategoryModel $category): array
    {

        $properties = $category->getAllProperties();
        sort($properties);

        $categoryLabel = $this->sanitize($category->getLabel());
        $lines = [];
        $lines[] = '== ' . $categoryLabel . ' Details ==';
        $lines[] = '<div class="ss-section">';

        foreach ($properties as $propertyName) {
            $param = $this->sanitize($this->propertyToParameter($propertyName));
            $label = $this->sanitize($this->propertyToLabel($propertyName));

            $lines[] = '  {{#if:{{{' . $param . '|}}}|';
            $lines[] = '    <div class="ss-row">';
            $lines[] = '      <span class="ss-label">\'\'\'' . $label . ':\'\'\'</span>';
            $lines[] = '      <span class="ss-value">{{{' . $param . '}}}</span>';
            $lines[] = '    </div>';
            $lines[] = '  }}';
        }

        $lines[] = '</div>';
        $lines[] = '';

        return $lines;
    }

    /* =====================================================================
     * CREATION WRAPPERS
     * ===================================================================== */

    public function displayStubExists(string $categoryName): bool
    {
        $title = $this->pageCreator
            ->makeTitle($categoryName . '/display', NS_TEMPLATE);
        return $title && $this->pageCreator->pageExists($title);
    }

    /**
     * Create the stub if missing (never overwrite existing display templates).
     *
     * @param CategoryModel $category
     * @return array{created:bool,message?:string,error?:string}
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
     * This will overwrite existing templates.
     *
     * @param CategoryModel $category
     * @return array{created:bool,updated:bool,message?:string,error?:string}
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
     * @param string $propertyName
     * @return string
     */
    private function propertyToParameter(string $propertyName): string
    {

        // Remove "Has "
        $param = $propertyName;
        if (str_starts_with($param, 'Has ')) {
            $param = substr($param, 4);
        }

        // Replace problematic characters
        $param = str_replace(':', '_', $param);

        // Normalize
        $param = strtolower(trim($param));
        $param = str_replace(' ', '_', $param);

        return $param;
    }

    /**
     * Convert a property name into a human-readable label for display.
     *
     * Uses PropertyModel label if available, otherwise falls back to
     * auto-generated label based on property name.
     *
     * @param string $propertyName
     * @return string
     */
    private function propertyToLabel(string $propertyName): string
    {
        $propertyName = $this->sanitize($propertyName);

        // Try to get property model and use its label
        $property = $this->propertyStore->readProperty($propertyName);
        if ($property !== null) {
            return $property->getLabel();
        }

        // Fallback: auto-generate label from property name
        // Strip "Has " or "Has_"
        if (str_starts_with($propertyName, 'Has ')) {
            $clean = substr($propertyName, 4);
        } elseif (str_starts_with($propertyName, 'Has_')) {
            $clean = substr($propertyName, 4);
        } else {
            $clean = $propertyName;
        }

        // Replace underscores with spaces and capitalize
        $clean = str_replace('_', ' ', $clean);
        return ucwords($clean);
    }
}
