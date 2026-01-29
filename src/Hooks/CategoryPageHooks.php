<?php

namespace MediaWiki\Extension\SemanticSchemas\Hooks;

use MediaWiki\SpecialPage\SpecialPage;
use Skin;

/**
 * CategoryPageHooks
 *
 * Hook handler for adding "Generate Form" action to Category pages.
 * This allows users to quickly generate a PageForms form for any category
 * without navigating to the Special:SemanticSchemas page.
 */
class CategoryPageHooks {

	/**
	 * Hook: SkinTemplateNavigation::Universal
	 *
	 * Adds a "Generate form" action link to the dropdown menu on Category pages.
	 * The link only appears for:
	 * - Existing Category pages
	 * - Users with 'editinterface' permission (same as Special:SemanticSchemas)
	 * - Categories that have schema data
	 *
	 * @param Skin $skin
	 * @param array &$links Navigation links array
	 * @return void
	 */
	public static function onSkinTemplateNavigation( Skin $skin, array &$links ): void {
		$title = $skin->getTitle();
		$user = $skin->getUser();

		// Only on Category pages
		if ( !$title || !$title->inNamespace( NS_CATEGORY ) ) {
			return;
		}

		// Only on existing pages
		if ( !$title->exists() ) {
			return;
		}

		// Check permission (same as Special:SemanticSchemas)
		if ( !$user->isAllowed( 'editinterface' ) ) {
			return;
		}

		$categoryName = $title->getText();

		// Add "Generate Form" action to the dropdown menu
		$links['actions']['ss-generate-form'] = [
			'text' => wfMessage( 'semanticschemas-action-generate-form' )->text(),
			'href' => SpecialPage::getTitleFor( 'SemanticSchemas' )->getLocalURL( [
				'action' => 'generate-form',
				'category' => $categoryName,
			] ),
		];
	}
}
