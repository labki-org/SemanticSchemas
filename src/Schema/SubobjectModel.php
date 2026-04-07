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
 *   - required/optional properties (as FieldDeclaration[])
 *
 * There is no inheritance or subtype merging.
 */
class SubobjectModel {

	private string $name;
	private string $label;
	private string $description;

	/** @var FieldDeclaration[] */
	private array $propertyFields;

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
		$this->propertyFields = FieldDeclaration::parseInput(
			$props, FieldDeclaration::TYPE_PROPERTY, "Subobject '{$name}'"
		);
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

	/** @return FieldDeclaration[] */
	public function getPropertyFields(): array {
		return $this->propertyFields;
	}

	/** @return string[] */
	public function getRequiredProperties(): array {
		return FieldDeclaration::filterNames( $this->propertyFields, true );
	}

	/** @return string[] */
	public function getOptionalProperties(): array {
		return FieldDeclaration::filterNames( $this->propertyFields, false );
	}

	/** @return string[] */
	public function getAllProperties(): array {
		return FieldDeclaration::names( $this->propertyFields );
	}

	public function isPropertyRequired( string $prop ): bool {
		foreach ( $this->propertyFields as $field ) {
			if ( $field->getName() === $prop ) {
				return $field->isRequired();
			}
		}
		return false;
	}

	/**
	 * @return array<array{name:string, required:bool}>
	 */
	public function getTaggedProperties(): array {
		return FieldDeclaration::tagged( $this->propertyFields );
	}

	/* -------------------------------------------------------------------------
	 * EXPORT
	 * ---------------------------------------------------------------------- */

	public function toArray(): array {
		return [
			'label' => $this->label,
			'description' => $this->description,
			'properties' => [
				'required' => $this->getRequiredProperties(),
				'optional' => $this->getOptionalProperties(),
			],
		];
	}
}
