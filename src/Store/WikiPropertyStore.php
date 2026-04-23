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
			'allowedCategory' => null,
			'allowedNamespace' => null,
			'allowsMultipleValues' => false,
			'inputType' => null,
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
		// SMW's PropertySpecificationLookup caches every `_TYPE` lookup
		// (including empty results) in an EntityCache backed by a
		// CompositeCache whose in-process layer lives for the PHP process's
		// lifetime. On long-lived interpreters (Apache mod_php prefork with
		// `MaxConnectionsPerChild 0`, PHP-FPM with high `pm.max_requests`),
		// that layer accumulates state across requests. A `findPropertyTypeID`
		// call racing with an in-flight property-page save caches `[]` for
		// the subject, and subsequent reads on that worker fall back to
		// `smwgPDefaultType` (`_wpg` → Page). The form generator then emits
		// combobox inputs for properties whose store entry is actually Date,
		// Text, URL, etc., until the worker is recycled or the property page
		// is purged. Regenerating a property model is infrequent and
		// explicit, so always force a fresh lookup.
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
