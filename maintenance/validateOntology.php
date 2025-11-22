<?php

namespace MediaWiki\Extension\StructureSync\Maintenance;

use Maintenance;
use MediaWiki\Extension\StructureSync\Schema\SchemaExporter;
use MediaWiki\Extension\StructureSync\Schema\SchemaLoader;
use MediaWiki\Extension\StructureSync\Schema\SchemaValidator;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Maintenance script to validate the ontology schema
 */
class ValidateOntology extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Validate the ontology schema for consistency' );
		$this->addOption( 'schema', 'Validate a schema file instead of the wiki state', false, true );
		$this->addOption( 'show-warnings', 'Show warnings in addition to errors', false, false );
		$this->requireExtension( 'StructureSync' );
	}

	public function execute() {
		$schemaFile = $this->getOption( 'schema' );
		$showWarnings = $this->hasOption( 'show-warnings' );

		if ( $schemaFile !== null ) {
			// Validate schema file
			$this->output( "Validating schema file: $schemaFile\n\n" );

			if ( !file_exists( $schemaFile ) ) {
				$this->fatalError( "Schema file not found: $schemaFile" );
			}

			$loader = new SchemaLoader();
			try {
				$schema = $loader->loadFromFile( $schemaFile );
			} catch ( \Exception $e ) {
				$this->fatalError( "Failed to load schema: " . $e->getMessage() );
			}

			$validator = new SchemaValidator();
			$errors = $validator->validateSchema( $schema );
			$warnings = $showWarnings ? $validator->generateWarnings( $schema ) : [];
		} else {
			// Validate wiki state
			$this->output( "Validating current wiki state...\n\n" );

			$exporter = new SchemaExporter();
			$result = $exporter->validateWikiState();

			$errors = $result['errors'];
			$warnings = $showWarnings ? $result['warnings'] : [];
		}

		// Display results
		if ( empty( $errors ) ) {
			$this->output( "✓ No errors found!\n" );
			$exitCode = 0;
		} else {
			$this->output( "✗ Found " . count( $errors ) . " error(s):\n\n" );
			foreach ( $errors as $error ) {
				$this->output( "  ERROR: $error\n" );
			}
			$exitCode = 1;
		}

		if ( $showWarnings && !empty( $warnings ) ) {
			$this->output( "\n" . count( $warnings ) . " warning(s):\n\n" );
			foreach ( $warnings as $warning ) {
				$this->output( "  WARNING: $warning\n" );
			}
		}

		return $exitCode;
	}
}

$maintClass = ValidateOntology::class;
require_once RUN_MAINTENANCE_IF_MAIN;

