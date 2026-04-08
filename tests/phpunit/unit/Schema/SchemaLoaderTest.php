<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit\Schema;

use MediaWiki\Extension\SemanticSchemas\Schema\SchemaLoader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Schema\SchemaLoader
 */
class SchemaLoaderTest extends TestCase {

	private SchemaLoader $loader;
	private string $tempDir;

	protected function setUp(): void {
		parent::setUp();
		$this->loader = new SchemaLoader();
		$this->tempDir = sys_get_temp_dir() . '/schemaloader_test_' . uniqid();
		mkdir( $this->tempDir, 0755, true );
	}

	protected function tearDown(): void {
		// Clean up temp directory
		if ( is_dir( $this->tempDir ) ) {
			$files = glob( $this->tempDir . '/*' );
			foreach ( $files as $file ) {
				unlink( $file );
			}
			rmdir( $this->tempDir );
		}
		parent::tearDown();
	}

	/* =========================================================================
	 * JSON LOADING
	 * ========================================================================= */

	public function testLoadFromJsonWithValidJson(): void {
		$json = '{"schemaVersion": "1.0", "categories": {}, "properties": {}}';
		$result = $this->loader->loadFromJson( $json );

		$this->assertIsArray( $result );
		$this->assertSame( '1.0', $result['schemaVersion'] );
		$this->assertArrayHasKey( 'categories', $result );
		$this->assertArrayHasKey( 'properties', $result );
	}

	public function testLoadFromJsonWithEmptyStringThrows(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Empty JSON' );

		$this->loader->loadFromJson( '' );
	}

	public function testLoadFromJsonWithWhitespaceOnlyThrows(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Empty JSON' );

		$this->loader->loadFromJson( '   ' );
	}

	public function testLoadFromJsonWithInvalidJsonThrows(): void {
		$this->expectException( \JsonException::class );

		$this->loader->loadFromJson( '{invalid json' );
	}

	public function testLoadFromJsonWithNonArrayStructureThrows(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'non-array' );

