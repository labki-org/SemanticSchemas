<?php

namespace MediaWiki\Extension\SemanticSchemas\Special;

use MediaWiki\Extension\SemanticSchemas\Generator\DisplayStubGenerator;
use MediaWiki\Extension\SemanticSchemas\Generator\FormGenerator;
use MediaWiki\Extension\SemanticSchemas\Generator\TemplateGenerator;
use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\ExtensionConfigInstaller;
use MediaWiki\Extension\SemanticSchemas\Schema\InheritanceResolver;
use MediaWiki\Extension\SemanticSchemas\Schema\OntologyInspector;
use MediaWiki\Extension\SemanticSchemas\Store\PageHashComputer;
use MediaWiki\Extension\SemanticSchemas\Store\StateManager;
use MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;
use MediaWiki\Extension\SemanticSchemas\Store\WikiSubobjectStore;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

/**
 * SpecialSemanticSchemas
 * ----------------------
 * Central administrative UI for SemanticSchemas schema management.
 *
 * Provides four main views:
 * - Overview: Dashboard with category status and sync state
 * - Validate: Check wiki state for errors and modifications
 * - Generate: Regenerate templates/forms/displays
 * - Hierarchy: Visualize category inheritance and subobjects
 *
 * Architecture:
 * - Schema is treated as the source of truth
 * - Wiki pages (Category/Property/Form/Template) are compiled artifacts
 * - State tracking prevents unnecessary regeneration
 * - Hash-based dirty detection identifies external modifications
 *
 * Security:
 * - Requires 'editinterface' permission
 * - CSRF token validation on all form submissions (matchEditToken)
 * - Input sanitization via MediaWiki's request handling
 * - Rate limiting: max 20 operations per hour per user
 * - Sysops can bypass rate limits via 'protect' permission
 * - All operations logged to MediaWiki log system (audit trail)
 */
class SpecialSemanticSchemas extends SpecialPage {

	/** @var int Default rate limit: max operations per hour per user */
	private const DEFAULT_RATE_LIMIT_PER_HOUR = 20;

	/** @var string Cache key for rate limiting */
	private const RATE_LIMIT_CACHE_PREFIX = 'semanticschemas-ratelimit';

	/**
	 * Get the rate limit from configuration.
	 *
	 * @return int Maximum operations per hour
	 */
	private function getRateLimitPerHour(): int {
		$config = $this->getConfig();
		return (int)$config->get( 'SemanticSchemasRateLimitPerHour' )
			?: self::DEFAULT_RATE_LIMIT_PER_HOUR;
	}

	public function __construct() {
		parent::__construct( 'SemanticSchemas', 'editinterface' );
	}

	/**
	 * Check rate limiting for expensive operations.
	 *
	 * Limits users to a maximum number of import/generate operations per hour
	 * to prevent abuse and server overload.
	 *
	 * @param string $operation Operation name (import, generate, etc.)
	 * @return bool True if rate limit exceeded
	 */
	private function checkRateLimit( string $operation ): bool {
		$user = $this->getUser();

		// Exempt sysops from rate limiting
		if ( $user->isAllowed( 'semanticschemas-bypass-ratelimit' ) || $user->isAllowed( 'protect' ) ) {
			return false;
		}

		$cache = \ObjectCache::getLocalClusterInstance();
		$key = $cache->makeKey( self::RATE_LIMIT_CACHE_PREFIX, $user->getId(), $operation );

		$count = $cache->get( $key ) ?: 0;

		if ( $count >= $this->getRateLimitPerHour() ) {
			return true; // Rate limit exceeded
		}

		// Increment counter (expires in 1 hour)
		$cache->set( $key, $count + 1, 3600 );

		return false;
	}

	/**
	 * Log an administrative operation to MediaWiki logs.
	 *
	 * Creates audit trail for import/export/generate operations.
	 *
	 * @param string $action Action performed (import, export, generate)
	 * @param string $details Additional details about the operation
	 * @param array $params Structured parameters for the log entry
	 */
	private function logOperation( string $action, string $details, array $params = [] ): void {
		$logEntry = new \ManualLogEntry( 'semanticschemas', $action );
		$logEntry->setPerformer( $this->getUser() );
		$logEntry->setTarget( $this->getPageTitle() );
		$logEntry->setComment( $details );
		$logEntry->setParameters( $params );
		$logId = $logEntry->insert();
		$logEntry->publish( $logId );
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

		// Check for generate-form action (from Category page link)
		$action = $request->getVal( 'action' );
		if ( $action === 'generate-form' ) {
			$this->handleGenerateFormAction();
			return;
		}

		// Check for install-config action
		if ( $action === 'install-config' ) {
			$this->handleInstallConfigAction();
			return;
		}

		// Check for install-properties action (Step 1)
		if ( $action === 'install-properties' ) {
			$this->handleInstallPropertiesAction();
			return;
		}

		// Check for install-categories action (Step 2)
		if ( $action === 'install-categories' ) {
			$this->handleInstallCategoriesAction();
			return;
		}

		// Add ResourceLoader styles
		$output->addModuleStyles( 'ext.semanticschemas.styles' );

		// Determine action from subpage
		$action = $subPage ?: 'overview';

		// Navigation tabs
		$this->showNavigation( $action );

		// Dispatch
		switch ( $action ) {
			case 'validate':
				$this->showValidate();
				break;
			case 'generate':
				$this->showGenerate();
				break;
			case 'hierarchy':
				$this->showHierarchy();
				break;
			case 'overview':
			default:
				$this->showOverview();
				break;
		}
	}

	/**
	 * Handle the "generate-form" action from Category page links.
	 *
	 * This generates the full artifact set for a single category:
	 * - Form (always regenerated)
	 * - Dispatcher template (always regenerated)
	 * - Semantic template (always regenerated)
	 * - Display template (conditional - only if not user-customized)
	 * - Subobject templates (always regenerated)
	 *
	 * After generation, redirects to the Form page.
	 */
	private function handleGenerateFormAction(): void {
		$request = $this->getRequest();
		$output = $this->getOutput();

		$categoryName = trim( $request->getVal( 'category', '' ) );

		if ( $categoryName === '' ) {
			$output->addHTML( Html::errorBox(
				$this->msg( 'semanticschemas-generate-form-no-category' )->text()
			) );
			return;
		}

		// Check rate limit
		if ( $this->checkRateLimit( 'generate-form' ) ) {
			$output->addHTML( Html::errorBox(
				$this->msg( 'semanticschemas-ratelimit-exceeded' )
					->params( $this->getRateLimitPerHour() )
					->text()
			) );
			return;
		}

		// Load the category
		$categoryStore = new WikiCategoryStore();
		$category = $categoryStore->readCategory( $categoryName );

		if ( $category === null ) {
			$output->addHTML( Html::errorBox(
				$this->msg( 'semanticschemas-generate-form-no-schema' )->params( $categoryName )->text()
			) );
			return;
		}

		try {
			// Build category map for inheritance resolution
			$categoryMap = $this->buildCategoryMap( $categoryStore );
			$resolver = new InheritanceResolver( $categoryMap );

			// Resolve inheritance to get effective category with all inherited properties
			$effective = $resolver->getEffectiveCategory( $categoryName );

			// Generate templates (always regenerate auto-generated ones)
			$templateGenerator = new TemplateGenerator();
			$templateResult = $templateGenerator->generateAllTemplates( $effective );

			if ( !$templateResult['success'] ) {
				$output->addHTML( Html::errorBox(
					$this->msg( 'semanticschemas-generate-form-failed' )
						->params( $categoryName, implode( ', ', $templateResult['errors'] ) )
						->text()
				) );
				return;
			}

			// Generate form
			$formGenerator = new FormGenerator();
			$formSuccess = $formGenerator->generateAndSaveForm( $effective );

			if ( !$formSuccess ) {
				$output->addHTML( Html::errorBox(
					$this->msg( 'semanticschemas-generate-form-failed' )
						->params( $categoryName, 'Failed to save form' )
						->text()
				) );
				return;
			}

			// Generate display template conditionally (respect user customizations)
			$displayGenerator = new DisplayStubGenerator();
			$displayResult = $displayGenerator->generateIfAllowed( $effective );

			// Log the operation
			$this->logOperation( 'generate', "Form generated for $categoryName", [
				'category' => $categoryName,
				'displayStatus' => $displayResult['status'],
			] );

			// Redirect to the form page
			$formTitle = Title::makeTitleSafe( $this->getFormNamespace(), $categoryName );
			if ( $formTitle ) {
				$output->redirect( $formTitle->getFullURL() );
			} else {
				// Fallback: show success message if redirect fails
				$output->addHTML( Html::successBox(
					$this->msg( 'semanticschemas-form-generated' )->params( $categoryName )->text()
				) );

				// Show warning if display template was preserved
				if ( $displayResult['status'] === 'preserved' ) {
					$output->addHTML( Html::warningBox( $displayResult['message'] ) );
				}
			}

		} catch ( \Exception $e ) {
			$this->logOperation( 'generate', "Form generation failed for $categoryName: " . $e->getMessage(), [
				'category' => $categoryName,
				'exception' => get_class( $e ),
			] );

			$output->addHTML( Html::errorBox(
				$this->msg( 'semanticschemas-generate-form-failed' )
					->params( $categoryName, $e->getMessage() )
					->text()
			) );
		}
	}

