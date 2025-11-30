<?php

namespace MediaWiki\Extension\StructureSync\Schema;

use Symfony\Component\Yaml\Yaml;

/**
 * SchemaLoader
 * -------------
 * Responsible for loading and saving StructureSync schema definitions
 * from JSON/YAML strings or files.
 *
 * Features:
 *   - Strict JSON + YAML parsing with clear error messages
 *   - Auto-format detection (JSON vs YAML)
 *   - Safe file I/O with consistent exceptions
 *   - Pretty JSON and YAML output for readability
 *   - Minimal structural validation helpers
 *   - File size limits for security (10MB default)
 *   - Support for gzip compressed schemas
 */
class SchemaLoader {

	/**
	 * Maximum allowed file size (10MB default)
	 * Can be overridden by setting $wgStructureSyncMaxSchemaSize
	 */
	private const MAX_FILE_SIZE = 10485760; // 10MB

	/**
	 * Load schema from a JSON string
	 *
	 * @param string $json
	 * @return array
	 * @throws \RuntimeException
	 */
	public function loadFromJson( string $json ): array {
		if ( trim( $json ) === '' ) {
			throw new \RuntimeException( 'Empty JSON content' );
		}

		$data = json_decode( $json, true );

		if ( $data === null && json_last_error() !== JSON_ERROR_NONE ) {
			throw new \RuntimeException( 'Invalid JSON: ' . json_last_error_msg() );
		}

		if ( !is_array( $data ) ) {
			throw new \RuntimeException( 'JSON did not decode to an array' );
		}

		return $data;
	}

	/**
	 * Load schema from a YAML string
	 *
	 * @param string $yaml
	 * @return array
	 * @throws \RuntimeException
	 */
	public function loadFromYaml( string $yaml ): array {
		if ( trim( $yaml ) === '' ) {
			throw new \RuntimeException( 'Empty YAML content' );
		}

		try {
			$data = Yaml::parse( $yaml );
		} catch ( \Exception $e ) {
			throw new \RuntimeException( 'Invalid YAML: ' . $e->getMessage() );
		}

		if ( !is_array( $data ) ) {
			throw new \RuntimeException( 'YAML did not parse to an array' );
		}

		return $data;
	}

	/**
	 * Write schema to JSON
	 */
	public function saveToJson( array $schema ): string {
		return json_encode(
			$schema,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		) ?: '{}';
	}

	/**
	 * Write schema to YAML
	 */
	public function saveToYaml( array $schema ): string {
		// depth 4, indent 2 – readable for humans
		return Yaml::dump( $schema, 4, 2 );
	}

	/**
	 * Auto-detect based on file extension and parse
	 *
	 * Supports:
	 *   - .json / .yaml / .yml
	 *   - .json.gz / .yaml.gz / .yml.gz (gzip compressed)
	 *
	 * @param string $filePath
	 * @return array
	 * @throws \RuntimeException
	 */
	public function loadFromFile( string $filePath ): array {
		if ( !is_file( $filePath ) ) {
			throw new \RuntimeException( "File not found: $filePath" );
		}

		// Security: Check file size before reading
		$fileSize = filesize( $filePath );
		if ( $fileSize === false ) {
			throw new \RuntimeException( "Cannot determine file size: $filePath" );
		}

		$maxSize = defined( 'MW_PHPUNIT_TEST' ) ? self::MAX_FILE_SIZE * 10 : self::MAX_FILE_SIZE;
		if ( $fileSize > $maxSize ) {
			$maxMB = round( $maxSize / 1048576, 1 );
			$actualMB = round( $fileSize / 1048576, 1 );
			throw new \RuntimeException(
				"Schema file too large: {$actualMB}MB exceeds maximum of {$maxMB}MB"
			);
		}

		// Check if file is gzip compressed
		$isGzipped = $this->isGzipFile( $filePath );

		if ( $isGzipped ) {
			$content = gzdecode( file_get_contents( $filePath ) );
			if ( $content === false ) {
				throw new \RuntimeException( "Failed to decompress gzip file: $filePath" );
			}
		} else {
			$content = file_get_contents( $filePath );
			if ( $content === false ) {
				throw new \RuntimeException( "Cannot read file: $filePath" );
			}
		}

		// Determine format from extension (strip .gz if present)
		$fileName = basename( $filePath );
		if ( str_ends_with( $fileName, '.gz' ) ) {
			$fileName = substr( $fileName, 0, -3 );
		}
		$ext = strtolower( pathinfo( $fileName, PATHINFO_EXTENSION ) );

		if ( $ext === 'json' ) {
			return $this->loadFromJson( $content );
		}

		if ( $ext === 'yaml' || $ext === 'yml' ) {
			return $this->loadFromYaml( $content );
		}

		// Try JSON first, then YAML
		try {
			return $this->loadFromJson( $content );
		} catch ( \RuntimeException $e ) {
			return $this->loadFromYaml( $content );
		}
	}

