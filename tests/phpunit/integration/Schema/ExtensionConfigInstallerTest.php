<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Integration\Schema;

use MediaWiki\Extension\SemanticSchemas\Schema\ExtensionConfigInstaller;
use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Schema\ExtensionConfigInstaller
 * @group Database
 */
class ExtensionConfigInstallerTest extends MediaWikiIntegrationTestCase {

	private ExtensionConfigInstaller $installer;
	private PageCreator $pageCreator;

	protected function setUp(): void {
		parent::setUp();

		$services = $this->getServiceContainer();
		$this->pageCreator = new PageCreator(
			$services->getWikiPageFactory(),
			$services->getDeletePageFactory()
		);

		$this->installer = new ExtensionConfigInstaller( $this->pageCreator );
	}

	public function testIsInstalledReturnsFalseBeforeInstall(): void {
		$this->assertFalse( $this->installer->isInstalled() );
	}

	public function testInstallCreatesPages(): void {
		$result = $this->installer->install();

		$this->assertEmpty( $result['errors'], 'No errors expected' );
		$this->assertNotEmpty( $result['created']['properties'], 'Properties should be created' );
		$this->assertNotEmpty( $result['created']['categories'], 'Categories should be created' );
		$this->assertNotEmpty( $result['created']['templates'], 'Templates should be created' );
	}

	public function testIsInstalledReturnsTrueAfterInstall(): void {
		$this->installer->install();
		$this->assertTrue( $this->installer->isInstalled() );
	}

	public function testInstallTwiceReportsUpdatesNotCreates(): void {
		$this->installer->install();

		// Second install should report updates
		$result = $this->installer->install();

		$this->assertEmpty( $result['errors'] );
		$this->assertEmpty( $result['created']['properties'], 'No new properties on second run' );
		$this->assertEmpty( $result['created']['categories'], 'No new categories on second run' );
		$this->assertNotEmpty( $result['updated']['properties'], 'Properties should be updated' );
		$this->assertNotEmpty( $result['updated']['categories'], 'Categories should be updated' );
	}

	public function testPreviewShowsWhatWouldBeCreated(): void {
		$result = $this->installer->preview();

		$this->assertNotEmpty( $result['would_create']['properties'] );
		$this->assertNotEmpty( $result['would_create']['categories'] );
		$this->assertEmpty( $result['would_update']['properties'] );
		$this->assertEmpty( $result['would_update']['categories'] );
	}

	public function testPreviewAfterInstallShowsUpdates(): void {
		$this->installer->install();

		$result = $this->installer->preview();

		$this->assertEmpty( $result['would_create']['properties'] );
		$this->assertEmpty( $result['would_create']['categories'] );
		$this->assertNotEmpty( $result['would_update']['properties'] );
		$this->assertNotEmpty( $result['would_update']['categories'] );
	}

	public function testPreviewDoesNotCreatePages(): void {
		$this->installer->preview();
		$this->assertFalse( $this->installer->isInstalled() );
	}
}