	/**
	 * Handle the "install-config" action to install/reinstall base configuration.
	 *
	 * This installs the base Category/Property/Subobject pages from extension-config.json.
	 * Requires a valid CSRF token and is subject to rate limiting.
	 */
	private function handleInstallConfigAction(): void {
		$request = $this->getRequest();
		$output = $this->getOutput();

		// Add ResourceLoader styles for consistent look
		$output->addModuleStyles( 'ext.semanticschemas.styles' );

		// CSRF check for POST requests
		if ( $request->wasPosted() ) {
			if ( !$this->getUser()->matchEditToken( $request->getVal( 'token' ) ) ) {
				$output->addHTML( Html::errorBox( 'Invalid edit token' ) );
				return;
			}
		} else {
			// GET request - show confirmation form
			$this->showInstallConfigConfirmation();
			return;
		}

		// Check rate limit
		if ( $this->checkRateLimit( 'install-config' ) ) {
			$output->addHTML( Html::errorBox(
				$this->msg( 'semanticschemas-ratelimit-exceeded' )
					->params( $this->getRateLimitPerHour() )
					->text()
			) );
			return;
		}

		$configPath = __DIR__ . '/../../resources/extension-config.json';
		if ( !file_exists( $configPath ) ) {
			$output->addHTML( Html::errorBox(
				$this->msg( 'semanticschemas-install-config-not-found' )->text()
			) );
			return;
		}

		try {
			$installer = new ExtensionConfigInstaller();
			$result = $installer->applyFromFile( $configPath );

			if ( !empty( $result['errors'] ) ) {
				$output->addHTML( Html::errorBox(
					$this->msg( 'semanticschemas-install-config-failed' )
						->params( implode( ', ', $result['errors'] ) )
						->text()
				) );
				return;
			}

			// Build a summary of what was installed
			$summary = [];
			$created = $result['created'] ?? [];
			$updated = $result['updated'] ?? [];

			$createdCount = count( $created['properties'] ?? [] ) +
				count( $created['categories'] ?? [] ) +
				count( $created['subobjects'] ?? [] );
			$updatedCount = count( $updated['properties'] ?? [] ) +
				count( $updated['categories'] ?? [] ) +
				count( $updated['subobjects'] ?? [] );

			if ( $createdCount > 0 ) {
				$summary[] = $this->msg( 'semanticschemas-import-created' )
					->numParams( $createdCount )->text();
			}
			if ( $updatedCount > 0 ) {
				$summary[] = $this->msg( 'semanticschemas-import-updated' )
					->numParams( $updatedCount )->text();
			}

			$this->logOperation( 'install-config', 'Base configuration installed', [
				'created' => $created,
				'updated' => $updated,
			] );

			$output->addHTML( Html::successBox(
				$this->msg( 'semanticschemas-install-config-success' )->text() .
				( !empty( $summary ) ? ' ' . implode( ', ', $summary ) : '' )
			) );

			// Show link back to overview
			$output->addHTML( Html::rawElement(
				'p',
				[ 'style' => 'margin-top: 1em;' ],
				Html::element(
					'a',
					[
						'href' => $this->getPageTitle()->getLocalURL(),
						'class' => 'mw-ui-button mw-ui-progressive',
					],
					$this->msg( 'semanticschemas-overview' )->text()
				)
			) );

		} catch ( \Exception $e ) {
			$this->logOperation( 'install-config', 'Install failed: ' . $e->getMessage(), [
				'exception' => get_class( $e ),
			] );

			$output->addHTML( Html::errorBox(
				$this->msg( 'semanticschemas-install-config-failed' )
					->params( $e->getMessage() )
					->text()
			) );
		}
	}

	/**
	 * Handle the "install-properties" action (Step 1 of 2) - legacy handler.
	 * Redirects to the automated installer.
	 */
	private function handleInstallPropertiesAction(): void {
		$this->showAutomatedInstaller();
	}

	/**
	 * Handle the "install-categories" action (Step 2 of 2) - legacy handler.
	 * Redirects to the automated installer.
	 */
	private function handleInstallCategoriesAction(): void {
		$this->showAutomatedInstaller();
	}

