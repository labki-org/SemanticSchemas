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
		$field = new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY );
		$this->assertSame( 'Has name', $field->getName() );
		$this->assertTrue( $field->isRequired() );
		$this->assertSame( FieldModel::TYPE_PROPERTY, $field->getFieldType() );
	}

	public function testSubobjectFactory(): void {
		$field = new FieldModel( 'Author', false, FieldModel::TYPE_SUBOBJECT );
		$this->assertSame( 'Author', $field->getName() );
		$this->assertFalse( $field->isRequired() );
		$this->assertSame( FieldModel::TYPE_SUBOBJECT, $field->getFieldType() );
	}

	public function testInvalidFieldTypeThrowsException(): void {
		$this->expectException( InvalidArgumentException::class );
		new FieldModel( 'Foo', true, 'invalid' );
	}

	/* =========================================================================
	 * ACCESSORS
	 * ========================================================================= */

	public function testGetParameterName(): void {
		$field = new FieldModel( 'Has full name', true, FieldModel::TYPE_PROPERTY );
		$this->assertSame( 'has_full_name', $field->getParameterName() );
	}

	public function testJsonSerialize(): void {
		$field = new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY );
		$this->assertSame(
			[ 'name' => 'Has name', 'required' => true ],
			$field->jsonSerialize()
		);
	}

	/* =========================================================================
	 * filter()
	 * ========================================================================= */

	public function testFilterWithNoParametersReturnsAll(): void {
		$fields = [
			new FieldModel( 'A', true, FieldModel::TYPE_PROPERTY ),
			new FieldModel( 'B', false, FieldModel::TYPE_PROPERTY ),
		];
		$this->assertCount( 2, FieldModel::filter( $fields ) );
	}

	public function testFilterByRequired(): void {
		$fields = [
			new FieldModel( 'A', true, FieldModel::TYPE_PROPERTY ),
			new FieldModel( 'B', false, FieldModel::TYPE_PROPERTY ),
			new FieldModel( 'C', true, FieldModel::TYPE_PROPERTY ),
		];
		$required = FieldModel::filter( $fields, required: true );
		$this->assertCount( 2, $required );
		$this->assertSame( 'A', $required[0]->getName() );
		$this->assertSame( 'C', $required[1]->getName() );
	}

	/* =========================================================================
	 * toWikitext — PROPERTY FIELDS
	 * ========================================================================= */

	public function testToWikitextPropertyField(): void {
		$field = new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY );
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
		$field = new FieldModel( 'Has email', false, FieldModel::TYPE_PROPERTY );
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
		$field = new FieldModel( 'Author', true, FieldModel::TYPE_SUBOBJECT );
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
			new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY ),
			new FieldModel( 'Has email', false, FieldModel::TYPE_PROPERTY ),
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
			new FieldModel( 'A', true, FieldModel::TYPE_PROPERTY ),
			new FieldModel( 'B', false, FieldModel::TYPE_PROPERTY ),
			new FieldModel( 'C', true, FieldModel::TYPE_PROPERTY ),
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
		$field = new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY );
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
		$field = new FieldModel( 'Funding', false, FieldModel::TYPE_SUBOBJECT );
		$block = $field->toWikitext( 3 );

		$this->assertStringStartsWith( '{{#subobject:', $block );
		$this->assertStringEndsWith( '}}', $block );

		$this->assertStringContainsString( '@category=Subobject field', $block );
		$this->assertStringContainsString( 'For category = Category:Funding', $block );
		$this->assertStringContainsString( 'Is required = false', $block );
		$this->assertStringContainsString( 'Has sort order = 3', $block );
	}
}
