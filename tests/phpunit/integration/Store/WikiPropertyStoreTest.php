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
	 * in the store, not whatever SMW's `PropertySpecificationLookup` happens
	 * to have cached. Background: `DIProperty::findPropertyTypeID()` resolves
	 * a user-defined property's type via `PropertySpecificationLookup->
	 * getSpecification( $prop, _TYPE )`, which is backed by SMW's EntityCache
	 * with a TTL_WEEK entry per subject. Field reports: after referencing
	 * Property:X in a form before X existed and then creating X with
	 * `Has type::Date`, form regeneration kept emitting a combobox (default
	 * `_wpg`) until `?action=purge` on Property:X — the subject's `_TYPE`
	 * entry had gone stale and nothing in the save path had cleared it.
	 *
	 * This test simulates that stale state by writing an empty `_TYPE`
	 * specification directly into the cache for the subject after the
	 * property was created with a real type, then asserts that
	 * `readProperty()` still returns the store's real datatype.
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
		// an empty array, mirroring the stale state observed in the field
		// (where ChangePropagationDispatchJob/_TYPE listener never fired).
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
