<?php

namespace MediaWiki\Extension\SemanticSchemas\Maintenance;

use Maintenance;
use MediaWiki\Extension\SemanticSchemas\Generator\DisplayStubGenerator;
use MediaWiki\Extension\SemanticSchemas\Generator\FormGenerator;
use MediaWiki\Extension\SemanticSchemas\Generator\TemplateGenerator;
use MediaWiki\Extension\SemanticSchemas\Schema\InheritanceResolver;
use MediaWiki\Extension\SemanticSchemas\Store\PageHashComputer;
use MediaWiki\Extension\SemanticSchemas\Store\StateManager;
use MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore;

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

		$categoryStore = new WikiCategoryStore();
		$templateGenerator = new TemplateGenerator();
		$formGenerator = new FormGenerator();
		$displayGenerator = new DisplayStubGenerator();

		if ( $categoryName !== null ) {
			// Regenerate for specific category
			$this->output( "Regenerating artifacts for category: $categoryName\n" );
			$category = $categoryStore->readCategory( $categoryName );

			if ( $category === null ) {
				$this->fatalError( "Category not found: $categoryName" );
			}

			$this->regenerateCategory(
				$category, $templateGenerator, $formGenerator, $displayGenerator, $generateDisplay
			);
		} else {
			// Regenerate for all categories
			$this->output( "Regenerating artifacts for all categories...\n\n" );
			$categories = $categoryStore->getAllCategories();

			$this->output( "Found " . count( $categories ) . " categories\n\n" );

			foreach ( $categories as $category ) {
				$this->regenerateCategory(
					$category, $templateGenerator, $formGenerator, $displayGenerator, $generateDisplay
				);
			}
		}

		// Update template hashes after regeneration
		$this->output( "Updating template hashes...\n" );
		$stateManager = new StateManager();
		$hashComputer = new PageHashComputer();
		$templateHashes = [];

		$allCategories = $categoryStore->getAllCategories();
		$categoryMap = [];
		foreach ( $allCategories as $cat ) {
			$categoryMap[$cat->getName()] = $cat;
		}
		$resolver = new InheritanceResolver( $categoryMap );

		// Compute hashes for all categories or just the specific category
		$categoriesToHash = ( $categoryName !== null )
			? [ $categoryStore->readCategory( $categoryName ) ]
			: $allCategories;

		foreach ( $categoriesToHash as $category ) {
			if ( $category === null ) {
				continue;
			}

			$name = $category->getName();
			try {
				$effective = $resolver->getEffectiveCategory( $name );

				$semanticContent = $templateGenerator->generateSemanticTemplate( $effective );
				$templateHashes["Template:$name/semantic"] = [
					'generated' => $hashComputer->hashContentString( $semanticContent ),
					'category' => $name,
				];

				$dispatcherContent = $templateGenerator->generateDispatcherTemplate( $effective );
				$templateHashes["Template:$name"] = [
					'generated' => $hashComputer->hashContentString( $dispatcherContent ),
					'category' => $name,
				];

				$formContent = $formGenerator->generateForm( $effective );
				$templateHashes["Form:$name"] = [
					'generated' => $hashComputer->hashContentString( $formContent ),
					'category' => $name,
				];
			} catch ( \Throwable $e ) {
				$this->output( "  Warning: Could not hash templates for $name: " . $e->getMessage() . "\n" );
			}
		}

		if ( !empty( $templateHashes ) ) {
			$stateManager->setTemplateHashes( $templateHashes );
			$this->output( "  Updated " . count( $templateHashes ) . " template hashes\n" );
		}

		$this->output( "\nRegeneration complete!\n" );
	}

	/**
	 * Regenerate artifacts for a single category
	 *
	 * @param \MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel $category
	 * @param TemplateGenerator $templateGenerator
	 * @param FormGenerator $formGenerator
	 * @param DisplayStubGenerator $displayGenerator
	 * @param bool $generateDisplay
	 */
	private function regenerateCategory(
		$category, $templateGenerator, $formGenerator, $displayGenerator, $generateDisplay
	) {
		$name = $category->getName();
		$this->output( "Processing: $name\n" );

		// Get effective category and ancestor chain
		$categoryStore = new WikiCategoryStore();
		$allCategories = $categoryStore->getAllCategories();
		$categoryMap = [];
		foreach ( $allCategories as $cat ) {
			$categoryMap[$cat->getName()] = $cat;
		}
		$resolver = new InheritanceResolver( $categoryMap );
		$effective = $resolver->getEffectiveCategory( $name );
		$ancestors = $resolver->getAncestors( $name );

		// Generate semantic template
		$result = $templateGenerator->generateAllTemplates( $effective );
		if ( $result['success'] ) {
			$this->output( "  ✓ Generated semantic and dispatcher templates\n" );
		} else {
			$this->output( "  ✗ Template generation failed\n" );
			foreach ( $result['errors'] as $error ) {
				$this->output( "    - $error\n" );
			}
		}

		// Generate form
		if ( $formGenerator->generateAndSaveForm( $effective, $ancestors ) ) {
			$this->output( "  ✓ Generated form\n" );
		} else {
			$this->output( "  ✗ Form generation failed\n" );
		}

		// Generate or update display template if requested
		if ( $generateDisplay ) {
			$displayResult = $displayGenerator->generateOrUpdateDisplayStub( $effective );
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
