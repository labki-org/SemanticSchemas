<?php

namespace MediaWiki\Extension\SemanticSchemas\Schema;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * SchemaLoader
 * -------------
 * Loads and saves canonical SemanticSchemas schema arrays using JSON/YAML
 * with optional gzip compression.
 *
 * Features:
 *   ✓ Strict JSON/YAML parsing
 *   ✓ Auto-detection of JSON vs YAML
 *   ✓ Safe file I/O with clear error messages
 *   ✓ Pretty JSON/YAML output
 *   ✓ 10MB default file-size limit
 *   ✓ Gzip input/output support
 *   ✓ Canonical empty-schema generator
 */
class SchemaLoader
{

    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB

    /* -------------------------------------------------------------------------
     * LOADING FROM STRINGS
     * ---------------------------------------------------------------------- */

    public function loadFromJson(string $json): array
    {
        if (trim($json) === '') {
            throw new RuntimeException('Empty JSON content');
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new RuntimeException('JSON decoded to non-array structure');
        }

        return $data;
    }

    public function loadFromYaml(string $yaml): array
    {
        if (trim($yaml) === '') {
            throw new RuntimeException('Empty YAML content');
        }

        try {
            $data = Yaml::parse($yaml);
        } catch (\Throwable $e) {
            throw new RuntimeException('Invalid YAML: ' . $e->getMessage());
        }

        if (!is_array($data)) {
            throw new RuntimeException('YAML parsed to non-array structure');
        }

        return $data;
    }

    /* -------------------------------------------------------------------------
     * SAVING TO STRINGS
     * ---------------------------------------------------------------------- */

    public function saveToJson(array $schema): string
    {
        return json_encode(
            $schema,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE
        ) ?: '{}';
    }

    public function saveToYaml(array $schema): string
    {
        return Yaml::dump($schema, 4, 2);
    }

    /* -------------------------------------------------------------------------
     * AUTO-DETECT FORMAT FROM STRING CONTENT
     * ---------------------------------------------------------------------- */

    public function detectFormat(string $content): string
    {
        $trim = ltrim($content);

        if ($trim === '') {
            return 'yaml'; // empty JSON is invalid
        }

        $first = $trim[0];

        // JSON begins with { or [
        return ($first === '{' || $first === '[') ? 'json' : 'yaml';
    }

    public function loadFromContent(string $content): array
    {
        if (trim($content) === '') {
            throw new RuntimeException('Schema content is empty');
        }

        $format = $this->detectFormat($content);

        if ($format === 'json') {
            try {
                return $this->loadFromJson($content);
            } catch (RuntimeException $e) {
                // Allow fallback to YAML in ambiguous cases
                return $this->loadFromYaml($content);
            }
        }

        return $this->loadFromYaml($content);
    }

    /* -------------------------------------------------------------------------
     * LOADING FROM FILES
     * ---------------------------------------------------------------------- */

    public function loadFromFile(string $filePath): array
    {
        if (!is_file($filePath)) {
            throw new RuntimeException("File not found: $filePath");
        }

        $size = filesize($filePath);
        if ($size === false) {
            throw new RuntimeException("Unable to read file size: $filePath");
        }

        $limit = defined('MW_PHPUNIT_TEST')
            ? self::MAX_FILE_SIZE * 10
            : self::MAX_FILE_SIZE;

        if ($size > $limit) {
            throw new RuntimeException(
                sprintf(
                    'Schema file is too large: %.1fMB (max allowed %.1fMB)',
                    $size / 1048576,
                    $limit / 1048576
                )
            );
        }

        $raw = file_get_contents($filePath);
        if ($raw === false) {
            throw new RuntimeException("Failed to read file: $filePath");
        }

        if ($this->isGzipFile($filePath)) {
            $raw = gzdecode($raw);
            if ($raw === false) {
                throw new RuntimeException("Failed to decompress gzip file: $filePath");
            }
        }

        return $this->loadFromContent($raw);
    }

    /* -------------------------------------------------------------------------
     * SAVING TO FILES
     * ---------------------------------------------------------------------- */

    public function saveToFile(array $schema, string $filePath): bool
    {

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext === 'json') {
            $content = $this->saveToJson($schema);
        } elseif ($ext === 'yaml' || $ext === 'yml') {
            $content = $this->saveToYaml($schema);
        } else {
            // default to JSON
            $content = $this->saveToJson($schema);
        }

        if (file_put_contents($filePath, $content) === false) {
            throw new RuntimeException("Failed to write schema to: $filePath");
        }

        return true;
    }

    /**
     * Save schema to file with optional gzip compression.
     */
    public function saveToFileCompressed(array $schema, string $filePath, bool $gzip = true): bool
    {

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $content = $this->saveToJson($schema); // default format

        if (in_array($ext, ['yaml', 'yml'], true)) {
            $content = $this->saveToYaml($schema);
        }

        if ($gzip) {
            $gz = gzencode($content, 9);
            if ($gz === false) {
                throw new RuntimeException("Failed to gzip-compress schema");
            }
            $content = $gz;
        }

        if (file_put_contents($filePath, $content) === false) {
            throw new RuntimeException("Failed to write file: $filePath");
        }

        return true;
    }

    /* -------------------------------------------------------------------------
     * STRUCTURE HELPERS
     * ---------------------------------------------------------------------- */

    public function createEmptySchema(): array
    {
        return [
            'schemaVersion' => '1.0',
            'categories' => [],
            'properties' => [],
            'subobjects' => [],
        ];
    }

    public function hasValidStructure(array $schema): bool
    {
        return isset($schema['schemaVersion'])
            && is_array($schema['categories'] ?? null)
            && is_array($schema['properties'] ?? null)
            && is_array($schema['subobjects'] ?? null);
    }

    /* -------------------------------------------------------------------------
     * INTERNAL HELPERS
     * ---------------------------------------------------------------------- */

    /**
     * Test whether the file begins with gzip magic bytes.
     */
    private function isGzipFile(string $filePath): bool
    {

        $fh = fopen($filePath, 'rb');
        if ($fh === false) {
            return false;
        }

        $bytes = fread($fh, 2);
        fclose($fh);

        if ($bytes === false || strlen($bytes) < 2) {
            return false;
        }

        return ord($bytes[0]) === 0x1f && ord($bytes[1]) === 0x8b;
    }
}
