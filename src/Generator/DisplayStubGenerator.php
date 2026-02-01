<?php

namespace MediaWiki\Extension\SemanticSchemas\Generator;

use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;
use MediaWiki\Extension\SemanticSchemas\Util\NamingHelper;

/**
 * Generates static display templates for Categories.
 *
 * This generator produces the 'Template:<Category>/display' page, which contains
 * a purely static wikitext definition (e.g., a table) where property values are
 * passed into specific render templates (e.g., {{Template:Property/Email}}).
 *
 * This "Generation-Time Resolution" replaces the older dynamic runtime system,
 * ensuring reliability, cacheability, and compatibility with the standard MediaWiki parser.
 *
 * Display templates include a special marker comment that indicates they are safe
 * to auto-regenerate. If a user removes this marker, the template is considered
 * "customized" and will be preserved during automatic updates.
 */
class DisplayStubGenerator {

	/**
	 * Marker comment that indicates the display template is safe to auto-regenerate.
	 * Users can remove this line to prevent automatic updates.
	 */
	public const AUTO_REGENERATE_MARKER =
		'<!-- SemanticSchemas:auto-regenerate - Remove this line to prevent automatic updates -->';

	private PageCreator $pageCreator;
	private WikiPropertyStore $propertyStore;

	public function __construct(
		?PageCreator $pageCreator = null,
		?WikiPropertyStore $propertyStore = null
	) {
		$this->pageCreator = $pageCreator ?? new PageCreator();
		$this->propertyStore = $propertyStore ?? new WikiPropertyStore( $this->pageCreator );
	}

	/**
	 * Generate and save the display template stub.
	 *
	 * @param CategoryModel $category
	 * @return array Result array with keys: 'created' (bool), 'updated' (bool), 'message' (string)
	 */
	public function generateOrUpdateDisplayStub( CategoryModel $category ): array {
		$titleText = $this->generateDisplayContent( $category );
		if ( $titleText === '' ) {
			return [
				'created' => false,
				'updated' => false,
				'message' => 'Failed to generate content or title.'
			];
		}

		return [
			'created' => true,
			'updated' => true,
			'message' => "Display stub updated: $titleText"
		];
	}

	/**
	 * Internal generation logic.
	 *
	 * @param CategoryModel $category
	 * @return string The prefixed title string of the generated page, or empty string on failure.
	 */
	private function generateDisplayContent( CategoryModel $category ): string {
		$categoryName = $category->getName();
		// Use NS_TEMPLATE constant
		$title = $this->pageCreator->makeTitle( "$categoryName/display", NS_TEMPLATE );
		if ( !$title ) {
			return '';
		}

		$content = $this->buildWikitext( $category );

		$this->pageCreator->createOrUpdatePage(
			$title,
			$content,
			"SemanticSchemas: Update static display template for $categoryName"
		);

		return $title->getPrefixedText();
	}

	/**
	 * Construct the wikitext content for the display template.
	 *
	 * @param CategoryModel $category
	 * @return string
	 */
	private function buildWikitext( CategoryModel $category ): string {
		$format = $category->getDisplayFormat();

		if ( $format === 'sidebox' ) {
			wfDebugLog( 'SemanticSchemas', 'Generating sidebox display for ' . $category->getName() );
			return $this->generateSideboxWikitext( $category );
		}

		if ( $format === 'sections' ) {
			return $this->generateSectionsWikitext( $category );
		}

		// Default to table
		return $this->generateTableWikitext( $category );
	}

	private function generateTableWikitext( CategoryModel $category ): string {
		$content = self::AUTO_REGENERATE_MARKER . "\n";
		$content .= "<includeonly>\n";
		$content .= "{| class=\"wikitable source-semanticschemas\"\n";
		$content .= "! Property !! Value\n";

		$content .= $this->generatePropertyRows( $category );

		$content .= "|}\n";
		$content .= "</includeonly><noinclude>[[Category:SemanticSchemas-managed-display]]</noinclude>";

		return $content;
	}

