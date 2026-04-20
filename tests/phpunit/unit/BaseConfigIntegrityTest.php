<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Validates the internal consistency of the shipped base-config.
 *
 * These tests ensure that the vocab manifest, property definitions,
 * meta-category definitions, and display templates stay in sync.
 *
 * @covers \MediaWiki\Extension\SemanticSchemas\Hooks\SemanticSchemasSetupHooks
 */
class BaseConfigIntegrityTest extends TestCase {

	private const BASE_CONFIG_DIR = __DIR__ . '/../../../resources/base-config';

	/* =========================================================================
	 * VOCAB MANIFEST
	 * ========================================================================= */

	public function testVocabManifestIsValidJson(): void {
		$path = self::BASE_CONFIG_DIR . '/semanticschemas.vocab.json';
		$this->assertFileExists( $path );

		$data = json_decode( file_get_contents( $path ), true );
		$this->assertIsArray( $data, 'vocab.json must decode to an array' );
		$this->assertArrayHasKey( 'import', $data );
		$this->assertNotEmpty( $data['import'] );
	}

	public function testVocabManifestImportFilesExist(): void {
		$manifest = $this->loadVocabManifest();

		foreach ( $manifest['import'] as $i => $entry ) {
			$this->assertArrayHasKey( 'contents', $entry, "Import entry $i missing 'contents'" );
			$this->assertArrayHasKey( 'importFrom', $entry['contents'], "Import entry $i missing 'importFrom'" );

			$filePath = self::BASE_CONFIG_DIR . '/' . $entry['contents']['importFrom'];
			$this->assertFileExists(
				$filePath,
				"Import entry '{$entry['page']}' references missing file: {$entry['contents']['importFrom']}"
			);
		}
	}

	public function testVocabManifestHasNoDuplicateImports(): void {
		$manifest = $this->loadVocabManifest();

		$seen = [];
		foreach ( $manifest['import'] as $entry ) {
			$key = ( $entry['namespace'] ?? '' ) . '::' . $entry['page'];
			$this->assertArrayNotHasKey(
				$key,
				$seen,
				"Duplicate import: {$entry['page']} in namespace {$entry['namespace']}"
			);
			$seen[$key] = true;
		}
	}

	/* =========================================================================
	 * PROPERTY WIKITEXT FILES
	 * ========================================================================= */

	/**
	 * @dataProvider providePropertyFiles
	 */
	public function testPropertyFileHasType( string $filename, string $content ): void {
		$this->assertMatchesRegularExpression(
			'/\[\[Has type::[^\]]+\]\]/',
			$content,
			"Property file $filename missing [[Has type::...]]"
		);
	}

	/**
	 * @dataProvider providePropertyFiles
	 */
	public function testPropertyFileHasDescription( string $filename, string $content ): void {
		$this->assertMatchesRegularExpression(
			'/\[\[Has description::[^\]]+\]\]/',
			$content,
			"Property file $filename missing [[Has description::...]]"
		);
	}

	public static function providePropertyFiles(): array {
		$dir = self::BASE_CONFIG_DIR . '/properties';
		$cases = [];
		foreach ( glob( "$dir/*.wikitext" ) as $path ) {
			$filename = basename( $path );
			$cases[$filename] = [ $filename, file_get_contents( $path ) ];
		}
		return $cases;
	}

	/* =========================================================================
	 * META-CATEGORY WIKITEXT FILES
	 * ========================================================================= */

	/**
	 * Every property referenced by a meta-category must have a corresponding
	 * wikitext file in the properties/ directory.
	 *
	 * @dataProvider provideMetaCategoryPropertyReferences
	 */
	public function testMetaCategoryReferencesExistingPropertyFile(
		string $categoryFile,
		string $propertyName
	): void {
		$filename = str_replace( ' ', '_', $propertyName ) . '.wikitext';
		$path = self::BASE_CONFIG_DIR . '/properties/' . $filename;
		$this->assertFileExists(
			$path,
			"Category $categoryFile references property '$propertyName' but $filename does not exist"
		);
	}

