<?php

namespace MediaWiki\Extension\StructureSync\Hooks;

/**
 * StructureSyncSetupHooks
 * 
 * Extension setup hooks for StructureSync that configure MediaWiki and SMW
 * for proper operation with our custom namespaces.
 */
class StructureSyncSetupHooks {

	/**
	 * Hook: SetupAfterCache
	 * 
	 * Called early in the setup process, after configuration is loaded but before
	 * extensions are fully initialized. This is the right place to modify global
	 * configuration variables.
	 * 
	 * We use this to tell Semantic MediaWiki that the Subobject namespace should
	 * have semantic annotations enabled. Without this, SMW won't parse [[Property::value]]
	 * annotations on Subobject: pages.
	 */
	public static function onSetupAfterCache() {
		global $smwgNamespacesWithSemanticLinks;
		
		// Enable semantic annotations in the Subobject namespace
		// This allows Subobject pages to use [[Has required property::...]] and similar
		if ( defined( 'NS_SUBOBJECT' ) ) {
			$smwgNamespacesWithSemanticLinks[NS_SUBOBJECT] = true;
		}
	}
}

