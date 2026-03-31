<?php

namespace MediaWiki\Extension\SemanticSchemas\Hooks;

use MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore;
use MediaWiki\Page\Article;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use Skin;

/**
 * CategoryPageHooks
 *
 * Hook handler for adding "New page" and "Generate Form" actions to Category pages,
 * composite form editing on multi-category content pages, and
 * rendering hierarchy footers.
 */
class CategoryPageHooks {

	private WikiCategoryStore $categoryStore;

	public function __construct( WikiCategoryStore $categoryStore ) {
		$this->categoryStore = $categoryStore;
	}

	/**
	 * Hook: SkinTemplateNavigation::Universal
	 *
	 * Adds "New page" and "Generate form" action links to the dropdown menu on Category pages.
	 * For content pages with managed categories, rewrites "Edit with form"
	 * to use CompositeForm for multi-category pages.
	 */
	// phpcs:ignore MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
	public function onSkinTemplateNavigation__Universal( Skin $skin, array &$links ): void {
		$title = $skin->getTitle();
		$user = $skin->getUser();

		if ( !$title || !$title->exists() ) {
			return;
		}

		// Category pages: add "New page" and "Generate Form" actions
		if ( $title->inNamespace( NS_CATEGORY ) ) {
			$categoryName = $title->getText();

			// "New page" — available to anyone who can create pages, but only
			// if the Form: page exists. If it doesn't and the user is an admin,
			// generate it first then redirect to FormEdit.
			if ( $user->isAllowed( 'createpage' ) ) {
				$formNs = defined( 'PF_NS_FORM' ) ? constant( 'PF_NS_FORM' ) : NS_MAIN;
				$formPage = Title::makeTitleSafe( $formNs, $categoryName );
				$formExists = $formPage && $formPage->exists();

				if ( $formExists ) {
					$links['actions']['ss-new-page'] = [
						'text' => wfMessage( 'semanticschemas-action-new-page' )->text(),
						'href' => $formPage->getLocalURL(),
					];
				} elseif ( $user->isAllowed( 'editinterface' ) ) {
					// Form missing — admin can generate it first, then redirect
					$links['actions']['ss-new-page'] = [
						'text' => wfMessage( 'semanticschemas-action-new-page' )->text(),
						'href' => SpecialPage::getTitleFor( 'SemanticSchemas' )->getLocalURL( [
							'action' => 'generate-form',
							'category' => $categoryName,
							'then' => 'new-page',
						] ),
					];
				}
			}

			// "Generate artifacts" — admin only
			if ( $user->isAllowed( 'editinterface' ) ) {
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

		// Content pages: rewrite formedit for multi-category, add "Add category"
		if ( !$title->isContentPage() ) {
			return;
		}

		$this->addCategoryEditActions( $title, $user, $links );
	}

	/**
	 * For content pages with managed categories:
	 * - 1 category: leave PF's formedit link alone
	 * - 2+ categories: rewrite formedit href to CompositeForm
	 * - Always: add "Add category" to views section
	 */
	private function addCategoryEditActions( Title $title, User $user, array &$links ): void {
		// Check page categories first (cheap) before loading all managed categories
		$pageCategories = $title->getParentCategories();
		if ( empty( $pageCategories ) ) {
			return;
		}

		$allCategories = $this->categoryStore->getAllCategories();
		if ( empty( $allCategories ) ) {
			return;
		}

		$managedNames = [];
		foreach ( $allCategories as $cat ) {
			$managedNames[$cat->getName()] = true;
		}

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

		// Multi-category: rewrite formedit to use CompositeForm
		if ( count( $matchedCategories ) > 1 && isset( $links['views']['formedit'] ) ) {
			$compositeTitle = Title::makeTitleSafe(
				NS_SPECIAL,
				'FormEdit/CompositeForm/' . $title->getPrefixedText()
			);
			if ( $compositeTitle ) {
				$links['views']['formedit']['href'] = $compositeTitle->getLocalURL();
			}
		}

		if ( $user->isAllowed( 'createpage' ) ) {
			$links['actions']['ss-add-category'] = [
				'text' => wfMessage( 'semanticschemas-action-add-category' )->text(),
				'href' => SpecialPage::getTitleFor( 'CreateSemanticPage' )->getLocalURL( [
					'ss-page-name' => $title->getText(),
					'ss-existing' => implode( '|', $matchedCategories ),
				] ),
			];
		}
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
