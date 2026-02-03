<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit\Schema;

use InvalidArgumentException;
use MediaWiki\Extension\SemanticSchemas\Schema\PropertyModel;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Schema\PropertyModel
 */
class PropertyModelTest extends TestCase {

	/* =========================================================================
	 * CONSTRUCTOR VALIDATION
	 * ========================================================================= */

	public function testEmptyNameThrowsException(): void {
		$this->expectException( InvalidArgumentException::class );
		new PropertyModel( '', [ 'datatype' => 'Text' ] );
	}

	public function testMissingDatatypeThrowsException(): void {
		$this->expectException( InvalidArgumentException::class );
		new PropertyModel( 'Has test', [] );
	}

	public function testMinimalConstructor(): void {
		$p = new PropertyModel( 'Has test', [ 'datatype' => 'Text' ] );
		$this->assertSame( 'Has test', $p->getName() );
		$this->assertSame( 'Text', $p->getDatatype() );
	}

	/* =========================================================================
	 * INPUT TYPE
	 * ========================================================================= */

	public function testInputTypeIsNullByDefault(): void {
		$p = new PropertyModel( 'Has test', [ 'datatype' => 'Text' ] );
		$this->assertNull( $p->getInputType() );
	}

	public function testInputTypeSetFromConstructor(): void {
		$p = new PropertyModel( 'Has test', [
			'datatype' => 'Text',
			'inputType' => 'textarea',
		] );
		$this->assertSame( 'textarea', $p->getInputType() );
	}

	public function testInputTypeIsTrimmed(): void {
		$p = new PropertyModel( 'Has test', [
			'datatype' => 'Text',
			'inputType' => '  dropdown  ',
		] );
		$this->assertSame( 'dropdown', $p->getInputType() );
	}

	public function testInputTypeEmptyStringBecomesNull(): void {
		$p = new PropertyModel( 'Has test', [
			'datatype' => 'Text',
			'inputType' => '',
		] );
		$this->assertNull( $p->getInputType() );
	}

	public function testInputTypeWhitespaceOnlyBecomesNull(): void {
		$p = new PropertyModel( 'Has test', [
			'datatype' => 'Text',
			'inputType' => '   ',
		] );
		$this->assertNull( $p->getInputType() );
	}

	/* =========================================================================
	 * toArray()
	 * ========================================================================= */

	public function testToArrayIncludesInputTypeWhenSet(): void {
		$p = new PropertyModel( 'Has test', [
			'datatype' => 'Text',
			'inputType' => 'datepicker',
		] );
		$arr = $p->toArray();
		$this->assertArrayHasKey( 'inputType', $arr );
		$this->assertSame( 'datepicker', $arr['inputType'] );
	}

	public function testToArrayOmitsInputTypeWhenNull(): void {
		$p = new PropertyModel( 'Has test', [ 'datatype' => 'Text' ] );
		$arr = $p->toArray();
		$this->assertArrayNotHasKey( 'inputType', $arr );
	}

	public function testToArrayContainsExpectedKeys(): void {
		$p = new PropertyModel( 'Has test', [
			'datatype' => 'Text',
			'description' => 'desc',
		] );
		$arr = $p->toArray();
		$this->assertArrayHasKey( 'datatype', $arr );
		$this->assertArrayHasKey( 'label', $arr );
		$this->assertArrayHasKey( 'description', $arr );
	}
}
