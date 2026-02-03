<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit\Store;

use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\StateManager;
use MediaWiki\Title\Title;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Store\StateManager
 */
class StateManagerTest extends TestCase {

	private ?string $storedPageContent = null;

	private function createStateManager(): StateManager {
		$mockTitle = $this->createMock( Title::class );

		$mockPageCreator = $this->createMock( PageCreator::class );
		$mockPageCreator->method( 'makeTitle' )->willReturn( $mockTitle );
		$mockPageCreator->method( 'pageExists' )->willReturnCallback( function () {
			return $this->storedPageContent !== null;
		} );
		$mockPageCreator->method( 'getPageContent' )->willReturnCallback( function () {
			return $this->storedPageContent;
		} );
		$mockPageCreator->method( 'createOrUpdatePage' )->willReturnCallback(
			function ( $title, $content, $summary ) {
				$this->storedPageContent = $content;
				return true;
			}
		);

		return new StateManager( $mockPageCreator );
	}

	/* =========================================================================
	 * DEFAULT STATE
	 * ========================================================================= */

	public function testIsDirtyReturnsFalseByDefault(): void {
		$manager = $this->createStateManager();
		$this->assertFalse( $manager->isDirty() );
	}

	public function testGetPageHashesReturnsEmptyArrayByDefault(): void {
		$manager = $this->createStateManager();
		$this->assertEquals( [], $manager->getPageHashes() );
	}

	public function testGetLastChangeTimestampReturnsNullByDefault(): void {
		$manager = $this->createStateManager();
		$this->assertNull( $manager->getLastChangeTimestamp() );
	}

	/* =========================================================================
	 * DIRTY FLAG
	 * ========================================================================= */

	public function testSetDirtyTrue(): void {
		$manager = $this->createStateManager();
		$result = $manager->setDirty( true );

		$this->assertTrue( $result );
		$this->assertTrue( $manager->isDirty() );
	}

