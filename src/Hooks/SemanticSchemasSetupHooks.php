<?php

namespace MediaWiki\Extension\SemanticSchemas\Hooks;

/**
 * SemanticSchemasSetupHooks
 *
 * Extension setup hooks for SemanticSchemas that configure MediaWiki and SMW
 * for proper operation with our custom namespaces.
 */
class SemanticSchemasSetupHooks {

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

	/**
	 * Hook: LoadExtensionSchemaUpdates
	 *
	 * Invoked from maintenance/update.php. We deliberately do NOT auto-install
	 * the base configuration here because:
	 *
	 * 1. SMW's own table setup runs during update.php, and creating Properties
	 *    triggers SMW operations that conflict with the ongoing setup.
	 * 2. DeferredUpdates still run within the same process, causing the same issues.
	 * 3. The extension may not be fully initialized when this hook fires.
	 *
	 * Instead, users should:
	 * - Use the "Install Base Configuration" UI at Special:SemanticSchemas
	 * - Or run: php maintenance/run.php SemanticSchemas:InstallConfig
	 *
	 * @param mixed $updater DatabaseUpdater (not used directly)
	 * @return bool
	 */
	public static function onLoadExtensionSchemaUpdates( $updater ): bool {
		// No automatic installation - see docblock above for rationale.
		// The Special:SemanticSchemas page will show a banner prompting
		// users to install the base configuration if it's missing.
		return true;
	}

}
