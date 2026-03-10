<?php

namespace MediaWiki\Extension\SemanticSchemas\Api;

use ApiBase;
use MediaWiki\Extension\SemanticSchemas\Schema\ExtensionConfigInstaller;
use MediaWiki\Extension\SemanticSchemas\Schema\SchemaLoader;

/**
 * ApiSemanticSchemasInstall
 * -------------------------
 * Handles installation of extension configuration in a single request.
 *
 * The installer uses DeferredUpdates::doUpdates() to force SMW's property type
 * registration to complete synchronously, so all entities can be created in one
 * request without needing job queue polling between layers.
 *
 * Endpoints:
 *   api.php?action=semanticschemas-install&step=status   - Get install status
 *   api.php?action=semanticschemas-install&step=install  - Install all entities
 *
 * Security:
 *   - Requires 'edit' permission
 *   - Write operations require POST with CSRF token
 */
class ApiSemanticSchemasInstall extends ApiBase {

	private ExtensionConfigInstaller $installer;
	private SchemaLoader $loader;

	/**
	 * @param \ApiMain $mainModule
	 * @param string $moduleName
	 * @param ExtensionConfigInstaller $installer
	 * @param SchemaLoader $loader
	 */
	public function __construct(
		$mainModule,
		$moduleName,
		ExtensionConfigInstaller $installer,
		SchemaLoader $loader
	) {
		parent::__construct( $mainModule, $moduleName );
		$this->installer = $installer;
		$this->loader = $loader;
	}

	/**
	 * Execute the API request.
	 */
	public function execute() {
		$this->checkUserRightsAny( 'edit' );

		$params = $this->extractRequestParams();
		$step = $params['step'];

		$configPath = __DIR__ . '/../../resources/extension-config.json';

		switch ( $step ) {
			case 'status':
				$this->executeStatus( $configPath );
				break;

			case 'install':
				$this->executeInstall( $configPath );
				break;

			default:
				$this->dieWithError( 'Invalid step parameter' );
		}
	}

	/**
	 * Get current installation status.
	 */
	private function executeStatus( string $configPath ): void {
		$schema = $this->loader->loadFromFile( $configPath );
		$status = $this->installer->getInstallationStatus( $schema );

		$this->getResult()->addValue( null, 'status', $status );
	}

	/**
	 * Execute full installation in a single request.
	 */
	private function executeInstall( string $configPath ): void {
		if ( !$this->getRequest()->wasPosted() ) {
			$this->dieWithError( [ 'apierror-mustbeposted', $this->getModuleName() ] );
		}

		$token = $this->getParameter( 'token' );
		if ( !$this->getUser()->matchEditToken( $token ) ) {
			$this->dieWithError( 'apierror-badtoken' );
		}

		if ( !file_exists( $configPath ) ) {
			$this->dieWithError( 'Configuration file not found' );
		}

		$result = $this->installer->applyFromFile( $configPath );

		$success = empty( $result['errors'] ) ? 'true' : 'false';

		$this->getResult()->addValue( null, 'install', [
			'success' => $success,
			'errors' => $result['errors'] ?? [],
			'created' => $result['created'] ?? [],
			'updated' => $result['updated'] ?? [],
			'failed' => $result['failed'] ?? [],
		] );
	}

	/**
	 * @inheritDoc
	 */
	public function getAllowedParams() {
		return [
			'step' => [
				ApiBase::PARAM_TYPE => [ 'status', 'install' ],
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
			'action=semanticschemas-install&step=install&token=TOKEN'
				=> 'apihelp-semanticschemas-install-example-install',
		];
	}
}
