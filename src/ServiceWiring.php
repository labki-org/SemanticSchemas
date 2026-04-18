<?php

use MediaWiki\Extension\SemanticSchemas\Generator\FormGenerator;
use MediaWiki\Extension\SemanticSchemas\Generator\PropertyInputMapper;
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
 * @phpcs-require-sorted-array
 * @phan-file-suppress PhanUnreferencedClosure
 */
return [

	'SemanticSchemas.CategoryHierarchyService' => static function (
		MediaWikiServices $services
	): CategoryHierarchyService {
		return new CategoryHierarchyService(
			$services->get( 'SemanticSchemas.WikiCategoryStore' )
		);
	},

	'SemanticSchemas.FormGenerator' => static function (
		MediaWikiServices $services
	): FormGenerator {
		return new FormGenerator(
			$services->get( 'SemanticSchemas.PageCreator' ),
			$services->get( 'SemanticSchemas.WikiPropertyStore' ),
			$services->get( 'SemanticSchemas.PropertyInputMapper' ),
		);
	},

	'SemanticSchemas.OntologyInspector' => static function (
		MediaWikiServices $services
	): OntologyInspector {
		return new OntologyInspector(
			$services->get( 'SemanticSchemas.WikiCategoryStore' ),
			$services->get( 'SemanticSchemas.WikiPropertyStore' ),
			$services->get( 'SemanticSchemas.StateManager' ),
			$services->get( 'SemanticSchemas.SchemaValidator' )
		);
	},

	'SemanticSchemas.PageCreator' => static function (
		MediaWikiServices $services
	): PageCreator {
		return new PageCreator(
			$services->getWikiPageFactory(),
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
			$services->get( 'SemanticSchemas.WikiPropertyStore' ),
			$services->getContentLanguage()
		);
	},

	'SemanticSchemas.WikiCategoryStore' => static function (
		MediaWikiServices $services
	): WikiCategoryStore {
		return new WikiCategoryStore(
			$services->get( 'SemanticSchemas.PageCreator' ),
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
		);
	},

];
