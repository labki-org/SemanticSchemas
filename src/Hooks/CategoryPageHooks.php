<?php

namespace MediaWiki\Extension\SemanticSchemas\Hooks;

use MediaWiki\Page\Article;
use MediaWiki\SpecialPage\SpecialPage;
use Skin;

/**
 * CategoryPageHooks
 *
 * Hook handler for adding "Generate Form" action to Category pages
 * and rendering hierarchy footers.
 */
class CategoryPageHooks {

	/**
	 * Hook: SkinTemplateNavigation::Universal
	 *
	 * Adds a "Generate form" action link to the dropdown menu on Category pages.
	 */
	// phpcs:ignore MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
	public function onSkinTemplateNavigation__Universal( Skin $skin, array &$links ): void {
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

	/**
	 * Displays the inheritance hierarchy in the footer of category pages
	 */
	public function onArticleViewFooter( Article $article, bool $patrolFooterShown ): bool {
		$title = $article->getTitle();
		if ( !$title || !$title->inNamespace( NS_CATEGORY ) ) {
			return true;
		}
		$output = $article->getContext()->getOutput();
		$output->addWikiTextAsContent( '{{#semanticschemas_hierarchy:' . $title->getText() . '}}' );
		return true;
	}
}
