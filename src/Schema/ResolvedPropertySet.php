<?php

namespace MediaWiki\Extension\SemanticSchemas\Schema;

/**
 * Immutable value object representing resolved properties across multiple categories.
 *
 * Holds the merged result of property resolution from one or more categories,
 * with deduplication, source attribution, and required/optional classification.
 *
 * Properties and subobjects are treated symmetrically:
 * - Shared items appear once with all source categories tracked
 * - Required promotion: if any category requires it, the merged result requires it
 * - Ordering: first-seen across categories (C3 accumulation order within each category)
 */
class ResolvedPropertySet {

	/* -------------------------------------------------------------------------
	 * PROPERTIES
	 * ---------------------------------------------------------------------- */

	/** @var string[] */
	private array $requiredProperties;

	/** @var string[] */
	private array $optionalProperties;

	/** @var array<string,string[]> */
	private array $propertySources;

	/* -------------------------------------------------------------------------
	 * SUBOBJECTS
	 * ---------------------------------------------------------------------- */

	/** @var string[] */
	private array $requiredSubobjects;

	/** @var string[] */
	private array $optionalSubobjects;

	/** @var array<string,string[]> */
	private array $subobjectSources;

	/* -------------------------------------------------------------------------
	 * METADATA
	 * ---------------------------------------------------------------------- */

	/** @var string[] */
	private array $categoryNames;

	/* -------------------------------------------------------------------------
	 * CONSTRUCTOR
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string[] $requiredProperties Deduplicated required property names
	 * @param string[] $optionalProperties Deduplicated optional property names (promoted away from required)
	 * @param array<string,string[]> $propertySources Map of property name to source category names
	 * @param string[] $requiredSubobjects Deduplicated required subobject names
	 * @param string[] $optionalSubobjects Deduplicated optional subobject names (promoted away from required)
	 * @param array<string,string[]> $subobjectSources Map of subobject name to source category names
	 * @param string[] $categoryNames All input category names
	 */
	public function __construct(
		array $requiredProperties,
		array $optionalProperties,
		array $propertySources,
		array $requiredSubobjects,
		array $optionalSubobjects,
		array $subobjectSources,
		array $categoryNames
	) {
		$this->requiredProperties = $requiredProperties;
		$this->optionalProperties = $optionalProperties;
		$this->propertySources = $propertySources;
		$this->requiredSubobjects = $requiredSubobjects;
		$this->optionalSubobjects = $optionalSubobjects;
		$this->subobjectSources = $subobjectSources;
		$this->categoryNames = $categoryNames;
	}

	/* -------------------------------------------------------------------------
	 * FACTORY METHODS
	 * ---------------------------------------------------------------------- */

	/**
	 * Return an empty ResolvedPropertySet (zero categories).
	 */
	public static function empty(): self {
		return new self( [], [], [], [], [], [], [] );
	}

	/* -------------------------------------------------------------------------
	 * PROPERTY ACCESSORS
	 * ---------------------------------------------------------------------- */

	/**
	 * Return all required properties.
	 *
	 * @return string[]
	 */
	public function getRequiredProperties(): array {
		return $this->requiredProperties;
	}

	/**
	 * Return all optional properties (already promoted away from required).
	 *
	 * @return string[]
	 */
	public function getOptionalProperties(): array {
		return $this->optionalProperties;
	}

	/**
	 * Return all properties (required + optional merged, deduplicated).
	 *
	 * Follows CategoryModel::getAllProperties pattern.
	 *
	 * @return string[]
	 */
	public function getAllProperties(): array {
		return array_values( array_unique(
			array_merge( $this->requiredProperties, $this->optionalProperties )
		) );
	}

	/**
	 * Return source category names for a given property.
	 *
	 * @param string $property Property name
	 * @return string[] Category names that define this property (empty if not found)
	 */
	public function getPropertySources( string $property ): array {
		return $this->propertySources[$property] ?? [];
	}

	/**
	 * Check if a property is shared across multiple categories.
	 *
	 * @param string $property Property name
	 * @return bool True if property appears in 2+ categories
	 */
	public function isSharedProperty( string $property ): bool {
		return count( $this->getPropertySources( $property ) ) > 1;
	}

	/* -------------------------------------------------------------------------
	 * SUBOBJECT ACCESSORS
	 * ---------------------------------------------------------------------- */

	/**
	 * Return all required subobjects.
	 *
	 * @return string[]
	 */
	public function getRequiredSubobjects(): array {
		return $this->requiredSubobjects;
	}

	/**
	 * Return all optional subobjects (already promoted away from required).
	 *
	 * @return string[]
	 */
	public function getOptionalSubobjects(): array {
		return $this->optionalSubobjects;
	}

	/**
	 * Return all subobjects (required + optional merged, deduplicated).
	 *
	 * @return string[]
	 */
	public function getAllSubobjects(): array {
		return array_values( array_unique(
			array_merge( $this->requiredSubobjects, $this->optionalSubobjects )
		) );
	}

	/**
	 * Return source category names for a given subobject.
	 *
	 * @param string $subobject Subobject name
	 * @return string[] Category names that define this subobject (empty if not found)
	 */
	public function getSubobjectSources( string $subobject ): array {
		return $this->subobjectSources[$subobject] ?? [];
	}

	/**
	 * Check if a subobject is shared across multiple categories.
	 *
	 * @param string $subobject Subobject name
	 * @return bool True if subobject appears in 2+ categories
	 */
	public function isSharedSubobject( string $subobject ): bool {
		return count( $this->getSubobjectSources( $subobject ) ) > 1;
	}

	/* -------------------------------------------------------------------------
	 * METADATA ACCESSORS
	 * ---------------------------------------------------------------------- */

	/**
	 * Return all input category names.
	 *
	 * @return string[]
	 */
	public function getCategoryNames(): array {
		return $this->categoryNames;
	}
}
