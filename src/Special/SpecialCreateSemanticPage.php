<?php

namespace MediaWiki\Extension\SemanticSchemas\Special;

use MediaWiki\Extension\SemanticSchemas\Generator\CompositeFormGenerator;
use MediaWiki\Extension\SemanticSchemas\Generator\FormGenerator;
use MediaWiki\Extension\SemanticSchemas\Schema\InheritanceResolver;
use MediaWiki\Extension\SemanticSchemas\Schema\MultiCategoryResolver;
use MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;

/**
 * SpecialCreateSemanticPage
 * -------------------------
 * Special page for creating new semantic pages with single or multiple categories.
 *
 * Provides:
 * - Interactive category selection tree
 * - Property preview for selected categories
 * - Namespace conflict resolution
 * - Composite form generation for multiple categories
 *
 * Security:
 * - Requires 'edit' permission
 * - CSRF token validation on form submission
 */
class SpecialCreateSemanticPage extends SpecialPage {

	public function __construct() {
		parent::__construct( 'CreateSemanticPage', 'edit' );
	}

	/**
	 * @param string|null $subPage
	 */
	public function execute( $subPage ) {
		$this->setHeaders();
		$this->checkPermissions();

		$request = $this->getRequest();
		$output = $this->getOutput();

		// Dependency checks
		if ( !defined( 'SMW_VERSION' ) ) {
			$output->addHTML( Html::errorBox(
				$this->msg( 'semanticschemas-error-no-smw' )->parse()
			) );
			return;
		}

		if ( !defined( 'PF_VERSION' ) ) {
			$output->addHTML( Html::errorBox(
				$this->msg( 'semanticschemas-error-no-pageforms' )->parse()
			) );
			return;
		}

		// Check for POST action
		if ( $request->wasPosted() && $request->getVal( 'action' ) === 'createpage' ) {
			$this->handleCreatePageAction();
			return;
		}

		// Load ResourceLoader modules
		$output->addModules( 'ext.semanticschemas.createpage' );
		$output->addModuleStyles( 'ext.semanticschemas.styles' );

		// Render the HTML layout
		$output->addHTML( $this->renderLayout() );
	}

	/**
	 * Render the HTML skeleton for the Create Page UI.
	 *
	 * The JavaScript module will populate the category tree and property preview.
	 * The root category is embedded as a data attribute for the JS to use.
	 *
	 * @return string HTML
	 */
	private function renderLayout(): string {
		$categoryStore = new WikiCategoryStore();
		$allCategories = $categoryStore->getAllCategories();

		// Build tree nodes for JS (all categories with their parents)
		$treeNodes = [];
		foreach ( $allCategories as $category ) {
			$fullName = 'Category:' . $category->getName();
			$parents = array_map(
				static fn ( $p ) => 'Category:' . $p,
				$category->getParents()
			);
			$treeNodes[$fullName] = [
				'title' => $fullName,
				'parents' => $parents,
			];
		}

		// Build HTML structure
		$html = Html::openElement( 'div', [ 'class' => 'semanticschemas-shell ss-createpage-shell' ] );
		$html .= Html::openElement( 'div', [ 'class' => 'ss-createpage-layout' ] );

		// Left panel: category tree
		$html .= Html::openElement( 'div', [ 'class' => 'ss-createpage-tree-panel' ] );
		$html .= Html::element( 'h3', [], $this->msg( 'semanticschemas-createpage-tree-title' )->text() );
		$html .= Html::element( 'div', [
			'id' => 'ss-createpage-tree',
			'data-tree-nodes' => json_encode( $treeNodes, JSON_UNESCAPED_UNICODE )
		] );
		$html .= Html::closeElement( 'div' );

		// Right panel: preview + form
		$html .= Html::openElement( 'div', [ 'class' => 'ss-createpage-preview-panel' ] );
		$html .= Html::element( 'h3', [], $this->msg( 'semanticschemas-createpage-preview-title' )->text() );

		// Chip list for selected categories
		$html .= Html::element( 'div', [ 'id' => 'ss-createpage-chips' ] );

		// Property preview area
		$html .= Html::openElement( 'div', [ 'id' => 'ss-createpage-preview' ] );
		$html .= Html::element( 'p', [
			'class' => 'ss-hierarchy-empty'
		], $this->msg( 'semanticschemas-createpage-empty-state' )->text() );
		$html .= Html::closeElement( 'div' );

		// Namespace picker (hidden by default, shown on conflict)
		$html .= Html::element( 'div', [
			'id' => 'ss-createpage-namespace',
			'style' => 'display:none;'
		] );

		// Page name + submit
		$html .= Html::openElement( 'div', [ 'class' => 'ss-createpage-submit-area' ] );
		$html .= Html::element( 'label', [
			'for' => 'ss-createpage-pagename'
		], $this->msg( 'semanticschemas-createpage-pagename-label' )->text() );
		$html .= Html::element( 'input', [
			'type' => 'text',
			'id' => 'ss-createpage-pagename',
			'placeholder' => $this->msg( 'semanticschemas-createpage-pagename-placeholder' )->text()
		] );
		$html .= Html::element( 'div', [
			'id' => 'ss-createpage-page-warning',
			'style' => 'display:none;'
		] );
		$html .= Html::element( 'button', [
			'id' => 'ss-createpage-submit',
			'class' => 'ss-btn ss-btn-primary',
			'disabled' => true
		], $this->msg( 'semanticschemas-createpage-submit' )->text() );
		$html .= Html::closeElement( 'div' );

		$html .= Html::closeElement( 'div' );
		$html .= Html::closeElement( 'div' );
		$html .= Html::closeElement( 'div' );

		return $html;
	}

