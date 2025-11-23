<?php

namespace MediaWiki\Extension\StructureSync\Generator;

use MediaWiki\Extension\StructureSync\Schema\PropertyModel;

/**
 * PropertyInputMapper
 * --------------------
 * Converts SMW property metadata into PageForms input definitions.
 * 
 * This class encapsulates the complex logic of mapping Semantic MediaWiki
 * property types and constraints to PageForms input field types and parameters.
 * 
 * Mapping Priority (highest to lowest):
 * 1. Allowed values (enum) → dropdown
 * 2. Autocomplete sources (category/namespace) → combobox
 * 3. Page type with range restriction → combobox
 * 4. SMW datatype mapping → various input types
 *
 * Responsibilities:
 *   - Map SMW datatype → PageForms input type
 *   - Add PageForms parameters (values, size, ranges, etc.)
 *   - Produce syntactically valid PF input strings
 *   - Support required/optional fields based on CategoryModel requirements
 * 
 * PageForms Compatibility:
 *   - Tested with PageForms 5.3+
 *   - Uses standard PF input types (text, dropdown, combobox, datepicker, etc.)
 *   - Follows PF parameter syntax conventions
 */
class PropertyInputMapper {

    /* =====================================================================
     * PageForms INPUT TYPE RESOLUTION
     * ===================================================================== */

    /**
     * Determine PageForms input type for a given property.
     * 
     * The selection logic follows a priority order:
     * 1. If property has allowed values → dropdown (highest priority)
     * 2. If property has autocomplete source → combobox
     * 3. If property is Page type → combobox
     * 4. Otherwise → map by SMW datatype
     * 
     * Examples:
     *   - Text property → text input
     *   - Number property → number input
     *   - Date property → datepicker
     *   - Boolean property → checkbox
     *   - Property with allowed values ["A", "B", "C"] → dropdown
     *   - Page property with category range → combobox with autocomplete
     *
     * @param PropertyModel $property Property to map
     * @return string PageForms input type (e.g., 'text', 'dropdown', 'combobox')
     */
    public function getInputType( PropertyModel $property ): string {

        $datatype = $property->getDatatype();

        // Hard mapping based on SMW datatypes
        static $map = [
            'Text'                  => 'text',
            'URL'                   => 'text',
            'Email'                 => 'text',
            'Telephone number'      => 'text',
            'Number'                => 'number',
            'Quantity'              => 'text',  // Could convert to number later
            'Temperature'           => 'number',
            'Date'                  => 'datepicker',
            'Boolean'               => 'checkbox',
            'Code'                  => 'textarea',
            'Geographic coordinate' => 'text',
        ];

        // Special cases override defaults
        // Priority order: enum values → autocomplete sources → Page-type → defaults

        // 1. Dropdown enum (highest priority)
        if ( $property->hasAllowedValues() ) {
            return 'dropdown';
        }

        // 2. Autocomplete from category/namespace
        if ( $property->shouldAutocomplete() ) {
            return 'combobox';
        }

        // 3. Page-type reference (lookup/autocomplete)
        if ( $property->isPageType() ) {

            // If restricted to a category (range), use "combobox"
            if ( $property->getRangeCategory() !== null ) {
                return 'combobox';
            }

            // Else, still a Page → PageForms combobox
            return 'combobox';
        }

        // 4. Fallback to datatype mapping
        return $map[$datatype] ?? 'text';
    }

    /* =====================================================================
     * ADDITIONAL INPUT PARAMETERS
     * ===================================================================== */

