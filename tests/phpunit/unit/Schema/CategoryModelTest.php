<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit\Schema;

use InvalidArgumentException;
use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\EffectiveCategoryModel;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel
 */
class CategoryModelTest extends TestCase {

	/* =========================================================================
	 * CONSTRUCTOR VALIDATION
	 * ========================================================================= */

	public function testEmptyNameThrowsException(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'empty' );
		new CategoryModel( '' );
	}

	public function testWhitespaceOnlyNameThrowsException(): void {
		$this->expectException( InvalidArgumentException::class );
		new CategoryModel( '   ' );
	}

	public function testInvalidCharactersInNameThrowsException(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'invalid characters' );
		new CategoryModel( 'Category<with>invalid' );
	}

	public function testSelfParentThrowsException(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'own parent' );
		new CategoryModel( 'TestCategory', [
			'parents' => [ 'TestCategory' ],
		] );
	}

	public function testDuplicatePropertyThrowsException(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'duplicate field declaration' );
		new CategoryModel( 'TestCategory', [
			'properties' => [
				[ 'name' => 'Has name', 'required' => true ],
				[ 'name' => 'Has name', 'required' => false ],
			],
		] );
	}

	public function testNonArrayPropertiesThrowsException(): void {
		$this->expectException( InvalidArgumentException::class );
		new CategoryModel( 'TestCategory', [
			'properties' => 'not an array',
		] );
	}

	public function testDuplicateSubobjectThrowsException(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'duplicate field declaration' );
		new CategoryModel( 'TestCategory', [
			'subobjects' => [
				[ 'name' => 'Address', 'required' => true ],
				[ 'name' => 'Address', 'required' => false ],
			],
		] );
	}

	/* =========================================================================
	 * BASIC ACCESSORS
	 * ========================================================================= */

	public function testGetNameReturnsCorrectValue(): void {
		$model = new CategoryModel( 'TestCategory' );
		$this->assertEquals( 'TestCategory', $model->getName() );
	}

	public function testGetNameTrimmed(): void {
		$model = new CategoryModel( '  TestCategory  ' );
		$this->assertEquals( 'TestCategory', $model->getName() );
	}

	public function testGetLabelReturnsLabelWhenSet(): void {
		$model = new CategoryModel( 'TestCategory', [ 'label' => 'Test Label' ] );
		$this->assertEquals( 'Test Label', $model->getLabel() );
	}

	public function testGetLabelReturnsNameWhenNotSet(): void {
		$model = new CategoryModel( 'TestCategory' );
		$this->assertEquals( 'TestCategory', $model->getLabel() );
	}

	public function testGetDescriptionReturnsCorrectValue(): void {
		$model = new CategoryModel( 'TestCategory', [ 'description' => 'Test description' ] );
		$this->assertEquals( 'Test description', $model->getDescription() );
	}

	public function testGetDescriptionReturnsEmptyWhenNotSet(): void {
		$model = new CategoryModel( 'TestCategory' );
		$this->assertSame( '', $model->getDescription() );
	}

	public function testGetParentsReturnsCorrectValue(): void {
		$model = new CategoryModel( 'TestCategory', [ 'parents' => [ 'Parent1', 'Parent2' ] ] );
		$this->assertEquals( [ 'Parent1', 'Parent2' ], $model->getParents() );
	}

	public function testGetParentsReturnsEmptyArrayWhenNotSet(): void {
		$model = new CategoryModel( 'TestCategory' );
		$this->assertEquals( [], $model->getParents() );
	}

	public function testGetTargetNamespaceReturnsCorrectValue(): void {
		$model = new CategoryModel( 'TestCategory', [ 'targetNamespace' => 'Project' ] );
		$this->assertEquals( 'Project', $model->getTargetNamespace() );
	}

	public function testGetTargetNamespaceReturnsNullWhenNotSet(): void {
		$model = new CategoryModel( 'TestCategory' );
		$this->assertNull( $model->getTargetNamespace() );
	}

	/* =========================================================================
	 * PROPERTY ACCESSORS
	 * ========================================================================= */

	public function testGetRequiredProperties(): void {
		$model = new CategoryModel( 'TestCategory', [
			'properties' => [
				[ 'name' => 'Has name', 'required' => true ],
				[ 'name' => 'Has email', 'required' => true ],
			],
		] );
		$this->assertEquals( [ 'Has name', 'Has email' ], $model->getRequiredProperties() );
	}

	public function testGetOptionalProperties(): void {
		$model = new CategoryModel( 'TestCategory', [
			'properties' => [
				[ 'name' => 'Has phone', 'required' => false ],
				[ 'name' => 'Has address', 'required' => false ],
			],
		] );
		$this->assertEquals( [ 'Has phone', 'Has address' ], $model->getOptionalProperties() );
	}

	public function testGetAllPropertiesCombinesBoth(): void {
		$model = new CategoryModel( 'TestCategory', [
			'properties' => [
				[ 'name' => 'Has name', 'required' => true ],
				[ 'name' => 'Has email', 'required' => false ],
			],
		] );
		$allProps = $model->getAllProperties();
		$this->assertContains( 'Has name', $allProps );
		$this->assertContains( 'Has email', $allProps );
		$this->assertCount( 2, $allProps );
	}

	public function testGetAnnotatedPropertiesReturnsBothWithFlags(): void {
		$model = new CategoryModel( 'TestCategory', [
			'properties' => [
				[ 'name' => 'Has name', 'required' => true ],
				[ 'name' => 'Has email', 'required' => false ],
			],
		] );
		$annotated = $model->getAnnotatedProperties();
		$this->assertCount( 2, $annotated );
		$this->assertSame( [ 'name' => 'Has name', 'required' => true ], $annotated[0] );
		$this->assertSame( [ 'name' => 'Has email', 'required' => false ], $annotated[1] );
	}

	public function testGetAnnotatedPropertiesReturnsEmptyWhenNone(): void {
		$model = new CategoryModel( 'TestCategory' );
		$this->assertSame( [], $model->getAnnotatedProperties() );
	}

	/* =========================================================================
	 * SUBOBJECT ACCESSORS
	 * ========================================================================= */

	public function testGetRequiredSubobjects(): void {
		$model = new CategoryModel( 'TestCategory', [
			'subobjects' => [
				[ 'name' => 'Author', 'required' => true ],
			],
		] );
		$this->assertEquals( [ 'Author' ], $model->getRequiredSubobjects() );
	}

	public function testGetOptionalSubobjects(): void {
		$model = new CategoryModel( 'TestCategory', [
			'subobjects' => [
				[ 'name' => 'Funding', 'required' => false ],
			],
		] );
		$this->assertEquals( [ 'Funding' ], $model->getOptionalSubobjects() );
	}

	public function testHasSubobjectsReturnsTrue(): void {
		$model = new CategoryModel( 'TestCategory', [
			'subobjects' => [
				[ 'name' => 'Author', 'required' => true ],
			],
		] );
		$this->assertTrue( $model->hasSubobjects() );
	}

	public function testHasSubobjectsReturnsFalse(): void {
		$model = new CategoryModel( 'TestCategory' );
		$this->assertFalse( $model->hasSubobjects() );
	}

	public function testGetAnnotatedSubobjectsReturnsBothWithFlags(): void {
		$model = new CategoryModel( 'TestCategory', [
			'subobjects' => [
				[ 'name' => 'Author', 'required' => true ],
				[ 'name' => 'Funding', 'required' => false ],
			],
		] );
		$annotated = $model->getAnnotatedSubobjects();
		$this->assertCount( 2, $annotated );
		$this->assertSame( [ 'name' => 'Author', 'required' => true ], $annotated[0] );
		$this->assertSame( [ 'name' => 'Funding', 'required' => false ], $annotated[1] );
	}

	public function testGetAnnotatedSubobjectsReturnsEmptyWhenNone(): void {
		$model = new CategoryModel( 'TestCategory' );
		$this->assertSame( [], $model->getAnnotatedSubobjects() );
	}

	/* =========================================================================
	 * DISPLAY CONFIG
	 * ========================================================================= */

	public function testGetDisplayConfig(): void {
		$displayConfig = [
			'format' => 'sidebox',
		];
		$model = new CategoryModel( 'TestCategory', [ 'display' => $displayConfig ] );
		$this->assertEquals( $displayConfig, $model->getDisplayConfig() );
	}

	/* =========================================================================
	 * FORM CONFIG
	 * ========================================================================= */

	public function testGetFormConfig(): void {
		$formConfig = [
			'sections' => [
				[ 'name' => 'Basic', 'properties' => [ 'Has name' ] ],
			],
		];
		$model = new CategoryModel( 'TestCategory', [ 'forms' => $formConfig ] );
		$this->assertEquals( $formConfig, $model->getFormConfig() );
	}

	/* =========================================================================
	 * MERGING WITH PARENT
	 * ========================================================================= */

	public function testMergeWithParentInheritsProperties(): void {
		$parent = new CategoryModel( 'Parent', [
			'properties' => [
				[ 'name' => 'Has parent prop', 'required' => true ],
			],
		] );
		$child = new CategoryModel( 'Child', [
			'parents' => [ 'Parent' ],
			'properties' => [
				[ 'name' => 'Has child prop', 'required' => true ],
			],
		] );

		$merged = $child->mergeWithParent( $parent );
		$allProps = $merged->getAllProperties();
		$this->assertContains( 'Has parent prop', $allProps );
		$this->assertContains( 'Has child prop', $allProps );
	}

	public function testMergeWithParentPreservesChildName(): void {
		$parent = new CategoryModel( 'Parent' );
		$child = new CategoryModel( 'Child', [ 'parents' => [ 'Parent' ] ] );

		$merged = $child->mergeWithParent( $parent );
		$this->assertEquals( 'Child', $merged->getName() );
	}

	public function testMergeWithParentPromotesOptionalToRequired(): void {
		$parent = new CategoryModel( 'Parent', [
			'properties' => [
				[ 'name' => 'Has prop', 'required' => false ],
			],
		] );
		$child = new CategoryModel( 'Child', [
			'parents' => [ 'Parent' ],
			'properties' => [
				[ 'name' => 'Has prop', 'required' => true ],
			],
		] );

		$merged = $child->mergeWithParent( $parent );
		$this->assertContains( 'Has prop', $merged->getRequiredProperties() );
		$this->assertNotContains( 'Has prop', $merged->getOptionalProperties() );
	}

	public function testMergeWithParentInheritsRenderAs(): void {
		$parent = new CategoryModel( 'Parent', [ 'renderAs' => 'ParentFormat' ] );
		$child = new CategoryModel( 'Child', [ 'parents' => [ 'Parent' ] ] );

		$merged = $child->mergeWithParent( $parent );
		$this->assertEquals( 'ParentFormat', $merged->getRenderAs() );
	}

	public function testMergeWithParentChildOverridesRenderAs(): void {
		$parent = new CategoryModel( 'Parent', [ 'renderAs' => 'ParentFormat' ] );
		$child = new CategoryModel( 'Child', [
			'parents' => [ 'Parent' ],
			'renderAs' => 'ChildFormat',
		] );

		$merged = $child->mergeWithParent( $parent );
		$this->assertEquals( 'ChildFormat', $merged->getRenderAs() );
	}

	public function testMergeWithParentMergesSubobjects(): void {
		$parent = new CategoryModel( 'Parent', [
			'subobjects' => [ [ 'name' => 'ParentSub', 'required' => true ] ],
		] );
		$child = new CategoryModel( 'Child', [
			'parents' => [ 'Parent' ],
			'subobjects' => [ [ 'name' => 'ChildSub', 'required' => true ] ],
		] );

		$merged = $child->mergeWithParent( $parent );
		$this->assertContains( 'ParentSub', $merged->getRequiredSubobjects() );
		$this->assertContains( 'ChildSub', $merged->getRequiredSubobjects() );
	}

	public function testMergePromotesOptionalSubobjectToRequired(): void {
		$parent = new CategoryModel( 'Parent', [
			'subobjects' => [ [ 'name' => 'Address', 'required' => false ] ],
		] );
		$child = new CategoryModel( 'Child', [
			'parents' => [ 'Parent' ],
			'subobjects' => [ [ 'name' => 'Address', 'required' => true ] ],
		] );

		$merged = $child->mergeWithParent( $parent );
		$this->assertContains( 'Address', $merged->getRequiredSubobjects() );
		$this->assertNotContains( 'Address', $merged->getOptionalSubobjects() );
	}

	public function testMergeDeduplicatesProperties(): void {
		$parent = new CategoryModel( 'Parent', [
			'properties' => [ [ 'name' => 'Has name', 'required' => true ] ],
		] );
		$child = new CategoryModel( 'Child', [
			'parents' => [ 'Parent' ],
			'properties' => [
				[ 'name' => 'Has name', 'required' => true ],
				[ 'name' => 'Has age', 'required' => true ],
			],
		] );

		$merged = $child->mergeWithParent( $parent );
		$required = $merged->getRequiredProperties();
		$this->assertCount( 1, array_keys( array_filter( $required, static fn ( $p ) => $p === 'Has name' ) ) );
		$this->assertContains( 'Has age', $required );
	}

	public function testMergeKeepsOptionalWhenNotPromoted(): void {
		$parent = new CategoryModel( 'Parent', [
			'properties' => [
				[ 'name' => 'Has name', 'required' => true ],
				[ 'name' => 'Has email', 'required' => false ],
			],
		] );
		$child = new CategoryModel( 'Child', [
			'parents' => [ 'Parent' ],
			'properties' => [
				[ 'name' => 'Has phone', 'required' => false ],
			],
		] );

		$merged = $child->mergeWithParent( $parent );
		$this->assertContains( 'Has email', $merged->getOptionalProperties() );
		$this->assertContains( 'Has phone', $merged->getOptionalProperties() );
		$this->assertNotContains( 'Has email', $merged->getRequiredProperties() );
	}

	/* =========================================================================
	 * EXPORT
	 * ========================================================================= */

	public function testToArrayOmitsEmptySubobjects(): void {
		$model = new CategoryModel( 'TestCategory' );
		$arr = $model->toArray();
		$this->assertArrayNotHasKey( 'subobjects', $arr );
	}

	public function testToArrayIncludesSubobjectsWhenPresent(): void {
		$model = new CategoryModel( 'TestCategory', [
			'subobjects' => [
				[ 'name' => 'Author', 'required' => true ],
				[ 'name' => 'Funding', 'required' => false ],
			],
		] );
		$arr = $model->toArray();
		$this->assertArrayHasKey( 'subobjects', $arr );
		$this->assertCount( 2, $arr['subobjects'] );
		$this->assertSame( 'Author', $arr['subobjects'][0]['name'] );
		$this->assertTrue( $arr['subobjects'][0]['required'] );
		$this->assertSame( 'Funding', $arr['subobjects'][1]['name'] );
		$this->assertFalse( $arr['subobjects'][1]['required'] );
	}

	public function testToArrayContainsAllFields(): void {
		$model = new CategoryModel( 'TestCategory', [
			'label' => 'Test Label',
			'description' => 'Test description',
			'parents' => [ 'Parent1' ],
			'properties' => [
				[ 'name' => 'Has name', 'required' => true ],
				[ 'name' => 'Has email', 'required' => false ],
			],
			'display' => [ 'header' => [ 'Has name' ] ],
			'forms' => [ 'sections' => [] ],
		] );

		$array = $model->toArray();
		$this->assertArrayHasKey( 'label', $array );
		$this->assertArrayHasKey( 'description', $array );
		$this->assertArrayHasKey( 'parents', $array );
		$this->assertArrayHasKey( 'properties', $array );
		$this->assertArrayHasKey( 'display', $array );
		$this->assertArrayHasKey( 'forms', $array );
	}

	/* =========================================================================
	 * IMMUTABILITY
	 * ========================================================================= */

	public function testMergeWithParentReturnsNewInstance(): void {
		$parent = new CategoryModel( 'Parent' );
		$child = new CategoryModel( 'Child', [ 'parents' => [ 'Parent' ] ] );

		$merged = $child->mergeWithParent( $parent );
		$this->assertNotSame( $child, $merged );
		$this->assertNotSame( $parent, $merged );
	}

	public function testMergeDoesNotModifyOriginalChild(): void {
		$parent = new CategoryModel( 'Parent', [
			'properties' => [ [ 'name' => 'Has parent prop', 'required' => true ] ],
		] );
		$child = new CategoryModel( 'Child', [
			'properties' => [ [ 'name' => 'Has child prop', 'required' => true ] ],
		] );

		$originalChildProps = $child->getRequiredProperties();
		$child->mergeWithParent( $parent );

		$this->assertEquals( $originalChildProps, $child->getRequiredProperties() );
	}

	public function testMergeDoesNotModifyOriginalParent(): void {
		$parent = new CategoryModel( 'Parent', [
			'properties' => [ [ 'name' => 'Has parent prop', 'required' => true ] ],
		] );
		$child = new CategoryModel( 'Child', [
			'properties' => [ [ 'name' => 'Has child prop', 'required' => true ] ],
		] );

		$originalParentProps = $parent->getRequiredProperties();
		$child->mergeWithParent( $parent );

		$this->assertEquals( $originalParentProps, $parent->getRequiredProperties() );
	}

	/* =========================================================================
	 * MERGE RETURNS EFFECTIVE TYPE
	 * ========================================================================= */

	public function testMergeWithParentReturnsEffectiveCategoryModel(): void {
		$parent = new CategoryModel( 'Parent', [
			'properties' => [ [ 'name' => 'Has parent prop', 'required' => true ] ],
		] );
		$child = new CategoryModel( 'Child', [
			'properties' => [ [ 'name' => 'Has child prop', 'required' => true ] ],
		] );

		$merged = $child->mergeWithParent( $parent );
		$this->assertInstanceOf( EffectiveCategoryModel::class, $merged );
		$this->assertContains( 'Has parent prop', $merged->getAllProperties() );
		$this->assertContains( 'Has child prop', $merged->getAllProperties() );
	}
}
