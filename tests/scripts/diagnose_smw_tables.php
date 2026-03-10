<?php
/**
 * Diagnostic script to trace SMW's table assignment during updateData().
 *
 * Run inside Docker:
 *   docker compose exec wiki php /var/www/html/extensions/SemanticSchemas/tests/scripts/diagnose_smw_tables.php
 *
 * Tests:
 * 1. Does findPropertyTypeID() return correct types after registration?
 * 2. Does updateData() write to the correct table?
 * 3. Is there a cache layer we're not clearing?
 */

require_once '/var/www/html/maintenance/Maintenance.php';

use MediaWiki\Maintenance\Maintenance;

class DiagnoseSMWTables extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Diagnose SMW table assignment during updateData()' );
	}

	public function execute() {
		$this->output( "=== SMW Table Assignment Diagnostic ===\n\n" );

		if ( !class_exists( \SMW\StoreFactory::class ) ) {
			$this->fatalError( 'SMW not loaded' );
		}

		$store = \SMW\StoreFactory::getStore();
		$lbFactory = $this->getServiceContainer()->getDBLoadBalancerFactory();

		// Test property: "Has description" (should be Text/_txt)
		$testPropertyName = 'Has description';
		$title = \MediaWiki\Title\Title::newFromText( $testPropertyName, SMW_NS_PROPERTY );

		if ( !$title || !$title->exists() ) {
			$this->output( "Property '$testPropertyName' does not exist. Run install first.\n" );
			$this->output( "Checking all properties in smw_fpt_type...\n\n" );
			$this->dumpTypeTable( $store );
			return;
		}

		$this->output( "--- Test 1: Check smw_fpt_type for registered types ---\n" );
		$this->dumpTypeTable( $store );

		$this->output( "\n--- Test 2: Check findPropertyTypeID() for key properties ---\n" );
		$propertiesToCheck = [
			'Has description', 'Has input type', 'Display label',
			'Has allowed namespace', 'Allows value',
		];
		foreach ( $propertiesToCheck as $propName ) {
			$propTitle = \MediaWiki\Title\Title::newFromText( $propName, SMW_NS_PROPERTY );
			if ( !$propTitle || !$propTitle->exists() ) {
				$this->output( "  $propName: PAGE NOT FOUND\n" );
				continue;
			}
			$diProp = new \SMW\DIProperty(
				\SMW\DIWikiPage::newFromTitle( $propTitle )->getDBkey()
			);
			$typeId = $diProp->findPropertyValueType();
			$tableId = $store->findPropertyTableID( $diProp );
			$this->output( "  $propName: type=$typeId table=$tableId\n" );
		}

		$this->output( "\n--- Test 3: Check current data tables for 'Has description' ---\n" );
		$this->checkDataTables( $store, $testPropertyName );

		$this->output( "\n--- Test 4: Fresh parse + updateData() with logging ---\n" );
		$this->testFreshParseAndUpdate( $store, $title, $lbFactory );

		$this->output( "\n--- Test 5: Check data tables AFTER updateData() ---\n" );
		$this->checkDataTables( $store, $testPropertyName );

		$this->output( "\n--- Test 6: Check findPropertyTypeID() AFTER update ---\n" );
		// Clear caches and re-check
		$store->clear();
		if ( class_exists( \SMW\Services\ServicesFactory::class ) ) {
			\SMW\Services\ServicesFactory::clear();
		}
		foreach ( $propertiesToCheck as $propName ) {
			$propTitle = \MediaWiki\Title\Title::newFromText( $propName, SMW_NS_PROPERTY );
			if ( !$propTitle || !$propTitle->exists() ) {
				continue;
			}
			// Create fresh DIProperty instance (no cached type)
			$diProp = new \SMW\DIProperty(
				\SMW\DIWikiPage::newFromTitle( $propTitle )->getDBkey()
			);
			$typeId = $diProp->findPropertyValueType();
			$tableId = $store->findPropertyTableID( $diProp );
			$this->output( "  $propName: type=$typeId table=$tableId\n" );
		}
	}

	private function getMWDb() {
		return $this->getServiceContainer()->getDBLoadBalancerFactory()
			->getPrimaryDatabase();
	}

	private function dumpTypeTable( $store ): void {
		$db = $this->getMWDb();
		$res = $db->newSelectQueryBuilder()
			->select( [ 'smw_id', 'smw_title', 'smw_namespace' ] )
			->from( 'smw_object_ids' )
			->where( [ 'smw_namespace' => SMW_NS_PROPERTY ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$propIds = [];
		foreach ( $res as $row ) {
			$propIds[$row->smw_id] = $row->smw_title;
		}

		if ( empty( $propIds ) ) {
			$this->output( "  No properties in smw_object_ids\n" );
			return;
		}

		$res = $db->newSelectQueryBuilder()
			->select( [ 's_id', 'o_serialized' ] )
			->from( 'smw_fpt_type' )
			->where( [ 's_id' => array_keys( $propIds ) ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		foreach ( $res as $row ) {
			$propName = $propIds[$row->s_id] ?? "id={$row->s_id}";
			$typeUri = $row->o_serialized;
			// Extract type ID from URI like http://semantic-mediawiki.org/swivt/1.0#_txt
			$typeId = substr( $typeUri, strrpos( $typeUri, '#' ) + 1 );
			$this->output( "  $propName: $typeId ($typeUri)\n" );
		}
	}

	private function checkDataTables( $store, string $propertyName ): void {
		$db = $this->getMWDb();

		// Find the SMW ID for this property page
		$propTitle = \MediaWiki\Title\Title::newFromText( $propertyName, SMW_NS_PROPERTY );
		$subject = \SMW\DIWikiPage::newFromTitle( $propTitle );

		$sid = $store->getObjectIds()->getSMWPageID(
			$subject->getDBkey(),
			$subject->getNamespace(),
			$subject->getInterwiki(),
			$subject->getSubobjectName()
		);

		if ( $sid == 0 ) {
			$this->output( "  No SMW ID found for $propertyName\n" );
			return;
		}

		$this->output( "  SMW ID for '$propertyName': $sid\n" );

		// Check smw_di_blob
		$blobCount = $db->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'smw_di_blob' )
			->where( [ 's_id' => $sid ] )
			->caller( __METHOD__ )
			->fetchField();
		$this->output( "  smw_di_blob rows: $blobCount\n" );

		if ( $blobCount > 0 ) {
			$res = $db->newSelectQueryBuilder()
				->select( [ 'p_id', 'o_blob', 'o_hash' ] )
				->from( 'smw_di_blob' )
				->where( [ 's_id' => $sid ] )
				->caller( __METHOD__ )
				->fetchResultSet();
			foreach ( $res as $row ) {
				$pName = $this->resolvePropertyId( $db, $row->p_id );
				$val = $row->o_blob ?: $row->o_hash;
				$this->output( "    p=$pName val=" . substr( $val, 0, 60 ) . "\n" );
			}
		}

		// Check smw_di_wikipage
		$wpCount = $db->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'smw_di_wikipage' )
			->where( [ 's_id' => $sid ] )
			->caller( __METHOD__ )
			->fetchField();
		$this->output( "  smw_di_wikipage rows: $wpCount\n" );

		if ( $wpCount > 0 ) {
			$res = $db->newSelectQueryBuilder()
				->select( [ 'p_id', 'o_id' ] )
				->from( 'smw_di_wikipage' )
				->where( [ 's_id' => $sid ] )
				->caller( __METHOD__ )
				->fetchResultSet();
			foreach ( $res as $row ) {
				$pName = $this->resolvePropertyId( $db, $row->p_id );
				$oName = $this->resolveObjectId( $db, $row->o_id );
				$this->output( "    p=$pName o=$oName\n" );
			}
		}
	}

	private function testFreshParseAndUpdate( $store, $title, $lbFactory ): void {
		$services = $this->getServiceContainer();
		$wikiPage = $services->getWikiPageFactory()->newFromTitle( $title );
		$content = $wikiPage->getContent();

		if ( !$content instanceof \MediaWiki\Content\TextContent ) {
			$this->output( "  Page has no text content\n" );
			return;
		}

		$this->output( "  Page wikitext:\n" );
		$text = $content->getText();
		foreach ( explode( "\n", $text ) as $line ) {
			$this->output( "    $line\n" );
		}

		// Clear ALL SMW caches before fresh parse
		$store->clear();
		if ( class_exists( \SMW\Services\ServicesFactory::class ) ) {
			\SMW\Services\ServicesFactory::clear();
		}

		// Also clear PropertyTableInfoFetcher
		$store->getPropertyTableInfoFetcher()->clearCache();

		// Also invalidate SpecificationLookup cache
		if ( class_exists( \SMW\Services\ServicesFactory::class ) ) {
			$lookup = \SMW\Services\ServicesFactory::getInstance()
				->getPropertySpecificationLookup();
			$lookup->invalidateCache( \SMW\DIWikiPage::newFromTitle( $title ) );
		}

		$this->output( "\n  Performing fresh parse...\n" );
		$parser = $services->getParserFactory()->getInstance();
		$parserOptions = \MediaWiki\Parser\ParserOptions::newFromAnon();
		$parserOutput = $parser->parse( $text, $title, $parserOptions );

		$smwData = $parserOutput->getExtensionData( \SMW\ParserData::DATA_ID );

		if ( !( $smwData instanceof \SMW\SemanticData ) ) {
			$this->output( "  NO SemanticData in ParserOutput!\n" );
			return;
		}

		$this->output( "  SemanticData properties:\n" );
		foreach ( $smwData->getProperties() as $prop ) {
			$typeId = $prop->findPropertyValueType();
			$tableId = $store->findPropertyTableID( $prop );
			$values = $smwData->getPropertyValues( $prop );
			foreach ( $values as $v ) {
				$diType = get_class( $v );
				$serial = $v->getSerialization();
				$this->output(
					"    {$prop->getKey()}: type=$typeId table=$tableId"
					. " di=$diType val=" . substr( $serial, 0, 50 ) . "\n"
				);
			}
		}

		$this->output( "\n  Calling store->updateData()...\n" );
		$store->updateData( $smwData );
		$lbFactory->commitPrimaryChanges( __METHOD__ );
		$this->output( "  Done.\n" );
	}

	private function resolvePropertyId( $db, int $pid ): string {
		$row = $db->newSelectQueryBuilder()
			->select( 'smw_title' )
			->from( 'smw_object_ids' )
			->where( [ 'smw_id' => $pid ] )
			->caller( __METHOD__ )
			->fetchRow();
		return $row ? str_replace( '_', ' ', $row->smw_title ) : "pid=$pid";
	}

	private function resolveObjectId( $db, int $oid ): string {
		$row = $db->newSelectQueryBuilder()
			->select( [ 'smw_title', 'smw_namespace' ] )
			->from( 'smw_object_ids' )
			->where( [ 'smw_id' => $oid ] )
			->caller( __METHOD__ )
			->fetchRow();
		return $row ? str_replace( '_', ' ', $row->smw_title ) . " (ns={$row->smw_namespace})" : "oid=$oid";
	}
}

$maintClass = DiagnoseSMWTables::class;
require_once RUN_MAINTENANCE_IF_MAIN;
