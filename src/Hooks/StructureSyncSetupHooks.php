<?php

namespace MediaWiki\Extension\StructureSync\Hooks;

use MediaWiki\Extension\StructureSync\Schema\ExtensionConfigInstaller;

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
	public static function onLoadExtensionSchemaUpdates( $updater ): bool {
		// Locate the bundled config file relative to the extension root.
		$root = dirname( __DIR__, 2 );
		$configPath = $root . '/resources/extension-config.json';

		if ( !file_exists( $configPath ) ) {
			return true;
		}

		$installer = new ExtensionConfigInstaller();
		$result = $installer->applyFromFile( $configPath );

		// Log validation errors, if any, but don't fail the schema update run.
		if ( !empty( $result['errors'] ) && function_exists( 'wfLogWarning' ) ) {
			foreach ( $result['errors'] as $msg ) {
				wfLogWarning( "StructureSync extension-config error: $msg" );
			}
		}

		return true;
	}
}

