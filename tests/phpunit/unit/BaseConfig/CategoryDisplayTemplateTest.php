<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit\BaseConfig;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the Category display wikitext templates in resources/base-config.
 *
 * These replace the old DisplayStubGeneratorTest coverage: the display
 * mechanism moved from PHP-generated per-category stubs to shared wikitext
 * templates that discover properties dynamically from the SMW store.
 *
 * @coversNothing Tests static wikitext template files, not PHP classes.
 */
class CategoryDisplayTemplateTest extends TestCase {

	private const TEMPLATE_BASE = __DIR__ . '/../../../../resources/base-config/templates';
	private const TEMPLATE_DIR = self::TEMPLATE_BASE . '/Category';

	/* =========================================================================
	 * DISPLAY-ROWS: COMPOSES PRIMITIVES
	 * Empty-value hiding, pipe-escape syntax, and property-value lookup now
	 * live in Category/property-row + Property/value (tested below).
	 * ========================================================================= */

	public function testDisplayRowsComposesEffectivePropertiesAndPropertyRow(): void {
		$content = $this->loadTemplate( 'display-rows' );

		$this->assertStringContainsString(
			'{{Category/effective-properties|category={{{category|}}}}}',
			$content,
			'display-rows must iterate the effective property list via Category/effective-properties'
		);
		$this->assertStringContainsString(
			'{{Category/property-row|prop=@@prop@@|page={{{page|{{FULLPAGENAME}}}}}}}',
			$content,
			'display-rows must delegate per-row rendering to Category/property-row'
		);
	}

	/* =========================================================================
	 * PROPERTY-ROW (Tier B renderer)
	 * ========================================================================= */

	public function testPropertyRowUsesIfToHideEmptyValues(): void {
		$content = $this->loadTemplate( 'property-row' );

		$this->assertStringContainsString(
			'{{#if:{{Property/value|prop={{{prop|}}}|page={{{page|{{FULLPAGENAME}}}}}}}',
			$content,
			'property-row must gate row emission on Property/value being non-empty'
		);
	}

	public function testPropertyRowUsesPipeEscapeForTableSyntax(): void {
		$content = $this->loadTemplate( 'property-row' );

		$this->assertStringContainsString( '{{!}}-', $content,
			'property-row must use {{!}}- for table row separators' );
		$this->assertStringContainsString( '{{!}}', $content,
			'property-row must use {{!}} for table cell separators' );
	}

	public function testPropertyRowDispatchesToPerPropertyOverride(): void {
		$content = $this->loadTemplate( 'property-row' );

		$this->assertStringContainsString(
			'{{#ifexist:Template:Category/property-row/{{PAGENAME:{{{prop|}}}}}',
			$content,
			'property-row must check for a per-property override template via #ifexist'
		);
		$this->assertStringContainsString(
			'{{Category/property-row/{{PAGENAME:{{{prop|}}}}}|prop={{{prop|}}}|page={{{page|{{FULLPAGENAME}}}}}}}',
			$content,
			'property-row must invoke the per-property override with prop= and page= when it exists'
		);
	}

	public function testPropertyRowRendersDefaultUsingPrimitives(): void {
		$content = $this->loadTemplate( 'property-row' );

		$this->assertStringContainsString(
			'{{Property/label|prop={{{prop|}}}}}',
			$content,
			'property-row default render must use Property/label for the header cell'
		);
		$this->assertStringContainsString(
			'{{Property/value|prop={{{prop|}}}|page={{{page|{{FULLPAGENAME}}}}}}}',
			$content,
			'property-row default render must use Property/value for the value cell'
		);
	}

	/* =========================================================================
	 * DISPLAY-HEADER
	 * ========================================================================= */

	public function testDisplayHeaderShowsCategoryLabel(): void {
		$content = $this->loadTemplate( 'display-header' );

		$this->assertStringContainsString(
			'{{#show:Category:{{{category|}}}|?Display label|default={{{category|}}}}}',
			$content,
			'display-header must show the category display label with name as fallback'
		);
	}

	public function testDisplayHeaderContainsEditLink(): void {
		$content = $this->loadTemplate( 'display-header' );

		$this->assertStringContainsString(
			'Special:FormEdit/{{{category|}}}',
			$content,
			'display-header must include an edit link via Special:FormEdit'
		);
	}

	/* =========================================================================
	 * TABLE FORMAT
	 * Replaces: testContainsWikitableMarkup
	 * ========================================================================= */

