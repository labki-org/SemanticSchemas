<?php

namespace MediaWiki\Extension\SemanticSchemas\Store;

use MediaWiki\Extension\SemanticSchemas\Schema\PropertyModel;
use MediaWiki\Extension\SemanticSchemas\Util\NamingHelper;
use MediaWiki\Extension\SemanticSchemas\Util\SMWDataExtractor;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * WikiPropertyStore
 * ------------------
 * Reads and writes Property: pages as PropertyModel objects.
 *
 * Fully semantic: NO wikitext parsing.
 */
class WikiPropertyStore {

	use SMWDataExtractor;

	private PageCreator $pageCreator;
	private IConnectionProvider $connectionProvider;

	public function __construct(
		PageCreator $pageCreator,
		IConnectionProvider $connectionProvider,
	) {
		$this->pageCreator = $pageCreator;
		$this->connectionProvider = $connectionProvider;
	}

	/* -------------------------------------------------------------------------
	 * PUBLIC API — READ
	 * ------------------------------------------------------------------------- */

	public function readProperty( string $propertyName ): ?PropertyModel {
		$canonical = $this->canonicalize( $propertyName );

		$title = $this->pageCreator->makeTitle( $canonical, SMW_NS_PROPERTY );
		if ( !$title || !$this->pageCreator->pageExists( $title ) ) {
			return null;
		}

		$data = $this->loadFromSMW( $title );

		// Ensure canonical minimal fields
		// Use NamingHelper to generate human-readable label from property name
		$data += [
			'datatype' => 'Page',
			'label' => NamingHelper::generatePropertyLabel( $canonical ),
			'description' => '',
			'allowedValues' => [],
			'subpropertyOf' => null,
			'allowedCategory' => null,
			'allowedNamespace' => null,
			'allowsMultipleValues' => false,
			'hasTemplate' => null,
			'inputType' => null,
			'inverseLabel' => null,
			'hidden' => false,
		];

		return new PropertyModel( $canonical, $data );
	}

	/* -------------------------------------------------------------------------
	 * PUBLIC API — WRITE
	 * ------------------------------------------------------------------------- */

	public function getAllProperties(): array {
		$out = [];

		$dbr = $this->connectionProvider->getReplicaDatabase();

		$res = $dbr->newSelectQueryBuilder()
			->select( 'page_title' )
			->from( 'page' )
			->where( [ 'page_namespace' => SMW_NS_PROPERTY ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		foreach ( $res as $row ) {
			$name = str_replace( '_', ' ', $row->page_title );

			$pm = $this->readProperty( $name );
			if ( $pm ) {
				$out[$name] = $pm;
			}
		}

		return $out;
	}

	/* -------------------------------------------------------------------------
	 * INTERNAL — LOAD FROM SMW
	 * ------------------------------------------------------------------------- */

	private function loadFromSMW( Title $title ): array {
		$store = \SMW\StoreFactory::getStore();
		$subject = \SMW\DIWikiPage::newFromTitle( $title );
		$sdata = $store->getSemanticData( $subject );

		$out = [];

		// Datatype comes from SMW's internal property type API, not semantic annotations
		try {
			$prop = \SMW\DIProperty::newFromUserLabel( $title->getText() );
			if ( $prop !== null ) {
				$internalTypeId = $prop->findPropertyTypeID();
				if ( $internalTypeId !== null ) {
					$out['datatype'] = $this->convertSMWTypeIdToCanonical( $internalTypeId );
				}
			}
		} catch ( \Throwable $e ) {
			// If property creation fails, datatype will default to 'Text' in readProperty()
		}

		$out += $this->smwLoadProperties( $sdata, PropertyModel::SMW_PROPERTIES );

		// hasTemplate also sets templateSource for backward compat
		if ( !empty( $out['hasTemplate'] ) ) {
			$out['templateSource'] = $out['hasTemplate'];
		}

		return array_filter(
			$out,
			static fn ( $v ) => $v !== null && $v !== []
		);
	}

	/* -------------------------------------------------------------------------
	 * CANONICALIZATION
	 * ------------------------------------------------------------------------- */

	private function canonicalize( string $name ): string {
		$name = trim( $name );
		$name = preg_replace( '/^Property:/i', '', $name );
		return str_replace( '_', ' ', $name );
	}
}
