<?php
/**
 * @phan-file-suppress PhanUnreferencedPublicMethod
 */

namespace MediaWiki\Extension\SemanticSchemas;

use MediaWiki\Extension\SemanticSchemas\Generator\DisplayStubGenerator;
use MediaWiki\Extension\SemanticSchemas\Generator\FormGenerator;
use MediaWiki\Extension\SemanticSchemas\Generator\TemplateGenerator;
use MediaWiki\Extension\SemanticSchemas\Schema\OntologyInspector;
use MediaWiki\Extension\SemanticSchemas\Schema\SchemaValidator;
use MediaWiki\Extension\SemanticSchemas\Service\CategoryHierarchyService;
use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\StateManager;
use MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;
use MediaWiki\MediaWikiServices;

/**
 * Typed accessors for SemanticSchemas services registered in src/ServiceWiring.php.
 *
 * This class is NOT injected itself -- it provides type-safe static getters that wrap
 * the untyped MediaWikiServices::get( 'string' ) calls. It is only used in entry points
 * where constructor injection via ObjectFactory is not available (e.g. maintenance scripts).
 *
 * All other consumers (Special pages, API modules, hook handlers) receive their
 * dependencies via constructor injection declared in extension.json.
 *
 * This is a standard MediaWiki pattern; see CirrusSearchServices and
 * GrowthExperimentsServices for reference implementations.
 *
 * @see src/ServiceWiring.php for service definitions
 */
class SemanticSchemasServices {

	public static function getPageCreator( MediaWikiServices $services ): PageCreator {
		return $services->get( 'SemanticSchemas.PageCreator' );
	}

	public static function getWikiPropertyStore( MediaWikiServices $services ): WikiPropertyStore {
		return $services->get( 'SemanticSchemas.WikiPropertyStore' );
	}

	public static function getWikiCategoryStore( MediaWikiServices $services ): WikiCategoryStore {
		return $services->get( 'SemanticSchemas.WikiCategoryStore' );
	}

	public static function getStateManager( MediaWikiServices $services ): StateManager {
		return $services->get( 'SemanticSchemas.StateManager' );
	}

	public static function getTemplateGenerator( MediaWikiServices $services ): TemplateGenerator {
		return $services->get( 'SemanticSchemas.TemplateGenerator' );
	}

	public static function getFormGenerator( MediaWikiServices $services ): FormGenerator {
		return $services->get( 'SemanticSchemas.FormGenerator' );
	}

	public static function getDisplayStubGenerator( MediaWikiServices $services ): DisplayStubGenerator {
		return $services->get( 'SemanticSchemas.DisplayStubGenerator' );
	}

	public static function getOntologyInspector( MediaWikiServices $services ): OntologyInspector {
		return $services->get( 'SemanticSchemas.OntologyInspector' );
	}

	public static function getCategoryHierarchyService( MediaWikiServices $services ): CategoryHierarchyService {
		return $services->get( 'SemanticSchemas.CategoryHierarchyService' );
	}

	public static function getSchemaValidator( MediaWikiServices $services ): SchemaValidator {
		return $services->get( 'SemanticSchemas.SchemaValidator' );
	}
}
