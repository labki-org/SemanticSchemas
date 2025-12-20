<?php

namespace MediaWiki\Extension\SemanticSchemas\Generator;

use MediaWiki\Extension\SemanticSchemas\Schema\PropertyModel;

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
class PropertyInputMapper
{

    /* =====================================================================
     * MAPPING: SMW Datatype → Default PageForms Input Type
     * ===================================================================== */

    /** @var array<string,string> */
    private static array $datatypeMap = [
        'Text' => 'text',
        'URL' => 'text',
        'Email' => 'text',
        'Telephone number' => 'text',
        'Number' => 'number',
        'Quantity' => 'text',
        'Temperature' => 'number',
        'Date' => 'datepicker',
        'Boolean' => 'checkbox',
        'Code' => 'textarea',
        'Geographic coordinate' => 'text',
    ];

    /* =====================================================================
     * HIGH-LEVEL INPUT TYPE LOGIC
     * ===================================================================== */

    /**
     * Resolve the PageForms input type in strict priority order.
     */
    public function getInputType(PropertyModel $property): string
    {

        // (1) Multiple values → tokens
        if ($property->allowsMultipleValues()) {
            return 'tokens';
        }

        // (2) Enum values → dropdown
        if ($property->hasAllowedValues()) {
            return 'dropdown';
        }

        // (3) Autocomplete source → combobox
        if ($property->shouldAutocomplete()) {
            return 'combobox';
        }

        // (4) Page-type property → combobox
        if ($property->isPageType()) {
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
     */
    public function getInputParameters(PropertyModel $property): array
    {

        $params = [];
        $datatype = $property->getDatatype();

        /* -------------------------------------------
         * MULTIPLE VALUES
         * ------------------------------------------- */
        if ($property->allowsMultipleValues()) {
            $params['multiple'] = '';
        }

        /* -------------------------------------------
         * ENUMERATED VALUES (dropdown/categorized lists)
         * ------------------------------------------- */
        if ($property->hasAllowedValues()) {
            $clean = array_map(static fn($v) => trim((string) $v), $property->getAllowedValues());
            $clean = array_filter($clean, static fn($v) => $v !== '');
            if (!empty($clean)) {
                $params['values'] = implode(',', $clean);
            }
            return $params;
        }

        /* -------------------------------------------
         * AUTOCOMPLETE SOURCES
         * ------------------------------------------- */
        if ($property->shouldAutocomplete()) {

            $allowedCategory = $property->getAllowedCategory();
            if ($allowedCategory !== null && $allowedCategory !== '') {
                $params['values from category'] = (string) $allowedCategory;
                $params['autocomplete'] = 'on';
                return $params;
            }

            $allowedNamespace = $property->getAllowedNamespace();
            if ($allowedNamespace !== null && $allowedNamespace !== '') {
                $params['values from namespace'] = (string) $allowedNamespace;
                $params['autocomplete'] = 'on';
                return $params;
            }
        }

        /* -------------------------------------------
         * PAGE TYPE with range restriction
         * ------------------------------------------- */
        if ($property->isPageType()) {
            $rangeCategory = $property->getRangeCategory();
            if ($rangeCategory !== null && $rangeCategory !== '') {
                $params['values from category'] = (string) $rangeCategory;
                $params['autocomplete'] = 'on';
            }
        }

        /* -------------------------------------------
         * BASIC TEXT FIELDS
         * ------------------------------------------- */
        if (in_array($datatype, ['Text', 'Email', 'URL', 'Telephone number'], true)) {
            $params['size'] = '60';
        }

        /* -------------------------------------------
         * TEXTAREA
         * ------------------------------------------- */
        if ($datatype === 'Code') {
            $params['rows'] = '10';
            $params['cols'] = '80';
        }

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

        $inputType = $this->getInputType($property);
        $params = $this->getInputParameters($property);

        if ($inputType === null || $inputType === '') {
            $inputType = 'text';
        }

        if ($isMandatory) {
            $params['mandatory'] = 'true';
        }

        $segments = [];
        foreach ($params as $key => $value) {
            $key = (string) $key;
            if ($key === '') {
                continue;
            }

            // Handle boolean flags (empty string value)
            if ($value === '') {
                $segments[] = $key;
                continue;
            }

            $value = (string) $value;
            if ($value !== '') {
                $segments[] = $key . '=' . $value;
            }
        }

        return 'input type=' . $inputType
            . (empty($segments) ? '' : '|' . implode('|', $segments));
    }
}