	private function generateSideboxWikitext( CategoryModel $category ): string {
		// Infobox style: floated right, distinct styling
		$content = self::AUTO_REGENERATE_MARKER . "\n";
		$content .= "<includeonly>\n";
		$tableStyle = 'float: right; clear: right; margin: 0 0 1em 1em; width: 300px; '
			. 'background: #f8f9fa; border: 1px solid #a2a9b1; box-shadow: 0 4px 12px rgba(0,0,0,0.05);';
		$content .= '{| class="wikitable source-semanticschemas-sidebox" style="' . $tableStyle . "\"\n";
		$captionStyle = 'font-size: 120%; font-weight: bold; background-color: #eaecf0;';
		$content .= '|+ style="' . $captionStyle . '" | ' . $category->getLabel() . "\n";

		$content .= $this->generatePropertyRows( $category );

		$content .= "|}\n";
		$content .= "</includeonly><noinclude>[[Category:SemanticSchemas-managed-display]]</noinclude>";

		return $content;
	}

	private function generateSectionsWikitext( CategoryModel $category ): string {
		$content = self::AUTO_REGENERATE_MARKER . "\n";
		$content .= "<includeonly>\n";
		$content .= "{| class=\"wikitable source-semanticschemas-sections\" style=\"width: 100%;\"\n";

		$sections = $category->getDisplaySections();

		// Convert sections to map to track used properties
		$usedProperties = [];

		foreach ( $sections as $section ) {
			$name = $section['name'];
			$props = $section['properties'];

			// Section Header
			$content .= "|-\n";
			$content .= "! colspan=\"2\" style=\"background-color: #eaecf0; text-align: center;\" | " . $name . "\n";

			// Section Properties
			$content .= $this->generatePropertyRows( $category, $props );

			foreach ( $props as $p ) {
				$usedProperties[$p] = true;
			}
		}

		// Catch-all for properties NOT in any section
		$allProps = $category->getAllProperties();
		$remaining = [];
		foreach ( $allProps as $p ) {
			if ( !isset( $usedProperties[$p] ) ) {
				$remaining[] = $p;
			}
		}

		if ( !empty( $remaining ) ) {
			$content .= "|-\n";
			$content .= "! colspan=\"2\" style=\"background-color: #eaecf0; text-align: center;\" | Other Properties\n";
			$content .= $this->generatePropertyRows( $category, $remaining );
		}

		$content .= "|}\n";
		$content .= "</includeonly><noinclude>[[Category:SemanticSchemas-managed-display]]</noinclude>";

		return $content;
	}

	/**
	 * Generate table rows for a list of properties.
	 * Default to all category properties if $properties is null.
	 */
	private function generatePropertyRows( CategoryModel $category, ?array $properties = null ): string {
		$out = "";
		$targetProperties = $properties ?? $category->getAllProperties();

		foreach ( $targetProperties as $propName ) {
			$property = $this->propertyStore->readProperty( $propName );
			$paramName = NamingHelper::propertyToParameter( $propName );

			if ( $property ) {
				$label = $property->getLabel();
				$renderTemplate = $property->getRenderTemplate();
				$valueExpr = $this->buildValueExpression( $property, $paramName );
			} else {
				// Fallback for properties without wiki pages:
				// Generate a readable label and use default render template
				$label = NamingHelper::generatePropertyLabel( $propName );
				$renderTemplate = 'Template:Property/Default';
				$valueExpr = '{{{' . $paramName . '|}}}';
			}

			// Construct the template call:
			// {{ Template:Property/Email | value={{{email|}}} }}
			$valueCall = "{{" . $renderTemplate . " | value=" . $valueExpr . " }}";

			$out .= "|-\n";
			// Standard row format works for both table and simplified infobox
			$out .= "! " . $label . "\n";
			$out .= "| " . $valueCall . "\n";
		}
		return $out;
	}