	/**
	 * Show the automated layer-by-layer installer with JavaScript progress monitoring.
	 *
	 * This installer creates wiki pages in 4 layers via separate API calls, waiting for
	 * SMW's job queue to complete between each layer. This is necessary because SMW's
	 * property type registry is updated asynchronously - if we create properties and
	 * categories in the same request, category annotations may be stored with incorrect
	 * data types (e.g., text values stored as page references).
	 *
	 * The JavaScript polls the API every 2 seconds to check job queue status, automatically
	 * proceeding to the next layer once all jobs are complete.
	 *
	 * @see ApiSemanticSchemasInstall for layer definitions and detailed explanation
	 */
	private function showAutomatedInstaller(): void {
		$output = $this->getOutput();
		$output->setPageTitle( $this->msg( 'semanticschemas-install-config-title' )->text() );
		$output->addModuleStyles( 'ext.semanticschemas.styles' );

		$configPath = __DIR__ . '/../../resources/extension-config.json';
		$installer = new ExtensionConfigInstaller();

		// Preview what will be installed
		$preview = [];
		if ( file_exists( $configPath ) ) {
			$preview = $installer->previewInstallation( $configPath );
		}

		$wouldCreate = $preview['would_create'] ?? [];
		$tplCount = count( $wouldCreate['templates'] ?? [] );
		$propCount = count( $wouldCreate['properties'] ?? [] );
		$catCount = count( $wouldCreate['categories'] ?? [] );
		$subCount = count( $wouldCreate['subobjects'] ?? [] );

		// Common styles to avoid long lines
		$previewStyle = 'margin: 1em 0; padding: 1em; background: #f8f9fa; border-radius: 4px;';
		$layerStyle = 'padding: 0.5em; margin: 0.5em 0; border-radius: 4px; background: #e9ecef;';
		$infoStyle = 'margin-left: 1em; color: #666;';
		$jobsStyle = 'margin-top: 1em; padding: 0.5em; background: #fff3cd; ' .
			'border-radius: 4px; display: none;';

		// Build the installer UI
		$html = Html::rawElement( 'div', [ 'id' => 'ss-installer' ],
			Html::element( 'h3', [], 'Automated Installation' ) .
			Html::element( 'p', [],
				'This installer will create pages in layers, waiting for SMW to process each layer ' .
				'before proceeding to the next.'
			) .
			Html::rawElement( 'div', [ 'class' => 'ss-install-preview', 'style' => $previewStyle ],
				Html::element( 'strong', [], 'Items to install:' ) .
				Html::rawElement( 'ul', [],
					Html::element( 'li', [], "Templates: $tplCount" ) .
					Html::element( 'li', [], "Properties: $propCount" ) .
					Html::element( 'li', [], "Subobjects: $subCount" ) .
					Html::element( 'li', [], "Categories: $catCount" )
				)
			) .

			// Progress display
			Html::rawElement( 'div', [ 'id' => 'ss-progress', 'style' => 'display: none; margin: 1em 0;' ],
				Html::rawElement( 'div', [ 'id' => 'ss-layer0', 'class' => 'ss-layer', 'style' => $layerStyle ],
					Html::rawElement( 'span', [ 'class' => 'ss-layer-status' ], '○' ) . ' ' .
					Html::element( 'span', [ 'class' => 'ss-layer-name' ], 'Layer 0: Templates' ) .
					Html::element( 'span', [ 'class' => 'ss-layer-info', 'style' => $infoStyle ], '' )
				) .
				Html::rawElement( 'div', [ 'id' => 'ss-layer1', 'class' => 'ss-layer', 'style' => $layerStyle ],
					Html::rawElement( 'span', [ 'class' => 'ss-layer-status' ], '○' ) . ' ' .
					Html::element( 'span', [ 'class' => 'ss-layer-name' ], 'Layer 1: Property Types' ) .
					Html::element( 'span', [ 'class' => 'ss-layer-info', 'style' => $infoStyle ], '' )
				) .
				Html::rawElement( 'div', [ 'id' => 'ss-layer2', 'class' => 'ss-layer', 'style' => $layerStyle ],
					Html::rawElement( 'span', [ 'class' => 'ss-layer-status' ], '○' ) . ' ' .
					Html::element( 'span', [ 'class' => 'ss-layer-name' ], 'Layer 2: Property Annotations' ) .
					Html::element( 'span', [ 'class' => 'ss-layer-info', 'style' => $infoStyle ], '' )
				) .
				Html::rawElement( 'div', [ 'id' => 'ss-layer3', 'class' => 'ss-layer', 'style' => $layerStyle ],
					Html::rawElement( 'span', [ 'class' => 'ss-layer-status' ], '○' ) . ' ' .
					Html::element( 'span', [ 'class' => 'ss-layer-name' ], 'Layer 3: Subobjects' ) .
					Html::element( 'span', [ 'class' => 'ss-layer-info', 'style' => $infoStyle ], '' )
				) .
				Html::rawElement( 'div', [ 'id' => 'ss-layer4', 'class' => 'ss-layer', 'style' => $layerStyle ],
					Html::rawElement( 'span', [ 'class' => 'ss-layer-status' ], '○' ) . ' ' .
					Html::element( 'span', [ 'class' => 'ss-layer-name' ], 'Layer 4: Categories' ) .
					Html::element( 'span', [ 'class' => 'ss-layer-info', 'style' => $infoStyle ], '' )
				) .
				Html::rawElement( 'div', [ 'id' => 'ss-jobs', 'style' => $jobsStyle ],
					Html::element( 'span', [], 'Waiting for SMW jobs: ' ) .
					Html::element( 'span', [ 'id' => 'ss-job-count' ], '0' )
				)
			) .

			// Result display
			Html::rawElement( 'div', [ 'id' => 'ss-result', 'style' => 'display: none; margin: 1em 0;' ] ) .

			// Buttons
			Html::rawElement( 'div', [ 'style' => 'margin-top: 1em;' ],
				Html::element( 'button', [
					'id' => 'ss-start-btn',
					'class' => 'mw-ui-button mw-ui-progressive',
				], 'Start Installation' ) .
				' ' .
				Html::element( 'a', [
					'href' => $this->getPageTitle()->getLocalURL(),
					'class' => 'mw-ui-button',
				], 'Cancel' )
			)
		);

		$output->addHTML( $html );

		// Add the JavaScript
		$token = json_encode( $this->getUser()->getEditToken() );
		$apiUrl = json_encode( wfScript( 'api' ) );

		$js = <<<JAVASCRIPT
(function() {
	var token = $token;
	var apiUrl = $apiUrl;
	var layers = ['layer0', 'layer1', 'layer2', 'layer3', 'layer4'];
	var layerNames = {
		'layer0': 'Templates',
		'layer1': 'Property Types',
		'layer2': 'Property Annotations',
		'layer3': 'Subobjects',
		'layer4': 'Categories'
	};
	var currentLayer = 0;
	var pollInterval = null;

	function updateLayerStatus(layer, status, info) {
		var el = document.getElementById('ss-' + layer);
		if (!el) return;

		var statusEl = el.querySelector('.ss-layer-status');
		var infoEl = el.querySelector('.ss-layer-info');

		if (status === 'pending') {
			el.style.background = '#e9ecef';
			statusEl.textContent = '○';
		} else if (status === 'running') {
			el.style.background = '#fff3cd';
			statusEl.textContent = '◐';
		} else if (status === 'waiting') {
			el.style.background = '#cce5ff';
			statusEl.textContent = '⏳';
		} else if (status === 'done') {
			el.style.background = '#d4edda';
			statusEl.textContent = '✓';
		} else if (status === 'error') {
			el.style.background = '#f8d7da';
			statusEl.textContent = '✗';
		}

		if (info) {
			infoEl.textContent = info;
		}
	}

	function showResult(success, message) {
		var resultEl = document.getElementById('ss-result');
		resultEl.style.display = 'block';
		resultEl.innerHTML = '<div style="padding: 1em; border-radius: 4px; background: ' +
			(success ? '#d4edda' : '#f8d7da') + ';">' + message + '</div>';

		if (success) {
			resultEl.innerHTML += '<p style="margin-top: 1em;"><a href="' +
				mw.config.get('wgScript') + '/Special:SemanticSchemas" ' +
				'class="mw-ui-button mw-ui-progressive">Return to Overview</a></p>';
		}
	}

	function checkJobsAndProceed() {
		fetch(apiUrl + '?action=semanticschemas-install&step=status&format=json')
			.then(function(r) { return r.json(); })
			.then(function(data) {
				var status = data.status;
				var jobCount = status.pendingJobs || 0;

				document.getElementById('ss-job-count').textContent = jobCount;

				if (jobCount === 0) {
					document.getElementById('ss-jobs').style.display = 'none';
					clearInterval(pollInterval);
					pollInterval = null;

					// Mark current layer as done before moving on
					updateLayerStatus(layers[currentLayer], 'done', 'Complete');

					// Move to next layer
					currentLayer++;
					if (currentLayer < layers.length) {
						runLayer(layers[currentLayer]);
					} else {
						showResult(true, '<strong>Installation Complete!</strong>' +
							'<br>All layers have been installed successfully.');
					}
				} else {
					document.getElementById('ss-jobs').style.display = 'block';
					updateLayerStatus(layers[currentLayer], 'waiting',
						'Waiting for ' + jobCount + ' jobs...');
				}
			})
			.catch(function(err) {
				console.error('Status check failed:', err);
			});
	}

	function runLayer(layer) {
		updateLayerStatus(layer, 'running', 'Installing...');

		var formData = new FormData();
		formData.append('action', 'semanticschemas-install');
		formData.append('step', layer);
		formData.append('token', token);
		formData.append('format', 'json');

		fetch(apiUrl, {
			method: 'POST',
			body: formData
		})
		.then(function(r) { return r.json(); })
		.then(function(data) {
			if (data.error) {
				updateLayerStatus(layer, 'error', data.error.info || 'Error');
				showResult(false, '<strong>Installation Failed</strong><br>' + (data.error.info || 'Unknown error'));
				return;
			}

			var install = data.install;
			// Check for errors array instead of success boolean (MW API quirk)
			if (install.errors && install.errors.length > 0) {
				updateLayerStatus(layer, 'error', install.errors.join(', '));
				showResult(false, '<strong>Installation Failed</strong><br>' +
					install.errors.join(', '));
				return;
			}

			var created = 0, updated = 0;
			for (var key in install.created) {
				created += (install.created[key] || []).length;
			}
			for (var key in install.updated) {
				updated += (install.updated[key] || []).length;
			}

			updateLayerStatus(layer, 'done', 'Created: ' + created + ', Updated: ' + updated);

			// Check for pending jobs
			if (install.pendingJobs > 0) {
				document.getElementById('ss-jobs').style.display = 'block';
				document.getElementById('ss-job-count').textContent = install.pendingJobs;
				updateLayerStatus(layer, 'waiting', 'Waiting for ' + install.pendingJobs + ' jobs...');

				// Start polling for job completion
				pollInterval = setInterval(checkJobsAndProceed, 2000);
			} else {
				// Proceed immediately to next layer
				currentLayer++;
				if (currentLayer < layers.length) {
					setTimeout(function() { runLayer(layers[currentLayer]); }, 500);
				} else {
					showResult(true, '<strong>Installation Complete!</strong>' +
						'<br>All layers have been installed successfully.');
				}
			}
		})
		.catch(function(err) {
			updateLayerStatus(layer, 'error', 'Network error');
			showResult(false, '<strong>Installation Failed</strong><br>Network error: ' + err.message);
		});
	}

	document.getElementById('ss-start-btn').addEventListener('click', function() {
		this.disabled = true;
		this.textContent = 'Installing...';
		document.getElementById('ss-progress').style.display = 'block';

		currentLayer = 0;
		runLayer(layers[0]);
	});
})();
JAVASCRIPT;

		$output->addInlineScript( $js );
	}

