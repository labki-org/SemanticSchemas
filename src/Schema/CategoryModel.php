<?php

namespace MediaWiki\Extension\StructureSync\Schema;

/**
 * CategoryModel
 * --------------
 * Immutable value object that represents the *schema-level* definition of a Category,
 * including:
 *   - direct category metadata (label, description)
 *   - direct required & optional properties
 *   - direct display & form configuration
 *   - direct parent list
 *
 * This object does NOT automatically handle inheritance. InheritanceResolver is
 * responsible for repeatedly calling mergeWithParent() in topological order to
 * produce an "effective" merged CategoryModel.
 *
 * This object is read-only after construction.
 */
class CategoryModel {

    /** @var string Category title (no namespace) */
    private $name;

    /** @var string[] Direct parent categories (no namespace) */
    private $parents;

    /** @var string Human-readable label */
    private $label;

    /** @var string Long-form description */
    private $description;

    /** @var string[] Direct required properties */
    private $requiredProperties;

    /** @var string[] Direct optional properties */
    private $optionalProperties;

    /**
     * @var array Display metadata in schema form:
     *   [
     *     'header' => [prop, ...],
     *     'sections' => [
     *        [ 'name' => ..., 'properties' => [...] ],
     *        ...
     *     ]
     *   ]
     */
    private $displayConfig;

    /**
     * @var array Form metadata in schema form:
     *   [
     *     'sections' => [
     *        [ 'name' => ..., 'properties' => [...] ],
     *        ...
     *     ]
     *   ]
     */
    private $formConfig;

    /**
     * Constructor
     *
     * @param string $name  Category name (no namespace)
     * @param array  $data  Parsed schema array for this category
     */
    public function __construct( string $name, array $data = [] ) {

        $this->name = $name;

        $this->parents = self::normalizeList(
            $data['parents'] ?? []
        );

        $this->label = !empty( $data['label'] ) ? (string)$data['label'] : $name;
        $this->description = $data['description'] ?? '';

        $this->requiredProperties = self::normalizeList(
            $data['properties']['required'] ?? []
        );

        $this->optionalProperties = self::normalizeList(
            $data['properties']['optional'] ?? []
        );

        $this->displayConfig = $data['display'] ?? [];
        $this->formConfig = $data['forms'] ?? [];
    }

    /* =======================================================================
     * BASIC ACCESSORS
     * ======================================================================= */

    public function getName(): string {
        return $this->name;
    }

    public function getParents(): array {
        return $this->parents;
    }

    public function getLabel(): string {
        // Ensure label is never empty - fallback to name if somehow empty
        return !empty( $this->label ) ? (string)$this->label : $this->name;
    }

    public function getDescription(): string {
        return $this->description;
    }

    /** @return string[] Direct required properties */
    public function getRequiredProperties(): array {
        return $this->requiredProperties;
    }

    /** @return string[] Direct optional properties */
    public function getOptionalProperties(): array {
        return $this->optionalProperties;
    }

    /**
     * @return string[] Direct required + optional
     * DO NOT USE THIS FOR FORM GENERATION.
     */
    public function getAllProperties(): array {
        return array_values(
            array_unique(
                array_merge( $this->requiredProperties, $this->optionalProperties )
            )
        );
    }

    public function getDisplayConfig(): array {
        return $this->displayConfig;
    }

    public function getFormConfig(): array {
        return $this->formConfig;
    }

    public function getDisplayHeaderProperties(): array {
        return $this->displayConfig['header'] ?? [];
    }

    public function getDisplaySections(): array {
        return $this->displayConfig['sections'] ?? [];
    }

    public function getFormSections(): array {
        return $this->formConfig['sections'] ?? [];
    }

    public function hasParent( string $parentName ): bool {
        return in_array( $parentName, $this->parents, true );
    }

    public function isPropertyRequired( string $propertyName ): bool {
        return in_array( $propertyName, $this->requiredProperties, true );
    }

    public function isPropertyOptional( string $propertyName ): bool {
        return in_array( $propertyName, $this->optionalProperties, true );
    }

    /* =======================================================================
     * SCHEMA EXPORT
     * ======================================================================= */

