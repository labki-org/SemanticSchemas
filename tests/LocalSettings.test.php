<?php
// Server Config
$wgServer = 'http://localhost:8889';

// SemanticSchemas
wfLoadExtension( 'SemanticSchemas', '/mw-user-extensions/SemanticSchemas/extension.json' );

// Debugging
$wgDebugLogGroups['semanticschemas'] = '/var/log/mediawiki/semanticschemas.log';
$wgDebugLogFile = '/var/log/mediawiki/debug.log';

// Cache
$wgCacheDirectory = "$IP/cache-semanticschemas";

// Skin
wfLoadSkin( 'Vector' );
$wgDefaultSkin = 'vector';
