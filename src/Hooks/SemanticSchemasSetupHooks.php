<?php

namespace MediaWiki\Extension\SemanticSchemas\Hooks;

use MediaWiki\Extension\SemanticSchemas\SemanticSchemasServices;
use MediaWiki\MediaWikiServices;

/**
 * Extension setup hooks for SemanticSchemas that configure MediaWiki and SMW
 * for proper operation with our custom namespaces.
 */
class SemanticSchemasSetupHooks {

	/**
	 * Hook: SetupAfterCache
	 *
	 * Enable semantic annotations in the Subobject namespace and register
	 * the SMW hook for auto-installing base config during update.php.
	 */
	public function onSetupAfterCache() {
		global $smwgNamespacesWithSemanticLinks;

		if ( defined( 'NS_SUBOBJECT' ) ) {
			$smwgNamespacesWithSemanticLinks[NS_SUBOBJECT] = true;
		}

		// Register SMW hook for auto-install during update.php
		if ( defined( 'SMW_EXTENSION_LOADED' ) ) {
			$hookContainer = MediaWikiServices::getInstance()->getHookContainer();
			$hookContainer->register(
				'SMW::SQLStore::Installer::AfterCreateTablesComplete',
				[ self::class, 'onAfterCreateTablesComplete' ]
			);
		}
	}

	/**
	 * Hook: SMW::SQLStore::Installer::AfterCreateTablesComplete
	 *
	 * Auto-install base configuration after SMW's tables are ready.
	 * This fires during update.php after SMW has set up its tables.
	 *
	 * @param mixed $tableBuilder
	 * @param mixed $messageReporter
	 * @param mixed $options
	 * @return bool
	 */
	public static function onAfterCreateTablesComplete( $tableBuilder, $messageReporter, $options ) {
		$services = MediaWikiServices::getInstance();
		$installer = SemanticSchemasServices::getExtensionConfigInstaller( $services );

		if ( $installer->isInstalled() ) {
			$messageReporter->reportMessage(
				"\n   ... SemanticSchemas base configuration already installed.\n"
			);
			return true;
		}

		$messageReporter->reportMessage( "\n   ... installing SemanticSchemas base configuration...\n" );

		try {
			$result = $installer->install();
			$created = array_sum( array_map( 'count', $result['created'] ) );
			$updated = array_sum( array_map( 'count', $result['updated'] ) );
			$failed = array_sum( array_map( 'count', $result['failed'] ) );

			$messageReporter->reportMessage(
				"   ... done (created: $created, updated: $updated, failed: $failed).\n"
			);

			foreach ( $result['errors'] as $error ) {
				$messageReporter->reportMessage( "   ... error: $error\n" );
			}
		} catch ( \Exception $e ) {
			// Never block update.php
			$messageReporter->reportMessage(
				"   ... SemanticSchemas install failed: " . $e->getMessage() . "\n"
			);
		}

		return true;
	}

	/**
	 * Hook: LoadExtensionSchemaUpdates
	 *
	 * No-op: SMW tables may not exist yet when this fires.
	 * Base config is installed via the SMW hook above instead.
	 *
	 * @param mixed $updater
	 * @return bool
	 */
	public function onLoadExtensionSchemaUpdates( $updater ): bool {
		return true;
	}

}