	public function testSetDirtySetsTimestamp(): void {
		$manager = $this->createStateManager();
		$manager->setDirty( true );

		$timestamp = $manager->getLastChangeTimestamp();
		$this->assertNotNull( $timestamp );
		// Timestamp should be in ISO 8601 format
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $timestamp );
	}

	public function testClearDirty(): void {
		$manager = $this->createStateManager();
		$manager->setDirty( true );
		$manager->clearDirty();

		$this->assertFalse( $manager->isDirty() );
	}

	/* =========================================================================
	 * PAGE HASHES
	 * ========================================================================= */

	public function testSetPageHashes(): void {
		$manager = $this->createStateManager();
		$hashes = [
			'Category:Person' => 'abc123',
			'Property:Has name' => 'def456',
		];

		$result = $manager->setPageHashes( $hashes );
		$this->assertTrue( $result );

		$stored = $manager->getPageHashes();
		$this->assertArrayHasKey( 'Category:Person', $stored );
		$this->assertArrayHasKey( 'Property:Has name', $stored );
	}

	public function testSetPageHashesStoresBothGeneratedAndCurrent(): void {
		$manager = $this->createStateManager();
		$manager->setPageHashes( [
			'Category:Person' => 'abc123',
		] );

		$stored = $manager->getPageHashes();
		$this->assertEquals( 'abc123', $stored['Category:Person']['generated'] );
		$this->assertEquals( 'abc123', $stored['Category:Person']['current'] );
	}

	public function testUpdateCurrentHashes(): void {
		$manager = $this->createStateManager();
		$manager->setPageHashes( [
			'Category:Person' => 'original',
		] );

		$manager->updateCurrentHashes( [
			'Category:Person' => 'modified',
		] );

		$stored = $manager->getPageHashes();
		$this->assertEquals( 'original', $stored['Category:Person']['generated'] );
		$this->assertEquals( 'modified', $stored['Category:Person']['current'] );
	}

	/* =========================================================================
	 * HASH COMPARISON
	 * ========================================================================= */

	public function testComparePageHashesReturnsModifiedPages(): void {
		$manager = $this->createStateManager();
		$manager->setPageHashes( [
			'Category:Person' => 'hash1',
			'Category:Student' => 'hash2',
		] );

		$currentHashes = [
			'Category:Person' => 'hash1', // unchanged
			'Category:Student' => 'modified', // changed
		];

		$modified = $manager->comparePageHashes( $currentHashes );
		$this->assertContains( 'Category:Student', $modified );
		$this->assertNotContains( 'Category:Person', $modified );
	}

	public function testComparePageHashesDetectsNewPages(): void {
		$manager = $this->createStateManager();
		$manager->setPageHashes( [
			'Category:Person' => 'hash1',
		] );

		$currentHashes = [
			'Category:Person' => 'hash1',
			'Category:NewCategory' => 'newhash', // new page
		];

		$modified = $manager->comparePageHashes( $currentHashes );
		$this->assertContains( 'Category:NewCategory', $modified );
	}

	public function testComparePageHashesDetectsDeletedPages(): void {
		$manager = $this->createStateManager();
		$manager->setPageHashes( [
			'Category:Person' => 'hash1',
			'Category:Deleted' => 'hash2',
		] );

		$currentHashes = [
			'Category:Person' => 'hash1',
			// Category:Deleted is missing
		];

		$modified = $manager->comparePageHashes( $currentHashes );
		$this->assertContains( 'Category:Deleted', $modified );
	}

	public function testComparePageHashesReturnsEmptyWhenUnchanged(): void {
		$manager = $this->createStateManager();
		$manager->setPageHashes( [
			'Category:Person' => 'hash1',
			'Category:Student' => 'hash2',
		] );

		$currentHashes = [
			'Category:Person' => 'hash1',
			'Category:Student' => 'hash2',
		];

		$modified = $manager->comparePageHashes( $currentHashes );
		$this->assertEmpty( $modified );
	}

	/* =========================================================================
	 * MODIFIED PAGES (GENERATED VS CURRENT)
	 * ========================================================================= */

	public function testGetModifiedPagesReturnsEmptyWhenUnchanged(): void {
		$manager = $this->createStateManager();
		$manager->setPageHashes( [
			'Category:Person' => 'hash1',
		] );

		$modified = $manager->getModifiedPages();
		$this->assertEmpty( $modified );
	}

	public function testGetModifiedPagesDetectsChanges(): void {
		$manager = $this->createStateManager();
		$manager->setPageHashes( [
			'Category:Person' => 'original',
		] );
		$manager->updateCurrentHashes( [
			'Category:Person' => 'modified',
		] );

		$modified = $manager->getModifiedPages();
		$this->assertContains( 'Category:Person', $modified );
	}

	/* =========================================================================
	 * FULL STATE
	 * ========================================================================= */

	public function testGetFullStateReturnsCompleteStructure(): void {
		$manager = $this->createStateManager();
		$manager->setDirty( true );
		$manager->setPageHashes( [ 'Category:Person' => 'hash1' ] );

		$state = $manager->getFullState();

		$this->assertArrayHasKey( 'dirty', $state );
		$this->assertArrayHasKey( 'lastChangeTimestamp', $state );
		$this->assertArrayHasKey( 'pageHashes', $state );
		$this->assertArrayHasKey( 'sourceSchemaHash', $state );
	}

	/* =========================================================================
	 * PERSISTENCE
	 * ========================================================================= */

	public function testStatePersistedAcrossInstances(): void {
		$manager1 = $this->createStateManager();
		$manager1->setPageHashes( [
			'Category:Person' => 'hash1',
		] );
		$manager1->setDirty( true );

		// Create a new instance with the same mock
		$manager2 = $this->createStateManager();

		$this->assertTrue( $manager2->isDirty() );
		$this->assertArrayHasKey( 'Category:Person', $manager2->getPageHashes() );
	}

	public function testMergeWithExistingState(): void {
		$manager = $this->createStateManager();
		$manager->setPageHashes( [
			'Category:Person' => 'hash1',
		] );

		// Add more hashes
		$manager->setPageHashes( [
			'Category:Student' => 'hash2',
		] );

		$hashes = $manager->getPageHashes();
		// Both should be present
		$this->assertArrayHasKey( 'Category:Person', $hashes );
		$this->assertArrayHasKey( 'Category:Student', $hashes );
	}
}