	/**
	 * Show the install config confirmation form with two-step process.
	 */
	private function showInstallConfigConfirmation(): void {
		$this->showAutomatedInstaller();
	}

	/**
	 * Render the install config warning banner for the overview page.
	 *
	 * @return string HTML
	 */
	private function renderInstallConfigBanner(): string {
		$content = Html::rawElement(
			'div',
			[ 'class' => 'semanticschemas-install-banner' ],
			Html::element(
				'strong',
				[],
				$this->msg( 'semanticschemas-install-config-banner-title' )->text()
			) .
			Html::element(
				'p',
				[],
				$this->msg( 'semanticschemas-install-config-banner-message' )->text()
			) .
			Html::element(
				'a',
				[
					'href' => $this->getPageTitle()->getLocalURL( [ 'action' => 'install-config' ] ),
					'class' => 'mw-ui-button mw-ui-progressive',
				],
				$this->msg( 'semanticschemas-install-config-button' )->text()
			)
		);

		return Html::warningBox( $content );
	}

	/**
	 * Navigation tabs for Special:SemanticSchemas
	 *
	 * @param string $currentAction
	 */
	private function showNavigation( string $currentAction ): void {
		$tabs = [
			'overview' => [
				'label' => $this->msg( 'semanticschemas-overview' )->text(),
				'subtext' => $this->msg( 'semanticschemas-tab-overview-subtext' )->text(),
			],
			'validate' => [
				'label' => $this->msg( 'semanticschemas-validate' )->text(),
				'subtext' => $this->msg( 'semanticschemas-tab-validate-subtext' )->text(),
			],
			'generate' => [
				'label' => $this->msg( 'semanticschemas-generate' )->text(),
				'subtext' => $this->msg( 'semanticschemas-tab-generate-subtext' )->text(),
			],
			'hierarchy' => [
				'label' => $this->msg( 'semanticschemas-hierarchy' )->text(),
				'subtext' => $this->msg( 'semanticschemas-tab-hierarchy-subtext' )->text(),
			],
		];

		$links = '';
		foreach ( $tabs as $action => $data ) {
			$url = $this->getPageTitle( $action )->getLocalURL();
			$isActive = ( $action === $currentAction );
			$links .= Html::rawElement(
				'a',
				[
					'href' => $url,
					'class' => 'semanticschemas-tab' . ( $isActive ? ' is-active' : '' ),
					'aria-current' => $isActive ? 'page' : null,
				],
				Html::rawElement( 'span', [ 'class' => 'semanticschemas-tab-label' ], $data['label'] ) .
				Html::rawElement( 'span', [ 'class' => 'semanticschemas-tab-subtext' ], $data['subtext'] )
			);
		}

		$nav = Html::rawElement(
			'nav',
			[ 'class' => 'semanticschemas-tabs', 'role' => 'tablist' ],
			$links
		);

		$this->getOutput()->addHTML(
			Html::rawElement( 'div', [ 'class' => 'semanticschemas-shell' ], $nav )
		);
	}

	/**
	 * Wrap arbitrary HTML in the standard page shell container.
	 *
	 * @param string $html
	 * @return string
	 */
	private function wrapShell( string $html ): string {
		return Html::rawElement( 'div', [ 'class' => 'semanticschemas-shell' ], $html );
	}

	/**
	 * Render a standard card header with title and optional subtitle.
	 *
	 * @param string $title
	 * @param string $subtitle
	 * @return string
	 */
	private function renderCardHeader( string $title, string $subtitle = '' ): string {
		$content = Html::element( 'h3', [ 'class' => 'semanticschemas-card-title' ], $title );

		if ( $subtitle !== '' ) {
			$content .= Html::element( 'p', [ 'class' => 'semanticschemas-card-subtitle' ], $subtitle );
		}

		return Html::rawElement( 'div', [ 'class' => 'semanticschemas-card-header' ], $content );
	}

	/**
	 * Render a card container with header and body content.
	 *
	 * @param string $title
	 * @param string $subtitle
	 * @param string $bodyHtml
	 * @return string
	 */
	private function renderCard( string $title, string $subtitle, string $bodyHtml ): string {
		return Html::rawElement(
			'div',
			[ 'class' => 'semanticschemas-card' ],
			$this->renderCardHeader( $title, $subtitle ) . $bodyHtml
		);
	}

	/**
	 * Render a simple HTML list from an array of items.
	 *
	 * @param array $items
	 * @return string
	 */
	private function renderList( array $items ): string {
		if ( empty( $items ) ) {
			return '';
		}

		$html = Html::openElement( 'ul' );
		foreach ( $items as $item ) {
			$html .= Html::element( 'li', [], $item );
		}
		$html .= Html::closeElement( 'ul' );

		return $html;
	}

