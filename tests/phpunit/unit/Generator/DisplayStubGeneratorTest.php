<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit\Generator;

use MediaWiki\Extension\SemanticSchemas\Generator\DisplayStubGenerator;
use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;
use MediaWiki\Language\Language;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Generator\DisplayStubGenerator
 */
class DisplayStubGeneratorTest extends TestCase {
	private DisplayStubGenerator $generator;

	protected function setUp(): void {
		parent::setUp();
		$pageCreator = $this->createMock( PageCreator::class );
		$pageCreator->method( 'pageExists' )
			->willReturn( false );
		$propertyStore = $this->createMock( WikiPropertyStore::class );
		$language = $this->createMock( Language::class );
		$language->method( 'getFormattedNsText' )
			->willReturn( "Category" );

		$this->generator = new DisplayStubGenerator( $pageCreator, $propertyStore, $language );
	}

	public function testNormalCategoryIncludesCategoryTag() {
		$cat = new CategoryModel( "TestCategory" );
		$generated = $this->generator->generateWikitext( $cat );
		$this->assertStringContainsString( '[[Category:TestCategory]]', $generated );
	}

	public function testCategoryCategoryExcludesCategoryTag() {
		$cat = new CategoryModel( "Category" );
		$generated = $this->generator->generateWikitext( $cat );
		$this->assertStringNotContainsString( '[[Category:Category]]', $generated );
	}
}
