<?php

namespace MediaWiki\Extension\SemanticSchemas\Store;

use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Util\SMWDataExtractor;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * WikiCategoryStore
 * -----------------
 * Reads/writes Category pages as CategoryModel objects.
 *
 * Fully symmetric with CategoryModel->toArray() structure.
 */
class WikiCategoryStore {

	use SMWDataExtractor;

	private const MARKER_START = '<!-- SemanticSchemas Start -->';
	private const MARKER_END = '<!-- SemanticSchemas End -->';

	private PageCreator $pageCreator;
	private WikiPropertyStore $propertyStore;
	private IConnectionProvider $connectionProvider;

	public function __construct(
		PageCreator $pageCreator,
		WikiPropertyStore $propertyStore,
		IConnectionProvider $connectionProvider
	) {
		$this->pageCreator = $pageCreator;
		$this->propertyStore = $propertyStore;
		$this->connectionProvider = $connectionProvider;
	}

	/* -------------------------------------------------------------------------
	 * READ PUBLIC
	 * ------------------------------------------------------------------------- */

	public function readCategory( string $categoryName ): ?CategoryModel {
		$title = $this->pageCreator->makeTitle( $categoryName, NS_CATEGORY );
		if ( !$title || !$this->pageCreator->pageExists( $title ) ) {
			return null;
		}

		$data = $this->loadFromSMW( $title, $categoryName );
		$cat = new CategoryModel( $categoryName, $data );

		// Resolve display template
		if ( !empty( $data['display']['templateProperty'] ) ) {
			$p = $this->propertyStore->readProperty( $data['display']['templateProperty'] );
			if ( $p ) {
				$cat->setDisplayTemplateProperty( $p );
			}
		}

		return $cat;
	}

	/* -------------------------------------------------------------------------
	 * WRITE PUBLIC
	 * ------------------------------------------------------------------------- */

	public function writeCategory( CategoryModel $category ): bool {
		$title = $this->pageCreator->makeTitle( $category->getName(), NS_CATEGORY );
		if ( !$title ) {
			return false;
		}

		$existing = $this->pageCreator->getPageContent( $title ) ?? '';

		$metadata = $this->generateSemanticBlock( $category );

		$newContent = $this->pageCreator->updateWithinMarkers(
			$existing,
			$metadata,
			self::MARKER_START,
			self::MARKER_END
		);

		if ( !str_contains( $newContent, '[[Category:SemanticSchemas-managed]]' ) ) {
			$newContent .= "\n[[Category:SemanticSchemas-managed]]";
		}

		return $this->pageCreator->createOrUpdatePage(
			$title,
			$newContent,
			'SemanticSchemas: Update category schema metadata'
		);
	}

	/* -------------------------------------------------------------------------
	 * ENUMERATION
	 * ------------------------------------------------------------------------- */

	public function getAllCategories(): array {
		$out = [];

		$dbr = $this->connectionProvider->getReplicaDatabase();

		$res = $dbr->newSelectQueryBuilder()
			->select( 'page_title' )
			->from( 'page' )
			->where( [ 'page_namespace' => NS_CATEGORY ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		foreach ( $res as $row ) {
			$name = str_replace( '_', ' ', $row->page_title );
			$cat = $this->readCategory( $name );
			if ( $cat ) {
				$out[$name] = $cat;
			}
		}

		return $out;
	}

	/* -------------------------------------------------------------------------
	 * SMW LOADING
	 * ------------------------------------------------------------------------- */

	private function loadFromSMW( Title $title, string $categoryName ): array {
		$store = \SMW\StoreFactory::getStore();
		$subject = \SMW\DIWikiPage::newFromTitle( $title );
		$sdata = $store->getSemanticData( $subject );

		return [
			'label' => $this->smwFetchOne( $sdata, 'Display label' ) ?? $categoryName,
			'description' => $this->smwFetchOne( $sdata, 'Has description' ) ?? '',
			'targetNamespace' => $this->smwFetchOne( $sdata, 'Has target namespace' ) ?? null,

			'parents' => $this->smwFetchMany( $sdata, 'Has parent category', 'category' ),

			'properties' => [
				'required' => $this->smwFetchMany( $sdata, 'Has required property', 'property' ),
				'optional' => $this->smwFetchMany( $sdata, 'Has optional property', 'property' ),
			],

			'subobjects' => [
				'required' => $this->smwFetchMany( $sdata, 'Has required subobject', 'subobject' ),
				'optional' => $this->smwFetchMany( $sdata, 'Has optional subobject', 'subobject' ),
			],

			'display' => $this->loadDisplayConfig( $sdata ),
		];
	}

	private function loadDisplayConfig( $semanticData ): array {
		$format = $this->smwFetchOne( $semanticData, 'Has display format' );
		$templateProp = $this->smwFetchOne( $semanticData, 'Has display template', 'property' );

		$out = [];
		if ( $format !== null ) {
			$out['format'] = strtolower( $format );
		}
		if ( $templateProp !== null ) {
			$out['templateProperty'] = $templateProp;
		}

		return $out;
	}

	/* -------------------------------------------------------------------------
	 * WRITE: semantic block
	 * ------------------------------------------------------------------------- */

	private function generateSemanticBlock( CategoryModel $cat ): string {
		$lines = [];

		if ( $cat->getDescription() !== '' ) {
			$lines[] = '[[Has description::' . $cat->getDescription() . ']]';
		}

		if ( $cat->getTargetNamespace() !== null ) {
			$lines[] = '[[Has target namespace::' . $cat->getTargetNamespace() . ']]';
		}

		// Parent categories
		foreach ( $cat->getParents() as $p ) {
			$lines[] = "[[Has parent category::Category:$p]]";
		}

		// Display format
		if ( $cat->getDisplayFormat() !== null ) {
			$lines[] = '[[Has display format::' . $cat->getDisplayFormat() . ']]';
		}

		// Display template
		if ( $cat->getDisplayTemplateProperty() !== null ) {
			$lines[] = '[[Has display template::' . $cat->getDisplayTemplateProperty()->getName() . ']]';
		}

		// Required/optional properties
		foreach ( $cat->getRequiredProperties() as $prop ) {
			$lines[] = "[[Has required property::Property:$prop]]";
		}

		foreach ( $cat->getOptionalProperties() as $prop ) {
			$lines[] = "[[Has optional property::Property:$prop]]";
		}

		// Subobjects
		foreach ( $cat->getRequiredSubobjects() as $sg ) {
			$lines[] = "[[Has required subobject::Subobject:$sg]]";
		}

		foreach ( $cat->getOptionalSubobjects() as $sg ) {
			$lines[] = "[[Has optional subobject::Subobject:$sg]]";
		}

		return implode( "\n", $lines );
	}
}
