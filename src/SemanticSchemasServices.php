<?php

namespace MediaWiki\Extension\SemanticSchemas;

use MediaWiki\Extension\SemanticSchemas\Generator\DisplayStubGenerator;
use MediaWiki\Extension\SemanticSchemas\Generator\FormGenerator;
use MediaWiki\Extension\SemanticSchemas\Generator\TemplateGenerator;
use MediaWiki\Extension\SemanticSchemas\Schema\ExtensionConfigInstaller;
use MediaWiki\Extension\SemanticSchemas\Schema\OntologyInspector;
use MediaWiki\Extension\SemanticSchemas\Service\CategoryHierarchyService;
use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\PageHashComputer;
use MediaWiki\Extension\SemanticSchemas\Store\StateManager;
use MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;
use MediaWiki\Extension\SemanticSchemas\Store\WikiSubobjectStore;
use MediaWiki\MediaWikiServices;

/**
 * Typed accessor for SemanticSchemas services registered in ServiceWiring.php.
 */
class SemanticSchemasServices {

	public static function getPageCreator(
		MediaWikiServices|null $services = null
	): PageCreator {
		return ( $services ?? MediaWikiServices::getInstance() )
			->get( 'SemanticSchemas.PageCreator' );
	}

	public static function getWikiPropertyStore(
		MediaWikiServices|null $services = null
	): WikiPropertyStore {
		return ( $services ?? MediaWikiServices::getInstance() )
			->get( 'SemanticSchemas.WikiPropertyStore' );
	}

	public static function getWikiCategoryStore(
		MediaWikiServices|null $services = null
	): WikiCategoryStore {
		return ( $services ?? MediaWikiServices::getInstance() )
			->get( 'SemanticSchemas.WikiCategoryStore' );
	}

	public static function getWikiSubobjectStore(
		MediaWikiServices|null $services = null
	): WikiSubobjectStore {
		return ( $services ?? MediaWikiServices::getInstance() )
			->get( 'SemanticSchemas.WikiSubobjectStore' );
	}

	public static function getStateManager(
		MediaWikiServices|null $services = null
	): StateManager {
		return ( $services ?? MediaWikiServices::getInstance() )
			->get( 'SemanticSchemas.StateManager' );
	}

	public static function getPageHashComputer(
		MediaWikiServices|null $services = null
	): PageHashComputer {
		return ( $services ?? MediaWikiServices::getInstance() )
			->get( 'SemanticSchemas.PageHashComputer' );
	}

	public static function getTemplateGenerator(
		MediaWikiServices|null $services = null
	): TemplateGenerator {
		return ( $services ?? MediaWikiServices::getInstance() )
			->get( 'SemanticSchemas.TemplateGenerator' );
	}

	public static function getFormGenerator(
		MediaWikiServices|null $services = null
	): FormGenerator {
		return ( $services ?? MediaWikiServices::getInstance() )
			->get( 'SemanticSchemas.FormGenerator' );
	}

	public static function getDisplayStubGenerator(
		MediaWikiServices|null $services = null
	): DisplayStubGenerator {
		return ( $services ?? MediaWikiServices::getInstance() )
			->get( 'SemanticSchemas.DisplayStubGenerator' );
	}

	public static function getExtensionConfigInstaller(
		MediaWikiServices|null $services = null
	): ExtensionConfigInstaller {
		return ( $services ?? MediaWikiServices::getInstance() )
			->get( 'SemanticSchemas.ExtensionConfigInstaller' );
	}

	public static function getOntologyInspector(
		MediaWikiServices|null $services = null
	): OntologyInspector {
		return ( $services ?? MediaWikiServices::getInstance() )
			->get( 'SemanticSchemas.OntologyInspector' );
	}

	public static function getCategoryHierarchyService(
		MediaWikiServices|null $services = null
	): CategoryHierarchyService {
		return ( $services ?? MediaWikiServices::getInstance() )
			->get( 'SemanticSchemas.CategoryHierarchyService' );
	}
}
