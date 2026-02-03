<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit\Generator;

use MediaWiki\Extension\SemanticSchemas\Generator\DisplayStubGenerator;
use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\PropertyModel;
use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Generator\DisplayStubGenerator
 */
class DisplayStubGeneratorTest extends TestCase {

	public function testPropertyRowsAreConditionallyRendered(): void {
		$generator = $this->createGenerator();

		$category = new CategoryModel( 'Person', [
			'properties' => [
				'required' => [ 'Has name', 'Has email' ],
				'optional' => [],
			],
		] );

		$result = $this->callBuildWikitext( $generator, $category );

		// Each property row should be wrapped in {{#if:{{{param|}}}|...|}}
		$this->assertStringContainsString( '{{#if:{{{name|}}}|', $result );
		$this->assertStringContainsString( '{{#if:{{{email|}}}|', $result );

		// Should use {{!}} for pipe inside #if
		$this->assertStringContainsString( '{{!}}-', $result );
		$this->assertStringContainsString( '{{!}} {{Template:Property/Default', $result );

		// Raw |- row separators should NOT appear (replaced by {{!}}-)
		$this->assertStringNotContainsString( "|-\n!", $result );
	}

	public function testTableStructurePreserved(): void {
		$generator = $this->createGenerator();

		$category = new CategoryModel( 'Person', [
			'properties' => [
				'required' => [ 'Has name' ],
				'optional' => [],
			],
		] );

		$result = $this->callBuildWikitext( $generator, $category );

		// Table syntax escaped for use inside outer {{#if:}} wrapper
		$this->assertStringContainsString( '{{{!}} class="wikitable source-semanticschemas"', $result );
		$this->assertStringContainsString( '! Property !! Value', $result );
		$this->assertStringContainsString( '{{!}}}', $result );
		$this->assertStringContainsString( '<includeonly>', $result );

		// Raw table syntax should NOT appear (replaced by escaped versions)
		$this->assertStringNotContainsString( '{| class=', $result );
	}

	public function testTableSuppressedWhenAllParamsEmpty(): void {
		$generator = $this->createGenerator();

		$category = new CategoryModel( 'Person', [
			'properties' => [
				'required' => [ 'Has name', 'Has email' ],
				'optional' => [],
			],
		] );

		$result = $this->callBuildWikitext( $generator, $category );

		// Table should be wrapped in outer conditional checking all params
		$this->assertStringContainsString( '{{#if:{{{name|}}}{{{email|}}}|', $result );

		// Closing structure: {{!}}} (escaped |}) then |}} (close outer #if)
		$this->assertStringContainsString( "{{!}}}\n|}}", $result );
	}

	/* =========================================================================
	 * HELPERS
	 * ========================================================================= */

	private function createGenerator(): DisplayStubGenerator {
		$pageCreator = $this->createMock( PageCreator::class );

		$propertyStore = $this->createMock( WikiPropertyStore::class );
		$propertyStore->method( 'readProperty' )
			->willReturnCallback( static function ( string $name ): PropertyModel {
				return new PropertyModel( $name, [ 'datatype' => 'Text' ] );
			} );

		return new DisplayStubGenerator( $pageCreator, $propertyStore );
	}

	/**
	 * Access the private buildWikitext method via reflection.
	 */
	private function callBuildWikitext( DisplayStubGenerator $generator, CategoryModel $category ): string {
		$reflection = new \ReflectionMethod( $generator, 'buildWikitext' );
		$reflection->setAccessible( true );
		return $reflection->invoke( $generator, $category );
	}
}
