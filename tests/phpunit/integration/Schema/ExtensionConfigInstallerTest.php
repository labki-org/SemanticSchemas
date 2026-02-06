<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Integration\Schema;

use MediaWiki\Extension\SemanticSchemas\Schema\ExtensionConfigInstaller;
use MediaWiki\Extension\SemanticSchemas\Schema\SchemaLoader;
use MediaWiki\Extension\SemanticSchemas\Schema\SchemaValidator;
use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;
use MediaWiki\Extension\SemanticSchemas\Store\WikiSubobjectStore;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Schema\ExtensionConfigInstaller
 * @group Database
 */
class ExtensionConfigInstallerTest extends MediaWikiIntegrationTestCase {

	private ExtensionConfigInstaller $installer;
	private PageCreator $pageCreator;
	private string $tempDir;

	protected function setUp(): void {
		parent::setUp();

		$this->pageCreator = new PageCreator( null );
		$loader = new SchemaLoader();
		$validator = new SchemaValidator();
		$categoryStore = new WikiCategoryStore( $this->pageCreator );
		$propertyStore = new WikiPropertyStore( $this->pageCreator );
		$subobjectStore = new WikiSubobjectStore( $this->pageCreator );

		$this->installer = new ExtensionConfigInstaller(
			$loader,
			$validator,
			$categoryStore,
			$propertyStore,
			$subobjectStore,
			$this->pageCreator
		);

		// Create temp directory for test schema files
		$this->tempDir = sys_get_temp_dir() . '/config_installer_test_' . uniqid();
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
	 * APPLY SCHEMA
	 * ========================================================================= */

	public function testApplySchemaCreatesProperties(): void {
		$propName = 'Has test prop ' . uniqid();
		$schema = $this->createValidSchema( [
			'properties' => [
				$propName => [
					'datatype' => 'Text',
					'description' => 'A test property',
				],
			],
		] );

		$result = $this->installer->applySchema( $schema );

		$this->assertEmpty( $result['errors'], 'No errors expected' );
		$this->assertContains( $propName, $result['created']['properties'] );

		// Verify property page exists
		$title = $this->pageCreator->makeTitle( $propName, SMW_NS_PROPERTY );
		$this->assertTrue( $title->exists() );
	}

	public function testApplySchemaCreatesCategories(): void {
		$catName = 'TestCategory ' . uniqid();
		$propName = 'Has cat prop ' . uniqid();
		$schema = $this->createValidSchema( [
			'categories' => [
				$catName => [
					'description' => 'A test category',
					'properties' => [
						'required' => [ $propName ],
						'optional' => [],
					],
				],
			],
			'properties' => [
				$propName => [ 'datatype' => 'Text' ],
			],
		] );

		$result = $this->installer->applySchema( $schema );

		$this->assertEmpty( $result['errors'], 'No errors expected' );
		$this->assertContains( $catName, $result['created']['categories'] );

		// Verify category page exists
		$title = $this->pageCreator->makeTitle( $catName, NS_CATEGORY );
		$this->assertTrue( $title->exists() );
	}

	public function testApplySchemaWithInvalidSchemaReturnsErrors(): void {
		$schema = [
			// Missing required schemaVersion
			'categories' => [],
			'properties' => [],
		];

		$result = $this->installer->applySchema( $schema );

		$this->assertNotEmpty( $result['errors'] );
		// Nothing should be created when there are errors
		$this->assertEmpty( $result['created']['properties'] );
		$this->assertEmpty( $result['created']['categories'] );
	}

	public function testApplySchemaUpdatesExistingProperty(): void {
		$propName = 'Has existing prop ' . uniqid();

		// First install
		$schema1 = $this->createValidSchema( [
			'properties' => [
				$propName => [
					'datatype' => 'Text',
					'description' => 'Initial description',
				],
			],
		] );
		$this->installer->applySchema( $schema1 );

		// Second install with updated description
		$schema2 = $this->createValidSchema( [
			'properties' => [
				$propName => [
					'datatype' => 'Text',
					'description' => 'Updated description',
				],
			],
		] );
		$result = $this->installer->applySchema( $schema2 );

		$this->assertEmpty( $result['errors'] );
		$this->assertContains( $propName, $result['updated']['properties'] );
		$this->assertNotContains( $propName, $result['created']['properties'] );
	}

	public function testApplySchemaUpdatesExistingCategory(): void {
		$catName = 'ExistingCat ' . uniqid();
		$propName = 'Has existing cat prop ' . uniqid();

		// First install
		$schema1 = $this->createValidSchema( [
			'categories' => [
				$catName => [
					'description' => 'Initial description',
					'properties' => [ 'required' => [ $propName ], 'optional' => [] ],
				],
			],
			'properties' => [
				$propName => [ 'datatype' => 'Text' ],
			],
		] );
		$this->installer->applySchema( $schema1 );

		// Second install
		$schema2 = $this->createValidSchema( [
			'categories' => [
				$catName => [
					'description' => 'Updated description',
					'properties' => [ 'required' => [ $propName ], 'optional' => [] ],
				],
			],
			'properties' => [
				$propName => [ 'datatype' => 'Text' ],
			],
		] );
		$result = $this->installer->applySchema( $schema2 );

		$this->assertEmpty( $result['errors'] );
		$this->assertContains( $catName, $result['updated']['categories'] );
	}

	public function testApplySchemaReturnsWarningsForNonBlockingIssues(): void {
		$propName = 'UnusedProperty ' . uniqid();
		$schema = $this->createValidSchema( [
			'properties' => [
				$propName => [ 'datatype' => 'Text' ],
			],
			// Property is not used by any category - should generate warning
		] );

		$result = $this->installer->applySchema( $schema );

		// Schema is valid but may have warnings
		$this->assertEmpty( $result['errors'] );
		// Property should still be created
		$this->assertContains( $propName, $result['created']['properties'] );
	}

	/* =========================================================================
	 * APPLY FROM FILE
	 * ========================================================================= */

	public function testApplyFromFileWithJsonFile(): void {
		$propName = 'Has file prop ' . uniqid();
		$filePath = $this->tempDir . '/schema.json';
		$schema = $this->createValidSchema( [
			'properties' => [
				$propName => [ 'datatype' => 'Text' ],
			],
		] );
		file_put_contents( $filePath, json_encode( $schema ) );

		$result = $this->installer->applyFromFile( $filePath );

		$this->assertEmpty( $result['errors'] );
		$this->assertContains( $propName, $result['created']['properties'] );
	}

	public function testApplyFromFileWithYamlFile(): void {
		$propName = 'Has yaml prop ' . uniqid();
		$filePath = $this->tempDir . '/schema.yaml';
		$yaml = <<<YAML
schemaVersion: "1.0"
categories: {}
properties:
  $propName:
    datatype: Text
subobjects: {}
YAML;
		file_put_contents( $filePath, $yaml );

		$result = $this->installer->applyFromFile( $filePath );

		$this->assertEmpty( $result['errors'] );
		$this->assertContains( $propName, $result['created']['properties'] );
	}

	/* =========================================================================
	 * PREVIEW INSTALLATION
	 * ========================================================================= */

	public function testPreviewInstallationShowsWhatWouldBeCreated(): void {
		$propName = 'Has preview prop ' . uniqid();
		$catName = 'PreviewCat ' . uniqid();
		$filePath = $this->tempDir . '/preview.json';
		$schema = $this->createValidSchema( [
			'categories' => [
				$catName => [
					'properties' => [ 'required' => [ $propName ], 'optional' => [] ],
				],
			],
			'properties' => [
				$propName => [ 'datatype' => 'Text' ],
			],
		] );
		file_put_contents( $filePath, json_encode( $schema ) );

		$result = $this->installer->previewInstallation( $filePath );

		$this->assertEmpty( $result['errors'] );
		$this->assertContains( $propName, $result['would_create']['properties'] );
		$this->assertContains( $catName, $result['would_create']['categories'] );

		// Verify nothing was actually created
		$propTitle = $this->pageCreator->makeTitle( $propName, SMW_NS_PROPERTY );
		$catTitle = $this->pageCreator->makeTitle( $catName, NS_CATEGORY );
		$this->assertFalse( $propTitle->exists() );
		$this->assertFalse( $catTitle->exists() );
	}

	public function testPreviewInstallationShowsWhatWouldBeUpdated(): void {
		$propName = 'Has preview update prop ' . uniqid();

		// First create the property
		$schema1 = $this->createValidSchema( [
			'properties' => [
				$propName => [ 'datatype' => 'Text' ],
			],
		] );
		$this->installer->applySchema( $schema1 );

		// Now preview an update
		$filePath = $this->tempDir . '/preview-update.json';
		$schema2 = $this->createValidSchema( [
			'properties' => [
				$propName => [
					'datatype' => 'Text',
					'description' => 'Updated',
				],
			],
		] );
		file_put_contents( $filePath, json_encode( $schema2 ) );

		$result = $this->installer->previewInstallation( $filePath );

		$this->assertEmpty( $result['errors'] );
		$this->assertContains( $propName, $result['would_update']['properties'] );
		$this->assertNotContains( $propName, $result['would_create']['properties'] );
	}

	public function testPreviewInstallationWithInvalidSchemaReturnsErrors(): void {
		$filePath = $this->tempDir . '/invalid.json';
		$schema = [
			// Missing schemaVersion
			'categories' => [],
			'properties' => [],
		];
		file_put_contents( $filePath, json_encode( $schema ) );

		$result = $this->installer->previewInstallation( $filePath );

		$this->assertNotEmpty( $result['errors'] );
		$this->assertEmpty( $result['would_create']['properties'] );
		$this->assertEmpty( $result['would_create']['categories'] );
	}

	/* =========================================================================
	 * COMPLEX SCENARIOS
	 * ========================================================================= */

	public function testApplySchemaWithMultiplePropertiesAndCategories(): void {
		$prop1 = 'Has multi prop 1 ' . uniqid();
		$prop2 = 'Has multi prop 2 ' . uniqid();
		$cat1 = 'MultiCat1 ' . uniqid();
		$cat2 = 'MultiCat2 ' . uniqid();

		$schema = $this->createValidSchema( [
			'categories' => [
				$cat1 => [
					'properties' => [ 'required' => [ $prop1 ], 'optional' => [] ],
				],
				$cat2 => [
					'properties' => [ 'required' => [ $prop2 ], 'optional' => [ $prop1 ] ],
				],
			],
			'properties' => [
				$prop1 => [ 'datatype' => 'Text' ],
				$prop2 => [ 'datatype' => 'Number' ],
			],
		] );

		$result = $this->installer->applySchema( $schema );

		$this->assertEmpty( $result['errors'] );
		$this->assertContains( $prop1, $result['created']['properties'] );
		$this->assertContains( $prop2, $result['created']['properties'] );
		$this->assertContains( $cat1, $result['created']['categories'] );
		$this->assertContains( $cat2, $result['created']['categories'] );
	}

	public function testApplySchemaWithCategoryHierarchy(): void {
		$parent = 'ParentCat ' . uniqid();
		$child = 'ChildCat ' . uniqid();
		$prop = 'Has hierarchy prop ' . uniqid();

		$schema = $this->createValidSchema( [
			'categories' => [
				$parent => [
					'properties' => [ 'required' => [ $prop ], 'optional' => [] ],
				],
				$child => [
					'parents' => [ $parent ],
					'properties' => [ 'required' => [], 'optional' => [] ],
				],
			],
			'properties' => [
				$prop => [ 'datatype' => 'Text' ],
			],
		] );

		$result = $this->installer->applySchema( $schema );

		$this->assertEmpty( $result['errors'] );
		$this->assertContains( $parent, $result['created']['categories'] );
		$this->assertContains( $child, $result['created']['categories'] );

		// Verify parent reference in child
		$childTitle = $this->pageCreator->makeTitle( $child, NS_CATEGORY );
		$content = $this->pageCreator->getPageContent( $childTitle );
		$this->assertStringContainsString( "[[Category:$parent]]", $content );
	}

	/* =========================================================================
	 * HELPER METHODS
	 * ========================================================================= */

	/**
	 * Create a valid schema with optional overrides.
	 */
	private function createValidSchema( array $overrides = [] ): array {
		$defaults = [
			'schemaVersion' => '1.0',
			'categories' => [],
			'properties' => [],
			'subobjects' => [],
		];

		return array_merge( $defaults, $overrides );
	}
}
