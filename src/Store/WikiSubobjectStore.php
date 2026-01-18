<?php

namespace MediaWiki\Extension\SemanticSchemas\Store;

use MediaWiki\Extension\SemanticSchemas\Schema\SubobjectModel;
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

	private const MARKER_START = '<!-- SemanticSchemas Start -->';
	private const MARKER_END   = '<!-- SemanticSchemas End -->';

	private PageCreator $pageCreator;

	public function __construct( PageCreator $pageCreator = null ) {
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
		$label = $this->fetchOne( $sdata, 'Display label', 'text' );
		if ( $label !== null && $label !== '' ) {
			$out['label'] = $label;
		}

		// Description
		$desc = $this->fetchOne( $sdata, 'Has description', 'text' );
		if ( $desc !== null ) {
			$out['description'] = $desc;
		}

		// Required properties
		$out['properties']['required'] = $this->fetchMany(
			$sdata,
			'Has required property',
			'property'
		);

		// Optional properties
		$out['properties']['optional'] = $this->fetchMany(
			$sdata,
			'Has optional property',
			'property'
		);

		return $out;
	}

	private function fetchOne( $semanticData, string $label, string $type ): ?string {
		$vals = $this->fetchMany( $semanticData, $label, $type );
		return $vals[0] ?? null;
	}

	private function fetchMany( $semanticData, string $label, string $type ): array {
		try {
			$prop = \SMW\DIProperty::newFromUserLabel( $label );
			$items = $semanticData->getPropertyValues( $prop );
		} catch ( \Throwable $e ) {
			return [];
		}

		$out = [];

		foreach ( $items as $di ) {
			$v = $this->extractValue( $di, $type );
			if ( $v !== null ) {
				$out[] = $v;
			}
		}

		return $out;
	}

	private function extractValue( $di, string $type ): ?string {
		if ( $di instanceof \SMW\DIWikiPage ) {
			$t = $di->getTitle();
			if ( !$t ) {
				return null;
			}

			$text = str_replace( '_', ' ', $t->getText() );

			return match ( $type ) {
				'property' =>
					$t->getNamespace() === SMW_NS_PROPERTY ? $text : null,

				'subobject' =>
					$t->getNamespace() === NS_SUBOBJECT ? $text : null,

				default => $text,
			};
		}

		if ( $di instanceof \SMWDIBlob || $di instanceof \SMWDIString ) {
			return trim( $di->getString() );
		}

		return null;
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

	public function subobjectExists( string $name ): bool {
		$title = $this->pageCreator->makeTitle( $name, NS_SUBOBJECT );
		return $title && $this->pageCreator->pageExists( $title );
	}
}
