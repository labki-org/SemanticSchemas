<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit\Schema;

use InvalidArgumentException;
use MediaWiki\Extension\SemanticSchemas\Schema\SubobjectModel;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Schema\SubobjectModel
 */
class SubobjectModelTest extends TestCase {

	/* =========================================================================
	 * CONSTRUCTOR VALIDATION
	 * ========================================================================= */

	public function testEmptyNameThrowsException(): void {
		$this->expectException( InvalidArgumentException::class );
		new SubobjectModel( '' );
	}

	public function testInvalidCharactersInNameThrowsException(): void {
		$this->expectException( InvalidArgumentException::class );
		new SubobjectModel( 'Bad<Name>' );
	}

	public function testDuplicateRequiredAndOptionalThrowsException(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'both required and optional' );
		new SubobjectModel( 'Test', [
			'properties' => [
				'required' => [ 'Has name' ],
				'optional' => [ 'Has name' ],
			],
		] );
	}

	/* =========================================================================
	 * BASIC ACCESSORS
	 * ========================================================================= */

	public function testDefaultValues(): void {
		$model = new SubobjectModel( 'Contact' );
		$this->assertSame( 'Contact', $model->getName() );
		$this->assertSame( 'Contact', $model->getLabel() );
		$this->assertSame( '', $model->getDescription() );
		$this->assertSame( [], $model->getRequiredProperties() );
		$this->assertSame( [], $model->getOptionalProperties() );
	}

	public function testCustomLabelAndDescription(): void {
		$model = new SubobjectModel( 'Contact Info', [
			'label' => 'Contact',
			'description' => 'Contact details',
		] );
		$this->assertSame( 'Contact', $model->getLabel() );
		$this->assertSame( 'Contact details', $model->getDescription() );
	}

	public function testRequiredAndOptionalProperties(): void {
		$model = new SubobjectModel( 'Address', [
			'properties' => [
				'required' => [ 'Has street', 'Has city' ],
				'optional' => [ 'Has zip' ],
			],
		] );
		$this->assertSame( [ 'Has street', 'Has city' ], $model->getRequiredProperties() );
		$this->assertSame( [ 'Has zip' ], $model->getOptionalProperties() );
		$this->assertSame( [ 'Has street', 'Has city', 'Has zip' ], $model->getAllProperties() );
		$this->assertTrue( $model->isPropertyRequired( 'Has street' ) );
		$this->assertFalse( $model->isPropertyRequired( 'Has zip' ) );
	}

	/* =========================================================================
	 * TAGGED PROPERTIES
	 * ========================================================================= */

	public function testGetTaggedProperties(): void {
		$model = new SubobjectModel( 'Address', [
			'properties' => [
				'required' => [ 'Has street' ],
				'optional' => [ 'Has zip' ],
			],
		] );
		$tagged = $model->getTaggedProperties();
		$this->assertCount( 2, $tagged );
		$this->assertSame( [ 'name' => 'Has street', 'required' => true ], $tagged[0] );
		$this->assertSame( [ 'name' => 'Has zip', 'required' => false ], $tagged[1] );
	}

	public function testGetTaggedPropertiesReturnsEmptyWhenNone(): void {
		$model = new SubobjectModel( 'Empty' );
		$this->assertSame( [], $model->getTaggedProperties() );
	}

	/* =========================================================================
	 * EXPORT
	 * ========================================================================= */

	public function testToArray(): void {
		$model = new SubobjectModel( 'Address', [
			'label' => 'Mailing Address',
			'description' => 'A physical address',
			'properties' => [
				'required' => [ 'Has street' ],
				'optional' => [ 'Has zip' ],
			],
		] );
		$arr = $model->toArray();
		$this->assertSame( 'Mailing Address', $arr['label'] );
		$this->assertSame( 'A physical address', $arr['description'] );
		$this->assertSame( [ 'Has street' ], $arr['properties']['required'] );
		$this->assertSame( [ 'Has zip' ], $arr['properties']['optional'] );
	}
}
