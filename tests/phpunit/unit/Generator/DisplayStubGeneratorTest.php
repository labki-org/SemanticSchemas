<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit\Generator;

use MediaWiki\Extension\SemanticSchemas\Generator\DisplayStubGenerator;
use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\FieldModel;
use MediaWiki\Extension\SemanticSchemas\Schema\InheritanceResolver;
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
		$propertyStore = $this->createMock( WikiPropertyStore::class );
		$language = $this->createMock( Language::class );
		$language->method( 'getFormattedNsText' )
			->willReturn( "Category" );

		$this->generator = new DisplayStubGenerator( $pageCreator, $propertyStore, $language );
	}

	public function testDisplayTemplateDoesNotContainCategoryTags() {
		$cat = new CategoryModel( "TestCategory" );
		$generated = $this->generator->generateWikitext( $cat );
		// Category membership is declared in the dispatcher, not the display template
		$this->assertStringNotContainsString( '[[Category:TestCategory]]', $generated );
	}

	public function testSubobjectSectionsUseDisplayTemplate() {
		$address = new CategoryModel( 'Address', [
			'properties' => [
				new FieldModel( 'Has street', true, FieldModel::TYPE_PROPERTY ),
				new FieldModel( 'Has city', true, FieldModel::TYPE_PROPERTY ),
			],
		] );

		$person = new CategoryModel( 'Person', [
			'properties' => [
				new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY ),
			],
			'subobjects' => [
				new FieldModel( 'Address', true, FieldModel::TYPE_SUBOBJECT ),
			],
		] );

		$resolver = new InheritanceResolver( [
			'Person' => $person,
			'Address' => $address,
		] );

		$generated = $this->generator->generateWikitext( $person, $resolver );

		// #ask query for subobject display
		$this->assertStringContainsString( '[[-Has subobject::{{FULLPAGENAME}}]]', $generated );
		$this->assertStringContainsString( '[[Category:Address]]', $generated );
		// Aliased printouts matching display template parameter names
		$this->assertStringContainsString( '?Has street = has_street', $generated );
		$this->assertStringContainsString( '?Has city = has_city', $generated );
		// Uses the subobject's display template
		$this->assertStringContainsString( 'template=Address/display', $generated );
		$this->assertStringContainsString( 'named args=yes', $generated );
	}

	public function testSubobjectDisplayIncludesInheritedProperties() {
		$baseAddress = new CategoryModel( 'Address', [
			'properties' => [
				new FieldModel( 'Has street', true, FieldModel::TYPE_PROPERTY ),
				new FieldModel( 'Has city', true, FieldModel::TYPE_PROPERTY ),
			],
		] );

		$mailingAddress = new CategoryModel( 'MailingAddress', [
			'parents' => [ 'Address' ],
			'properties' => [
				new FieldModel( 'Has zip', true, FieldModel::TYPE_PROPERTY ),
			],
		] );

		$person = new CategoryModel( 'Person', [
			'properties' => [
				new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY ),
			],
			'subobjects' => [
				new FieldModel( 'MailingAddress', true, FieldModel::TYPE_SUBOBJECT ),
			],
		] );

		$resolver = new InheritanceResolver( [
			'Person' => $person,
			'Address' => $baseAddress,
			'MailingAddress' => $mailingAddress,
		] );

		$generated = $this->generator->generateWikitext( $person, $resolver );

		// Inherited properties from Address
		$this->assertStringContainsString( '?Has street', $generated );
		$this->assertStringContainsString( '?Has city', $generated );
		// Own property from MailingAddress
		$this->assertStringContainsString( '?Has zip', $generated );
	}

	public function testNoSubobjectSectionsWithoutResolver() {
		$person = new CategoryModel( 'Person', [
			'properties' => [
				new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY ),
			],
			'subobjects' => [
				new FieldModel( 'Address', true, FieldModel::TYPE_SUBOBJECT ),
			],
		] );

		$generated = $this->generator->generateWikitext( $person );

		$this->assertStringNotContainsString( '#ask', $generated );
		$this->assertStringNotContainsString( 'Address/display', $generated );
	}
}