		$this->loader->loadFromJson( '"just a string"' );
	}

	public function testLoadFromJsonWithNestedStructure(): void {
		$json = json_encode( [
			'schemaVersion' => '1.0',
			'categories' => [
				'Person' => [
					'label' => 'Person',
					'properties' => [ 'required' => [ 'Has name' ] ],
				],
			],
			'properties' => [
				'Has name' => [ 'datatype' => 'Text' ],
			],
		] );

		$result = $this->loader->loadFromJson( $json );

		$this->assertArrayHasKey( 'Person', $result['categories'] );
		$this->assertEquals( 'Person', $result['categories']['Person']['label'] );
	}

	/* =========================================================================
	 * YAML LOADING
	 * ========================================================================= */

	public function testLoadFromYamlWithValidYaml(): void {
		$yaml = <<<YAML
schemaVersion: "1.0"
categories: {}
properties: {}
YAML;

		$result = $this->loader->loadFromYaml( $yaml );

		$this->assertIsArray( $result );
		$this->assertSame( '1.0', $result['schemaVersion'] );
	}

	public function testLoadFromYamlWithEmptyStringThrows(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Empty YAML' );

		$this->loader->loadFromYaml( '' );
	}

	public function testLoadFromYamlWithNestedStructure(): void {
		$yaml = <<<YAML
schemaVersion: "1.0"
categories:
  Person:
    label: Person
    properties:
      required:
        - Has name
properties:
  Has name:
    datatype: Text
YAML;

		$result = $this->loader->loadFromYaml( $yaml );

		$this->assertArrayHasKey( 'Person', $result['categories'] );
		$this->assertEquals( [ 'Has name' ], $result['categories']['Person']['properties']['required'] );
	}

	public function testLoadFromYamlWithNonArrayStructureThrows(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'non-array' );

		$this->loader->loadFromYaml( 'just a string' );
	}

	/* =========================================================================
	 * FORMAT DETECTION
	 * ========================================================================= */

	public function testDetectFormatReturnsJsonForObjectStart(): void {
		$result = $this->loader->detectFormat( '{"key": "value"}' );
		$this->assertEquals( 'json', $result );
	}

	public function testDetectFormatReturnsJsonForArrayStart(): void {
		$result = $this->loader->detectFormat( '[1, 2, 3]' );
		$this->assertEquals( 'json', $result );
	}

	public function testDetectFormatReturnsYamlForKeyValue(): void {
		$result = $this->loader->detectFormat( 'key: value' );
		$this->assertEquals( 'yaml', $result );
	}

	public function testDetectFormatReturnsYamlForEmpty(): void {
		$result = $this->loader->detectFormat( '' );
		$this->assertEquals( 'yaml', $result );
	}

	public function testDetectFormatIgnoresLeadingWhitespace(): void {
		$result = $this->loader->detectFormat( '  {"key": "value"}' );
		$this->assertEquals( 'json', $result );
	}

	/* =========================================================================
	 * CONTENT LOADING (AUTO-DETECT)
	 * ========================================================================= */

	public function testLoadFromContentAutoDetectsJson(): void {
		$content = '{"schemaVersion": "1.0", "categories": {}, "properties": {}}';
		$result = $this->loader->loadFromContent( $content );

		$this->assertSame( '1.0', $result['schemaVersion'] );
	}

	public function testLoadFromContentAutoDetectsYaml(): void {
		$content = <<<YAML
schemaVersion: "1.0"
categories: {}
properties: {}
YAML;
		$result = $this->loader->loadFromContent( $content );

		$this->assertSame( '1.0', $result['schemaVersion'] );
	}

	public function testLoadFromContentWithEmptyContentThrows(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'empty' );

		$this->loader->loadFromContent( '' );
	}

	/* =========================================================================
	 * JSON OUTPUT
	 * ========================================================================= */

	public function testSaveToJsonReturnsValidJson(): void {
		$schema = [
			'schemaVersion' => '1.0',
			'categories' => [],
			'properties' => [],
		];

		$result = $this->loader->saveToJson( $schema );

		$this->assertJson( $result );
		$decoded = json_decode( $result, true );
		$this->assertSame( '1.0', $decoded['schemaVersion'] );
	}

	public function testSaveToJsonIsPrettyPrinted(): void {
		$schema = [ 'key' => 'value' ];
		$result = $this->loader->saveToJson( $schema );

		// Pretty printed JSON contains newlines
		$this->assertStringContainsString( "\n", $result );
	}

	public function testSaveToJsonUnescapesSlashes(): void {
		$schema = [ 'url' => 'https://example.com/path' ];
		$result = $this->loader->saveToJson( $schema );

		// Slashes should not be escaped
		$this->assertStringContainsString( 'https://example.com/path', $result );
		$this->assertStringNotContainsString( '\/', $result );
	}

	/* =========================================================================
	 * YAML OUTPUT
	 * ========================================================================= */

	public function testSaveToYamlReturnsValidYaml(): void {
		$schema = [
			'schemaVersion' => '1.0',
			'categories' => [],
			'properties' => [],
		];

		$result = $this->loader->saveToYaml( $schema );

		$this->assertStringContainsString( 'schemaVersion:', $result );
	}

	/* =========================================================================
	 * FILE LOADING
	 * ========================================================================= */

	public function testLoadFromFileWithJsonFile(): void {
		$filePath = $this->tempDir . '/test.json';
		$content = '{"schemaVersion": "1.0", "categories": {}, "properties": {}}';
		file_put_contents( $filePath, $content );

		$result = $this->loader->loadFromFile( $filePath );

		$this->assertSame( '1.0', $result['schemaVersion'] );
	}

	public function testLoadFromFileWithYamlFile(): void {
		$filePath = $this->tempDir . '/test.yaml';
		$content = <<<YAML
schemaVersion: "1.0"
categories: {}
properties: {}
YAML;
		file_put_contents( $filePath, $content );

		$result = $this->loader->loadFromFile( $filePath );

		$this->assertSame( '1.0', $result['schemaVersion'] );
	}

	public function testLoadFromFileWithYmlExtension(): void {
		$filePath = $this->tempDir . '/test.yml';
		$content = "schemaVersion: '1.0'\ncategories: {}\nproperties: {}";
		file_put_contents( $filePath, $content );

		$result = $this->loader->loadFromFile( $filePath );

		$this->assertSame( '1.0', $result['schemaVersion'] );
	}

	public function testLoadFromFileWithNonExistentFileThrows(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'not found' );

		$this->loader->loadFromFile( '/nonexistent/path/file.json' );
	}

	/* =========================================================================
	 * FILE SAVING
	 * ========================================================================= */

	public function testSaveToFileWithJsonExtension(): void {
		$filePath = $this->tempDir . '/output.json';
		$schema = [ 'schemaVersion' => '1.0', 'categories' => [], 'properties' => [] ];

		$result = $this->loader->saveToFile( $schema, $filePath );

		$this->assertTrue( $result );
		$this->assertFileExists( $filePath );
		$content = file_get_contents( $filePath );
		$this->assertJson( $content );
	}

	public function testSaveToFileWithYamlExtension(): void {
		$filePath = $this->tempDir . '/output.yaml';
		$schema = [ 'schemaVersion' => '1.0', 'categories' => [], 'properties' => [] ];

		$result = $this->loader->saveToFile( $schema, $filePath );

		$this->assertTrue( $result );
		$this->assertFileExists( $filePath );
		$content = file_get_contents( $filePath );
		$this->assertStringContainsString( 'schemaVersion:', $content );
	}

	public function testSaveToFileWithYmlExtension(): void {
		$filePath = $this->tempDir . '/output.yml';
		$schema = [ 'schemaVersion' => '1.0' ];

		$result = $this->loader->saveToFile( $schema, $filePath );

		$this->assertTrue( $result );
		$content = file_get_contents( $filePath );
		$this->assertStringContainsString( 'schemaVersion:', $content );
	}

	public function testSaveToFileDefaultsToJson(): void {
		$filePath = $this->tempDir . '/output.txt';
		$schema = [ 'key' => 'value' ];

		$result = $this->loader->saveToFile( $schema, $filePath );

		$this->assertTrue( $result );
		$content = file_get_contents( $filePath );
		$this->assertJson( $content );
	}

	/* =========================================================================
	 * GZIP SUPPORT
	 * ========================================================================= */

	public function testSaveToFileCompressedCreatesGzipFile(): void {
		$filePath = $this->tempDir . '/output.json.gz';
		$schema = [ 'schemaVersion' => '1.0', 'categories' => [], 'properties' => [] ];

		$result = $this->loader->saveToFileCompressed( $schema, $filePath, true );

		$this->assertTrue( $result );
		$this->assertFileExists( $filePath );

		// Check gzip magic bytes
		$content = file_get_contents( $filePath );
		$this->assertSame( 0x1f, ord( $content[0] ) );
		$this->assertSame( 0x8b, ord( $content[1] ) );
	}

	public function testLoadFromFileWithGzipFile(): void {
		$filePath = $this->tempDir . '/test.json.gz';
		$schema = [ 'schemaVersion' => '1.0', 'categories' => [], 'properties' => [] ];
		$json = json_encode( $schema );
		$compressed = gzencode( $json );
		file_put_contents( $filePath, $compressed );

		$result = $this->loader->loadFromFile( $filePath );

		$this->assertSame( '1.0', $result['schemaVersion'] );
	}

	/* =========================================================================
	 * EMPTY SCHEMA
	 * ========================================================================= */

	public function testCreateEmptySchemaReturnsValidStructure(): void {
		$result = $this->loader->createEmptySchema();

		$this->assertArrayHasKey( 'schemaVersion', $result );
		$this->assertArrayHasKey( 'categories', $result );
		$this->assertArrayHasKey( 'properties', $result );
		$this->assertSame( '1.0', $result['schemaVersion'] );
		$this->assertIsArray( $result['categories'] );
		$this->assertIsArray( $result['properties'] );
	}

	public function testCreateEmptySchemaIsEmpty(): void {
		$result = $this->loader->createEmptySchema();

		$this->assertEmpty( $result['categories'] );
		$this->assertEmpty( $result['properties'] );
	}

	/* =========================================================================
	 * STRUCTURE VALIDATION
	 * ========================================================================= */

	public function testHasValidStructureReturnsTrueForValidSchema(): void {
		$schema = [
			'schemaVersion' => '1.0',
			'categories' => [],
			'properties' => [],
		];

		$result = $this->loader->hasValidStructure( $schema );

		$this->assertTrue( $result );
	}

	public function testHasValidStructureReturnsFalseForMissingSchemaVersion(): void {
		$schema = [
			'categories' => [],
			'properties' => [],
		];

		$result = $this->loader->hasValidStructure( $schema );

		$this->assertFalse( $result );
	}

	public function testHasValidStructureReturnsFalseForMissingCategories(): void {
		$schema = [
			'schemaVersion' => '1.0',
			'properties' => [],
		];

		$result = $this->loader->hasValidStructure( $schema );

		$this->assertFalse( $result );
	}

	public function testHasValidStructureReturnsFalseForMissingProperties(): void {
		$schema = [
			'schemaVersion' => '1.0',
			'categories' => [],
		];

		$result = $this->loader->hasValidStructure( $schema );

		$this->assertFalse( $result );
	}

	public function testHasValidStructureReturnsFalseForNonArrayCategories(): void {
		$schema = [
			'schemaVersion' => '1.0',
			'categories' => 'not an array',
			'properties' => [],
		];

		$result = $this->loader->hasValidStructure( $schema );

		$this->assertFalse( $result );
	}
}
