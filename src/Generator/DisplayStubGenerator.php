<?php

namespace MediaWiki\Extension\SemanticSchemas\Generator;

use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\EffectiveCategoryModel;
use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;
use MediaWiki\Extension\SemanticSchemas\Util\Constants;
use MediaWiki\Extension\SemanticSchemas\Util\NamingHelper;

/**
 * Generates static display templates for Categories.
 *
 * This generator produces the 'Template:<Category>/display' page, which contains
 * a purely static wikitext definition (e.g., a table) where property values are
 * passed into specific render templates (e.g., {{Property/Email}}).
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
		PageCreator $pageCreator,
		WikiPropertyStore $propertyStore
	) {
		$this->pageCreator = $pageCreator;
		$this->propertyStore = $propertyStore;
	}

	/**
	 * Generate and save the display template stub.
	 *
	 * @param EffectiveCategoryModel $category
	 * @return array Result array with keys: 'created' (bool), 'updated' (bool), 'message' (string)
	 */
	public function generateOrUpdateDisplayStub( EffectiveCategoryModel $category ): array {
		$existed = $this->displayStubExists( $category->getName() );

		$titleText = $this->generateDisplayContent( $category );
		if ( $titleText === '' ) {
			return [
				'created' => false,
				'updated' => false,
				'error' => 'Failed to generate content or title.',
			];
		}

		return [
			'created' => !$existed,
			'updated' => $existed,
			'message' => $existed
				? "Display template updated: $titleText"
				: "Display template stub created: $titleText",
		];
	}

	/**
	 * @param CategoryModel $category
	 * @return string The prefixed title string of the generated page, or empty string on failure.
	 */
	private function generateDisplayContent( CategoryModel $category ): string {
		$categoryName = $category->getName();
		$title = $this->pageCreator->makeTitle( "$categoryName/display", NS_TEMPLATE );
		if ( !$title ) {
			return '';
		}

		$content = $this->generateWikitext( $category );

		$this->pageCreator->createOrUpdatePage(
			$title,
			$content,
			"SemanticSchemas: Update static display template for $categoryName"
		);

		return $title->getPrefixedText();
	}

	/**
	 * @param CategoryModel $category
	 * @return string
	 */
	private function generateWikitext( CategoryModel $category ): string {
		$format = $category->getDisplayFormat();

		if ( $format === 'sidebox' ) {
			$body = $this->generateSideboxBody( $category );
		} else {
			$body = $this->generateTableBody( $category );
		}

		return self::AUTO_REGENERATE_MARKER . "\n"
			. "<includeonly>\n"
			. $body
			. '[[Category:' . $category->getName() . "]]" . "\n"
			. '[[Category:' . Constants::SEMANTICSCHEMAS_MANAGED_CATEGORY . ']]' . "\n"
			. "</includeonly><noinclude>[[Category:SemanticSchemas-managed-display]]</noinclude>";
	}

	private function generateTableBody( CategoryModel $category ): string {
		$content = "{| class=\"wikitable source-semanticschemas\"\n";
		$content .= $this->buildCategoryHeadingRow( $category->getName(), $category->getLabel() );
		$content .= $this->generatePropertyRows( $category );
		$content .= $this->generateBacklinkRows( $category );
		$content .= "|}\n";

		return $content;
	}

	/**
	 * Build a category heading row with an inline edit link for display tables.
	 */
	private function buildCategoryHeadingRow( string $catName, string $catLabel ): string {
		$editLink = '<span style="float: right; font-weight: normal; font-size: 0.8em;">'
			. '[[Special:FormEdit/' . $catName . '/{{FULLPAGENAME}}|edit]]</span>';
		return "|-\n"
			. '! colspan="2" style="background-color: #eaecf0; text-align: center;" | '
			. $catLabel . ' ' . $editLink . "\n";
	}

	private function generateSideboxBody( CategoryModel $category ): string {
		$tableStyle = 'float: right; clear: right; margin: 0 0 1em 1em; width: 300px; '
			. 'background: #f8f9fa; border: 1px solid #a2a9b1; box-shadow: 0 4px 12px rgba(0,0,0,0.05);';
		$content = '{| class="wikitable source-semanticschemas-sidebox" style="' . $tableStyle . "\"\n";
		$content .= $this->buildCategoryHeadingRow( $category->getName(), $category->getLabel() );
		$content .= $this->generatePropertyRows( $category );
		$content .= $this->generateBacklinkRows( $category );
		$content .= "|}\n";

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
				$renderTemplate = 'Property/Default';
				$valueExpr = '{{{' . $paramName . '|}}}';
			}

			$valueCall = "{{" . $renderTemplate . " | value=" . $valueExpr . " }}";

			$out .= $this->buildConditionalRow(
				'{{{' . $paramName . '|}}}', $label, $valueCall
			);
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
	 * @param EffectiveCategoryModel $category
	 * @return array{status: string, message: string}
	 */
	public function generateIfAllowed( EffectiveCategoryModel $category ): array {
		$categoryName = $category->getName();
		$title = $this->pageCreator->makeTitle( "$categoryName/display", NS_TEMPLATE );

		if ( !$title ) {
			return [
				'status' => 'error',
				'message' => 'Failed to create title for display template.'
			];
		}

		// Template doesn't exist - create it
		if ( !$this->pageCreator->pageExists( $title ) ) {
			$this->generateDisplayContent( $category );
			return [
				'status' => 'created',
				'message' => "Display template created: {$title->getPrefixedText()}"
			];
		}

		// Template exists and has marker - safe to regenerate
		if ( $this->hasAutoRegenerateMarker( $title ) ) {
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

	/**
	 * Generate backlink rows for properties declared via "Show backlinks for" on the category.
	 *
	 * For each declared property, generates a single conditional row with an
	 * {{#ask:}} query that finds all pages linking here via that property.
	 * Rows are grouped under a "Backlinks" header, labeled by the backlink label.
	 */
	private function generateBacklinkRows( CategoryModel $category ): string {
		$backlinksFor = $category->getBacklinksFor();
		if ( $backlinksFor === [] ) {
			return '';
		}

		$rows = '';

		foreach ( $backlinksFor as $propName ) {
			$prop = $this->propertyStore->readProperty( $propName );
			if ( $prop === null ) {
			    // Implicitly page-typed
			    $inverseLabel = $propName;
			} elseif ( $prop->isPageType() ) {
				$inverseLabel = $prop->getInverseLabel() ?? $prop->getLabel();
			} else {
			    continue;
			}

			$askQuery = '{{#ask: [[' . $propName . '::{{FULLPAGENAME}}]]'
				. ' | format=list }}';

			$rows .= $this->buildConditionalRow( $askQuery, $reverseLabel, $askQuery );
		}

		if ( $rows === '' ) {
			return '';
		}

		return $this->buildBacklinksHeader() . $rows;
	}

	private function buildBacklinksHeader(): string {
		return "|-\n"
			. '! colspan="2" style="background-color: #eaecf0; text-align: center; '
			. 'font-size: 0.9em;" | Backlinks' . "\n";
	}

	/**
	 * Build a conditionally-visible wikitext table row.
	 * Hidden when $condition evaluates to empty/falsy.
	 */
	private function buildConditionalRow( string $condition, string $label, string $value ): string {
		$out = '{{#if: ' . $condition . ' |' . "\n";
		$out .= '{{!}}-' . "\n";
		$out .= '! ' . $label . "\n";
		$out .= '{{!}} ' . $value . "\n";
		$out .= "}}\n";
		return $out;
	}
}
