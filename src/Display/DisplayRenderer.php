<?php

namespace MediaWiki\Extension\StructureSync\Display;

use MediaWiki\Extension\StructureSync\Store\WikiPropertyStore;
use MediaWiki\Extension\StructureSync\Schema\PropertyModel;
use MediaWiki\Extension\StructureSync\Util\NamingHelper;
use PPFrame;

/**
 * DisplayRenderer
 * ---------------
 * Renders the display specification into HTML.
 * 
 * This class is responsible for:
 * - Rendering property values with appropriate formatting
 * - Applying display templates and patterns
 * - Handling built-in display types (email, URL, image, boolean)
 * - Managing section-based layouts
 */
class DisplayRenderer
{
    /** @var string Default image size for image display type */
    private const DEFAULT_IMAGE_SIZE = '200px';
    
    /** @var string CSS class prefix for styled elements */
    private const CSS_PREFIX = 'ss-';

    /** @var WikiPropertyStore */
    private $propertyStore;

    /** @var DisplaySpecBuilder */
    private $specBuilder;

    public function __construct(
        WikiPropertyStore $propertyStore = null,
        DisplaySpecBuilder $specBuilder = null
    ) {
        $this->propertyStore = $propertyStore ?? new WikiPropertyStore();
        $this->specBuilder = $specBuilder ?? new DisplaySpecBuilder();
    }

    /**
     * Render all sections as headings and rows.
     *
     * @param string $categoryName
     * @param PPFrame $frame
     * @return string
     */
    public function renderAllSections(string $categoryName, PPFrame $frame): string
    {
        $spec = $this->specBuilder->buildSpec($categoryName);
        $html = '';

        foreach ($spec['sections'] as $section) {
            $html .= $this->renderSectionHtml($section, $frame);
        }

        return $html;
    }

    /**
     * Render a single section by name.
     *
     * @param string $categoryName
     * @param string $sectionName
     * @param PPFrame $frame
     * @return string
     */
    public function renderSection(string $categoryName, string $sectionName, PPFrame $frame): string
    {
        $spec = $this->specBuilder->buildSpec($categoryName);

        foreach ($spec['sections'] as $section) {
            if (strcasecmp($section['name'], $sectionName) === 0) {
                return $this->renderSectionHtml($section, $frame);
            }
        }

        return ''; // Section not found
    }

    /**
     * Internal helper to render a section array to HTML.
     * 
     * Renders a display section with all its properties. Sections with no
     * populated properties are skipped (returns empty string).
     * 
     * CSS classes applied:
     * - ss-section: Container for the entire section
     * - ss-section-title: Section heading
     * - ss-row: Container for each property row
     * - ss-label: Property label
     * - ss-value: Property value
     *
     * @param array<string,mixed> $section Section configuration with 'name' and 'properties' keys
     * @param PPFrame $frame Parser frame for accessing template arguments
     * @return string Rendered HTML, or empty string if section has no values
     */
    private function renderSectionHtml(array $section, PPFrame $frame): string
    {
        $lines = [];

        // Check if any property in this section has a value
        $hasAnyValue = false;
        $rows = [];

        foreach ($section['properties'] as $propertyName) {
            $paramName = $this->propertyToParameter($propertyName);
            // Use standard getArgument since we are passing args explicitly now
            $value = trim($frame->getArgument($paramName));

            if ($value !== '') {
                $hasAnyValue = true;

                // Get page title from frame for template variable
                $pageTitle = $frame->getTitle() ? $frame->getTitle()->getText() : '';
                $renderedValue = $this->renderValue($value, $propertyName, $pageTitle, $frame);

                // Check if property has a custom display template
                $property = $this->propertyStore->readProperty($propertyName);
                $hasCustomDisplay = $property !== null && $property->getDisplayTemplate() !== null;

                if ($hasCustomDisplay) {
                    // Custom display template handles its own formatting and labels
                    $rows[] = '<div class="' . self::CSS_PREFIX . 'row ' . self::CSS_PREFIX . 'custom-display">';
                    $rows[] = '  ' . $renderedValue;
                    $rows[] = '</div>';
                } else {
                    // Default: label + value structure
                    $label = $this->getPropertyLabel($propertyName);
                    $rows[] = '<div class="' . self::CSS_PREFIX . 'row">';
                    $rows[] = '  <span class="' . self::CSS_PREFIX . 'label">' . htmlspecialchars($label) . ':</span>';
                    $rows[] = '  <span class="' . self::CSS_PREFIX . 'value">' . $renderedValue . '</span>';
                    $rows[] = '</div>';
                }
            }
        }

        if (!$hasAnyValue) {
            return '';
        }

        $lines[] = '<div class="' . self::CSS_PREFIX . 'section">';
        $lines[] = '  <h2 class="' . self::CSS_PREFIX . 'section-title">' . htmlspecialchars($section['name']) . '</h2>';
        $lines[] = implode("\n", $rows);
        $lines[] = '</div>';

        return implode("\n", $lines);
    }

