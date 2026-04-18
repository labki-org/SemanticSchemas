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

	public function testCreateProperty(): void {
		$field = new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY );
		$this->assertSame( 'Has name', $field->getName() );
		$this->assertTrue( $field->isRequired() );
		$this->assertSame( FieldModel::TYPE_PROPERTY, $field->getFieldType() );
	}

	public function testCreateSubobject(): void {
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
}
