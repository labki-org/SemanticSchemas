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
class PropertyModel
{

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

    /** @var string|null Display type hint (e.g. "Email", "URL", "Image") */
    private $displayType;

    /** @var string|null Wiki-editable display template (wikitext) */
    private $displayTemplate;

    /** @var string|null Property name to reference for display pattern */
    private $displayPattern;

    /** @var string|null Category name for autocomplete value source */
    private $allowedCategory;

    /** @var string|null Namespace name for autocomplete value source */
    private $allowedNamespace;

    /** @var bool Whether the property allows multiple values */
    private $allowsMultipleValues;

    /* ---------------------------------------------------------------------
     * CONSTRUCTOR
     * --------------------------------------------------------------------- */

    /**
     * @param string $name Property name (e.g. "Has advisor")
     * @param array $data Structured schema array
     * @throws \InvalidArgumentException If name is empty or datatype is invalid
     */
    public function __construct(string $name, array $data = [])
    {

        // Property names must be canonicalized (whitespace trimmed)
        $this->name = trim($name);
        
        if ($this->name === '') {
            throw new \InvalidArgumentException('Property name cannot be empty');
        }

        $this->datatype = $this->normalizeDatatype($data['datatype'] ?? 'Text');
        
        if ($this->datatype === '') {
            throw new \InvalidArgumentException("Property '$name' has invalid datatype");
        }

        // Set label: use provided label, or auto-generate if label equals name
        if (!empty($data['label'])) {
            $this->label = (string) $data['label'];
        } else {
            $this->label = $this->name;
        }

        // Auto-generate label if it equals the name (meaning no explicit label was provided)
        if ($this->label === $this->name) {
            $this->label = $this->autoGenerateLabel($this->name);
        }

        $this->description = $data['description'] ?? '';

        $allowedValues = $data['allowedValues'] ?? [];
        $this->allowedValues = is_array($allowedValues)
            ? array_values(array_filter(array_map('trim', $allowedValues)))
            : [];

        $this->rangeCategory = isset($data['rangeCategory'])
            ? trim($data['rangeCategory'])
            : null;

        $this->subpropertyOf = isset($data['subpropertyOf'])
            ? trim($data['subpropertyOf'])
            : null;

        // Display type: check new display block first, then schema field
        if (isset($data['display']['type'])) {
            $this->displayType = trim($data['display']['type']);
        } elseif (isset($data['displayBuiltin'])) {
            $this->displayType = trim($data['displayBuiltin']);
        } else {
            $this->displayType = null;
        }

        // Display template: from new display block
        $this->displayTemplate = isset($data['display']['template'])
            ? trim($data['display']['template'])
            : null;

        // Display pattern: property-to-property template reference
        if (isset($data['displayFromProperty'])) {
            $this->displayPattern = trim($data['displayFromProperty']);
        } else {
            $this->displayPattern = null;
        }

        // Autocomplete sources (normalized without prefixes)
        $this->allowedCategory = isset($data['allowedCategory'])
            ? trim($data['allowedCategory'])
            : null;

        $this->allowedNamespace = isset($data['allowedNamespace'])
            ? trim($data['allowedNamespace'])
            : null;

        // Allows multiple values (default: false)
        $this->allowsMultipleValues = !empty($data['allowsMultipleValues']);
    }

    /* ---------------------------------------------------------------------
     * NORMALIZATION HELPERS
     * --------------------------------------------------------------------- */

    /**
     * Normalize SMW datatype input.
     *
     * Validates against a list of standard SMW datatypes. If an unknown
     * datatype is provided, logs a warning and defaults to 'Text'.
     *
     * @param string $datatype
     * @return string Valid SMW datatype
     */
    private function normalizeDatatype(string $datatype): string
    {

        $datatype = trim($datatype);

        // Supported types based on SMW core datatype mappings
        // @see https://www.semantic-mediawiki.org/wiki/Help:List_of_datatypes
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
            'Annotation URI',  // Added for completeness
            'External identifier',
            'Keyword',
            'Monolingual text',
            'Record',
            'Reference',
        ];

        // Case-insensitive validation (SMW accepts various casings)
        $validLower = array_map( 'strtolower', $valid );
        $datatypeLower = strtolower( $datatype );
        
        $index = array_search( $datatypeLower, $validLower );
        if ( $index !== false ) {
            // Return the canonical casing
            return $valid[$index];
        }

        // Unknown datatype: log warning and fallback to Text (SMW default behavior)
        wfLogWarning(
            "StructureSync: Unknown datatype '$datatype' for property '{$this->name}'. " .
            "Falling back to 'Text'. Valid types: " . implode( ', ', $valid )
        );
        
