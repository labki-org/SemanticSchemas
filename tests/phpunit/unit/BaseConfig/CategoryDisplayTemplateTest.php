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
			'{{#if:{{#show:{{FULLPAGENAME}}',
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
			'{{Category/collect-ancestors|{{{category|}}}}}',
			$content,
			'display-rows must walk the ancestor chain to discover inherited properties'
		);
	}

	public function testDisplayRowsShowsPropertyValues(): void {
		$content = $this->loadTemplate( 'display-rows' );

		$this->assertStringContainsString(
			'{{#show:{{FULLPAGENAME}}|?{{PAGENAME:@@prop@@}}}}',
			$content,
			'display-rows must use #show to retrieve property values for the current page'
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
			'render-reverse must check count before rendering'
		);
	}

	public function testRenderReverseFiltersPageTypeProperties(): void {
		$content = $this->loadTemplate( 'render-reverse' );

		$this->assertStringContainsString(
			'[[Has type::Page]]',
			$content,
			'render-reverse must filter to Page-type properties only'
		);
	}

	public function testRenderReverseDelegatesToReverseDiscover(): void {
		$content = $this->loadTemplate( 'render-reverse' );

		$this->assertStringContainsString(
			'template=Category/reverse-discover',
			$content,
			'render-reverse must delegate to reverse-discover for per-property rendering'
		);
	}

	public function testReverseDiscoverShowsInversePropertyLabel(): void {
		$content = $this->loadTemplate( 'reverse-discover' );

		$this->assertStringContainsString(
			'Inverse property label',
			$content,
			'reverse-discover must use the Inverse property label for relationship names'
		);
	}

	public function testReverseDiscoverFallsBackToDisplayLabel(): void {
		$content = $this->loadTemplate( 'reverse-discover' );

		$this->assertStringContainsString(
			'?Display label|default=',
			$content,
			'reverse-discover must fall back to Display label when no inverse label set'
		);
	}

	/* =========================================================================
	 * COLLECT-ANCESTORS
	 * ========================================================================= */

	public function testCollectAncestorsWalksParentChain(): void {
		$content = $this->loadTemplate( 'collect-ancestors' );

		$this->assertStringContainsString(
			'?Has parent category',
			$content,
			'collect-ancestors must query Has parent category to walk the chain'
		);
		$this->assertStringContainsString(
			'{{Category/collect-ancestors-L1',
			$content,
			'collect-ancestors must delegate to L1 for deeper levels'
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
