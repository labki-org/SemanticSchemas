<?php

namespace MediaWiki\Extension\SemanticSchemas\Schema;

use InvalidArgumentException;

/**
 * SchemaComparer
 * ---------------
 * Compares two canonical SemanticSchemas schema arrays and produces a structured diff:
 *
 * [
 *   'categories' => [...],
 *   'properties' => [...],
 *   'subobjects' => [...],
 * ]
 *
 * Each section contains:
 *   [
 *     'added'    => [ ['name'=>..., 'data'=>...], ... ],
 *     'removed'  => [ ['name'=>..., 'data'=>...], ... ],
 *     'modified' => [ ['name'=>..., 'old'=>..., 'new'=>..., 'diff'=>...], ... ],
 *     'unchanged'=> [ 'Name1', 'Name2', ... ],
 *   ]
 *
 * The comparer is *strict*:
 * - Category, property, and subobject schemas must be canonical.
 * - Only canonical keys are compared.
 * - Order-sensitive arrays (display/forms) use structural comparison.
 */
class SchemaComparer
{

    /* -------------------------------------------------------------------------
     * PUBLIC API
     * ---------------------------------------------------------------------- */

    /**
     * Compare two full schema arrays and return a structured diff.
     *
     * @param array $schemaA "New" schema (file import)
     * @param array $schemaB "Old" schema (wiki export)
     * @return array
     */
    public function compare(array $schemaA, array $schemaB): array
    {

        return [
            'categories' => $this->compareEntityMap(
                $schemaA['categories'] ?? [],
                $schemaB['categories'] ?? [],
                fn($a, $b) => $this->diffCategory($a, $b)
            ),
            'properties' => $this->compareEntityMap(
                $schemaA['properties'] ?? [],
                $schemaB['properties'] ?? [],
                fn($a, $b) => $this->diffProperty($a, $b)
            ),
            'subobjects' => $this->compareEntityMap(
                $schemaA['subobjects'] ?? [],
                $schemaB['subobjects'] ?? [],
                fn($a, $b) => $this->diffSubobject($a, $b)
            ),
        ];
    }

    /* -------------------------------------------------------------------------
     * GENERIC MAP COMPARISON
     * ---------------------------------------------------------------------- */

    /**
     * Compare two entity maps (categories/properties/subobjects).
     *
     * @param array<string,array> $mapA  name => canonical data
     * @param array<string,array> $mapB
     * @param callable $diffFn function( array $a, array $b ): array
     * @return array
     */
    private function compareEntityMap(array $mapA, array $mapB, callable $diffFn): array
    {

        $added = [];
        $removed = [];
        $modified = [];
        $unchanged = [];

        $allNames = array_unique(array_merge(array_keys($mapA), array_keys($mapB)));
        sort($allNames, SORT_STRING);

        foreach ($allNames as $name) {

            $existsA = array_key_exists($name, $mapA);
            $existsB = array_key_exists($name, $mapB);

            if ($existsA && !$existsB) {
                $added[] = ['name' => $name, 'data' => $mapA[$name]];
                continue;
            }

            if (!$existsA && $existsB) {
                $removed[] = ['name' => $name, 'data' => $mapB[$name]];
                continue;
            }

            // Both exist → compare canonical data
            $dataA = $mapA[$name] ?? [];
            $dataB = $mapB[$name] ?? [];

            $diff = $diffFn($dataA, $dataB);
            if ($diff !== []) {
                $modified[] = [
                    'name' => $name,
                    'old' => $dataB,
                    'new' => $dataA,
                    'diff' => $diff,
                ];
            } else {
                $unchanged[] = $name;
            }
        }

        return [
            'added' => $added,
            'removed' => $removed,
            'modified' => $modified,
            'unchanged' => $unchanged,
        ];
    }

    /* -------------------------------------------------------------------------
     * CATEGORY DIFF
     * ---------------------------------------------------------------------- */

