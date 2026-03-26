<?php

namespace MediaWiki\Extension\SemanticSchemas\Special;

use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\InheritanceResolver;
use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\Title\Title;

/**
 * Special:CreateSemanticPage
 *
 * Lets any user with page-creation rights create a new page and assign it
 * to one or more SemanticSchemas-managed categories. Single-category
 * selection redirects to FormEdit; multi-category creates a composed page
 * with dispatcher template calls.
 *
 * This is intentionally a separate special page from Special:SemanticSchemas
 * (which requires editinterface) so that regular editors can create pages.
 */
class SpecialCreateSemanticPage extends SpecialPage {

	private WikiCategoryStore $categoryStore;
	private PageCreator $pageCreator;
	private NamespaceInfo $namespaceInfo;

	public function __construct(
		WikiCategoryStore $categoryStore,
		PageCreator $pageCreator,
		NamespaceInfo $namespaceInfo
	) {
		parent::__construct( 'CreateSemanticPage', 'createpage' );
		$this->categoryStore = $categoryStore;
		$this->pageCreator = $pageCreator;
		$this->namespaceInfo = $namespaceInfo;
	}

	public function execute( $subPage ) {
		$this->checkPermissions();
		$output = $this->getOutput();
		$request = $this->getRequest();

		$output->setPageTitle( $this->msg( 'semanticschemas-create-title' )->text() );
		$output->addModuleStyles( 'ext.semanticschemas.styles' );

		// Handle POST
		if ( $request->wasPosted() && $request->getVal( 'ss-action' ) === 'create-page' ) {
			if ( !$this->getUser()->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
				$output->addHTML( Html::errorBox( 'Invalid session token. Please try again.' ) );
			} else {
				$this->processCreatePage();
				return;
			}
		}

		$this->showForm();
	}

	private function showForm(): void {
		$output = $this->getOutput();
		$request = $this->getRequest();

		$categories = $this->categoryStore->getAllCategories();
		if ( empty( $categories ) ) {
			$output->addHTML( Html::rawElement( 'div', [ 'class' => 'semanticschemas-shell' ],
				Html::element( 'p', [],
					$this->msg( 'semanticschemas-create-no-categories' )->text()
				)
			) );
			return;
		}

		// Pre-populate from query params (e.g. when arriving via "Add category" action)
		$prefilledPageName = $request->getText( 'ss-page-name', '' );
		$existingRaw = $request->getText( 'ss-existing', '' );
		$existingCategories = $existingRaw !== ''
			? array_flip( explode( '|', $existingRaw ) )
			: [];

		$isAddMode = $prefilledPageName !== '';

		// Description
		$descMsg = $isAddMode
			? $this->msg( 'semanticschemas-create-add-description' )->params( $prefilledPageName )->text()
			: $this->msg( 'semanticschemas-create-description' )->text();
		$output->addHTML( Html::rawElement( 'p', [ 'class' => 'mw-body-content' ], $descMsg ) );

		$formHtml = '';

		// Page name input
		$pageNameAttrs = [
			'id' => 'ss-page-name',
			'required' => true,
			'placeholder' => $this->msg( 'semanticschemas-create-page-name-placeholder' )->text(),
		];
		if ( $isAddMode ) {
			$pageNameAttrs['readonly'] = true;
		}
		$formHtml .= Html::rawElement( 'div', [ 'class' => 'semanticschemas-form-group ss-create-page-name' ],
			Html::element( 'label', [ 'for' => 'ss-page-name' ],
				$this->msg( 'semanticschemas-create-page-name' )->text()
			) .
			Html::input( 'ss-page-name', $prefilledPageName, 'text', $pageNameAttrs )
		);

		// Category tree
		[ $roots, $childrenOf, $categoryMap, $resolver ] = $this->buildCategoryHierarchy( $categories );

		$checkboxes = $this->renderCategoryTree(
			$roots, $childrenOf, $categoryMap, $resolver, $existingCategories, 0
		);

		$output->addModules( [ 'ext.semanticschemas.createpage' ] );

		$formHtml .= Html::rawElement( 'div', [ 'class' => 'semanticschemas-form-group' ],
			Html::element( 'label', [],
				$this->msg( 'semanticschemas-create-select-categories' )->text()
			) .
			Html::rawElement( 'div', [ 'class' => 'ss-create-cat-grid' ],
				$checkboxes
			)
		);

		// Submit
		$formHtml .= Html::hidden( 'ss-action', 'create-page' );
		$formHtml .= Html::hidden( 'wpEditToken', $this->getUser()->getEditToken() );
		$formHtml .= Html::rawElement( 'div', [ 'class' => 'ss-create-actions' ],
			Html::submitButton(
				$isAddMode
					? $this->msg( 'semanticschemas-create-add-submit' )->text()
					: $this->msg( 'semanticschemas-create-submit' )->text(),
				[ 'class' => 'mw-ui-button mw-ui-progressive' ]
			)
		);

		$form = Html::rawElement( 'form', [
			'method' => 'post',
			'action' => $this->getPageTitle()->getLocalURL(),
			'class' => 'semanticschemas-create-form',
		], $formHtml );

		$output->addHTML(
			Html::rawElement( 'div', [ 'class' => 'semanticschemas-shell' ], $form )
		);
	}