	public static function provideMetaCategoryPropertyReferences(): array {
		$dir = self::BASE_CONFIG_DIR . '/categories';
		$cases = [];
		foreach ( glob( "$dir/*.wikitext" ) as $path ) {
			$filename = basename( $path );
			$content = file_get_contents( $path );

			$pattern = '/\[\[Has (?:required|optional) property::Property:([^\]]+)\]\]/';
			if ( preg_match_all( $pattern, $content, $matches ) ) {
				foreach ( $matches[1] as $propName ) {
					$cases["$filename -> $propName"] = [ $filename, $propName ];
				}
			}
		}
		return $cases;
	}

	/* =========================================================================
	 * TEMPLATE WIKITEXT FILES
	 * ========================================================================= */

	/**
	 * @dataProvider providePropertyTemplateFiles
	 */
	public function testPropertyTemplateAcceptsValueParameter( string $filename, string $content ): void {
		$this->assertStringContainsString(
			'{{{value',
			$content,
			"Property template $filename must accept a {{{value}}} parameter"
		);
	}

	public static function providePropertyTemplateFiles(): array {
		$dir = self::BASE_CONFIG_DIR . '/templates/Property';
		$cases = [];
		foreach ( glob( "$dir/*.wikitext" ) as $path ) {
			$filename = basename( $path );
			// Convention: PascalCase filenames are render templates (SMW format=template
			// entries that receive {{{value}}}); lowercase filenames are primitives
			// (receive {{{prop}}} etc. — see testPropertyPrimitiveAcceptsPropParameter).
			if ( !ctype_upper( $filename[0] ) ) {
				continue;
			}
			$cases[$filename] = [ $filename, file_get_contents( $path ) ];
		}
		return $cases;
	}

	/**
	 * @dataProvider providePropertyPrimitiveFiles
	 */
	public function testPropertyPrimitiveAcceptsPropParameter(
		string $filename,
		string $content
	): void {
		$this->assertStringContainsString(
			'{{{prop',
			$content,
			"Property primitive $filename must accept a {{{prop}}} parameter"
		);
	}

	public static function providePropertyPrimitiveFiles(): array {
		$dir = self::BASE_CONFIG_DIR . '/templates/Property';
		$cases = [];
		foreach ( glob( "$dir/*.wikitext" ) as $path ) {
			$filename = basename( $path );
			if ( ctype_upper( $filename[0] ) ) {
				continue;
			}
			$cases[$filename] = [ $filename, file_get_contents( $path ) ];
		}
		return $cases;
	}

	/**
	 * @dataProvider provideCategoryDisplayTemplateFiles
	 */
	public function testCategoryDisplayTemplateAcceptsCategoryParameter(
		string $filename,
		string $content
	): void {
		$this->assertStringContainsString(
			'{{{category',
			$content,
			"Category display template $filename must accept a {{{category}}} parameter"
		);
	}

	public static function provideCategoryDisplayTemplateFiles(): array {
		$dir = self::BASE_CONFIG_DIR . '/templates/Category';
		$cases = [];
		foreach ( glob( "$dir/*.wikitext" ) as $path ) {
			$filename = basename( $path );
			$content = file_get_contents( $path );
			// Only display format templates (table, sidebox) — not utility templates
			if ( str_contains( $content, '{{{category' ) ) {
				$cases[$filename] = [ $filename, $content ];
			}
		}
		return $cases;
	}

	/**
	 * @dataProvider provideAllTemplateFiles
	 */
	public function testTemplateFileHasIncludeOnlyTags( string $filename, string $content ): void {
		$this->assertStringContainsString(
			'<includeonly>',
			$content,
			"Template $filename missing <includeonly> tags"
		);
	}

	public static function provideAllTemplateFiles(): array {
		$dir = self::BASE_CONFIG_DIR . '/templates';
		$cases = [];
		foreach ( glob( "$dir/{Property,Category}/*.wikitext", GLOB_BRACE ) as $path ) {
			$relative = str_replace( "$dir/", '', $path );
			$cases[$relative] = [ $relative, file_get_contents( $path ) ];
		}
		return $cases;
	}

	/* =========================================================================
	 * HELPERS
	 * ========================================================================= */

	private function loadVocabManifest(): array {
		$path = self::BASE_CONFIG_DIR . '/semanticschemas.vocab.json';
		return json_decode( file_get_contents( $path ), true );
	}
}
