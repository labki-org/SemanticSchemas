<?php

namespace MediaWiki\Extension\StructureSync\Schema;

/**
 * SubobjectModel
 * --------------
 * Immutable value object that represents a repeatable subgroup definition.
 *
 * A Subobject is conceptually similar to a Category slice: it declares the
 * schema (required/optional properties) for structured, repeatable data that
 * belongs to a parent Category.
 *
 * Subobject pages live in the Subobject: namespace and are authored using
 * standard Semantic MediaWiki annotations such as:
 *   [[Has description::...]]
 *   [[Has required property::Property:Has author]]
 *   [[Has optional property::Property:Is corresponding author]]
 *
 * This model mirrors CategoryModel but without inheritance semantics.
 *
 * @psalm-immutable
 */
class SubobjectModel {

	/** @var string */
	private $name;

	/** @var string */
	private $label;

	/** @var string */
	private $description;

	/** @var string[] */
	private $requiredProperties;

	/** @var string[] */
	private $optionalProperties;

	/**
	 * @param string $name
	 * @param array $data
	 */
	public function __construct( string $name, array $data = [] ) {

		$name = trim( $name );
		if ( $name === '' ) {
			throw new \InvalidArgumentException( 'Subobject name cannot be empty' );
		}

		$this->name = $name;

		$label = $data['label'] ?? $name;
		$this->label = trim( $label ) !== '' ? (string)$label : $name;

		$this->description = (string)( $data['description'] ?? '' );

		$this->requiredProperties = self::normalizeList(
			$data['properties']['required'] ?? []
		);
		$this->optionalProperties = self::normalizeList(
			$data['properties']['optional'] ?? []
		);

		$overlap = array_intersect( $this->requiredProperties, $this->optionalProperties );
		if ( !empty( $overlap ) ) {
			throw new \InvalidArgumentException(
				'Subobject has properties in both required and optional lists: ' . implode( ', ', $overlap )
			);
		}
	}

	/**
	 * @param array $list
	 * @return string[]
	 */
	private static function normalizeList( array $list ): array {
		$list = array_map(
			static function ( $value ) {
				return trim( (string)$value );
			},
			$list
		);
		$list = array_filter( $list, static function ( $value ) {
			return $value !== '';
		} );

		return array_values( array_unique( $list ) );
	}

	public function getName(): string {
		return $this->name;
	}

	public function getLabel(): string {
		return $this->label;
	}

	public function getDescription(): string {
		return $this->description;
	}

	/**
	 * @return string[]
	 */
	public function getRequiredProperties(): array {
		return $this->requiredProperties;
	}

	/**
	 * @return string[]
	 */
	public function getOptionalProperties(): array {
		return $this->optionalProperties;
	}

	/**
	 * @return string[]
	 */
	public function getAllProperties(): array {
		return array_values(
			array_unique(
				array_merge( $this->requiredProperties, $this->optionalProperties )
			)
		);
	}

	public function isPropertyRequired( string $property ): bool {
		return in_array( $property, $this->requiredProperties, true );
	}

	public function isPropertyOptional( string $property ): bool {
		return in_array( $property, $this->optionalProperties, true );
	}

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

