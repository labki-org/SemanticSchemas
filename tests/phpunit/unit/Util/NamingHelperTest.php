<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit\Util;

use MediaWiki\Extension\SemanticSchemas\Util\NamingHelper;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Util\NamingHelper
 */
class NamingHelperTest extends TestCase {

	/**
	 * @dataProvider providePropertyToParameter
	 */
	public function testPropertyToParameter( string $input, string $expected ): void {
		$this->assertSame( $expected, NamingHelper::propertyToParameter( $input ) );
	}

	public static function providePropertyToParameter(): array {
		return [
			'preserves Has prefix' => [ 'Has full name', 'has_full_name' ],
			'preserves Is prefix' => [ 'Is active', 'is_active' ],
			'no prefix property' => [ 'Name', 'name' ],
			'namespace colon to underscore' => [ 'Foaf:name', 'foaf_name' ],
			'spaces to underscores' => [ 'Research Area', 'research_area' ],
			'lowercased' => [ 'Has Person', 'has_person' ],
			'empty string' => [ '', '' ],
			'whitespace trimmed' => [ '  Has name  ', 'has_name' ],
			'no collision: Has name vs Name' => [
				'Has name', 'has_name',
			],
		];
	}

	public function testNoCollisionBetweenHasPrefixAndBare(): void {
		$this->assertNotSame(
			NamingHelper::propertyToParameter( 'Has name' ),
			NamingHelper::propertyToParameter( 'Name' ),
			'Properties "Has name" and "Name" must produce different parameters'
		);
	}

	/**
	 * @dataProvider provideGeneratePropertyLabel
	 */
	public function testGeneratePropertyLabel( string $input, string $expected ): void {
		$this->assertSame( $expected, NamingHelper::generatePropertyLabel( $input ) );
	}

	public static function provideGeneratePropertyLabel(): array {
		return [
			'strips Has prefix for label' => [ 'Has full name', 'Full Name' ],
			'strips Has_ prefix' => [ 'Has_research_area', 'Research Area' ],
			'strips Is prefix' => [ 'Is active', 'Active' ],
			'no prefix' => [ 'research_topic', 'Research Topic' ],
			'Has department' => [ 'Has department', 'Department' ],
		];
	}
}
