<?php

namespace MediaWiki\Extension\StructureSync\Schema;

/**
 * Immutable value object representing the schema-level definition
 * of a Semantic MediaWiki property.
 *
 * This model abstracts:
 *   - SMW datatype
 *   - PageForms input implications (via PropertyInputMapper)
 *   - Allowed values (enums)
 *   - Category range restriction for Page-type properties
 *   - Subproperty relationships
 *
 * The class is intentionally immutable so that:
 *   - Schema objects remain stable across request boundaries
 *   - Inheritance & merging can be done safely by cloning or reconstruction
 */
class PropertyModel {

    /** @var string Canonical property name (SMW Property page name) */
    private $name;

    /** @var string SMW datatype string ("Text", "Page", "Number", etc.) */
    private $datatype;

    /** @var string Human-readable label displayed in forms */
    private $label;

    /** @var string Description displayed on Property: pages */
    private $description;

    /** @var string[] Allowed enumeration values (optional) */
    private $allowedValues;

    /** @var string|null Category restriction for Page-type properties */
    private $rangeCategory;

    /** @var string|null Parent property (for SMW subproperty semantics) */
    private $subpropertyOf;

    /* ---------------------------------------------------------------------
     * CONSTRUCTOR
     * --------------------------------------------------------------------- */

    /**
     * @param string $name Property name (e.g. "Has advisor")
     * @param array $data Structured schema array
     */
    public function __construct( string $name, array $data = [] ) {

        // Property names must be canonicalized (whitespace trimmed)
        $this->name = trim( $name );

        $this->datatype      = $this->normalizeDatatype( $data['datatype'] ?? 'Text' );
        
        // Set label: use provided label, or auto-generate if label equals name
        if ( !empty( $data['label'] ) ) {
            $this->label = (string)$data['label'];
        } else {
            $this->label = $this->name;
        }
        
        // Auto-generate label if it equals the name (meaning no explicit label was provided)
        if ( $this->label === $this->name ) {
            $this->label = $this->autoGenerateLabel( $this->name );
        }
        
        $this->description   = $data['description'] ?? '';

        $allowedValues = $data['allowedValues'] ?? [];
        $this->allowedValues = is_array( $allowedValues )
            ? array_values( array_filter( array_map( 'trim', $allowedValues ) ) )
            : [];

        $this->rangeCategory = isset( $data['rangeCategory'] )
            ? trim( $data['rangeCategory'] )
            : null;

        $this->subpropertyOf = isset( $data['subpropertyOf'] )
            ? trim( $data['subpropertyOf'] )
            : null;
    }

    /* ---------------------------------------------------------------------
     * NORMALIZATION HELPERS
     * --------------------------------------------------------------------- */

    /**
     * Normalize SMW datatype input.
     *
     * @param string $datatype
     * @return string
     */
    private function normalizeDatatype( string $datatype ): string {

        $datatype = trim( $datatype );

        // Supported types based on SMW core datatype mappings
        static $valid = [
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
        ];

        // Unknown → Text (SMW default behavior)
        return in_array( $datatype, $valid ) ? $datatype : 'Text';
    }

    /**
     * Auto-generate a human-readable label from a property name.
     *
     * Strips "Has " or "Has_" prefix, replaces underscores with spaces,
     * and capitalizes words.
     *
     * Examples:
     *   "Has department" → "Department"
     *   "Has_research_area" → "Research Area"
     *   "Has_full_name" → "Full Name"
     *
     * @param string $name Property name
     * @return string Generated label
     */
    private function autoGenerateLabel( string $name ): string {
        $clean = preg_replace( '/^Has[_ ]/', '', $name );
        $clean = str_replace( '_', ' ', $clean );
        return ucwords( $clean );
    }

    /* ---------------------------------------------------------------------
     * BASIC ACCESSORS
     * --------------------------------------------------------------------- */

    public function getName(): string {
        return $this->name;
    }

    public function getDatatype(): string {
        return $this->datatype;
    }

    public function getLabel(): string {
        // Ensure label is never empty - fallback to name if somehow empty
        return !empty( $this->label ) ? (string)$this->label : $this->name;
    }

    public function getDescription(): string {
        return $this->description;
    }

    public function getAllowedValues(): array {
        return $this->allowedValues;
    }

    public function getRangeCategory(): ?string {
        return $this->rangeCategory;
    }

    public function getSubpropertyOf(): ?string {
        return $this->subpropertyOf;
    }

    /* ---------------------------------------------------------------------
     * BOOLEAN CHECKS
     * --------------------------------------------------------------------- */

    /**
     * Whether this property has enumeration constraints.
     */
    public function hasAllowedValues(): bool {
        return !empty( $this->allowedValues );
    }

    /**
     * Whether the property maps to the SMW "Page" type.
     */
    public function isPageType(): bool {
        return $this->datatype === 'Page';
    }

    /**
     * Whether this property is a Page-type restricted to a CATEGORY range.
     */
    public function isCategoryRestrictedPageType(): bool {
        return $this->isPageType() && $this->rangeCategory !== null && $this->rangeCategory !== '';
    }

    /* ---------------------------------------------------------------------
     * EXPORT
     * --------------------------------------------------------------------- */

    /**
     * Convert to schema-array representation for YAML/JSON export.
     *
     * @return array
     */
    public function toArray(): array {

        $data = [
            'datatype'    => $this->datatype,
            'label'       => $this->label,
            'description' => $this->description,
        ];

        if ( $this->hasAllowedValues() ) {
            $data['allowedValues'] = $this->allowedValues;
        }

        if ( $this->rangeCategory !== null ) {
            $data['rangeCategory'] = $this->rangeCategory;
        }

        if ( $this->subpropertyOf !== null ) {
            $data['subpropertyOf'] = $this->subpropertyOf;
        }

        return $data;
    }

    /* ---------------------------------------------------------------------
     * SMW DATATYPE MAPPING
     * --------------------------------------------------------------------- */

    /**
     * Returns a stable SMW datatype string for Property: page generation.
     *
     * @return string
     */
    public function getSMWType(): string {

        // Explicit pass-through — normalizeDatatype() already validated types.
        return $this->datatype;
    }
}
