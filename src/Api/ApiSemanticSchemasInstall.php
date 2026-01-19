<?php

namespace MediaWiki\Extension\SemanticSchemas\Api;

use ApiBase;
use MediaWiki\Extension\SemanticSchemas\Schema\ExtensionConfigInstaller;
use MediaWiki\Extension\SemanticSchemas\Schema\SchemaLoader;

/**
 * ApiSemanticSchemasInstall
 * -------------------------
 * Handles layer-by-layer installation of extension configuration.
 *
 * ## Why Layer-by-Layer Installation?
 *
 * SMW (Semantic MediaWiki) maintains an internal property type registry that is updated
 * asynchronously via the job queue. When properties and categories are created in the
 * same HTTP request, SMW may not recognize the property types when parsing category pages.
 *
 * For example, if we create `Property:Has target namespace` with `[[Has type::Text]]` and
 * then immediately create `Category:Category` with `[[Has target namespace::Category]]`,
 * SMW may store the value as a DIWikiPage (page reference) instead of DIBlob (text string)
 * because it hasn't yet registered "Has target namespace" as a Text-type property.
 *
 * The solution is to install in layers with separate HTTP requests, allowing SMW's job
 * queue to process each layer before proceeding:
 *
 *   Layer 0: Templates - property display templates (no SMW dependencies)
 *   Layer 1: Property types only (just [[Has type::...]]) - establishes type registry
 *   Layer 2: Full property annotations - adds labels, descriptions, constraints
 *   Layer 3: Subobjects - can now reference properly-typed properties
 *   Layer 4: Categories - semantic annotations are now stored with correct types
 *
 * The JavaScript UI polls for job queue completion between layers to ensure SMW has
 * fully processed each layer before proceeding to the next.
 *
 * Endpoints:
 *   api.php?action=semanticschemas-install&step=status    - Get job count and install status
 *   api.php?action=semanticschemas-install&step=layer0    - Install property display templates
 *   api.php?action=semanticschemas-install&step=layer1    - Install property types only
 *   api.php?action=semanticschemas-install&step=layer2    - Install properties with full annotations
 *   api.php?action=semanticschemas-install&step=layer3    - Install subobjects
 *   api.php?action=semanticschemas-install&step=layer4    - Install categories
 *
 * Security:
 *   - Requires 'edit' permission
 *   - Write operations require POST with CSRF token
 */
class ApiSemanticSchemasInstall extends ApiBase {

	/**
	 * Execute the API request.
	 */
	public function execute() {
		$this->checkUserRightsAny( 'edit' );

		$params = $this->extractRequestParams();
		$step = $params['step'];

		$installer = new ExtensionConfigInstaller();
		$configPath = __DIR__ . '/../../resources/extension-config.json';

		switch ( $step ) {
			case 'status':
				$this->executeStatus( $installer, $configPath );
				break;

			case 'layer0':
			case 'layer1':
			case 'layer2':
			case 'layer3':
			case 'layer4':
				$this->executeLayer( $step, $installer, $configPath );
				break;

			default:
				$this->dieWithError( 'Invalid step parameter' );
		}
	}

	/**
	 * Get current installation status.
	 */
	private function executeStatus( ExtensionConfigInstaller $installer, string $configPath ): void {
		$jobCount = $installer->getPendingJobCount();
		$templatesInstalled = $installer->areTemplatesInstalled( $configPath );
		$propertiesInstalled = $installer->arePropertiesInstalled( $configPath );
		$subobjectsInstalled = $installer->areSubobjectsInstalled( $configPath );
		$categoriesInstalled = $installer->areCategoriesInstalled( $configPath );

		$this->getResult()->addValue( null, 'status', [
			'pendingJobs' => $jobCount,
			'templatesInstalled' => $templatesInstalled,
			'propertiesInstalled' => $propertiesInstalled,
			'subobjectsInstalled' => $subobjectsInstalled,
			'categoriesInstalled' => $categoriesInstalled,
			'ready' => $jobCount === 0,
		] );
	}

	/**
	 * Execute a specific installation layer.
	 */
	private function executeLayer(
		string $step,
		ExtensionConfigInstaller $installer,
		string $configPath
	): void {
		// Require POST for write operations
		if ( !$this->getRequest()->wasPosted() ) {
			$this->dieWithError( 'This action requires POST' );
		}

		// Check CSRF token
		$token = $this->getParameter( 'token' );
		if ( !$this->getUser()->matchEditToken( $token ) ) {
			$this->dieWithError( 'Invalid CSRF token' );
		}

		if ( !file_exists( $configPath ) ) {
			$this->dieWithError( 'Configuration file not found' );
		}

		$loader = new SchemaLoader();
		$schema = $loader->loadFromFile( $configPath );

		$result = [];
		$layerName = '';

		switch ( $step ) {
			case 'layer0':
				$layerName = 'Templates';
				$result = $installer->applyTemplatesOnly( $schema );
				break;

			case 'layer1':
				$layerName = 'Property Types';
				$result = $installer->applyPropertiesTypeOnly( $schema );
				break;

			case 'layer2':
				$layerName = 'Property Annotations';
				$result = $installer->applyPropertiesFull( $schema );
				break;

			case 'layer3':
				$layerName = 'Subobjects';
				$result = $installer->applySubobjectsOnly( $schema );
				break;

			case 'layer4':
				$layerName = 'Categories';
				$result = $installer->applyCategoriesOnly( $schema );
				break;
		}

		// Use string "true"/"false" to avoid MediaWiki API boolean quirks
		$success = empty( $result['errors'] ) ? 'true' : 'false';

		$this->getResult()->addValue( null, 'install', [
			'success' => $success,
			'layer' => $step,
			'layerName' => $layerName,
			'errors' => $result['errors'] ?? [],
			'created' => $result['created'] ?? [],
			'updated' => $result['updated'] ?? [],
			'failed' => $result['failed'] ?? [],
			'pendingJobs' => $installer->getPendingJobCount(),
		] );
	}

	/**
	 * @inheritDoc
	 */
	public function getAllowedParams() {
		return [
			'step' => [
				ApiBase::PARAM_TYPE => [ 'status', 'layer0', 'layer1', 'layer2', 'layer3', 'layer4' ],
				ApiBase::PARAM_REQUIRED => true,
			],
			'token' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => false,
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function needsToken() {
		return false; // We handle token validation manually for POST requests
	}

	/**
	 * @inheritDoc
	 */
	public function isWriteMode() {
		$step = $this->getParameter( 'step' );
		return $step !== 'status';
	}

	/**
	 * @inheritDoc
	 */
	protected function getExamplesMessages() {
		return [
			'action=semanticschemas-install&step=status'
				=> 'apihelp-semanticschemas-install-example-status',
			'action=semanticschemas-install&step=layer1&token=TOKEN'
				=> 'apihelp-semanticschemas-install-example-layer1',
		];
	}
}
