<?php

namespace MediaWiki\Extension\SemanticSchemas\Store;

use MediaWiki\Config\Config;
use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\FieldModel;
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

	private PageCreator $pageCreator;
	private IConnectionProvider $connectionProvider;
	private Config $mainConfig;

	public function __construct(
		PageCreator $pageCreator,
		IConnectionProvider $connectionProvider,
		Config $mainConfig
	) {
		$this->pageCreator = $pageCreator;
		$this->connectionProvider = $connectionProvider;
		$this->mainConfig = $mainConfig;
	}

	/* -------------------------------------------------------------------------
	 * READ PUBLIC
	 * ------------------------------------------------------------------------- */

	public function readCategory( string $categoryName ): ?CategoryModel {
		$title = $this->pageCreator->makeTitle( $categoryName, NS_CATEGORY );
		if ( !$title || !$title->exists() ) {
			return null;
		}

		$data = $this->loadFromSMW( $title, $categoryName );
		$cat = new CategoryModel( $categoryName, $data );

		return $cat;
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
			->where( [
				'page_namespace' => NS_CATEGORY,
				$dbr->expr( 'page_title', '!=', Constants::SEMANTICSCHEMAS_MANAGED_CATEGORY )
			] )
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

		# strip namespace prefix from parent categories
		$parents = array_map(
			static fn ( string $parentName ) => explode( ':', $parentName, 2 )[1],
			array_keys( $title->getParentCategories() )
		);

		$out = $this->smwLoadProperties( $sdata, CategoryModel::SMW_PROPERTIES );

		$out['label'] ??= $categoryName;
		$out['description'] ??= '';
		$out['parents'] = $parents;

		$out['properties'] = $this->smwFetchFieldReferences(
			$sdata, FieldModel::TYPE_PROPERTY
		);
		$out['subobjects'] = $this->smwFetchFieldReferences(
			$sdata, FieldModel::TYPE_SUBOBJECT
		);

		$out['display'] = $this->loadDisplayConfig( $sdata );

		return $out;
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
}
