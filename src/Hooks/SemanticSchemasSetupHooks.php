<?php

namespace MediaWiki\Extension\SemanticSchemas\Hooks;

use MediaWiki\Extension\SemanticSchemas\SemanticSchemasServices;
use MediaWiki\MediaWikiServices;

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
	 *
	 * Also registers a hook on SMW's post-table-creation event so that the base
	 * configuration is auto-installed during update.php.
	 */
	public function onSetupAfterCache() {
		global $smwgNamespacesWithSemanticLinks;

		// Enable semantic annotations in the Subobject namespace
		// This allows Subobject pages to use [[Has required property::...]] and similar
		if ( defined( 'NS_SUBOBJECT' ) ) {
			$smwgNamespacesWithSemanticLinks[NS_SUBOBJECT] = true;
		}

		// Register for SMW's post-table-creation hook so we can auto-install
		// the base configuration after update.php creates SMW's tables.
		// SMW hooks cannot be registered via extension.json — they must be
		// registered programmatically via the global HookContainer.
		if ( defined( 'SMW_EXTENSION_LOADED' ) ) {
			$hookContainer = MediaWikiServices::getInstance()->getHookContainer();
			$hookContainer->register(
				'SMW::SQLStore::Installer::AfterCreateTablesComplete',
				[ self::class, 'onAfterCreateTablesComplete' ]
			);
		}
	}

	/**
	 * SMW Hook: SMW::SQLStore::Installer::AfterCreateTablesComplete
	 *
	 * Fires after SMW finishes creating/checking its database tables during
	 * update.php. At this point it is safe to write property pages and
	 * semantic data via the store.
	 *
	 * @param mixed $tableBuilder SMW\SQLStore\TableBuilder (loose type to avoid hard autoload dep)
	 * @param mixed $messageReporter Onoi\MessageReporter\MessageReporter
	 * @param mixed $options SMW\Options or null
	 * @return bool
	 */
	public static function onAfterCreateTablesComplete( $tableBuilder, $messageReporter, $options ) {
		$configPath = __DIR__ . '/../../resources/extension-config.json';

		$services = MediaWikiServices::getInstance();
		$installer = SemanticSchemasServices::getExtensionConfigInstaller( $services );

		if ( $installer->isInstalled() ) {
			$messageReporter->reportMessage(
				"\n   ... SemanticSchemas base configuration already installed, skipping.\n"
			);
			return true;
		}

		$messageReporter->reportMessage( "\n   ... installing SemanticSchemas base configuration...\n" );

		try {
			$result = $installer->applyFromFile( $configPath );

			$created = array_sum( array_map( 'count', $result['created'] ) );
			$updated = array_sum( array_map( 'count', $result['updated'] ) );
			$failed = array_sum( array_map( 'count', $result['failed'] ) );

			$messageReporter->reportMessage(
				"   ... done (created: $created, updated: $updated, failed: $failed).\n"
			);

			if ( $result['errors'] ) {
				foreach ( $result['errors'] as $error ) {
					$messageReporter->reportMessage( "   ... error: $error\n" );
				}
			}
		} catch ( \Exception $e ) {
			// Never block update.php — log the error and continue.
			$messageReporter->reportMessage(
				"   ... SemanticSchemas base config install failed: " . $e->getMessage() . "\n"
			);
		}

		return true;
	}

	/**
	 * Hook: LoadExtensionSchemaUpdates
	 *
	 * Invoked from maintenance/update.php. Base configuration auto-install is
	 * handled by onAfterCreateTablesComplete() which fires after SMW's tables
	 * are ready, so this hook is intentionally a no-op.
	 *
	 * @param mixed $updater DatabaseUpdater (not used directly)
	 * @return bool
	 */
	public function onLoadExtensionSchemaUpdates( $updater ): bool {
		return true;
	}

}