    /**
     * Get display label for a property.
     * 
     * Falls back to auto-generated label using NamingHelper if property is not found in store.
     * 
     * @param string $propertyName The property name
     * @return string Display label for the property
     */
    private function getPropertyLabel(string $propertyName): string
    {
        $property = $this->propertyStore->readProperty($propertyName);
        if ($property !== null) {
            return $property->getDisplayLabel();
        }

        // Fallback if property not found in store - use NamingHelper
        return NamingHelper::generatePropertyLabel($propertyName);
    }

    /**
     * Get display type for a property.
     * 
     * @param string $propertyName The property name
     * @return string|null Display type if defined, null otherwise
     */
    private function getPropertyDisplayType(string $propertyName): ?string
    {
        $property = $this->propertyStore->readProperty($propertyName);
        return $property !== null ? $property->getDisplayType() : null;
    }

    /**
     * Render a value using property's display template or fallback.
     * 
     * This method tries multiple rendering strategies in order:
     * 1. Inline display template (highest priority)
     * 2. Display pattern (property-to-property reference)
     * 3. Display type (built-in type)
     * 4. Plain text with HTML escaping (default fallback)
     *
     * @param string $value Property value
     * @param string $propertyName Property name
     * @param string $pageTitle Current page title
     * @param PPFrame $frame Parser frame for parsing wikitext
     * @return string Rendered HTML
     */
    private function renderValue(string $value, string $propertyName, string $pageTitle, PPFrame $frame): string
    {
        $property = $this->propertyStore->readProperty($propertyName);

        // 1. Check for inline template
        if ($property !== null && $property->getDisplayTemplate() !== null) {
            try {
                $wikitext = $this->expandTemplate(
                    $property->getDisplayTemplate(),
                    ['value' => $value, 'property' => $propertyName, 'page' => $pageTitle]
                );
                // Parse the wikitext to convert [mailto:...] etc to actual links
                return $this->parseWikitext($wikitext, $frame);
            } catch (\Exception $e) {
                // Log error and fall through to next strategy
                wfLogWarning("StructureSync: Failed to expand display template for $propertyName: " . $e->getMessage());
            }
        }

        // 2. Check for display pattern (property-to-property reference)
        if ($property !== null && $property->getDisplayPattern() !== null) {
            try {
                $visited = [];
                $patternTemplate = $this->resolveDisplayPattern($property->getDisplayPattern(), $visited);
                if ($patternTemplate !== null) {
                    $wikitext = $this->expandTemplate(
                        $patternTemplate,
                        ['value' => $value, 'property' => $propertyName, 'page' => $pageTitle]
                    );
                    // Parse the wikitext
                    return $this->parseWikitext($wikitext, $frame);
                }
            } catch (\Exception $e) {
                // Log error and fall through to next strategy
                wfLogWarning("StructureSync: Failed to resolve display pattern for $propertyName: " . $e->getMessage());
            }
        }

        // 3. Check for display type (could reference shared template)
        if ($property !== null && $property->getDisplayType() !== null) {
            $displayType = $property->getDisplayType();

            // Try to load as a pattern property (property-to-property template reference)
            $typeTemplate = $this->loadDisplayTypeTemplate($displayType);
            if ($typeTemplate !== null) {
                try {
                    $wikitext = $this->expandTemplate(
                        $typeTemplate,
                        ['value' => $value, 'property' => $propertyName, 'page' => $pageTitle]
                    );
                    // Parse the wikitext
                    return $this->parseWikitext($wikitext, $frame);
                } catch (\Exception $e) {
                    // Log error and fall through to built-in rendering
                    wfLogWarning("StructureSync: Failed to expand display type template for $propertyName: " . $e->getMessage());
                }
            }

            // Fall back to hardcoded rendering for built-in types
            return $this->renderBuiltInDisplayType($value, $displayType);
        }

        // 4. Default: plain text with HTML escaping
        return htmlspecialchars($value);
    }

