<?php

namespace MediaWiki\Extension\SemanticSchemas\Schema;

use InvalidArgumentException;

/**
 * FormatModel
 * -----------
 * Immutable schema-level representation of a TemplateFormat category.
 *
 * Represents how category properties should be composed into display templates.
 * Format categories define the structure and styling for rendering category pages.
 *
 * Canonical schema structure:
 *
 *   name: string                     - Format name (e.g., "TemplateFormat/Sections")
 *   label: string                    - Human-readable label
 *   description: string              - What this format does
 *   wrapperTemplate: string|null     - Outer wrapper wikitext pattern
 *   propertyPattern: string|null     - How to wrap each property template call
 *   sectionSeparator: string|null    - Separator between sections/properties
 *   emptyValueBehavior: string       - "show" or "hide" for empty properties
 *
 * @since 1.0
 */
class FormatModel
{

	private string $name;
	private string $label;
	private string $description;
	private ?string $wrapperTemplate;
	private ?string $propertyPattern;
	private ?string $sectionSeparator;
	private string $emptyValueBehavior;

	/* -------------------------------------------------------------------------
	 * CONSTRUCTOR
	 * ------------------------------------------------------------------------- */

	/**
	 * @param string $name Format name (e.g., "TemplateFormat/Sections")
	 * @param array $data Schema data with optional keys:
	 *   - label: string
	 *   - description: string
	 *   - wrapperTemplate: string
	 *   - propertyPattern: string
	 *   - sectionSeparator: string
	 *   - emptyValueBehavior: "show" | "hide"
	 */
	public function __construct(string $name, array $data = [])
	{
		$name = trim($name);
		if ($name === '') {
			throw new InvalidArgumentException("Format name cannot be empty.");
		}
		$this->name = $name;

		$this->label = isset($data['label']) && trim($data['label']) !== ''
			? trim($data['label'])
			: $name;

		$this->description = isset($data['description'])
			? trim((string) $data['description'])
			: '';

		$this->wrapperTemplate = $this->cleanNullableString($data['wrapperTemplate'] ?? null);
		$this->propertyPattern = $this->cleanNullableString($data['propertyPattern'] ?? null);
		$this->sectionSeparator = $this->cleanNullableString($data['sectionSeparator'] ?? null);

		// Validate empty value behavior
		$behavior = strtolower(trim((string) ($data['emptyValueBehavior'] ?? 'hide')));
		if (!in_array($behavior, ['show', 'hide'], true)) {
			$behavior = 'hide';
		}
		$this->emptyValueBehavior = $behavior;
	}

	/* -------------------------------------------------------------------------
	 * HELPER
	 * ------------------------------------------------------------------------- */

	private function cleanNullableString($value): ?string
	{
		if ($value === null) {
			return null;
		}
		$v = trim((string) $value);
		return $v !== '' ? $v : null;
	}

	/* -------------------------------------------------------------------------
	 * ACCESSORS
	 * ------------------------------------------------------------------------- */

	public function getName(): string
	{
		return $this->name;
	}

	public function getLabel(): string
	{
		return $this->label;
	}

	public function getDescription(): string
	{
		return $this->description;
	}

	public function getWrapperTemplate(): ?string
	{
		return $this->wrapperTemplate;
	}

	public function getPropertyPattern(): ?string
	{
		return $this->propertyPattern;
	}

	public function getSectionSeparator(): ?string
	{
		return $this->sectionSeparator;
	}

	public function getEmptyValueBehavior(): string
	{
		return $this->emptyValueBehavior;
	}

	public function shouldShowEmptyValues(): bool
	{
		return $this->emptyValueBehavior === 'show';
	}

	/* -------------------------------------------------------------------------
	 * EXPORT
	 * ------------------------------------------------------------------------- */

	/**
	 * Export to array representation.
	 *
	 * @return array
	 */
	public function toArray(): array
	{
		return [
			'name' => $this->name,
			'label' => $this->label,
			'description' => $this->description,
			'wrapperTemplate' => $this->wrapperTemplate,
			'propertyPattern' => $this->propertyPattern,
			'sectionSeparator' => $this->sectionSeparator,
			'emptyValueBehavior' => $this->emptyValueBehavior,
		];
	}
}

