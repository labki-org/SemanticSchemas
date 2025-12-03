<?php

namespace MediaWiki\Extension\StructureSync\Schema;

use InvalidArgumentException;

/**
 * Immutable, canonical schema-level representation of an SMW Property.
 *
 * Supported fields:
 *   - datatype            (string, required)
 *   - label               (string)
 *   - description         (string)
 *   - allowedValues       (string[])
 *   - rangeCategory       (string|null)
 *   - subpropertyOf       (string|null)
 *   - display:            array{
 *                             type: ?string,
 *                             template: ?string,
 *                             fromProperty: ?string
 *                         }
 *   - allowedCategory     (string|null)
 *   - allowedNamespace    (string|null)
 *   - allowsMultipleValues (bool)
 */
class PropertyModel {

    private string $name;
    private string $datatype;

    private string $label;
    private string $description;

    /** @var string[] */
    private array $allowedValues;

    private ?string $rangeCategory;
    private ?string $subpropertyOf;

    /** @var array{type:?string, template:?string, fromProperty:?string} */
    private array $display;

    private ?string $allowedCategory;
    private ?string $allowedNamespace;

    private bool $allowsMultipleValues;

    /* -------------------------------------------------------------------------
     * CONSTRUCTOR
     * ---------------------------------------------------------------------- */

    public function __construct(string $name, array $data = []) {

        /* -------------------- Name -------------------- */
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException("Property name cannot be empty.");
        }
        $this->name = $name;

        /* -------------------- Datatype -------------------- */
        if (empty($data['datatype'])) {
            throw new InvalidArgumentException(
                "Property '{$name}' must define a 'datatype' field."
            );
        }
        $this->datatype = $this->normalizeDatatype((string)$data['datatype']);

        /* -------------------- Label -------------------- */
        $this->label = !empty($data['label'])
            ? (string)$data['label']
            : $this->autoGenerateLabel($this->name);

        /* -------------------- Description -------------------- */
        $this->description = isset($data['description'])
            ? trim((string)$data['description'])
            : '';

        /* -------------------- Allowed Values -------------------- */
        $rawEnum = $data['allowedValues'] ?? [];
        if (!is_array($rawEnum)) {
            throw new InvalidArgumentException(
                "Property '{$name}': 'allowedValues' must be an array of strings."
            );
        }

        $this->allowedValues = array_values(
            array_unique(
                array_filter(
                    array_map('trim', $rawEnum),
                    static fn($v) => $v !== ''
                )
            )
        );

        /* -------------------- Page-type category restriction -------------------- */
        $range = $data['rangeCategory'] ?? null;
        $this->rangeCategory = ($range !== null && trim($range) !== '')
            ? trim($range)
            : null;

        /* -------------------- Subproperty -------------------- */
        $subOf = $data['subpropertyOf'] ?? null;
        $this->subpropertyOf = ($subOf !== null && trim($subOf) !== '')
            ? trim($subOf)
            : null;

        /* -------------------- Display Block -------------------- */
        $display = $data['display'] ?? [];
        if (!is_array($display)) {
            throw new InvalidArgumentException(
                "Property '{$name}': 'display' must be an array if provided."
            );
        }

        $this->display = [
            'type'         => isset($display['type']) ? $this->cleanNullableString($display['type']) : null,
            'template'     => isset($display['template']) ? $this->cleanNullableString($display['template']) : null,
            'fromProperty' => isset($display['fromProperty']) ? $this->cleanNullableString($display['fromProperty']) : null,
        ];

        /* -------------------- Autocomplete restrictions -------------------- */
        $cat = $data['allowedCategory'] ?? null;
        $this->allowedCategory = ($cat !== null && trim($cat) !== '')
            ? trim($cat)
            : null;

        $ns = $data['allowedNamespace'] ?? null;
        $this->allowedNamespace = ($ns !== null && trim($ns) !== '')
            ? trim($ns)
            : null;

