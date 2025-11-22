<?php

namespace MediaWiki\Extension\StructureSync\Special;

use MediaWiki\Extension\StructureSync\Generator\DisplayStubGenerator;
use MediaWiki\Extension\StructureSync\Generator\FormGenerator;
use MediaWiki\Extension\StructureSync\Generator\TemplateGenerator;
use MediaWiki\Extension\StructureSync\Schema\InheritanceResolver;
use MediaWiki\Extension\StructureSync\Schema\SchemaComparer;
use MediaWiki\Extension\StructureSync\Schema\SchemaExporter;
use MediaWiki\Extension\StructureSync\Schema\SchemaImporter;
use MediaWiki\Extension\StructureSync\Schema\SchemaLoader;
use MediaWiki\Extension\StructureSync\Store\WikiCategoryStore;
use MediaWiki\Extension\StructureSync\Store\WikiPropertyStore;
use MediaWiki\Extension\StructureSync\Store\StateManager;
use MediaWiki\Extension\StructureSync\Store\PageHashComputer;
use MediaWiki\Extension\StructureSync\Store\PageCreator;
use MediaWiki\Html\Html;
use MediaWiki\Request\WebRequest;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

/**
 * SpecialStructureSync
 * --------------------
 * Central UI for:
 *   - Inspecting current schema (export / preview)
 *   - Importing a schema (JSON/YAML) into the wiki
 *   - Validating wiki state against expected invariants
 *   - Generating Templates / Forms / Display templates from schema / categories
 *   - Comparing an external schema against current wiki-derived schema
 *
 * Assumptions:
 *   - Schema is treated as the source of truth.
 *   - Wiki pages (Category/Property/Form/Template) are compiled artifacts.
 */
class SpecialStructureSync extends SpecialPage {

