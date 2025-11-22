<?php

namespace MediaWiki\Extension\StructureSync\Maintenance;

use Maintenance;
use MediaWiki\Extension\StructureSync\Schema\SchemaImporter;
use MediaWiki\Extension\StructureSync\Schema\SchemaLoader;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Maintenance script to import an ontology schema
 */
class ImportOntology extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Import an ontology schema from JSON or YAML file' );
		$this->addOption( 'input', 'Input file path', true, true );
		$this->addOption( 'dry-run', 'Preview changes without applying them', false, false );
		$this->addOption( 'no-generate', 'Skip generating templates and forms', false, false );
		$this->requireExtension( 'StructureSync' );
	}

	public function execute() {
		$inputFile = $this->getOption( 'input' );
		$dryRun = $this->hasOption( 'dry-run' );
		$noGenerate = $this->hasOption( 'no-generate' );

		if ( !file_exists( $inputFile ) ) {
			$this->fatalError( "Input file not found: $inputFile" );
		}

		$this->output( "Importing ontology schema from: $inputFile\n" );

		// Load schema
		$loader = new SchemaLoader();
		try {
			$schema = $loader->loadFromFile( $inputFile );
		} catch ( \Exception $e ) {
			$this->fatalError( "Failed to load schema: " . $e->getMessage() );
		}

		if ( $dryRun ) {
			$this->output( "\n=== DRY RUN MODE ===\n" );
		}

		// Import schema
		$importer = new SchemaImporter();
		$result = $importer->importFromArray( $schema, [
			'dryRun' => $dryRun,
			'generateArtifacts' => !$noGenerate,
		] );

		// Display results
		$this->output( "\nImport Results:\n" );
		$this->output( "  Categories:\n" );
		$this->output( "    Created: {$result['categoriesCreated']}\n" );
		$this->output( "    Updated: {$result['categoriesUpdated']}\n" );
		$this->output( "    Unchanged: {$result['categoriesUnchanged']}\n" );
		$this->output( "  Properties:\n" );
		$this->output( "    Created: {$result['propertiesCreated']}\n" );
		$this->output( "    Updated: {$result['propertiesUpdated']}\n" );
		$this->output( "    Unchanged: {$result['propertiesUnchanged']}\n" );

		if ( !empty( $result['errors'] ) ) {
			$this->output( "\nErrors:\n" );
			foreach ( $result['errors'] as $error ) {
				$this->output( "  - $error\n" );
			}
		}

		if ( $result['success'] ) {
			if ( $dryRun ) {
				$this->output( "\nDry run complete. No changes were made.\n" );
			} else {
				$this->output( "\nImport complete!\n" );
			}
		} else {
			$this->fatalError( "Import failed with errors." );
		}
	}
}

$maintClass = ImportOntology::class;
require_once RUN_MAINTENANCE_IF_MAIN;

