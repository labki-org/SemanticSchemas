<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit\Schema;

use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\InheritanceResolver;
use MediaWiki\Extension\SemanticSchemas\Schema\MultiCategoryResolver;
use MediaWiki\Extension\SemanticSchemas\Schema\ResolvedPropertySet;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Schema\MultiCategoryResolver
 * @covers \MediaWiki\Extension\SemanticSchemas\Schema\ResolvedPropertySet
 */
class MultiCategoryResolverTest extends TestCase {

	/* =========================================================================
	 * EMPTY INPUT
	 * ========================================================================= */

	public function testEmptyInputReturnsEmptyResult(): void {
		$resolver = $this->createResolver( [] );
		$result = $resolver->resolve( [] );

		$this->assertInstanceOf( ResolvedPropertySet::class, $result );
		$this->assertSame( [], $result->getRequiredProperties() );
		$this->assertSame( [], $result->getOptionalProperties() );
		$this->assertSame( [], $result->getAllProperties() );
		$this->assertSame( [], $result->getRequiredSubobjects() );
		$this->assertSame( [], $result->getOptionalSubobjects() );
		$this->assertSame( [], $result->getAllSubobjects() );
		$this->assertSame( [], $result->getCategoryNames() );
	}

	/* =========================================================================
	 * SINGLE CATEGORY
	 * ========================================================================= */

	public function testSingleCategoryReturnsItsProperties(): void {
		$person = new CategoryModel( 'Person', [
			'properties' => [
				'required' => [ 'Has name', 'Has email' ],
				'optional' => [ 'Has phone' ],
			],
		] );

		$resolver = $this->createResolver( [ 'Person' => $person ] );
		$result = $resolver->resolve( [ 'Person' ] );

		$this->assertSame( [ 'Has name', 'Has email' ], $result->getRequiredProperties() );
		$this->assertSame( [ 'Has phone' ], $result->getOptionalProperties() );
		$this->assertSame( [ 'Has name', 'Has email', 'Has phone' ], $result->getAllProperties() );
		$this->assertSame( [ 'Person' ], $result->getCategoryNames() );
	}

	public function testSingleCategoryIncludesSubobjects(): void {
		$person = new CategoryModel( 'Person', [
			'subobjects' => [
				'required' => [ 'Address' ],
				'optional' => [ 'Social media' ],
			],
		] );

		$resolver = $this->createResolver( [ 'Person' => $person ] );
		$result = $resolver->resolve( [ 'Person' ] );

		$this->assertSame( [ 'Address' ], $result->getRequiredSubobjects() );
		$this->assertSame( [ 'Social media' ], $result->getOptionalSubobjects() );
		$this->assertSame( [ 'Address', 'Social media' ], $result->getAllSubobjects() );
	}

	/* =========================================================================
	 * SHARED PROPERTIES
	 * ========================================================================= */

	public function testSharedPropertyDeduplication(): void {
		$person = new CategoryModel( 'Person', [
			'properties' => [
				'required' => [ 'Has name' ],
			],
		] );

		$employee = new CategoryModel( 'Employee', [
			'properties' => [
				'required' => [ 'Has name', 'Has employee ID' ],
			],
		] );

		$resolver = $this->createResolver( [
			'Person' => $person,
			'Employee' => $employee,
		] );

		$result = $resolver->resolve( [ 'Person', 'Employee' ] );

		// "Has name" appears once
		$this->assertSame( [ 'Has name', 'Has employee ID' ], $result->getRequiredProperties() );
		$this->assertSame( [ 'Has name', 'Has employee ID' ], $result->getAllProperties() );

		// "Has name" is shared
		$this->assertTrue( $result->isSharedProperty( 'Has name' ) );
		$this->assertSame( [ 'Person', 'Employee' ], $result->getPropertySources( 'Has name' ) );

		// "Has employee ID" is not shared
		$this->assertFalse( $result->isSharedProperty( 'Has employee ID' ) );
		$this->assertSame( [ 'Employee' ], $result->getPropertySources( 'Has employee ID' ) );
	}

	/* =========================================================================
	 * REQUIRED PROMOTION
	 * ========================================================================= */

