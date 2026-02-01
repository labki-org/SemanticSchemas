<?php

namespace MediaWiki\Extension\SemanticSchemas\Store;

use MediaWiki\Extension\SemanticSchemas\Schema\SubobjectModel;
use MediaWiki\Extension\SemanticSchemas\Util\SMWDataExtractor;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * WikiSubobjectStore
 * -------------------
 * Reads and writes Subobject: pages as SubobjectModel objects.
 *
 * Pure SMW I/O. No wikitext parsing. No legacy formats.
 */
class WikiSubobjectStore {

	use SMWDataExtractor;

	private const MARKER_START = '<!-- SemanticSchemas Start -->';
	private const MARKER_END   = '<!-- SemanticSchemas End -->';

	private PageCreator $pageCreator;

	public function __construct( ?PageCreator $pageCreator = null ) {
		$this->pageCreator = $pageCreator ?? new PageCreator();
	}

	/* -------------------------------------------------------------------------
	 * READ
	 * ------------------------------------------------------------------------- */

	public function readSubobject( string $name ): ?SubobjectModel {
		$title = $this->pageCreator->makeTitle( $name, NS_SUBOBJECT );
		if ( !$title || !$this->pageCreator->pageExists( $title ) ) {
			return null;
		}

		$data = $this->readFromSMW( $title );

		// Guarantee canonical minimal structure
		$data += [
			'label'       => $name,
			'description' => '',
			'properties'  => [
				'required' => [],
				'optional' => [],
			],
		];

		return new SubobjectModel( $name, $data );
	}

	private function readFromSMW( Title $title ): array {
		$out = [
			'label' => null,
			'description' => '',
			'properties' => [
				'required' => [],
				'optional' => [],
			],
		];

		try {
			$store   = \SMW\StoreFactory::getStore();
			$subject = \SMW\DIWikiPage::newFromTitle( $title );
			$sdata   = $store->getSemanticData( $subject );
		} catch ( \Throwable $e ) {
			return $out;
		}

		// Optional label
		$label = $this->smwFetchOne( $sdata, 'Display label', 'text' );
		if ( $label !== null && $label !== '' ) {
			$out['label'] = $label;
		}

		// Description
		$desc = $this->smwFetchOne( $sdata, 'Has description', 'text' );
		if ( $desc !== null ) {
			$out['description'] = $desc;
		}

		// Required properties
		$out['properties']['required'] = $this->smwFetchMany(
			$sdata,
			'Has required property',
			'property'
		);

		// Optional properties
		$out['properties']['optional'] = $this->smwFetchMany(
			$sdata,
			'Has optional property',
			'property'
		);

		return $out;
	}

	/* -------------------------------------------------------------------------
	 * WRITE
	 * ------------------------------------------------------------------------- */

	public function writeSubobject( SubobjectModel $s ): bool {
		$title = $this->pageCreator->makeTitle( $s->getName(), NS_SUBOBJECT );
		if ( !$title ) {
			return false;
		}

		$existing = $this->pageCreator->getPageContent( $title ) ?? '';

		$block = $this->buildSemanticBlock( $s );

		$newContent = $this->pageCreator->updateWithinMarkers(
			$existing,
			$block,
			self::MARKER_START,
			self::MARKER_END
		);

		// Tracking category
		if ( !str_contains( $newContent, '[[Category:SemanticSchemas-managed-subobject]]' ) ) {
			$newContent .= "\n[[Category:SemanticSchemas-managed-subobject]]";
		}

		return $this->pageCreator->createOrUpdatePage(
			$title,
			$newContent,
			'SemanticSchemas: Update subobject schema metadata'
		);
	}

	private function buildSemanticBlock( SubobjectModel $s ): string {
		$lines = [];

		if ( $s->getDescription() !== '' ) {
			$lines[] = '[[Has description::' . $s->getDescription() . ']]';
		}

		foreach ( $s->getRequiredProperties() as $p ) {
			$lines[] = '[[Has required property::Property:' . $p . ']]';
		}

		foreach ( $s->getOptionalProperties() as $p ) {
			$lines[] = '[[Has optional property::Property:' . $p . ']]';
		}

		return implode( "\n", $lines );
	}

	/* -------------------------------------------------------------------------
	 * LISTING
	 * ------------------------------------------------------------------------- */

	public function getAllSubobjects(): array {
		$out = [];

		$dbr = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getConnection( DB_REPLICA );

		$res = $dbr->newSelectQueryBuilder()
			->select( 'page_title' )
			->from( 'page' )
			->where( [ 'page_namespace' => NS_SUBOBJECT ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		foreach ( $res as $row ) {
			$name = str_replace( '_', ' ', $row->page_title );

			$model = $this->readSubobject( $name );
			if ( $model ) {
				$out[$name] = $model;
			}
		}

		return $out;
	}
}
