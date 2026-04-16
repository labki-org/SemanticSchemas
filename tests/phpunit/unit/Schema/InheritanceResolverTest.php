<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit\Schema;

use InvalidArgumentException;
use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\EffectiveCategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\FieldModel;
use MediaWiki\Extension\SemanticSchemas\Schema\InheritanceResolver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Schema\InheritanceResolver
 */
class InheritanceResolverTest extends TestCase {

	/* =========================================================================
	 * CONSTRUCTOR VALIDATION
	 * ========================================================================= */

	public function testConstructorRejectsNonCategoryModelValues(): void {
		$this->expectException( InvalidArgumentException::class );
		new InheritanceResolver( [
			'Category' => 'not a CategoryModel',
		] );
	}

	public function testConstructorAcceptsCategoryModelMap(): void {
		$map = [
			'Person' => new CategoryModel( 'Person' ),
		];
		$resolver = new InheritanceResolver( $map );
		$this->assertInstanceOf( InheritanceResolver::class, $resolver );
	}

	/* =========================================================================
	 * SINGLE INHERITANCE
	 * ========================================================================= */

	public function testSingleCategoryWithNoParents(): void {
		$map = [
			'Person' => new CategoryModel( 'Person' ),
		];
		$resolver = new InheritanceResolver( $map );

		$ancestors = $resolver->getAncestors( 'Person' );
		$this->assertEquals( [ 'Person' ], $ancestors );
	}

	public function testSingleParentInheritance(): void {
		$map = [
			'Person' => new CategoryModel( 'Person' ),
			'Student' => new CategoryModel( 'Student', [ 'parents' => [ 'Person' ] ] ),
		];
		$resolver = new InheritanceResolver( $map );

		$ancestors = $resolver->getAncestors( 'Student' );
		$this->assertEquals( [ 'Student', 'Person' ], $ancestors );
	}

	public function testMultiLevelSingleInheritance(): void {
		$map = [
			'Person' => new CategoryModel( 'Person' ),
			'Student' => new CategoryModel( 'Student', [ 'parents' => [ 'Person' ] ] ),
			'GradStudent' => new CategoryModel( 'GradStudent', [ 'parents' => [ 'Student' ] ] ),
		];
		$resolver = new InheritanceResolver( $map );

		$ancestors = $resolver->getAncestors( 'GradStudent' );
		$this->assertEquals( [ 'GradStudent', 'Student', 'Person' ], $ancestors );
	}

	/* =========================================================================
	 * MULTIPLE INHERITANCE (C3 LINEARIZATION)
	 * ========================================================================= */

	public function testDiamondInheritanceC3Linearization(): void {
		// Classic diamond: D inherits from B and C, both inherit from A
		$map = [
			'A' => new CategoryModel( 'A' ),
			'B' => new CategoryModel( 'B', [ 'parents' => [ 'A' ] ] ),
			'C' => new CategoryModel( 'C', [ 'parents' => [ 'A' ] ] ),
			'D' => new CategoryModel( 'D', [ 'parents' => [ 'B', 'C' ] ] ),
		];
		$resolver = new InheritanceResolver( $map );

		$ancestors = $resolver->getAncestors( 'D' );
		// D should be first, A should only appear once
		$this->assertEquals( 'D', $ancestors[0] );
		$this->assertContains( 'B', $ancestors );
		$this->assertContains( 'C', $ancestors );
		$this->assertContains( 'A', $ancestors );
		// A should appear only once
		$this->assertSame( 1, array_count_values( $ancestors )['A'] );
	}

	public function testMultipleParentsPreserveOrder(): void {
		$map = [
			'Parent1' => new CategoryModel( 'Parent1' ),
			'Parent2' => new CategoryModel( 'Parent2' ),
			'Child' => new CategoryModel( 'Child', [ 'parents' => [ 'Parent1', 'Parent2' ] ] ),
		];
		$resolver = new InheritanceResolver( $map );

		$ancestors = $resolver->getAncestors( 'Child' );
		$this->assertEquals( [ 'Child', 'Parent1', 'Parent2' ], $ancestors );
	}

