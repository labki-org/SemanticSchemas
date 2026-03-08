<?php

namespace MediaWiki\Extension\SemanticSchemas\Maintenance;

use Maintenance;
use MediaWiki\Extension\SemanticSchemas\SemanticSchemasServices;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = '/var/www/html'; // Docker default
}
if ( !file_exists( "$IP/maintenance/Maintenance.php" ) ) {
	$IP = __DIR__ . '/../../..'; // Fallback for standard structure
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Maintenance script to regenerate templates and forms
 */
class RegenerateArtifacts extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Regenerate templates and forms for categories' );
		$this->addOption( 'category', 'Regenerate artifacts for a specific category', false, true );
		$this->addOption(
			'generate-display',
			'Generate or update display templates (includes parent displays)',
			false,
			false
		);
		$this->requireExtension( 'SemanticSchemas' );
	}

	public function execute() {
		$categoryName = $this->getOption( 'category' );
		$generateDisplay = $this->hasOption( 'generate-display' );

		$services = $this->getServiceContainer();
		$categoryStore = SemanticSchemasServices::getWikiCategoryStore( $services );
		$artifactGenerator = SemanticSchemasServices::getArtifactGenerator( $services );

		// getAllCategories() returns a name-keyed map
		$categoryMap = $categoryStore->getAllCategories();

		if ( $categoryName !== null ) {
			if ( !isset( $categoryMap[$categoryName] ) ) {
				$this->fatalError( "Category not found: $categoryName" );
			}
			$this->output( "Regenerating artifacts for category: $categoryName\n" );
		} else {
			$this->output( "Regenerating artifacts for all categories...\n\n" );
			$this->output( "Found " . count( $categoryMap ) . " categories\n\n" );
		}

		$result = $artifactGenerator->generateAll(
			$categoryMap,
			$generateDisplay,
			$categoryName !== null ? [ $categoryName ] : null
		);

		foreach ( $result['results'] as $name => $genResult ) {
			$this->output( "Processing: $name\n" );

			if ( $genResult['success'] ) {
				$this->output( "  ✓ Generated templates and form\n" );
			} else {
				$this->output( "  ✗ Generation failed\n" );
				foreach ( $genResult['errors'] as $error ) {
					$this->output( "    - $error\n" );
				}
			}

			if ( $generateDisplay ) {
				$displayResult = $genResult['displayResult'] ?? [];
				if ( !empty( $displayResult['error'] ) ) {
					$this->output( "  ✗ Display template failed: {$displayResult['error']}\n" );
				} elseif ( $displayResult['created'] ?? false ) {
					$this->output( "  ✓ Generated display template stub\n" );
				} elseif ( $displayResult['updated'] ?? false ) {
					$this->output( "  ✓ Updated display template\n" );
				}
			}

			$this->output( "\n" );
		}

		$this->output( "\nRegeneration complete!\n" );
	}
}

$maintClass = RegenerateArtifacts::class;
require_once RUN_MAINTENANCE_IF_MAIN;
