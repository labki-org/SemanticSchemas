<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit\Schema;

use InvalidArgumentException;
use MediaWiki\Extension\SemanticSchemas\Schema\FieldModel;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Schema\FieldModel
 */
class FieldModelTest extends TestCase {

	/* =========================================================================
	 * CONSTRUCTION
	 * ========================================================================= */

	public function testPropertyFactory(): void {
		$field = FieldModel::property( 'Has name', true );
		$this->assertSame( 'Has name', $field->getName() );
		$this->assertTrue( $field->isRequired() );
		$this->assertSame( FieldModel::TYPE_PROPERTY, $field->getFieldType() );
	}

	public function testSubobjectFactory(): void {
		$field = FieldModel::subobject( 'Author', false );
		$this->assertSame( 'Author', $field->getName() );
		$this->assertFalse( $field->isRequired() );
		$this->assertSame( FieldModel::TYPE_SUBOBJECT, $field->getFieldType() );
	}

	public function testInvalidFieldTypeThrowsException(): void {
		$this->expectException( InvalidArgumentException::class );
		new FieldModel( 'Foo', true, 'invalid' );
	}

	public function testFromAnnotatedArray(): void {
		$entries = [
			[ 'name' => 'Has name', 'required' => true ],
			[ 'name' => 'Has email', 'required' => false ],
		];
		$fields = FieldModel::fromAnnotatedArray( $entries, FieldModel::TYPE_PROPERTY );

		$this->assertCount( 2, $fields );
		$this->assertSame( 'Has name', $fields[0]->getName() );
		$this->assertTrue( $fields[0]->isRequired() );
		$this->assertSame( 'Has email', $fields[1]->getName() );
		$this->assertFalse( $fields[1]->isRequired() );
	}

	public function testFromAnnotatedArrayEmpty(): void {
		$fields = FieldModel::fromAnnotatedArray( [], FieldModel::TYPE_PROPERTY );
		$this->assertSame( [], $fields );
	}

	/* =========================================================================
	 * toWikitext — PROPERTY FIELDS
	 * ========================================================================= */

	public function testToWikitextPropertyField(): void {
		$field = FieldModel::property( 'Has name', true );
		$wikitext = $field->toWikitext( 1 );

		$expected = implode( "\n", [
			'{{#subobject:',
			' |@category=Property field',
			' | For property = Property:Has name',
			' | Is required = true',
			' | Has sort order = 1',
			'}}',
		] );

		$this->assertSame( $expected, $wikitext );
	}

	public function testToWikitextOptionalPropertyField(): void {
		$field = FieldModel::property( 'Has email', false );
		$wikitext = $field->toWikitext( 2 );

		$expected = implode( "\n", [
			'{{#subobject:',
			' |@category=Property field',
			' | For property = Property:Has email',
			' | Is required = false',
			' | Has sort order = 2',
			'}}',
		] );

		$this->assertSame( $expected, $wikitext );
	}

	/* =========================================================================
	 * toWikitext — SUBOBJECT FIELDS
	 * ========================================================================= */

	public function testToWikitextSubobjectField(): void {
		$field = FieldModel::subobject( 'Author', true );
		$wikitext = $field->toWikitext( 1 );

		$expected = implode( "\n", [
			'{{#subobject:',
			' |@category=Subobject field',
			' | For category = Category:Author',
			' | Is required = true',
			' | Has sort order = 1',
			'}}',
		] );

		$this->assertSame( $expected, $wikitext );
	}

	/* =========================================================================
	 * toWikitextAll — BATCH SERIALIZATION
	 * ========================================================================= */

	public function testToWikitextAllMultipleFields(): void {
		$fields = [
			FieldModel::property( 'Has name', true ),
			FieldModel::property( 'Has email', false ),
		];

		$wikitext = FieldModel::toWikitextAll( $fields );

		$this->assertStringContainsString( 'For property = Property:Has name', $wikitext );
		$this->assertStringContainsString( 'Is required = true', $wikitext );
		$this->assertStringContainsString( 'Has sort order = 1', $wikitext );

		$this->assertStringContainsString( 'For property = Property:Has email', $wikitext );
		$this->assertStringContainsString( 'Is required = false', $wikitext );
		$this->assertStringContainsString( 'Has sort order = 2', $wikitext );
	}

	public function testToWikitextAllSequentialSortOrder(): void {
		$fields = [
			FieldModel::property( 'A', true ),
			FieldModel::property( 'B', false ),
			FieldModel::property( 'C', true ),
		];

		$wikitext = FieldModel::toWikitextAll( $fields );

		$this->assertStringContainsString( 'Has sort order = 1', $wikitext );
		$this->assertStringContainsString( 'Has sort order = 2', $wikitext );
		$this->assertStringContainsString( 'Has sort order = 3', $wikitext );
	}

	public function testToWikitextAllEmpty(): void {
		$this->assertSame( '', FieldModel::toWikitextAll( [] ) );
	}

	/* =========================================================================
	 * toWikitext — COMPLETE BLOCK INTEGRITY
	 *
	 * Each subobject block must be self-contained: the reference and required
	 * flag must appear within the same {{#subobject:...}} declaration.
	 * ========================================================================= */

	public function testToWikitextBlockContainsAllFieldsForProperty(): void {
		$field = FieldModel::property( 'Has name', true );
		$block = $field->toWikitext( 1 );

		// All lines must be within a single subobject block
		$this->assertStringStartsWith( '{{#subobject:', $block );
		$this->assertStringEndsWith( '}}', $block );

		// The block must contain the category, reference, required flag, and sort order
		$this->assertStringContainsString( '@category=Property field', $block );
		$this->assertStringContainsString( 'For property = Property:Has name', $block );
		$this->assertStringContainsString( 'Is required = true', $block );
		$this->assertStringContainsString( 'Has sort order = 1', $block );
	}

	public function testToWikitextBlockContainsAllFieldsForSubobject(): void {
		$field = FieldModel::subobject( 'Funding', false );
		$block = $field->toWikitext( 3 );

		$this->assertStringStartsWith( '{{#subobject:', $block );
		$this->assertStringEndsWith( '}}', $block );

		$this->assertStringContainsString( '@category=Subobject field', $block );
		$this->assertStringContainsString( 'For category = Category:Funding', $block );
		$this->assertStringContainsString( 'Is required = false', $block );
		$this->assertStringContainsString( 'Has sort order = 3', $block );
	}
}
