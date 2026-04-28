<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit\Schema;

use InvalidArgumentException;
use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\EffectiveCategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\FieldModel;
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
				new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY ),
				new FieldModel( 'Has name', false, FieldModel::TYPE_PROPERTY ),
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
				new FieldModel( 'Address', true, FieldModel::TYPE_SUBOBJECT ),
				new FieldModel( 'Address', false, FieldModel::TYPE_SUBOBJECT ),
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

	public function testFilterFieldsByRequired(): void {
		$model = new CategoryModel( 'TestCategory', [
			'properties' => [
				new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY ),
				new FieldModel( 'Has email', true, FieldModel::TYPE_PROPERTY ),
				new FieldModel( 'Has phone', false, FieldModel::TYPE_PROPERTY ),
			],
		] );
		$required = $this->names( FieldModel::filter( $model->getPropertyFields(), required: true ) );
		$optional = $this->names( FieldModel::filter( $model->getPropertyFields(), required: false ) );
		$this->assertEquals( [ 'Has name', 'Has email' ], $required );
		$this->assertEquals( [ 'Has phone' ], $optional );
	}

	public function testGetPropertyFields(): void {
		$model = new CategoryModel( 'TestCategory', [
			'properties' => [
				new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY ),
				new FieldModel( 'Has email', false, FieldModel::TYPE_PROPERTY ),
			],
		] );
		$fields = $model->getPropertyFields();
		$this->assertCount( 2, $fields );
		$this->assertSame( 'Has name', $fields[0]->getName() );
		$this->assertTrue( $fields[0]->isRequired() );
		$this->assertSame( 'Has email', $fields[1]->getName() );
		$this->assertFalse( $fields[1]->isRequired() );
	}

	public function testGetPropertyFieldsReturnsEmptyWhenNone(): void {
		$model = new CategoryModel( 'TestCategory' );
		$this->assertSame( [], $model->getPropertyFields() );
	}

	/* =========================================================================
	 * SUBOBJECT ACCESSORS
	 * ========================================================================= */

	public function testFilterSubobjectFieldsByRequired(): void {
		$model = new CategoryModel( 'TestCategory', [
			'subobjects' => [
				new FieldModel( 'Author', true, FieldModel::TYPE_SUBOBJECT ),
				new FieldModel( 'Funding', false, FieldModel::TYPE_SUBOBJECT ),
			],
		] );
		$required = $this->names( FieldModel::filter( $model->getSubobjectFields(), required: true ) );
		$optional = $this->names( FieldModel::filter( $model->getSubobjectFields(), required: false ) );
		$this->assertEquals( [ 'Author' ], $required );
		$this->assertEquals( [ 'Funding' ], $optional );
	}

	public function testHasSubobjectsReturnsTrue(): void {
		$model = new CategoryModel( 'TestCategory', [
			'subobjects' => [
				new FieldModel( 'Author', true, FieldModel::TYPE_SUBOBJECT ),
			],
		] );
		$this->assertTrue( $model->hasSubobjects() );
	}

	public function testHasSubobjectsReturnsFalse(): void {
		$model = new CategoryModel( 'TestCategory' );
		$this->assertFalse( $model->hasSubobjects() );
	}

	public function testGetSubobjectFieldsReturnsBothWithFlags(): void {
		$model = new CategoryModel( 'TestCategory', [
			'subobjects' => [
				new FieldModel( 'Author', true, FieldModel::TYPE_SUBOBJECT ),
				new FieldModel( 'Funding', false, FieldModel::TYPE_SUBOBJECT ),
			],
		] );
		$fields = $model->getSubobjectFields();
		$this->assertCount( 2, $fields );
		$this->assertSame( 'Author', $fields[0]->getName() );
		$this->assertTrue( $fields[0]->isRequired() );
		$this->assertSame( 'Funding', $fields[1]->getName() );
		$this->assertFalse( $fields[1]->isRequired() );
	}

	public function testGetSubobjectFieldsReturnsEmptyWhenNone(): void {
		$model = new CategoryModel( 'TestCategory' );
		$this->assertSame( [], $model->getSubobjectFields() );
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

	public function testDisplayTemplateDefaultsToNull(): void {
		$model = new CategoryModel( 'TestCategory' );
		$this->assertNull( $model->getDisplayTemplate() );
	}

	public function testDisplayTemplateSetFromConstructorData(): void {
		$model = new CategoryModel( 'TestCategory', [
			'display' => [ 'template' => 'Template:MyDisplay' ],
		] );
		$this->assertSame( 'Template:MyDisplay', $model->getDisplayTemplate() );
	}

	public function testDisplayTemplateSurvivesToArrayRoundTrip(): void {
		$model = new CategoryModel( 'TestCategory', [
			'display' => [ 'format' => 'table', 'template' => 'Template:MyDisplay' ],
		] );
		$rebuilt = new CategoryModel( 'TestCategory', $model->toArray() );
		$this->assertSame( 'Template:MyDisplay', $rebuilt->getDisplayTemplate() );
	}

	public function testDisplayTemplateInheritedThroughMerge(): void {
		$parent = new CategoryModel( 'Parent', [
			'display' => [ 'template' => 'Template:ParentDisplay' ],
		] );
		$child = new CategoryModel( 'Child', [ 'parents' => [ 'Parent' ] ] );

		$merged = $child->mergeWithParent( $parent );
		$this->assertSame( 'Template:ParentDisplay', $merged->getDisplayTemplate() );
	}

	public function testChildDisplayTemplateOverridesParent(): void {
		$parent = new CategoryModel( 'Parent', [
			'display' => [ 'template' => 'Template:ParentDisplay' ],
		] );
		$child = new CategoryModel( 'Child', [
			'parents' => [ 'Parent' ],
			'display' => [ 'template' => 'Template:ChildDisplay' ],
		] );

		$merged = $child->mergeWithParent( $parent );
		$this->assertSame( 'Template:ChildDisplay', $merged->getDisplayTemplate() );
	}

	public function testSubobjectDisplayTemplateDefaultsToNull(): void {
		$model = new CategoryModel( 'Chapter' );
		$this->assertNull( $model->getSubobjectDisplayTemplate() );
	}

	public function testSubobjectDisplayTemplateSetFromConstructorData(): void {
		$model = new CategoryModel( 'Chapter', [
			'display' => [ 'subobjectTemplate' => 'ChapterTable' ],
		] );
		$this->assertSame( 'ChapterTable', $model->getSubobjectDisplayTemplate() );
	}

	public function testSubobjectDisplayTemplateSurvivesToArrayRoundTrip(): void {
		$model = new CategoryModel( 'Chapter', [
			'display' => [ 'subobjectTemplate' => 'ChapterTable' ],
		] );
		$rebuilt = new CategoryModel( 'Chapter', $model->toArray() );
		$this->assertSame( 'ChapterTable', $rebuilt->getSubobjectDisplayTemplate() );
	}

	public function testSubobjectDisplayTemplateInheritedThroughMerge(): void {
		$parent = new CategoryModel( 'Parent', [
			'display' => [ 'subobjectTemplate' => 'ParentSubTable' ],
		] );
		$child = new CategoryModel( 'Child', [ 'parents' => [ 'Parent' ] ] );

		$merged = $child->mergeWithParent( $parent );
		$this->assertSame( 'ParentSubTable', $merged->getSubobjectDisplayTemplate() );
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
				new FieldModel( 'Has parent prop', true, FieldModel::TYPE_PROPERTY ),
			],
		] );
		$child = new CategoryModel( 'Child', [
			'parents' => [ 'Parent' ],
			'properties' => [
				new FieldModel( 'Has child prop', true, FieldModel::TYPE_PROPERTY ),
			],
		] );

		$merged = $child->mergeWithParent( $parent );
		$allNames = $this->names( $merged->getPropertyFields() );
		$this->assertContains( 'Has parent prop', $allNames );
		$this->assertContains( 'Has child prop', $allNames );
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
				new FieldModel( 'Has prop', false, FieldModel::TYPE_PROPERTY ),
			],
		] );
		$child = new CategoryModel( 'Child', [
			'parents' => [ 'Parent' ],
			'properties' => [
				new FieldModel( 'Has prop', true, FieldModel::TYPE_PROPERTY ),
			],
		] );

		$merged = $child->mergeWithParent( $parent );
		$required = $this->names( FieldModel::filter( $merged->getPropertyFields(), required: true ) );
		$optional = $this->names( FieldModel::filter( $merged->getPropertyFields(), required: false ) );
		$this->assertContains( 'Has prop', $required );
		$this->assertNotContains( 'Has prop', $optional );
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
			'subobjects' => [ new FieldModel( 'ParentSub', true, FieldModel::TYPE_SUBOBJECT ) ],
		] );
		$child = new CategoryModel( 'Child', [
			'parents' => [ 'Parent' ],
			'subobjects' => [ new FieldModel( 'ChildSub', true, FieldModel::TYPE_SUBOBJECT ) ],
		] );

		$merged = $child->mergeWithParent( $parent );
		$requiredSubs = $this->names( FieldModel::filter( $merged->getSubobjectFields(), required: true ) );
		$this->assertContains( 'ParentSub', $requiredSubs );
		$this->assertContains( 'ChildSub', $requiredSubs );
	}

	public function testMergePromotesOptionalSubobjectToRequired(): void {
		$parent = new CategoryModel( 'Parent', [
			'subobjects' => [ new FieldModel( 'Address', false, FieldModel::TYPE_SUBOBJECT ) ],
		] );
		$child = new CategoryModel( 'Child', [
			'parents' => [ 'Parent' ],
			'subobjects' => [ new FieldModel( 'Address', true, FieldModel::TYPE_SUBOBJECT ) ],
		] );

		$merged = $child->mergeWithParent( $parent );
		$requiredSubs = $this->names( FieldModel::filter( $merged->getSubobjectFields(), required: true ) );
		$optionalSubs = $this->names( FieldModel::filter( $merged->getSubobjectFields(), required: false ) );
		$this->assertContains( 'Address', $requiredSubs );
		$this->assertNotContains( 'Address', $optionalSubs );
	}

	public function testMergeDeduplicatesProperties(): void {
		$parent = new CategoryModel( 'Parent', [
			'properties' => [ new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY ) ],
		] );
		$child = new CategoryModel( 'Child', [
			'parents' => [ 'Parent' ],
			'properties' => [
				new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY ),
				new FieldModel( 'Has age', true, FieldModel::TYPE_PROPERTY ),
			],
		] );

		$merged = $child->mergeWithParent( $parent );
		$required = $this->names( FieldModel::filter( $merged->getPropertyFields(), required: true ) );
		$this->assertCount( 1, array_keys( array_filter( $required, static fn ( $p ) => $p === 'Has name' ) ) );
		$this->assertContains( 'Has age', $required );
	}

	public function testMergeKeepsOptionalWhenNotPromoted(): void {
		$parent = new CategoryModel( 'Parent', [
			'properties' => [
				new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY ),
				new FieldModel( 'Has email', false, FieldModel::TYPE_PROPERTY ),
			],
		] );
		$child = new CategoryModel( 'Child', [
			'parents' => [ 'Parent' ],
			'properties' => [
				new FieldModel( 'Has phone', false, FieldModel::TYPE_PROPERTY ),
			],
		] );

		$merged = $child->mergeWithParent( $parent );
		$optional = $this->names( FieldModel::filter( $merged->getPropertyFields(), required: false ) );
		$required = $this->names( FieldModel::filter( $merged->getPropertyFields(), required: true ) );
		$this->assertContains( 'Has email', $optional );
		$this->assertContains( 'Has phone', $optional );
		$this->assertNotContains( 'Has email', $required );
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
				new FieldModel( 'Author', true, FieldModel::TYPE_SUBOBJECT ),
				new FieldModel( 'Funding', false, FieldModel::TYPE_SUBOBJECT ),
			],
		] );
		$arr = $model->toArray();
		$this->assertArrayHasKey( 'subobjects', $arr );
		$this->assertCount( 2, $arr['subobjects'] );
		$this->assertSame( 'Author', $arr['subobjects'][0]->getName() );
		$this->assertTrue( $arr['subobjects'][0]->isRequired() );
		$this->assertSame( 'Funding', $arr['subobjects'][1]->getName() );
		$this->assertFalse( $arr['subobjects'][1]->isRequired() );
	}

	public function testToArrayContainsAllFields(): void {
		$model = new CategoryModel( 'TestCategory', [
			'label' => 'Test Label',
			'description' => 'Test description',
			'parents' => [ 'Parent1' ],
			'properties' => [
				new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY ),
				new FieldModel( 'Has email', false, FieldModel::TYPE_PROPERTY ),
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
			'properties' => [ new FieldModel( 'Has parent prop', true, FieldModel::TYPE_PROPERTY ) ],
		] );
		$child = new CategoryModel( 'Child', [
			'properties' => [ new FieldModel( 'Has child prop', true, FieldModel::TYPE_PROPERTY ) ],
		] );

		$originalChildProps = $this->names( FieldModel::filter( $child->getPropertyFields(), required: true ) );
		$child->mergeWithParent( $parent );

		$childRequired = FieldModel::filter( $child->getPropertyFields(), required: true );
		$this->assertEquals( $originalChildProps, $this->names( $childRequired ) );
	}

	public function testMergeDoesNotModifyOriginalParent(): void {
		$parent = new CategoryModel( 'Parent', [
			'properties' => [ new FieldModel( 'Has parent prop', true, FieldModel::TYPE_PROPERTY ) ],
		] );
		$child = new CategoryModel( 'Child', [
			'properties' => [ new FieldModel( 'Has child prop', true, FieldModel::TYPE_PROPERTY ) ],
		] );

		$originalParentProps = $this->names( FieldModel::filter( $parent->getPropertyFields(), required: true ) );
		$child->mergeWithParent( $parent );

		$afterMerge = $this->names( FieldModel::filter( $parent->getPropertyFields(), required: true ) );
		$this->assertEquals( $originalParentProps, $afterMerge );
	}

	/* =========================================================================
	 * MERGE RETURNS EFFECTIVE TYPE
	 * ========================================================================= */

	public function testMergeWithParentReturnsEffectiveCategoryModel(): void {
		$parent = new CategoryModel( 'Parent', [
			'properties' => [ new FieldModel( 'Has parent prop', true, FieldModel::TYPE_PROPERTY ) ],
		] );
		$child = new CategoryModel( 'Child', [
			'properties' => [ new FieldModel( 'Has child prop', true, FieldModel::TYPE_PROPERTY ) ],
		] );

		$merged = $child->mergeWithParent( $parent );
		$this->assertInstanceOf( EffectiveCategoryModel::class, $merged );
		$allNames = $this->names( $merged->getPropertyFields() );
		$this->assertContains( 'Has parent prop', $allNames );
		$this->assertContains( 'Has child prop', $allNames );
	}

	/**
	 * Extract names from a list of field models.
	 *
	 * @param FieldModel[] $fields
	 * @return string[]
	 */
	private static function names( array $fields ): array {
		return array_map(
			static fn ( FieldModel $f ) => $f->getName(),
			$fields
		);
	}
}
