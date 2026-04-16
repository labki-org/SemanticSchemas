<?php

namespace MediaWiki\Extension\SemanticSchemas\Schema;

use InvalidArgumentException;
use JsonSerializable;
use MediaWiki\Extension\SemanticSchemas\Util\NamingHelper;

/**
 * Immutable value object representing a field declaration on a category page.
 *
 * A field declaration records that a category includes a specific property
 * (TYPE_PROPERTY) or subobject category reference (TYPE_SUBOBJECT), along
 * with whether that field is required.
 *
 * Each FieldModel serializes to a {{#subobject:}} block on the
 * category page, using @category to distinguish property fields from
 * subobject fields.
 *
 * Implements JsonSerializable so that arrays containing FieldModel
 * objects can be passed directly to json_encode (used by PageHashComputer).
 */
class FieldModel implements JsonSerializable {

	public const TYPE_PROPERTY = 'property';
	public const TYPE_SUBOBJECT = 'subobject';

	/**
	 * Configuration for each field type: @category value, reference
	 * property name, and namespace prefix for the referenced page.
	 *
	 * Used for both serialization (toWikitext) and deserialization
	 * (reading from SMW via fieldTypeConfigs()).
	 */
	public const FIELD_CONFIG = [
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
	 * Construct a FieldModel from an SMW subobject's semantic data.
	 *
	 * Reads the reference property, required flag, and sort order
	 * from the subobject. Returns null if the reference property is missing.
	 *
	 * @param \SMW\SemanticData $subData A single subobject's semantic data
	 * @param string $fieldType One of TYPE_PROPERTY or TYPE_SUBOBJECT
	 * @return ?array{field: self, sort: int} Field model with sort order, or null
	 */
	public static function fromSMWSubobject( $subData, string $fieldType ): ?array {
		$config = self::FIELD_CONFIG[$fieldType];
		$refPropName = $config['referenceProperty'];
		$nsType = strtolower( $config['namespacePrefix'] );

		$ref = self::smwReadOne( $subData, $refPropName, $nsType );
		if ( $ref === null ) {
			return null;
		}

		$required = self::smwReadBoolean( $subData, 'Is required' );
		$sortRaw = self::smwReadOne( $subData, 'Has sort order' );
		$sort = (int)( $sortRaw ?? 0 );

		return [
			'field' => new self( $ref, $required, $fieldType ),
			'sort' => $sort,
		];
	}

	/**
	 * Read a single text/page value from semantic data.
	 *
	 * @param \SMW\SemanticData $sdata
	 * @param string $propName Property label
	 * @param string $type 'text', 'property', or 'category'
	 * @return ?string
	 */
	private static function smwReadOne( $sdata, string $propName, string $type = 'text' ): ?string {
		try {
			$prop = \SMW\DIProperty::newFromUserLabel( $propName );
			$items = $sdata->getPropertyValues( $prop );
		} catch ( \Throwable $e ) {
			return null;
		}

		foreach ( $items as $di ) {
			if ( $di instanceof \SMW\DIWikiPage ) {
				$t = $di->getTitle();
				if ( !$t ) {
					continue;
				}
				$text = str_replace( '_', ' ', $t->getText() );
				switch ( $type ) {
					case 'property':
						return $t->getNamespace() === SMW_NS_PROPERTY ? $text : null;
					case 'category':
						return $t->getNamespace() === NS_CATEGORY ? $text : null;
					default:
						return $text;
				}
			}
			if ( $di instanceof \SMWDIBlob || $di instanceof \SMWDIString ) {
				return trim( $di->getString() );
			}
			if ( $di instanceof \SMWDINumber ) {
				return (string)$di->getNumber();
			}
		}
		return null;
	}

	/**
	 * Read a boolean value from semantic data.
	 *
	 * @param \SMW\SemanticData $sdata
	 * @param string $propName Property label
	 * @return bool
	 */
	private static function smwReadBoolean( $sdata, string $propName ): bool {
		try {
			$prop = \SMW\DIProperty::newFromUserLabel( $propName );
			$items = $sdata->getPropertyValues( $prop );
		} catch ( \Throwable $e ) {
			return false;
		}

		foreach ( $items as $di ) {
			if ( $di instanceof \SMWDIBoolean ) {
				return $di->getBoolean();
			}
			if ( $di instanceof \SMWDINumber ) {
				return $di->getNumber() > 0;
			}
			if ( $di instanceof \SMWDIBlob || $di instanceof \SMWDIString ) {
				$v = strtolower( trim( $di->getString() ) );
				return in_array( $v, [ '1', 'true', 'yes', 'y', 't' ], true );
			}
		}
		return false;
	}

	/**
	 * Build FieldModel[] from an annotated array.
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
	 * Parse raw field data into FieldModel[].
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
	 * Filter field models by attribute values.
	 *
	 * All parameters are optional; only supplied criteria are applied.
	 *
	 * @param self[] $fields
	 * @param ?bool $required Filter by required status
	 * @return self[]
	 */
	public static function filter(
		array $fields,
		?bool $required = null
	): array {
		return array_values( array_filter(
			$fields,
			static function ( self $f ) use ( $required ) {
				if ( $required !== null && $f->isRequired() !== $required ) {
					return false;
				}
				return true;
			}
		) );
	}

	/**
	 * Extract names from a list of field models.
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

	public function getParameterName(): string {
		return NamingHelper::propertyToParameter( $this->name );
	}

	/* -------------------------------------------------------------------------
	 * SERIALIZATION
	 * ---------------------------------------------------------------------- */

	/** @return array{name:string, required:bool} */
	public function jsonSerialize(): array {
		return [
			'name' => $this->name,
			'required' => $this->required,
		];
	}

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
