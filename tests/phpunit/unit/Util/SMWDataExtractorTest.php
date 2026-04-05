<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit\Util;

use MediaWiki\Extension\SemanticSchemas\Util\SMWDataExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Concrete harness that exposes the trait's protected methods for testing.
 */
class SMWDataExtractorHarness {
	use SMWDataExtractor;

	public function callSplitTaggedFields( array $tagged ): array {
		return $this->splitTaggedFields( $tagged );
	}

	public function callBuildFieldSubobjectLines(
		array $taggedEntries,
		string $subobjectType,
		string $referenceProperty,
		string $namespacePrefix
	): array {
		return $this->buildFieldSubobjectLines(
			$taggedEntries, $subobjectType, $referenceProperty, $namespacePrefix
		);
	}
}

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Util\SMWDataExtractor
 */
class SMWDataExtractorTest extends TestCase {

	private SMWDataExtractorHarness $harness;

	protected function setUp(): void {
		parent::setUp();
		$this->harness = new SMWDataExtractorHarness();
	}

	/* =========================================================================
	 * splitTaggedFields
	 * ========================================================================= */

	public function testSplitTaggedFieldsSeparatesRequiredAndOptional(): void {
		$tagged = [
			[ 'name' => 'Has name', 'required' => true ],
			[ 'name' => 'Has email', 'required' => false ],
			[ 'name' => 'Has phone', 'required' => true ],
		];
		$result = $this->harness->callSplitTaggedFields( $tagged );
		$this->assertSame( [ 'Has name', 'Has phone' ], $result['required'] );
		$this->assertSame( [ 'Has email' ], $result['optional'] );
	}

	public function testSplitTaggedFieldsEmptyInput(): void {
		$result = $this->harness->callSplitTaggedFields( [] );
		$this->assertSame( [], $result['required'] );
		$this->assertSame( [], $result['optional'] );
	}

	public function testSplitTaggedFieldsAllRequired(): void {
		$tagged = [
			[ 'name' => 'A', 'required' => true ],
			[ 'name' => 'B', 'required' => true ],
		];
		$result = $this->harness->callSplitTaggedFields( $tagged );
		$this->assertSame( [ 'A', 'B' ], $result['required'] );
		$this->assertSame( [], $result['optional'] );
	}

	public function testSplitTaggedFieldsAllOptional(): void {
		$tagged = [
			[ 'name' => 'A', 'required' => false ],
			[ 'name' => 'B', 'required' => false ],
		];
		$result = $this->harness->callSplitTaggedFields( $tagged );
		$this->assertSame( [], $result['required'] );
		$this->assertSame( [ 'A', 'B' ], $result['optional'] );
	}

	/* =========================================================================
	 * buildFieldSubobjectLines
	 * ========================================================================= */

	public function testBuildFieldSubobjectLinesProducesCorrectWikitext(): void {
		$entries = [
			[ 'name' => 'Has name', 'required' => true ],
			[ 'name' => 'Has email', 'required' => false ],
		];

		$lines = $this->harness->callBuildFieldSubobjectLines(
			$entries, 'Has property field', 'Has property reference', 'Property'
		);

		$text = implode( "\n", $lines );

		$this->assertStringContainsString( '{{#subobject:has_property_field-1', $text );
		$this->assertStringContainsString( 'Has subobject type = Subobject:Has property field', $text );
		$this->assertStringContainsString( 'Has property reference = Property:Has name', $text );
		$this->assertStringContainsString( 'Is required = true', $text );

		$this->assertStringContainsString( '{{#subobject:has_property_field-2', $text );
		$this->assertStringContainsString( 'Has property reference = Property:Has email', $text );
		$this->assertStringContainsString( 'Is required = false', $text );
	}

	public function testBuildFieldSubobjectLinesForSubobjectFields(): void {
		$entries = [
			[ 'name' => 'Author', 'required' => true ],
		];

		$lines = $this->harness->callBuildFieldSubobjectLines(
			$entries, 'Has subobject field', 'Has subobject reference', 'Subobject'
		);

		$text = implode( "\n", $lines );

		$this->assertStringContainsString( '{{#subobject:has_subobject_field-1', $text );
		$this->assertStringContainsString( 'Has subobject type = Subobject:Has subobject field', $text );
		$this->assertStringContainsString( 'Has subobject reference = Subobject:Author', $text );
		$this->assertStringContainsString( 'Is required = true', $text );
	}

	public function testBuildFieldSubobjectLinesEmptyInput(): void {
		$lines = $this->harness->callBuildFieldSubobjectLines(
			[], 'Has property field', 'Has property reference', 'Property'
		);
		$this->assertSame( [], $lines );
	}

	public function testBuildFieldSubobjectLinesSequentialIds(): void {
		$entries = [
			[ 'name' => 'A', 'required' => true ],
			[ 'name' => 'B', 'required' => false ],
			[ 'name' => 'C', 'required' => true ],
		];

		$lines = $this->harness->callBuildFieldSubobjectLines(
			$entries, 'Has property field', 'Has property reference', 'Property'
		);

		$text = implode( "\n", $lines );

		$this->assertStringContainsString( '{{#subobject:has_property_field-1', $text );
		$this->assertStringContainsString( '{{#subobject:has_property_field-2', $text );
		$this->assertStringContainsString( '{{#subobject:has_property_field-3', $text );
	}
}
