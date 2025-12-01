<?php

namespace MediaWiki\Extension\StructureSync\Schema;

use InvalidArgumentException;

/**
 * Immutable schema-level representation of a Category.
 *
 * Canonical schema structure:
 *
 *   name: string
 *   parents: string[]
 *   label: string
 *   description: string
 *   targetNamespace: string|null
 *
 *   properties:
 *     required: string[]
 *     optional: string[]
 *
 *   subgroups:
 *     required: string[]
 *     optional: string[]
 *
 *   display:
 *     header: string[]
 *     sections: [
 *        [ 'name' => string, 'properties' => string[] ],
 *     ]
 *
 *   forms:
 *     sections: [
 *        [ 'name' => string, 'properties' => string[] ],
 *     ]
 *
 * Fully immutable. No parsing or backward-compatibility.
 */
class CategoryModel {

    private string $name;
    private array $parents;

    private string $label;
    private string $description;

    private ?string $targetNamespace;

    private array $requiredProperties;
    private array $optionalProperties;

    private array $requiredSubgroups;
    private array $optionalSubgroups;

    private array $displayConfig;
    private array $formConfig;

    /* -------------------------------------------------------------------------
     * CONSTRUCTOR
     * ------------------------------------------------------------------------- */

    public function __construct(string $name, array $data = []) {

        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException("Category name cannot be empty.");
        }
        if (preg_match('/[<>{}|#]/', $name)) {
            throw new InvalidArgumentException("Category '{$name}' contains invalid characters.");
        }
        $this->name = $name;

        /* -------------------- Parents -------------------- */

        $this->parents = self::normalizeList($data['parents'] ?? []);
        foreach ($this->parents as $p) {
            if ($p === $name) {
                throw new InvalidArgumentException("Category '{$name}' cannot be its own parent.");
            }
        }

        /* -------------------- Metadata -------------------- */

        $this->label = (string)($data['label'] ?? $name);
        $this->description = (string)($data['description'] ?? '');

        $ns = $data['targetNamespace'] ?? null;
        $this->targetNamespace = ($ns !== null && trim($ns) !== '') ? trim($ns) : null;

        /* -------------------- Properties -------------------- */

        $props = $data['properties'] ?? [];
        if (!is_array($props)) {
            throw new InvalidArgumentException("Category '{$name}': 'properties' must be an array.");
        }

        $this->requiredProperties = self::normalizeList($props['required'] ?? []);
        $this->optionalProperties = self::normalizeList($props['optional'] ?? []);

        $dup = array_intersect($this->requiredProperties, $this->optionalProperties);
        if ($dup !== []) {
            throw new InvalidArgumentException(
                "Category '{$name}' has properties listed as both required and optional: " .
                implode(', ', $dup)
            );
        }

        /* -------------------- Subgroups -------------------- */

        $subs = $data['subgroups'] ?? [];
        if (!is_array($subs)) {
            throw new InvalidArgumentException("Category '{$name}': 'subgroups' must be an array.");
        }

        $this->requiredSubgroups = self::normalizeList($subs['required'] ?? []);
        $this->optionalSubgroups = self::normalizeList($subs['optional'] ?? []);

        $dupSG = array_intersect($this->requiredSubgroups, $this->optionalSubgroups);
        if ($dupSG !== []) {
            throw new InvalidArgumentException(
                "Category '{$name}' has subgroups listed as both required and optional: " .
                implode(', ', $dupSG)
            );
        }

        /* -------------------- Display Config -------------------- */

        $display = $data['display'] ?? [];
        if (!is_array($display)) {
            throw new InvalidArgumentException("Category '{$name}': 'display' must be an array.");
        }
        $this->displayConfig = $display;

        /* -------------------- Form Config -------------------- */

