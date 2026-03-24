<?php

namespace MediaWiki\Extension\SemanticSchemas\Hooks;

/**
 * Extension setup hooks for SemanticSchemas that configure MediaWiki and SMW
 * for proper operation with our custom namespaces.
 */
class SemanticSchemasSetupHooks {

	/**
	 * Hook: SetupAfterCache
	 *
	 * Enable semantic annotations in the Subobject namespace and register
	 * the base-config directory with SMW's content importer.
	 */
	public function onSetupAfterCache() {
		global $smwgNamespacesWithSemanticLinks, $smwgImportFileDirs;

		if ( defined( 'NS_SUBOBJECT' ) ) {
			$smwgNamespacesWithSemanticLinks[NS_SUBOBJECT] = true;
		}

		if ( defined( 'SMW_EXTENSION_LOADED' ) ) {
			$smwgImportFileDirs['semanticschemas'] = __DIR__ . '/../../resources/base-config';
		}
	}

}
