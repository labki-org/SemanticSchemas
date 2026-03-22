<?php

namespace MediaWiki\Extension\SemanticSchemas\Hooks;

use MediaWiki\Extension\SemanticSchemas\SemanticSchemasServices;
use MediaWiki\Page\Article;
use MediaWiki\SpecialPage\SpecialPage;
use Skin;

/**
 * CategoryPageHooks
 *
 * Hook handler for adding "Generate Form" action to Category pages,
 * per-category "Edit fields" actions on content pages, and
 * rendering hierarchy footers.
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

		if ( !$title || !$title->exists() ) {
			return;
		}

		// Category pages: add "Generate Form" action
		if ( $title->inNamespace( NS_CATEGORY ) ) {
			if ( $user->isAllowed( 'editinterface' ) ) {
				$categoryName = $title->getText();
				$links['actions']['ss-generate-form'] = [
					'text' => wfMessage( 'semanticschemas-action-generate-form' )->text(),
					'href' => SpecialPage::getTitleFor( 'SemanticSchemas' )->getLocalURL( [
						'action' => 'generate-form',
						'category' => $categoryName,
					] ),
				];
			}
			return;
		}

		// Content pages: add per-category "Edit fields" actions
		if ( !$title->isContentPage() ) {
			return;
		}

		$this->addCategoryEditActions( $title, $links );
	}

	/**
	 * Add "Edit [Category] fields" action links for each SemanticSchemas-managed
	 * category the page belongs to, plus an "Add category" link.
	 */
	private function addCategoryEditActions( \MediaWiki\Title\Title $title, array &$links ): void {
		$services = \MediaWiki\MediaWikiServices::getInstance();
		$categoryStore = SemanticSchemasServices::getWikiCategoryStore( $services );

		// Get all managed category names
		$allCategories = $categoryStore->getAllCategories();
		if ( empty( $allCategories ) ) {
			return;
		}

		$managedNames = [];
		foreach ( $allCategories as $cat ) {
			$managedNames[$cat->getName()] = true;
		}

		// Get this page's categories
		$pageCategories = $title->getParentCategories();
		$matchedCategories = [];
		foreach ( $pageCategories as $catTitle => $pageName ) {
			// $catTitle is like "Category:Person"
			$catName = preg_replace( '/^[^:]+:/', '', $catTitle );
			if ( isset( $managedNames[$catName] ) ) {
				$matchedCategories[] = $catName;
			}
		}

		if ( empty( $matchedCategories ) ) {
			return;
		}

		// Add "Edit [Category] fields" for each managed category
		$pageName = $title->getPrefixedText();
		foreach ( $matchedCategories as $catName ) {
			$formEditTitle = \MediaWiki\Title\Title::makeTitleSafe(
				NS_SPECIAL,
				'FormEdit/' . $catName . '/' . $pageName
			);
			if ( $formEditTitle ) {
				$links['actions']['ss-edit-' . strtolower( str_replace( ' ', '-', $catName ) )] = [
					'text' => wfMessage( 'semanticschemas-action-edit-fields' )
						->params( $catName )->text(),
					'href' => $formEditTitle->getLocalURL(),
				];
			}
		}

		// Remove PageForms' generic "Edit with form" — our per-category links replace it
		unset( $links['views']['formedit'] );

		// Add "Add category" action — pass existing categories so the form can pre-check them
		$links['actions']['ss-add-category'] = [
			'text' => wfMessage( 'semanticschemas-action-add-category' )->text(),
			'href' => SpecialPage::getTitleFor( 'SemanticSchemas', 'create' )->getLocalURL( [
				'ss-page-name' => $title->getText(),
				'ss-existing' => implode( '|', $matchedCategories ),
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
