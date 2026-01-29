<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit\Schema;

use InvalidArgumentException;
use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
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
					'required' => [ 'Has name' ],
					'optional' => [ 'Has email' ],
				],
			] ),
			'Student' => new CategoryModel( 'Student', [
				'parents' => [ 'Person' ],
				'properties' => [
					'required' => [ 'Has student ID' ],
					'optional' => [],
				],
			] ),
		];
		$resolver = new InheritanceResolver( $map );

		$effective = $resolver->getEffectiveCategory( 'Student' );

		$allProps = $effective->getAllProperties();
		$this->assertContains( 'Has name', $allProps );
		$this->assertContains( 'Has student ID', $allProps );
		$this->assertContains( 'Has email', $allProps );
	}

	public function testGetEffectiveCategoryForUnknownReturnsEmpty(): void {
		$map = [
			'Person' => new CategoryModel( 'Person' ),
		];
		$resolver = new InheritanceResolver( $map );

		$effective = $resolver->getEffectiveCategory( 'Unknown' );
		$this->assertEquals( 'Unknown', $effective->getName() );
		$this->assertEmpty( $effective->getAllProperties() );
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

	public function testUnknownCategoryReturnsStandalone(): void {
		$map = [
			'Person' => new CategoryModel( 'Person' ),
		];
		$resolver = new InheritanceResolver( $map );

		$ancestors = $resolver->getAncestors( 'Unknown' );
		$this->assertEquals( [ 'Unknown' ], $ancestors );
	}
}
