<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit\Generator;

use MediaWiki\Extension\SemanticSchemas\Generator\PropertyInputMapper;
use MediaWiki\Extension\SemanticSchemas\Schema\PropertyModel;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Generator\PropertyInputMapper
 */
class PropertyInputMapperTest extends TestCase {

	private PropertyInputMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = new PropertyInputMapper();
	}

	/* =========================================================================
	 * EXPLICIT INPUT TYPE OVERRIDE
	 * ========================================================================= */

	public function testExplicitInputTypeOverridesMultipleValues(): void {
		$p = new PropertyModel( 'Has test', [
			'datatype' => 'Text',
			'allowsMultipleValues' => true,
			'inputType' => 'listbox',
		] );
		$this->assertSame( 'listbox', $this->mapper->getInputType( $p ) );
	}

	public function testExplicitInputTypeOverridesEnum(): void {
		$p = new PropertyModel( 'Has test', [
			'datatype' => 'Text',
			'allowedValues' => [ 'A', 'B', 'C' ],
			'inputType' => 'radiobutton',
		] );
		$this->assertSame( 'radiobutton', $this->mapper->getInputType( $p ) );
	}

	public function testExplicitInputTypeOverridesPageType(): void {
		$p = new PropertyModel( 'Has test', [
			'datatype' => 'Page',
			'inputType' => 'tree',
		] );
		$this->assertSame( 'tree', $this->mapper->getInputType( $p ) );
	}

	public function testExplicitInputTypeOverridesDatatypeDefault(): void {
		$p = new PropertyModel( 'Has test', [
			'datatype' => 'Date',
			'inputType' => 'text',
		] );
		$this->assertSame( 'text', $this->mapper->getInputType( $p ) );
	}

	/* =========================================================================
	 * NULL INPUT TYPE FALLS THROUGH TO CASCADE
	 * ========================================================================= */

	public function testNullInputTypeFallsToMultipleValues(): void {
		$p = new PropertyModel( 'Has test', [
			'datatype' => 'Text',
			'allowsMultipleValues' => true,
		] );
		$this->assertSame( 'tokens', $this->mapper->getInputType( $p ) );
	}

	public function testNullInputTypeFallsToEnum(): void {
		$p = new PropertyModel( 'Has test', [
			'datatype' => 'Text',
			'allowedValues' => [ 'X', 'Y' ],
		] );
		$this->assertSame( 'dropdown', $this->mapper->getInputType( $p ) );
	}

	public function testNullInputTypeFallsToPageType(): void {
		$p = new PropertyModel( 'Has test', [
			'datatype' => 'Page',
		] );
		$this->assertSame( 'combobox', $this->mapper->getInputType( $p ) );
	}

	public function testNullInputTypeFallsToDatatypeDefault(): void {
		$p = new PropertyModel( 'Has test', [
			'datatype' => 'Boolean',
		] );
		$this->assertSame( 'checkbox', $this->mapper->getInputType( $p ) );
	}

	public function testNullInputTypeFallsToTextForPlainText(): void {
		$p = new PropertyModel( 'Has test', [
			'datatype' => 'Text',
		] );
		$this->assertSame( 'text', $this->mapper->getInputType( $p ) );
	}
}