    /**
     * Parse wikitext fragment using MediaWiki's parser.
     * 
     * Converts wikitext markup (links, templates, etc.) into HTML.
     *
     * @param string $wikitext Wikitext to parse
     * @param PPFrame $frame Parser frame
     * @return string Parsed HTML
     */
    private function parseWikitext(string $wikitext, PPFrame $frame): string
    {
        // Access parser from frame's parser property
        $parser = $frame->parser;
        // Use recursiveTagParse to parse the wikitext in the current context
        return $parser->recursiveTagParse($wikitext, $frame);
    }

    /**
     * Expand a wikitext template with variables.
     * 
     * Performs simple variable substitution for template placeholders:
     * - {{{value}}} - The property value
     * - {{{property}}} - The property name
     * - {{{page}}} - The current page title
     *
     * @param string $template Template wikitext
     * @param array<string,string> $vars Variables to replace: value, property, page
     * @return string Expanded wikitext (not yet parsed)
     */
    private function expandTemplate(string $template, array $vars): string
    {
        // Simple variable replacement for {{{value}}}, {{{property}}}, {{{page}}}
        $expanded = $template;
        $expanded = str_replace('{{{value}}}', $vars['value'] ?? '', $expanded);
        $expanded = str_replace('{{{property}}}', $vars['property'] ?? '', $expanded);
        $expanded = str_replace('{{{page}}}', $vars['page'] ?? '', $expanded);

        // Return as-is (will be interpreted as wikitext by MediaWiki)
        return $expanded;
    }

    /**
     * Load display template by resolving pattern references.
     * 
     * This is a convenience wrapper around resolveDisplayPattern().
     *
     * @param string $propertyName Property name or pattern name
     * @return string|null Template content or null if not found
     */
    private function loadDisplayTypeTemplate(string $propertyName): ?string
    {
        $visited = [];
        return $this->resolveDisplayPattern($propertyName, $visited);
    }

    /**
     * Recursively resolve display pattern with cycle detection.
     * 
     * This method follows property-to-property display pattern references
     * until it finds an inline template or detects a circular reference.
     * 
     * Example:
     * - Property A references Property B's display pattern
     * - Property B has an inline template
     * - Result: Property A uses Property B's template
     *
     * @param string $propertyName Property name to resolve
     * @param array<int,string> &$visited Tracking array for cycle detection
     * @return string|null Template or null if not found or cycle detected
     */
    private function resolveDisplayPattern(string $propertyName, array &$visited): ?string
    {
        // Cycle detection
        if (in_array($propertyName, $visited, true)) {
            wfLogWarning("StructureSync: Circular display pattern detected for property: $propertyName");
            return null; // Cycle detected, fall back
        }
        $visited[] = $propertyName;

        $property = $this->propertyStore->readProperty($propertyName);
        if ($property === null) {
            return null;
        }

        // 1. Check for inline template
        if ($property->getDisplayTemplate() !== null) {
            return $property->getDisplayTemplate();
        }

        // 2. Follow pattern reference
        if ($property->getDisplayPattern() !== null) {
            return $this->resolveDisplayPattern($property->getDisplayPattern(), $visited);
        }

        // 3. No template found
        return null;
    }

    /**
     * Render value using built-in hardcoded display types.
     * 
     * Supported display types:
     * - email: Renders as mailto link
     * - url: Renders as external link
     * - image: Renders as thumbnail image
     * - boolean: Renders as Yes/No
     * 
     * @param string $value The property value to render
     * @param string|null $displayType The display type to use
     * @return string Rendered wikitext or HTML
     */
    private function renderBuiltInDisplayType(string $value, ?string $displayType): string
    {
        if ($displayType === null) {
            return htmlspecialchars($value);
        }

        switch (strtolower($displayType)) {
            case 'email':
                // Render as mailto link
                return '[mailto:' . htmlspecialchars($value) . ' ' . htmlspecialchars($value) . ']';

            case 'url':
                // Render as external link
                return '[' . htmlspecialchars($value) . ' Website]';

            case 'image':
                // Render as image with thumbnail
                return '[[File:' . htmlspecialchars($value) . '|thumb|' . self::DEFAULT_IMAGE_SIZE . ']]';

            case 'boolean':
                // Render as Yes/No
                $v = strtolower($value);
                if (in_array($v, ['1', 'true', 'yes', 'on'], true)) {
                    return 'Yes';
                }
                return 'No';

            default:
                return htmlspecialchars($value);
        }
    }

    /**
     * Convert property name to parameter name.
     * 
     * Delegates to NamingHelper for consistent transformation across all components.
     * 
     * @param string $propertyName The SMW property name
     * @return string The normalized parameter name for templates
     */
    private function propertyToParameter(string $propertyName): string
    {
        return NamingHelper::propertyToParameter($propertyName);
    }
}
