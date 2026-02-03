<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit\Generator;

use MediaWiki\Extension\SemanticSchemas\Generator\CompositeFormGenerator;
use MediaWiki\Extension\SemanticSchemas\Generator\PropertyInputMapper;
use MediaWiki\Extension\SemanticSchemas\Schema\PropertyModel;
use MediaWiki\Extension\SemanticSchemas\Schema\ResolvedPropertySet;
use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;
use MediaWiki\Extension\SemanticSchemas\Store\WikiSubobjectStore;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Generator\CompositeFormGenerator
 */
class CompositeFormGeneratorTest extends TestCase {

	/* =========================================================================
	 * VALIDATION
	 * ========================================================================= */

	public function testThrowsForSingleCategory(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'at least 2 categories' );

		$resolved = new ResolvedPropertySet(
			[], // required properties
			[], // optional properties
			[], // property sources
			[], // required subobjects
			[], // optional subobjects
			[], // subobject sources
			[ 'Person' ] // single category
		);

		$generator = $this->createGenerator();
		$generator->generateCompositeForm( $resolved );
	}

	/* =========================================================================
	 * DISJOINT CATEGORIES (no shared properties)
	 * ========================================================================= */

	public function testDisjointCategoriesProduceTwoTemplateSections(): void {
		$resolved = new ResolvedPropertySet(
			[ 'Has name', 'Has email' ], // required
			[ 'Has phone', 'Has address' ], // optional
			[
				'Has name' => [ 'Person' ],
				'Has phone' => [ 'Person' ],
				'Has email' => [ 'Contact' ],
				'Has address' => [ 'Contact' ],
			],
			[], // required subobjects
			[], // optional subobjects
			[], // subobject sources
			[ 'Person', 'Contact' ]
		);

		$generator = $this->createGenerator();
		$result = $generator->generateCompositeForm( $resolved );

		// Should have two template sections
		$this->assertStringContainsString( '{{{for template|Person|label=Person Properties}}}', $result );
		$this->assertStringContainsString( '{{{for template|Contact|label=Contact Properties}}}', $result );
		$this->assertStringContainsString( '{{{end template}}}', $result );

		// Person section should have Has name, Has phone
		$personStart = strpos( $result, '{{{for template|Person' );
		$contactStart = strpos( $result, '{{{for template|Contact' );
		$personSection = substr( $result, $personStart, $contactStart - $personStart );

		$this->assertStringContainsString( 'Has name', $personSection );
		$this->assertStringContainsString( 'Has phone', $personSection );
		$this->assertStringNotContainsString( 'Has email', $personSection );
		$this->assertStringNotContainsString( 'Has address', $personSection );

		// Contact section should have Has email, Has address
		$contactSection = substr( $result, $contactStart );
		$this->assertStringContainsString( 'Has email', $contactSection );
		$this->assertStringContainsString( 'Has address', $contactSection );
		$this->assertStringNotContainsString( 'Has name', $contactSection );
		$this->assertStringNotContainsString( 'Has phone', $contactSection );
	}

	/* =========================================================================
	 * SHARED PROPERTY HANDLING (core deduplication behavior)
	 * ========================================================================= */

	public function testSharedPropertyMergedIntoFirstCategoryWithSubHeaders(): void {
		$resolved = new ResolvedPropertySet(
			[ 'Has name', 'Has email', 'Has employee ID' ], // required
			[], // optional
			[
				'Has name' => [ 'Person', 'Employee' ], // shared
				'Has email' => [ 'Person' ], // Person-specific
				'Has employee ID' => [ 'Employee' ], // Employee-specific
			],
			[], // required subobjects
			[], // optional subobjects
			[], // subobject sources
			[ 'Person', 'Employee' ]
		);

		$generator = $this->createGenerator();
		$result = $generator->generateCompositeForm( $resolved );

		// First category merges shared + specific into one section
		$this->assertStringContainsString(
			'{{{for template|Person|label=Person Properties}}}', $result
		);
		$this->assertStringContainsString(
			'{{{for template|Employee|label=Employee Properties}}}', $result
		);

		// Sub-headers within the Person section separate shared from specific
		$personStart = strpos( $result, 'label=Person Properties' );
		$employeeStart = strpos( $result, 'label=Employee Properties' );
		$personSection = substr( $result, $personStart, $employeeStart - $personStart );

		$this->assertStringContainsString( "'''Shared Properties:'''", $personSection );
		$this->assertStringContainsString( "'''Person-Specific Properties:'''", $personSection );
		$this->assertStringContainsString( 'Has name', $personSection );
		$this->assertStringContainsString( 'Has email', $personSection );

		// Employee section should have Has employee ID, NOT shared Has name
		$employeeSection = substr( $result, $employeeStart );
		$this->assertStringContainsString( 'Has employee ID', $employeeSection );
		$this->assertStringNotContainsString(
			'Has name',
			$employeeSection,
			'Shared property should not appear in other category sections'
		);

		// Only one Person template block (no duplicate)
		$personForCount = substr_count( $result, '{{{for template|Person' );
		$this->assertSame( 1, $personForCount,
			'First category should have a single template block' );
	}

	/* =========================================================================
	 * REQUIRED/OPTIONAL SPLIT
	 * ========================================================================= */

	public function testRequiredOptionalSplitWithinSections(): void {
		$resolved = new ResolvedPropertySet(
			[ 'Has name' ], // required
			[ 'Has phone' ], // optional
			[
				'Has name' => [ 'Person' ],
				'Has phone' => [ 'Person' ],
			],
			[], // required subobjects
			[], // optional subobjects
			[], // subobject sources
			[ 'Person', 'Contact' ]
		);

		$generator = $this->createGenerator();
		$result = $generator->generateCompositeForm( $resolved );

		// Should have Required fields and Optional fields sections
		$this->assertStringContainsString( "'''Required fields:'''", $result );
		$this->assertStringContainsString( "'''Optional fields:'''", $result );

		// Required field should have mandatory=true
		$this->assertMatchesRegularExpression(
			'/Has name.*mandatory=true/s',
			$result,
			'Required property should have mandatory=true'
		);
	}

	/* =========================================================================
	 * CATEGORY WIKILINKS
	 * ========================================================================= */

	public function testCategoryWikilinksIncluded(): void {
		$resolved = new ResolvedPropertySet(
			[ 'Has name' ], // required
			[], // optional
			[ 'Has name' => [ 'Person' ] ],
			[], // required subobjects
			[], // optional subobjects
			[], // subobject sources
			[ 'Person', 'Employee', 'Contact' ]
		);

		$generator = $this->createGenerator();
		$result = $generator->generateCompositeForm( $resolved );

		// Should have [[Category:X]] for all categories
		$this->assertStringContainsString( '[[Category:Person]]', $result );
		$this->assertStringContainsString( '[[Category:Employee]]', $result );
		$this->assertStringContainsString( '[[Category:Contact]]', $result );
	}

	/* =========================================================================
	 * TEMPLATE SECTION LABELS
	 * ========================================================================= */

	public function testTemplateSectionLabels(): void {
		$resolved = new ResolvedPropertySet(
			[ 'Has name', 'Has dept' ], // required
			[], // optional
			[
				'Has name' => [ 'Person' ],
				'Has dept' => [ 'Employee' ],
			],
			[], // required subobjects
			[], // optional subobjects
			[], // subobject sources
			[ 'Person', 'Employee' ]
		);

		$generator = $this->createGenerator();
		$result = $generator->generateCompositeForm( $resolved );

		// Each category with properties should have a labeled template section
		$this->assertStringContainsString( '{{{for template|Person|label=Person Properties}}}', $result );
		$this->assertStringContainsString( '{{{for template|Employee|label=Employee Properties}}}', $result );
	}

	/* =========================================================================
	 * FORM NAMING
	 * ========================================================================= */

	public function testCompositeFormNamingAlphabetical(): void {
		$generator = $this->createGenerator();

		$this->assertSame(
			'Employee+Person',
			$generator->getCompositeFormName( [ 'Person', 'Employee' ] )
		);

		$this->assertSame(
			'A+M+Z',
			$generator->getCompositeFormName( [ 'Z', 'A', 'M' ] )
		);

		$this->assertSame(
			'Category1+Category2+Category3',
			$generator->getCompositeFormName( [ 'Category2', 'Category1', 'Category3' ] )
		);
	}

	/* =========================================================================
	 * FORM INPUT HEADER
	 * ========================================================================= */

	public function testFormInputWithAutocomplete(): void {
		$resolved = new ResolvedPropertySet(
			[ 'Has name' ], // required
			[], // optional
			[ 'Has name' => [ 'Person' ] ],
			[], // required subobjects
			[], // optional subobjects
			[], // subobject sources
			[ 'Person', 'Employee' ]
		);

		$generator = $this->createGenerator();
		$result = $generator->generateCompositeForm( $resolved );

		// Should have noinclude section with forminput
		$this->assertStringContainsString( '<noinclude>', $result );
		$this->assertStringContainsString( '{{#forminput:', $result );
		$this->assertStringContainsString( 'form=Employee+Person', $result );
		$this->assertStringContainsString( '</noinclude>', $result );
	}

	/* =========================================================================
	 * SAVE BEHAVIOR
	 * ========================================================================= */

	public function testGenerateAndSaveCallsPageCreator(): void {
		// Define PF_NS_FORM constant if not already defined (unit test environment)
		if ( !defined( 'PF_NS_FORM' ) ) {
			define( 'PF_NS_FORM', 106 );
		}

		$resolved = new ResolvedPropertySet(
			[ 'Has name' ], // required
			[], // optional
			[ 'Has name' => [ 'Person' ] ],
			[], // required subobjects
			[], // optional subobjects
			[], // subobject sources
			[ 'Person', 'Employee' ]
		);

		$pageCreator = $this->createMock( PageCreator::class );
		$pageCreator->method( 'makeTitle' )
			->with( 'Employee+Person', \PF_NS_FORM )
			->willReturn( null ); // Simulates title creation failure

		$generator = $this->createGenerator( $pageCreator );
		$result = $generator->generateAndSaveCompositeForm( $resolved );

		// When makeTitle returns null, method should return false gracefully
		$this->assertFalse( $result );
	}

	/* =========================================================================
	 * EMPTY SECTION HANDLING
	 * ========================================================================= */

	public function testAllSharedPropertiesNoSubHeaders(): void {
		// All properties shared — no category-specific properties
		$resolved = new ResolvedPropertySet(
			[ 'Has name' ], // required (shared)
			[], // optional
			[
				'Has name' => [ 'Person', 'Employee' ], // shared
			],
			[], // required subobjects
			[], // optional subobjects
			[], // subobject sources
			[ 'Person', 'Employee' ]
		);

		$generator = $this->createGenerator();
		$result = $generator->generateCompositeForm( $resolved );

		// Shared properties in first category's section
		$this->assertStringContainsString(
			'{{{for template|Person|label=Person Properties}}}', $result
		);

		// No sub-headers when only shared exist (no specific to contrast with)
		$this->assertStringNotContainsString( "'''Shared Properties:'''", $result );
		$this->assertStringNotContainsString( '-Specific Properties:', $result );

		// Employee has no category-specific properties, so section is skipped
		$this->assertStringNotContainsString( 'label=Employee Properties', $result );

		// Only 1 template block (first category with shared properties)
		$endCount = substr_count( $result, '{{{end template}}}' );
		$this->assertSame( 1, $endCount,
			'Only first category section should exist when all properties are shared' );
	}

	/* =========================================================================
	 * STANDARD FORM STRUCTURE
	 * ========================================================================= */

	public function testStandardFormStructure(): void {
		$resolved = new ResolvedPropertySet(
			[ 'Has name' ], // required
			[], // optional
			[ 'Has name' => [ 'Person' ] ],
			[], // required subobjects
			[], // optional subobjects
			[], // subobject sources
			[ 'Person', 'Employee' ]
		);

		$generator = $this->createGenerator();
		$result = $generator->generateCompositeForm( $resolved );

		// Should have standard form structure
		$this->assertStringContainsString( '<noinclude>', $result );
		$this->assertStringContainsString( '<includeonly>', $result );
		$this->assertStringContainsString( '</includeonly>', $result );
		$this->assertStringContainsString( '{{{info|page name=<page name>}}}', $result );

		// Should have standard inputs at end
		$this->assertStringContainsString( '{{{standard input|summary}}}', $result );
		$this->assertStringContainsString( '{{{standard input|save}}}', $result );
		$this->assertStringContainsString( '{{{standard input|preview}}}', $result );
		$this->assertStringContainsString( '{{{standard input|changes}}}', $result );
		$this->assertStringContainsString( '{{{standard input|cancel}}}', $result );
	}

	/* =========================================================================
	 * DEFAULT FORM OVERRIDE
	 * ========================================================================= */

	public function testCompositeFormIncludesDefaultFormOverride(): void {
		$resolved = new ResolvedPropertySet(
			[ 'Has name' ], // required
			[], // optional
			[ 'Has name' => [ 'Person' ] ],
			[], // required subobjects
			[], // optional subobjects
			[], // subobject sources
			[ 'Person', 'Employee' ]
		);

		$generator = $this->createGenerator();
		$result = $generator->generateCompositeForm( $resolved );

		// Should include #default_form with alphabetically sorted composite name
		$this->assertStringContainsString( '{{#default_form:Employee+Person}}', $result );
	}

	/* =========================================================================
	 * HELPERS
	 * ========================================================================= */

	private function createGenerator( ?PageCreator $pageCreator = null ): CompositeFormGenerator {
		// Mock PageCreator to avoid MediaWiki dependencies
		if ( $pageCreator === null ) {
			$pageCreator = $this->createMock( PageCreator::class );
		}

		$propertyStore = $this->createMock( WikiPropertyStore::class );
		$propertyStore->method( 'readProperty' )
			->willReturnCallback( static function ( string $name ): PropertyModel {
				return new PropertyModel( $name, [ 'datatype' => 'Text' ] );
			} );

		$subobjectStore = $this->createMock( WikiSubobjectStore::class );
		$inputMapper = new PropertyInputMapper();

		return new CompositeFormGenerator(
			$pageCreator,
			$propertyStore,
			$inputMapper,
			$subobjectStore
		);
	}
}