	public function __construct() {
		parent::__construct( 'StructureSync', 'editinterface' );
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
				$this->msg( 'structuresync-error-no-smw' )->parse()
			) );
			return;
		}

		if ( !defined( 'PF_VERSION' ) ) {
			$output->addHTML( Html::errorBox(
				$this->msg( 'structuresync-error-no-pageforms' )->parse()
			) );
			return;
		}

		// Add ResourceLoader styles
		$output->addModuleStyles( 'ext.structuresync.styles' );

		// Determine action from subpage
		$action = $subPage ?: 'overview';

		// Navigation tabs
		$this->showNavigation( $action );

		// Dispatch
		switch ( $action ) {
			case 'export':
				$this->showExport();
				break;
			case 'import':
				$this->showImport();
				break;
			case 'validate':
				$this->showValidate();
				break;
			case 'generate':
				$this->showGenerate();
				break;
			case 'diff':
				$this->showDiff();
				break;
			case 'overview':
			default:
				$this->showOverview();
				break;
		}
	}

	/**
	 * Navigation tabs for Special:StructureSync
	 *
	 * @param string $currentAction
	 */
	private function showNavigation( string $currentAction ): void {
		$tabs = [
			'overview' => $this->msg( 'structuresync-overview' )->text(),
			'export'   => $this->msg( 'structuresync-export' )->text(),
			'import'   => $this->msg( 'structuresync-import' )->text(),
			'validate' => $this->msg( 'structuresync-validate' )->text(),
			'generate' => $this->msg( 'structuresync-generate' )->text(),
			'diff'     => $this->msg( 'structuresync-diff' )->text(),
		];

		$html = '<div class="structuresync-nav"><ul>';

		foreach ( $tabs as $action => $label ) {
			$class = ( $action === $currentAction ) ? 'active' : '';
			$url = $this->getPageTitle( $action )->getLocalURL();
			$html .= Html::rawElement(
				'li',
				[ 'class' => $class ],
				Html::element( 'a', [ 'href' => $url ], $label )
			);
		}

		$html .= '</ul></div>';

		$this->getOutput()->addHTML( $html );
	}

	/**
	 * Overview page: summarises current schema + category/template/form status.
	 */
	private function showOverview(): void {
		$output = $this->getOutput();
		$output->setPageTitle( $this->msg( 'structuresync-overview' )->text() );

		$exporter = new SchemaExporter();
		$stats = $exporter->getStatistics();
		$stateManager = new StateManager();

		// Check sync status
		$isDirty = $stateManager->isDirty();
		$sourceSchemaHash = $stateManager->getSourceSchemaHash();

		// Status box at top
		if ( $isDirty ) {
			$statusMessage = $this->msg( 'structuresync-status-out-of-sync' )->text();
			$generateUrl = $this->getPageTitle( 'generate' )->getLocalURL();
			$generateLink = Html::element(
				'a',
				[ 'href' => $generateUrl ],
				$this->msg( 'structuresync-status-generate-link' )->text()
			);
			$statusMessage .= ' ' . $generateLink;
			$html = Html::rawElement(
				'div',
				[ 'class' => 'mw-message-box mw-message-box-error' ],
				$statusMessage
			);
		} else {
			$html = Html::rawElement(
				'div',
				[ 'class' => 'mw-message-box mw-message-box-success' ],
				$this->msg( 'structuresync-status-in-sync' )->text()
			);
		}

		$html .= Html::element(
			'h2',
			[],
			$this->msg( 'structuresync-overview-summary' )->text()
		);

		// Basic statistics (category & property counts)
		$html .= Html::openElement( 'div', [ 'class' => 'structuresync-stats' ] );
		$html .= Html::element(
			'p',
			[],
			$this->msg( 'structuresync-categories-count' )
				->numParams( $stats['categoryCount'] ?? 0 )
				->text()
		);
		$html .= Html::element(
			'p',
			[],
			$this->msg( 'structuresync-properties-count' )
				->numParams( $stats['propertyCount'] ?? 0 )
				->text()
		);
		$html .= Html::closeElement( 'div' );

		// Category status table
		$html .= Html::element( 'h3', [], 'Categories' );
		$html .= $this->getCategoryStatusTable();

		$output->addHTML( $html );
	}

	/**
	 * Status table: each category + whether templates/forms/display exist.
	 *
	 * @return string HTML
	 */
	private function getCategoryStatusTable(): string {
		$categoryStore    = new WikiCategoryStore();
		$propertyStore    = new WikiPropertyStore();
		$templateGenerator = new TemplateGenerator();
		$formGenerator     = new FormGenerator();
		$displayGenerator  = new DisplayStubGenerator();
		$stateManager      = new StateManager();
		$hashComputer      = new PageHashComputer();
		$pageCreator       = new PageCreator();

		$categories = $categoryStore->getAllCategories();

		if ( empty( $categories ) ) {
			// Could be localized with a message key if desired.
			return Html::element( 'p', [], 'No categories found.' );
		}

		// Get stored hashes for comparison
		$storedHashes = $stateManager->getPageHashes();
		$modifiedPages = [];

		// Check each category and its properties
		foreach ( $categories as $category ) {
			$categoryName = $category->getName();
			$pageName = "Category:$categoryName";
			
			$title = $pageCreator->makeTitle( $categoryName, NS_CATEGORY );
			if ( $title && $title->exists() ) {
				$content = $pageCreator->getPageContent( $title );
				if ( $content !== null ) {
					$currentHash = $hashComputer->computeCategoryHash( $content );
					$storedHash = $storedHashes[$pageName]['generated'] ?? '';
					if ( $storedHash !== '' && $currentHash !== $storedHash ) {
						$modifiedPages[$pageName] = true;
					}
				}
			}

			// Check all properties used by this category
			$allProperties = $category->getAllProperties();
			foreach ( $allProperties as $propertyName ) {
				$propPageName = "Property:$propertyName";
				if ( isset( $modifiedPages[$propPageName] ) ) {
					continue; // Already checked
				}

				$propTitle = $pageCreator->makeTitle( $propertyName, \SMW_NS_PROPERTY );
				if ( $propTitle && $propTitle->exists() ) {
					$propContent = $pageCreator->getPageContent( $propTitle );
					if ( $propContent !== null ) {
						$currentHash = $hashComputer->computePropertyHash( $propContent );
						$storedHash = $storedHashes[$propPageName]['generated'] ?? '';
						if ( $storedHash !== '' && $currentHash !== $storedHash ) {
							$modifiedPages[$propPageName] = true;
						}
					}
				}
			}
		}

		$html = Html::openElement(
			'table',
			[ 'class' => 'wikitable sortable structuresync-category-table' ]
		);

		$html .= Html::openElement( 'thead' );
		$html .= Html::openElement( 'tr' );
		$html .= Html::element( 'th', [], 'Category' );
		$html .= Html::element( 'th', [], 'Parents' );
		$html .= Html::element( 'th', [], 'Properties' );
		$html .= Html::element( 'th', [], 'Template' );
		$html .= Html::element( 'th', [], 'Form' );
		$html .= Html::element( 'th', [], 'Display' );
		$html .= Html::element( 'th', [], $this->msg( 'structuresync-status-modified-outside' )->text() );
		$html .= Html::closeElement( 'tr' );
		$html .= Html::closeElement( 'thead' );

		$html .= Html::openElement( 'tbody' );
		foreach ( $categories as $category ) {
			$name = $category->getName();
			$pageName = "Category:$name";

			// Check if this category or any of its properties are modified
			$isModified = isset( $modifiedPages["Category:$name"] );
			if ( !$isModified ) {
				// Check if any properties are modified
				foreach ( $category->getAllProperties() as $propName ) {
					if ( isset( $modifiedPages["Property:$propName"] ) ) {
						$isModified = true;
						break;
					}
				}
			}

			$html .= Html::openElement( 'tr' );
			$html .= Html::element( 'td', [], $name );
			$html .= Html::element( 'td', [], (string)count( $category->getParents() ) );
			$html .= Html::element( 'td', [], (string)count( $category->getAllProperties() ) );
			$html .= Html::element(
				'td',
				[],
				$templateGenerator->semanticTemplateExists( $name ) ? '✓' : '✗'
			);
			$html .= Html::element(
				'td',
				[],
				$formGenerator->formExists( $name ) ? '✓' : '✗'
			);
			$html .= Html::element(
				'td',
				[],
				$displayGenerator->displayStubExists( $name ) ? '✓' : '✗'
			);
			$html .= Html::element(
				'td',
				[],
				$isModified ? '✗' : '✓'
			);
			$html .= Html::closeElement( 'tr' );
		}
		$html .= Html::closeElement( 'tbody' );
		$html .= Html::closeElement( 'table' );

		return $html;
	}

	/**
	 * Export schema (JSON/YAML) and render a preview + download link.
	 */
	private function showExport(): void {
		$output  = $this->getOutput();
		$request = $this->getRequest();

		$output->setPageTitle( $this->msg( 'structuresync-export-title' )->text() );

		$format   = $request->getVal( 'format', 'json' );
		$download = $request->getBool( 'download', false );

		$exporter = new SchemaExporter();
		$schema   = $exporter->exportToArray( false );
		$loader   = new SchemaLoader();

		// Handle direct download (no HTML output)
		if (
			$request->wasPosted() &&
			$request->getVal( 'action' ) === 'export' &&
			$download
		) {
			// No CSRF risk here; this is a GET-like download triggered from UI.

			if ( $format === 'yaml' ) {
				$content     = $loader->saveToYaml( $schema );
				$filename    = 'schema.yaml';
				$contentType = 'text/yaml';
			} else {
				$content     = $loader->saveToJson( $schema );
				$filename    = 'schema.json';
				$contentType = 'application/json';
			}

			/** @var WebRequest $request */
			$response = $request->response();
			$response->header( 'Content-Type: ' . $contentType );
			$response->header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			echo $content;
			// Bypass normal rendering.
			return;
		}

		// Prepare schema content for preview
		if ( $format === 'yaml' ) {
			$schemaContent = $loader->saveToYaml( $schema );
		} else {
			$schemaContent = $loader->saveToJson( $schema );
		}

		$html = Html::element(
			'p',
			[],
			$this->msg( 'structuresync-export-description' )->text()
		);

		// Format selector
		$html .= Html::openElement(
			'form',
			[
				'method' => 'get',
				'action' => $this->getPageTitle( 'export' )->getLocalURL()
			]
		);

		$html .= Html::openElement( 'div', [ 'class' => 'structuresync-form-group' ] );
		$html .= Html::element(
			'label',
			[],
			$this->msg( 'structuresync-export-format' )->text()
		);

		$html .= Html::openElement( 'select', [
			'name'     => 'format',
			'onchange' => 'this.form.submit()'
		] );
		$html .= Html::element(
			'option',
			[
				'value'    => 'json',
				'selected' => $format === 'json'
			],
			$this->msg( 'structuresync-export-format-json' )->text()
		);
		$html .= Html::element(
			'option',
			[
				'value'    => 'yaml',
				'selected' => $format === 'yaml'
			],
			$this->msg( 'structuresync-export-format-yaml' )->text()
		);
		$html .= Html::closeElement( 'select' );
		$html .= Html::closeElement( 'div' );

		$html .= Html::closeElement( 'form' );

		// Preview heading
		$html .= Html::element(
			'h3',
			[],
			$this->msg( 'structuresync-export-schema-preview' )->text()
		);

		// Download button
		$downloadUrl = $this->getPageTitle( 'export' )->getLocalURL( [
			'format'   => $format,
			'action'   => 'export',
			'download' => '1'
		] );

		$html .= Html::openElement( 'div', [ 'class' => 'structuresync-export-actions' ] );
		$html .= Html::element(
			'a',
			[
				'href'  => $downloadUrl,
				'class' => 'mw-ui-button mw-ui-progressive',
				'style' => 'margin-bottom: 10px; display: inline-block;'
			],
			$this->msg( 'structuresync-export-download' )->text()
		);
		$html .= Html::closeElement( 'div' );

		// Schema content preview
		$html .= Html::openElement( 'div', [ 'class' => 'structuresync-schema-display' ] );
		$html .= Html::openElement( 'pre', [
			'class' => 'structuresync-schema-content',
			'style' => 'background: #f8f9fa; border: 1px solid #a7d7f9; padding: 15px; overflow-x: auto; max-height: 600px; overflow-y: auto;'
		] );
		$html .= Html::element( 'code', [], htmlspecialchars( $schemaContent ) );
		$html .= Html::closeElement( 'pre' );
		$html .= Html::closeElement( 'div' );

		// Simple copy-to-clipboard helper
		$html .= Html::openElement( 'div', [ 'class' => 'structuresync-export-actions' ] );
		$html .= Html::openElement( 'button', [
			'type'   => 'button',
			'class'  => 'mw-ui-button',
			'onclick' => 'navigator.clipboard.writeText(' . json_encode( $schemaContent ) . ').then(() => alert(\'Copied to clipboard!\')).catch(() => alert(\'Failed to copy\'));',
			'style'  => 'margin-top: 10px;'
		] );
		$html .= $this->msg( 'structuresync-export-copy' )->text();
		$html .= Html::closeElement( 'button' );
		$html .= Html::closeElement( 'div' );

		$output->addHTML( $html );
	}

	/**
	 * Import schema (JSON/YAML) into wiki.
	 * This does NOT automatically regenerate artifacts; generation happens
	 * through the "Generate" step after validation.
	 */
	private function showImport(): void {
		$output  = $this->getOutput();
		$request = $this->getRequest();

		$output->setPageTitle( $this->msg( 'structuresync-import-title' )->text() );

		// Handle POST
		if ( $request->wasPosted() && $request->getVal( 'action' ) === 'import' ) {
			if ( !$this->getUser()->matchEditToken( $request->getVal( 'token' ) ) ) {
				$output->addHTML( Html::errorBox( 'Invalid edit token' ) );
				return;
			}
			$this->processImport();
			return;
		}

		// Render import form
		$html = Html::element(
			'p',
			[],
			$this->msg( 'structuresync-import-description' )->text()
		);

		$html .= Html::openElement( 'form', [
			'method'  => 'post',
			'enctype' => 'multipart/form-data',
			'action'  => $this->getPageTitle( 'import' )->getLocalURL()
		] );

		// File upload input
		$html .= Html::openElement( 'div', [ 'class' => 'structuresync-form-group' ] );
		$html .= Html::element(
			'label',
			[],
			$this->msg( 'structuresync-import-file' )->text()
		);
		$html .= Html::element( 'input', [
			'type'   => 'file',
			'name'   => 'schemafile',
			'accept' => '.json,.yaml,.yml'
		] );
		$html .= Html::closeElement( 'div' );

		// Textarea input
		$html .= Html::openElement( 'div', [ 'class' => 'structuresync-form-group' ] );
		$html .= Html::element(
			'label',
			[],
			$this->msg( 'structuresync-import-text' )->text()
		);
		$html .= Html::element( 'textarea', [
			'name' => 'schematext',
			'rows' => '10',
			'cols' => '80'
		], '' );
		$html .= Html::closeElement( 'div' );

		// Dry run checkbox
		$html .= Html::openElement( 'div', [ 'class' => 'structuresync-form-group' ] );
		$html .= Html::check( 'dryrun', false, [ 'id' => 'dryrun' ] );
		$html .= Html::element(
			'label',
			[ 'for' => 'dryrun' ],
			$this->msg( 'structuresync-import-dryrun' )->text()
		);
		$html .= Html::closeElement( 'div' );

		$html .= Html::hidden( 'action', 'import' );
		$html .= Html::hidden( 'token', $this->getUser()->getEditToken() );

		$html .= Html::submitButton(
			$this->msg( 'structuresync-import-button' )->text(),
			[ 'class' => 'mw-ui-button mw-ui-progressive' ]
		);

		$html .= Html::closeElement( 'form' );

		$output->addHTML( $html );
	}

	/**
	 * Process import POST request.
	 */
	private function processImport(): void {
		$output  = $this->getOutput();
		$request = $this->getRequest();

		$dryRun = $request->getBool( 'dryrun' );

		try {
			$loader = new SchemaLoader();
			$content = null;

			// 1) File upload if present
			$upload = $request->getUpload( 'schemafile' );
			if ( $upload && $upload->exists() && $upload->getTempName() !== '' ) {
				$content = @file_get_contents( $upload->getTempName() );
			}

			// 2) Fallback to textarea
			if ( $content === null || $content === '' ) {
				$content = $request->getText( 'schematext' );
			}

			if ( $content === null || trim( $content ) === '' ) {
				$output->addHTML( Html::errorBox( 'No schema provided' ) );
				return;
			}

			$schema   = $loader->loadFromContent( $content );
			$importer = new SchemaImporter();

			// Do not auto-generate artifacts here; generation goes through "Generate".
			$result = $importer->importFromArray( $schema, [
				'dryRun'           => $dryRun,
				'generateArtifacts' => false,
			] );

			if ( $result['success'] ?? false ) {
				$message = $this->msg( 'structuresync-import-success' )->text() . '<br>';

				$createdCount = (int)( $result['categoriesCreated'] ?? 0 )
					+ (int)( $result['propertiesCreated'] ?? 0 );
				$updatedCount = (int)( $result['categoriesUpdated'] ?? 0 )
					+ (int)( $result['propertiesUpdated'] ?? 0 );

				$message .= $this->msg( 'structuresync-import-created' )
					->numParams( $createdCount )
					->text() . '<br>';
				$message .= $this->msg( 'structuresync-import-updated' )
					->numParams( $updatedCount )
					->text();

				if ( !$dryRun ) {
					$message .= '<br>' .
						$this->msg( 'structuresync-import-posthint-generate' )->text();
				}

				$output->addHTML( Html::successBox( $message ) );
			} else {
				$errors = $result['errors'] ?? [ 'Unknown import error' ];
				$message = implode( '<br>', $errors );
				$output->addHTML( Html::errorBox( $message ) );
			}
		} catch ( \Exception $e ) {
			$output->addHTML( Html::errorBox( 'Error: ' . $e->getMessage() ) );
		}
	}

	/**
	 * Validate wiki state against expected invariants.
	 */
	private function showValidate(): void {
		$output = $this->getOutput();
		$output->setPageTitle( $this->msg( 'structuresync-validate-title' )->text() );

		$exporter = new SchemaExporter();
		$result   = $exporter->validateWikiState();
		$stateManager = new StateManager();

		$html = Html::element(
			'p',
			[],
			$this->msg( 'structuresync-validate-description' )->text()
		);

		// Check for schema changes
		$sourceSchemaHash = $stateManager->getSourceSchemaHash();
		if ( $sourceSchemaHash !== null ) {
			// Schema hash exists, but we can't compare with current import here
			// This would require storing the current import's hash, which is done in import flow
		}

		if ( empty( $result['errors'] ) ) {
			$html .= Html::successBox(
				$this->msg( 'structuresync-validate-success' )->text()
			);
		} else {
			$html .= Html::element(
				'h3',
				[],
				$this->msg( 'structuresync-validate-errors' )->text()
			);
			$html .= Html::openElement( 'ul' );
			foreach ( $result['errors'] as $error ) {
				$html .= Html::element( 'li', [], $error );
			}
			$html .= Html::closeElement( 'ul' );
		}

		if ( !empty( $result['warnings'] ) ) {
			$html .= Html::element(
				'h3',
				[],
				$this->msg( 'structuresync-validate-warnings' )->text()
			);
			$html .= Html::openElement( 'ul' );
			foreach ( $result['warnings'] as $warning ) {
				$html .= Html::element( 'li', [], $warning );
			}
			$html .= Html::closeElement( 'ul' );
		}

		// Display modified pages if any
		$modifiedPages = $result['modifiedPages'] ?? [];
		if ( !empty( $modifiedPages ) ) {
			$html .= Html::element(
				'h3',
				[],
				$this->msg( 'structuresync-validate-modified-pages' )
					->numParams( count( $modifiedPages ) )
					->text()
			);
			$html .= Html::openElement( 'ul' );
			foreach ( $modifiedPages as $pageName ) {
				$html .= Html::element( 'li', [], $pageName );
			}
			$html .= Html::closeElement( 'ul' );
		}

		$output->addHTML( $html );
	}

	/**
	 * Show the "Generate" page for regenerating templates/forms/display.
	 */
	private function showGenerate(): void {
		$output  = $this->getOutput();
		$request = $this->getRequest();

		$output->setPageTitle( $this->msg( 'structuresync-generate-title' )->text() );

		if ( $request->wasPosted() && $request->getVal( 'action' ) === 'generate' ) {
			if ( !$this->getUser()->matchEditToken( $request->getVal( 'token' ) ) ) {
				$output->addHTML( Html::errorBox( 'Invalid edit token' ) );
				return;
			}
			$this->processGenerate();
			return;
		}

		$html = Html::element(
			'p',
			[],
			$this->msg( 'structuresync-generate-description' )->text()
		);

		$html .= Html::openElement( 'form', [
			'method' => 'post',
			'action' => $this->getPageTitle( 'generate' )->getLocalURL()
		] );

		// Category picker driven by current wiki categories
		$categoryStore = new WikiCategoryStore();
		$categories    = $categoryStore->getAllCategories();

		$html .= Html::openElement( 'div', [ 'class' => 'structuresync-form-group' ] );
		$html .= Html::element(
			'label',
			[],
			$this->msg( 'structuresync-generate-category' )->text()
		);
		$html .= Html::openElement( 'select', [ 'name' => 'category' ] );
		$html .= Html::element(
			'option',
			[ 'value' => '' ],
			$this->msg( 'structuresync-generate-all' )->text()
		);
		foreach ( $categories as $category ) {
			$name = $category->getName();
			$html .= Html::element(
				'option',
				[ 'value' => $name ],
				$name
			);
		}
		$html .= Html::closeElement( 'select' );
		$html .= Html::closeElement( 'div' );

		$html .= Html::hidden( 'action', 'generate' );
		$html .= Html::hidden( 'token', $this->getUser()->getEditToken() );

		$html .= Html::submitButton(
			$this->msg( 'structuresync-generate-button' )->text(),
			[ 'class' => 'mw-ui-button mw-ui-progressive' ]
		);

		$html .= Html::closeElement( 'form' );

		$output->addHTML( $html );
	}

	/**
	 * Process "Generate" POST:
	 *   - Build inheritance graph from current categories.
	 *   - For each category: compute effective category + ancestor chain.
	 *   - Invoke TemplateGenerator, FormGenerator (with ancestors), DisplayStubGenerator.
	 */
	private function processGenerate(): void {
		$output        = $this->getOutput();
		$request       = $this->getRequest();

		$categoryName     = trim( $request->getText( 'category' ) );
		$categoryStore    = new WikiCategoryStore();
		$templateGenerator = new TemplateGenerator();
		$formGenerator     = new FormGenerator();
		$displayGenerator  = new DisplayStubGenerator();

		try {
			// Determine target categories
			if ( $categoryName === '' ) {
				$categories = $categoryStore->getAllCategories();
			} else {
				$single = $categoryStore->readCategory( $categoryName );
				$categories = $single ? [ $single ] : [];
			}

			if ( empty( $categories ) ) {
				$output->addHTML( Html::errorBox( 'No matching categories found.' ) );
				return;
			}

			// Build map for inheritance resolution
			$categoryMap = [];
			foreach ( $categories as $cat ) {
				$categoryMap[ $cat->getName() ] = $cat;
			}

			// In many cases, you want to include *all* categories in the resolver,
			// not only the subset being generated, so parent relationships resolve.
			$allCategories = $categoryStore->getAllCategories();
			foreach ( $allCategories as $cat ) {
				$name = $cat->getName();
				if ( !isset( $categoryMap[ $name ] ) ) {
					$categoryMap[ $name ] = $cat;
				}
			}

			$resolver = new InheritanceResolver( $categoryMap );

			$stateManager = new StateManager();
			$hashComputer = new PageHashComputer();
			$pageCreator = new PageCreator();
			$propertyStore = new WikiPropertyStore();

			foreach ( $categories as $category ) {
				$name = $category->getName();

				// Effective category (merged properties)
				$effective = $resolver->getEffectiveCategory( $name );
				// Ancestor chain for layout grouping (Option C)
				$ancestors = $resolver->getAncestors( $name );

				$templateGenerator->generateAllTemplates( $effective );
				$formGenerator->generateAndSaveForm( $effective, $ancestors );
				$displayGenerator->generateDisplayStubIfMissing( $effective );
			}

			// Compute and store hashes for all generated pages
			$pageHashes = [];
			
			// Hash all categories (StructureSync section only)
			$allCategories = $categoryStore->getAllCategories();
			foreach ( $allCategories as $category ) {
				$categoryName = $category->getName();
				$title = $pageCreator->makeTitle( $categoryName, NS_CATEGORY );
				if ( $title && $title->exists() ) {
					$content = $pageCreator->getPageContent( $title );
					if ( $content !== null ) {
						$hash = $hashComputer->computeCategoryHash( $content );
						$pageHashes["Category:$categoryName"] = $hash;
					}
				}
			}

			// Hash all properties (full page)
			$allProperties = $propertyStore->getAllProperties();
			foreach ( $allProperties as $property ) {
				$propertyName = $property->getName();
				$title = $pageCreator->makeTitle( $propertyName, \SMW_NS_PROPERTY );
				if ( $title && $title->exists() ) {
					$content = $pageCreator->getPageContent( $title );
					if ( $content !== null ) {
						$hash = $hashComputer->computePropertyHash( $content );
						$pageHashes["Property:$propertyName"] = $hash;
					}
				}
			}

			// Store hashes and clear dirty flag
			if ( !empty( $pageHashes ) ) {
				$stateManager->setPageHashes( $pageHashes );
				$stateManager->clearDirty();
			}

			$output->addHTML(
				Html::successBox(
					$this->msg( 'structuresync-generate-success' )->text()
				)
			);
		} catch ( \Exception $e ) {
			$output->addHTML( Html::errorBox( 'Error: ' . $e->getMessage() ) );
		}
	}

	/**
	 * Show diff page: compare an external schema to current wiki-derived schema.
	 */
	private function showDiff(): void {
		$output  = $this->getOutput();
		$request = $this->getRequest();

		$output->setPageTitle( $this->msg( 'structuresync-diff-title' )->text() );

		if ( $request->wasPosted() && $request->getVal( 'action' ) === 'diff' ) {
			if ( !$this->getUser()->matchEditToken( $request->getVal( 'token' ) ) ) {
				$output->addHTML( Html::errorBox( 'Invalid edit token' ) );
				return;
			}
			$this->processDiff();
			return;
		}

		$html = Html::element(
			'p',
			[],
			$this->msg( 'structuresync-diff-description' )->text()
		);

		$html .= Html::openElement( 'form', [
			'method'  => 'post',
			'enctype' => 'multipart/form-data',
			'action'  => $this->getPageTitle( 'diff' )->getLocalURL()
		] );

		$html .= Html::openElement( 'div', [ 'class' => 'structuresync-form-group' ] );
		$html .= Html::element(
			'label',
			[],
			$this->msg( 'structuresync-diff-file' )->text()
		);
		$html .= Html::element( 'textarea', [
			'name' => 'schematext',
			'rows' => '10',
			'cols' => '80'
		], '' );
		$html .= Html::closeElement( 'div' );

		$html .= Html::hidden( 'action', 'diff' );
		$html .= Html::hidden( 'token', $this->getUser()->getEditToken() );

		$html .= Html::submitButton(
			$this->msg( 'structuresync-diff-button' )->text(),
			[ 'class' => 'mw-ui-button mw-ui-progressive' ]
		);

		$html .= Html::closeElement( 'form' );

		$output->addHTML( $html );
	}

	/**
	 * Process diff submission: external schema vs current wiki schema.
	 */
	private function processDiff(): void {
		$output  = $this->getOutput();
		$request = $this->getRequest();

		try {
			$content = $request->getText( 'schematext' );
			if ( trim( $content ) === '' ) {
				$output->addHTML( Html::errorBox( 'No schema provided' ) );
				return;
			}

			$loader    = new SchemaLoader();
			$fileSchema = $loader->loadFromContent( $content );

			$exporter  = new SchemaExporter();
			$wikiSchema = $exporter->exportToArray( false );

			$comparer = new SchemaComparer();
			$diff     = $comparer->compare( $fileSchema, $wikiSchema );
			$summary  = $comparer->generateSummary( $diff );

			$output->addHTML(
				Html::element( 'pre', [], $summary )
			);
		} catch ( \Exception $e ) {
			$output->addHTML( Html::errorBox( 'Error: ' . $e->getMessage() ) );
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
}