    /**
     * Return PageForms input parameters for the property (except mandatory).
     * 
     * Generates the parameter string that goes after "input type=" in PageForms syntax.
     * Parameters are property-specific and depend on the datatype and constraints.
     * 
     * Common parameters:
     * - size: Width of text inputs (default: 60)
     * - rows/cols: Dimensions for textarea
     * - values: Comma-separated list for dropdown/checkboxes
     * - values from category: Source for autocomplete
     * - values from namespace: Source for autocomplete
     * - autocomplete: Enable autocomplete (on/off)
     * 
     * The mandatory parameter is handled separately in generateInputDefinition().
     *
     * @param PropertyModel $property Property to generate parameters for
     * @return array<string,string> Map of parameter name => value
     */
    public function getInputParameters( PropertyModel $property ): array {

        $params = [];
        $datatype = $property->getDatatype();

        /* ------------------------------------------------------------------
         * TEXT-LIKE FIELDS
         * ------------------------------------------------------------------ */
        if ( in_array( $datatype, [ 'Text', 'Email', 'URL', 'Telephone number' ] ) ) {
            $params['size'] = '60';
        }

        /* ------------------------------------------------------------------
         * TEXTAREA (code blocks)
         * ------------------------------------------------------------------ */
        if ( $datatype === 'Code' ) {
            $params['rows'] = '10';
            $params['cols'] = '80';
        }

        /* ------------------------------------------------------------------
         * ENUMERATED VALUES (highest priority)
         * ------------------------------------------------------------------ */
        if ( $property->hasAllowedValues() ) {
            // PageForms expects comma-separated list with NO SPACES
            $params['values'] = implode( ',', array_map( 'trim', $property->getAllowedValues() ) );
        }

        /* ------------------------------------------------------------------
         * AUTOCOMPLETE SOURCES (category/namespace)
         * ------------------------------------------------------------------ */
        elseif ( $property->shouldAutocomplete() ) {
            // Autocomplete from category
            if ( $property->getAllowedCategory() !== null ) {
                $params['values from category'] = $property->getAllowedCategory();
                $params['autocomplete'] = 'on';
            }
            // Autocomplete from namespace
            elseif ( $property->getAllowedNamespace() !== null ) {
                $params['values from namespace'] = $property->getAllowedNamespace();
                $params['autocomplete'] = 'on';
            }
        }

        /* ------------------------------------------------------------------
         * PAGE / COMBOBOX LOOKUPS (Page-type with rangeCategory)
         * ------------------------------------------------------------------ */
        elseif ( $property->isPageType() && $property->getRangeCategory() !== null ) {
            $params['values from category'] = $property->getRangeCategory();
            $params['autocomplete'] = 'on';
        }

        /* ------------------------------------------------------------------
         * BOOLEAN OVERRIDES
         * ------------------------------------------------------------------ */
        if ( $datatype === 'Boolean' ) {
            // No additional params needed; PF checkbox is simple
        }

        return $params;
    }

    /* =====================================================================
     * GENERATE INPUT STRING
     * ===================================================================== */

    /**
     * Build the PageForms input definition string.
     * 
     * Combines the input type and all parameters into a complete PageForms
     * input definition string suitable for use in {{{field}}} tags.
     * 
     * Output format: "input type=<type>|param1=value1|param2=value2|..."
     * 
     * Example outputs:
     * - "input type=text|size=60"
     * - "input type=dropdown|values=A,B,C|mandatory=true"
     * - "input type=combobox|values from category=Department|autocomplete=on"
     * 
     * The mandatory parameter is only added for required fields to avoid
     * PageForms validation on optional fields.
     *
     * @param PropertyModel $property Property to generate definition for
     * @param bool $isMandatory Whether the category requires this property
     * @return string PageForms input definition (without surrounding {{{field}}} tags)
     */
    public function generateInputDefinition( PropertyModel $property, bool $isMandatory = false ): string {

        $inputType = $this->getInputType( $property );
        $params = $this->getInputParameters( $property );

        // Only set mandatory parameter if field is required
        // For optional fields, omit the parameter entirely
        if ( $isMandatory ) {
            $params['mandatory'] = 'true';
        }

        // Build "key=value" segments
        $paramText = '';
        foreach ( $params as $key => $value ) {
            // Avoid empty or null parameters
            if ( $value === '' || $value === null ) {
                continue;
            }
            $paramText .= "|$key=$value";
        }

        return "input type=$inputType$paramText";
    }
}