	public function testOptionalPromotedToRequiredAcrossCategories(): void {
		$personA = new CategoryModel( 'Person', [
			'properties' => [
				'optional' => [ 'Has phone' ],
			],
		] );

		$personB = new CategoryModel( 'Contact', [
			'properties' => [
				'required' => [ 'Has phone' ],
			],
		] );

		$resolver = $this->createResolver( [
			'Person' => $personA,
			'Contact' => $personB,
		] );

		$result = $resolver->resolve( [ 'Person', 'Contact' ] );

		// "Has phone" promoted to required
		$this->assertSame( [ 'Has phone' ], $result->getRequiredProperties() );
		$this->assertSame( [], $result->getOptionalProperties() );
		$this->assertSame( [ 'Has phone' ], $result->getAllProperties() );
	}

	/* =========================================================================
	 * DISJOINT CATEGORIES
	 * ========================================================================= */

	public function testDisjointCategoriesMergeAllProperties(): void {
		$person = new CategoryModel( 'Person', [
			'properties' => [
				'required' => [ 'Has name' ],
			],
		] );

		$vehicle = new CategoryModel( 'Vehicle', [
			'properties' => [
				'required' => [ 'Has license plate' ],
			],
		] );

		$resolver = $this->createResolver( [
			'Person' => $person,
			'Vehicle' => $vehicle,
		] );

		$result = $resolver->resolve( [ 'Person', 'Vehicle' ] );

		$this->assertSame( [ 'Has name', 'Has license plate' ], $result->getRequiredProperties() );
		$this->assertFalse( $result->isSharedProperty( 'Has name' ) );
		$this->assertFalse( $result->isSharedProperty( 'Has license plate' ) );
	}

	/* =========================================================================
	 * SUBOBJECT BEHAVIOR MIRRORS PROPERTIES
	 * ========================================================================= */

	public function testSharedSubobjectDeduplication(): void {
		$person = new CategoryModel( 'Person', [
			'subobjects' => [
				'required' => [ 'Address' ],
			],
		] );

		$company = new CategoryModel( 'Company', [
			'subobjects' => [
				'required' => [ 'Address' ],
			],
		] );

		$resolver = $this->createResolver( [
			'Person' => $person,
			'Company' => $company,
		] );

		$result = $resolver->resolve( [ 'Person', 'Company' ] );

		// "Address" appears once
		$this->assertSame( [ 'Address' ], $result->getRequiredSubobjects() );
		$this->assertSame( [ 'Address' ], $result->getAllSubobjects() );

		// "Address" is shared
		$this->assertTrue( $result->isSharedSubobject( 'Address' ) );
		$this->assertSame( [ 'Person', 'Company' ], $result->getSubobjectSources( 'Address' ) );
	}

	public function testSubobjectOptionalPromotedToRequired(): void {
		$person = new CategoryModel( 'Person', [
			'subobjects' => [
				'optional' => [ 'Social media' ],
			],
		] );

		$influencer = new CategoryModel( 'Influencer', [
			'subobjects' => [
				'required' => [ 'Social media' ],
			],
		] );

		$resolver = $this->createResolver( [
			'Person' => $person,
			'Influencer' => $influencer,
		] );

		$result = $resolver->resolve( [ 'Person', 'Influencer' ] );

		// "Social media" promoted to required
		$this->assertSame( [ 'Social media' ], $result->getRequiredSubobjects() );
		$this->assertSame( [], $result->getOptionalSubobjects() );
	}

	/* =========================================================================
	 * PROPERTY ORDERING
	 * ========================================================================= */

	public function testPropertyOrderPreservesCategoryInputOrder(): void {
		$catA = new CategoryModel( 'A', [
			'properties' => [
				'required' => [ 'X', 'Y' ],
			],
		] );

		$catB = new CategoryModel( 'B', [
			'properties' => [
				'required' => [ 'Z', 'W' ],
			],
		] );

		$resolver = $this->createResolver( [
			'A' => $catA,
			'B' => $catB,
		] );

		$result = $resolver->resolve( [ 'A', 'B' ] );

		// Order: A's properties first, then B's
		$this->assertSame( [ 'X', 'Y', 'Z', 'W' ], $result->getAllProperties() );
	}