	/**
	 * Render a compact stat card tile.
	 *
	 * @param string $label
	 * @param string $value
	 * @param string $subtext
	 * @return string
	 */
	private function renderStatCard( string $label, string $value, string $subtext = '' ): string {
		$body = Html::rawElement( 'div', [ 'class' => 'semanticschemas-stat-label' ], $label ) .
			Html::rawElement( 'div', [ 'class' => 'semanticschemas-stat-value' ], $value );

		if ( $subtext !== '' ) {
			$body .= Html::rawElement( 'p', [ 'class' => 'semanticschemas-stat-subtext' ], $subtext );
		}

		return Html::rawElement( 'div', [ 'class' => 'semanticschemas-stat-card' ], $body );
	}

	/**
	 * Format timestamps for display using the viewer's language.
	 *
	 * @param string|null $timestamp
	 * @return string
	 */
	private function formatTimestamp( ?string $timestamp ): string {
		if ( $timestamp === null || trim( $timestamp ) === '' ) {
			return $this->msg( 'semanticschemas-label-not-available' )->text();
		}

		$ts = wfTimestamp( TS_MW, $timestamp );
		if ( $ts === false ) {
			return $timestamp;
		}

		$lang = $this->getLanguage();
		return $lang->userDate( $ts, $this->getUser() ) . ' · ' . $lang->userTime( $ts, $this->getUser() );
	}

	/**
	 * Trim schema hashes for smaller display.
	 *
	 * @param string|null $hash
	 * @return string
	 */
	private function formatSchemaHash( ?string $hash ): string {
		if ( $hash === null || $hash === '' ) {
			return $this->msg( 'semanticschemas-label-not-available' )->text();
		}

		return substr( $hash, 0, 10 ) . '…';
	}

	/**
	 * Render a colored pill badge.
	 *
	 * @param bool $state
	 * @param string $labelWhenTrue
	 * @param string $labelWhenFalse
	 * @param string $trueClass
	 * @param string $falseClass
	 * @return string
	 */
	private function renderBadge(
		bool $state,
		string $labelWhenTrue,
		string $labelWhenFalse,
		string $trueClass = 'is-ok',
		string $falseClass = 'is-alert'
	): string {
		return Html::rawElement(
			'span',
			[ 'class' => 'semanticschemas-badge ' . ( $state ? $trueClass : $falseClass ) ],
			$state ? $labelWhenTrue : $labelWhenFalse
		);
	}

	/**
	 * Render a badge that links to a MediaWiki title when provided.
	 *
	 * @param bool $state
	 * @param string $labelWhenTrue
	 * @param string $labelWhenFalse
	 * @param Title|null $title
	 * @param string $trueClass
	 * @param string $falseClass
	 * @return string
	 */
	private function renderBadgeLink(
		bool $state,
		string $labelWhenTrue,
		string $labelWhenFalse,
		?Title $title,
		string $trueClass = 'is-ok',
		string $falseClass = 'is-alert'
	): string {
		$badge = Html::rawElement(
			'span',
			[ 'class' => 'semanticschemas-badge ' . ( $state ? $trueClass : $falseClass ) ],
			$state ? $labelWhenTrue : $labelWhenFalse
		);

		if ( $title === null ) {
			return $badge;
		}

		return Html::rawElement(
			'a',
			[
				'href' => $title->getLocalURL(),
				'class' => 'semanticschemas-inline-link'
			],
			$badge
		);
	}

	/**
	 * Resolve the PageForms namespace ID.
	 *
	 * @return int
	 */
	private function getFormNamespace(): int {
		return defined( 'PF_NS_FORM' ) ? constant( 'PF_NS_FORM' ) : NS_MAIN;
	}

	private function getTemplateTitle( string $categoryName ): ?Title {
		return Title::makeTitleSafe( NS_TEMPLATE, $categoryName );
	}

	private function getFormTitle( string $categoryName ): ?Title {
		return Title::makeTitleSafe( $this->getFormNamespace(), $categoryName );
	}

	private function getDisplayTitle( string $categoryName ): ?Title {
		return Title::makeTitleSafe( NS_TEMPLATE, $categoryName . '/display' );
	}

	/**
	 * Metadata describing quick actions surfaced on the overview page.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function getQuickActions(): array {
		$actions = [
			[
				'action' => 'generate',
				'label' => $this->msg( 'semanticschemas-generate' )->text(),
				'description' => $this->msg( 'semanticschemas-generate-description' )->text(),
			],
			[
				'action' => 'validate',
				'label' => $this->msg( 'semanticschemas-validate' )->text(),
				'description' => $this->msg( 'semanticschemas-validate-description' )->text(),
			],
		];

		// Add install config action if base config is not installed
		$installer = new ExtensionConfigInstaller();
		if ( !$installer->isInstalled() ) {
			// Add at the beginning since it's a prerequisite
			array_unshift( $actions, [
				'action' => 'install-config',
				'href' => $this->getPageTitle()->getLocalURL( [ 'action' => 'install-config' ] ),
				'label' => $this->msg( 'semanticschemas-quick-action-install-config' )->text(),
				'description' => $this->msg( 'semanticschemas-quick-action-install-config-desc' )->text(),
			] );
		}

		return $actions;
	}

	/**
	 * Render the hero section for the overview page.
	 *
	 * @param bool $isDirty Whether the schema is out of sync
	 * @return string HTML
	 */
	private function renderOverviewHero( bool $isDirty ): string {
		$statusMessage = $isDirty
			? $this->msg( 'semanticschemas-status-out-of-sync' )->text()
			: $this->msg( 'semanticschemas-status-in-sync' )->text();

		$generateLink = $isDirty
			? Html::element(
				'a',
				[
					'href' => $this->getPageTitle( 'generate' )->getLocalURL(),
					'class' => 'mw-ui-button mw-ui-progressive'
				],
				$this->msg( 'semanticschemas-status-generate-link' )->text()
			)
			: '';

		$heroContent = Html::rawElement(
			'div',
			[],
			Html::element(
				'h1',
				[],
				$this->msg( 'semanticschemas' )->text() . ' — ' . $this->msg( 'semanticschemas-overview' )->text()
			) .
			Html::element( 'p', [], $this->msg( 'semanticschemas-overview-hero-description' )->text() )
		);

		$heroStatus = Html::rawElement(
			'div',
			[ 'class' => 'semanticschemas-hero-status' ],
			Html::rawElement(
				'span',
				[ 'class' => 'semanticschemas-status-chip ' . ( $isDirty ? 'is-dirty' : 'is-clean' ) ],
				$statusMessage
			) . $generateLink
		);

		return Html::rawElement( 'div', [ 'class' => 'semanticschemas-hero' ], $heroContent . $heroStatus );
	}

	/**
	 * Render the summary statistics grid for the overview page.
	 *
	 * @param array $stats Statistics from OntologyInspector
	 * @param array $state Full state from StateManager
	 * @return string HTML
	 */
	private function renderSummaryGrid( array $stats, array $state ): string {
		$categoryCount = (int)( $stats['categoryCount'] ?? 0 );
		$propertyCount = (int)( $stats['propertyCount'] ?? 0 );
		$subobjectCount = (int)( $stats['subobjectCount'] ?? 0 );
		$lang = $this->getLanguage();

		return Html::rawElement(
			'div',
			[ 'class' => 'semanticschemas-summary-grid' ],
			$this->renderStatCard(
				$this->msg( 'semanticschemas-label-categories' )->text(),
				$lang->formatNum( $categoryCount ),
				$this->msg( 'semanticschemas-categories-count' )->numParams( $categoryCount )->text()
			) .
			$this->renderStatCard(
				$this->msg( 'semanticschemas-label-properties' )->text(),
				$lang->formatNum( $propertyCount ),
				$this->msg( 'semanticschemas-properties-count' )->numParams( $propertyCount )->text()
			) .
			$this->renderStatCard(
				$this->msg( 'semanticschemas-label-subobjects' )->text(),
				$lang->formatNum( $subobjectCount ),
				$this->msg( 'semanticschemas-subobjects-count' )->numParams( $subobjectCount )->text()
			) .
			$this->renderStatCard(
				$this->msg( 'semanticschemas-label-last-change' )->text(),
				$this->formatTimestamp( $state['lastChangeTimestamp'] ?? null )
			) .
			$this->renderStatCard(
				$this->msg( 'semanticschemas-label-last-generation' )->text(),
				$this->formatTimestamp( $state['generated'] ?? null )
			) .
			$this->renderStatCard(
				$this->msg( 'semanticschemas-label-schema-hash' )->text(),
				$this->formatSchemaHash( $state['sourceSchemaHash'] ?? null )
			)
		);
	}