	public function testComplexMultipleInheritance(): void {
		// O -> A, B, C; A,B -> D; C -> E; D,E -> F
		$map = [
			'O' => new CategoryModel( 'O' ),
			'A' => new CategoryModel( 'A', [ 'parents' => [ 'O' ] ] ),
			'B' => new CategoryModel( 'B', [ 'parents' => [ 'O' ] ] ),
			'C' => new CategoryModel( 'C', [ 'parents' => [ 'O' ] ] ),
			'D' => new CategoryModel( 'D', [ 'parents' => [ 'A', 'B' ] ] ),
			'E' => new CategoryModel( 'E', [ 'parents' => [ 'C' ] ] ),
			'F' => new CategoryModel( 'F', [ 'parents' => [ 'D', 'E' ] ] ),
		];
		$resolver = new InheritanceResolver( $map );

		$ancestors = $resolver->getAncestors( 'F' );
		// F should be first
		$this->assertEquals( 'F', $ancestors[0] );
		// O should be last (root)
		$this->assertEquals( 'O', $ancestors[count( $ancestors ) - 1] );
		// All categories should be present exactly once
		$this->assertCount( 7, $ancestors );
		$this->assertEquals( array_unique( $ancestors ), $ancestors );
	}

	/* =========================================================================
	 * CIRCULAR DEPENDENCY DETECTION
	 * ========================================================================= */

	public function testDirectCircularDependencyThrowsException(): void {
		$map = [
			'A' => new CategoryModel( 'A', [ 'parents' => [ 'B' ] ] ),
			'B' => new CategoryModel( 'B', [ 'parents' => [ 'A' ] ] ),
		];
		$resolver = new InheritanceResolver( $map );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Circular' );
		$resolver->getAncestors( 'A' );
	}

	public function testIndirectCircularDependencyThrowsException(): void {
		$map = [
			'A' => new CategoryModel( 'A', [ 'parents' => [ 'B' ] ] ),
			'B' => new CategoryModel( 'B', [ 'parents' => [ 'C' ] ] ),
			'C' => new CategoryModel( 'C', [ 'parents' => [ 'A' ] ] ),
		];
		$resolver = new InheritanceResolver( $map );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Circular' );
		$resolver->getAncestors( 'A' );
	}

