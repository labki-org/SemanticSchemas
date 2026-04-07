<?php

namespace MediaWiki\Extension\SemanticSchemas\Store;

use MediaWiki\Extension\SemanticSchemas\Schema\FieldDeclaration;
use MediaWiki\Extension\SemanticSchemas\Schema\SubobjectModel;
use MediaWiki\Extension\SemanticSchemas\Util\SMWDataExtractor;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IConnectionProvider;

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
	private IConnectionProvider $connectionProvider;

	public function __construct(
		PageCreator $pageCreator,
		IConnectionProvider $connectionProvider
	) {
		$this->pageCreator = $pageCreator;
		$this->connectionProvider = $connectionProvider;
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

		// Property fields (subobject-based with Is required flag)
		$out['properties'] = $this->smwFetchTaggedFieldReferences(
			$sdata, 'Has property field', 'Has property reference', 'property'
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

		$propWikitext = FieldDeclaration::toWikitextAll( $s->getPropertyFields() );
		if ( $propWikitext !== '' ) {
			$lines[] = $propWikitext;
		}

		return implode( "\n", $lines );
	}

	/* -------------------------------------------------------------------------
	 * LISTING
	 * ------------------------------------------------------------------------- */

	public function getAllSubobjects(): array {
		$out = [];

		$dbr = $this->connectionProvider->getReplicaDatabase();

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
