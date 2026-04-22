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

	private const TEMPLATE_DIR = __DIR__ . '/../../../../resources/base-config/templates/Category';

	/* =========================================================================
	 * DISPLAY-ROWS: EMPTY VALUE HIDING
	 * Replaces: testPropertyRowsWrappedInIfCondition,
	 *           testMultiplePropertiesEachHaveIfCondition,
	 *           testOptionalPropertiesAlsoHaveIfCondition,
	 *           testCustomRenderTemplateStillWrappedInIf
	 * ========================================================================= */

	public function testDisplayRowsUsesIfToHideEmptyValues(): void {
		$content = $this->loadTemplate( 'display-rows' );

		$this->assertStringContainsString(
			'{{#if:{{#show:{{{page|{{FULLPAGENAME}}}}}',
			$content,
			'display-rows must use #if with #show to conditionally render rows'
		);
	}

	/* =========================================================================
	 * DISPLAY-ROWS: TABLE ROW SYNTAX
	 * Replaces: testPropertyRowUsesMagicWordPipeEscape
	 * ========================================================================= */

	public function testDisplayRowsUsesPipeEscapeForTableSyntax(): void {
		$content = $this->loadTemplate( 'display-rows' );

		$this->assertStringContainsString( '{{!}}-', $content,
			'display-rows must use {{!}}- for table row separators' );
		$this->assertStringContainsString( '{{!}}', $content,
			'display-rows must use {{!}} for table cell separators' );
	}

	/* =========================================================================
	 * DISPLAY-ROWS: PROPERTY DISCOVERY VIA ANCESTORS
	 * Replaces: testValueExpressionPassedToRenderTemplate (value rendering)
	 * ========================================================================= */

	public function testDisplayRowsDiscoversPropertiesViaAncestorChain(): void {
		$content = $this->loadTemplate( 'display-rows' );

		$this->assertStringContainsString(
			'{{Category/ancestors|{{{category|}}}}}',
			$content,
			'display-rows must walk the ancestor chain to discover inherited properties'
		);
	}

	public function testDisplayRowsShowsPropertyValues(): void {
		$content = $this->loadTemplate( 'display-rows' );

		$this->assertStringContainsString(
			'{{#show:{{{page|{{FULLPAGENAME}}}}}|?{{PAGENAME:@@prop@@}}}}',
			$content,
			'display-rows must use #show to retrieve property values from the target page'
		);
	}

	public function testDisplayRowsSkipsHiddenProperties(): void {
		$content = $this->loadTemplate( 'display-rows' );

		$this->assertStringContainsString(
			'{{#ifeq:{{#show:@@prop@@|?Is hidden}}|true||',
			$content,
			'display-rows must skip rows for properties flagged Is hidden=true'
		);
	}

	public function testDisplayRowsShowsPropertyLabels(): void {
		$content = $this->loadTemplate( 'display-rows' );

		$this->assertStringContainsString(
			'{{#show:@@prop@@|?Display label|default={{PAGENAME:@@prop@@}}}}',
			$content,
			'display-rows must show the property display label with a fallback to the page name'
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

	public function testDisplayHeaderHasNoEditLink(): void {
		$content = $this->loadTemplate( 'display-header' );

		$this->assertStringNotContainsString(
			'Special:FormEdit',
			$content,
			'display-header must not include an edit link — used in subobject rendering too where the link does not apply'
		);
	}

	public function testDisplayHeaderUsesBakedLabelWhenProvided(): void {
		$content = $this->loadTemplate( 'display-header' );

		$this->assertStringContainsString(
			'{{#if:{{{label|}}}|{{{label}}}|',
			$content,
			'display-header must short-circuit the Display-label lookup when the caller passes a baked label'
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

	public function testTableTemplateHasBakedRowFastPath(): void {
		$content = $this->loadTemplate( 'table' );

		$this->assertStringContainsString(
			'{{#if:{{{props|}}}',
			$content,
			'table must branch on presence of baked props to take the fast path'
		);
		$this->assertStringContainsString(
			'{{{val_@@p@@|}}}',
			$content,
			'table fast path must look up per-property values via dynamic param name'
		);
		$this->assertStringContainsString(
			'{{{label_@@p@@|@@p@@}}}',
			$content,
			'table fast path must look up per-property labels, falling back to the param name'
		);
	}

	public function testTableTemplateForwardsBakedLabelToHeader(): void {
		$content = $this->loadTemplate( 'table' );

		$this->assertStringContainsString(
			'label={{{label|}}}',
			$content,
			'table must forward baked label to display-header'
		);
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

	public function testSideboxTemplateHasBakedRowFastPath(): void {
		$content = $this->loadTemplate( 'sidebox' );

		$this->assertStringContainsString(
			'{{#if:{{{props|}}}',
			$content,
			'sidebox must branch on presence of baked props'
		);
		$this->assertStringContainsString(
			'{{{val_@@p@@|}}}',
			$content,
			'sidebox fast path must look up per-property values via dynamic param name'
		);
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
	 * SUBOBJECTS: DISCOVERY QUERY
	 * ========================================================================= */

	public function testSubobjectsDiscoversTypesViaSubobjectField(): void {
		$content = $this->loadTemplate( 'subobjects' );

		$this->assertStringContainsString(
			'[[-Has subobject::{{Category/ancestors|{{{category|}}}}}]] [[Category:Subobject field]]',
			$content,
			'subobjects must walk the ancestor chain and filter Subobject field subobjects'
		);
		$this->assertStringContainsString(
			'?For category=',
			$content,
			'subobjects must read the For category printout with empty-label suffix'
		);
	}

	public function testSubobjectsUsesPlainlistWithMainlabelSuppressed(): void {
		$content = $this->loadTemplate( 'subobjects' );

		$this->assertStringContainsString(
			'mainlabel=-',
			$content,
			'subobjects discovery query must suppress the mainlabel so the CSV contains only For category values'
		);
		$this->assertStringContainsString(
			'format=plainlist',
			$content,
			'subobjects query must use plainlist (format=list wraps values in <span> markup)'
		);
	}

	/* =========================================================================
	 * SUBOBJECTS: INSTANCE RENDERING
	 * ========================================================================= */

	public function testSubobjectsInstanceRenderingUsesTemplate(): void {
		$content = $this->loadTemplate( 'subobjects' );

		$this->assertStringContainsString(
			'format=template',
			$content,
			'subobjects must render each instance via format=template'
		);
		$this->assertStringContainsString(
			'template=Category/subobject-instance',
			$content,
			'subobjects must use Category/subobject-instance as the row template'
		);
		$this->assertStringContainsString(
			'userparam={{PAGENAME:@@subcat@@}}',
			$content,
			'subobjects must pass the subobject category name (without Category: prefix) via userparam'
		);
	}

	public function testSubobjectsSortsBySortOrder(): void {
		$content = $this->loadTemplate( 'subobjects' );

		$this->assertStringContainsString(
			'sort=Has sort order',
			$content,
			'subobjects must sort by Has sort order so user-defined ordering is preserved'
		);
		$this->assertStringContainsString(
			'order=asc',
			$content,
			'subobjects must sort ascending'
		);
	}

	public function testSubobjectsUsesLiteralPipesInNestedAsk(): void {
		$content = $this->loadTemplate( 'subobjects' );

		// Inside an enclosing #arraymap, {{!}} evaluated at render time
		// confuses arraymap's formula splitter and produces "empty condition"
		// warnings. Literal | is safe because MW protects pipes inside {{...}}.
		$this->assertStringNotContainsString(
			'{{!}} format=template',
			$content,
			'nested #ask inside #arraymap must use literal | for its parameter separators, not {{!}}'
		);
	}

	public function testSubobjectsPassesSubjectAsPlainText(): void {
		$content = $this->loadTemplate( 'subobjects' );

		$this->assertStringContainsString(
			'link=none',
			$content,
			'subobjects must set link=none so the subobject fragment reaches subobject-instance as plain text'
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
			'=== {{#show:',
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

	public function testTableGatesRenderReverseOnBacklinksFlagWithSubobjectsFallback(): void {
		$content = $this->loadTemplate( 'table' );

		// render-reverse hardcodes {{FULLPAGENAME}} for backlink lookups, so
		// when Category/<Name>/subobject-row re-enters table, an ungated
		// render-reverse would duplicate the parent page's Backlinks section
		// inside every subobject mini-table. Dispatcher's fast path passes
		// subobjects=no (to suppress the nested Category/subobjects block)
		// while still wanting backlinks, so gate on `backlinks` with
		// `subobjects` as the default for backward compat.
		$this->assertStringContainsString(
			'{{#ifeq:{{{backlinks|{{{subobjects|yes}}}}}}|yes|{{#if:{{{backlink_section|}}}|{{{backlink_section}}}|{{Category/render-reverse',
			$content,
			'table must gate backlinks block on backlinks flag, with backlink_section fast path before render-reverse fallback'
		);
	}

	public function testSideboxGatesRenderReverseOnBacklinksFlagWithSubobjectsFallback(): void {
		$content = $this->loadTemplate( 'sidebox' );

		$this->assertStringContainsString(
			'{{#ifeq:{{{backlinks|{{{subobjects|yes}}}}}}|yes|{{#if:{{{backlink_section|}}}|{{{backlink_section}}}|{{Category/render-reverse',
			$content,
			'sidebox must gate backlinks block on backlinks flag, with backlink_section fast path before render-reverse fallback'
		);
	}

	/* =========================================================================
	 * COLLECT-ANCESTORS
	 * ========================================================================= */

	public function testCollectAncestorsWalksParentChain(): void {
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
	 * HELPERS
	 * ========================================================================= */

	private function loadTemplate( string $name ): string {
		$path = self::TEMPLATE_DIR . '/' . $name . '.wikitext';
		$this->assertFileExists( $path );
		return file_get_contents( $path );
	}
}
