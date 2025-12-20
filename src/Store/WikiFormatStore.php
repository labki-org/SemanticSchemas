<?php

namespace MediaWiki\Extension\SemanticSchemas\Store;

use MediaWiki\Extension\SemanticSchemas\Schema\FormatModel;
use MediaWiki\Title\Title;

/**
 * WikiFormatStore
 * ---------------
 * Reads TemplateFormat category pages as FormatModel objects.
 *
 * Provides access to format schemas that define how category properties
 * should be composed into display templates.
 *
 * @since 1.0
 */
class WikiFormatStore {

	private PageCreator $pageCreator;

	public function __construct( ?PageCreator $pageCreator = null ) {
		$this->pageCreator = $pageCreator ?? new PageCreator();
	}

	/* -------------------------------------------------------------------------
	 * PUBLIC API — READ
	 * ------------------------------------------------------------------------- */

	/**
	 * Read a TemplateFormat category and return a FormatModel.
	 *
	 * @param string $formatName Format name (e.g., "TemplateFormat/Sections")
	 * @return FormatModel|null
	 */
	public function readFormat( string $formatName ): ?FormatModel {
		$canonical = $this->canonicalize( $formatName );

		$title = $this->pageCreator->makeTitle( $canonical, NS_CATEGORY );
		if ( !$title || !$this->pageCreator->pageExists( $title ) ) {
			return null;
		}

		$data = $this->loadFromSMW( $title );

		// Ensure minimal fields
		$data += [
			'label'              => $canonical,
			'description'        => '',
			'wrapperTemplate'    => null,
			'propertyPattern'    => null,
			'sectionSeparator'   => null,
			'emptyValueBehavior' => 'hide',
		];

		return new FormatModel( $canonical, $data );
	}

	/**
	 * Check if a format exists.
	 *
	 * @param string $formatName Format name
	 * @return bool
	 */
	public function formatExists( string $formatName ): bool {
		$canonical = $this->canonicalize( $formatName );
		$title = $this->pageCreator->makeTitle( $canonical, NS_CATEGORY );
		return $title && $this->pageCreator->pageExists( $title );
	}

	/**
	 * Get all TemplateFormat categories.
	 *
	 * @return FormatModel[] Array of format models
	 */
	public function getAllFormats(): array {
		$out = [];

		if ( !defined( 'SMW_VERSION' ) ) {
			return $out;
		}

		// Query for all categories that are subpages of TemplateFormat
		// This includes TemplateFormat itself and all TemplateFormat/* categories
		$store = \SMW\StoreFactory::getStore();

		// Use database query to find all Category pages starting with "TemplateFormat"
		$dbr = \MediaWiki\MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getConnection( DB_REPLICA );

		$res = $dbr->newSelectQueryBuilder()
			->select( 'page_title' )
			->from( 'page' )
			->where( [
				'page_namespace' => NS_CATEGORY,
				$dbr->expr( 'page_title', \Wikimedia\Rdbms\IExpression::LIKE,
					new \Wikimedia\Rdbms\LikeValue( 'TemplateFormat', $dbr->anyString() )
				),
			] )
			->caller( __METHOD__ )
			->fetchResultSet();

		foreach ( $res as $row ) {
			$name = str_replace( '_', ' ', $row->page_title );

			$format = $this->readFormat( $name );
			if ( $format ) {
				$out[$name] = $format;
			}
		}

		return $out;
	}

	/* -------------------------------------------------------------------------
	 * INTERNAL — LOAD FROM SMW
	 * ------------------------------------------------------------------------- */

	/**
	 * Load format data from SMW semantic properties.
	 *
	 * @param Title $title Category title
	 * @return array Format data
	 */
	private function loadFromSMW( Title $title ): array {
		if ( !defined( 'SMW_VERSION' ) ) {
			return [];
		}

		$store = \SMW\StoreFactory::getStore();
		$subject = \SMW\DIWikiPage::newFromTitle( $title );
		$sdata = $store->getSemanticData( $subject );

		$out = [];

		// Read format-specific properties
		$out['label'] = $this->fetchOne( $sdata, 'Display label', 'text' );
		$out['description'] = $this->fetchOne( $sdata, 'Has description', 'text' );
		$out['wrapperTemplate'] = $this->fetchOne( $sdata, 'Has wrapper template', 'text' );
		$out['propertyPattern'] = $this->fetchOne( $sdata, 'Has property template pattern', 'text' );
		$out['sectionSeparator'] = $this->fetchOne( $sdata, 'Has section separator', 'text' );
		$out['emptyValueBehavior'] = $this->fetchOne( $sdata, 'Has empty value behavior', 'text' );

		// Remove nulls
		return array_filter( $out, static fn( $v ) => $v !== null );
	}

	/**
	 * Fetch a single property value from semantic data.
	 *
	 * @param \SMW\SemanticData $sdata Semantic data
	 * @param string $propertyName Property name
	 * @param string $type Expected type: 'text', 'page', 'boolean'
	 * @return mixed|null Property value
	 */
	private function fetchOne( \SMW\SemanticData $sdata, string $propertyName, string $type ) {
		try {
			$prop = \SMW\DIProperty::newFromUserLabel( $propertyName );
			$values = $sdata->getPropertyValues( $prop );

			if ( empty( $values ) ) {
				return null;
			}

			$value = reset( $values );

			return match ( $type ) {
				'text'    => $value instanceof \SMWDIBlob ? trim( $value->getString() ) : null,
				'page'    => $value instanceof \SMW\DIWikiPage ? $value->getTitle()->getText() : null,
				'boolean' => $value instanceof \SMWDIBoolean ? $value->getBoolean() : null,
				default   => null,
			};
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Canonicalize format name (trim and normalize spaces).
	 *
	 * @param string $name Format name
	 * @return string Canonical name
	 */
	private function canonicalize( string $name ): string {
		return trim( str_replace( '_', ' ', $name ) );
	}
}

