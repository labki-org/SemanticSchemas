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
