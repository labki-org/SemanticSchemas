<?php

namespace MediaWiki\Extension\SemanticSchemas\Schema;

use MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;
use MediaWiki\Extension\SemanticSchemas\Store\WikiSubobjectStore;

/**
 * ExtensionConfigInstaller
 * ------------------------
 * Applies the bundled extension configuration schema
 * (resources/extension-config.json) to the wiki by creating or
 * updating Category:, Property:, and Subobject: pages.
 *
 * This is a minimal, internal helper that reuses the canonical
 * schema models + Wiki*Store classes, and writes via PageCreator.
 */
class ExtensionConfigInstaller
{

    private SchemaLoader $loader;
    private SchemaValidator $validator;

    private WikiCategoryStore $categoryStore;
    private WikiPropertyStore $propertyStore;
    private WikiSubobjectStore $subobjectStore;

    public function __construct(
        ?SchemaLoader $loader = null,
        ?SchemaValidator $validator = null,
        ?WikiCategoryStore $categoryStore = null,
        ?WikiPropertyStore $propertyStore = null,
        ?WikiSubobjectStore $subobjectStore = null
    ) {
        $this->loader = $loader ?? new SchemaLoader();
        $this->validator = $validator ?? new SchemaValidator();

        $this->categoryStore = $categoryStore ?? new WikiCategoryStore();
        $this->propertyStore = $propertyStore ?? new WikiPropertyStore();
        $this->subobjectStore = $subobjectStore ?? new WikiSubobjectStore();
    }

    /**
     * Load and apply an extension config schema from a JSON/YAML file.
     *
     * @param string $filePath
     * @return array{
     *   errors:array,
     *   warnings:array,
     *   applied:array{
     *     categories:array<string,bool>,
     *     properties:array<string,bool>,
     *     subobjects:array<string,bool>
     *   }
     * }
     */
    public function applyFromFile(string $filePath): array
    {
        $schema = $this->loader->loadFromFile($filePath);
        return $this->applySchema($schema);
    }

    /**
     * Apply a parsed extension config schema array.
     *
     * If validation errors are present, no pages are written and the
     * errors are returned to the caller.
     *
     * @param array $schema
     * @return array See applyFromFile()
     */
    public function applySchema(array $schema): array
    {
        $validation = $this->validator->validateSchemaWithSeverity($schema);

        $result = [
            'errors' => $validation['errors'],
            'warnings' => $validation['warnings'],
            'applied' => [
                'categories' => [],
                'properties' => [],
                'subobjects' => [],
            ],
        ];

        // Do not write anything if the schema is invalid.
        if ($validation['errors']) {
            return $result;
        }

        $categories = $schema['categories'] ?? [];
        $properties = $schema['properties'] ?? [];
        $subobjects = $schema['subobjects'] ?? [];

        // 1. Properties
        foreach ($properties as $name => $data) {
            $model = new PropertyModel($name, $data ?? []);
            $ok = $this->propertyStore->writeProperty($model);
            $result['applied']['properties'][$name] = $ok;
        }

        // 2. Subobjects
        foreach ($subobjects as $name => $data) {
            $model = new SubobjectModel($name, $data ?? []);
            $ok = $this->subobjectStore->writeSubobject($model);
            $result['applied']['subobjects'][$name] = $ok;
        }

        // 3. Categories
        foreach ($categories as $name => $data) {
            $model = new CategoryModel($name, $data ?? []);
            $ok = $this->categoryStore->writeCategory($model);
            $result['applied']['categories'][$name] = $ok;
        }

        return $result;
    }
}


