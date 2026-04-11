<?php

use MediaWiki\Extension\SemanticSchemas\Generator\DisplayStubGenerator;
use MediaWiki\Extension\SemanticSchemas\Generator\FormGenerator;
use MediaWiki\Extension\SemanticSchemas\Generator\PropertyInputMapper;
use MediaWiki\Extension\SemanticSchemas\Generator\TemplateGenerator;
use MediaWiki\Extension\SemanticSchemas\Schema\OntologyInspector;
use MediaWiki\Extension\SemanticSchemas\Schema\SchemaValidator;
use MediaWiki\Extension\SemanticSchemas\Service\CategoryHierarchyService;
use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\PageHashComputer;
use MediaWiki\Extension\SemanticSchemas\Store\StateManager;
use MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;
use MediaWiki\MediaWikiServices;

/** @phpcs-require-sorted-array */
return [

	'SemanticSchemas.CategoryHierarchyService' => static function (
		MediaWikiServices $services
	): CategoryHierarchyService {
		return new CategoryHierarchyService(
			$services->get( 'SemanticSchemas.WikiCategoryStore' )
		);
	},

	'SemanticSchemas.DisplayStubGenerator' => static function (
		MediaWikiServices $services
	): DisplayStubGenerator {
		return new DisplayStubGenerator(
			$services->get( 'SemanticSchemas.PageCreator' ),
			$services->get( 'SemanticSchemas.WikiPropertyStore' ),
			$services->getContentLanguage()
		);
	},

	'SemanticSchemas.FormGenerator' => static function (
		MediaWikiServices $services
	): FormGenerator {
		return new FormGenerator(
			$services->get( 'SemanticSchemas.PageCreator' ),
			$services->get( 'SemanticSchemas.WikiPropertyStore' ),
			$services->get( 'SemanticSchemas.PropertyInputMapper' ),
			$services->get( 'SemanticSchemas.WikiCategoryStore' )
		);
	},

	'SemanticSchemas.OntologyInspector' => static function (
		MediaWikiServices $services
	): OntologyInspector {
		return new OntologyInspector(
			$services->get( 'SemanticSchemas.WikiCategoryStore' ),
			$services->get( 'SemanticSchemas.WikiPropertyStore' ),
			$services->get( 'SemanticSchemas.StateManager' ),
			$services->get( 'SemanticSchemas.PageHashComputer' ),
			$services->get( 'SemanticSchemas.SchemaValidator' )
		);
	},

	'SemanticSchemas.PageCreator' => static function (
		MediaWikiServices $services
	): PageCreator {
		return new PageCreator(
			$services->getWikiPageFactory(),
			$services->getDeletePageFactory()
		);
	},

	'SemanticSchemas.PageHashComputer' => static function (
		MediaWikiServices $services
	): PageHashComputer {
		return new PageHashComputer(
			$services->get( 'SemanticSchemas.WikiCategoryStore' ),
			$services->get( 'SemanticSchemas.WikiPropertyStore' )
		);
	},

	'SemanticSchemas.PropertyInputMapper' => static function (
		MediaWikiServices $services
	): PropertyInputMapper {
		return new PropertyInputMapper();
	},

	'SemanticSchemas.SchemaValidator' => static function (
		MediaWikiServices $services
	): SchemaValidator {
		return new SchemaValidator();
	},

	'SemanticSchemas.StateManager' => static function (
		MediaWikiServices $services
	): StateManager {
		return new StateManager(
			$services->get( 'SemanticSchemas.PageCreator' )
		);
	},

	'SemanticSchemas.TemplateGenerator' => static function (
		MediaWikiServices $services
	): TemplateGenerator {
		return new TemplateGenerator(
			$services->get( 'SemanticSchemas.PageCreator' ),
			$services->get( 'SemanticSchemas.WikiCategoryStore' ),
			$services->get( 'SemanticSchemas.WikiPropertyStore' )
		);
	},

	'SemanticSchemas.WikiCategoryStore' => static function (
		MediaWikiServices $services
	): WikiCategoryStore {
		return new WikiCategoryStore(
			$services->get( 'SemanticSchemas.PageCreator' ),
			$services->get( 'SemanticSchemas.WikiPropertyStore' ),
			$services->getConnectionProvider(),
			$services->getMainConfig()
		);
	},

	'SemanticSchemas.WikiPropertyStore' => static function (
		MediaWikiServices $services
	): WikiPropertyStore {
		return new WikiPropertyStore(
			$services->get( 'SemanticSchemas.PageCreator' ),
			$services->getConnectionProvider(),
			$services->getContentLanguage()
		);
	},

];
