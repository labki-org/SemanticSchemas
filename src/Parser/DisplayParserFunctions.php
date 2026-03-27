<?php

namespace MediaWiki\Extension\SemanticSchemas\Parser;

use MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore;
use MediaWiki\Html\Html;
use MediaWiki\Page\WikiPageFactory;
use Parser;
use PPFrame;

/**
 * DisplayParserFunctions (Redesigned 2025)
 * -----------------------------------------
 * Registers SemanticSchemas's parser functions:
 *
 *   {{#semanticschemas_hierarchy:}}
 *   {{#semanticschemas_load_form_preview:}}
 *   {{#semanticschemas_categories:}}
 *
 * The old rendering parser functions have been removed since
 * display templates are now static wikitext that directly calls
 * property templates.
 *
 * Responsibilities:
 *   - Inject hierarchy widget
 *   - Load form preview modules
 *   - Resolve page categories from wikitext
 *   - Provide clean HTML-safe outputs
 */
class DisplayParserFunctions {

	private WikiCategoryStore $categoryStore;
	private WikiPageFactory $wikiPageFactory;

	public function __construct(
		WikiCategoryStore $categoryStore,
		WikiPageFactory $wikiPageFactory
	) {
		$this->categoryStore = $categoryStore;
		$this->wikiPageFactory = $wikiPageFactory;
	}

	/* =====================================================================
	 * REGISTRATION
	 * ===================================================================== */

	public function onParserFirstCallInit( Parser $parser ): void {
		// Category hierarchy UI
		$parser->setFunctionHook(
			'semanticschemas_hierarchy',
			[ $this, 'renderHierarchy' ],
			SFH_OBJECT_ARGS
		);

		// Form preview JS loader
		$parser->setFunctionHook(
			'semanticschemas_load_form_preview',
			[ $this, 'loadFormPreview' ],
			SFH_OBJECT_ARGS
		);

		// Resolve categories from page wikitext (no SMW indexing required)
		$parser->setFunctionHook(
			'semanticschemas_categories',
			[ $this, 'renderCategories' ],
			SFH_OBJECT_ARGS
		);
	}

	/* =====================================================================
	 * HELPER UTILITIES
	 * ===================================================================== */

	private function htmlReturn( string $html ): array {
		return [
			$html,
			'noparse' => true,
			'isHTML' => true
		];
	}

	/* =====================================================================
	 * CATEGORY HIERARCHY UI
	 * ===================================================================== */

	public function renderHierarchy( Parser $parser, PPFrame $frame, array $args ) {
		// Argument 0: Optional Category Name (e.g. "Person")
		// If provided, we force display for that category regardless of the current page.
		$category = isset( $args[0] ) ? trim( $frame->expand( $args[0] ) ) : null;

		if ( !$category ) {
			// Fallback: Infer from current page title if in Category namespace
			$title = $parser->getTitle();
			if ( !$title || $title->getNamespace() !== NS_CATEGORY ) {
				return '';
			}
			$category = $title->getText();
		}

		$output = $parser->getOutput();
		$output->addModules( [ 'ext.semanticschemas.hierarchy' ] );

		return $this->htmlReturn( Html::rawElement(
			'div',
			[
				'id' => 'ss-category-hierarchy-' . md5( $category ),
				'class' => 'ss-hierarchy-block mw-collapsible',
				'data-category' => $category
			],
			Html::element( 'p', [], wfMessage( 'semanticschemas-hierarchy-loading' )->text() )
		) );
	}

	/* =====================================================================
	 * PAGE CATEGORIES (from wikitext, no SMW indexing needed)
	 * ===================================================================== */

	/**
	 * {{#semanticschemas_categories:PageName}}
	 *
	 * Returns a comma-separated list of SemanticSchemas-managed categories
	 * found as template calls in the page's wikitext. Works immediately
	 * after page creation without waiting for SMW to index.
	 */
	public function renderCategories( Parser $parser, PPFrame $frame, array $args ): array {
		$pageName = isset( $args[0] ) ? trim( $frame->expand( $args[0] ) ) : '';
		if ( $pageName === '' ) {
			return [ '' ];
		}

		$title = \MediaWiki\Title\Title::newFromText( $pageName );
		if ( !$title || !$title->exists() ) {
			return [ '' ];
		}

		$wikiPage = $this->wikiPageFactory->newFromTitle( $title );
		$content = $wikiPage->getContent();
		if ( !$content ) {
			return [ '' ];
		}
		$wikitext = $content->serialize();

		// Build set of managed category names
		$managedNames = [];
		foreach ( $this->categoryStore->getAllCategories() as $cat ) {
			$managedNames[$cat->getName()] = true;
		}

		// Find template calls matching managed categories: {{CategoryName\n...}}
		preg_match_all( '/\{\{\s*([^\n|{}]+)/', $wikitext, $matches );
		$found = [];
		foreach ( $matches[1] as $templateName ) {
			$name = trim( $templateName );
			if ( isset( $managedNames[$name] ) && !in_array( $name, $found ) ) {
				$found[] = $name;
			}
		}

		return [ implode( ',', $found ) ];
	}

	/* =====================================================================
	 * FORM PREVIEW MODULE
	 * ===================================================================== */

	public function loadFormPreview( Parser $parser, PPFrame $frame, array $args ): array {
		$output = $parser->getOutput();
		$output->addModules( [ 'ext.semanticschemas.hierarchy.formpreview' ] );

		// Ensure loading order in <head>
		$output->addHeadItem(
			'semanticschemas-formpreview-loader',
			Html::inlineScript( 'mw.loader.using("ext.semanticschemas.hierarchy.formpreview");' )
		);

		return [ '', 'noparse' => false, 'isHTML' => false ];
	}
}
