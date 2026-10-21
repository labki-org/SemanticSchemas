<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests;

use MediaWiki\Deferred\DeferredUpdates;
use SMW\MediaWiki\Deferred\CallableUpdate;
use SMW\Services\ServicesFactory as SMWServicesFactory;

class SMWIntegrationTestCase extends \MediaWikiIntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();

		// SMW < 7 registers hooks dynamically via HookContainer::register(), but
		// MediaWikiIntegrationTestCase resets the hook container between tests.
		// Re-register so semantic data gets processed on page save. SMW 7
		// declares its hooks in extension.json, which survive the reset.
		if ( class_exists( \SMW\MediaWiki\Hooks::class ) ) {
			$smwHooks = new \SMW\MediaWiki\Hooks();
			$smwHooks->register();
		}

		// Make SMW store updates synchronous (same as SMW's own test suite).
		$GLOBALS['smwgEnabledDeferredUpdate'] = false;
		SMWServicesFactory::getInstance()->getSettings()->set( 'smwgEnabledDeferredUpdate', false );
	}

	/**
	 * Complete SMW data processing after page saves.
	 *
	 * With smwgEnabledDeferredUpdate=false, SMW stores semantic data and MW
	 * populates categorylinks synchronously during saveRevision(). After that,
	 * MW queues deferred updates and jobs (RefreshLinksJob) that re-parse pages
	 * and can trigger LinksUpdateComplete with empty semantic data, overwriting
	 * the correct store. We discard that pending work and clear SMW's in-memory
	 * cache so subsequent reads return fresh data from the database.
	 */
	public function runSMWUpdates(): void {
		CallableUpdate::clearPendingUpdates();
		DeferredUpdates::clearPendingUpdates();

		// Clear SMW's in-memory cache so reads fetch fresh data from DB
		$store = \SMW\StoreFactory::getStore();
		if ( method_exists( $store, 'clear' ) ) {
			$store->clear();
		}
	}
}
