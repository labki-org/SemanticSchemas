<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit\Generator;

use InvalidArgumentException;
use MediaWiki\Extension\SemanticSchemas\Generator\TemplateGenerator;
use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\EffectiveCategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\FieldModel;
use MediaWiki\Extension\SemanticSchemas\Schema\InheritanceResolver;
use MediaWiki\Extension\SemanticSchemas\Schema\PropertyModel;
use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;
use MediaWiki\Language\Language;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Generator\TemplateGenerator
 */
class TemplateGeneratorTest extends TestCase {

	private TemplateGenerator $generator;

	protected function setUp(): void {
		parent::setUp();

		$language = $this->createMock( Language::class );
		$language->method( 'getFormattedNsText' )
			->willReturn( 'Category' );

		$this->generator = new TemplateGenerator(
			$this->createMock( PageCreator::class ),
			$this->createMock( WikiPropertyStore::class ),
			$language
		);
	}

	/* =========================================================================
	 * SEMANTIC TEMPLATE GENERATION
	 * ========================================================================= */

	public function testGenerateSemanticTemplateReturnsString(): void {
		$category = new CategoryModel( 'Person', [
			'properties' => [
				new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY ),
			],
		] );

		$result = $this->generator->generateSemanticTemplate( $category );
		$this->assertIsString( $result );
	}

	public function testGenerateSemanticTemplateContainsNoInclude(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generator->generateSemanticTemplate( $category );

		$this->assertStringContainsString( '<noinclude>', $result );
		$this->assertStringContainsString( '</noinclude>', $result );
	}

	public function testGenerateSemanticTemplateContainsIncludeOnly(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generator->generateSemanticTemplate( $category );

		$this->assertStringContainsString( '<includeonly>', $result );
		$this->assertStringContainsString( '</includeonly>', $result );
	}

	public function testGenerateSemanticTemplateContainsSetParser(): void {
		$category = new CategoryModel( 'Person', [
			'properties' => [
				new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY ),
			],
		] );

		$result = $this->generator->generateSemanticTemplate( $category );
		$this->assertStringContainsString( '{{#set:', $result );
		$this->assertStringContainsString( '}}', $result );
	}

	public function testGenerateSemanticTemplateContainsPropertyMappings(): void {
		$category = new CategoryModel( 'Person', [
			'properties' => [
				new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY ),
				new FieldModel( 'Has email', true, FieldModel::TYPE_PROPERTY ),
			],
		] );

		$result = $this->generator->generateSemanticTemplate( $category );
		// Should contain property to parameter mappings
		$this->assertStringContainsString( 'Has name', $result );
		$this->assertStringContainsString( 'Has email', $result );
		$this->assertStringContainsString( '{{{has_name|}}}', $result );
		$this->assertStringContainsString( '{{{has_email|}}}', $result );
	}

	public function testSemanticTemplateDoesNotContainCategoryStamp(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generator->generateSemanticTemplate( $category );

		$this->assertStringNotContainsString( '[[Category:', $result );
	}

	public function testDispatcherTemplateContainsCategoryStamps(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generateDispatcher( $category );

		$this->assertStringContainsString( '[[Category:Person]]', $result );
		$this->assertStringContainsString( '[[Category:SemanticSchemas-managed]]', $result );
	}

	public function testGenerateSemanticTemplateWithEmptyNameThrowsException(): void {
		$this->expectException( InvalidArgumentException::class );

		// Create a mock that returns empty name
		$category = $this->createMock( CategoryModel::class );
		$category->method( 'getName' )->willReturn( '' );
		$category->method( 'getPropertyFields' )->willReturn( [] );

		$this->generator->generateSemanticTemplate( $category );
	}

	public function testGenerateSemanticTemplatePropertiesAreSorted(): void {
		$category = new CategoryModel( 'Person', [
			'properties' => [
				new FieldModel( 'Has zoo', true, FieldModel::TYPE_PROPERTY ),
				new FieldModel( 'Has apple', true, FieldModel::TYPE_PROPERTY ),
				new FieldModel( 'Has middle', true, FieldModel::TYPE_PROPERTY ),
			],
		] );

		$result = $this->generator->generateSemanticTemplate( $category );

		// Properties should be sorted alphabetically
		$applePos = strpos( $result, 'Has apple' );
		$middlePos = strpos( $result, 'Has middle' );
		$zooPos = strpos( $result, 'Has zoo' );

		$this->assertLessThan( $middlePos, $applePos );
		$this->assertLessThan( $zooPos, $middlePos );
	}

	/* =========================================================================
	 * DISPATCHER TEMPLATE GENERATION
	 * ========================================================================= */

	/**
	 * Helper: generate dispatcher for a single category (no inheritance).
	 */
	private function generateDispatcher( CategoryModel $category ): string {
		$effective = new EffectiveCategoryModel( $category->getName(), $category->toArray() );
		return $this->generator->generateDispatcherTemplate( $effective );
	}

	public function testGenerateDispatcherTemplateReturnsString(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generateDispatcher( $category );

		$this->assertIsString( $result );
	}

	public function testGenerateDispatcherTemplateContainsDefaultForm(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generateDispatcher( $category );

		$this->assertStringContainsString( '{{#default_form:Person}}', $result );
	}

	public function testGenerateDispatcherTemplateCallsSemanticTemplate(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generateDispatcher( $category );

		$this->assertStringContainsString( '{{Person/semantic', $result );
	}

	public function testGenerateDispatcherTemplateCallsDynamicDisplay(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generateDispatcher( $category );

		$this->assertStringContainsString( "{{Category/table\n | category=Person", $result );
	}

	public function testDispatcherBakesEffectiveLabelIntoFormatCall(): void {
		$category = new CategoryModel( 'Person', [
			'label' => 'Human being',
		] );
		$result = $this->generateDispatcher( $category );

		$this->assertStringContainsString( ' | label=Human being', $result );
	}

	public function testDispatcherBakesPropertyListIntoFormatCall(): void {
		$category = new CategoryModel( 'Person', [
			'properties' => [
				new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY ),
				new FieldModel( 'Has email', false, FieldModel::TYPE_PROPERTY ),
			],
		] );
		$result = $this->generateDispatcher( $category );

		// Property params are sorted alphabetically by property name ("email" < "name").
		$this->assertStringContainsString( ' | props=has_email,has_name', $result );
	}

	public function testDispatcherBakesPerPropertyValuePassthrough(): void {
		$category = new CategoryModel( 'Person', [
			'properties' => [
				new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY ),
			],
		] );
		$result = $this->generateDispatcher( $category );

		$this->assertStringContainsString( ' | val_has_name={{{has_name|}}}', $result );
	}

	public function testDispatcherBakesPerPropertyLabelFallback(): void {
		// With no WikiPropertyStore-resolved label, the label falls back to
		// NamingHelper::generatePropertyLabel ("Has name" → "Name").
		$category = new CategoryModel( 'Person', [
			'properties' => [
				new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY ),
			],
		] );
		$result = $this->generateDispatcher( $category );

		$this->assertStringContainsString( ' | label_has_name=Name', $result );
	}

	public function testDispatcherOmitsPropsParamWhenNoFields(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generateDispatcher( $category );

		$this->assertStringNotContainsString( ' | props=', $result );
		$this->assertStringNotContainsString( ' | val_', $result );
		$this->assertStringNotContainsString( ' | label_', $result );
	}

	public function testDispatcherWrapsValueInFieldRenderTemplateWhenSet(): void {
		$category = new CategoryModel( 'Person', [
			'properties' => [
				new FieldModel( 'Has email', false, FieldModel::TYPE_PROPERTY, 'Property/Email' ),
			],
		] );
		$result = $this->generateDispatcher( $category );

		$this->assertStringContainsString(
			' | val_has_email={{Property/Email | value={{{has_email|}}} }}',
			$result,
			'fields with a render template bake their value inside the template wrapper'
		);
	}

	public function testDispatcherEmitsBareValueWhenNoRenderTemplate(): void {
		$category = new CategoryModel( 'Person', [
			'properties' => [
				new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY ),
			],
		] );
		$result = $this->generateDispatcher( $category );

		$this->assertStringContainsString( ' | val_has_name={{{has_name|}}}', $result );
	}

	public function testDispatcherSkipsHiddenPropertiesFromDisplayBake(): void {
		$hiddenProp = new PropertyModel( 'Has sort order', [
			'datatype' => 'Number',
			'hidden' => true,
		] );
		$visibleProp = new PropertyModel( 'Has name', [
			'datatype' => 'Text',
		] );

		$propertyStore = $this->createMock( WikiPropertyStore::class );
		$propertyStore->method( 'readProperty' )->willReturnMap( [
			[ 'Has sort order', $hiddenProp ],
			[ 'Has name', $visibleProp ],
		] );

		$language = $this->createMock( Language::class );
		$language->method( 'getFormattedNsText' )->willReturn( 'Category' );

		$generator = new TemplateGenerator(
			$this->createMock( PageCreator::class ),
			$propertyStore,
			$language
		);

		$category = new CategoryModel( 'Chapter', [
			'properties' => [
				new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY ),
				new FieldModel( 'Has sort order', false, FieldModel::TYPE_PROPERTY ),
			],
		] );
		$effective = new EffectiveCategoryModel( $category->getName(), $category->toArray() );
		$result = $generator->generateDispatcherTemplate( $effective );

		// Visible property is baked; hidden property is skipped from display
		// (but still present in the semantic template, where it's needed for storage).
		$this->assertStringContainsString( ' | props=has_name', $result );
		$tableCall = strstr( $result, '{{Category/table' );
		$this->assertStringNotContainsString( 'has_sort_order', $tableCall );
		$this->assertStringContainsString( 'has_sort_order', $result );
	}

	public function testGenerateDispatcherTemplatePassesParameters(): void {
		$category = new CategoryModel( 'Person', [
			'properties' => [
				new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY ),
			],
		] );

		$result = $this->generateDispatcher( $category );
		// Should pass parameter to sub-templates
		$this->assertStringContainsString( '| has_name = {{{has_name|}}}', $result );
	}

	public function testGenerateDispatcherTemplateWithEmptyNameThrowsException(): void {
		$this->expectException( InvalidArgumentException::class );

		$category = $this->createMock( EffectiveCategoryModel::class );
		$category->method( 'getName' )->willReturn( '' );

		$this->generator->generateDispatcherTemplate( $category );
	}

	/* =========================================================================
	 * PARAMETER NAME CONVERSION
	 * ========================================================================= */

	public function testPropertyToParameterConversionInTemplate(): void {
		$category = new CategoryModel( 'Person', [
			'properties' => [
				new FieldModel( 'Has full name', true, FieldModel::TYPE_PROPERTY ),
			],
		] );

		$result = $this->generator->generateSemanticTemplate( $category );

		// "Has full name" preserves prefix → "has_full_name" parameter
		$this->assertStringContainsString( '{{{has_full_name|}}}', $result );
	}

	public function testMultiplePropertiesConvertedCorrectly(): void {
		$category = new CategoryModel( 'Person', [
			'properties' => [
				new FieldModel( 'Has first name', true, FieldModel::TYPE_PROPERTY ),
				new FieldModel( 'Has last name', true, FieldModel::TYPE_PROPERTY ),
				new FieldModel( 'Has email address', true, FieldModel::TYPE_PROPERTY ),
			],
		] );

		$result = $this->generator->generateSemanticTemplate( $category );

		$this->assertStringContainsString( '{{{has_first_name|}}}', $result );
		$this->assertStringContainsString( '{{{has_last_name|}}}', $result );
		$this->assertStringContainsString( '{{{has_email_address|}}}', $result );
	}

	/* =========================================================================
	 * GENERATE ALL TEMPLATES
	 * ========================================================================= */

	/* =========================================================================
	 * AUTO-GENERATED COMMENTS
	 * ========================================================================= */

	public function testSemanticTemplateContainsAutoGeneratedComment(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generator->generateSemanticTemplate( $category );

		$this->assertStringContainsString( 'AUTO-GENERATED by SemanticSchemas', $result );
		$this->assertStringContainsString( 'DO NOT EDIT MANUALLY', $result );
	}

	public function testDispatcherTemplateContainsAutoGeneratedComment(): void {
		$category = new CategoryModel( 'Person' );
		$result = $this->generateDispatcher( $category );

		$this->assertStringContainsString( 'AUTO-GENERATED by SemanticSchemas', $result );
		$this->assertStringContainsString( 'DO NOT EDIT MANUALLY', $result );
	}

	/* =========================================================================
	 * EDGE CASES
	 * ========================================================================= */

	public function testGenerateSemanticTemplateWithNoProperties(): void {
		$category = new CategoryModel( 'EmptyCategory' );
		$result = $this->generator->generateSemanticTemplate( $category );

		// Should still generate valid template structure
		$this->assertStringContainsString( '{{#set:', $result );
	}

	public function testCategoryNameWithSpacesInDispatcher(): void {
		$category = new CategoryModel( 'PhD Student' );
		$result = $this->generateDispatcher( $category );

		$this->assertStringContainsString( '{{#default_form:PhD Student}}', $result );
		$this->assertStringContainsString( '{{PhD Student/semantic', $result );
	}

	/* =========================================================================
	 * MULTI-VALUE PROPERTY HANDLING
	 * ========================================================================= */

	/**
	 * Create a TemplateGenerator whose WikiPropertyStore returns specific PropertyModel instances.
	 *
	 * @param array<string, PropertyModel> $propertyMap property name => PropertyModel
	 * @return TemplateGenerator
	 */
	private function generatorWithProperties( array $propertyMap ): TemplateGenerator {
		$propStore = $this->createMock( WikiPropertyStore::class );
		$propStore->method( 'readProperty' )
			->willReturnCallback( static fn ( string $name ) => $propertyMap[$name] ?? null );

		$language = $this->createMock( Language::class );
		$language->method( 'getFormattedNsText' )
			->willReturn( 'Category' );

		return new TemplateGenerator(
			$this->createMock( PageCreator::class ),
			$propStore,
			$language
		);
	}

	public function testMultiValueTextPropertyUsesSepSyntax(): void {
		$gen = $this->generatorWithProperties( [
			'Has tags' => new PropertyModel( 'Has tags', [
				'datatype' => 'Text',
				'allowsMultipleValues' => true,
			] ),
		] );

		$category = new CategoryModel( 'Article', [
			'properties' => [
				new FieldModel( 'Has tags', true, FieldModel::TYPE_PROPERTY ),
			],
		] );

		$result = $gen->generateSemanticTemplate( $category );
		$this->assertStringContainsString( 'Has tags = {{{has_tags|}}} |+sep=,', $result );
	}

	public function testSingleValueTextPropertyDoesNotUseSep(): void {
		$gen = $this->generatorWithProperties( [
			'Has title' => new PropertyModel( 'Has title', [
				'datatype' => 'Text',
				'allowsMultipleValues' => false,
			] ),
		] );

		$category = new CategoryModel( 'Article', [
			'properties' => [
				new FieldModel( 'Has title', true, FieldModel::TYPE_PROPERTY ),
			],
		] );

		$result = $gen->generateSemanticTemplate( $category );
		$this->assertStringContainsString( 'Has title = {{{has_title|}}}', $result );
		$this->assertStringNotContainsString( '+sep=', $result );
	}

	public function testMultiValuePagePropertyWithoutNamespaceUsesSep(): void {
		$gen = $this->generatorWithProperties( [
			'Has related' => new PropertyModel( 'Has related', [
				'datatype' => 'Page',
				'allowsMultipleValues' => true,
			] ),
		] );

		$category = new CategoryModel( 'Article', [
			'properties' => [
				new FieldModel( 'Has related', true, FieldModel::TYPE_PROPERTY ),
			],
		] );

		$result = $gen->generateSemanticTemplate( $category );
		$this->assertStringContainsString( 'Has related = {{{has_related|}}} |+sep=,', $result );
	}

	public function testMultiValuePagePropertyWithNamespaceUsesArraymap(): void {
		$gen = $this->generatorWithProperties( [
			'Has author' => new PropertyModel( 'Has author', [
				'datatype' => 'Page',
				'allowsMultipleValues' => true,
				'allowedNamespace' => 'User',
			] ),
		] );

		$category = new CategoryModel( 'Article', [
			'properties' => [
				new FieldModel( 'Has author', true, FieldModel::TYPE_PROPERTY ),
			],
		] );

		$result = $gen->generateSemanticTemplate( $category );
		$this->assertStringContainsString( '{{#arraymap:', $result );
		$this->assertStringContainsString( '|+sep=,', $result );
		// Only prefixes when value has no namespace (FULLPAGENAME == PAGENAME)
		$this->assertStringContainsString(
			'{{#ifeq:{{FULLPAGENAME:@@item@@}}|{{PAGENAME:@@item@@}}|User:}}@@item@@',
			$result
		);
	}

	public function testSingleValuePagePropertyWithNamespaceConditionallyPrefixes(): void {
		$gen = $this->generatorWithProperties( [
			'Has location' => new PropertyModel( 'Has location', [
				'datatype' => 'Page',
				'allowedNamespace' => 'Project',
			] ),
		] );

		$category = new CategoryModel( 'Article', [
			'properties' => [
				new FieldModel( 'Has location', true, FieldModel::TYPE_PROPERTY ),
			],
		] );

		$result = $gen->generateSemanticTemplate( $category );
		// Only prefixes when value has no namespace (FULLPAGENAME == PAGENAME)
		$expected = '{{#ifeq:{{FULLPAGENAME:{{{has_location|}}}}}'
			. '|{{PAGENAME:{{{has_location|}}}}}|Project:}}{{{has_location|}}}';
		$this->assertStringContainsString( $expected, $result );
	}

	public function testSingleValuePagePropertyWithoutNamespaceDoesNotUseSep(): void {
		$gen = $this->generatorWithProperties( [
			'Has homepage' => new PropertyModel( 'Has homepage', [
				'datatype' => 'Page',
				'allowsMultipleValues' => false,
			] ),
		] );

		$category = new CategoryModel( 'Article', [
			'properties' => [
				new FieldModel( 'Has homepage', true, FieldModel::TYPE_PROPERTY ),
			],
		] );

		$result = $gen->generateSemanticTemplate( $category );
		$this->assertStringContainsString( 'Has homepage = {{{has_homepage|}}}', $result );
		$this->assertStringNotContainsString( '+sep=', $result );
	}

	/* =========================================================================
	 * MODULAR TEMPLATES — INHERITANCE CHAIN
	 * ========================================================================= */

	public function testDispatcherCallsOnlyLeafSemanticTemplate(): void {
		$person = new CategoryModel( 'Person', [
			'properties' => [
				new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY ),
				new FieldModel( 'Has email', false, FieldModel::TYPE_PROPERTY ),
			],
		] );
		$student = new CategoryModel( 'Student', [
			'parents' => [ 'Person' ],
			'properties' => [
				new FieldModel( 'Has student ID', true, FieldModel::TYPE_PROPERTY ),
			],
		] );

		$categoryMap = [ 'Person' => $person, 'Student' => $student ];
		$resolver = new InheritanceResolver( $categoryMap );
		$effective = $resolver->getEffectiveCategory( 'Student' );

		$result = $this->generator->generateDispatcherTemplate( $effective );

		$this->assertStringContainsString( '{{Student/semantic', $result );
		$this->assertStringNotContainsString( '{{Person/semantic', $result );
		$this->assertStringContainsString( "{{Category/table\n | category=Student", $result );
		$this->assertStringContainsString( '[[Category:Student]]', $result );
	}

	public function testSemanticTemplateForEachAncestorHasOwnPropertiesOnly(): void {
		$person = new CategoryModel( 'Person', [
			'properties' => [
				new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY ),
			],
		] );
		$student = new CategoryModel( 'Student', [
			'parents' => [ 'Person' ],
			'properties' => [
				new FieldModel( 'Has student ID', true, FieldModel::TYPE_PROPERTY ),
			],
		] );

		$resolver = new InheritanceResolver( [ 'Person' => $person, 'Student' => $student ] );

		// Person/semantic: own props only, no parent calls, no category stamp
		$personSemantic = $this->generator->generateSemanticTemplate( $person );
		$this->assertStringContainsString( 'Has name', $personSemantic );
		$this->assertStringNotContainsString( 'Has student ID', $personSemantic );
		$this->assertStringNotContainsString( '[[Category:', $personSemantic );
		$this->assertStringNotContainsString( '/semantic', $personSemantic );

		// Student/semantic: embeds Person/semantic, then #set own props
		$parentEffectives = $resolver->getParentEffectiveModels( 'Student' );
		$studentSemantic = $this->generator->generateSemanticTemplate( $student, $parentEffectives );
		$this->assertStringContainsString( '{{Person/semantic', $studentSemantic );
		$this->assertStringContainsString( 'Has student ID', $studentSemantic );
		$this->assertStringNotContainsString( '[[Category:', $studentSemantic );
		// Person's property is forwarded to the parent semantic call, not set directly
		$parentCall = strstr( $studentSemantic, '{{#set:', true );
		$this->assertStringContainsString( 'name', $parentCall );
		// Student's own #set should not repeat Person's properties
		$this->assertStringNotContainsString( 'Has name = ', $studentSemantic );
	}

	public function testDispatcherUsesSideboxFormatWhenConfigured(): void {
		$category = new CategoryModel( 'Person', [
			'display' => [ 'format' => 'sidebox' ],
		] );
		$result = $this->generateDispatcher( $category );

		$this->assertStringContainsString( "{{Category/sidebox\n | category=Person", $result );
		$this->assertStringNotContainsString( '{{Category/table', $result );
	}

	public function testDispatcherSkipsFormatTemplateWhenNone(): void {
		$category = new CategoryModel( 'Person', [
			'properties' => [
				new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY ),
			],
			'display' => [ 'format' => 'none' ],
		] );
		$result = $this->generateDispatcher( $category );

		$this->assertStringNotContainsString( '{{Category/table', $result );
		$this->assertStringNotContainsString( '{{Category/sidebox', $result );
	}

	public function testDispatcherIncludesCustomDisplayTemplate(): void {
		$category = new CategoryModel( 'Person', [
			'properties' => [
				new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY ),
			],
			'display' => [ 'template' => 'Template:Person/custom' ],
		] );
		$effective = new EffectiveCategoryModel( $category->getName(), $category->toArray() );
		$result = $this->generator->generateDispatcherTemplate( $effective );

		// Should have both dynamic display AND custom template
		$this->assertStringContainsString( "{{Category/table\n | category=Person", $result );
		$this->assertStringContainsString( '{{Person/custom', $result );
	}

	public function testDispatcherCustomTemplateOnlyWithFormatNone(): void {
		$category = new CategoryModel( 'Person', [
			'properties' => [
				new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY ),
			],
			'display' => [ 'format' => 'none', 'template' => 'Template:Person/custom' ],
		] );
		$effective = new EffectiveCategoryModel( $category->getName(), $category->toArray() );
		$result = $this->generator->generateDispatcherTemplate( $effective );

		// Only custom template, no format template
		$this->assertStringNotContainsString( '{{Category/table', $result );
		$this->assertStringContainsString( '{{Person/custom', $result );
	}

	public function testDispatcherCustomTemplateSurvivesInheritanceResolution(): void {
		$parent = new CategoryModel( 'Person', [
			'properties' => [
				new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY ),
			],
		] );
		$child = new CategoryModel( 'Student', [
			'parents' => [ 'Person' ],
			'properties' => [
				new FieldModel( 'Has student ID', true, FieldModel::TYPE_PROPERTY ),
			],
			'display' => [ 'template' => 'Template:Student/custom' ],
		] );

		$resolver = new InheritanceResolver( [ 'Person' => $parent, 'Student' => $child ] );
		$effective = $resolver->getEffectiveCategory( 'Student' );
		$result = $this->generator->generateDispatcherTemplate( $effective );

		$this->assertStringContainsString( "{{Category/table\n | category=Student", $result );
		$this->assertStringContainsString( '{{Student/custom', $result );
	}

	public function testSemanticTemplateForwardsOnlyParentEffectiveParams(): void {
		$person = new CategoryModel( 'Person', [
			'properties' => [
				new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY ),
			],
		] );
		$student = new CategoryModel( 'Student', [
			'parents' => [ 'Person' ],
			'properties' => [
				new FieldModel( 'Has student ID', true, FieldModel::TYPE_PROPERTY ),
			],
		] );

		$resolver = new InheritanceResolver( [ 'Person' => $person, 'Student' => $student ] );
		$parentEffectives = $resolver->getParentEffectiveModels( 'Student' );

		$result = $this->generator->generateSemanticTemplate( $student, $parentEffectives );

		// Everything before {{#set: is the parent template calls section
		$parentSection = strstr( $result, '{{#set:', true );
		$this->assertStringContainsString( '{{Person/semantic', $parentSection );
		$this->assertStringContainsString( 'name', $parentSection );
		// Student-only param should NOT be forwarded to Person
		$this->assertStringNotContainsString( 'student_id', $parentSection );
	}

	/* =========================================================================
	 * SUBOBJECT TEMPLATE GENERATION
	 * ========================================================================= */

	public function testDispatcherContainsCategoryMembership(): void {
		$category = new CategoryModel( 'Person', [
			'properties' => [
				new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY ),
			],
		] );

		$dispatcher = $this->generateDispatcher( $category );

		$this->assertStringContainsString( '[[Category:Person]]', $dispatcher );
		$this->assertStringContainsString( '[[Category:SemanticSchemas-managed]]', $dispatcher );
	}

	public function testDispatcherExcludesSelfMembershipForMetacategory(): void {
		$category = new CategoryModel( 'Category' );

		$dispatcher = $this->generateDispatcher( $category );

		$this->assertStringNotContainsString( '[[Category:Category]]', $dispatcher );
		$this->assertStringContainsString( '[[Category:SemanticSchemas-managed]]', $dispatcher );
	}

	public function testDispatcherInlinesSubobjectSectionsWhenResolverProvided(): void {
		$subCategory = new CategoryModel( 'Address', [
			'properties' => [
				new FieldModel( 'Has street', true, FieldModel::TYPE_PROPERTY ),
				new FieldModel( 'Has city', true, FieldModel::TYPE_PROPERTY ),
			],
		] );

		$person = new CategoryModel( 'Person', [
			'properties' => [
				new FieldModel( 'Has name', true, FieldModel::TYPE_PROPERTY ),
			],
			'subobjects' => [
				new FieldModel( 'Address', true, FieldModel::TYPE_SUBOBJECT ),
			],
		] );

		$resolver = new InheritanceResolver( [
			'Person' => $person,
			'Address' => $subCategory,
		] );

		$effective = $resolver->getEffectiveCategory( 'Person' );
		$dispatcher = $this->generator->generateDispatcherTemplate( $effective, $resolver );

		// Dispatcher emits a projected #ask per subobject type, bypassing the
		// dynamic Category/subobjects discovery path. Category/table gets
		// subobjects=no so it doesn't also invoke the nested block.
		$this->assertStringContainsString( '=== Address ===', $dispatcher );
		$this->assertStringContainsString(
			'{{#ask: [[-Has subobject::{{FULLPAGENAME}}]] [[Category:Address]]',
			$dispatcher
		);
		$this->assertStringContainsString( '| ?Has street=has_street', $dispatcher );
		$this->assertStringContainsString( '| ?Has city=has_city', $dispatcher );
		$this->assertStringContainsString( '| template=Address/subobject-row', $dispatcher );
		$this->assertStringContainsString( '| named args=yes', $dispatcher );
		$this->assertStringContainsString( ' | subobjects=no', $dispatcher );
		// backlinks defaults to subobjects, which the dispatcher has just set to
		// no; pass it explicitly as yes to keep render-reverse on top-level pages.
		$this->assertStringContainsString( ' | backlinks=yes', $dispatcher );
	}

	public function testDispatcherOmitsSubobjectSectionsWithoutResolver(): void {
		$person = new CategoryModel( 'Person', [
			'subobjects' => [
				new FieldModel( 'Address', true, FieldModel::TYPE_SUBOBJECT ),
			],
		] );
		$effective = new EffectiveCategoryModel( 'Person', $person->toArray() );

		// No resolver → no inline subobject sections; Category/table's dynamic
		// fallback handles them instead (Category/subobjects isn't suppressed).
		$dispatcher = $this->generator->generateDispatcherTemplate( $effective );

		$this->assertStringNotContainsString( '#ask', $dispatcher );
		$this->assertStringNotContainsString( '/subobject-row', $dispatcher );
		$this->assertStringNotContainsString( ' | subobjects=no', $dispatcher );
	}

	public function testDispatcherSubobjectSectionOmitsSortWhenNoSortOrderField(): void {
		$address = new CategoryModel( 'Address', [
			'properties' => [
				new FieldModel( 'Has street', true, FieldModel::TYPE_PROPERTY ),
			],
		] );
		$person = new CategoryModel( 'Person', [
			'subobjects' => [
				new FieldModel( 'Address', true, FieldModel::TYPE_SUBOBJECT ),
			],
		] );
		$resolver = new InheritanceResolver( [
			'Person' => $person,
			'Address' => $address,
		] );
		$effective = $resolver->getEffectiveCategory( 'Person' );

		$dispatcher = $this->generator->generateDispatcherTemplate( $effective, $resolver );

		// Address has no Has sort order field — omit sort= to avoid SMW
		// silently dropping subobjects without a sort value.
		$this->assertStringNotContainsString( 'sort=Has sort order', $dispatcher );
	}

	public function testDispatcherSubobjectSectionIncludesSortWhenSortOrderPresent(): void {
		$chapter = new CategoryModel( 'Chapter', [
			'properties' => [
				new FieldModel( 'Has chapter title', true, FieldModel::TYPE_PROPERTY ),
				new FieldModel( 'Has sort order', false, FieldModel::TYPE_PROPERTY ),
			],
		] );
		$book = new CategoryModel( 'Book', [
			'subobjects' => [
				new FieldModel( 'Chapter', true, FieldModel::TYPE_SUBOBJECT ),
			],
		] );
		$resolver = new InheritanceResolver( [
			'Book' => $book,
			'Chapter' => $chapter,
		] );
		$effective = $resolver->getEffectiveCategory( 'Book' );

		$dispatcher = $this->generator->generateDispatcherTemplate( $effective, $resolver );

		$this->assertStringContainsString( ' | sort=Has sort order', $dispatcher );
		$this->assertStringContainsString( ' | order=asc', $dispatcher );
	}

	/**
	 * Helper: call the private generateSubobjectTemplate method via reflection.
	 */
	private function callGenerateSubobjectTemplate( EffectiveCategoryModel $sub ): string {
		$method = new \ReflectionMethod( $this->generator, 'generateSubobjectTemplate' );
		$method->setAccessible( true );
		return $method->invoke( $this->generator, $sub );
	}

	public function testSubobjectTemplateUsesAtCategory(): void {
		$address = new CategoryModel( 'Address', [
			'properties' => [
				new FieldModel( 'Has street', true, FieldModel::TYPE_PROPERTY ),
				new FieldModel( 'Has city', true, FieldModel::TYPE_PROPERTY ),
			],
		] );

		$resolver = new InheritanceResolver( [ 'Address' => $address ] );
		$effective = $resolver->getEffectiveCategory( 'Address' );
		$result = $this->callGenerateSubobjectTemplate( $effective );

		$this->assertStringContainsString( '{{#subobject:', $result );
		$this->assertStringContainsString( '@category=Address', $result );
		$this->assertStringContainsString( 'Has street', $result );
		$this->assertStringContainsString( 'Has city', $result );
	}

	/**
	 * Helper: call the private generateSubobjectRowTemplate method via reflection.
	 */
	private function callGenerateSubobjectRowTemplate( EffectiveCategoryModel $sub ): string {
		$method = new \ReflectionMethod( $this->generator, 'generateSubobjectRowTemplate' );
		$method->setAccessible( true );
		return $method->invoke( $this->generator, $sub );
	}

	public function testSubobjectRowTemplateWrapsCategoryTable(): void {
		$chapter = new CategoryModel( 'Chapter', [
			'properties' => [
				new FieldModel( 'Has chapter title', true, FieldModel::TYPE_PROPERTY ),
			],
		] );
		$resolver = new InheritanceResolver( [ 'Chapter' => $chapter ] );
		$effective = $resolver->getEffectiveCategory( 'Chapter' );

		$result = $this->callGenerateSubobjectRowTemplate( $effective );

		$this->assertStringContainsString( '{{Category/table', $result );
		$this->assertStringContainsString( ' | category=Chapter', $result );
		$this->assertStringContainsString( ' | subobjects=no', $result );
		$this->assertStringContainsString( ' | backlinks=no', $result );
		$this->assertStringContainsString( ' | props=has_chapter_title', $result );
		$this->assertStringContainsString(
			' | val_has_chapter_title={{{has_chapter_title|}}}',
			$result
		);
	}

	public function testSubobjectTemplateIncludesInheritedProperties(): void {
		$base = new CategoryModel( 'Address', [
			'properties' => [
				new FieldModel( 'Has street', true, FieldModel::TYPE_PROPERTY ),
				new FieldModel( 'Has city', true, FieldModel::TYPE_PROPERTY ),
			],
		] );
		$child = new CategoryModel( 'MailingAddress', [
			'parents' => [ 'Address' ],
			'properties' => [
				new FieldModel( 'Has zip', true, FieldModel::TYPE_PROPERTY ),
			],
		] );

		$resolver = new InheritanceResolver( [
			'Address' => $base,
			'MailingAddress' => $child,
		] );
		$effective = $resolver->getEffectiveCategory( 'MailingAddress' );
		$result = $this->callGenerateSubobjectTemplate( $effective );

		$this->assertStringContainsString( '@category=MailingAddress', $result );
		// Inherited from Address
		$this->assertStringContainsString( 'Has street', $result );
		$this->assertStringContainsString( 'Has city', $result );
		// Own property
		$this->assertStringContainsString( 'Has zip', $result );
	}
}
