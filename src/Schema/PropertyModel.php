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
 *   - subpropertyOf       (string|null)
 *   - hasTemplate         (string|null) - Template page name or PropertyType reference
 *   - allowedCategory     (string|null)
 *   - allowedNamespace    (string|null)
 *   - allowsMultipleValues (bool)
 *   - inputType            (string|null) - Explicit PageForms input type override
 *   - inverseLabel         (string|null) - Label describing the inverse relationship
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
		'subpropertyOf' => [ 'Subproperty of', 'property' ],
		'allowedCategory' => [ 'Allows value from category', 'category' ],
		'allowedNamespace' => [ 'Allows value from namespace', 'text' ],
		'allowsMultipleValues' => [ 'Allows multiple values', 'boolean' ],
		'hasTemplate' => [ 'Has template', 'page' ],
		'inputType' => [ 'Has input type', 'text' ],
		'inverseLabel' => [ 'Inverse property label', 'text' ],
	];

	private string $name;
	private string $datatype;

	private string $label;
	private string $description;

	/** @var string[] */
	private array $allowedValues;

	private ?string $subpropertyOf;
	private ?string $hasTemplate;
	private ?string $allowedCategory;
	private ?string $allowedNamespace;
	private bool $allowsMultipleValues;
	private ?string $inputType;
	private ?string $inverseLabel;

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

		/* -------------------- Subproperty -------------------- */
		$subOf = $data['subpropertyOf'] ?? null;
		$this->subpropertyOf = ( $subOf !== null && trim( $subOf ) !== '' )
			? trim( $subOf )
			: null;

		/* -------------------- Template -------------------- */
		// Read from new hasTemplate field
		$template = $data['hasTemplate'] ?? null;

		// Backward compatibility: read from old display['template'] if hasTemplate not set
		if ( $template === null && isset( $data['display']['template'] ) ) {
			$template = $data['display']['template'];
		}

		$this->hasTemplate = ( $template !== null && trim( $template ) !== '' )
			? trim( $template )
			: null;

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

		/* -------------------- Backlink label -------------------- */
		$invLabel = $data['inverseLabel'] ?? null;
		$this->inverseLabel = ( $invLabel !== null && trim( (string)$invLabel ) !== '' )
			? trim( (string)$invLabel ) : null;

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

	public function getRenderTemplate(): string {
		// Priority 1: Explicit custom template
		if ( $this->hasTemplate !== null ) {
			// Strip namespace prefix if present — wikitext {{Name}} already
			// resolves in the Template namespace, and hardcoding the prefix
			// breaks on non-English wikis.
			return preg_replace( '/^Template:/', '', $this->hasTemplate );
		}
		// Priority 2: Datatype-specific template for Page type
		if ( $this->isPageType() ) {
			return 'Property/Page';
		}
		// Fallback: Default template
		return 'Property/Default';
	}

	// Backward compatibility aliases

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

	public function getInverseLabel(): ?string {
		return $this->inverseLabel;
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
			'subpropertyOf' => $this->subpropertyOf,
			'hasTemplate' => $this->hasTemplate,
			'allowedCategory' => $this->allowedCategory,
			'allowedNamespace' => $this->allowedNamespace,
			'allowsMultipleValues' => $this->allowsMultipleValues,
			'inputType' => $this->inputType,
			'inverseLabel' => $this->inverseLabel,
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
