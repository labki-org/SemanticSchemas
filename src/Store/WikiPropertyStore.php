<?php

namespace MediaWiki\Extension\SemanticSchemas\Store;

use MediaWiki\Extension\SemanticSchemas\Schema\PropertyModel;
use MediaWiki\Extension\SemanticSchemas\Util\NamingHelper;
use MediaWiki\Extension\SemanticSchemas\Util\SMWDataExtractor;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * WikiPropertyStore
 * ------------------
 * Reads and writes Property: pages as PropertyModel objects.
 *
 * Fully semantic: NO wikitext parsing.
 */
class WikiPropertyStore {

	use SMWDataExtractor;

	private const MARKER_START = '<!-- SemanticSchemas Start -->';
	private const MARKER_END = '<!-- SemanticSchemas End -->';

	private PageCreator $pageCreator;

	public function __construct( ?PageCreator $pageCreator = null ) {
		$this->pageCreator = $pageCreator ?? new PageCreator();
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
			'rangeCategory' => null,
			'subpropertyOf' => null,
			'allowedCategory' => null,
			'allowedNamespace' => null,
			'allowsMultipleValues' => false,
			'hasTemplate' => null,
			'inputType' => null,
		];

		return new PropertyModel( $canonical, $data );
	}

	/* -------------------------------------------------------------------------
	 * PUBLIC API — WRITE
	 * ------------------------------------------------------------------------- */

	public function writeProperty( PropertyModel $property ): bool {
		$title = $this->pageCreator->makeTitle( $property->getName(), SMW_NS_PROPERTY );
		if ( !$title ) {
			return false;
		}

		$existing = $this->pageCreator->getPageContent( $title ) ?? '';

		$semanticBlock = $this->buildSemanticBlock( $property );

		$newContent = $this->pageCreator->updateWithinMarkers(
			$existing,
			$semanticBlock,
			self::MARKER_START,
			self::MARKER_END
		);

		if ( !str_contains( $newContent, '[[Category:SemanticSchemas-managed-property]]' ) ) {
			$newContent .= "\n[[Category:SemanticSchemas-managed-property]]";
		}

		return $this->pageCreator->createOrUpdatePage(
			$title,
			$newContent,
			"SemanticSchemas: Update property metadata"
		);
	}

	/**
	 * Write a property page with ONLY the datatype declaration.
	 *
	 * This is Layer 1 of the installation process. SMW's property type registry is
	 * updated asynchronously via the job queue. If we write full property annotations
	 * (like [[Has description::...]]) before SMW knows the property's type, SMW may
	 * store values with incorrect data types (e.g., DIWikiPage instead of DIBlob).
	 *
	 * By writing only [[Has type::...]] first and waiting for SMW jobs to complete,
	 * we ensure the type registry is populated before any pages use these properties.
	 *
	 * @see ApiSemanticSchemasInstall for the full layer-by-layer installation explanation
	 *
	 * @param PropertyModel $property
	 * @return bool
	 */
	public function writePropertyTypeOnly( PropertyModel $property ): bool {
		$title = $this->pageCreator->makeTitle( $property->getName(), SMW_NS_PROPERTY );
		if ( !$title ) {
			return false;
		}

		$existing = $this->pageCreator->getPageContent( $title ) ?? '';

		// Only write the datatype declaration - no other semantic annotations
		$semanticBlock = '[[Has type::' . $property->getSMWType() . ']]';

		$newContent = $this->pageCreator->updateWithinMarkers(
			$existing,
			$semanticBlock,
			self::MARKER_START,
			self::MARKER_END
		);

		if ( !str_contains( $newContent, '[[Category:SemanticSchemas-managed-property]]' ) ) {
			$newContent .= "\n[[Category:SemanticSchemas-managed-property]]";
		}

		return $this->pageCreator->createOrUpdatePage(
			$title,
			$newContent,
			"SemanticSchemas: Initialize property type"
		);
	}

	public function getAllProperties(): array {
		$out = [];

		$dbr = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getConnection( DB_REPLICA );

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

		// Get datatype from SMW's internal property type API
		// Note: SMW stores datatypes internally, not as semantic annotations
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

		$out['label'] = $this->smwFetchOne( $sdata, 'Display label' );
		$out['description'] = $this->smwFetchOne( $sdata, 'Has description' );

		$out['allowedValues'] =
			$this->smwFetchMany( $sdata, 'Allows value', 'text' );

		$out['rangeCategory'] =
			$this->smwFetchOne( $sdata, 'Has domain and range', 'category' );

		$out['subpropertyOf'] =
			$this->smwFetchOne( $sdata, 'Subproperty of', 'property' );

		$out['allowedCategory'] =
			$this->smwFetchOne( $sdata, 'Allows value from category', 'text' );

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

		// Clean null/empty
		return array_filter(
			$out,
			static fn ( $v ) => $v !== null && $v !== []
		);
	}

	/* -------------------------------------------------------------------------
	 * INTERNAL — WRITE SEMANTIC BLOCK
	 * ------------------------------------------------------------------------- */

	private function buildSemanticBlock( PropertyModel $p ): string {
		$lines = [];

		// Datatype (required)
		$lines[] = '[[Has type::' . $p->getSMWType() . ']]';

		if ( $p->getDescription() !== '' ) {
			$lines[] = '[[Has description::' . $p->getDescription() . ']]';
		}

		if ( $p->allowsMultipleValues() ) {
			$lines[] = '[[Allows multiple values::true]]';
		}

		// Display label only if differs from canonical name
		if ( $p->getLabel() !== $p->getName() ) {
			$lines[] = '[[Display label::' . $p->getLabel() . ']]';
		}

		foreach ( $p->getAllowedValues() as $v ) {
			$lines[] = '[[Allows value::' . str_replace( '|', ' ', $v ) . ']]';
		}

		if ( $p->getRangeCategory() !== null ) {
			$lines[] = '[[Has domain and range::Category:' . $p->getRangeCategory() . ']]';
		}

		if ( $p->getSubpropertyOf() !== null ) {
			$lines[] = '[[Subproperty of::' . $p->getSubpropertyOf() . ']]';
		}

		if ( $p->getAllowedCategory() !== null ) {
			$lines[] = '[[Allows value from category::' . $p->getAllowedCategory() . ']]';
		}

		if ( $p->getAllowedNamespace() !== null ) {
			$lines[] = '[[Allows value from namespace::' . $p->getAllowedNamespace() . ']]';
		}

		// Template reference (or source)
		if ( $p->getHasTemplate() !== null ) {
			$lines[] = '[[Has template::' . $p->getHasTemplate() . ']]';
		}

		if ( $p->getInputType() !== null ) {
			$lines[] = '[[Has input type::' . $p->getInputType() . ']]';
		}

		return implode( "\n", $lines );
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
