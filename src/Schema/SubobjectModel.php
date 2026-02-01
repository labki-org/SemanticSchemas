<?php

namespace MediaWiki\Extension\SemanticSchemas\Schema;

use InvalidArgumentException;
use MediaWiki\Extension\SemanticSchemas\Util\NamingHelper;

/**
 * Immutable schema-level representation of a Subobject.
 *
 * A Subobject is a repeatable structured data group belonging to a Category.
 * It defines only:
 *   - label
 *   - description
 *   - required/optional properties
 *
 * There is no inheritance or subtype merging.
 */
class SubobjectModel {

	private string $name;
	private string $label;
	private string $description;

	/** @var string[] */
	private array $requiredProperties;

	/** @var string[] */
	private array $optionalProperties;

	/* -------------------------------------------------------------------------
	 * CONSTRUCTOR
	 * ---------------------------------------------------------------------- */

	public function __construct( string $name, array $data = [] ) {
		/* -------------------- Name -------------------- */
		$name = trim( $name );
		if ( $name === '' ) {
			throw new InvalidArgumentException( "Subobject name cannot be empty." );
		}
		if ( preg_match( '/[<>{}|#]/', $name ) ) {
			throw new InvalidArgumentException(
				"Subobject name '{$name}' contains invalid characters."
			);
		}
		$this->name = $name;

		/* -------------------- Label -------------------- */
		$rawLabel = $data['label'] ?? $name;
		$cleanLabel = trim( (string)$rawLabel );

		if ( $cleanLabel === '' ) {
			// Normalize to a generated human-readable form
			$this->label = NamingHelper::generatePropertyLabel( $name );
		} else {
			$this->label = $cleanLabel;
		}

		/* -------------------- Description -------------------- */
		$this->description = isset( $data['description'] )
			? trim( (string)$data['description'] )
			: '';

		/* -------------------- Properties -------------------- */
		$props = $data['properties'] ?? [];
		if ( !is_array( $props ) ) {
			throw new InvalidArgumentException(
				"Subobject '{$name}': 'properties' must be an array."
			);
		}

		$req = $props['required'] ?? [];
		$opt = $props['optional'] ?? [];

		if ( !is_array( $req ) || !is_array( $opt ) ) {
			throw new InvalidArgumentException(
				"Subobject '{$name}': 'properties.required' and 'properties.optional' must be arrays."
			);
		}

		$this->requiredProperties = NamingHelper::normalizeList( $req );
		$this->optionalProperties = NamingHelper::normalizeList( $opt );

		$overlap = array_intersect( $this->requiredProperties, $this->optionalProperties );
		if ( $overlap !== [] ) {
			throw new InvalidArgumentException(
				"Subobject '{$name}' has properties listed as both required and optional: "
				. implode( ', ', $overlap )
			);
		}
	}

	/* -------------------------------------------------------------------------
	 * ACCESSORS
	 * ---------------------------------------------------------------------- */

	public function getName(): string {
		return $this->name;
	}

	public function getLabel(): string {
		return $this->label;
	}

	public function getDescription(): string {
		return $this->description;
	}

	/** @return string[] */
	public function getRequiredProperties(): array {
		return $this->requiredProperties;
	}

	/** @return string[] */
	public function getOptionalProperties(): array {
		return $this->optionalProperties;
	}

	/** @return string[] */
	public function getAllProperties(): array {
		return array_values(
			array_unique(
				array_merge( $this->requiredProperties, $this->optionalProperties )
			)
		);
	}

	public function isPropertyRequired( string $prop ): bool {
		return in_array( $prop, $this->requiredProperties, true );
	}

	/* -------------------------------------------------------------------------
	 * EXPORT
	 * ---------------------------------------------------------------------- */

	public function toArray(): array {
		return [
			'label' => $this->label,
			'description' => $this->description,
			'properties' => [
				'required' => $this->requiredProperties,
				'optional' => $this->optionalProperties,
			],
		];
	}
}