    private function diffCategory(array $a, array $b): array
    {

        $diff = [];

        // parents (order-insensitive)
        if ($this->arraysDiffer($a['parents'] ?? [], $b['parents'] ?? [])) {
            $diff['parents'] = [
                'old' => $b['parents'] ?? [],
                'new' => $a['parents'] ?? [],
            ];
        }

        // label
        if (($a['label'] ?? '') !== ($b['label'] ?? '')) {
            $diff['label'] = ['old' => $b['label'] ?? '', 'new' => $a['label'] ?? ''];
        }

        // description
        if (($a['description'] ?? '') !== ($b['description'] ?? '')) {
            $diff['description'] = [
                'old' => $b['description'] ?? '',
                'new' => $a['description'] ?? '',
            ];
        }

        // targetNamespace (string|null)
        if (($a['targetNamespace'] ?? null) !== ($b['targetNamespace'] ?? null)) {
            $diff['targetNamespace'] = [
                'old' => $b['targetNamespace'] ?? null,
                'new' => $a['targetNamespace'] ?? null,
            ];
        }

        // properties (required/optional lists)
        $propsA = $a['properties'] ?? [];
        $propsB = $b['properties'] ?? [];

        if ($this->arraysDiffer($propsA['required'] ?? [], $propsB['required'] ?? [])) {
            $diff['properties']['required'] = [
                'old' => $propsB['required'] ?? [],
                'new' => $propsA['required'] ?? [],
            ];
        }

        if ($this->arraysDiffer($propsA['optional'] ?? [], $propsB['optional'] ?? [])) {
            $diff['properties']['optional'] = [
                'old' => $propsB['optional'] ?? [],
                'new' => $propsA['optional'] ?? [],
            ];
        }

        // subobjects (required/optional)
        $sgA = $a['subobjects'] ?? [];
        $sgB = $b['subobjects'] ?? [];

        if ($this->arraysDiffer($sgA['required'] ?? [], $sgB['required'] ?? [])) {
            $diff['subobjects']['required'] = [
                'old' => $sgB['required'] ?? [],
                'new' => $sgA['required'] ?? [],
            ];
        }

        if ($this->arraysDiffer($sgA['optional'] ?? [], $sgB['optional'] ?? [])) {
            $diff['subobjects']['optional'] = [
                'old' => $sgB['optional'] ?? [],
                'new' => $sgA['optional'] ?? [],
            ];
        }

        // display (order-sensitive)
        if ($this->deepDiffer($a['display'] ?? [], $b['display'] ?? [])) {
            $diff['display'] = [
                'old' => $b['display'] ?? [],
                'new' => $a['display'] ?? [],
            ];
        }

        // forms (order-sensitive)
        if ($this->deepDiffer($a['forms'] ?? [], $b['forms'] ?? [])) {
            $diff['forms'] = [
                'old' => $b['forms'] ?? [],
                'new' => $a['forms'] ?? [],
            ];
        }

        return $diff;
    }

    /* -------------------------------------------------------------------------
     * PROPERTY DIFF
     * ---------------------------------------------------------------------- */

