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
			$internalTypeId = $prop->findPropertyTypeID();
			if ( $internalTypeId !== null ) {
				$out['datatype'] = $this->convertSMWTypeIdToCanonical( $internalTypeId );
			}
		} catch ( \Throwable ) {
			// If property creation fails, datatype will default to 'Page' in readProperty()
		}

		$out['label'] = $this->smwFetchOne( $sdata, 'Display label' );
		$out['description'] = $this->smwFetchOne( $sdata, 'Has description' );

		$out['allowedValues'] =
			$this->smwFetchMany( $sdata, 'Allows value', 'text' );

		$out['subpropertyOf'] =
			$this->smwFetchOne( $sdata, 'Subproperty of', 'property' );

		$out['allowedCategory'] =
			$this->smwFetchOne( $sdata, 'Allows value from category', 'category' );

		$out['allowedNamespace'] =
			$this->smwFetchOne( $sdata, 'Allows value from namespace', 'text' );

		$out['allowsMultipleValues'] =
			$this->smwFetchBoolean( $sdata, 'Allows multiple values' );

		/* -------------------- Template Configuration -------------------- */
		$hasTemplate = $this->smwFetchOne( $sdata, 'Has template', 'page' );

		if ( $hasTemplate ) {
			$out['hasTemplate'] = $hasTemplate;
			$out['templateSource'] = $hasTemplate;
		}

		/* -------------------- Input type override -------------------- */
		$out['inputType'] = $this->smwFetchOne( $sdata, 'Has input type' );

		/* -------------------- Backlink label -------------------- */
		$out['inverseLabel'] = $this->smwFetchOne( $sdata, 'Inverse property label' );

		/* -------------------- Hidden -------------------- */
		$out['hidden'] = $this->smwFetchBoolean( $sdata, 'Is hidden' );

		// Clean null/empty
		return array_filter(
			$out,
			static fn ( $v ) => $v !== null && $v !== []
		);
	}

	/* -------------------------------------------------------------------------
	 * TYPE ID CONVERSION
	 * ------------------------------------------------------------------------- */

	/**
	 * Convert SMW's internal type ID (e.g., '_txt', '_wpg') to canonical datatype name.
	 *
	 * @param string $typeId SMW internal type ID
	 * @return string Canonical datatype name
	 */
	private function convertSMWTypeIdToCanonical( string $typeId ): string {
		// Mapping from SMW internal type IDs to canonical names
		static $typeMap = [
			'_txt' => 'Text',
			'_wpg' => 'Page',
			'_dat' => 'Date',
			'_num' => 'Number',
			'_boo' => 'Boolean',
			'_uri' => 'URL',
			'_ema' => 'Email',
			'_tel' => 'Telephone number',
			'_cod' => 'Code',
			'_geo' => 'Geographic coordinate',
			'_qty' => 'Quantity',
			'_tem' => 'Temperature',
			'_anu' => 'Annotation URI',
			'_eid' => 'External identifier',
			'_key' => 'Keyword',
			'_mlt_rec' => 'Monolingual text',
			'_rec' => 'Record',
			'_ref_rec' => 'Reference',
		];

		// If it's already a canonical name, return as-is
		if ( substr( $typeId, 0, 1 ) !== '_' ) {
			return $typeId;
		}

		return $typeMap[$typeId] ?? 'Text';
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
