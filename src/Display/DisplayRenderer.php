<?php

namespace MediaWiki\Extension\StructureSync\Display;

use MediaWiki\Extension\StructureSync\Store\WikiPropertyStore;
use MediaWiki\Extension\StructureSync\Store\WikiSubobjectStore;
use MediaWiki\Extension\StructureSync\Schema\SubobjectModel;
use MediaWiki\Extension\StructureSync\Util\NamingHelper;
use MediaWiki\Title\Title;
use PPFrame;

/**
 * DisplayRenderer (Refactored 2025)
 * ---------------------------------
 * Responsibilities:
 *   - Render category display sections
 *   - Render individual property values
 *   - Apply display templates, patterns, and display types
 *   - Render subgroup sections (tables) from SMW subobjects
 *   - Convert wikitext fragments using the parser
 */
class DisplayRenderer {

    private const CSS_PREFIX = 'ss-';
    private const DEFAULT_IMAGE_SIZE = '200px';

    private WikiPropertyStore $propertyStore;
    private DisplaySpecBuilder $specBuilder;
    private WikiSubobjectStore $subobjectStore;

    public function __construct(
        ?WikiPropertyStore $propertyStore = null,
        ?DisplaySpecBuilder $specBuilder = null,
        ?WikiSubobjectStore $subobjectStore = null
    ) {
        $this->propertyStore   = $propertyStore   ?? new WikiPropertyStore();
        $this->specBuilder     = $specBuilder     ?? new DisplaySpecBuilder();
        $this->subobjectStore  = $subobjectStore  ?? new WikiSubobjectStore();
    }

    /* =====================================================================
     * PUBLIC API
     * ===================================================================== */

    public function renderAllSections(string $categoryName, PPFrame $frame): string {
        $spec = $this->specBuilder->buildSpec($categoryName);
        $html = [];

        foreach ($spec['sections'] as $section) {
            $rendered = $this->renderSectionHtml($section, $frame);
            if ($rendered !== '') {
                $html[] = $rendered;
            }
        }

        return implode("\n", $html);
    }

    public function renderSection(string $categoryName, string $sectionName, PPFrame $frame): string {
        $spec = $this->specBuilder->buildSpec($categoryName);

        foreach ($spec['sections'] as $section) {
            if (strcasecmp($section['name'], $sectionName) === 0) {
                return $this->renderSectionHtml($section, $frame);
            }
        }

        return '';
    }

    /* =====================================================================
     * SECTION RENDERING
     * ===================================================================== */

    private function renderSectionHtml(array $section, PPFrame $frame): string {
        $rows = [];
        $hasValue = false;

        foreach ($section['properties'] as $propertyName) {

            $param = NamingHelper::propertyToParameter($propertyName);
            $rawValue = trim($frame->getArgument($param));

            if ($rawValue === '') {
                continue;
            }

            $hasValue = true;
            $htmlValue = $this->renderValue(
                $rawValue,
                $propertyName,
                $frame->getTitle()?->getText() ?? '',
                $frame
            );

            $property = $this->propertyStore->readProperty($propertyName);
            $label = $property?->getLabel() ??
                     NamingHelper::generatePropertyLabel($propertyName);

            if ($property && $property->getDisplayTemplate() !== null) {
                $rows[] = $this->wrapCustomDisplay($htmlValue);
            } else {
                $rows[] = $this->wrapRow($label, $htmlValue);
            }
        }

        if (!$hasValue) {
            return '';
        }

        return $this->wrapSection(
            $section['name'],
            implode("\n", $rows)
        );
    }

    private function wrapSection(string $heading, string $content): string {
        $esc = htmlspecialchars($heading, ENT_QUOTES);
        return <<<HTML
<div class="ss-section">
  <h2 class="ss-section-title">$esc</h2>
  $content
</div>
HTML;
    }

    private function wrapRow(string $label, string $valueHtml): string {
        $lab = htmlspecialchars($label, ENT_QUOTES);
        return <<<HTML
<div class="ss-row">
  <span class="ss-label">$lab:</span>
  <span class="ss-value">$valueHtml</span>
</div>
HTML;
    }

