<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit\Util;

use MediaWiki\Extension\SemanticSchemas\Util\SMWDataExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Concrete harness that exposes the trait's protected methods for testing.
 */
class SMWDataExtractorHarness {
	use SMWDataExtractor;
}

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Util\SMWDataExtractor
 *
 * Note: splitTaggedFields and buildFieldSubobjectLines have been moved out of
 * this trait. Field serialization is now handled by FieldDeclaration::toWikitext()
 * (see FieldDeclarationTest). The remaining SMW extraction methods
 * (smwFetchTaggedFieldReferences, smwFetchBoolean, etc.) require SMW mocks
 * and are covered by the integration tests in WikiCategoryStoreTest.
 */
class SMWDataExtractorTest extends TestCase {

	private SMWDataExtractorHarness $harness;

	protected function setUp(): void {
		parent::setUp();
		$this->harness = new SMWDataExtractorHarness();
	}

	public function testHarnessCanBeInstantiated(): void {
		$this->assertInstanceOf( SMWDataExtractorHarness::class, $this->harness );
	}
}
