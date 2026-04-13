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
	 * Register the base-config directory with SMW's content importer.
	 */
	public function onSetupAfterCache() {
		global $smwgImportFileDirs;

		if ( defined( 'SMW_EXTENSION_LOADED' ) ) {
			$smwgImportFileDirs['semanticschemas'] = __DIR__ . '/../../resources/base-config';
		}
	}

}
