<?php
// CI test settings for SemanticSchemas integration tests.
// Loaded by the CI workflow after install.php generates LocalSettings.php.

require_once __DIR__ . '/LocalSettings.common.php';

wfLoadExtension( 'SemanticSchemas' );
