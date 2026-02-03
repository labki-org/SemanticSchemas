<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit\Schema;

use InvalidArgumentException;
use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
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

	public function testDuplicateRequiredOptionalPropertyThrowsException(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'both required and optional' );
		new CategoryModel( 'TestCategory', [
			'properties' => [
				'required' => [ 'Has name' ],
				'optional' => [ 'Has name' ],
			],
		] );
	}

	public function testDuplicateRequiredOptionalSubobjectThrowsException(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'both required and optional' );
		new CategoryModel( 'TestCategory', [
			'subobjects' => [
				'required' => [ 'Author' ],
				'optional' => [ 'Author' ],
			],
		] );
	}

	public function testNonArrayPropertiesThrowsException(): void {
		$this->expectException( InvalidArgumentException::class );
		new CategoryModel( 'TestCategory', [
			'properties' => 'not an array',
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
				'required' => [ 'Has name', 'Has email' ],
				'optional' => [],
			],
		] );
		$this->assertEquals( [ 'Has name', 'Has email' ], $model->getRequiredProperties() );
	}

	public function testGetOptionalProperties(): void {
		$model = new CategoryModel( 'TestCategory', [
			'properties' => [
				'required' => [],
				'optional' => [ 'Has phone', 'Has address' ],
			],
		] );
		$this->assertEquals( [ 'Has phone', 'Has address' ], $model->getOptionalProperties() );
	}

	public function testGetAllPropertiesCombinesBoth(): void {
		$model = new CategoryModel( 'TestCategory', [
			'properties' => [
				'required' => [ 'Has name' ],
				'optional' => [ 'Has email' ],
			],
		] );
		$allProps = $model->getAllProperties();
		$this->assertContains( 'Has name', $allProps );
		$this->assertContains( 'Has email', $allProps );
		$this->assertCount( 2, $allProps );
	}

	public function testGetTaggedPropertiesReturnsBothWithFlags(): void {
		$model = new CategoryModel( 'TestCategory', [
			'properties' => [
				'required' => [ 'Has name' ],
				'optional' => [ 'Has email' ],
			],
		] );
		$tagged = $model->getTaggedProperties();
		$this->assertCount( 2, $tagged );
		$this->assertSame( [ 'name' => 'Has name', 'required' => true ], $tagged[0] );
		$this->assertSame( [ 'name' => 'Has email', 'required' => false ], $tagged[1] );
	}

	public function testGetTaggedPropertiesReturnsEmptyWhenNone(): void {
		$model = new CategoryModel( 'TestCategory' );
		$this->assertSame( [], $model->getTaggedProperties() );
	}

	/* =========================================================================
	 * SUBOBJECT ACCESSORS
	 * ========================================================================= */

	public function testGetRequiredSubobjects(): void {
		$model = new CategoryModel( 'TestCategory', [
			'subobjects' => [
				'required' => [ 'Author' ],
				'optional' => [],
			],
		] );
		$this->assertEquals( [ 'Author' ], $model->getRequiredSubobjects() );
	}

	public function testGetOptionalSubobjects(): void {
		$model = new CategoryModel( 'TestCategory', [
			'subobjects' => [
				'required' => [],
				'optional' => [ 'Funding' ],
			],
		] );
		$this->assertEquals( [ 'Funding' ], $model->getOptionalSubobjects() );
	}

	public function testHasSubobjectsReturnsTrue(): void {
		$model = new CategoryModel( 'TestCategory', [
			'subobjects' => [
				'required' => [ 'Author' ],
				'optional' => [],
			],
		] );
		$this->assertTrue( $model->hasSubobjects() );
	}

	public function testHasSubobjectsReturnsFalse(): void {
		$model = new CategoryModel( 'TestCategory' );
		$this->assertFalse( $model->hasSubobjects() );
	}

	public function testGetTaggedSubobjectsReturnsBothWithFlags(): void {
		$model = new CategoryModel( 'TestCategory', [
			'subobjects' => [
				'required' => [ 'Author' ],
				'optional' => [ 'Funding' ],
			],
		] );
		$tagged = $model->getTaggedSubobjects();
		$this->assertCount( 2, $tagged );
		$this->assertSame( [ 'name' => 'Author', 'required' => true ], $tagged[0] );
		$this->assertSame( [ 'name' => 'Funding', 'required' => false ], $tagged[1] );
	}

	public function testGetTaggedSubobjectsReturnsEmptyWhenNone(): void {
		$model = new CategoryModel( 'TestCategory' );
		$this->assertSame( [], $model->getTaggedSubobjects() );
	}

	/* =========================================================================
	 * DISPLAY CONFIG
	 * ========================================================================= */

	public function testGetDisplayConfig(): void {
		$displayConfig = [
			'header' => [ 'Has name' ],
			'sections' => [
				[ 'name' => 'Basic', 'properties' => [ 'Has name' ] ],
			],
		];
		$model = new CategoryModel( 'TestCategory', [ 'display' => $displayConfig ] );
		$this->assertEquals( $displayConfig, $model->getDisplayConfig() );
	}

	public function testGetDisplayHeaderProperties(): void {
		$model = new CategoryModel( 'TestCategory', [
			'display' => [ 'header' => [ 'Has name', 'Has email' ] ],
		] );
		$this->assertEquals( [ 'Has name', 'Has email' ], $model->getDisplayHeaderProperties() );
	}

	public function testGetDisplaySections(): void {
		$sections = [
			[ 'name' => 'Basic', 'properties' => [ 'Has name' ] ],
		];
		$model = new CategoryModel( 'TestCategory', [
			'display' => [ 'sections' => $sections ],
		] );
		$this->assertEquals( $sections, $model->getDisplaySections() );
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
				'required' => [ 'Has parent prop' ],
				'optional' => [],
			],
		] );
		$child = new CategoryModel( 'Child', [
			'parents' => [ 'Parent' ],
			'properties' => [
				'required' => [ 'Has child prop' ],
				'optional' => [],
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
				'required' => [],
				'optional' => [ 'Has prop' ],
			],
		] );
		$child = new CategoryModel( 'Child', [
			'parents' => [ 'Parent' ],
			'properties' => [
				'required' => [ 'Has prop' ],
				'optional' => [],
			],
		] );

		$merged = $child->mergeWithParent( $parent );
		$this->assertContains( 'Has prop', $merged->getRequiredProperties() );
		$this->assertNotContains( 'Has prop', $merged->getOptionalProperties() );
	}

	public function testMergeWithParentMergesSubobjects(): void {
		$parent = new CategoryModel( 'Parent', [
			'subobjects' => [
				'required' => [ 'ParentSub' ],
				'optional' => [],
			],
		] );
		$child = new CategoryModel( 'Child', [
			'parents' => [ 'Parent' ],
			'subobjects' => [
				'required' => [ 'ChildSub' ],
				'optional' => [],
			],
		] );

		$merged = $child->mergeWithParent( $parent );
		$this->assertContains( 'ParentSub', $merged->getRequiredSubobjects() );
		$this->assertContains( 'ChildSub', $merged->getRequiredSubobjects() );
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

	/* =========================================================================
	 * EXPORT
	 * ========================================================================= */

	public function testToArrayContainsAllFields(): void {
		$model = new CategoryModel( 'TestCategory', [
			'label' => 'Test Label',
			'description' => 'Test description',
			'parents' => [ 'Parent1' ],
			'properties' => [
				'required' => [ 'Has name' ],
				'optional' => [ 'Has email' ],
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

	public function testToArrayOmitsEmptySubobjects(): void {
		$model = new CategoryModel( 'TestCategory' );
		$array = $model->toArray();
		$this->assertArrayNotHasKey( 'subobjects', $array );
	}

	public function testToArrayIncludesSubobjectsWhenPresent(): void {
		$model = new CategoryModel( 'TestCategory', [
			'subobjects' => [
				'required' => [ 'Author' ],
				'optional' => [],
			],
		] );
		$array = $model->toArray();
		$this->assertArrayHasKey( 'subobjects', $array );
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
			'properties' => [ 'required' => [ 'Has parent prop' ], 'optional' => [] ],
		] );
		$child = new CategoryModel( 'Child', [
			'properties' => [ 'required' => [ 'Has child prop' ], 'optional' => [] ],
		] );

		$originalChildProps = $child->getRequiredProperties();
		$child->mergeWithParent( $parent );

		$this->assertEquals( $originalChildProps, $child->getRequiredProperties() );
	}

	public function testMergeDoesNotModifyOriginalParent(): void {
		$parent = new CategoryModel( 'Parent', [
			'properties' => [ 'required' => [ 'Has parent prop' ], 'optional' => [] ],
		] );
		$child = new CategoryModel( 'Child', [
			'properties' => [ 'required' => [ 'Has child prop' ], 'optional' => [] ],
		] );

		$originalParentProps = $parent->getRequiredProperties();
		$child->mergeWithParent( $parent );

		$this->assertEquals( $originalParentProps, $parent->getRequiredProperties() );
	}
}
