<?php

namespace MediaWiki\Extension\SemanticSchemas\Hooks;

use MediaWiki\Extension\SemanticSchemas\Schema\ExtensionConfigInstaller;

/**
 * SemanticSchemasSetupHooks
 * 
 * Extension setup hooks for SemanticSchemas that configure MediaWiki and SMW
 * for proper operation with our custom namespaces.
 */
class SemanticSchemasSetupHooks
{

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
	public static function onSetupAfterCache()
	{
		global $smwgNamespacesWithSemanticLinks;

		// Enable semantic annotations in the Subobject namespace
		// This allows Subobject pages to use [[Has required property::...]] and similar
		if (defined('NS_SUBOBJECT')) {
			$smwgNamespacesWithSemanticLinks[NS_SUBOBJECT] = true;
		}
	}

	/**
	 * Hook: LoadExtensionSchemaUpdates
	 *
	 * Invoked from maintenance/update.php. This is a convenient place to apply
	 * the bundled extension configuration so that required schema pages
	 * (Category:, Property:, Subobject:) are created or updated when the
	 * extension is installed or upgraded.
	 *
	 * @param mixed $updater DatabaseUpdater (not used directly)
	 * @return bool
	 */
	public static function onLoadExtensionSchemaUpdates($updater): bool
	{
		// Note: We deliberately DO NOT run the installer here anymore.
		// Doing so causes transaction conflicts ("Uncommitted DB writes") because
		// we are inside a transaction started by update.php, and constructing
		// Categories/Properties triggers complex SMW updates that expect a clean state.
		//
		// Instead, use maintenance/installConfig.php to run this manually.

		return true;
	}

}

