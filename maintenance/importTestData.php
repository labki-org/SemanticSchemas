<?php

namespace MediaWiki\Extension\SemanticSchemas\Maintenance;

use MediaWiki\Extension\SemanticSchemas\Schema\InheritanceResolver;
use MediaWiki\Extension\SemanticSchemas\SemanticSchemasServices;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Title\Title;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = '/var/www/html'; // Docker default
}
if ( !file_exists( "$IP/maintenance/Maintenance.php" ) ) {
	$IP = __DIR__ . '/../../..'; // Fallback for standard structure
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Import the end-to-end test dataset (Library/Books domain) that exercises
 * every SemanticSchemas feature: inheritance, subobjects, display formats,
 * per-property overrides.
 *
 * Reads tests/fixtures/testdata/semanticschemas-testdata.vocab.json and
 * imports in three phases:
 *   1. Schema pages (properties + categories + custom templates)
 *   2. regenerateArtifacts for each test-data category (dispatcher +
 *      semantic + form templates)
 *   3. Content pages (main-NS instances with annotations + subobjects)
 */
class ImportTestData extends Maintenance {

	/** @var array<string,int> */
	private const NS_MAP = [
		'NS_MAIN' => NS_MAIN,
		'NS_TEMPLATE' => NS_TEMPLATE,
		'NS_CATEGORY' => NS_CATEGORY,
		'SMW_NS_PROPERTY' => SMW_NS_PROPERTY,
	];

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Import SemanticSchemas end-to-end test data (Library/Books domain).'
		);
		$this->addOption( 'skip-regen', 'Skip regenerateArtifacts between schema and content import' );
		$this->addOption(
			'vocab',
			'Path to vocab.json (defaults to tests/fixtures/testdata/semanticschemas-testdata.vocab.json)',
			false,
			true
		);
		$this->requireExtension( 'SemanticSchemas' );
	}

	public function execute() {
		$vocabPath = $this->getOption( 'vocab' )
			?: __DIR__ . '/../tests/fixtures/testdata/semanticschemas-testdata.vocab.json';
		$vocabPath = realpath( $vocabPath ) ?: $vocabPath;

		if ( !is_readable( $vocabPath ) ) {
			$this->fatalError( "Vocab file not found or unreadable: $vocabPath" );
		}

		$raw = file_get_contents( $vocabPath );
		$vocab = json_decode( $raw, true );
		if ( !is_array( $vocab ) || !isset( $vocab['import'] ) || !is_array( $vocab['import'] ) ) {
			$this->fatalError( "Invalid vocab.json structure at $vocabPath" );
		}

		$baseDir = dirname( $vocabPath );

		$schemaEntries = [];
		$contentEntries = [];
		foreach ( $vocab['import'] as $entry ) {
			$ns = $this->resolveNamespace( $entry['namespace'] ?? '' );
			if ( $ns === NS_MAIN ) {
				$contentEntries[] = $entry;
			} else {
				$schemaEntries[] = $entry;
			}
		}

		$this->output( "=== Phase 1: schema (" . count( $schemaEntries ) . " pages) ===\n" );
		$ok1 = 0;
		$fail1 = 0;
		foreach ( $schemaEntries as $entry ) {
			$this->importEntry( $entry, $baseDir ) ? $ok1++ : $fail1++;
		}

		if ( !$this->hasOption( 'skip-regen' ) ) {
			$this->output( "\n=== Phase 2: regenerate artifacts ===\n" );
			$this->regenerateTestCategories();
		} else {
			$this->output( "\n=== Phase 2: skipped (--skip-regen) ===\n" );
		}

		$this->output( "\n=== Phase 3: content (" . count( $contentEntries ) . " pages) ===\n" );
		$ok3 = 0;
		$fail3 = 0;
		foreach ( $contentEntries as $entry ) {
			$this->importEntry( $entry, $baseDir ) ? $ok3++ : $fail3++;
		}

		$this->output( "\n---\n" );
		$this->output( "Schema:  $ok1 imported, $fail1 failed\n" );
		$this->output( "Content: $ok3 imported, $fail3 failed\n" );
		if ( $fail1 + $fail3 > 0 ) {
			$this->fatalError( 'One or more imports failed.' );
		}
		$this->output( "\n✓ Test data import complete.\n" );
	}

	private function resolveNamespace( string $nsName ): int {
		if ( !isset( self::NS_MAP[$nsName] ) ) {
			$this->fatalError( "Unknown namespace: '$nsName'" );
		}
		return self::NS_MAP[$nsName];
	}

	private function importEntry( array $entry, string $baseDir ): bool {
		$pageName = $entry['page'] ?? '';
		$nsName = $entry['namespace'] ?? '';
		$importFrom = $entry['contents']['importFrom'] ?? '';
		if ( $pageName === '' || $importFrom === '' ) {
			$this->output( "  ✗ Malformed entry (missing page/contents.importFrom)\n" );
			return false;
		}

		$ns = $this->resolveNamespace( $nsName );
		$title = Title::makeTitleSafe( $ns, $pageName );
		if ( !$title ) {
			$this->output( "  ✗ Invalid title: $nsName:$pageName\n" );
			return false;
		}

		$contentPath = $baseDir . '/' . $importFrom;
		if ( !is_readable( $contentPath ) ) {
			$this->output( "  ✗ Content file missing: $contentPath\n" );
			return false;
		}
		$wikitext = file_get_contents( $contentPath );

		$creator = SemanticSchemasServices::getPageCreator( $this->getServiceContainer() );
		$ok = $creator->createOrUpdatePage(
			$title,
			$wikitext,
			'SemanticSchemas test-data import'
		);
		$this->output( '  ' . ( $ok ? '✓' : '✗' ) . ' ' . $title->getPrefixedText() . "\n" );
		return $ok;
	}

	private function regenerateTestCategories(): void {
		$services = $this->getServiceContainer();
		$categoryStore = SemanticSchemasServices::getWikiCategoryStore( $services );
		$templateGenerator = SemanticSchemasServices::getTemplateGenerator( $services );
		$formGenerator = SemanticSchemasServices::getFormGenerator( $services );

		$allCategories = $categoryStore->getAllCategories();
		$categoryMap = [];
		foreach ( $allCategories as $cat ) {
			$categoryMap[$cat->getName()] = $cat;
		}
		$resolver = new InheritanceResolver( $categoryMap );

		$testCategories = [
			'Work', 'Book', 'Textbook', 'Article',
			'Reviewed', 'ReviewedBook',
			'Chapter', 'Review', 'Person',
		];
		foreach ( $testCategories as $name ) {
			if ( !isset( $categoryMap[$name] ) ) {
				$this->output( "  ? $name (not found in store yet — skipping)\n" );
				continue;
			}
			$category = $categoryMap[$name];
			$effective = $resolver->getEffectiveCategory( $name );

			$tplResult = $templateGenerator->generateAllTemplates( $category, $resolver );
			$formOK = $formGenerator->generateAndSaveForm( $effective );
			$compositeOK = $formGenerator->generateAndSaveCompositeForm( $effective, $resolver );

			$status = ( $tplResult['success'] && $formOK && $compositeOK ) ? '✓' : '✗';
			$this->output( "  $status $name\n" );
			if ( !$tplResult['success'] ) {
				foreach ( $tplResult['errors'] as $err ) {
					$this->output( "      tpl: $err\n" );
				}
			}
		}
	}
}

$maintClass = ImportTestData::class;
require_once RUN_MAINTENANCE_IF_MAIN;