	public function testTableTemplateHasWikitableMarkup(): void {
		$content = $this->loadTemplate( 'table' );

		$this->assertStringContainsString( '{| class="wikitable', $content );
		$this->assertStringContainsString( '|}', $content );
	}

	public function testTableTemplateComposesSubTemplates(): void {
		$content = $this->loadTemplate( 'table' );

		$this->assertStringContainsString( '{{Category/display-header', $content );
		$this->assertStringContainsString( '{{Category/display-rows', $content );
		$this->assertStringContainsString( '{{Category/render-reverse', $content );
	}

	/* =========================================================================
	 * SIDEBOX FORMAT
	 * ========================================================================= */

	public function testSideboxTemplateHasWikitableMarkup(): void {
		$content = $this->loadTemplate( 'sidebox' );

		$this->assertStringContainsString( '{| class="wikitable', $content );
		$this->assertStringContainsString( '|}', $content );
	}

	public function testSideboxTemplateHasFloatStyling(): void {
		$content = $this->loadTemplate( 'sidebox' );

		$this->assertStringContainsString( 'float: right', $content,
			'sidebox must float to the right' );
		$this->assertStringContainsString( 'width: 300px', $content,
			'sidebox must have a fixed width' );
	}

	public function testSideboxTemplateComposesSubTemplates(): void {
		$content = $this->loadTemplate( 'sidebox' );

		$this->assertStringContainsString( '{{Category/display-header', $content );
		$this->assertStringContainsString( '{{Category/display-rows', $content );
		$this->assertStringContainsString( '{{Category/render-reverse', $content );
	}

	/* =========================================================================
	 * RENDER-REVERSE: BACKLINK ROWS
	 * Replaces: testBacklinkRowWithReverseLabel,
	 *           testBacklinkRowFallsBackToPropertyLabel,
	 *           testNoBacklinkRowsWithoutDeclaration,
	 *           testNonPageTypeBacklinkPropertyIgnored
	 * ========================================================================= */

	public function testRenderReverseOnlyRunsWhenBacklinksExist(): void {
		$content = $this->loadTemplate( 'render-reverse' );

		$this->assertStringContainsString(
			'{{#ifexpr:',
			$content,
			'render-reverse must guard with #ifexpr count check'
		);
		$this->assertStringContainsString(
			'format=count',
			$content,
			'render-reverse must check backlink counts before rendering'
		);
	}

	public function testRenderReverseReadsShowBacklinksFor(): void {
		$content = $this->loadTemplate( 'render-reverse' );

		$this->assertStringContainsString(
			'?Show backlinks for',
			$content,
			'render-reverse must read Show backlinks for from the category'
		);
	}

	public function testRenderReverseShowsBacklinksHeader(): void {
		$content = $this->loadTemplate( 'render-reverse' );

		$this->assertStringContainsString(
			'{{!}} Backlinks',
			$content,
			'render-reverse must show a Backlinks header'
		);
	}

	public function testRenderReverseShowsInversePropertyLabel(): void {
		$content = $this->loadTemplate( 'render-reverse' );

		$this->assertStringContainsString(
			'Inverse property label',
			$content,
			'render-reverse must use the Inverse property label for relationship names'
		);
	}

	public function testRenderReverseFallsBackToPropertyName(): void {
		$content = $this->loadTemplate( 'render-reverse' );

		$this->assertStringContainsString(
			'default={{PAGENAME:',
			$content,
			'render-reverse must fall back to property name when no inverse label set'
		);
	}

	/* =========================================================================
	 * SUBOBJECTS: COMPOSES PRIMITIVES
	 * The inverse-ask discovery, plainlist/mainlabel handling, sort, and
	 * format=template wiring all live in Category/effective-subobject-types
	 * and Category/subobject-instances (tested below).
	 * ========================================================================= */

	public function testSubobjectsComposesEffectiveTypesAndSubobjectInstances(): void {
		$content = $this->loadTemplate( 'subobjects' );

		$this->assertStringContainsString(
			'{{Category/effective-subobject-types|category={{{category|}}}}}',
			$content,
			'subobjects must iterate effective subobject types via Category/effective-subobject-types'
		);
		$this->assertStringContainsString(
			'{{Category/subobject-instances|subcat=@@subcat@@|page={{{page|{{FULLPAGENAME}}}}}}}',
			$content,
			'subobjects must delegate per-type instance rendering to Category/subobject-instances'
		);
	}

	public function testSubobjectsHeadingUsesHtmlTag(): void {
		$content = $this->loadTemplate( 'subobjects' );

		$this->assertStringContainsString(
			'<h3>',
			$content,
			'subobjects must use an HTML <h3> tag (arraymap trims the leading newline wikitext === needs)'
		);
		$this->assertStringNotContainsString(
			'=== ',
			$content,
			'subobjects must not use wikitext === headings (render as literal after arraymap trims newline)'
		);
	}