    public function toArray(): array {
        $data = [
            'parents' => $this->parents,
            'label' => $this->label,
            'description' => $this->description,
            'properties' => [
                'required' => $this->requiredProperties,
                'optional' => $this->optionalProperties,
            ],
        ];

        if ( !empty( $this->displayConfig ) ) {
            $data['display'] = $this->displayConfig;
        }

        if ( !empty( $this->formConfig ) ) {
            $data['forms'] = $this->formConfig;
        }

        return $data;
    }

    /* =======================================================================
     * INHERITANCE MERGING
     * ======================================================================= */

    /**
     * Merge this CategoryModel with a parent's CategoryModel.
     *
     * Child overrides parent where conflicting settings exist.
     *
     * @param CategoryModel $parent
     * @return CategoryModel New merged model
     */
    public function mergeWithParent( CategoryModel $parent ): CategoryModel {

        // 1. Merge required properties:
        // If any ancestor required a property, it remains required UNLESS the
        // child explicitly marked it optional (child override).
        $mergedRequired = array_unique(
            array_merge(
                $parent->getRequiredProperties(),
                $this->requiredProperties
            )
        );

        // 2. Merge optional properties:
        // union of parent + child optional, EXCEPT anything marked required.
        $mergedOptional = array_unique(
            array_merge(
                $parent->getOptionalProperties(),
                $this->optionalProperties
            )
        );
        $mergedOptional = array_values(
            array_diff( $mergedOptional, $mergedRequired )
        );

        // 3. Merge display config
        $mergedDisplay = self::mergeDisplayConfigs(
            $parent->getDisplayConfig(),
            $this->displayConfig
        );

        // 4. Merge form config
        $mergedForm = self::mergeFormConfigs(
            $parent->getFormConfig(),
            $this->formConfig
        );

        return new self( $this->name, [
            'parents' => $this->parents,
            'label' => $this->label,
            'description' => $this->description,
            'properties' => [
                'required' => array_values( $mergedRequired ),
                'optional' => array_values( $mergedOptional ),
            ],
            'display' => $mergedDisplay,
            'forms' => $mergedForm,
        ] );
    }

    /* =======================================================================
     * CONFIG MERGING HELPERS
     * ======================================================================= */

    /**
     * Merge parent + child display configs.
     * Child overrides on header and section name collisions.
     */
    private static function mergeDisplayConfigs( array $parent, array $child ): array {
        if ( empty( $parent ) ) {
            return $child;
        }
        if ( empty( $child ) ) {
            return $parent;
        }

        $merged = $parent;

        // Merge header
        if ( isset( $child['header'] ) ) {
            $merged['header'] = self::normalizeList( $child['header'] );
        }

        // Merge sections
        if ( isset( $child['sections'] ) ) {
            $mergedSections = $parent['sections'] ?? [];
            foreach ( $child['sections'] as $childSection ) {
                $found = false;
                foreach ( $mergedSections as &$section ) {
                    if ( $section['name'] === $childSection['name'] ) {
                        // override parent
                        $section = $childSection;
                        $found = true;
                        break;
                    }
                }
                if ( !$found ) {
                    // append
                    $mergedSections[] = $childSection;
                }
            }
            $merged['sections'] = $mergedSections;
        }

        return $merged;
    }

    /**
     * Merge parent + child form configs.
     * Child takes precedence.
     */
    private static function mergeFormConfigs( array $parent, array $child ): array {
        if ( empty( $parent ) ) {
            return $child;
        }
        if ( empty( $child ) ) {
            return $parent;
        }

        $merged = $parent;

        if ( isset( $child['sections'] ) ) {
            $merged['sections'] = $child['sections'];
        }

        return $merged;
    }

    /* =======================================================================
     * UTILITIES
     * ======================================================================= */

    /**
     * Normalize a list of strings:
     * - trim whitespace
     * - remove empty entries
     * - remove duplicates
     * - preserve order of appearance
     */
    private static function normalizeList( array $list ): array {
        $clean = [];
        foreach ( $list as $value ) {
            $v = trim( (string)$value );
            if ( $v !== '' && !in_array( $v, $clean, true ) ) {
                $clean[] = $v;
            }
        }
        return $clean;
    }
}
