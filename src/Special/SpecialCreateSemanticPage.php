<?php

namespace MediaWiki\Extension\SemanticSchemas\Special;

use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\InheritanceResolver;
use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore;
use MediaWiki\Html\Html;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Parser\ParserOutputLinkTypes;
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

	// duration to delay before redirecting after creating page
	public const DELAY_SECONDS = 1;

	/**
	 * Meta-categories that create pages in their own namespaces and should
	 * not be mixed with regular page categories.
	 */
	private const META_CATEGORY_NAMES = [ 'Category', 'Property' ];

	private WikiCategoryStore $categoryStore;
	private PageCreator $pageCreator;
	private NamespaceInfo $namespaceInfo;
	private WikiPageFactory $wikiPageFactory;

	public function __construct(
		WikiCategoryStore $categoryStore,
		PageCreator $pageCreator,
		NamespaceInfo $namespaceInfo,
		WikiPageFactory $wikiPageFactory
	) {
		parent::__construct( 'CreateSemanticPage', 'createpage' );
		$this->categoryStore = $categoryStore;
		$this->pageCreator = $pageCreator;
		$this->namespaceInfo = $namespaceInfo;
		$this->wikiPageFactory = $wikiPageFactory;
	}

	public function doesWrites(): bool {
		return true;
	}

	public function execute( $subPage ) {
		$this->checkPermissions();
		$output = $this->getOutput();
		$request = $this->getRequest();

		$output->setPageTitle( $this->msg( 'semanticschemas-create-title' )->text() );
		$output->addModuleStyles( 'ext.semanticschemas.styles' );

		// Handle POST
		if ( $request->wasPosted() && $request->getVal( 's2-action' ) === 'create-page' ) {
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
		if ( !$categories ) {
			$output->addHTML( Html::rawElement( 'div', [ 'class' => 'semanticschemas-shell' ],
				Html::element( 'p', [],
					$this->msg( 'semanticschemas-create-no-categories' )->text()
				)
			) );
			return;
		}

		// Pre-populate from query params (e.g. when arriving via "Add category" action)
		$prefilledPageName = $request->getText( 's2-page-name', '' );
		$existingRaw = $request->getText( 's2-existing', '' );
		$existingCategories = $existingRaw !== ''
			? array_flip( explode( '|', $existingRaw ) )
			: [];

		$isAddMode = $prefilledPageName !== '';

		// Description
		$descMsg = $isAddMode
			? $this->msg( 'semanticschemas-create-add-description' )->params( $prefilledPageName )->text()
			: $this->msg( 'semanticschemas-create-description' )->text();
		$output->addHTML(
			Html::rawElement(
				'p',
				[ 'class' => 'mw-body-content' ],
				htmlspecialchars( $descMsg, ENT_QUOTES )
			)
		);

		$formHtml = '';

		// Page name input
		$pageNameAttrs = [
			'id' => 's2-page-name',
			'required' => true,
			'placeholder' => $this->msg( 'semanticschemas-create-page-name-placeholder' )->text(),
		];
		if ( $isAddMode ) {
			$pageNameAttrs['readonly'] = true;
		}
		$formHtml .= Html::rawElement( 'div', [ 'class' => 'semanticschemas-form-group s2-create-page-name' ],
			Html::element( 'label', [ 'for' => 's2-page-name' ],
				$this->msg( 'semanticschemas-create-page-name' )->text()
			) .
			Html::input( 's2-page-name', $prefilledPageName, 'text', $pageNameAttrs )
		);

		$metaCategories = [];
		$pageCategories = [];
		foreach ( $categories as $cat ) {
			if ( in_array( $cat->getName(), self::META_CATEGORY_NAMES, true ) ) {
				$metaCategories[] = $cat;
			} else {
				$pageCategories[] = $cat;
			}
		}

		// Category tree (regular page categories only)
		[ $roots, $childrenOf, $categoryMap, $resolver ] = $this->buildCategoryHierarchy( $pageCategories );

		$checkboxes = $this->renderCategoryTree(
			$roots, $childrenOf, $categoryMap, $resolver, $existingCategories, 0
		);

		$output->addModules( [ 'ext.semanticschemas.createpage' ] );

		$formHtml .= Html::openElement( 'div', [ 'class' => 'semanticschemas-form-group s2-create-categories' ] ) .
			Html::element( 'label', [],
				$this->msg( 'semanticschemas-create-select-categories' )->text()
			) .
			Html::element( 'input', [
				'type' => 'text',
				'id' => 's2-cat-search',
				'class' => 's2-cat-search',
				'placeholder' => $this->msg( 'semanticschemas-create-search-placeholder' )->text(),
				'autocomplete' => 'off',
			] ) .
			Html::openElement( 'div', [ 'class' => 's2-create-cat-grid' ] ) .
			$checkboxes .
			Html::closeElement( 'div' ) .
			Html::closeElement( 'div' );

		// Meta-category quick-create buttons
		if ( !$isAddMode && $metaCategories ) {
			$buttonHtml = '';
			foreach ( $metaCategories as $meta ) {
				$formTitle = Title::makeTitleSafe( PF_NS_FORM, $meta->getName() );
				$buttonHtml .= Html::element( 'a', [
					'class' => 'cdx-button',
					'href' => $formTitle->getLocalURL(),
				], $meta->getLabel() );
			}
			$formHtml .= Html::openElement(
				'div',
				[ 'class' => 'semanticschemas-form-group s2-create-meta-categories' ]
			) .
				Html::element( 'label', [],
					$this->msg( 'semanticschemas-create-meta-categories' )->text()
				) .
				Html::openElement( 'div', [ 'class' => 's2-create-meta-buttons' ] ) .
				$buttonHtml .
				Html::closeElement( 'div' ) .
				Html::closeElement( 'div' );
		}

		// Submit
		$formHtml .= Html::hidden( 's2-action', 'create-page' ) .
			Html::hidden( 'wpEditToken', $this->getUser()->getEditToken() ) .
			Html::openElement( 'div', [ 'class' => 's2-create-actions' ] ) .
			Html::submitButton(
				$isAddMode
					? $this->msg( 'semanticschemas-create-add-submit' )->text()
					: $this->msg( 'semanticschemas-create-submit' )->text(),
				[ 'class' => 'cdx-button cdx-button--action-progressive' ]
			) .
			Html::closeElement( 'div' );

		$form = Html::openElement( 'form', [
			'method' => 'post',
			'action' => $this->getPageTitle()->getLocalURL(),
			'class' => 'semanticschemas-create-form',
		] ) .
			$formHtml .
			Html::closeElement( 'form' );

		$container = Html::openElement( 'div', [ 'class' => 'semanticschemas-shell' ] ) .
			$form .
			Html::closeElement( 'div' );

		$output->addHTML( $container );
	}

	private function processCreatePage(): void {
		$output = $this->getOutput();
		$request = $this->getRequest();

		$pageName = trim( $request->getText( 's2-page-name' ) );
		$selectedCategories = array_values( array_unique(
			$request->getArray( 's2-categories', [] )
		) );

		if ( $pageName === '' ) {
			$output->addHTML( Html::errorBox(
				htmlspecialchars( $this->msg( 'semanticschemas-create-no-page-name' )->text(), ENT_QUOTES )
			) );
			return;
		}

		if ( !$selectedCategories ) {
			$output->addHTML( Html::errorBox(
				htmlspecialchars( $this->msg( 'semanticschemas-create-no-selection' )->text(), ENT_QUOTES )
			) );
			return;
		}

		// Single category: redirect directly to FormEdit
		if ( count( $selectedCategories ) === 1 ) {
			$catName = $selectedCategories[0];
			$targetPage = $pageName;
			$cat = $this->categoryStore->readCategory( $catName );
			$targetNamespace = $cat->getTargetNamespace();
			if ( $cat && $targetNamespace !== null ) {
				$nsIndex = $this->namespaceInfo->getCanonicalIndex( strtolower( $targetNamespace ) );
				if ( $nsIndex !== null ) {
					$targetPage = $targetNamespace . ':' . $pageName;
				}
			}
			$formEditTitle = Title::makeTitleSafe( NS_SPECIAL, 'FormEdit/' . $catName . '/' . $targetPage );
			if ( $formEditTitle ) {
				$output->redirect( $formEditTitle->getFullURL() );
				return;
			}
		}

		// Determine namespace: find the selected category with a target namespace (at most one)
		$ns = NS_MAIN;
		foreach ( $selectedCategories as $sc ) {
			$scModel = $this->categoryStore->readCategory( $sc );
			$targetNamespace = $scModel->getTargetNamespace();
			if ( $scModel && $targetNamespace !== null ) {
				$nsIndex = $this->namespaceInfo
					->getCanonicalIndex( strtolower( $targetNamespace ) );
				if ( $nsIndex !== null ) {
					$ns = $nsIndex;
				}
				break;
			}
		}

		$pageTitle = Title::makeTitleSafe( $ns, $pageName );
		if ( !$pageTitle ) {
			$output->addHTML( Html::errorBox(
				htmlspecialchars( $this->msg( 'semanticschemas-create-invalid-title' )->text(), ENT_QUOTES )
			) );
			return;
		}

		// Build inheritance resolver for parent detection
		$allCategories = $this->categoryStore->getAllCategories();
		$categoryMap = [];
		foreach ( $allCategories as $cat ) {
			$categoryMap[$cat->getName()] = $cat;
		}
		$resolver = new InheritanceResolver( $categoryMap );

		// Build page content: preserve existing content, replace parent→child
		// template calls where applicable, and append genuinely new ones.
		// Build set of templates already used on the page via parser output
		$existingTemplates = [];
		$existingContent = '';
		if ( $pageTitle->exists() ) {
			$wikiPage = $this->wikiPageFactory->newFromTitle( $pageTitle );
			$content = $wikiPage->getContent();
			if ( $content ) {
				$existingContent = $content->serialize();
			}
			$parserOutput = $wikiPage->getParserOutput();
			if ( $parserOutput ) {
				foreach ( $parserOutput->getLinkList( ParserOutputLinkTypes::TEMPLATE ) as $link ) {
					$existingTemplates[str_replace( '_', ' ', $link['link']->getText() )] = true;
				}
			}
		}

		$pageContent = $existingContent;
		$newCalls = '';
		$removedParents = [];
		foreach ( $selectedCategories as $catName ) {
			// Skip categories already on the page
			if ( isset( $existingTemplates[$catName] ) ) {
				continue;
			}

			// Skip categories that were removed as parents of a child category
			if ( isset( $removedParents[$catName] ) ) {
				continue;
			}

			// Replace parent template calls with this child. First parent found
			// becomes the child (preserving params); additional parents are removed
			// with their unique params merged into the child.
			$ancestors = $resolver->getAncestors( $catName );
			array_shift( $ancestors );
			$replaced = false;
			foreach ( $ancestors as $ancestor ) {
				if ( !preg_match( self::templateCallPattern( $ancestor ), $pageContent, $m ) ) {
					continue;
				}
				if ( !$replaced ) {
					$pageContent = preg_replace(
						self::templateCallPattern( $ancestor, true ),
						'$1' . $catName . '$2',
						$pageContent,
						1
					);
					$replaced = true;
				} else {
					$parentParams = $this->extractTemplateParams( $m[0] );
					$pageContent = preg_replace(
						self::templateCallPattern( $ancestor, false, true ),
						'',
						$pageContent,
						1
					);
					if ( $parentParams ) {
						$pageContent = $this->mergeParamsIntoTemplate(
							$pageContent, $catName, $parentParams
						);
					}
				}
				$removedParents[$ancestor] = true;
			}

			if ( !$replaced ) {
				$newCalls .= '{{' . $catName . "\n}}\n\n";
			}
		}
		$newCalls = rtrim( $newCalls );

		// Composite pages redirect to the composite form
		$compositeUrl = Title::makeTitleSafe(
				NS_SPECIAL,
				'FormEdit/CompositeForm/' . $pageTitle->getPrefixedText()
			)->getFullURL() . '?action=purge';

		if ( $newCalls === '' && $pageContent === $existingContent ) {
			// Nothing changed - directly redirect
			$output->redirect( $compositeUrl );
			return;
		}

		if ( $newCalls !== '' ) {
			$pageContent = $pageContent !== ''
				? rtrim( $pageContent ) . "\n\n" . $newCalls
				: $newCalls;
		}

		$editSummary = $existingContent !== ''
			? 'SemanticSchemas: Updated category templates'
			: 'SemanticSchemas: Created multi-category page';

		$success = $this->pageCreator->createOrUpdatePage(
			$pageTitle,
			$pageContent,
			$editSummary
		);

		if ( !$success ) {
			$output->addHTML( Html::errorBox(
				htmlspecialchars(
					$this->msg( 'semanticschemas-create-failed' )
						->params( $pageName )->text(),
					ENT_QUOTES
				)
			) );
			return;
		}

		$this->delayedRedirect( $compositeUrl, self::DELAY_SECONDS );
	}

	/**
	 * Show a notice telling the user they will be redirected,
	 * and then use HTTP Refresh headers to do the redirect after a delay.
	 * This triggers an extra page load which runs deferred jobs mw & smw need to display the composite form
	 *
	 * @param string $redirectUrl
	 * @param float $delaySeconds
	 * @return void
	 */
	private function delayedRedirect( string $redirectUrl, float $delaySeconds ): void {
		$res = $this->getRequest()->response();
		$res->header( "Refresh: $delaySeconds; url=$redirectUrl", );

		$modal = Html::openElement( 'div', [ 'class' => 'delayed-redirect-notice' ] );
		$modal .= Html::element( 'p', [],
			$this->msg( 'semanticschemas-create-redirect' )->params( $delaySeconds )->text()
		);
		$modal .= Html::element( 'a', [ 'href' => $redirectUrl ], $redirectUrl );
		$modal .= Html::element( 'p', [], $this->msg( 'semanticschemas-create-refresh' )->text() );
		$modal .= Html::closeElement( 'div' );

		$output = $this->getOutput();
		$output->addHTML( $modal );
	}

	/**
	 * Build a regex matching a dispatcher template call like {{Name\n...\n}}.
	 *
	 * @param string $name Template name to match
	 * @param bool $captureNameSeparately If true, captures name and body as $1/$2
	 *   for substitution; otherwise captures the whole call.
	 */
	private static function templateCallPattern(
		string $name, bool $captureNameSeparately = false, bool $trailingWhitespace = false
	): string {
		$qName = preg_quote( $name, '/' );
		$trail = $trailingWhitespace ? '\s*' : '';
		if ( $captureNameSeparately ) {
			return '/(\{\{\s*)' . $qName . '(\s*\n[^}]*\}\})' . $trail . '/';
		}
		return '/\{\{\s*' . $qName . '\s*\n[^}]*\}\}' . $trail . '/';
	}

	/**
	 * Extract |key=value pairs from a template call string.
	 *
	 * @param string $templateCall e.g. "{{Cat2\n|description=hello\n}}"
	 * @return array<string,string>
	 */
	private function extractTemplateParams( string $templateCall ): array {
		$params = [];
		preg_match_all( '/\|([^=\n]+)=([^\n]*)/', $templateCall, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			$key = trim( $match[1] );
			$value = trim( $match[2] );
			if ( $key !== '' && $value !== '' ) {
				$params[$key] = $value;
			}
		}
		return $params;
	}

	/**
	 * Merge params into an existing template call, skipping keys already present.
	 *
	 * @param string $pageContent Full page wikitext
	 * @param string $templateName Template to merge into
	 * @param array<string,string> $params Key-value pairs to add
	 * @return string Updated page content
	 */
	private function mergeParamsIntoTemplate(
		string $pageContent, string $templateName, array $params
	): string {
		$pattern = '/(\{\{\s*' . preg_quote( $templateName, '/' ) . '\s*\n)([^}]*)(\}\})/';
		if ( !preg_match( $pattern, $pageContent, $m ) ) {
			return $pageContent;
		}

		$existingBody = $m[2];
		$newParams = '';
		foreach ( $params as $key => $value ) {
			if ( strpos( $existingBody, '|' . $key . '=' ) === false ) {
				$newParams .= '|' . $key . '=' . $value . "\n";
			}
		}

		if ( $newParams === '' ) {
			return $pageContent;
		}

		return preg_replace( $pattern, '$1$2' . $newParams . '$3', $pageContent, 1 );
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
			if ( !$managedParents ) {
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
				$html .= Html::openElement( 'div', [ 'class' => 's2-create-cat-children' ] ) .
					$childrenHtml .
					Html::closeElement( 'div' );
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
		$targetNamespace = $cat->getTargetNamespace() ?? '';
		$isExisting = isset( $existingCategories[$catName] );

		// Ancestors for JS (excluding self)
		$ancestors = $resolver->getAncestors( $catName );
		array_shift( $ancestors );
		$ancestorStr = implode( '|', $ancestors );

		// Unique ID per tree instance (category may appear multiple times)
		static $instanceCounter = 0;
		$instanceId = 's2-cat-' . $instanceCounter++;

		$attrs = [
			'id' => $instanceId,
			'value' => $catName,
			'data-category' => $catName,
			'data-ancestors' => $ancestorStr,
		];
		if ( $targetNamespace !== '' ) {
			$attrs['data-namespace'] = $targetNamespace;
		}
		if ( $isExisting ) {
			$attrs['disabled'] = true;
		}

		$labelHtml = Html::element( 'strong', [], $catLabel );
		if ( $targetNamespace !== '' ) {
			$labelHtml .= Html::element( 'span',
				[ 'class' => 'semanticschemas-badge is-muted s2-create-cat-ns-badge' ],
				$targetNamespace . ':'
			);
		}
		if ( $isExisting ) {
			$labelHtml .= Html::element( 'span',
				[ 'class' => 'semanticschemas-badge is-ok s2-create-cat-badge' ],
				$this->msg( 'semanticschemas-create-on-page' )->text()
			);
		}
		if ( $catDesc !== '' ) {
			$labelHtml .= Html::element( 'span',
				[ 'class' => 's2-create-cat-desc' ],
				$catDesc
			);
		}

		$rowClass = 's2-create-cat-item';
		if ( $isExisting ) {
			$rowClass .= ' is-existing';
		}
		if ( $hasChildren ) {
			$rowClass .= ' has-children';
		}

		$toggleHtml = $hasChildren
			? Html::element( 'span', [ 'class' => 's2-create-cat-toggle' ], '▸' )
			: Html::element( 'span', [ 'class' => 's2-create-cat-toggle-spacer' ] );

		return Html::openElement( 'div', [
			'class' => $rowClass,
			'style' => $depth > 0 ? '--depth: ' . $depth : '',
		] ) .
			$toggleHtml .
			Html::openElement( 'label', [ 'for' => $instanceId, 'class' => 's2-create-cat-label-wrap' ] ) .
			Html::check( 's2-categories[]', $isExisting, $attrs ) .
			( $isExisting ? Html::hidden( 's2-categories[]', $catName ) : '' ) .
			Html::openElement( 'span', [ 'class' => 's2-create-cat-label' ] ) .
			$labelHtml .
			Html::closeElement( 'span' ) .
			Html::closeElement( 'label' ) .
			Html::closeElement( 'div' );
	}

	protected function getGroupName() {
		return 'labki';
	}
}