	/**
	 * Render the quick actions section for the overview page.
	 *
	 * @return string HTML
	 */
	private function renderQuickActionsHtml(): string {
		$actionsHtml = '';
		foreach ( $this->getQuickActions() as $action ) {
			// Use custom href if provided, otherwise generate from action subpage
			$href = $action['href'] ?? $this->getPageTitle( $action['action'] )->getLocalURL();
			$actionsHtml .= Html::rawElement(
				'a',
				[
					'href' => $href,
					'class' => 'semanticschemas-quick-action'
				],
				Html::element( 'strong', [], $action['label'] ) .
				Html::rawElement( 'span', [], $action['description'] )
			);
		}

		return Html::rawElement( 'div', [ 'class' => 'semanticschemas-quick-actions' ], $actionsHtml );
	}

	/**
	 * Overview page: summarises current schema + category/template/form status.
	 */
	private function showOverview(): void {
		$output = $this->getOutput();
		$output->setPageTitle( $this->msg( 'semanticschemas-overview' )->text() );

		// Check if base config is installed and show banner if not
		$installer = new ExtensionConfigInstaller();
		$installBanner = '';
		if ( !$installer->isInstalled() ) {
			$installBanner = $this->renderInstallConfigBanner();
		}

		$inspector = new OntologyInspector();
		$stats = $inspector->getStatistics();
		$stateManager = new StateManager();
		$state = $stateManager->getFullState();
		$isDirty = $stateManager->isDirty();

		$hero = $this->renderOverviewHero( $isDirty );
		$summaryGrid = $this->renderSummaryGrid( $stats, $state );

		$quickActionsCard = $this->renderCard(
			$this->msg( 'semanticschemas-overview-quick-actions' )->text(),
			$this->msg( 'semanticschemas-overview-quick-actions-subtitle' )->text(),
			$this->renderQuickActionsHtml()
		);

		$categoryCard = $this->renderCard(
			$this->msg( 'semanticschemas-overview-summary' )->text(),
			$this->msg( 'semanticschemas-overview-categories-subtitle' )->text(),
			Html::rawElement( 'div', [ 'class' => 'semanticschemas-table-wrapper' ], $this->getCategoryStatusTable() )
		);

		$content = $installBanner . $hero . $summaryGrid . $quickActionsCard . $categoryCard;
		$output->addHTML( $this->wrapShell( $content ) );
	}

	/**
	 * Detect pages that have been modified outside the schema system.
	 *
	 * Compares current model hashes against stored hashes to identify
	 * categories and properties that have been externally edited.
	 *
	 * @param array $categories Array of CategoryModel objects
	 * @return array<string, bool> Map of page names to modification status
	 */
	private function detectModifiedPages( array $categories ): array {
		$propertyStore = new WikiPropertyStore();
		$stateManager = new StateManager();
		$hashComputer = new PageHashComputer();

		$storedHashes = $stateManager->getPageHashes();
		$modifiedPages = [];

		foreach ( $categories as $category ) {
			$categoryName = $category->getName();
			$pageName = "Category:$categoryName";

			$currentHash = $hashComputer->computeCategoryModelHash( $category );
			$storedHash = $storedHashes[$pageName]['generated'] ?? '';
			if ( $storedHash !== '' && $currentHash !== $storedHash ) {
				$modifiedPages[$pageName] = true;
			}

			foreach ( $category->getAllProperties() as $propertyName ) {
				$propPageName = "Property:$propertyName";
				if ( isset( $modifiedPages[$propPageName] ) ) {
					continue;
				}

				$propModel = $propertyStore->readProperty( $propertyName );
				if ( $propModel === null ) {
					continue;
				}

				$currentHash = $hashComputer->computePropertyModelHash( $propModel );
				$storedHash = $storedHashes[$propPageName]['generated'] ?? '';
				if ( $storedHash !== '' && $currentHash !== $storedHash ) {
					$modifiedPages[$propPageName] = true;
				}
			}
		}

		return $modifiedPages;
	}

