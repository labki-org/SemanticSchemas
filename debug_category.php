<?php

use MediaWiki\Extension\StructureSync\Store\WikiCategoryStore;
use MediaWiki\Extension\StructureSync\Schema\InheritanceResolver;

$IP = '/var/www/html/w';
require_once "$IP/maintenance/Maintenance.php";

class DebugCategory extends Maintenance
{
    public function __construct()
    {
        parent::__construct();
        $this->requireExtension('StructureSync');
    }

    public function execute()
    {
        $store = new WikiCategoryStore();

        $this->output("--- Debugging Person Category ---\n");
        $person = $store->readCategory('Person');

        if (!$person) {
            $this->output("Person category not found!\n");
            return;
        }

        $this->output("Parents: " . implode(', ', $person->getParents()) . "\n");
        $this->output("Direct Properties: " . implode(', ', $person->getAllProperties()) . "\n");

        $this->output("\n--- Debugging Inheritance ---\n");
        $all = $store->getAllCategories();
        $map = [];
        foreach ($all as $c) {
            $map[$c->getName()] = $c;
        }

        $resolver = new InheritanceResolver($map);
        $ancestors = $resolver->getAncestors('Person');
        $this->output("Ancestors: " . implode(', ', $ancestors) . "\n");

        $effective = $resolver->getEffectiveCategory('Person');
        $this->output("Effective Properties: " . implode(', ', $effective->getAllProperties()) . "\n");
    }
}

$maintClass = DebugCategory::class;
require_once RUN_MAINTENANCE_IF_MAIN;