<?php

namespace MediaWiki\Extension\StructureSync\Generator;

use MediaWiki\Extension\StructureSync\Schema\PropertyModel;

/**
 * PropertyInputMapper (Improved 2025)
 * -----------------------------------
 * Converts SMW property constraints → PageForms input definitions.
 *
 * Feature priorities:
 *   1. Allows multiple values → tokens
 *   2. Allowed values (enum) → dropdown
 *   3. Autocomplete source → combobox
 *   4. Page-type property → combobox
 *   5. SMW datatype fallback → (text, number, checkbox, datepicker, textarea)
 *
 * Produces PageForms definitions suitable for:
 *   {{{field|MyField|property=Has something|input type=dropdown|values=A,B}}}
 */
class PropertyInputMapper {

    /* =====================================================================
     * MAPPING: SMW Datatype → Default PageForms Input Type
     * ===================================================================== */

    /** @var array<string,string> */
    private static array $datatypeMap = [
        'Text'                  => 'text',
        'URL'                   => 'text',
        'Email'                 => 'text',
        'Telephone number'      => 'text',
        'Number'                => 'number',
        'Quantity'              => 'text',       // Could later allow min/max
        'Temperature'           => 'number',
        'Date'                  => 'datepicker',
        'Boolean'               => 'checkbox',
        'Code'                  => 'textarea',
        'Geographic coordinate' => 'text',
    ];

    /* =====================================================================
     * HIGH-LEVEL INPUT TYPE LOGIC
     * ===================================================================== */

    /**
     * Resolve the PageForms input type in strict priority order.
     */
    public function getInputType( PropertyModel $property ): string {

        // (1) Multiple values → tokens
        if ( $property->allowsMultipleValues() ) {
            return 'tokens';
        }

        // (2) Enum values → dropdown
        if ( $property->hasAllowedValues() ) {
            return 'dropdown';
        }

        // (3) Autocomplete source → combobox
        if ( $property->shouldAutocomplete() ) {
            return 'combobox';
        }

        // (4) Page-type property → combobox
        if ( $property->isPageType() ) {
            return 'combobox';
        }

        // (5) Fallback to datatype mapping
        $datatype = $property->getDatatype();
        return self::$datatypeMap[$datatype] ?? 'text';
    }

    /* =====================================================================
     * PARAMETER LOGIC
     * ===================================================================== */

    /**
     * Optional parameters for PageForms input types.
     *
     * Returns key/value pairs where values may be empty strings for boolean flags:
     *   - multiple=""
     *   - autocomplete="on"
     *   - values="A,B,C"
     *   - rows="10"
     */
    public function getInputParameters( PropertyModel $property ): array {

        $params = [];
        $datatype = $property->getDatatype();

        /* -------------------------------------------
         * MULTIPLE VALUES
         * ------------------------------------------- */
        if ( $property->allowsMultipleValues() ) {
            $params['multiple'] = '';
        }

        /* -------------------------------------------
         * ENUMERATED VALUES (dropdown/categorized lists)
         * ------------------------------------------- */
        if ( $property->hasAllowedValues() ) {
            $clean = array_map( static fn( $v ) => trim( (string)$v ), $property->getAllowedValues() );
            $clean = array_filter( $clean, static fn( $v ) => $v !== '' );
            $params['values'] = implode( ',', $clean );
            return $params; // Enum overrides all other behaviors
        }

        /* -------------------------------------------
         * AUTOCOMPLETE SOURCES
         * ------------------------------------------- */
        if ( $property->shouldAutocomplete() ) {

            if ( $property->getAllowedCategory() !== null ) {
                $params['values from category'] = $property->getAllowedCategory();
                $params['autocomplete'] = 'on';
                return $params;
            }

            if ( $property->getAllowedNamespace() !== null ) {
                $params['values from namespace'] = $property->getAllowedNamespace();
                $params['autocomplete'] = 'on';
                return $params;
            }
        }

        /* -------------------------------------------
         * PAGE TYPE with range restriction
         * ------------------------------------------- */
        if ( $property->isPageType() && $property->getRangeCategory() !== null ) {
            $params['values from category'] = $property->getRangeCategory();
            $params['autocomplete'] = 'on';
        }

        /* -------------------------------------------
         * BASIC TEXT FIELDS
         * ------------------------------------------- */
        if ( in_array( $datatype, [ 'Text', 'Email', 'URL', 'Telephone number' ], true ) ) {
            $params['size'] = '60';
        }

        /* -------------------------------------------
         * TEXTAREA
         * ------------------------------------------- */
        if ( $datatype === 'Code' ) {
            $params['rows'] = '10';
            $params['cols'] = '80';
        }

        /* -------------------------------------------
         * BOOLEAN (checkbox) needs no parameters
         * ------------------------------------------- */

        return $params;
    }

    /* =====================================================================
     * FINAL STRING ASSEMBLY
     * ===================================================================== */

    /**
     * Build the PageForms "input type=..." string.
     */
    public function generateInputDefinition(
        PropertyModel $property,
        bool $isMandatory = false
    ): string {

        $inputType = $this->getInputType( $property );
        $params    = $this->getInputParameters( $property );

        // Only required fields get mandatory validation
        if ( $isMandatory ) {
            $params['mandatory'] = 'true';
        }

        // Assemble "|key=value" or "|key" for boolean flags
        $segments = [];
        foreach ( $params as $key => $value ) {

            if ( $value === '' ) {
                // Boolean PF parameters: |multiple
                $segments[] = $key;
                continue;
            }

            if ( $value === null ) {
                continue;
            }

            $segments[] = $key . '=' . $value;
        }

        return 'input type=' . $inputType
            . ( empty($segments) ? '' : '|' . implode( '|', $segments ) );
    }
}