	/* =========================================================================
	 * SUBOBJECT-INSTANCE
	 * ========================================================================= */

	public function testSubobjectInstanceReinvokesTable(): void {
		$content = $this->loadTemplate( 'subobject-instance' );

		$this->assertStringContainsString(
			'{{Category/table',
			$content,
			'subobject-instance must reuse Category/table so subobjects share the category-page layout'
		);
	}

	public function testSubobjectInstanceReadsHashUserparam(): void {
		$content = $this->loadTemplate( 'subobject-instance' );

		// SMW's format=template exposes the userparam= value as {{{#userparam}}}
		// (hash-prefixed), not {{{userparam}}}. Using the wrong name produces
		// an empty category which triggers "Category: invalid characters".
		$this->assertStringContainsString(
			'{{{#userparam',
			$content,
			'subobject-instance must read {{{#userparam}}} (SMW\'s format=template param name is hash-prefixed)'
		);
	}

	public function testSubobjectInstanceReadsPositionalOneAsPage(): void {
		$content = $this->loadTemplate( 'subobject-instance' );

		$this->assertStringContainsString(
			'page={{{1|',
			$content,
			'subobject-instance must pass {{{1}}} (the subobject fragment) as the page parameter to Category/table'
		);
	}

	public function testSubobjectInstanceGuardsAgainstRecursion(): void {
		$content = $this->loadTemplate( 'subobject-instance' );

		$this->assertStringContainsString(
			'subobjects=no',
			$content,
			'subobject-instance must pass subobjects=no to prevent Category/table from recursing into nested subobjects'
		);
	}

	/* =========================================================================
	 * TABLE / SIDEBOX: RENDER-REVERSE GATING
	 * ========================================================================= */

	public function testTableGatesRenderReverseOnSubobjectsParam(): void {
		$content = $this->loadTemplate( 'table' );

		// render-reverse hardcodes {{FULLPAGENAME}} for backlink lookups, so
		// when Category/subobject-instance re-enters table with subobjects=no,
		// an ungated render-reverse would duplicate the parent page's
		// Backlinks section inside every subobject mini-table.
		$this->assertStringContainsString(
			'{{#ifeq:{{{subobjects|yes}}}|yes|{{Category/render-reverse',
			$content,
			'table must gate Category/render-reverse on subobjects=yes to keep backlinks out of subobject instances'
		);
	}

	public function testSideboxGatesRenderReverseOnSubobjectsParam(): void {
		$content = $this->loadTemplate( 'sidebox' );

		$this->assertStringContainsString(
			'{{#ifeq:{{{subobjects|yes}}}|yes|{{Category/render-reverse',
			$content,
			'sidebox must gate Category/render-reverse on subobjects=yes for the same reason as table'
		);
	}

	/* =========================================================================
	 * ANCESTORS (formerly collect-ancestors)
	 * ========================================================================= */

	public function testAncestorsWalksParentChain(): void {
		$content = $this->loadTemplate( 'ancestors' );

		$this->assertStringContainsString(
			'?Subcategory of',
			$content,
			'ancestors must query ?Subcategory of to walk the category hierarchy'
		);
		$this->assertStringContainsString(
			'{{Category/ancestors-L1',
			$content,
			'ancestors must delegate to L1 for deeper levels'
		);
	}

	/* =========================================================================
	 * TIER A: EFFECTIVE-PROPERTIES
	 * ========================================================================= */

	public function testEffectivePropertiesQueriesPropertyFieldsAcrossAncestors(): void {
		$content = $this->loadTemplate( 'effective-properties' );

		$this->assertStringContainsString(
			'[[-Has subobject::{{Category/ancestors|{{{category|}}}}}]] [[Category:Property field]]',
			$content,
			'effective-properties must walk the ancestor chain and filter Property field subobjects'
		);
		$this->assertStringContainsString(
			'?For property=',
			$content,
			'effective-properties must read the For property printout with empty-label suffix'
		);
	}

	public function testEffectivePropertiesUsesPlainlistWithMainlabelSuppressed(): void {
		$content = $this->loadTemplate( 'effective-properties' );

		$this->assertStringContainsString( 'mainlabel=-', $content,
			'effective-properties must suppress the mainlabel' );
		$this->assertStringContainsString( 'format=plainlist', $content,
			'effective-properties must use plainlist format to avoid <span> markup' );
		$this->assertStringContainsString( 'sep=,', $content,
			'effective-properties must separate entries with comma for arraymap consumption' );
	}

