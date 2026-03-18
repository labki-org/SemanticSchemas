<?php

namespace MediaWiki\Extension\SemanticSchemas\Maintenance;

use Maintenance;
use MediaWiki\Extension\SemanticSchemas\Schema\ExtensionConfigInstaller;
use MediaWiki\Extension\SemanticSchemas\SemanticSchemasServices;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = '/var/www/html';
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Maintenance script to install or update the SemanticSchemas base configuration.
 *
 * Reads pre-compiled .wikitext files from resources/base-config/ and writes them
 * as wiki pages via PageCreator.
 */
class InstallConfig extends Maintenance {

	private const NAMESPACE_LABELS = [
		'templates'  => 'Template',
		'properties' => 'Property',
		'subobjects' => 'Subobject',
		'categories' => 'Category',
	];

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Install SemanticSchemas base configuration (categories, properties, templates)' );
		$this->addOption( 'dry-run', 'Preview changes without writing to the wiki' );
		$this->requireExtension( 'SemanticSchemas' );
	}

	public function execute() {
		$installer = SemanticSchemasServices::getExtensionConfigInstaller(
			$this->getServiceContainer()
		);

		if ( $this->hasOption( 'dry-run' ) ) {
			$this->executeDryRun( $installer );
		} else {
			$this->executeInstall( $installer );
		}
	}

	private function executeDryRun( ExtensionConfigInstaller $installer ): void {
		$this->output( "DRY RUN - No changes will be made\n" );
		$this->output( str_repeat( '=', 50 ) . "\n\n" );

		$result = $installer->preview();

		$totalCreate = 0;
		$totalUpdate = 0;

		foreach ( self::NAMESPACE_LABELS as $key => $prefix ) {
			$create = $result['would_create'][$key] ?? [];
			$update = $result['would_update'][$key] ?? [];
			$totalCreate += count( $create );
			$totalUpdate += count( $update );

			foreach ( $create as $name ) {
				$this->output( "  [CREATE] $prefix:$name\n" );
			}
			foreach ( $update as $name ) {
				$this->output( "  [UPDATE] $prefix:$name\n" );
			}
		}

		$this->output( "\n" . str_repeat( '-', 50 ) . "\n" );
		$this->output( "Summary: $totalCreate would be created, $totalUpdate would be updated\n" );
	}

	private function executeInstall( ExtensionConfigInstaller $installer ): void {
		$this->output( "Installing SemanticSchemas base configuration...\n" );
		$this->output( str_repeat( '=', 50 ) . "\n\n" );

		$result = $installer->install();

		$totalCreated = 0;
		$totalUpdated = 0;
		$totalFailed = 0;

		foreach ( self::NAMESPACE_LABELS as $key => $prefix ) {
			$created = $result['created'][$key] ?? [];
			$updated = $result['updated'][$key] ?? [];
			$failed = $result['failed'][$key] ?? [];

			$totalCreated += count( $created );
			$totalUpdated += count( $updated );
			$totalFailed += count( $failed );

			foreach ( $created as $name ) {
				$this->output( "  [CREATE] $prefix:$name\n" );
			}
			foreach ( $updated as $name ) {
				$this->output( "  [UPDATE] $prefix:$name\n" );
			}
			foreach ( $failed as $name ) {
				$this->output( "  [FAILED] $prefix:$name\n" );
			}
		}

		foreach ( $result['errors'] as $error ) {
			$this->error( "  ERROR: $error" );
		}

		$this->output( "\n" . str_repeat( '-', 50 ) . "\n" );
		$this->output( "Summary: $totalCreated created, $totalUpdated updated" );
		if ( $totalFailed > 0 ) {
			$this->output( ", $totalFailed failed" );
		}
		$this->output( "\n" );
	}
}

$maintClass = InstallConfig::class;
require_once RUN_MAINTENANCE_IF_MAIN;
