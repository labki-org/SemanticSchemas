<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Integration\Store;

use MediaWiki\Extension\SemanticSchemas\Schema\PropertyModel;
use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore
 * @group Database
 */
class WikiPropertyStoreTest extends MediaWikiIntegrationTestCase {

	private WikiPropertyStore $propertyStore;
	private PageCreator $pageCreator;

	protected function setUp(): void {
		parent::setUp();

		$services = $this->getServiceContainer();
		$this->pageCreator = new PageCreator(
			$services->getWikiPageFactory(),
		);
		$this->propertyStore = new WikiPropertyStore(
			$services->getConnectionProvider(),
		);
	}

	/* =========================================================================
	 * READ PROPERTY
	 * ========================================================================= */

	public function testReadPropertyReturnsNullForNonExistent(): void {
		$result = $this->propertyStore->readProperty( 'Has nonexistent prop ' . uniqid() );

		$this->assertNull( $result );
	}

	public function testReadPropertyReturnsPropertyModel(): void {
		$name = 'Has readable prop ' . uniqid();
		$title = Title::makeTitle( SMW_NS_PROPERTY, $name );
		$this->pageCreator->createOrUpdatePage( $title,
			'[[Has type::Text]] [[Has description::Readable property]]',
			''
		);

		// SMW needs to process the page - run jobs if needed
		$this->executeJobs();

		$result = $this->propertyStore->readProperty( $name );

		$this->assertInstanceOf( PropertyModel::class, $result );
		$this->assertEquals( $name, $result->getName() );
	}

	/**
	 * Helper to run any pending MediaWiki jobs.
	 */
	private function executeJobs(): void {
		$runner = $this->getServiceContainer()->getJobRunner();
		$runner->run( [
			'type' => false,
			'maxJobs' => 100,
			'maxTime' => 30,
		] );
	}
}