        /* -------------------- Multiple values -------------------- */
        $this->allowsMultipleValues = !empty($data['allowsMultipleValues']);
    }

    /* -------------------------------------------------------------------------
     * NORMALIZATION
     * ---------------------------------------------------------------------- */

    private function cleanNullableString($value): ?string {
        $v = trim((string)$value);
        return $v !== '' ? $v : null;
    }

    /**
     * Get the list of valid SMW datatypes.
     *
     * @return string[]
     */
    public static function getValidDatatypes(): array {
        return [
            'Text', 'Page', 'Date', 'Number', 'Email', 'URL', 'Boolean',
            'Code', 'Geographic coordinate', 'Quantity', 'Temperature',
            'Telephone number', 'Annotation URI', 'External identifier',
            'Keyword', 'Monolingual text', 'Record', 'Reference',
        ];
    }

    /**
     * Normalize and validate SMW datatype.
     */
    private function normalizeDatatype(string $datatype): string {
        $valid = self::getValidDatatypes();

        $lower = strtolower($datatype);
        $validLower = array_map('strtolower', $valid);

        $idx = array_search($lower, $validLower, true);
        if ($idx !== false) {
            return $valid[$idx];
        }

        // Fallback with logging
        if (function_exists('wfLogWarning')) {
            wfLogWarning(
                "StructureSync: Unknown datatype '{$datatype}' for property '{$this->name}'. Defaulting to 'Text'."
            );
        }

        return 'Text';
    }

    private function autoGenerateLabel(string $name): string {
        $clean = preg_replace('/^Has[_ ]+/i', '', $name);
        $clean = str_replace('_', ' ', $clean);
        return ucwords($clean);
    }

    /* -------------------------------------------------------------------------
     * ACCESSORS
     * ---------------------------------------------------------------------- */

    public function getName(): string { return $this->name; }
    public function getDatatype(): string { return $this->datatype; }
    public function getLabel(): string { return $this->label; }
    public function getDescription(): string { return $this->description; }

    public function getAllowedValues(): array { return $this->allowedValues; }
    public function hasAllowedValues(): bool { return $this->allowedValues !== []; }

    public function getRangeCategory(): ?string { return $this->rangeCategory; }

    public function isPageType(): bool {
        return $this->datatype === 'Page';
    }

    public function isCategoryRestrictedPageType(): bool {
        return $this->isPageType() && $this->rangeCategory !== null;
    }

    public function getSubpropertyOf(): ?string { return $this->subpropertyOf; }

    /* Display config */
    public function getDisplayType(): ?string { return $this->display['type']; }
    public function getDisplayTemplate(): ?string { return $this->display['template']; }
    public function getDisplayPattern(): ?string { return $this->display['fromProperty']; }

    public function hasDisplayConfig(): bool {
        return $this->display['type'] !== null ||
               $this->display['template'] !== null ||
               $this->display['fromProperty'] !== null;
    }

    /* Autocomplete config */
    public function shouldAutocomplete(): bool {
        return !$this->hasAllowedValues() &&
            ($this->allowedCategory !== null || $this->allowedNamespace !== null);
    }

    public function getAllowedCategory(): ?string { return $this->allowedCategory; }
    public function getAllowedNamespace(): ?string { return $this->allowedNamespace; }

    public function allowsMultipleValues(): bool { return $this->allowsMultipleValues; }

    /* -------------------------------------------------------------------------
     * EXPORT
     * ---------------------------------------------------------------------- */

    public function toArray(): array {

        $data = [
            'datatype'            => $this->datatype,
            'label'               => $this->label,
            'description'         => $this->description,
            'allowedValues'       => $this->allowedValues,
            'rangeCategory'       => $this->rangeCategory,
            'subpropertyOf'       => $this->subpropertyOf,
            'display'             => $this->display,
            'allowedCategory'     => $this->allowedCategory,
            'allowedNamespace'    => $this->allowedNamespace,
            'allowsMultipleValues'=> $this->allowsMultipleValues,
        ];

        // Remove nulls + empty arrays, but preserve boolean false
        return array_filter(
            $data,
            static fn($v) =>
                $v !== null &&
                !(is_array($v) && $v === [])
        );
    }

    /* -------------------------------------------------------------------------
     * SMW Type
     * ---------------------------------------------------------------------- */

    public function getSMWType(): string {
        return $this->datatype;
    }
}
