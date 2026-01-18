<?php

namespace MediaWiki\Extension\SemanticSchemas\Maintenance;

use Maintenance;
use MediaWiki\Extension\SemanticSchemas\Schema\ExtensionConfigInstaller;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = '/var/www/html';
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Maintenance script to manually run the ExtensionConfigInstaller.
 * This replaces the automatic hook execution to avoid transaction conflicts during boot.
 */
class InstallConfig extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Install SemanticSchemas configuration (Categories, Properties, etc.)' );
		$this->addOption( 'dry-run', 'Preview changes without writing to the wiki' );
		$this->requireExtension( 'SemanticSchemas' );
	}

	public function execute() {
		// Locate the bundled config file relative to the extension root.
		$root = dirname( __DIR__, 1 );
		$configPath = $root . '/resources/extension-config.json';

		if ( !file_exists( $configPath ) ) {
			$this->fatalError( "Config file not found: $configPath" );
		}

		$installer = new ExtensionConfigInstaller();
		$dryRun = $this->hasOption( 'dry-run' );

		if ( $dryRun ) {
			$this->executeDryRun( $installer, $configPath );
		} else {
			$this->executeInstall( $installer, $configPath );
		}
	}

	/**
	 * Execute dry-run mode: preview what would be created/updated.
	 */
	private function executeDryRun( ExtensionConfigInstaller $installer, string $configPath ): void {
		$this->output( "DRY RUN - No changes will be made\n" );
		$this->output( str_repeat( '=', 50 ) . "\n\n" );

		$result = $installer->previewInstallation( $configPath );

		// Show errors and warnings
		if ( !empty( $result['errors'] ) ) {
			$this->output( "ERRORS (installation would be blocked):\n" );
			foreach ( $result['errors'] as $msg ) {
				$this->output( "  ✗ $msg\n" );
			}
			$this->output( "\n" );
			return;
		}

		if ( !empty( $result['warnings'] ) ) {
			$this->output( "Warnings:\n" );
			foreach ( $result['warnings'] as $msg ) {
				$this->output( "  ! $msg\n" );
			}
			$this->output( "\n" );
		}

		// Show what would be created
		$wouldCreate = $result['would_create'];
		$totalCreate = count( $wouldCreate['properties'] )
			+ count( $wouldCreate['categories'] )
			+ count( $wouldCreate['subobjects'] );

		if ( $totalCreate > 0 ) {
			$this->output( "Would CREATE:\n" );

			if ( !empty( $wouldCreate['properties'] ) ) {
				foreach ( $wouldCreate['properties'] as $name ) {
					$this->output( "  Property:$name\n" );
				}
			}
			if ( !empty( $wouldCreate['subobjects'] ) ) {
				foreach ( $wouldCreate['subobjects'] as $name ) {
					$this->output( "  Subobject:$name\n" );
				}
			}
			if ( !empty( $wouldCreate['categories'] ) ) {
				foreach ( $wouldCreate['categories'] as $name ) {
					$this->output( "  Category:$name (+ templates and form)\n" );
				}
			}
			$this->output( "\n" );
		}

		// Show what would be updated
		$wouldUpdate = $result['would_update'];
		$totalUpdate = count( $wouldUpdate['properties'] )
			+ count( $wouldUpdate['categories'] )
			+ count( $wouldUpdate['subobjects'] );

		if ( $totalUpdate > 0 ) {
			$this->output( "Would UPDATE (append to existing pages):\n" );

			if ( !empty( $wouldUpdate['properties'] ) ) {
				foreach ( $wouldUpdate['properties'] as $name ) {
					$this->output( "  Property:$name\n" );
				}
			}
			if ( !empty( $wouldUpdate['subobjects'] ) ) {
				foreach ( $wouldUpdate['subobjects'] as $name ) {
					$this->output( "  Subobject:$name\n" );
				}
			}
			if ( !empty( $wouldUpdate['categories'] ) ) {
				foreach ( $wouldUpdate['categories'] as $name ) {
					$this->output( "  Category:$name\n" );
				}
			}
			$this->output( "\n" );
		}

		// Summary
		$this->output( str_repeat( '-', 50 ) . "\n" );
		$this->output( "Summary: $totalCreate would be created, $totalUpdate would be updated\n" );
	}

	/**
	 * Execute actual installation with verbose output.
	 */
	private function executeInstall( ExtensionConfigInstaller $installer, string $configPath ): void {
		$this->output( "Installing SemanticSchemas base configuration...\n" );
		$this->output( str_repeat( '=', 50 ) . "\n\n" );

		$result = $installer->applyFromFile( $configPath );

		// Show errors
		if ( !empty( $result['errors'] ) ) {
			$this->output( "ERRORS (installation aborted):\n" );
			foreach ( $result['errors'] as $msg ) {
				$this->error( "  ✗ $msg" );
			}
			$this->output( "\n" );
			return;
		}

		// Show warnings
		if ( !empty( $result['warnings'] ) ) {
			$this->output( "Warnings:\n" );
			foreach ( $result['warnings'] as $msg ) {
				$this->output( "  ! $msg\n" );
			}
			$this->output( "\n" );
		}

		// Show properties
		$this->outputSection(
			'Properties',
			$result['created']['properties'],
			$result['updated']['properties'],
			$result['failed']['properties'],
			'Property'
		);

		// Show subobjects
		$this->outputSection(
			'Subobjects',
			$result['created']['subobjects'],
			$result['updated']['subobjects'],
			$result['failed']['subobjects'],
			'Subobject'
		);

		// Show categories
		$this->outputSection(
			'Categories',
			$result['created']['categories'],
			$result['updated']['categories'],
			$result['failed']['categories'],
			'Category'
		);

		// Summary
		$totalCreated = count( $result['created']['properties'] )
			+ count( $result['created']['categories'] )
			+ count( $result['created']['subobjects'] );
		$totalUpdated = count( $result['updated']['properties'] )
			+ count( $result['updated']['categories'] )
			+ count( $result['updated']['subobjects'] );
		$totalFailed = count( $result['failed']['properties'] )
			+ count( $result['failed']['categories'] )
			+ count( $result['failed']['subobjects'] );

		$this->output( str_repeat( '-', 50 ) . "\n" );
		$this->output( "Summary: $totalCreated created, $totalUpdated updated" );
		if ( $totalFailed > 0 ) {
			$this->output( ", $totalFailed failed" );
		}
		$this->output( "\n" );

		if ( $totalFailed > 0 ) {
			$this->output( "\nInstallation completed with errors. Check warnings above.\n" );
		} else {
			$this->output( "\nConfiguration installation complete.\n" );
		}
	}

	/**
	 * Output a section with created/updated/failed items.
	 */
	private function outputSection(
		string $sectionName,
		array $created,
		array $updated,
		array $failed,
		string $prefix
	): void {
		$total = count( $created ) + count( $updated ) + count( $failed );
		if ( $total === 0 ) {
			return;
		}

		$this->output( "$sectionName:\n" );

		foreach ( $created as $name ) {
			$this->output( "  [CREATE] $prefix:$name\n" );
		}
		foreach ( $updated as $name ) {
			$this->output( "  [UPDATE] $prefix:$name\n" );
		}
		foreach ( $failed as $name ) {
			$this->output( "  [FAILED] $prefix:$name\n" );
		}

		$this->output( "\n" );
	}
}

$maintClass = InstallConfig::class;
require_once RUN_MAINTENANCE_IF_MAIN;
