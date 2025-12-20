<?php

namespace MediaWiki\Extension\StructureSync\Maintenance;

use Maintenance;
use MediaWiki\Extension\StructureSync\Schema\ExtensionConfigInstaller;

$IP = getenv('MW_INSTALL_PATH');
if ($IP === false) {
    $IP = '/var/www/html';
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Maintenance script to manually run the ExtensionConfigInstaller.
 * This replaces the automatic hook execution to avoid transaction conflicts during boot.
 */
class InstallConfig extends Maintenance
{

    public function __construct()
    {
        parent::__construct();
        $this->addDescription('Install StructureSync configuration (Categories, Properties, etc.)');
        $this->requireExtension('StructureSync');
    }

    public function execute()
    {
        $this->output("Installing StructureSync configuration...\n");

        // Locate the bundled config file relative to the extension root.
        $root = dirname(__DIR__, 1);
        $configPath = $root . '/resources/extension-config.json';

        if (!file_exists($configPath)) {
            $this->fatalError("Config file not found: $configPath");
        }

        $installer = new ExtensionConfigInstaller();
        $result = $installer->applyFromFile($configPath);

        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $msg) {
                $this->output("Error: $msg\n");
            }
        }

        if (!empty($result['warnings'])) {
            foreach ($result['warnings'] as $msg) {
                $this->output("Warning: $msg\n");
            }
        }

        $this->output("Configuration installation complete.\n");
    }
}

$maintClass = InstallConfig::class;
require_once RUN_MAINTENANCE_IF_MAIN;
