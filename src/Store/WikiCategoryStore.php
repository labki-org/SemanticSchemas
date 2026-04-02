<?php

namespace MediaWiki\Extension\SemanticSchemas\Store;

use MediaWiki\Config\Config;
use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Util\Constants;
use MediaWiki\Extension\SemanticSchemas\Util\SMWDataExtractor;
use MediaWiki\MainConfigNames;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\RawSQLExpression;
use Wikimedia\Rdbms\Subquery;

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
	private Config $mainConfig;

	public function __construct(
		PageCreator $pageCreator,
		WikiPropertyStore $propertyStore,
		IConnectionProvider $connectionProvider,
		Config $mainConfig
	) {
		$this->pageCreator = $pageCreator;
		$this->propertyStore = $propertyStore;
		$this->connectionProvider = $connectionProvider;
		$this->mainConfig = $mainConfig;
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

		if ( !str_contains( $newContent, '[[Category:' . Constants::SEMANTICSCHEMAS_MANAGED_CATEGORY . ']]' ) ) {
			$newContent .= "\n[[Category:" . Constants::SEMANTICSCHEMAS_MANAGED_CATEGORY . ']]';
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

	/**
	 * Get the names of the parent categories for the given page that are managed by semantic schemas
	 * (i.e., the categories are in the SemanticSchemas-managed category)
	 *
	 * Use a SQL query directly rather than our store models
	 * because we use this in the navigation hooks, which are an extremely perf-sensitive area -
	 * they run on every page load.
	 *
	 * This is a bit complicated by compatibility between mediawiki versions,
	 * as the categorylinks table schema changed in 1.44 to use an additional linktargets table
	 *
	 * A Raw expression is used here because mediawiki's db adapter doesn't support the IN operator,
	 * but it doesn't pose an SQLi risk because there is no user input in the query
	 * (the category names are not in the query, but data in the subquery)
	 *
	 * @param Title $title
	 * @return string[]
	 */
	public function getManagedParents( Title $title ): array {
		$dbr = $this->connectionProvider->getReplicaDatabase();
		$data = [];
		$titleKey = $title->getArticleID();

		if ( $titleKey === 0 ) {
			return $data;
		}

		// Detect which version of the query to use
		$mwMinor = (int)explode( '.', MW_VERSION )[1];
		$oldQuery = true;
		if ( $mwMinor >= 44 ) {
			$migrationStage = $this->mainConfig->get(
				MainConfigNames::CategoryLinksSchemaMigrationStage
			);
			$oldQuery = $migrationStage & SCHEMA_COMPAT_READ_OLD;
		}

		$subQuery = $dbr->newSelectQueryBuilder()
			->select( 'page_title' )
			->from( 'categorylinks' )
			->caller( __METHOD__ );

		if ( $oldQuery ) {
			// Pre-1.44 version
			$subQuery->join( 'page', null, 'page_id=cl_from' )
				->where( [
					'cl_to' => Constants::SEMANTICSCHEMAS_MANAGED_CATEGORY,
					'page_namespace' => NS_CATEGORY,
					]
				);
			$query = $dbr->newSelectQueryBuilder()
				->field( 'cl_to', 'category_title' )
				->from( 'categorylinks' )
				->where( [
					'cl_from' => $titleKey,
					new RawSQLExpression( 'cl_to IN' . new Subquery( $subQuery->getSQL() ) )
				] );
		} else {
			// Post-1.44 version
			$subQuery->join( 'linktarget', null, 'cl_target_id=lt_id' )
				->join( 'page', null, 'page_id=cl_from' )
				->where( [
					'lt_title' => Constants::SEMANTICSCHEMAS_MANAGED_CATEGORY,
					'page_namespace' => NS_CATEGORY
				] );
			$query = $dbr->newSelectQueryBuilder()
				->field( 'lt_title', 'category_title' )
				->from( 'categorylinks' )
				->join( 'linktarget', null, 'cl_target_id=lt_id' )
				->where( [
					'cl_from' => $titleKey,
					'lt_namespace' => NS_CATEGORY,
					new RawSQLExpression( 'lt_title IN' . new Subquery( $subQuery->getSQL() ) )
				] )
				->caller( __METHOD__ );
		}

		$res = $query->fetchResultSet();
		if ( $res->numRows() > 0 ) {
			foreach ( $res as $row ) {
				$data[] = $row->category_title;
			}
		}
		return $data;
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
		$header = $this->smwFetchMany( $semanticData, 'Has display header property', 'property' );
		$sections = $this->fetchDisplaySections( $semanticData );
		$format = $this->smwFetchOne( $semanticData, 'Has display format' );
		$templateProp = $this->smwFetchOne( $semanticData, 'Has display template', 'property' );

		$out = [];
		if ( $header !== [] ) {
			$out['header'] = $header;
		}
		if ( $sections !== [] ) {
			$out['sections'] = $sections;
		}
		if ( $format !== null ) {
			$out['format'] = strtolower( $format );
		}
		if ( $templateProp !== null ) {
			$out['templateProperty'] = $templateProp;
		}

		return $out;
	}

	private function fetchDisplaySections( $semanticData ): array {
		$sections = [];

		foreach ( $semanticData->getSubSemanticData() as $subSD ) {

			$name = $subSD->getSubject()->getSubobjectName();
			if ( !str_starts_with( $name, 'display_section_' ) ) {
				continue;
			}

			$secName = $this->smwFetchOne( $subSD, 'Has display section name' );
			$props = $this->smwFetchMany( $subSD, 'Has display section property', 'property' );

			if ( $secName !== null && $props !== [] ) {
				$sections[] = [
					'name' => $secName,
					'properties' => $props,
				];
			}
		}

		return $sections;
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

		// Header properties
		foreach ( $cat->getDisplayHeaderProperties() as $h ) {
			$lines[] = "[[Has display header property::Property:$h]]";
		}

		// Display format (Legacy)
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

		// Display sections
		foreach ( $cat->getDisplaySections() as $i => $sec ) {
			$lines[] = "{{#subobject:display_section_$i";
			$lines[] = "|Has display section name=" . $sec['name'];
			foreach ( $sec['properties'] as $p ) {
				$lines[] = "|Has display section property=Property:$p";
			}
			$lines[] = "}}";
		}

		return implode( "\n", $lines );
	}
}