	private function processCreatePage(): void {
		$output = $this->getOutput();
		$request = $this->getRequest();

		$pageName = trim( $request->getText( 'ss-page-name' ) );
		$selectedCategories = array_values( array_unique(
			$request->getArray( 'ss-categories', [] )
		) );

		if ( $pageName === '' ) {
			$output->addHTML( Html::errorBox(
				$this->msg( 'semanticschemas-create-no-page-name' )->text()
			) );
			return;
		}

		if ( empty( $selectedCategories ) ) {
			$output->addHTML( Html::errorBox(
				$this->msg( 'semanticschemas-create-no-selection' )->text()
			) );
			return;
		}

		// Single category: redirect directly to FormEdit
		if ( count( $selectedCategories ) === 1 ) {
			$catName = $selectedCategories[0];
			$formEditTitle = Title::makeTitleSafe( NS_SPECIAL, 'FormEdit/' . $catName . '/' . $pageName );
			if ( $formEditTitle ) {
				$output->redirect( $formEditTitle->getFullURL() );
				return;
			}
		}

		// Multiple categories: create page with dispatcher template calls
		$pageContent = '';
		foreach ( $selectedCategories as $catName ) {
			$pageContent .= '{{' . $catName . "\n}}\n\n";
		}
		$pageContent = rtrim( $pageContent );

		// Determine namespace from first category
		$firstCat = $this->categoryStore->readCategory( $selectedCategories[0] );
		$ns = NS_MAIN;
		if ( $firstCat && $firstCat->getTargetNamespace() !== null ) {
			$nsIndex = $this->namespaceInfo
				->getCanonicalIndex( strtolower( $firstCat->getTargetNamespace() ) );
			if ( $nsIndex !== null ) {
				$ns = $nsIndex;
			}
		}

		$pageTitle = Title::makeTitleSafe( $ns, $pageName );
		if ( !$pageTitle ) {
			$output->addHTML( Html::errorBox(
				$this->msg( 'semanticschemas-create-invalid-title' )->text()
			) );
			return;
		}

		$success = $this->pageCreator->createOrUpdatePage(
			$pageTitle,
			$pageContent,
			'SemanticSchemas: Created multi-category page'
		);

		if ( !$success ) {
			$output->addHTML( Html::errorBox(
				$this->msg( 'semanticschemas-create-failed' )->params( $pageName )->text()
			) );
			return;
		}

		// Redirect to the page itself — CompositeForm needs SMW to index
		// the categories first (via the job queue), so redirect to the page
		// view where the user can click "Edit with form" when ready.
		$output->redirect( $pageTitle->getFullURL() );
	}

