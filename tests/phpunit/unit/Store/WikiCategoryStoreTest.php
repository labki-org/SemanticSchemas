<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit\Store;

use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;
use MediaWiki\Title\Title;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore
 */
class WikiCategoryStoreTest extends TestCase {
	private Title $mockTitle;
	private PageCreator $mockCreator;
	private WikiCategoryStore $store;

	public function setUp(): void {
		parent::setUp();
		$this->mockTitle = $this->createMock( Title::class );
		$this->mockCreator = $this->createMock( PageCreator::class );
		$this->mockCreator->method( 'makeTitle' )->willReturn( $this->mockTitle );
		$this->mockCreator->method( 'getPageContent' )->willReturn( '' );
		$this->mockCreator->method( 'updateWithinMarkers' )->willReturnArgument( 1 );

		$this->store = new WikiCategoryStore(
			$this->mockCreator,
			$this->createMock( WikiPropertyStore::class ),
			$this->getMockBuilder( "Wikimedia\Rdbms\IConnectionProvider" )->getMock(),
			$this->getMockBuilder( "MediaWiki\Config\Config" )->getMock()
		);
	}

	public function testParentsRenderedAsCategories(): void {
		$cat = new CategoryModel( "TestCategory", [ 'parents' => [ 'ParentCategory' ] ] );
		$this->mockCreator
			->expects( self::once() )
			->method( 'createOrUpdatePage' )
			->with( $this->mockTitle,
				$this->stringContains( '[[Category:ParentCategory]]' ),
				'SemanticSchemas: Update category schema metadata' );
		$this->store->writeCategory( $cat );
	}
}