	/**
	 * Build the value expression for a property, adding namespace prefix if needed.
	 *
	 * For Page-type properties with allowedNamespace, prefixes values with the namespace.
	 * For multi-value properties, uses #arraymap to prefix each value.
	 * For other properties, returns the raw parameter reference.
	 *
	 * @param \MediaWiki\Extension\SemanticSchemas\Schema\PropertyModel $property
	 * @param string $paramName
	 * @return string Wikitext expression for the value
	 */
	private function buildValueExpression( $property, string $paramName ): string {
		$allowedNamespace = $property->getAllowedNamespace();

		// Non-Page or no namespace restriction: simple parameter reference
		if ( !$property->isPageType() || $allowedNamespace === null || $allowedNamespace === '' ) {
			return '{{{' . $paramName . '|}}}';
		}

		// Page-type with namespace restriction: prefix namespace to values
		if ( $property->allowsMultipleValues() ) {
			// Multi-value: use #arraymap to prefix each value
			// Input: "Value1,Value2" -> Output: "Namespace:Value1,Namespace:Value2"
			return '{{#arraymap:{{{' . $paramName . '|}}}|,|@@item@@|' .
				$allowedNamespace . ':@@item@@|,&#32;}}';
		}

		// Single value: conditional prefix (empty if no value)
		return '{{#if:{{{' . $paramName . '|}}}|' . $allowedNamespace . ':{{{' . $paramName . '|}}}|}}';
	}

	/**
	 * Check if the display stub already exists.
	 *
	 * @param string $categoryName
	 * @return bool
	 */
	public function displayStubExists( string $categoryName ): bool {
		$title = $this->pageCreator->makeTitle( $categoryName . "/display", NS_TEMPLATE );
		return $title && $this->pageCreator->pageExists( $title );
	}

	/**
	 * Generate display template only if allowed by the auto-regenerate marker.
	 *
	 * This method implements the conditional regeneration logic:
	 * - If the display template doesn't exist, generate it (with marker)
	 * - If it exists AND has the marker, regenerate it (user hasn't customized)
	 * - If it exists but NO marker, preserve it (user customized)
	 *
	 * @param CategoryModel $category
	 * @return array Result array with keys:
	 *   - 'status' (string): 'created', 'updated', or 'preserved'
	 *   - 'message' (string): Human-readable status message
	 */
	public function generateIfAllowed( CategoryModel $category ): array {
		$categoryName = $category->getName();
		$title = $this->pageCreator->makeTitle( "$categoryName/display", NS_TEMPLATE );

		if ( !$title ) {
			return [
				'status' => 'error',
				'message' => 'Failed to create title for display template.'
			];
		}

		// Check if the page exists
		if ( !$this->pageCreator->pageExists( $title ) ) {
			// Template doesn't exist - create it
			$this->generateDisplayContent( $category );
			return [
				'status' => 'created',
				'message' => "Display template created: {$title->getPrefixedText()}"
			];
		}

		// Template exists - check for the auto-regenerate marker
		if ( $this->hasAutoRegenerateMarker( $title ) ) {
			// Safe to regenerate
			$this->generateDisplayContent( $category );
			return [
				'status' => 'updated',
				'message' => "Display template updated: {$title->getPrefixedText()}"
			];
		}

		// User has customized the template (marker removed) - preserve it
		return [
			'status' => 'preserved',
			'message' => "Display template was not updated because it has been customized. " .
				"The template may be out of date if category properties have changed."
		];
	}

	/**
	 * Check if a display template has the auto-regenerate marker.
	 *
	 * @param \MediaWiki\Title\Title $title
	 * @return bool True if the template has the marker (safe to regenerate)
	 */
	public function hasAutoRegenerateMarker( $title ): bool {
		$content = $this->pageCreator->getPageContent( $title );
		if ( $content === null ) {
			return false;
		}
		return str_contains( $content, self::AUTO_REGENERATE_MARKER );
	}
}