    private function diffProperty(array $a, array $b): array
    {

        $diff = [];

        if (($a['datatype'] ?? '') !== ($b['datatype'] ?? '')) {
            $diff['datatype'] = [
                'old' => $b['datatype'] ?? '',
                'new' => $a['datatype'] ?? '',
            ];
        }

        if (($a['label'] ?? '') !== ($b['label'] ?? '')) {
            $diff['label'] = [
                'old' => $b['label'] ?? '',
                'new' => $a['label'] ?? '',
            ];
        }

        if (($a['description'] ?? '') !== ($b['description'] ?? '')) {
            $diff['description'] = [
                'old' => $b['description'] ?? '',
                'new' => $a['description'] ?? '',
            ];
        }

        // allowedValues: order-insensitive list
        if ($this->arraysDiffer($a['allowedValues'] ?? [], $b['allowedValues'] ?? [])) {
            $diff['allowedValues'] = [
                'old' => $b['allowedValues'] ?? [],
                'new' => $a['allowedValues'] ?? [],
            ];
        }

        // rangeCategory
        if (($a['rangeCategory'] ?? null) !== ($b['rangeCategory'] ?? null)) {
            $diff['rangeCategory'] = [
                'old' => $b['rangeCategory'] ?? null,
                'new' => $a['rangeCategory'] ?? null,
            ];
        }

        // subpropertyOf
        if (($a['subpropertyOf'] ?? null) !== ($b['subpropertyOf'] ?? null)) {
            $diff['subpropertyOf'] = [
                'old' => $b['subpropertyOf'] ?? null,
                'new' => $a['subpropertyOf'] ?? null,
            ];
        }

        // display block (order-sensitive)
        if ($this->deepDiffer($a['display'] ?? [], $b['display'] ?? [])) {
            $diff['display'] = [
                'old' => $b['display'] ?? [],
                'new' => $a['display'] ?? [],
            ];
        }

        // allowedCategory / allowedNamespace
        if (($a['allowedCategory'] ?? null) !== ($b['allowedCategory'] ?? null)) {
            $diff['allowedCategory'] = [
                'old' => $b['allowedCategory'] ?? null,
                'new' => $a['allowedCategory'] ?? null,
            ];
        }

        if (($a['allowedNamespace'] ?? null) !== ($b['allowedNamespace'] ?? null)) {
            $diff['allowedNamespace'] = [
                'old' => $b['allowedNamespace'] ?? null,
                'new' => $a['allowedNamespace'] ?? null,
            ];
        }

        if (($a['allowsMultipleValues'] ?? false) !== ($b['allowsMultipleValues'] ?? false)) {
            $diff['allowsMultipleValues'] = [
                'old' => $b['allowsMultipleValues'] ?? false,
                'new' => $a['allowsMultipleValues'] ?? false,
            ];
        }

        return $diff;
    }

    /* -------------------------------------------------------------------------
     * SUBOBJECT DIFF
     * ---------------------------------------------------------------------- */

    private function diffSubobject(array $a, array $b): array
    {

        $diff = [];

        if (($a['label'] ?? '') !== ($b['label'] ?? '')) {
            $diff['label'] = [
                'old' => $b['label'] ?? '',
                'new' => $a['label'] ?? '',
            ];
        }

        if (($a['description'] ?? '') !== ($b['description'] ?? '')) {
            $diff['description'] = [
                'old' => $b['description'] ?? '',
                'new' => $a['description'] ?? '',
            ];
        }

        $propsA = $a['properties'] ?? [];
        $propsB = $b['properties'] ?? [];

        if ($this->arraysDiffer($propsA['required'] ?? [], $propsB['required'] ?? [])) {
            $diff['properties']['required'] = [
                'old' => $propsB['required'] ?? [],
                'new' => $propsA['required'] ?? [],
            ];
        }

        if ($this->arraysDiffer($propsA['optional'] ?? [], $propsB['optional'] ?? [])) {
            $diff['properties']['optional'] = [
                'old' => $propsB['optional'] ?? [],
                'new' => $propsA['optional'] ?? [],
            ];
        }

        return $diff;
    }

    /* -------------------------------------------------------------------------
     * HELPERS
     * ---------------------------------------------------------------------- */

    /**
     * Order-insensitive comparison of scalar lists.
     */
    private function arraysDiffer(array $a, array $b): bool
    {
        $a = array_map('strval', $a);
        $b = array_map('strval', $b);
        sort($a);
        sort($b);
        return $a !== $b;
    }

    /**
     * Strict, order-sensitive structural comparison.
     */
    private function deepDiffer($a, $b): bool
    {
        return json_encode($a) !== json_encode($b);
    }

    /* -------------------------------------------------------------------------
     * SUMMARY GENERATOR
     * ---------------------------------------------------------------------- */

    /**
     * Generate a simple human-readable summary.
     */
    public function generateSummary(array $diff): string
    {

        $fmt = function ($section) {
            return sprintf(
                "Added: %d, Removed: %d, Modified: %d, Unchanged: %d",
                count($section['added'] ?? []),
                count($section['removed'] ?? []),
                count($section['modified'] ?? []),
                count($section['unchanged'] ?? [])
            );
        };

        return implode("\n", [
            "Categories: " . $fmt($diff['categories'] ?? []),
            "Properties: " . $fmt($diff['properties'] ?? []),
            "Subobjects: " . $fmt($diff['subobjects'] ?? []),
        ]);
    }
}