        return 'Text';
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
    private function autoGenerateLabel(string $name): string
    {
        $clean = preg_replace('/^Has[_ ]/', '', $name);
        $clean = str_replace('_', ' ', $clean);
        return ucwords($clean);
    }

    /* ---------------------------------------------------------------------
     * BASIC ACCESSORS
     * --------------------------------------------------------------------- */

    public function getName(): string
    {
        return $this->name;
    }

    public function getDatatype(): string
    {
        return $this->datatype;
    }

    public function getLabel(): string
    {
        return $this->getDisplayLabel();
    }

    /**
     * Get the display label.
     *
     * Logic:
     * 1. If explicit label was provided in schema, use it.
     * 2. If not, generate from name:
     *    - Strip "Has " prefix
     *    - Replace "_" with space
     *    - Capitalize words
     *
     * @return string
     */
    public function getDisplayLabel(): string
    {
        // If label is different from name, it was explicitly set (or we already auto-generated it in constructor)
        // The constructor already handles the "auto-generate if empty" logic and sets $this->label.
        // So we can just return $this->label, but let's be explicit about the fallback just in case.

        if (!empty($this->label)) {
            return (string) $this->label;
        }

        return $this->autoGenerateLabel($this->name);
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getAllowedValues(): array
    {
        return $this->allowedValues;
    }

    public function getRangeCategory(): ?string
    {
        return $this->rangeCategory;
    }

    public function getSubpropertyOf(): ?string
    {
        return $this->subpropertyOf;
    }

    public function getDisplayType(): ?string
    {
        return $this->displayType;
    }

    public function getDisplayTemplate(): ?string
    {
        return $this->displayTemplate;
    }

    public function getDisplayPattern(): ?string
    {
        return $this->displayPattern;
    }

    public function getAllowedCategory(): ?string
    {
        return $this->allowedCategory;
    }

    public function getAllowedNamespace(): ?string
    {
        return $this->allowedNamespace;
    }

    public function allowsMultipleValues(): bool
    {
        return $this->allowsMultipleValues;
    }

    /* ---------------------------------------------------------------------
     * BOOLEAN CHECKS
     * --------------------------------------------------------------------- */

    /**
     * Whether this property has enumeration constraints.
     */
    public function hasAllowedValues(): bool
    {
        return !empty($this->allowedValues);
    }

    /**
     * Whether the property maps to the SMW "Page" type.
     */
    public function isPageType(): bool
    {
        return $this->datatype === 'Page';
    }

    /**
     * Whether this property is a Page-type restricted to a CATEGORY range.
     */
    public function isCategoryRestrictedPageType(): bool
    {
        return $this->isPageType() && $this->rangeCategory !== null && $this->rangeCategory !== '';
    }

    /**
     * Whether this property should use autocomplete in forms.
     * 
     * Returns true if:
     *   - Property does NOT have enum values (allowedValues)
     *   - Property HAS either allowedCategory or allowedNamespace defined
     * 
     * This keeps PropertyInputMapper logic clean and centralizes the decision.
     *
     * @return bool
     */
    public function shouldAutocomplete(): bool
    {
        return !$this->hasAllowedValues() 
            && ($this->allowedCategory !== null || $this->allowedNamespace !== null);
    }

    /* ---------------------------------------------------------------------
     * EXPORT
     * --------------------------------------------------------------------- */

    /**
     * Convert to schema-array representation for YAML/JSON export.
     *
     * @return array
     */
    public function toArray(): array
    {

        $data = [
            'datatype' => $this->datatype,
            'label' => $this->label,
            'description' => $this->description,
        ];

        if ($this->hasAllowedValues()) {
            $data['allowedValues'] = $this->allowedValues;
        }

        if ($this->rangeCategory !== null) {
            $data['rangeCategory'] = $this->rangeCategory;
        }

        if ($this->subpropertyOf !== null) {
            $data['subpropertyOf'] = $this->subpropertyOf;
        }

        if ($this->displayType !== null) {
            $data['displayBuiltin'] = $this->displayType;
        }

        // Include displayTemplate if present
        if ($this->displayTemplate !== null) {
            $data['displayTemplate'] = $this->displayTemplate;
        }

        // Include displayFromProperty if present (property reference for template)
        if ($this->displayPattern !== null) {
            $data['displayFromProperty'] = $this->displayPattern;
        }

        // Include autocomplete sources if present
        if ($this->allowedCategory !== null) {
            $data['allowedCategory'] = $this->allowedCategory;
        }

        if ($this->allowedNamespace !== null) {
            $data['allowedNamespace'] = $this->allowedNamespace;
        }

        if ($this->allowsMultipleValues) {
            $data['allowsMultipleValues'] = true;
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
    public function getSMWType(): string
    {

        // Explicit pass-through — normalizeDatatype() already validated types.
        return $this->datatype;
    }
}