	/**
	 * Handle the createpage POST action.
	 *
	 * Validates input, generates the appropriate form (single or composite),
	 * and returns JSON with the FormEdit URL.
	 */
	private function handleCreatePageAction(): void {
		$request = $this->getRequest();
		$output = $this->getOutput();

		// Disable default output
		$output->disable();

		// Set JSON response header
		$request->response()->header( 'Content-Type: application/json' );

		// CSRF token validation
		if ( !$this->getUser()->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
			echo json_encode( [
				'success' => false,
				'error' => $this->msg( 'semanticschemas-permission-denied' )->text()
			] );
			return;
		}

		// Get categories from POST
		$categoriesRaw = $request->getArray( 'categories', [] );
		if ( empty( $categoriesRaw ) ) {
			// Try pipe-separated string
			$categoriesStr = $request->getVal( 'categories', '' );
			$categoriesRaw = $categoriesStr ? explode( '|', $categoriesStr ) : [];
		}

		// Strip "Category:" prefix (case-insensitive)
		$categories = [];
		foreach ( $categoriesRaw as $cat ) {
			$cat = trim( $cat );
			if ( stripos( $cat, 'Category:' ) === 0 ) {
				$cat = substr( $cat, 9 );
			}
			if ( $cat !== '' ) {
				$categories[] = $cat;
			}
		}

		// Validate: at least 1 category
		if ( empty( $categories ) ) {
			echo json_encode( [
				'success' => false,
				'error' => $this->msg( 'semanticschemas-createpage-no-categories' )->text()
			] );
			return;
		}

		// Get page name
		$pageName = trim( $request->getVal( 'pagename', '' ) );
		if ( $pageName === '' ) {
			echo json_encode( [
				'success' => false,
				'error' => $this->msg( 'semanticschemas-createpage-no-pagename' )->text()
			] );
			return;
		}

		// Get namespace (optional)
		$namespace = $request->getVal( 'namespace', '' );

		// Load all categories and validate
		$categoryStore = new WikiCategoryStore();
		$allCategories = $categoryStore->getAllCategories();
		$categoryMap = [];
		foreach ( $allCategories as $cat ) {
			$categoryMap[$cat->getName()] = $cat;
		}

		// Validate categories exist
		foreach ( $categories as $catName ) {
			if ( !isset( $categoryMap[$catName] ) ) {
				echo json_encode( [
					'success' => false,
					'error' => $this->msg( 'semanticschemas-error-missing-category' )->params( $catName )->text()
				] );
				return;
			}
		}

		try {
			// Build resolver
			$resolver = new InheritanceResolver( $categoryMap );
			$formName = '';

			if ( count( $categories ) === 1 ) {
				// Single category: use FormGenerator
				$categoryName = $categories[0];
				$effective = $resolver->getEffectiveCategory( $categoryName );

				$formGenerator = new FormGenerator();
				$formGenerator->generateAndSaveForm( $effective );

				$formName = $categoryName;

			} else {
				// Multiple categories: use CompositeFormGenerator
				$multiResolver = new MultiCategoryResolver( $resolver );
				$resolved = $multiResolver->resolve( $categories );

				$compositeGenerator = new CompositeFormGenerator();
				$compositeGenerator->generateAndSaveCompositeForm( $resolved );

				$formName = $compositeGenerator->getCompositeFormName( $categories );
			}

			// Build target page
			$targetPage = $pageName;
			if ( $namespace !== '' ) {
				$targetPage = $namespace . ':' . $pageName;
			}

			// Build FormEdit URL
			$formEditUrl = 'Special:FormEdit/' . $formName . '/' . $targetPage;

			echo json_encode( [
				'success' => true,
				'formName' => $formName,
				'formEditUrl' => $formEditUrl
			] );

		} catch ( \Throwable $e ) {
			echo json_encode( [
				'success' => false,
				'error' => $e->getMessage()
			] );
		}
	}

	/**
	 * Special page group name.
	 *
	 * @return string
	 */
	protected function getGroupName() {
		return 'wiki';
	}

	/**
	 * Mark this page as a write operation.
	 *
	 * @return bool
	 */
	public function doesWrites() {
		return true;
	}
}