	/**
	 * Build the parent→children map and identify root categories for tree rendering.
	 *
	 * @param CategoryModel[] $categories
	 * @return array [ string[] $roots, array $childrenOf, array $categoryMap, InheritanceResolver $resolver ]
	 */
	private function buildCategoryHierarchy( array $categories ): array {
		$categoryMap = [];
		foreach ( $categories as $cat ) {
			$categoryMap[$cat->getName()] = $cat;
		}
		$resolver = new InheritanceResolver( $categoryMap );

		$childrenOf = [];
		$roots = [];
		foreach ( $categories as $cat ) {
			$parents = $cat->getParents();
			$managedParents = array_filter( $parents, static fn ( $p ) => isset( $categoryMap[$p] ) );
			if ( empty( $managedParents ) ) {
				$roots[] = $cat->getName();
			}
			foreach ( $managedParents as $parent ) {
				$childrenOf[$parent][] = $cat->getName();
			}
		}

		return [ $roots, $childrenOf, $categoryMap, $resolver ];
	}

	private function renderCategoryTree(
		array $names,
		array $childrenOf,
		array $categoryMap,
		InheritanceResolver $resolver,
		array $existingCategories,
		int $depth
	): string {
		$html = '';
		foreach ( $names as $name ) {
			if ( !isset( $categoryMap[$name] ) ) {
				continue;
			}
			$hasChildren = !empty( $childrenOf[$name] );
			$itemHtml = $this->renderCategoryItem(
				$categoryMap[$name], $resolver, $existingCategories, $depth, $hasChildren
			);

			$html .= $itemHtml;

			if ( $hasChildren ) {
				$childrenHtml = $this->renderCategoryTree(
					$childrenOf[$name], $childrenOf, $categoryMap,
					$resolver, $existingCategories, $depth + 1
				);
				$html .= Html::rawElement( 'div',
					[ 'class' => 'ss-create-cat-children' ],
					$childrenHtml
				);
			}
		}
		return $html;
	}

	private function renderCategoryItem(
		CategoryModel $cat,
		InheritanceResolver $resolver,
		array $existingCategories,
		int $depth,
		bool $hasChildren = false
	): string {
		$catName = $cat->getName();
		$catLabel = $cat->getLabel();
		$catDesc = $cat->getDescription();
		$isExisting = isset( $existingCategories[$catName] );

		// Ancestors for JS (excluding self)
		$ancestors = $resolver->getAncestors( $catName );
		array_shift( $ancestors );
		$ancestorStr = implode( '|', $ancestors );

		// Unique ID per tree instance (category may appear multiple times)
		static $instanceCounter = 0;
		$instanceId = 'ss-cat-' . $instanceCounter++;

		$attrs = [
			'id' => $instanceId,
			'value' => $catName,
			'data-category' => $catName,
			'data-ancestors' => $ancestorStr,
		];
		if ( $isExisting ) {
			$attrs['disabled'] = true;
		}

		$labelHtml = Html::element( 'strong', [], $catLabel );
		if ( $isExisting ) {
			$labelHtml .= Html::rawElement( 'span',
				[ 'class' => 'semanticschemas-badge is-ok ss-create-cat-badge' ],
				$this->msg( 'semanticschemas-create-on-page' )->text()
			);
		}
		if ( $catDesc !== '' ) {
			$labelHtml .= Html::element( 'span',
				[ 'class' => 'ss-create-cat-desc' ],
				$catDesc
			);
		}

		$rowClass = 'ss-create-cat-item';
		if ( $isExisting ) {
			$rowClass .= ' is-existing';
		}
		if ( $hasChildren ) {
			$rowClass .= ' has-children';
		}

		$toggleHtml = $hasChildren
			? Html::rawElement( 'span', [ 'class' => 'ss-create-cat-toggle' ], '▸' )
			: Html::rawElement( 'span', [ 'class' => 'ss-create-cat-toggle-spacer' ] );

		return Html::rawElement( 'div', [
			'class' => $rowClass,
			'style' => $depth > 0 ? '--depth: ' . $depth : '',
		],
			$toggleHtml .
			Html::rawElement( 'label', [ 'for' => $instanceId, 'class' => 'ss-create-cat-label-wrap' ],
				Html::check( 'ss-categories[]', $isExisting, $attrs ) .
				( $isExisting ? Html::hidden( 'ss-categories[]', $catName ) : '' ) .
				Html::rawElement( 'span', [ 'class' => 'ss-create-cat-label' ], $labelHtml )
			)
		);
	}

	protected function getGroupName() {
		return 'labki';
	}
}
