<?php

namespace MediaWiki\Extension\StructureSync\Maintenance;

use Maintenance;
use MediaWiki\Extension\StructureSync\Schema\SchemaExporter;
use MediaWiki\Extension\StructureSync\Schema\SchemaLoader;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Maintenance script to export the ontology schema
 */
class ExportOntology extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Export the ontology schema to JSON or YAML format' );
		$this->addOption( 'format', 'Output format: json or yaml', false, true );
		$this->addOption( 'output', 'Output file path (if not specified, outputs to stdout)', false, true );
		$this->addOption( 'include-inherited', 'Include inherited properties in export', false, false );
		$this->requireExtension( 'StructureSync' );
	}

	public function execute() {
		$format = $this->getOption( 'format', 'json' );
		$outputFile = $this->getOption( 'output' );
		$includeInherited = $this->hasOption( 'include-inherited' );

		if ( !in_array( $format, [ 'json', 'yaml' ] ) ) {
			$this->fatalError( "Invalid format: $format. Must be 'json' or 'yaml'." );
		}

		$this->output( "Exporting ontology schema...\n" );

		// Export schema
		$exporter = new SchemaExporter();
		$schema = $exporter->exportToArray( $includeInherited );

		$stats = $exporter->getStatistics();
		$this->output( "Found {$stats['categoryCount']} categories and {$stats['propertyCount']} properties\n" );

		// Convert to requested format
		$loader = new SchemaLoader();
		if ( $format === 'json' ) {
			$content = $loader->saveToJson( $schema );
		} else {
			$content = $loader->saveToYaml( $schema );
		}

		// Output
		if ( $outputFile !== null ) {
			try {
				$loader->saveToFile( $schema, $outputFile );
				$this->output( "Schema exported to: $outputFile\n" );
			} catch ( \Exception $e ) {
				$this->fatalError( "Failed to write file: " . $e->getMessage() );
			}
		} else {
			$this->output( "\n" . $content . "\n" );
		}

		$this->output( "Export complete!\n" );
	}
}

$maintClass = ExportOntology::class;
require_once RUN_MAINTENANCE_IF_MAIN;

