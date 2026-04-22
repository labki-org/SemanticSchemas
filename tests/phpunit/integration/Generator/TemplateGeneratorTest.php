<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Integration\Generator;

use MediaWiki\Extension\SemanticSchemas\Generator\TemplateGenerator;
use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\InheritanceResolver;
use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;
use MediaWiki\Language\Language;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Generator\TemplateGenerator
 */
class TemplateGeneratorTest extends MediaWikiIntegrationTestCase {
	private TemplateGenerator $generator;

	protected function setUp(): void {
		parent::setUp();

		$language = $this->createMock( Language::class );
		$language->method( 'getFormattedNsText' )
			->willReturn( 'Category' );

		$this->generator = new TemplateGenerator(
			$this->createMock( PageCreator::class ),
			$this->createMock( WikiPropertyStore::class ),
			$language
		);
	}

	public function testGenerateAllTemplatesSuccessWhenNoErrors(): void {
		$category = new CategoryModel( 'Person' );
		$resolver = new InheritanceResolver( [ 'Person' => $category ] );
		$result = $this->generator->generateAllTemplates( $category, $resolver );

		$this->assertTrue( $result['success'] );
		$this->assertEmpty( $result['errors'] );
	}
}
