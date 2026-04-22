<?php
// Server Config
$wgServer = 'http://localhost:8889';

// SemanticSchemas
wfLoadExtension( 'SemanticSchemas', '/mw-user-extensions/SemanticSchemas/extension.json' );

// Local test-data fixture (Library/Books domain). Imported into pages on
// `maintenance/run.php update` via SMW's ImportFilesBuilder.
$smwgImportFileDirs['testdata'] =
	'/mw-user-extensions/SemanticSchemas/tests/fixtures/testdata';

// Debugging
$wgDebugLogGroups['semanticschemas'] = '/var/log/mediawiki/semanticschemas.log';
$wgDebugLogFile = '/var/log/mediawiki/debug.log';

// Cache
$wgCacheDirectory = "$IP/cache-semanticschemas";

// Skin
wfLoadSkin( 'Vector' );
$wgDefaultSkin = 'vector';
