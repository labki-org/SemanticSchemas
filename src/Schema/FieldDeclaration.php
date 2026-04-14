<?php

namespace MediaWiki\Extension\SemanticSchemas\Schema;

use InvalidArgumentException;

/**
 * Immutable value object representing a field declaration on a category page.
 *
 * A field declaration records that a category includes a specific property
 * (TYPE_PROPERTY) or subobject category reference (TYPE_SUBOBJECT), along
 * with whether that field is required.
 *
 * Each FieldDeclaration serializes to a {{#subobject:}} block on the
 * category page, using @category to distinguish property fields from
 * subobject fields.
 */
class FieldDeclaration {

	public const TYPE_PROPERTY = 'property';
	public const TYPE_SUBOBJECT = 'subobject';

	/**
	 * Configuration for each field type: @category value, reference
	 * property name, and namespace prefix for the referenced page.
	 */
	private const FIELD_CONFIG = [
		self::TYPE_PROPERTY => [
			'category' => 'Property field',
			'referenceProperty' => 'For property',
			'namespacePrefix' => 'Property',
		],
		self::TYPE_SUBOBJECT => [
			'category' => 'Subobject field',
			'referenceProperty' => 'For category',
			'namespacePrefix' => 'Category',
		],
	];

	private string $name;
	private bool $required;
	private string $fieldType;

	public function __construct( string $name, bool $required, string $fieldType ) {
		if ( !isset( self::FIELD_CONFIG[$fieldType] ) ) {
			throw new InvalidArgumentException(
				"Invalid field type '$fieldType'. Must be one of: "
				. implode( ', ', array_keys( self::FIELD_CONFIG ) )
			);
		}
		$this->name = $name;
		$this->required = $required;
		$this->fieldType = $fieldType;
	}

	/* -------------------------------------------------------------------------
	 * STATIC FACTORIES
	 * ---------------------------------------------------------------------- */

	public static function property( string $name, bool $required ): self {
		return new self( $name, $required, self::TYPE_PROPERTY );
	}

	public static function subobject( string $name, bool $required ): self {
		return new self( $name, $required, self::TYPE_SUBOBJECT );
	}

	/**
	 * Build FieldDeclaration[] from an annotated array.
	 *
	 * @param array<array{name:string, required:bool}> $entries
	 * @param string $fieldType One of TYPE_PROPERTY or TYPE_SUBOBJECT
	 * @return self[]
	 */
	public static function fromAnnotatedArray( array $entries, string $fieldType ): array {
		return array_map(
			static fn ( array $entry ) => new self( $entry['name'], $entry['required'], $fieldType ),
			$entries
		);
	}

	/* -------------------------------------------------------------------------
	 * PARSING
	 *
	 * Accepts annotated format: [ ['name'=>..., 'required'=>bool], ... ]
	 * ---------------------------------------------------------------------- */

	/**
	 * Parse raw field data into FieldDeclaration[].
	 *
	 * @param array<array{name:string, required:bool}> $input Annotated field entries
	 * @param string $fieldType One of TYPE_PROPERTY or TYPE_SUBOBJECT
	 * @param string $contextLabel For error messages (e.g. "Category 'Person'")
	 * @return self[]
	 */
	public static function parseInput( array $input, string $fieldType, string $contextLabel ): array {
		if ( $input === [] ) {
			return [];
		}

		$fields = self::fromAnnotatedArray( $input, $fieldType );
		self::validateNoDuplicates( $fields, $contextLabel );
		return $fields;
	}

	public static function validateNoDuplicates( array $fields, string $contextLabel ): void {
		$names = [];
		foreach ( $fields as $field ) {
			$n = $field->getName();
			if ( isset( $names[$n] ) ) {
				throw new InvalidArgumentException(
					"$contextLabel has duplicate field declaration: $n"
				);
			}
			$names[$n] = true;
		}
	}

	/* -------------------------------------------------------------------------
	 * COLLECTION UTILITIES
	 * ---------------------------------------------------------------------- */

	/**
	 * Extract names from a list of field declarations.
	 *
	 * @param self[] $fields
	 * @return string[]
	 */
	public static function names( array $fields ): array {
		return array_map(
			static fn ( self $f ) => $f->getName(),
			$fields
		);
	}

	/**
	 * Extract names filtered by required status.
	 *
	 * @param self[] $fields
	 * @param bool $required
	 * @return string[]
	 */
	public static function filterNames( array $fields, bool $required ): array {
		return array_values( array_map(
			static fn ( self $f ) => $f->getName(),
			array_filter(
				$fields,
				static fn ( self $f ) => $f->isRequired() === $required
			)
		) );
	}

	/**
	 * Convert field declarations to annotated array format.
	 *
	 * @param self[] $fields
	 * @return array<array{name:string, required:bool}>
	 */
	public static function annotated( array $fields ): array {
		return array_map(
			static fn ( self $f ) => [ 'name' => $f->getName(), 'required' => $f->isRequired() ],
			$fields
		);
	}

	/* -------------------------------------------------------------------------
	 * ACCESSORS
	 * ---------------------------------------------------------------------- */

	public function getName(): string {
		return $this->name;
	}

	public function isRequired(): bool {
		return $this->required;
	}

	public function getFieldType(): string {
		return $this->fieldType;
	}

	/* -------------------------------------------------------------------------
	 * SERIALIZATION
	 * ---------------------------------------------------------------------- */

	/**
	 * Generate the wikitext subobject block for this field declaration.
	 *
	 * Uses anonymous subobjects (SMW assigns a stable hash-based ID) with an
	 * explicit sort order property so that #ask queries can preserve ordering.
	 *
	 * @param int $index 1-based position index used for sort ordering
	 * @return string Complete {{#subobject:...}} block
	 */
	public function toWikitext( int $index ): string {
		$config = self::FIELD_CONFIG[$this->fieldType];

		$lines = [];
		$lines[] = '{{#subobject:';
		$lines[] = ' |@category=' . $config['category'];
		$lines[] = ' | ' . $config['referenceProperty'] . ' = ' . $config['namespacePrefix'] . ':' . $this->name;
		$lines[] = ' | Is required = ' . ( $this->required ? 'true' : 'false' );
		$lines[] = ' | Has sort order = ' . $index;
		$lines[] = '}}';

		return implode( "\n", $lines );
	}

	/**
	 * Generate wikitext for a list of field declarations.
	 *
	 * @param self[] $fields
	 * @return string Concatenated wikitext blocks separated by newlines
	 */
	public static function toWikitextAll( array $fields ): string {
		$blocks = [];
		foreach ( $fields as $i => $field ) {
			$blocks[] = $field->toWikitext( $i + 1 );
		}
		return implode( "\n", $blocks );
	}
}
