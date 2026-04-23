<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Integration\Store;

use MediaWiki\Extension\SemanticSchemas\Schema\PropertyModel;
use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;
use MediaWiki\Extension\SemanticSchemas\Tests\SMWIntegrationTestCase;
use MediaWiki\Title\Title;
use SMW\DIWikiPage;
use SMW\EntityCache;
use SMW\Property\SpecificationLookup;
use SMW\Services\ServicesFactory;

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore
 * @group Database
 */
class WikiPropertyStoreTest extends SMWIntegrationTestCase {

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

		$this->runSMWUpdates();

		$result = $this->propertyStore->readProperty( $name );

		$this->assertInstanceOf( PropertyModel::class, $result );
		$this->assertEquals( $name, $result->getName() );
	}

	/**
	 * Regression: `readProperty()` must return the datatype that is currently
	 * in the store, not whatever SMW's `PropertySpecificationLookup` has
	 * cached in-memory.
	 *
	 * Mechanism from field diagnosis: `SpecificationLookup::getSpecification`
	 * writes every result — including empty ones — into SMW's EntityCache,
	 * which is an `Onoi\CompositeCache` whose first layer is an in-process
	 * PHP array held by the service container. If `findPropertyTypeID` fires
	 * while a property-page save is still in flight (the `_TYPE` write hasn't
	 * committed yet), the lookup caches `[]` for that subject. The entry has
	 * no effective TTL: it lives for the lifetime of the PHP process.
	 *
	 * On the reporter's VPS (Apache mod_php prefork, `MaxConnectionsPerChild
	 * 0` → immortal workers), the poisoned `[]` sits in an Apache worker's
	 * in-memory layer indefinitely. Subsequent `readProperty` calls on that
	 * worker fall back to `smwgPDefaultType = _wpg`, so the form generator
	 * emits a combobox for what should be a date/text/URL field. Graceful
	 * Apache reload or `?action=purge` on the property page flushes the
	 * layer and unsticks the wrong type. Local dev Docker reproduces neither
	 * symptom because workers recycle between actions.
	 *
	 * The test mimics the end-state directly — write `[]` into the `_TYPE`
	 * sub-key for a subject whose store actually has Date — so it fails the
	 * same way regardless of how the poisoning happened upstream.
	 */
	public function testReadPropertyIgnoresStaleSpecificationLookupCache(): void {
		$name = 'Has cache poisoned type ' . uniqid();
		$title = Title::makeTitleSafe( SMW_NS_PROPERTY, $name );

		$this->pageCreator->createOrUpdatePage( $title, '[[Has type::Date]]', '' );
		$this->runSMWUpdates();

		// Sanity: the store and cache agree on Date right after creation.
		$fresh = $this->propertyStore->readProperty( $name );
		$this->assertNotNull( $fresh );
		$this->assertSame( 'Date', $fresh->getDatatype() );

		// Poison SpecificationLookup's `_TYPE` sub-key for this subject with
		// an empty array, as a race with an in-flight save would produce.
		// `DIProperty::findPropertyValueType()` treats an empty array as
		// "no type" and falls back to `smwgPDefaultType` (`_wpg` → `Page`).
		$entityCache = ServicesFactory::getInstance()->getEntityCache();
		$subject = DIWikiPage::newFromTitle( $title );
		$cacheKey = EntityCache::makeCacheKey(
			SpecificationLookup::CACHE_NS_KEY_SPECIFICATIONLOOKUP,
			$subject
		);
		$entityCache->saveSub( $cacheKey, '_TYPE', [], EntityCache::TTL_WEEK );
		$entityCache->associate( $subject, $cacheKey );

		$result = $this->propertyStore->readProperty( $name );
		$this->assertNotNull( $result );
		$this->assertSame(
			'Date',
			$result->getDatatype(),
			'readProperty() must reflect the store, not a stale '
			. 'SpecificationLookup cache entry.'
		);
	}
}