	/* =========================================================================
	 * TIER A: EFFECTIVE-SUBOBJECT-TYPES
	 * ========================================================================= */

	public function testEffectiveSubobjectTypesQueriesSubobjectFieldsAcrossAncestors(): void {
		$content = $this->loadTemplate( 'effective-subobject-types' );

		$this->assertStringContainsString(
			'[[-Has subobject::{{Category/ancestors|{{{category|}}}}}]] [[Category:Subobject field]]',
			$content,
			'effective-subobject-types must walk the ancestor chain and filter Subobject field subobjects'
		);
		$this->assertStringContainsString(
			'?For category=',
			$content,
			'effective-subobject-types must read the For category printout with empty-label suffix'
		);
	}

	public function testEffectiveSubobjectTypesUsesPlainlistWithMainlabelSuppressed(): void {
		$content = $this->loadTemplate( 'effective-subobject-types' );

		$this->assertStringContainsString( 'mainlabel=-', $content );
		$this->assertStringContainsString( 'format=plainlist', $content );
		$this->assertStringContainsString( 'sep=,', $content );
	}

	/* =========================================================================
	 * TIER A: SUBOBJECT-INSTANCES
	 * ========================================================================= */

	public function testSubobjectInstancesUsesFormatTemplate(): void {
		$content = $this->loadTemplate( 'subobject-instances' );

		$this->assertStringContainsString(
			'format=template',
			$content,
			'subobject-instances must dispatch each instance via SMW format=template'
		);
		$this->assertStringContainsString(
			'template={{{row-template|Category/subobject-instance}}}',
			$content,
			'subobject-instances must accept a row-template= parameter, defaulting to Category/subobject-instance'
		);
		$this->assertStringContainsString(
			'userparam={{PAGENAME:{{{subcat|}}}}}',
			$content,
			'subobject-instances must pass the subobject category name (without Category: prefix) via userparam'
		);
	}

	public function testSubobjectInstancesSortsBySortOrder(): void {
		$content = $this->loadTemplate( 'subobject-instances' );

		$this->assertStringContainsString( 'sort=Has sort order', $content,
			'subobject-instances must sort by Has sort order' );
		$this->assertStringContainsString( 'order=asc', $content,
			'subobject-instances must sort ascending' );
	}

	public function testSubobjectInstancesPassesSubjectAsPlainText(): void {
		$content = $this->loadTemplate( 'subobject-instances' );

		$this->assertStringContainsString( 'link=none', $content,
			'subobject-instances must set link=none so fragments reach the row template as plain text' );
	}

	/* =========================================================================
	 * TIER A: CATEGORY/LABEL
	 * ========================================================================= */

	public function testCategoryLabelShowsDisplayLabelWithFallback(): void {
		$content = $this->loadTemplate( 'label' );

		$this->assertStringContainsString(
			'{{#show:Category:{{{category|}}}|?Display label|default={{{category|}}}}}',
			$content,
			'Category/label must show Display label with fallback to the raw category name'
		);
	}

	/* =========================================================================
	 * TIER A: PROPERTY/LABEL + PROPERTY/VALUE
	 * ========================================================================= */

	public function testPropertyLabelShowsDisplayLabelWithFallback(): void {
		$content = $this->loadPropertyTemplate( 'label' );

		$this->assertStringContainsString(
			'{{#show:{{{prop|}}}|?Display label|default={{PAGENAME:{{{prop|}}}}}}}',
			$content,
			'Property/label must show Display label with fallback to PAGENAME of the property ref'
		);
	}

	public function testPropertyValueShowsPropertyOnPage(): void {
		$content = $this->loadPropertyTemplate( 'value' );

		$this->assertStringContainsString(
			'{{#show:{{{page|{{FULLPAGENAME}}}}}|?{{PAGENAME:{{{prop|}}}}}}}',
			$content,
			'Property/value must #show the property on the target page (defaulting page to FULLPAGENAME)'
		);
	}

	/* =========================================================================
	 * HELPERS
	 * ========================================================================= */

	private function loadTemplate( string $name ): string {
		$path = self::TEMPLATE_DIR . '/' . $name . '.wikitext';
		$this->assertFileExists( $path );
		return file_get_contents( $path );
	}

	private function loadPropertyTemplate( string $name ): string {
		$path = self::TEMPLATE_BASE . '/Property/' . $name . '.wikitext';
		$this->assertFileExists( $path );
		return file_get_contents( $path );
	}
}