	/* =========================================================================
	 * INHERITANCE INTEGRATION
	 * ========================================================================= */

	public function testInheritedPropertiesIncluded(): void {
		$person = new CategoryModel( 'Person', [
			'properties' => [
				'required' => [ 'Has name' ],
			],
		] );

		$student = new CategoryModel( 'Student', [
			'parents' => [ 'Person' ],
			'properties' => [
				'required' => [ 'Has student ID' ],
			],
		] );

		$resolver = $this->createResolver( [
			'Person' => $person,
			'Student' => $student,
		] );

		$result = $resolver->resolve( [ 'Student' ] );

		// Student inherits Person's properties
		$this->assertContains( 'Has name', $result->getAllProperties() );
		$this->assertContains( 'Has student ID', $result->getAllProperties() );
	}

	public function testDiamondInheritanceDeduplication(): void {
		$entity = new CategoryModel( 'Entity', [
			'properties' => [
				'required' => [ 'Has ID' ],
			],
		] );

		$person = new CategoryModel( 'Person', [
			'parents' => [ 'Entity' ],
			'properties' => [
				'required' => [ 'Has name' ],
			],
		] );

		$organization = new CategoryModel( 'Organization', [
			'parents' => [ 'Entity' ],
			'properties' => [
				'required' => [ 'Has tax ID' ],
			],
		] );

		$resolver = $this->createResolver( [
			'Entity' => $entity,
			'Person' => $person,
			'Organization' => $organization,
		] );

		$result = $resolver->resolve( [ 'Person', 'Organization' ] );

		// "Has ID" appears once (from shared ancestor)
		$properties = $result->getAllProperties();
		$idCount = count( array_filter( $properties, static fn ( $p ) => $p === 'Has ID' ) );
		$this->assertSame( 1, $idCount, '"Has ID" should appear exactly once' );

		// Both categories contribute to "Has ID"
		$this->assertTrue( $result->isSharedProperty( 'Has ID' ) );
		$this->assertSame( [ 'Person', 'Organization' ], $result->getPropertySources( 'Has ID' ) );
	}

	/* =========================================================================
	 * EDGE CASES
	 * ========================================================================= */

	public function testSourcesForUnknownPropertyReturnsEmptyArray(): void {
		$person = new CategoryModel( 'Person', [
			'properties' => [
				'required' => [ 'Has name' ],
			],
		] );

		$resolver = $this->createResolver( [ 'Person' => $person ] );
		$result = $resolver->resolve( [ 'Person' ] );

		$this->assertSame( [], $result->getPropertySources( 'Nonexistent' ) );
		$this->assertSame( [], $result->getSubobjectSources( 'Nonexistent' ) );
	}

	public function testIsSharedReturnsFalseForSingleSourceProperty(): void {
		$person = new CategoryModel( 'Person', [
			'properties' => [
				'required' => [ 'Has name' ],
			],
		] );

		$resolver = $this->createResolver( [ 'Person' => $person ] );
		$result = $resolver->resolve( [ 'Person' ] );

		$this->assertFalse( $result->isSharedProperty( 'Has name' ) );
		$this->assertSame( [ 'Person' ], $result->getPropertySources( 'Has name' ) );
	}

	public function testEmptyCategoryContributesNothingButAppearsInCategoryNames(): void {
		$empty = new CategoryModel( 'Empty', [] );
		$person = new CategoryModel( 'Person', [
			'properties' => [
				'required' => [ 'Has name' ],
			],
		] );

		$resolver = $this->createResolver( [
			'Empty' => $empty,
			'Person' => $person,
		] );

		$result = $resolver->resolve( [ 'Empty', 'Person' ] );

		// Empty contributes no properties
		$this->assertSame( [ 'Has name' ], $result->getAllProperties() );

		// But appears in category names
		$this->assertSame( [ 'Empty', 'Person' ], $result->getCategoryNames() );
	}

	/* =========================================================================
	 * HELPERS
	 * ========================================================================= */

	private function createResolver( array $categoryMap ): MultiCategoryResolver {
		$inheritanceResolver = new InheritanceResolver( $categoryMap );
		return new MultiCategoryResolver( $inheritanceResolver );
	}
}
