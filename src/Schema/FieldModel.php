<?php

namespace MediaWiki\Extension\SemanticSchemas\Schema;

use InvalidArgumentException;
use JsonSerializable;
use MediaWiki\Extension\SemanticSchemas\Util\NamingHelper;
use MediaWiki\Extension\SemanticSchemas\Util\SMWDataExtractor;
use SMW\SemanticData;

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

	use SMWDataExtractor;

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
	private ?string $renderTemplate;
	private ?string $subobjectDisplayTemplate;

	public function __construct(
		string $name,
		bool $required,
		string $fieldType,
		?string $renderTemplate = null,
		?string $subobjectDisplayTemplate = null
	) {
		if ( !isset( self::FIELD_CONFIG[$fieldType] ) ) {
			throw new InvalidArgumentException(
				"Invalid field type '$fieldType'. Must be one of: "
				. implode( ', ', array_keys( self::FIELD_CONFIG ) )
			);
		}
		$this->name = $name;
		$this->required = $required;
		$this->fieldType = $fieldType;
		$this->renderTemplate = ( $renderTemplate !== null && trim( $renderTemplate ) !== '' )
			? trim( $renderTemplate )
			: null;
		$this->subobjectDisplayTemplate = (
			$subobjectDisplayTemplate !== null && trim( $subobjectDisplayTemplate ) !== ''
		)
			? trim( $subobjectDisplayTemplate )
			: null;
	}

	/**
	 * Construct a FieldModel from an SMW subobject's semantic data.
	 * Returns null if the reference property is missing.
	 *
	 * @param \SMW\SemanticData $subData A single subobject's semantic data
	 * @param string $fieldType One of TYPE_PROPERTY or TYPE_SUBOBJECT
	 * @return ?array{field: self, sort: int} Field model with sort order, or null
	 */
	public static function fromSMWSubobject( SemanticData $subData, string $fieldType ): ?array {
		$config = self::FIELD_CONFIG[$fieldType];
		$refPropName = $config['referenceProperty'];
		$nsType = strtolower( $config['namespacePrefix'] );

		$ref = self::smwFetchOne( $subData, $refPropName, $nsType );
		if ( $ref === null ) {
			return null;
		}

		$required = self::smwFetchBoolean( $subData, 'Is required' );
		$sortRaw = self::smwFetchOne( $subData, 'Has sort order' );
		$sort = (int)( $sortRaw ?? 0 );
		$renderTemplate = self::smwFetchOne( $subData, 'Has render template', 'template' );
		$subobjectDisplayTemplate = self::smwFetchOne(
			$subData, 'Has subobject display template', 'template'
		);

		return [
			'field' => new self(
				$ref, $required, $fieldType, $renderTemplate, $subobjectDisplayTemplate
			),
			'sort' => $sort,
		];
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

	/* -------------------------------------------------------------------------
	 * ACCESSORS
	 * ---------------------------------------------------------------------- */

	public function getName(): string {
		return $this->name;
	}

	public function isRequired(): bool {
		return $this->required;
	}

	/**
	 * fieldType accessor - subobject or property
	 *
	 * fine to suppress as unused, as it's a no-op accessor that would be expected from the object model.
	 * @suppress PhanUnreferencedPublicMethod
	 */
	public function getFieldType(): string {
		return $this->fieldType;
	}

	public function getParameterName(): string {
		return NamingHelper::propertyToParameter( $this->name );
	}

	/**
	 * Per-field override for the value-render template. Null when not set.
	 */
	public function getRenderTemplate(): ?string {
		return $this->renderTemplate;
	}

	/**
	 * Per-parent override for the subobject-section display template
	 * (only meaningful on TYPE_SUBOBJECT fields). Null when not set.
	 */
	public function getSubobjectDisplayTemplate(): ?string {
		return $this->subobjectDisplayTemplate;
	}

	/* -------------------------------------------------------------------------
	 * SERIALIZATION
	 * ---------------------------------------------------------------------- */

	/**
	 * @return array{name:string, required:bool, renderTemplate?:string, subobjectDisplayTemplate?:string}
	 */
	public function jsonSerialize(): array {
		$out = [
			'name' => $this->name,
			'required' => $this->required,
		];
		if ( $this->renderTemplate !== null ) {
			$out['renderTemplate'] = $this->renderTemplate;
		}
		if ( $this->subobjectDisplayTemplate !== null ) {
			$out['subobjectDisplayTemplate'] = $this->subobjectDisplayTemplate;
		}
		return $out;
	}

}
