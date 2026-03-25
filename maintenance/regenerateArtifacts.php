<?php

namespace MediaWiki\Extension\SemanticSchemas\Maintenance;

use Maintenance;
use MediaWiki\Extension\SemanticSchemas\Schema\InheritanceResolver;
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
		$templateGenerator = SemanticSchemasServices::getTemplateGenerator( $services );
		$formGenerator = SemanticSchemasServices::getFormGenerator( $services );
		$displayGenerator = SemanticSchemasServices::getDisplayStubGenerator( $services );

		// Build category map and resolver once for all categories
		$allCategories = $categoryStore->getAllCategories();
		$categoryMap = [];
		foreach ( $allCategories as $cat ) {
			$categoryMap[$cat->getName()] = $cat;
		}
		$resolver = new InheritanceResolver( $categoryMap );

		if ( $categoryName !== null ) {
			// Regenerate for specific category
			$this->output( "Regenerating artifacts for category: $categoryName\n" );
			$category = $categoryStore->readCategory( $categoryName );

			if ( $category === null ) {
				$this->fatalError( "Category not found: $categoryName" );
			}

			$this->regenerateCategory(
				$category, $resolver, $templateGenerator, $formGenerator,
				$displayGenerator, $generateDisplay
			);
		} else {
			// Regenerate for all categories
			$this->output( "Regenerating artifacts for all categories...\n\n" );

			$this->output( "Found " . count( $allCategories ) . " categories\n\n" );

			foreach ( $allCategories as $category ) {
				$this->regenerateCategory(
					$category, $resolver, $templateGenerator, $formGenerator,
					$displayGenerator, $generateDisplay
				);
			}
		}

		$this->output( "\nRegeneration complete!\n" );
	}

	/**
	 * Regenerate artifacts for a single category
	 *
	 * @param \MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel $category
	 * @param InheritanceResolver $resolver
	 * @param \MediaWiki\Extension\SemanticSchemas\Generator\TemplateGenerator $templateGenerator
	 * @param \MediaWiki\Extension\SemanticSchemas\Generator\FormGenerator $formGenerator
	 * @param \MediaWiki\Extension\SemanticSchemas\Generator\DisplayStubGenerator $displayGenerator
	 * @param bool $generateDisplay
	 */
	private function regenerateCategory(
		$category, $resolver, $templateGenerator, $formGenerator,
		$displayGenerator, $generateDisplay
	) {
		$name = $category->getName();
		$this->output( "Processing: $name\n" );

		$chain = $resolver->getInheritanceChain( $name );

		$result = $templateGenerator->generateAllTemplates( $category, $chain );
		if ( $result['success'] ) {
			$this->output( "  ✓ Generated semantic and dispatcher templates\n" );
		} else {
			$this->output( "  ✗ Template generation failed\n" );
			foreach ( $result['errors'] as $error ) {
				$this->output( "    - $error\n" );
			}
		}

		if ( $formGenerator->generateAndSaveForm( $category->effective() ) ) {
			$this->output( "  ✓ Generated form\n" );
		} else {
			$this->output( "  ✗ Form generation failed\n" );
		}

		if ( $generateDisplay ) {
			$displayResult = $displayGenerator->generateOrUpdateDisplayStub( $category->effective() );
			if ( !empty( $displayResult['error'] ) ) {
				$this->output( "  ✗ Display template failed: {$displayResult['error']}\n" );
			} elseif ( $displayResult['created'] ) {
				$this->output( "  ✓ Generated display template stub\n" );
			} elseif ( $displayResult['updated'] ) {
				$this->output( "  ✓ Updated display template\n" );
			}
		}

		$this->output( "\n" );
	}
}

$maintClass = RegenerateArtifacts::class;
require_once RUN_MAINTENANCE_IF_MAIN;
