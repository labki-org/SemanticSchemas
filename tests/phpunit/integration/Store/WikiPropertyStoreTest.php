<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Integration\Store;

use MediaWiki\Extension\SemanticSchemas\Schema\PropertyModel;
use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore
 * @group Database
 * @group Broken
 */
class WikiPropertyStoreTest extends MediaWikiIntegrationTestCase {

	private WikiPropertyStore $propertyStore;
	private PageCreator $pageCreator;

	protected function setUp(): void {
		parent::setUp();

		// Skip if SMW is not available
		if ( !defined( 'SMW_NS_PROPERTY' ) ) {
			$this->markTestSkipped( 'Semantic MediaWiki is not installed' );
		}

		$this->pageCreator = new PageCreator( null );
		$this->propertyStore = new WikiPropertyStore( $this->pageCreator );
	}

	/* =========================================================================
	 * WRITE PROPERTY
	 * ========================================================================= */

	public function testWritePropertyCreatesNewProperty(): void {
		$property = new PropertyModel( 'Has test name ' . uniqid(), [
			'datatype' => 'Text',
			'description' => 'A test property',
		] );

		$result = $this->propertyStore->writeProperty( $property );

		$this->assertTrue( $result );
	}

	public function testWritePropertyWithTextDatatype(): void {
		$name = 'Has text prop ' . uniqid();
		$property = new PropertyModel( $name, [
			'datatype' => 'Text',
			'description' => 'Text property test',
		] );

		$result = $this->propertyStore->writeProperty( $property );

		$this->assertTrue( $result );

		// Verify page content contains correct semantic annotations
		$title = $this->pageCreator->makeTitle( $name, SMW_NS_PROPERTY );
		$content = $this->pageCreator->getPageContent( $title );
		$this->assertStringContainsString( '[[Has type::Text]]', $content );
	}

	public function testWritePropertyWithPageDatatype(): void {
		$name = 'Has page prop ' . uniqid();
		$property = new PropertyModel( $name, [
			'datatype' => 'Page',
			'description' => 'Page property test',
		] );

		$result = $this->propertyStore->writeProperty( $property );

		$this->assertTrue( $result );
		$title = $this->pageCreator->makeTitle( $name, SMW_NS_PROPERTY );
		$content = $this->pageCreator->getPageContent( $title );
		$this->assertStringContainsString( '[[Has type::Page]]', $content );
	}

	public function testWritePropertyWithDescription(): void {
		$name = 'Has described prop ' . uniqid();
		$description = 'This is a detailed description of the property';
		$property = new PropertyModel( $name, [
			'datatype' => 'Text',
			'description' => $description,
		] );

		$result = $this->propertyStore->writeProperty( $property );

		$this->assertTrue( $result );
		$title = $this->pageCreator->makeTitle( $name, SMW_NS_PROPERTY );
		$content = $this->pageCreator->getPageContent( $title );
		$this->assertStringContainsString( "[[Has description::$description]]", $content );
	}

	public function testWritePropertyWithAllowedValues(): void {
		$name = 'Has enum prop ' . uniqid();
		$property = new PropertyModel( $name, [
			'datatype' => 'Text',
			'allowedValues' => [ 'Option A', 'Option B', 'Option C' ],
		] );

		$result = $this->propertyStore->writeProperty( $property );

		$this->assertTrue( $result );
		$title = $this->pageCreator->makeTitle( $name, SMW_NS_PROPERTY );
		$content = $this->pageCreator->getPageContent( $title );
		$this->assertStringContainsString( '[[Allows value::Option A]]', $content );
		$this->assertStringContainsString( '[[Allows value::Option B]]', $content );
		$this->assertStringContainsString( '[[Allows value::Option C]]', $content );
	}

	public function testWritePropertyWithMultipleValuesAllowed(): void {
		$name = 'Has multi prop ' . uniqid();
		$property = new PropertyModel( $name, [
			'datatype' => 'Text',
			'allowsMultipleValues' => true,
		] );

		$result = $this->propertyStore->writeProperty( $property );

		$this->assertTrue( $result );
		$title = $this->pageCreator->makeTitle( $name, SMW_NS_PROPERTY );
		$content = $this->pageCreator->getPageContent( $title );
		$this->assertStringContainsString( '[[Allows multiple values::true]]', $content );
	}

	public function testWritePropertyPreservesExistingContentOutsideMarkers(): void {
		$name = 'Has preserved prop ' . uniqid();
		$title = $this->pageCreator->makeTitle( $name, SMW_NS_PROPERTY );

		// Create page with some initial content
		$initialContent = "== User Notes ==\nThis is user-added content.";
		$this->pageCreator->createOrUpdatePage(
			$title,
			$initialContent,
			'Initial content'
		);

		// Now write property through the store
		$property = new PropertyModel( $name, [
			'datatype' => 'Text',
			'description' => 'Property description',
		] );
		$this->propertyStore->writeProperty( $property );

		// User content should be preserved
		$content = $this->pageCreator->getPageContent( $title );
		$this->assertStringContainsString( '== User Notes ==', $content );
		$this->assertStringContainsString( 'This is user-added content.', $content );
		$this->assertStringContainsString( '[[Has type::Text]]', $content );
	}

