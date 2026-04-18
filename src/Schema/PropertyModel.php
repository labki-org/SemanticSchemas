<?php

namespace MediaWiki\Extension\SemanticSchemas\Schema;

use InvalidArgumentException;
use MediaWiki\Extension\SemanticSchemas\Util\NamingHelper;

/**
 * Immutable, canonical schema-level representation of an SMW Property.
 *
 * Supported fields:
 *   - datatype            (string, required)
 *   - label               (string)
 *   - description         (string)
 *   - allowedValues       (string[])
 *   - allowedCategory     (string|null)
 *   - allowedNamespace    (string|null)
 *   - allowsMultipleValues (bool)
 *   - inputType            (string|null) - Explicit PageForms input type override
 */
class PropertyModel {

	/**
	 * Declarative map of internal field names to SMW property labels and types.
	 * Used by WikiPropertyStore::loadFromSMW via smwLoadProperties().
	 */
	public const SMW_PROPERTIES = [
		'label' => [ 'Display label', 'text' ],
		'description' => [ 'Has description', 'text' ],
		'allowedValues' => [ 'Allows value', 'text[]' ],
		'allowedCategory' => [ 'Allows value from category', 'category' ],
		'allowedNamespace' => [ 'Allows value from namespace', 'text' ],
		'allowsMultipleValues' => [ 'Allows multiple values', 'boolean' ],
		'inputType' => [ 'Has input type', 'text' ],
		'hidden' => [ 'Is hidden', 'boolean' ],
	];

	private string $name;
	private string $datatype;

	private string $label;
	private string $description;

	/** @var string[] */
	private array $allowedValues;

	private ?string $allowedCategory;
	private ?string $allowedNamespace;
	private bool $allowsMultipleValues;
	private ?string $inputType;

	private bool $hidden;

	/* -------------------------------------------------------------------------
	 * CONSTRUCTOR
	 * ---------------------------------------------------------------------- */

	public function __construct( string $name, array $data = [] ) {
		/* -------------------- Name -------------------- */
		$name = trim( $name );
		if ( $name === '' ) {
			throw new InvalidArgumentException( "Property name cannot be empty." );
		}
		$this->name = $name;

		/* -------------------- Datatype -------------------- */
		if ( !isset( $data['datatype'] ) ) {
			throw new InvalidArgumentException(
				"Property '{$name}' must define a 'datatype' field."
			);
		}
		$this->datatype = $this->normalizeDatatype( (string)$data['datatype'] );

		/* -------------------- Label -------------------- */
		$this->label = !empty( $data['label'] )
			? (string)$data['label']
			: NamingHelper::generatePropertyLabel( $this->name );

		/* -------------------- Description -------------------- */
		$this->description = isset( $data['description'] )
			? trim( (string)$data['description'] )
			: '';

		/* -------------------- Allowed Values -------------------- */
		$rawEnum = $data['allowedValues'] ?? [];
		if ( !is_array( $rawEnum ) ) {
			throw new InvalidArgumentException(
				"Property '{$name}': 'allowedValues' must be an array of strings."
			);
		}

		$this->allowedValues = array_values(
			array_unique(
				array_filter(
					array_map( 'trim', $rawEnum ),
					static fn ( $v ) => $v !== ''
				)
			)
		);

		/* -------------------- Autocomplete restrictions -------------------- */
		$cat = $data['allowedCategory'] ?? null;
		$this->allowedCategory = ( $cat !== null && trim( $cat ) !== '' )
			? trim( $cat )
			: null;

		$ns = $data['allowedNamespace'] ?? null;
		$this->allowedNamespace = ( $ns !== null && trim( $ns ) !== '' )
			? trim( $ns )
			: null;

		/* -------------------- Multiple values -------------------- */
		$this->allowsMultipleValues = !empty( $data['allowsMultipleValues'] );

		/* -------------------- Input type override -------------------- */
		$it = $data['inputType'] ?? null;
		$this->inputType = ( $it !== null && trim( (string)$it ) !== '' )
			? trim( (string)$it ) : null;

		/* -------------------- Hidden -------------------- */
		$this->hidden = !empty( $data['hidden'] );
	}

	/* -------------------------------------------------------------------------
	 * NORMALIZATION
	 * ---------------------------------------------------------------------- */

	/**
	 * Get the list of valid SMW datatypes.
	 *
	 * @return string[]
	 */
	public static function getValidDatatypes(): array {
		return [
			'Text',
			'Page',
			'Date',
			'Number',
			'Email',
			'URL',
			'Boolean',
			'Code',
			'Geographic coordinate',
			'Quantity',
			'Temperature',
			'Telephone number',
			'Annotation URI',
			'External identifier',
			'Keyword',
			'Monolingual text',
			'Record',
			'Reference',
		];
	}

	/**
	 * Normalize and validate SMW datatype.
	 */
	private function normalizeDatatype( string $datatype ): string {
		$valid = self::getValidDatatypes();

		$lower = strtolower( $datatype );
		$validLower = array_map( 'strtolower', $valid );

		$idx = array_search( $lower, $validLower, true );
		if ( $idx !== false ) {
			return $valid[$idx];
		}

		// Fallback with logging
		if ( function_exists( 'wfLogWarning' ) ) {
			wfLogWarning(
				"SemanticSchemas: Unknown datatype '{$datatype}' for property '{$this->name}'. Defaulting to 'Page'."
			);
		}

		return 'Page';
	}

	/* -------------------------------------------------------------------------
	 * ACCESSORS
	 * ---------------------------------------------------------------------- */

	public function getName(): string {
		return $this->name;
	}

	public function getDatatype(): string {
		return $this->datatype;
	}

	public function getLabel(): string {
		return $this->label;
	}

	public function getDescription(): string {
		return $this->description;
	}

	public function getAllowedValues(): array {
		return $this->allowedValues;
	}

	public function hasAllowedValues(): bool {
		return $this->allowedValues !== [];
	}

	public function isPageType(): bool {
		return $this->datatype === 'Page';
	}

	public function shouldAutocomplete(): bool {
		return (
			( $this->allowedCategory !== null && $this->allowedCategory !== '' ) ||
			( $this->allowedNamespace !== null && $this->allowedNamespace !== '' )
		);
	}

	public function getAllowedCategory(): ?string {
		return $this->allowedCategory;
	}

	public function getAllowedNamespace(): ?string {
		return $this->allowedNamespace;
	}

	public function allowsMultipleValues(): bool {
		return $this->allowsMultipleValues;
	}

	public function getInputType(): ?string {
		return $this->inputType;
	}

	public function isHidden(): bool {
		return $this->hidden;
	}

	/* -------------------------------------------------------------------------
	 * EXPORT
	 * ---------------------------------------------------------------------- */

	public function toArray(): array {
		$data = [
			'datatype' => $this->datatype,
			'label' => $this->label,
			'description' => $this->description,
			'allowedValues' => $this->allowedValues,
			'allowedCategory' => $this->allowedCategory,
			'allowedNamespace' => $this->allowedNamespace,
			'allowsMultipleValues' => $this->allowsMultipleValues,
			'inputType' => $this->inputType,
			'hidden' => $this->hidden ?: null,
		];

		// Remove nulls + empty arrays, but preserve boolean false
		return array_filter(
			$data,
			static fn ( $v ) =>
			$v !== null &&
			!( is_array( $v ) && $v === [] )
		);
	}

}
