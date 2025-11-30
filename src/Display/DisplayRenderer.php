<?php

namespace MediaWiki\Extension\StructureSync\Display;

use MediaWiki\Extension\StructureSync\Store\WikiPropertyStore;
use MediaWiki\Extension\StructureSync\Store\WikiSubobjectStore;
use MediaWiki\Extension\StructureSync\Schema\PropertyModel;
use MediaWiki\Extension\StructureSync\Schema\SubobjectModel;
use MediaWiki\Extension\StructureSync\Util\NamingHelper;
use PPFrame;
use MediaWiki\Title\Title;

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

    /** @var WikiSubobjectStore */
    private $subobjectStore;

    public function __construct(
        WikiPropertyStore $propertyStore = null,
        DisplaySpecBuilder $specBuilder = null,
        WikiSubobjectStore $subobjectStore = null
    ) {
        $this->propertyStore = $propertyStore ?? new WikiPropertyStore();
        $this->specBuilder = $specBuilder ?? new DisplaySpecBuilder();
        $this->subobjectStore = $subobjectStore ?? new WikiSubobjectStore();
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
        wfDebugLog( 'structuresync', 'renderAllSections: Category=' . $categoryName . ', Sections=' . json_encode( array_map( function( $s ) { return [ 'name' => $s['name'], 'properties' => $s['properties'] ]; }, $spec['sections'] ) ) );
        $html = '';

        foreach ($spec['sections'] as $section) {
            wfDebugLog( 'structuresync', 'renderAllSections: Rendering section: ' . $section['name'] );
            $html .= $this->renderSectionHtml($section, $frame);
        }

        // Subgroups are now handled by the dispatcher template via #ask queries
        // $html .= $this->renderSubgroupSections(
        //     $categoryName,
        //     $spec['subgroups'] ?? [],
        //     $frame
        // );

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
            
            wfDebugLog( 'structuresync', 'renderSectionHtml: Property=' . $propertyName . ', Param=' . $paramName . ', Value=' . ( $value !== '' ? $value : '(empty)' ) );

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
	 * Render subgroup tables based on SMW subobject instances.
	 *
	 * @param string $categoryName
	 * @param array $subgroups
	 * @param PPFrame $frame
	 * @return string
	 */
	private function renderSubgroupSections( string $categoryName, array $subgroups, PPFrame $frame ): string {
		if (
			( empty( $subgroups['required'] ) )
			&& ( empty( $subgroups['optional'] ) )
		) {
			return '';
		}

		// Get the actual page title from the parser, not the template title
		// The parser's title is the page being rendered
		$parser = $frame->parser;
		$title = $parser->getTitle();
		
		if ( !$title instanceof Title ) {
			wfDebugLog( 'structuresync', 'renderSubgroupSections: No title found in parser' );
			return '';
		}

		wfDebugLog( 'structuresync', 'renderSubgroupSections: Category=' . $categoryName . ', Required=' . json_encode( $subgroups['required'] ?? [] ) . ', Optional=' . json_encode( $subgroups['optional'] ?? [] ) );
		wfDebugLog( 'structuresync', 'renderSubgroupSections: Using page title: ' . $title->getPrefixedText() );

		$pageSubobjects = $this->collectPageSubobjects( $title );
		
		wfDebugLog( 'structuresync', 'renderSubgroupSections: Found subobjects for keys: ' . implode( ', ', array_keys( $pageSubobjects ) ) );

		$html = '';

		foreach ( $subgroups['required'] ?? [] as $subgroupName ) {
			$found = isset( $pageSubobjects[$subgroupName] ) ? count( $pageSubobjects[$subgroupName] ) : 0;
			wfDebugLog( 'structuresync', 'renderSubgroupSections: Required subgroup ' . $subgroupName . ' - found ' . $found . ' entries' );
			$html .= $this->renderSingleSubgroup(
				$categoryName,
				$subgroupName,
				true,
				$pageSubobjects[$subgroupName] ?? [],
				$frame
			);
		}

		foreach ( $subgroups['optional'] ?? [] as $subgroupName ) {
			$found = isset( $pageSubobjects[$subgroupName] ) ? count( $pageSubobjects[$subgroupName] ) : 0;
			wfDebugLog( 'structuresync', 'renderSubgroupSections: Optional subgroup ' . $subgroupName . ' - found ' . $found . ' entries' );
			$html .= $this->renderSingleSubgroup(
				$categoryName,
				$subgroupName,
				false,
				$pageSubobjects[$subgroupName] ?? [],
				$frame
			);
		}

		return $html;
	}

	/**
	 * @param string $categoryName
	 * @param string $subgroupName
	 * @param bool $isRequired
	 * @param \SMW\SemanticData[] $semanticRows
	 * @param PPFrame $frame
	 * @return string
	 */
	private function renderSingleSubgroup(
		string $categoryName,
		string $subgroupName,
		bool $isRequired,
		array $semanticRows,
		PPFrame $frame
	): string {
		$subobject = $this->subobjectStore->readSubobject( $subgroupName );
		if ( !$subobject instanceof SubobjectModel ) {
			wfLogWarning(
				"StructureSync: Unable to render subgroup '$subgroupName' for category '$categoryName' (definition missing)"
			);
			return '';
		}

		$rows = $this->convertSemanticRows( $semanticRows, $subobject, $frame );

		if ( empty( $rows ) && !$isRequired ) {
			return '';
		}

		$label = htmlspecialchars( $subobject->getLabel() ?: $subobject->getName(), ENT_QUOTES );
		$html = [];
		$html[] = '<div class="ss-section ss-subgroup">';
		$html[] = '<h2 class="ss-section-title">' . $label . '</h2>';

		if ( empty( $rows ) ) {
			$html[] = '<p class="ss-hierarchy-empty">' . htmlspecialchars( 'No entries yet.', ENT_QUOTES ) . '</p>';
			$html[] = '</div>';
			return implode( "\n", $html );
		}

		$properties = $subobject->getAllProperties();
		
		// Build table as wikitext so links can be parsed
		$tableWikitext = [];
		$tableWikitext[] = '{| class="wikitable ss-subgroup-table"';
		$tableWikitext[] = '|-';
		// Header row
		foreach ( $properties as $propertyName ) {
			$tableWikitext[] = '! ' . $this->getPropertyLabel( $propertyName );
		}
		// Data rows
		foreach ( $rows as $row ) {
			$tableWikitext[] = '|-';
			foreach ( $properties as $propertyName ) {
				$value = $row[$propertyName] ?? '';
				$tableWikitext[] = '| ' . $value;
			}
		}
		$tableWikitext[] = '|}';
		
		// Parse the entire table wikitext to convert links to HTML
		$tableHtml = $this->parseWikitext( implode( "\n", $tableWikitext ), $frame );
		$html[] = $tableHtml;
		$html[] = '</div>';

		return implode( "\n", $html );
	}

	/**
	 * Convert SMW subobject rows into display-safe arrays.
	 *
	 * @param \SMW\SemanticData[] $semanticRows
	 * @param SubobjectModel $subobject
	 * @param PPFrame $frame Parser frame for parsing wikitext links
	 * @return array<int,array<string,string>>
	 */
	private function convertSemanticRows( array $semanticRows, SubobjectModel $subobject, PPFrame $frame ): array {
		$rows = [];
		$properties = $subobject->getAllProperties();

		foreach ( $semanticRows as $rowData ) {
			$row = [];
			foreach ( $properties as $propertyName ) {
				// Check property type to determine how to render it
				$property = $this->propertyStore->readProperty( $propertyName );
				$isPageType = $property !== null && strtolower( $property->getDatatype() ) === 'page';
				
				if ( $isPageType ) {
					// For Page type properties, get the page titles and render as wikitext links
					$values = $this->getSemanticPropertyValues( $rowData, $propertyName, 'page' );
					if ( empty( $values ) ) {
						$row[$propertyName] = '';
						continue;
					}
					
					// Generate wikitext links: [[Page Title]]
					$formatted = array_map(
						function ( $value ) {
							return '[[' . $value . ']]';
						},
						$values
					);
					// Keep as wikitext - will be parsed when table is built
					$row[$propertyName] = implode( '<br />', $formatted );
				} else {
					// For other types, render as plain text
					$values = $this->getSemanticPropertyValues( $rowData, $propertyName, 'text' );
					if ( empty( $values ) ) {
						$row[$propertyName] = '';
						continue;
					}

					$formatted = array_map(
						function ( $value ) {
							return htmlspecialchars( $value, ENT_QUOTES );
						},
						$values
					);
					$row[$propertyName] = implode( '<br />', $formatted );
				}
			}
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * @param Title $title
	 * @return array<string,\SMW\SemanticData[]>
	 */
	private function collectPageSubobjects( Title $title ): array {
		$grouped = [];

		wfDebugLog( 'structuresync', 'collectPageSubobjects: Starting for page ' . $title->getPrefixedText() );

		try {
			/** @var object $store */
			$store = \SMW\StoreFactory::getStore();
			$subject = \SMW\DIWikiPage::newFromTitle( $title );
			$semanticData = $store->getSemanticData( $subject );
			$subSemantic = $semanticData->getSubSemanticData();

			wfDebugLog( 'structuresync', 'collectPageSubobjects: Found ' . count( $subSemantic ) . ' subobjects' );

			foreach ( $subSemantic as $subobjectData ) {
				wfDebugLog( 'structuresync', 'collectPageSubobjects: Processing subobject' );
				
				$types = $this->getSemanticPropertyValues(
					$subobjectData,
					'Has subgroup type',
					'subobject'
				);
				
				wfDebugLog( 'structuresync', 'collectPageSubobjects: Has subgroup type values: ' . json_encode( $types ) );
				
				if ( empty( $types ) ) {
					// Debug: log what properties this subobject actually has
					$allProps = [];
					// Get all properties by trying to read common property names
					$testProps = [ 'Has author', 'Has author order', 'Is corresponding author', 'Is co-first author' ];
					foreach ( $testProps as $testProp ) {
						$values = $this->getSemanticPropertyValues( $subobjectData, $testProp, 'text' );
						if ( !empty( $values ) ) {
							$allProps[] = $testProp . '=' . implode( ',', $values );
						}
					}
					wfDebugLog( 'structuresync', 'collectPageSubobjects: Subobject missing Has subgroup type. Found properties: ' . implode( ', ', $allProps ) );
					continue;
				}

				$typeName = $types[0];
				wfDebugLog( 'structuresync', 'collectPageSubobjects: Raw typeName: ' . $typeName );
				
				// Normalize: ensure we're using just the name (stringifyDataItem should handle this, but be defensive)
				if ( strpos( $typeName, 'Subobject:' ) === 0 ) {
					$typeName = substr( $typeName, strlen( 'Subobject:' ) );
				}
				
				wfDebugLog( 'structuresync', 'collectPageSubobjects: Normalized typeName: ' . $typeName );
				$grouped[$typeName][] = $subobjectData;
			}
			
			wfDebugLog( 'structuresync', 'collectPageSubobjects: Final grouped keys: ' . implode( ', ', array_keys( $grouped ) ) );
		} catch ( \Exception $e ) {
			wfDebugLog( 'structuresync', 'collectPageSubobjects: Exception: ' . $e->getMessage() );
			wfLogWarning( 'StructureSync: Failed to collect subobject data for ' . $title->getPrefixedText() . ': ' . $e->getMessage() );
		}

		return $grouped;
	}

	/**
	 * Extract property values from SemanticData.
	 *
	 * @param \SMW\SemanticData $semanticData
	 * @param string $propertyName
	 * @param string $type
	 * @return array
	 */
	private function getSemanticPropertyValues( $semanticData, string $propertyName, string $type = 'text' ): array {
		$values = [];
		try {
			$property = \SMW\DIProperty::newFromUserLabel( $propertyName );
			$rawValues = $semanticData->getPropertyValues( $property );
			wfDebugLog( 'structuresync', 'getSemanticPropertyValues: Property=' . $propertyName . ', Type=' . $type . ', Raw count=' . count( $rawValues ) );
			
			foreach ( $rawValues as $dataItem ) {
				$value = $this->stringifyDataItem( $dataItem, $type );
				wfDebugLog( 'structuresync', 'getSemanticPropertyValues: Extracted value=' . ( $value ?? 'NULL' ) );
				if ( $value !== null ) {
					$values[] = $value;
				}
			}
		} catch ( \Exception $e ) {
			wfDebugLog( 'structuresync', 'getSemanticPropertyValues: Exception for ' . $propertyName . ': ' . $e->getMessage() );
		}

		return $values;
	}

	/**
	 * Convert SMW data item to human-readable string.
	 *
	 * @param \SMWDataItem $dataItem
	 * @param string $type
	 * @return string|null
	 */
	private function stringifyDataItem( $dataItem, string $type ): ?string {
		if ( $dataItem instanceof \SMW\DIWikiPage ) {
			$title = $dataItem->getTitle();
			if ( !$title ) {
				wfDebugLog( 'structuresync', 'stringifyDataItem: DIWikiPage with null title' );
				return null;
			}
			
			$ns = $title->getNamespace();
			$text = $title->getText();
			$prefixed = $title->getPrefixedText();
			
			wfDebugLog( 'structuresync', 'stringifyDataItem: Type=' . $type . ', Namespace=' . $ns . ', Text=' . $text . ', Prefixed=' . $prefixed );
			
			// For subobject type, return just the text (without namespace prefix)
			// to match the subgroup name used as a key
			if ( $type === 'subobject' && $ns === NS_SUBOBJECT ) {
				wfDebugLog( 'structuresync', 'stringifyDataItem: Returning text only (subobject match): ' . $text );
				return $text;
			}
			
			wfDebugLog( 'structuresync', 'stringifyDataItem: Returning prefixed text: ' . $prefixed );
			return $prefixed;
		}

		if ( $dataItem instanceof \SMWDIBoolean ) {
			return $dataItem->getBoolean() ? 'Yes' : 'No';
		}

		if ( $dataItem instanceof \SMWDINumber ) {
			return (string)$dataItem->getNumber();
		}

		if ( $dataItem instanceof \SMWDITime ) {
			return $dataItem->getTimestamp()->format( 'Y-m-d' );
		}

		if ( $dataItem instanceof \SMWDIBlob || $dataItem instanceof \SMWDIString ) {
			return $dataItem->getString();
		}

		return null;
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