	/**
	 * Check if a category or any of its properties have been modified externally.
	 *
	 * @param CategoryModel $category Category model object
	 * @param array<string, bool> $modifiedPages Map of modified page names
	 * @return bool
	 */
	private function isCategoryModified( CategoryModel $category, array $modifiedPages ): bool {
		$name = $category->getName();

		if ( isset( $modifiedPages["Category:$name"] ) ) {
			return true;
		}

		foreach ( $category->getAllProperties() as $propName ) {
			if ( isset( $modifiedPages["Property:$propName"] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Render an availability badge cell for the category table.
	 *
	 * @param bool $exists Whether the artifact exists
	 * @param Title|null $title Link target
	 * @return string HTML table cell content
	 */
	private function renderAvailabilityBadge( bool $exists, ?Title $title ): string {
		return $this->renderBadgeLink(
			$exists,
			$this->msg( 'semanticschemas-badge-available' )->text(),
			$this->msg( 'semanticschemas-badge-missing' )->text(),
			$title
		);
	}

	/**
	 * Status table: each category + whether templates/forms/display exist.
	 *
	 * @return string HTML
	 */
	private function getCategoryStatusTable(): string {
		$categoryStore = new WikiCategoryStore();
		$templateGenerator = new TemplateGenerator();
		$formGenerator = new FormGenerator();
		$displayGenerator = new DisplayStubGenerator();

		$categories = $categoryStore->getAllCategories();

		if ( empty( $categories ) ) {
			return Html::rawElement(
				'div',
				[ 'class' => 'semanticschemas-empty-state' ],
				$this->msg( 'semanticschemas-overview-no-categories' )->text()
			);
		}

		$modifiedPages = $this->detectModifiedPages( $categories );

		$html = Html::openElement( 'table', [ 'class' => 'wikitable sortable semanticschemas-table' ] );

		$html .= Html::openElement( 'thead' );
		$html .= Html::openElement( 'tr' );
		$html .= Html::element( 'th', [], 'Category' );
		$html .= Html::element( 'th', [], 'Parents' );
		$html .= Html::element( 'th', [], 'Properties' );
		$html .= Html::element( 'th', [], 'Template' );
		$html .= Html::element( 'th', [], 'Form' );
		$html .= Html::element( 'th', [], 'Display' );
		$html .= Html::element( 'th', [], $this->msg( 'semanticschemas-status-modified-outside' )->text() );
		$html .= Html::closeElement( 'tr' );
		$html .= Html::closeElement( 'thead' );

		$html .= Html::openElement( 'tbody' );
		foreach ( $categories as $category ) {
			$name = $category->getName();
			$isModified = $this->isCategoryModified( $category, $modifiedPages );

			$html .= Html::openElement( 'tr' );
			$html .= Html::element( 'td', [], $name );
			$html .= Html::element( 'td', [], (string)count( $category->getParents() ) );
			$html .= Html::element( 'td', [], (string)count( $category->getAllProperties() ) );
			$html .= Html::rawElement(
				'td',
				[],
				$this->renderAvailabilityBadge(
					$templateGenerator->semanticTemplateExists( $name ),
					$this->getTemplateTitle( $name )
				)
			);
			$html .= Html::rawElement(
				'td',
				[],
				$this->renderAvailabilityBadge(
					$formGenerator->formExists( $name ),
					$this->getFormTitle( $name )
				)
			);
			$html .= Html::rawElement(
				'td',
				[],
				$this->renderAvailabilityBadge(
					$displayGenerator->displayStubExists( $name ),
					$this->getDisplayTitle( $name )
				)
			);
			$html .= Html::rawElement(
				'td',
				[],
				$this->renderBadge(
					!$isModified,
					$this->msg( 'semanticschemas-badge-clean' )->text(),
					$this->msg( 'semanticschemas-badge-review' )->text(),
					'is-ok',
					'is-alert'
				)
			);
			$html .= Html::closeElement( 'tr' );
		}
		$html .= Html::closeElement( 'tbody' );
		$html .= Html::closeElement( 'table' );

		return $html;
	}

	/**
	 * Validate wiki state against expected invariants.
	 */
	private function showValidate(): void {
		$output = $this->getOutput();
		$output->setPageTitle( $this->msg( 'semanticschemas-validate-title' )->text() );

		$inspector = new OntologyInspector();
		$result = $inspector->validateWikiState();

		$body = $this->renderCardHeader(
			$this->msg( 'semanticschemas-validate-title' )->text(),
			$this->msg( 'semanticschemas-validate-description' )->text()
		);

		if ( empty( $result['errors'] ) ) {
			$body .= Html::successBox( $this->msg( 'semanticschemas-validate-success' )->text() );
		} else {
			$body .= Html::element( 'h3', [], $this->msg( 'semanticschemas-validate-errors' )->text() );
			$body .= $this->renderList( $result['errors'] );
		}

		if ( !empty( $result['warnings'] ) ) {
			$body .= Html::element( 'h3', [], $this->msg( 'semanticschemas-validate-warnings' )->text() );
			$body .= $this->renderList( $result['warnings'] );
		}

		$modifiedPages = $result['modifiedPages'] ?? [];
		if ( !empty( $modifiedPages ) ) {
			$body .= Html::element(
				'h3',
				[],
				$this->msg( 'semanticschemas-validate-modified-pages' )
					->numParams( count( $modifiedPages ) )
					->text()
			);
			$body .= $this->renderList( $modifiedPages );
		}

		$output->addHTML(
			$this->wrapShell(
				Html::rawElement( 'div', [ 'class' => 'semanticschemas-card' ], $body )
			)
		);
	}

	/**
	 * Show the "Generate" page for regenerating templates/forms/display.
	 */
	private function showGenerate(): void {
		$output = $this->getOutput();
		$request = $this->getRequest();

		$output->setPageTitle( $this->msg( 'semanticschemas-generate-title' )->text() );

		if ( $request->wasPosted() && $request->getVal( 'action' ) === 'generate' ) {
			if ( !$this->getUser()->matchEditToken( $request->getVal( 'token' ) ) ) {
				$output->addHTML( Html::errorBox( 'Invalid edit token' ) );
				return;
			}
			$this->processGenerate();
			return;
		}

		$categoryStore = new WikiCategoryStore();
		$categories = $categoryStore->getAllCategories();

		$form = Html::openElement( 'form', [
			'method' => 'post',
			'action' => $this->getPageTitle( 'generate' )->getLocalURL()
		] );

		$form .= Html::openElement( 'div', [ 'class' => 'semanticschemas-form-group' ] );
		$form .= Html::element(
			'label',
			[],
			$this->msg( 'semanticschemas-generate-category' )->text()
		);
		$form .= Html::openElement( 'select', [ 'name' => 'category' ] );
		$form .= Html::element(
			'option',
			[ 'value' => '' ],
			$this->msg( 'semanticschemas-generate-all' )->text()
		);
		foreach ( $categories as $category ) {
			$name = $category->getName();
			$form .= Html::element(
				'option',
				[ 'value' => $name ],
				$name
			);
		}
		$form .= Html::closeElement( 'select' );
		$form .= Html::closeElement( 'div' );

		$form .= Html::openElement( 'div', [ 'class' => 'semanticschemas-form-group' ] );
		$form .= Html::element( 'input', [
			'type' => 'checkbox',
			'name' => 'generate-display',
			'value' => '1',
			'id' => 'generate-display-check'
		] );
		$form .= Html::element(
			'label',
			[ 'for' => 'generate-display-check', 'style' => 'display:inline; margin-left: 0.5em;' ],
			"Force update display templates (e.g. Template:Category/display)"
		);
		$form .= Html::element(
			'p',
			[
				'class' => 'semanticschemas-form-help',
				'style' => 'margin-top: 0.25em; color: #72777d; font-size: 0.85em;'
			],
			"Warning: This replaces any manual customizations to the display structure."
		);
		$form .= Html::closeElement( 'div' );

		$form .= Html::hidden( 'action', 'generate' );
		$form .= Html::hidden( 'token', $this->getUser()->getEditToken() );

		$form .= Html::submitButton(
			$this->msg( 'semanticschemas-generate-button' )->text(),
			[ 'class' => 'mw-ui-button mw-ui-progressive' ]
		);

		$form .= Html::closeElement( 'form' );

		$helpItems = [
			$this->msg( 'semanticschemas-generate-description' )->text(),
			$this->msg( 'semanticschemas-generate-tip' )->text(),
			$this->msg( 'semanticschemas-status-modified-outside' )->text(),
		];
		$helper = Html::rawElement(
			'div',
			[ 'class' => 'semanticschemas-help-card' ],
			Html::element( 'strong', [], $this->msg( 'semanticschemas-generate-title' )->text() ) .
			$this->renderList( $helpItems )
		);

		$formGrid = Html::rawElement(
			'div',
			[ 'class' => 'semanticschemas-form-grid' ],
			Html::rawElement( 'div', [], $form ) .
			Html::rawElement( 'div', [], $helper )
		);

		$card = $this->renderCard(
			$this->msg( 'semanticschemas-generate-title' )->text(),
			$this->msg( 'semanticschemas-generate-description' )->text(),
			$formGrid
		);

		$output->addHTML( $this->wrapShell( $card ) );
	}

	/**
	 * Compute hashes for all schema entities (categories, properties, subobjects).
	 *
	 * @return array<string, string> Map of page names to their computed hashes
	 */
	private function computeAllSchemaHashes(): array {
		$categoryStore = new WikiCategoryStore();
		$propertyStore = new WikiPropertyStore();
		$subobjectStore = new WikiSubobjectStore();
		$hashComputer = new PageHashComputer();

		$pageHashes = [];

		foreach ( $categoryStore->getAllCategories() as $category ) {
			$name = $category->getName();
			$pageHashes["Category:$name"] = $hashComputer->computeCategoryModelHash( $category );
		}

		foreach ( $propertyStore->getAllProperties() as $property ) {
			$name = $property->getName();
			$pageHashes["Property:$name"] = $hashComputer->computePropertyModelHash( $property );
		}

		foreach ( $subobjectStore->getAllSubobjects() as $subobject ) {
			$name = $subobject->getName();
			$pageHashes["Subobject:$name"] = $hashComputer->computeSubobjectModelHash( $subobject );
		}

		return $pageHashes;
	}

	/**
	 * Build a complete category map for inheritance resolution.
	 *
	 * Ensures all categories are included so parent relationships resolve correctly.
	 *
	 * @param WikiCategoryStore $categoryStore
	 * @return array<string, object> Map of category names to CategoryModel objects
	 */
	private function buildCategoryMap( WikiCategoryStore $categoryStore ): array {
		$categoryMap = [];
		foreach ( $categoryStore->getAllCategories() as $cat ) {
			$categoryMap[$cat->getName()] = $cat;
		}
		return $categoryMap;
	}

	/**
	 * Close the progress container HTML elements.
	 */
	private function closeProgressContainer(): void {
		$this->getOutput()->addHTML(
			Html::closeElement( 'div' ) .
			Html::closeElement( 'div' )
		);
	}

	/**
	 * Process "Generate" POST:
	 *   - Build inheritance graph from current categories.
	 *   - For each category: compute effective category + ancestor chain.
	 *   - Invoke TemplateGenerator, FormGenerator (with ancestors), DisplayStubGenerator.
	 */
	private function processGenerate(): void {
		$output = $this->getOutput();
		$request = $this->getRequest();

		if ( $this->checkRateLimit( 'generate' ) ) {
			$output->addHTML( Html::errorBox(
				$this->msg( 'semanticschemas-ratelimit-exceeded' )
					->params( $this->getRateLimitPerHour() )
					->text()
			) );
			return;
		}

		$categoryName = trim( $request->getText( 'category' ) );
		$categoryStore = new WikiCategoryStore();
		$templateGenerator = new TemplateGenerator();
		$formGenerator = new FormGenerator();
		$displayGenerator = new DisplayStubGenerator();

		try {
			$categories = $this->getTargetCategories( $categoryStore, $categoryName );

			if ( empty( $categories ) ) {
				$output->addHTML( Html::errorBox(
					$this->msg( 'semanticschemas-generate-no-categories' )->text()
				) );
				return;
			}

			$progressContainerOpen = true;
			$output->addHTML(
				Html::openElement( 'div', [ 'class' => 'semanticschemas-progress' ] ) .
				Html::element( 'p', [], $this->msg( 'semanticschemas-generate-inprogress' )->text() ) .
				Html::openElement( 'div', [ 'class' => 'semanticschemas-progress-log' ] )
			);

			$categoryMap = $this->buildCategoryMap( $categoryStore );
			$resolver = new InheritanceResolver( $categoryMap );
			$generateDisplay = $request->getBool( 'generate-display' );

			$successCount = 0;
			$totalCount = count( $categories );

			foreach ( $categories as $category ) {
				$name = $category->getName();

				$output->addHTML(
					Html::element(
						'div',
						[ 'class' => 'semanticschemas-progress-item' ],
						$this->msg( 'semanticschemas-generate-processing' )->params( $name )->text()
					)
				);

				try {
					$effective = $resolver->getEffectiveCategory( $name );
					$ancestors = $resolver->getAncestors( $name );

					$templateGenerator->generateAllTemplates( $effective );
					$formGenerator->generateAndSaveForm( $effective, $ancestors );

					if ( $generateDisplay ) {
						$displayGenerator->generateOrUpdateDisplayStub( $effective );
					}

					$successCount++;
				} catch ( \Exception $e ) {
					wfLogWarning( "SemanticSchemas generate failed for category '$name': " . $e->getMessage() );
					$output->addHTML(
						Html::element(
							'div',
							[ 'class' => 'semanticschemas-progress-item semanticschemas-error' ],
							$this->msg( 'semanticschemas-generate-category-error' )
								->params( $name, $e->getMessage() )
								->text()
						)
					);
				}
			}

			$pageHashes = $this->computeAllSchemaHashes();

			if ( !empty( $pageHashes ) ) {
				$stateManager = new StateManager();
				$stateManager->setPageHashes( $pageHashes );
				$stateManager->clearDirty();
			}

			if ( $progressContainerOpen ) {
				$this->closeProgressContainer();
				$progressContainerOpen = false;
			}

			$this->logOperation( 'generate', 'Template/form generation completed', [
				'categoryFilter' => $categoryName ?: 'all',
				'categoriesProcessed' => $successCount,
				'categoriesTotal' => $totalCount,
				'pagesHashed' => count( $pageHashes ),
			] );

			$output->addHTML(
				Html::successBox(
					$this->msg( 'semanticschemas-generate-success' )
						->numParams( $successCount, $totalCount )
						->text()
				)
			);
		} catch ( \Exception $e ) {
			if ( $progressContainerOpen ) {
				$this->closeProgressContainer();
			}

			$this->logOperation( 'generate', 'Generation exception: ' . $e->getMessage(), [
				'exception' => get_class( $e ),
				'categoryFilter' => $categoryName ?? '',
			] );

			$output->addHTML( Html::errorBox(
				$this->msg( 'semanticschemas-generate-error' )->params( $e->getMessage() )->text()
			) );
		}
	}

	/**
	 * Get target categories for generation based on filter.
	 *
	 * @param WikiCategoryStore $categoryStore
	 * @param string $categoryName Filter by name, or empty for all
	 * @return array Array of CategoryModel objects
	 */
	private function getTargetCategories( WikiCategoryStore $categoryStore, string $categoryName ): array {
		if ( $categoryName === '' ) {
			return $categoryStore->getAllCategories();
		}

		$single = $categoryStore->readCategory( $categoryName );
		return $single ? [ $single ] : [];
	}

	/**
	 * Render the hierarchy category selection form.
	 *
	 * @param string $categoryValue Current category value from request
	 * @return string HTML form
	 */
	private function renderHierarchyForm( string $categoryValue ): string {
		$form = Html::openElement( 'form', [
			'method' => 'get',
			'class' => 'ss-hierarchy-special-form',
		] );

		$form .= Html::element( 'input', [
			'type' => 'hidden',
			'name' => 'title',
			'value' => $this->getPageTitle( 'hierarchy' )->getPrefixedText(),
		] );

		$form .= Html::element( 'label', [
			'for' => 'ss-hierarchy-category-input',
		], $this->msg( 'semanticschemas-hierarchy-category-label' )->text() );

		$form .= Html::element( 'input', [
			'type' => 'text',
			'id' => 'ss-hierarchy-category-input',
			'name' => 'category',
			'value' => $categoryValue,
			'placeholder' => 'e.g., PhDStudent',
		] );

		$form .= Html::element( 'button', [
			'type' => 'submit',
			'class' => 'mw-ui-button mw-ui-progressive',
		], $this->msg( 'semanticschemas-hierarchy-show-button' )->text() );

		$form .= Html::closeElement( 'form' );

		return $form;
	}

	/**
	 * Show hierarchy visualization tab.
	 *
	 * Provides a simple form to select a category and displays:
	 * - Inheritance tree (parents, grandparents, etc.)
	 * - Inherited properties with source category and required/optional status
	 */
	private function showHierarchy(): void {
		$output = $this->getOutput();
		$output->setPageTitle( $this->msg( 'semanticschemas-hierarchy-title' )->text() );
		$output->addModules( 'ext.semanticschemas.hierarchy' );

		$categoryValue = $this->getRequest()->getText( 'category', '' );
		$form = $this->renderHierarchyForm( $categoryValue );

		$containerAttrs = [
			'id' => 'ss-hierarchy-container',
			'class' => 'ss-hierarchy-block',
		];
		if ( $categoryValue !== '' ) {
			$containerAttrs['data-category'] = $categoryValue;
		}
		$container = Html::rawElement( 'div', $containerAttrs, '' );

		$card = $this->renderCard(
			$this->msg( 'semanticschemas-hierarchy-title' )->text(),
			$this->msg( 'semanticschemas-hierarchy-tree-title' )->text(),
			$form . $container
		);

		$output->addHTML( $this->wrapShell( $card ) );
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
