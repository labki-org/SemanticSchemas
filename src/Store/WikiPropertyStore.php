<?php

namespace MediaWiki\Extension\SemanticSchemas\Store;

use MediaWiki\Extension\SemanticSchemas\Schema\PropertyModel;
use MediaWiki\Extension\SemanticSchemas\Util\NamingHelper;
use MediaWiki\Extension\SemanticSchemas\Util\SMWDataExtractor;
use MediaWiki\Title\Title;
use SMW\Services\ServicesFactory;
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

	private IConnectionProvider $connectionProvider;

	public function __construct(
		IConnectionProvider $connectionProvider,
	) {
		$this->connectionProvider = $connectionProvider;
	}

	/* -------------------------------------------------------------------------
	 * PUBLIC API — READ
	 * ------------------------------------------------------------------------- */

	public function readProperty( string $propertyName ): ?PropertyModel {
		$canonical = $this->canonicalize( $propertyName );

		$title = Title::makeTitleSafe( SMW_NS_PROPERTY, $canonical );
		if ( !$title || !$title->exists() ) {
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

		// Datatype comes from SMW's internal property type API, not semantic annotations.
		//
		// SMW's PropertySpecificationLookup caches the `_TYPE` lookup per
		// subject in an EntityCache entry with TTL_WEEK, and the
		// invalidation paths it relies on (ChangePropagationDispatchJob,
		// PropertyChangeListener watchlist, ArticlePurge) do not fire for
		// every state transition — a stale entry seeded before the property
		// existed can survive a first-time type assignment. Callers then
		// see the wrong form input widget (e.g. combobox for what should be
		// a date field) until someone purges the property page. Force a
		// fresh read; regenerating a property model is infrequent and
		// explicit, so bypassing a week-long cache is cheap.
		try {
			$prop = \SMW\DIProperty::newFromUserLabel( $title->getText() );
			ServicesFactory::getInstance()
				->getPropertySpecificationLookup()
				->invalidateCache( $subject );
			$internalTypeId = $prop->findPropertyTypeID();
			if ( $internalTypeId !== null ) {
				$out['datatype'] = $this->convertSMWTypeIdToCanonical( $internalTypeId );
			}
		} catch ( \Throwable ) {
			// If property creation fails, datatype will default to 'Page' in readProperty()
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
