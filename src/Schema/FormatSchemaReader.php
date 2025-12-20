<?php

namespace MediaWiki\Extension\SemanticSchemas\Schema;

use MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;
use MediaWiki\Extension\SemanticSchemas\Util\NamingHelper;

/**
 * FormatSchemaReader
 * ------------------
 * Reads TemplateFormat category schemas and provides methods to compose
 * property template calls according to format specifications.
 *
 * This makes format behavior completely data-driven - all layout logic
 * is defined in wiki categories, not hardcoded.
 *
 * Usage:
 *   $reader = new FormatSchemaReader();
 *   $format = $reader->readFormat('TemplateFormat/Table');
 *   $wikitext = $reader->composePropertyCalls($format, $category);
 *
 * @since 1.0
 */
class FormatSchemaReader
{

	private WikiCategoryStore $categoryStore;
	private WikiPropertyStore $propertyStore;

	public function __construct(
		?WikiCategoryStore $categoryStore = null,
		?WikiPropertyStore $propertyStore = null
	) {
		$this->categoryStore = $categoryStore ?? new WikiCategoryStore();
		$this->propertyStore = $propertyStore ?? new WikiPropertyStore();
	}

	/* -------------------------------------------------------------------------
	 * FORMAT READING
	 * ------------------------------------------------------------------------- */

	/**
	 * Read a TemplateFormat category and return a FormatModel.
	 *
	 * @param string $formatName Format category name (e.g., "TemplateFormat/Sections")
	 * @return FormatModel|null Format model, or null if not found
	 */
	public function readFormat(string $formatName): ?FormatModel
	{
		$category = $this->categoryStore->readCategory($formatName);
		if (!$category) {
			return null;
		}

		// Extract format-specific properties
		$data = [
			'label' => $category->getLabel(),
			'description' => $category->getDescription(),
			'wrapperTemplate' => $this->getPropertyValue($formatName, 'Has wrapper template'),
			'propertyPattern' => $this->getPropertyValue($formatName, 'Has property template pattern'),
			'sectionSeparator' => $this->getPropertyValue($formatName, 'Has section separator'),
			'emptyValueBehavior' => $this->getPropertyValue($formatName, 'Has empty value behavior') ?? 'hide',
		];

		return new FormatModel($formatName, $data);
	}

	/**
	 * Get a property value from a category using SMW query.
	 *
	 * @param string $categoryName Category to query
	 * @param string $propertyName Property to fetch
	 * @return string|null Property value
	 */
	private function getPropertyValue(string $categoryName, string $propertyName): ?string
	{
		if (!defined('SMW_VERSION')) {
			return null;
		}

		$store = \SMW\StoreFactory::getStore();
		$subject = \SMW\DIWikiPage::newFromTitle(
			\Title::makeTitle(NS_CATEGORY, $categoryName)
		);

		$propertyDI = \SMWDIProperty::newFromUserLabel($propertyName);
		$values = $store->getPropertyValues($subject, $propertyDI);

		if (empty($values)) {
			return null;
		}

		$value = reset($values);
		if ($value instanceof \SMWDIBlob) {
			return trim($value->getString());
		}

		return null;
	}

	/* -------------------------------------------------------------------------
	 * PROPERTY COMPOSITION
	 * ------------------------------------------------------------------------- */

	/**
	 * Compose property template calls for a category using a format.
	 *
	 * This is the main method that generates the display template wikitext
	 * by composing property template calls according to the format schema.
	 *
	 * @param FormatModel $format Format to use
	 * @param CategoryModel $category Category whose properties to render
	 * @return string Generated wikitext
	 */
	public function composePropertyCalls(FormatModel $format, CategoryModel $category): string
	{
		$sections = $category->getDisplaySections();
		$lines = [];

		foreach ($sections as $section) {
			$sectionName = $section['name'];
			$properties = $section['properties'];

			if (empty($properties)) {
				continue;
			}

			// Section heading
			$lines[] = '== ' . $sectionName . ' ==';
			$lines[] = '';

			// Compose property calls
			foreach ($properties as $propertyName) {
				$paramName = NamingHelper::propertyToParameter($propertyName);
				$templateCall = $this->makePropertyTemplateCall($propertyName, $paramName, $format);

				if ($templateCall !== null) {
					$lines[] = $templateCall;
				}
			}

			// Section separator
			if ($format->getSectionSeparator() !== null) {
				$lines[] = $format->getSectionSeparator();
			}

			$lines[] = '';
		}

		// Apply wrapper template if defined
		$content = implode("\n", $lines);
		if ($format->getWrapperTemplate() !== null) {
			$wrapper = $format->getWrapperTemplate();
			// Replace {{{content}}} placeholder with generated content
			$content = str_replace('{{{content}}}', $content, $wrapper);
		}

		return $content;
	}

	/**
	 * Make a property template call with label.
	 *
	 * Generates: '''Label:''' {{Property/PropertyName|{{{param_name|}}}}}
	 *
	 * Note: NO spaces around pipes and parameters to prevent whitespace in mailto: links.
	 * MediaWiki preserves all whitespace in template parameters.
	 *
	 * @param string $propertyName Property name
	 * @param string $paramName Template parameter name
	 * @param FormatModel $format Format for pattern wrapping
	 * @return string|null Template call wikitext
	 */
	private function makePropertyTemplateCall(
		string $propertyName,
		string $paramName,
		FormatModel $format
	): ?string {
		// Get property label
		$property = $this->propertyStore->readProperty($propertyName);
		$label = $property ? $property->getLabel() : NamingHelper::generatePropertyLabel($propertyName);

		$templateName = 'Property/' . $propertyName;

		// Generate template call with NO spaces around pipes
		// This prevents whitespace from being passed to nested templates
		$call = '{{' . $templateName . '|{{{' . $paramName . '|}}}}}';

		// Add label before the template call
		$fullCall = "'''" . $label . ":''' " . $call;

		// Apply property pattern if defined
		if ($format->getPropertyPattern() !== null) {
			$pattern = $format->getPropertyPattern();
			// Replace {{{property}}} placeholder with the property call
			$fullCall = str_replace('{{{property}}}', $fullCall, $pattern);
		}

		return $fullCall;
	}

	/* -------------------------------------------------------------------------
	 * DEFAULT FORMATS
	 * ------------------------------------------------------------------------- */

	/**
	 * Get default format model when no format is specified or found.
	 *
	 * Returns a basic section-based format.
	 *
	 * @return FormatModel
	 */
	public function getDefaultFormat(): FormatModel
	{
		return new FormatModel('TemplateFormat/Sections', [
			'label' => 'Sections',
			'description' => 'Default section-based layout',
			'wrapperTemplate' => null,
			'propertyPattern' => null,
			'sectionSeparator' => null,
			'emptyValueBehavior' => 'hide',
		]);
	}

	/**
	 * Read format by name, falling back to default if not found.
	 *
	 * @param string|null $formatName Format name, or null for default
	 * @return FormatModel
	 */
	public function readFormatOrDefault(?string $formatName): FormatModel
	{
		if ($formatName === null || trim($formatName) === '') {
			return $this->getDefaultFormat();
		}

		$format = $this->readFormat($formatName);
		return $format ?? $this->getDefaultFormat();
	}
}