        $forms = $data['forms'] ?? [];
        if (!is_array($forms)) {
            throw new InvalidArgumentException("Category '{$name}': 'forms' must be an array.");
        }
        $this->formConfig = $forms;
    }

    /* -------------------------------------------------------------------------
     * ACCESSORS (read-only)
     * ------------------------------------------------------------------------- */

    public function getName(): string {
        return $this->name;
    }

    public function getParents(): array {
        return $this->parents;
    }

    public function getLabel(): string {
        return $this->label !== '' ? $this->label : $this->name;
    }

    public function getDescription(): string {
        return $this->description;
    }

    public function getTargetNamespace(): ?string {
        return $this->targetNamespace;
    }

    /* -------------------- Properties -------------------- */

    public function getRequiredProperties(): array {
        return $this->requiredProperties;
    }

    public function getOptionalProperties(): array {
        return $this->optionalProperties;
    }

    public function getAllProperties(): array {
        return array_values(array_unique(
            array_merge($this->requiredProperties, $this->optionalProperties)
        ));
    }

    /* -------------------- Subgroups -------------------- */

    public function getRequiredSubgroups(): array {
        return $this->requiredSubgroups;
    }

    public function getOptionalSubgroups(): array {
        return $this->optionalSubgroups;
    }

    public function hasSubgroups(): bool {
        return $this->requiredSubgroups !== [] || $this->optionalSubgroups !== [];
    }

    /* -------------------- Display + Forms -------------------- */

    public function getDisplayConfig(): array {
        return $this->displayConfig;
    }

    public function getDisplayHeaderProperties(): array {
        return $this->displayConfig['header'] ?? [];
    }

    public function getDisplaySections(): array {
        return $this->displayConfig['sections'] ?? [];
    }

    public function getFormConfig(): array {
        return $this->formConfig;
    }

    public function getFormSections(): array {
        return $this->formConfig['sections'] ?? [];
    }

    /* -------------------------------------------------------------------------
     * MERGING (CATEGORY + PARENT)
     * ------------------------------------------------------------------------- */

    public function mergeWithParent(CategoryModel $parent): CategoryModel {

        /* -------------------- Properties -------------------- */

        $mergedRequired = array_values(array_unique(array_merge(
            $parent->getRequiredProperties(),
            $this->requiredProperties
        )));

        $mergedOptional = array_values(array_diff(
            array_unique(array_merge(
                $parent->getOptionalProperties(),
                $this->optionalProperties
            )),
            $mergedRequired
        ));

        /* -------------------- Subgroups -------------------- */

        $mergedRequiredSG = array_values(array_unique(array_merge(
            $parent->getRequiredSubgroups(),
            $this->requiredSubgroups
        )));

        $mergedOptionalSG = array_values(array_diff(
            array_unique(array_merge(
                $parent->getOptionalSubgroups(),
                $this->optionalSubgroups
            )),
            $mergedRequiredSG
        ));

        /* -------------------- Display -------------------- */

        $mergedDisplay = self::mergeDisplayConfigs(
            $parent->getDisplayConfig(),
            $this->displayConfig
        );

        /* -------------------- Forms -------------------- */

        $mergedForms = self::mergeFormConfigs(
            $parent->getFormConfig(),
            $this->formConfig
        );

        /* -------------------- New CategoryModel -------------------- */

        return new self(
            $this->name,
            [
                'parents' => $this->parents,
                'label' => $this->label,
                'description' => $this->description,
                'targetNamespace' => $this->targetNamespace,
                'properties' => [
                    'required' => $mergedRequired,
                    'optional' => $mergedOptional,
                ],
                'subgroups' => [
                    'required' => $mergedRequiredSG,
                    'optional' => $mergedOptionalSG,
                ],
                'display' => $mergedDisplay,
                'forms' => $mergedForms,
            ]
        );
    }

    /* -------------------------------------------------------------------------
     * MERGE HELPERS
     * ------------------------------------------------------------------------- */

    private static function mergeDisplayConfigs(array $parent, array $child): array {
        if ($parent === []) {
            return $child;
        }
        if ($child === []) {
            return $parent;
        }

        $merged = $parent;

        if (isset($child['header'])) {
            $merged['header'] = self::normalizeList($child['header']);
        }

        if (isset($child['sections'])) {
            $mergedSections = $parent['sections'] ?? [];

            foreach ($child['sections'] as $c) {
                $found = false;

                foreach ($mergedSections as &$m) {
                    if (($m['name'] ?? null) === ($c['name'] ?? null)) {
                        $m = $c;
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $mergedSections[] = $c;
                }
            }

            $merged['sections'] = $mergedSections;
        }

        return $merged;
    }

    private static function mergeFormConfigs(array $parent, array $child): array {
        if ($parent === []) {
            return $child;
        }
        if ($child === []) {
            return $parent;
        }

        $merged = $parent;

        if (isset($child['sections'])) {
            $merged['sections'] = $child['sections'];
        }

        return $merged;
    }

    /* -------------------------------------------------------------------------
     * UTILITIES
     * ------------------------------------------------------------------------- */

    private static function normalizeList(array $list): array {
        $out = [];
        foreach ($list as $v) {
            $v = trim((string)$v);
            if ($v !== '' && !in_array($v, $out, true)) {
                $out[] = $v;
            }
        }
        return $out;
    }

    /* -------------------------------------------------------------------------
     * EXPORT
     * ------------------------------------------------------------------------- */

    public function toArray(): array {
        $out = [
            'parents' => $this->parents,
            'label' => $this->label,
            'description' => $this->description,
            'properties' => [
                'required' => $this->requiredProperties,
                'optional' => $this->optionalProperties,
            ],
        ];

        if ($this->hasSubgroups()) {
            $out['subgroups'] = [
                'required' => $this->requiredSubgroups,
                'optional' => $this->optionalSubgroups,
            ];
        }

        if ($this->displayConfig !== []) {
            $out['display'] = $this->displayConfig;
        }

        if ($this->formConfig !== []) {
            $out['forms'] = $this->formConfig;
        }

        if ($this->targetNamespace !== null) {
            $out['targetNamespace'] = $this->targetNamespace;
        }

        return $out;
    }
}