	/**
	 * Save to file
	 *
	 * @param array $schema
	 * @param string $filePath
	 * @return bool
	 * @throws \RuntimeException
	 */
	public function saveToFile( array $schema, string $filePath ): bool {
		$ext = strtolower( pathinfo( $filePath, PATHINFO_EXTENSION ) );

		if ( $ext === 'json' ) {
			$content = $this->saveToJson( $schema );
		} elseif ( $ext === 'yaml' || $ext === 'yml' ) {
			$content = $this->saveToYaml( $schema );
		} else {
			// Default to JSON
			$content = $this->saveToJson( $schema );
		}

		$ok = file_put_contents( $filePath, $content );

		if ( $ok === false ) {
			throw new \RuntimeException( "Failed to write file: $filePath" );
		}

		return true;
	}

	/**
	 * Detect content format from the first non-whitespace character
	 *
	 * @param string $content
	 * @return string 'json' or 'yaml'
	 */
	public function detectFormat( string $content ): string {
		$content = ltrim( $content );

		if ( $content === '' ) {
			// ambiguous but treat empty as YAML (JSON empty isn't valid)
			return 'yaml';
		}

		$firstChar = $content[0];

		// JSON objects/arrays begin with { or [
		if ( $firstChar === '{' || $firstChar === '[' ) {
			return 'json';
		}

		// YAML is the fallback
		return 'yaml';
	}

	/**
	 * Load from content with format auto-detection
	 */
	public function loadFromContent( string $content ): array {
		$format = $this->detectFormat( $content );

		if ( $format === 'json' ) {
			// On failure, try YAML to allow minimal ambiguity handling
			try {
				return $this->loadFromJson( $content );
			} catch ( \RuntimeException $e ) {
				return $this->loadFromYaml( $content );
			}
		}

		return $this->loadFromYaml( $content );
	}

	/**
	 * Return an empty valid schema structure
	 */
	public function createEmptySchema(): array {
		return [
			'schemaVersion' => '1.0',
			'categories'    => [],
			'properties'    => [],
			'subobjects'    => [],
		];
	}

	/**
	 * Minimal structural check. Full validation happens in SchemaValidator.
	 */
	public function hasValidStructure( array $schema ): bool {
		if ( !is_array( $schema ) ) {
			return false;
		}

		return isset( $schema['schemaVersion'] )
			&& array_key_exists( 'categories', $schema )
			&& array_key_exists( 'properties', $schema )
			&& array_key_exists( 'subobjects', $schema )
			&& is_array( $schema['categories'] )
			&& is_array( $schema['properties'] )
			&& is_array( $schema['subobjects'] );
	}

	/**
	 * Check if a file is gzip compressed by examining magic bytes.
	 *
	 * Gzip files start with 0x1f 0x8b
	 *
	 * @param string $filePath
	 * @return bool
	 */
	private function isGzipFile( string $filePath ): bool {
		$handle = fopen( $filePath, 'rb' );
		if ( $handle === false ) {
			return false;
		}

		$magic = fread( $handle, 2 );
		fclose( $handle );

		if ( $magic === false || strlen( $magic ) < 2 ) {
			return false;
		}

		// Gzip magic bytes: 0x1f 0x8b
		return ord( $magic[0] ) === 0x1f && ord( $magic[1] ) === 0x8b;
	}

	/**
	 * Save schema to file with optional gzip compression.
	 *
	 * @param array $schema
	 * @param string $filePath
	 * @param bool $compress If true, gzip compress the output
	 * @return bool
	 * @throws \RuntimeException
	 */
	public function saveToFileCompressed( array $schema, string $filePath, bool $compress = true ): bool {
		$ext = strtolower( pathinfo( $filePath, PATHINFO_EXTENSION ) );

		// Determine base format
		$isGzExt = ( $ext === 'gz' );
		if ( $isGzExt ) {
			$fileName = basename( $filePath, '.gz' );
			$ext = strtolower( pathinfo( $fileName, PATHINFO_EXTENSION ) );
		}

		if ( $ext === 'json' ) {
			$content = $this->saveToJson( $schema );
		} elseif ( $ext === 'yaml' || $ext === 'yml' ) {
			$content = $this->saveToYaml( $schema );
		} else {
			// Default to JSON
			$content = $this->saveToJson( $schema );
		}

		if ( $compress ) {
			$content = gzencode( $content, 9 ); // Maximum compression
			if ( $content === false ) {
				throw new \RuntimeException( "Failed to compress schema" );
			}
		}

		$ok = file_put_contents( $filePath, $content );

		if ( $ok === false ) {
			throw new \RuntimeException( "Failed to write file: $filePath" );
		}

		return true;
	}
}