	public function testWritePropertyAddsManagedCategory(): void {
		$name = 'Has managed prop ' . uniqid();
		$property = new PropertyModel( $name, [
			'datatype' => 'Text',
		] );

		$this->propertyStore->writeProperty( $property );

		$title = $this->pageCreator->makeTitle( $name, SMW_NS_PROPERTY );
		$content = $this->pageCreator->getPageContent( $title );
		$this->assertStringContainsString(
			'[[Category:SemanticSchemas-managed-property]]',
			$content
		);
	}

	public function testWritePropertyUpdatesExistingProperty(): void {
		$name = 'Has updatable prop ' . uniqid();

		// Create initial property
		$property1 = new PropertyModel( $name, [
			'datatype' => 'Text',
			'description' => 'Initial description',
		] );
		$this->propertyStore->writeProperty( $property1 );

		// Update property
		$property2 = new PropertyModel( $name, [
			'datatype' => 'Text',
			'description' => 'Updated description',
		] );
		$result = $this->propertyStore->writeProperty( $property2 );

		$this->assertTrue( $result );
		$title = $this->pageCreator->makeTitle( $name, SMW_NS_PROPERTY );
		$content = $this->pageCreator->getPageContent( $title );
		$this->assertStringContainsString( '[[Has description::Updated description]]', $content );
		$this->assertStringNotContainsString( 'Initial description', $content );
	}

	/* =========================================================================
	 * READ PROPERTY
	 * ========================================================================= */

	public function testReadPropertyReturnsNullForNonExistent(): void {
		$result = $this->propertyStore->readProperty( 'Has nonexistent prop ' . uniqid() );

		$this->assertNull( $result );
	}

	public function testReadPropertyReturnsPropertyModel(): void {
		$name = 'Has readable prop ' . uniqid();
		$property = new PropertyModel( $name, [
			'datatype' => 'Text',
			'description' => 'Readable property',
		] );
		$this->propertyStore->writeProperty( $property );

		// SMW needs to process the page - run jobs if needed
		$this->executeJobs();

		$result = $this->propertyStore->readProperty( $name );

		$this->assertInstanceOf( PropertyModel::class, $result );
		$this->assertEquals( $name, $result->getName() );
	}

	/* =========================================================================
	 * EDGE CASES
	 * ========================================================================= */

	public function testWritePropertyWithDisplayLabel(): void {
		$name = 'Has labelled prop ' . uniqid();
		$property = new PropertyModel( $name, [
			'datatype' => 'Text',
			'label' => 'Custom Label',
		] );

		$this->propertyStore->writeProperty( $property );

		$title = $this->pageCreator->makeTitle( $name, SMW_NS_PROPERTY );
		$content = $this->pageCreator->getPageContent( $title );
		$this->assertStringContainsString( '[[Display label::Custom Label]]', $content );
	}

	public function testWritePropertyWithRangeCategory(): void {
		$name = 'Has ranged prop ' . uniqid();
		$property = new PropertyModel( $name, [
			'datatype' => 'Page',
			'rangeCategory' => 'Person',
		] );

		$this->propertyStore->writeProperty( $property );

		$title = $this->pageCreator->makeTitle( $name, SMW_NS_PROPERTY );
		$content = $this->pageCreator->getPageContent( $title );
		$this->assertStringContainsString( '[[Has domain and range::Category:Person]]', $content );
	}

	public function testWritePropertyWithDateDatatype(): void {
		$name = 'Has date prop ' . uniqid();
		$property = new PropertyModel( $name, [
			'datatype' => 'Date',
		] );

		$result = $this->propertyStore->writeProperty( $property );

		$this->assertTrue( $result );
		$title = $this->pageCreator->makeTitle( $name, SMW_NS_PROPERTY );
		$content = $this->pageCreator->getPageContent( $title );
		$this->assertStringContainsString( '[[Has type::Date]]', $content );
	}

	public function testWritePropertyWithNumberDatatype(): void {
		$name = 'Has number prop ' . uniqid();
		$property = new PropertyModel( $name, [
			'datatype' => 'Number',
		] );

		$result = $this->propertyStore->writeProperty( $property );

		$this->assertTrue( $result );
		$title = $this->pageCreator->makeTitle( $name, SMW_NS_PROPERTY );
		$content = $this->pageCreator->getPageContent( $title );
		$this->assertStringContainsString( '[[Has type::Number]]', $content );
	}

	/**
	 * Helper to run any pending MediaWiki jobs.
	 */
	private function executeJobs(): void {
		$runner = $this->getServiceContainer()->getJobRunner();
		$runner->run( [
			'type' => false,
			'maxJobs' => 100,
			'maxTime' => 30,
		] );
	}
}