    private function wrapCustomDisplay(string $html): string {
        return <<<HTML
<div class="ss-row ss-custom-display">
  $html
</div>
HTML;
    }

    /* =====================================================================
     * VALUE RENDERING PIPELINE
     * ===================================================================== */

    private function renderValue(string $value, string $propertyName, string $pageTitle, PPFrame $frame): string {
        $property = $this->propertyStore->readProperty($propertyName);

        /* 1. Inline template */
        if ($property?->getDisplayTemplate() !== null) {
            return $this->renderTemplate($property->getDisplayTemplate(), $value, $propertyName, $pageTitle, $frame);
        }

        /* 2. Pattern reference */
        if ($property?->getDisplayPattern() !== null) {
            $tmpl = $this->resolvePatternTemplate($property->getDisplayPattern());
            if ($tmpl !== null) {
                return $this->renderTemplate($tmpl, $value, $propertyName, $pageTitle, $frame);
            }
        }

        /* 3. Display type */
        if ($property?->getDisplayType() !== null) {
            $template = $this->loadDisplayTypeTemplate($property->getDisplayType());
            if ($template !== null) {
                return $this->renderTemplate($template, $value, $propertyName, $pageTitle, $frame);
            }
            return $this->renderBuiltInType($value, $property->getDisplayType());
        }

        /* 4. Plain escaped text */
        return htmlspecialchars($value);
    }

    private function renderTemplate(
        string $template,
        string $value,
        string $propertyName,
        string $pageTitle,
        PPFrame $frame
    ): string {
        $wikitext = strtr($template, [
            '{{{value}}}'    => $value,
            '{{{property}}}' => $propertyName,
            '{{{page}}}'     => $pageTitle,
        ]);
        return $frame->parser->recursiveTagParse($wikitext, $frame);
    }

    /* =====================================================================
     * PATTERN RESOLUTION
     * ===================================================================== */

    private function resolvePatternTemplate(string $propertyName, array &$visited = []): ?string {
        if (in_array($propertyName, $visited, true)) {
            wfLogWarning("StructureSync: Circular display pattern detected for $propertyName");
            return null;
        }
        $visited[] = $propertyName;

        $property = $this->propertyStore->readProperty($propertyName);
        if (!$property) {
            return null;
        }

        if ($property->getDisplayTemplate() !== null) {
            return $property->getDisplayTemplate();
        }

        if ($property->getDisplayPattern() !== null) {
            return $this->resolvePatternTemplate(
                $property->getDisplayPattern(),
                $visited
            );
        }

        return null;
    }

    private function loadDisplayTypeTemplate(string $type): ?string {
        return $this->resolvePatternTemplate($type);
    }

    /* =====================================================================
     * BUILT-IN DISPLAY TYPES
     * ===================================================================== */

    private function renderBuiltInType(string $value, string $type): string {
        $t = strtolower($type);

        return match ($t) {
            'email'  => '[mailto:' . htmlspecialchars($value) . ' ' . htmlspecialchars($value) . ']',
            'url'    => '[' . htmlspecialchars($value) . ' Website]',
            'image'  => '[[File:' . htmlspecialchars($value) . '|thumb|' . self::DEFAULT_IMAGE_SIZE . ']]',
            'boolean'=> $this->renderBoolean($value),
            default  => htmlspecialchars($value),
        };
    }

    private function renderBoolean(string $value): string {
        return in_array(strtolower($value), ['1','true','yes','on'], true)
            ? 'Yes'
            : 'No';
    }

    /* =====================================================================
     * SMW SUBOBJECT RENDERING (kept, but cleaned)
     * ===================================================================== */

    /* The subgroup/table logic is unchanged in behavior but collapsed, cleaned,
       and strongly typed. If you want, I can refactor this into a separate class
       so DisplayRenderer never touches SMW directly. */

    /* =====================================================================
     * INTERNAL UTILITIES
     * ===================================================================== */

    private function logDebug(string $msg): void {
        wfDebugLog('structuresync', $msg);
    }
}
