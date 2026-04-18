<?php

namespace MediaWiki\Extension\SemanticSchemas\Generator;

use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\EffectiveCategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\InheritanceResolver;
use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;
use MediaWiki\Extension\SemanticSchemas\Util\NamingHelper;
use MediaWiki\Language\Language;

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
	private Language $language;

	public function __construct(
		PageCreator $pageCreator,
		WikiPropertyStore $propertyStore,
		Language $language
	) {
		$this->pageCreator = $pageCreator;
		$this->propertyStore = $propertyStore;
		$this->language = $language;
	}

	/**
	 * Generate and save the display template stub.
	 *
	 * @param EffectiveCategoryModel $category
	 * @param ?InheritanceResolver $resolver For resolving subobject category properties
	 * @return array Result array with keys: 'created' (bool), 'updated' (bool), 'message' (string)
	 */
	public function generateOrUpdateDisplayStub(
		EffectiveCategoryModel $category,
		?InheritanceResolver $resolver = null
	): array {
		$existed = $this->displayStubExists( $category->getName() );

		$titleText = $this->generateDisplayContent( $category, $resolver );
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
	 * @param ?InheritanceResolver $resolver For resolving subobject category properties
	 * @return string
	 */
	public function generateWikitext(
		CategoryModel $category,
		?InheritanceResolver $resolver = null
	): string {
		$format = $category->getDisplayFormat();

		if ( $format === 'sidebox' ) {
			$body = $this->generateSideboxBody( $category );
		} else {
			$body = $this->generateTableBody( $category );
		}

		$body .= $this->generateSubobjectSections( $category, $resolver );

		$categoryPrefix = $this->language->getFormattedNsText( NS_CATEGORY );

		$body = self::AUTO_REGENERATE_MARKER . "\n"
			. "<includeonly>\n"
			. $body
			. "</includeonly><noinclude>[[" . $categoryPrefix . ":SemanticSchemas-managed-display]]</noinclude>";

		return $body;
	}

	/**
	 * @param CategoryModel $category
	 * @param ?InheritanceResolver $resolver
	 * @return string The prefixed title string of the generated page, or empty string on failure.
	 */
	private function generateDisplayContent(
		CategoryModel $category,
		?InheritanceResolver $resolver = null
	): string {
		$categoryName = $category->getName();
		$title = $this->pageCreator->makeTitle( "$categoryName/display", NS_TEMPLATE );
		if ( !$title ) {
			return '';
		}

		$content = $this->generateWikitext( $category, $resolver );

		$this->pageCreator->createOrUpdatePage(
			$title,
			$content,
			"SemanticSchemas: Update static display template for $categoryName"
		);

		return $title->getPrefixedText();
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
	 * Generate table rows for all properties in a category.
	 */
	private function generatePropertyRows( CategoryModel $category ): string {
		$out = "";

		foreach ( $category->getPropertyFields() as $field ) {
			$propName = $field->getName();
			$property = $this->propertyStore->readProperty( $propName );
			$paramName = $field->getParameterName();

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
	 * Uses {{PAGENAME:}} to strip any existing namespace prefix before re-adding it,
	 * making the expression idempotent. This is needed because display templates are
	 * called from two contexts:
	 *   - Dispatcher templates: values come from forms as bare names ("MyPage")
	 *   - #ask queries: values come from SMW as prefixed names ("Namespace:MyPage")
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

		// Page-type with namespace restriction: add namespace only when the
		// value has none. If the user (or an #ask query) provides a value
		// that already has a namespace, preserve it as-is.
		if ( $property->allowsMultipleValues() ) {
			return '{{#arraymap:{{{' . $paramName . '|}}}|,|@@item@@|'
				. '{{#ifeq:{{FULLPAGENAME:@@item@@}}|{{PAGENAME:@@item@@}}|'
				. $allowedNamespace . ':}}@@item@@|,&#32;}}';
		}

		return '{{#if:{{{' . $paramName . '|}}}|'
			. '{{#ifeq:{{FULLPAGENAME:{{{' . $paramName . '|}}}}}|{{PAGENAME:{{{' . $paramName . '|}}}}}'
			. '|' . $allowedNamespace . ':}}{{{' . $paramName . '|}}}|}}';
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
	 * @param ?InheritanceResolver $resolver For resolving subobject category properties
	 * @return array{status: string, message: string}
	 */
	public function generateIfAllowed(
		EffectiveCategoryModel $category,
		?InheritanceResolver $resolver = null
	): array {
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
			$this->generateDisplayContent( $category, $resolver );
			return [
				'status' => 'created',
				'message' => "Display template created: {$title->getPrefixedText()}"
			];
		}

		// Template exists and has marker - safe to regenerate
		if ( $this->hasAutoRegenerateMarker( $title ) ) {
			$this->generateDisplayContent( $category, $resolver );
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
				$inverseLabel = $prop->getInverseLabel() ?? $prop->getName();
			} else {
				continue;
			}

			$askQuery = '{{#ask: [[' . $propName . '::{{FULLPAGENAME}}]]'
				. ' | format=list }}';

			$rows .= $this->buildConditionalRow( $askQuery, $inverseLabel, $askQuery );
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
	 * Generate #ask sections for subobject categories, using their display templates.
	 */
	private function generateSubobjectSections(
		CategoryModel $category,
		?InheritanceResolver $resolver
	): string {
		if ( $resolver === null ) {
			return '';
		}

		$subFields = $category->getSubobjectFields();
		if ( !$subFields ) {
			return '';
		}

		$sections = '';
		$catNs = $this->language->getFormattedNsText( NS_CATEGORY );

		foreach ( $subFields as $field ) {
			$subName = $field->getName();
			if ( !$resolver->hasCategory( $subName ) ) {
				continue;
			}

			$sub = $resolver->getEffectiveCategory( $subName );

			$fields = $sub->getPropertyFields();
			if ( !$fields ) {
				continue;
			}

			$label = $sub->getLabel() ?: $sub->getName();
			$sections .= '=== ' . $label . " ===\n";
			$sections .= '{{#ask: [[-Has subobject::{{FULLPAGENAME}}]]'
				. ' [[' . $catNs . ':' . $subName . ']]' . "\n";

			foreach ( $fields as $f ) {
				$sections .= ' | ?' . $f->getName() . ' = ' . $f->getParameterName() . "\n";
			}

			$sections .= ' | format=template' . "\n";
			$sections .= ' | template=' . $subName . '/display' . "\n";
			$sections .= ' | named args=yes' . "\n";
			$sections .= ' | link=none' . "\n";
			$sections .= ' | mainlabel=-' . "\n";
			$sections .= '}}' . "\n";
		}

		return $sections;
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