	public function testValidateInheritanceReturnsCircularErrors(): void {
		$map = [
			'A' => new CategoryModel( 'A', [ 'parents' => [ 'B' ] ] ),
			'B' => new CategoryModel( 'B', [ 'parents' => [ 'A' ] ] ),
		];
		$resolver = new InheritanceResolver( $map );

		$errors = $resolver->validateInheritance();
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'Circular', $errors[0] );
	}

	public function testValidateInheritanceReturnsEmptyForValidSchema(): void {
		$map = [
			'A' => new CategoryModel( 'A' ),
			'B' => new CategoryModel( 'B', [ 'parents' => [ 'A' ] ] ),
		];
		$resolver = new InheritanceResolver( $map );

		$errors = $resolver->validateInheritance();
		$this->assertEmpty( $errors );
	}

	/* =========================================================================
	 * EFFECTIVE CATEGORY (MERGED PROPERTIES)
	 * ========================================================================= */

	public function testGetEffectiveCategoryMergesProperties(): void {
		$map = [
			'Person' => new CategoryModel( 'Person', [
				'properties' => [
					FieldModel::property( 'Has name', true ),
					FieldModel::property( 'Has email', false ),
				],
			] ),
			'Student' => new CategoryModel( 'Student', [
				'parents' => [ 'Person' ],
				'properties' => [
					FieldModel::property( 'Has student ID', true ),
				],
			] ),
		];
		$resolver = new InheritanceResolver( $map );

		$effective = $resolver->getEffectiveCategory( 'Student' );

		$allNames = FieldModel::names( $effective->getPropertyFields() );
		$this->assertContains( 'Has name', $allNames );
		$this->assertContains( 'Has student ID', $allNames );
		$this->assertContains( 'Has email', $allNames );
	}

	public function testGetEffectiveCategoryForUnknownThrows(): void {
		$resolver = new InheritanceResolver( [
			'Person' => new CategoryModel( 'Person' ),
		] );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Unknown category: Unknown' );
		$resolver->getEffectiveCategory( 'Unknown' );
	}

	public function testNonexistentParentIgnored(): void {
		$resolver = new InheritanceResolver( [
			'Person' => new CategoryModel( 'Person', [ 'parents' => [ 'NonExistentCategory' ] ] ),
		] );
		// Mostly just testing that we don't throw and ignore these
		$effective = $resolver->getEffectiveCategory( 'Person' );
		$this->assertEquals( [ 'NonExistentCategory' ], $effective->getParents() );
	}

	/* =========================================================================
	 * CACHING
	 * ========================================================================= */

	public function testGetAncestorsCachesResults(): void {
		$map = [
			'Person' => new CategoryModel( 'Person' ),
			'Student' => new CategoryModel( 'Student', [ 'parents' => [ 'Person' ] ] ),
		];
		$resolver = new InheritanceResolver( $map );

		$first = $resolver->getAncestors( 'Student' );
		$second = $resolver->getAncestors( 'Student' );

		// Results should be identical (cached)
		$this->assertEquals( $first, $second );
	}

	public function testUnknownCategoryThrows(): void {
		$resolver = new InheritanceResolver( [
			'Person' => new CategoryModel( 'Person' ),
		] );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Unknown category: Unknown' );
		$resolver->getAncestors( 'Unknown' );
	}

	/* =========================================================================
	 * INHERITANCE CHAIN (RAW MODELS)
	 * ========================================================================= */

	public function testGetInheritanceChainRootReturnsSelf(): void {
		$person = new CategoryModel( 'Person', [
			'properties' => [
				FieldModel::property( 'Has name', true ),
			],
		] );
		$resolver = new InheritanceResolver( [ 'Person' => $person ] );

		$chain = $resolver->getInheritanceChain( 'Person' );
		$this->assertCount( 1, $chain );
		$this->assertEquals( 'Person', $chain[0]->getName() );
		$this->assertContains( 'Has name', FieldModel::names( $chain[0]->getPropertyFields() ) );
	}

	public function testGetInheritanceChainSingleParent(): void {
		$person = new CategoryModel( 'Person', [
			'properties' => [
				FieldModel::property( 'Has name', true ),
			],
		] );
		$student = new CategoryModel( 'Student', [
			'parents' => [ 'Person' ],
			'properties' => [
				FieldModel::property( 'Has student ID', true ),
			],
		] );
		$resolver = new InheritanceResolver( [
			'Person' => $person,
			'Student' => $student,
		] );

		$chain = $resolver->getInheritanceChain( 'Student' );
		$this->assertCount( 2, $chain );
		$this->assertEquals( 'Student', $chain[0]->getName() );
		$this->assertEquals( 'Person', $chain[1]->getName() );

		// Each model should have only its own properties
		$studentNames = FieldModel::names( $chain[0]->getPropertyFields() );
		$personNames = FieldModel::names( $chain[1]->getPropertyFields() );
		$this->assertContains( 'Has student ID', $studentNames );
		$this->assertNotContains( 'Has name', $studentNames );
		$this->assertContains( 'Has name', $personNames );
		$this->assertNotContains( 'Has student ID', $personNames );
	}

	public function testGetInheritanceChainMultiParent(): void {
		$person = new CategoryModel( 'Person', [
			'properties' => [
				FieldModel::property( 'Has name', true ),
			],
		] );
		$labMember = new CategoryModel( 'LabMember', [
			'properties' => [
				FieldModel::property( 'Has lab role', true ),
			],
		] );
		$gradStudent = new CategoryModel( 'GradStudent', [
			'parents' => [ 'Person', 'LabMember' ],
			'properties' => [
				FieldModel::property( 'Has advisor', true ),
			],
		] );
		$resolver = new InheritanceResolver( [
			'Person' => $person,
			'LabMember' => $labMember,
			'GradStudent' => $gradStudent,
		] );

		$chain = $resolver->getInheritanceChain( 'GradStudent' );
		$names = array_map( static fn ( $m ) => $m->getName(), $chain );

		// C3 linearization: child first, then parents in declared order
		$this->assertEquals( [ 'GradStudent', 'Person', 'LabMember' ], $names );

		// Each model in the chain carries only its own declared properties
		$this->assertEquals( [ 'Has advisor' ], FieldModel::names( $chain[0]->getPropertyFields() ) );
		$this->assertEquals( [ 'Has name' ], FieldModel::names( $chain[1]->getPropertyFields() ) );
		$this->assertEquals( [ 'Has lab role' ], FieldModel::names( $chain[2]->getPropertyFields() ) );
	}

	public function testGetInheritanceChainForUnknownThrows(): void {
		$resolver = new InheritanceResolver( [
			'Person' => new CategoryModel( 'Person' ),
		] );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Unknown category: Unknown' );
		$resolver->getInheritanceChain( 'Unknown' );
	}

	/* =========================================================================
	 * EFFECTIVE CATEGORY MODEL TYPE
	 * ========================================================================= */

	public function testGetEffectiveCategoryReturnsEffectiveType(): void {
		$resolver = new InheritanceResolver( [
			'Person' => new CategoryModel( 'Person' ),
		] );

		$effective = $resolver->getEffectiveCategory( 'Person' );
		$this->assertInstanceOf( EffectiveCategoryModel::class, $effective );
	}

	public function testGetEffectiveCategoryForUnknownThrowsRuntimeException(): void {
		$resolver = new InheritanceResolver( [
			'Person' => new CategoryModel( 'Person' ),
		] );

		$this->expectException( RuntimeException::class );
		$resolver->getEffectiveCategory( 'Unknown' );
	}

	public function testGetEffectiveCategoryIsCached(): void {
		$resolver = new InheritanceResolver( [
			'Person' => new CategoryModel( 'Person' ),
		] );

		$this->assertSame(
			$resolver->getEffectiveCategory( 'Person' ),
			$resolver->getEffectiveCategory( 'Person' )
		);
	}

	public function testGetEffectiveCategoryMergesInheritedProperties(): void {
		$person = new CategoryModel( 'Person', [
			'properties' => [
				FieldModel::property( 'Has name', true ),
			],
		] );
		$student = new CategoryModel( 'Student', [
			'parents' => [ 'Person' ],
			'properties' => [
				FieldModel::property( 'Has student ID', true ),
			],
		] );

		$resolver = new InheritanceResolver( [ 'Person' => $person, 'Student' => $student ] );
		$effective = $resolver->getEffectiveCategory( 'Student' );

		$this->assertInstanceOf( EffectiveCategoryModel::class, $effective );
		$allNames = FieldModel::names( $effective->getPropertyFields() );
		$this->assertContains( 'Has name', $allNames );
		$this->assertContains( 'Has student ID', $allNames );
	}

	/* =========================================================================
	 * PARENT EFFECTIVE MODELS
	 * ========================================================================= */

	public function testGetParentEffectiveModelsReturnsEffectiveParents(): void {
		$grandparent = new CategoryModel( 'Grandparent', [
			'properties' => [
				FieldModel::property( 'Has gp prop', true ),
			],
		] );
		$parent = new CategoryModel( 'Parent', [
			'parents' => [ 'Grandparent' ],
			'properties' => [
				FieldModel::property( 'Has parent prop', true ),
			],
		] );
		$child = new CategoryModel( 'Child', [
			'parents' => [ 'Parent' ],
			'properties' => [
				FieldModel::property( 'Has child prop', true ),
			],
		] );

		$resolver = new InheritanceResolver( [
			'Grandparent' => $grandparent,
			'Parent' => $parent,
			'Child' => $child,
		] );

		$parentModels = $resolver->getParentEffectiveModels( 'Child' );
		$this->assertArrayHasKey( 'Parent', $parentModels );
		$this->assertCount( 1, $parentModels );
		$this->assertInstanceOf( EffectiveCategoryModel::class, $parentModels['Parent'] );
		$parentNames = FieldModel::names( $parentModels['Parent']->getPropertyFields() );
		$this->assertContains( 'Has gp prop', $parentNames );
		$this->assertContains( 'Has parent prop', $parentNames );
	}

	public function testGetParentEffectiveModelsForUnknownThrows(): void {
		$resolver = new InheritanceResolver( [
			'Person' => new CategoryModel( 'Person' ),
		] );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Unknown category: Unknown' );
		$resolver->getParentEffectiveModels( 'Unknown' );
	}

	public function testGetParentEffectiveModelsForRootReturnsEmpty(): void {
		$resolver = new InheritanceResolver( [
			'Person' => new CategoryModel( 'Person' ),
		] );

		$this->assertEmpty( $resolver->getParentEffectiveModels( 'Person' ) );
	}
}
