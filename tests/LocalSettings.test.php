<?php
// Server Config
$wgServer = 'http://localhost:8889';

// Semantic MediaWiki
wfLoadExtension( 'SemanticMediaWiki' );
enableSemantics( 'localhost' );

// Extensions
wfLoadExtension( 'PageForms' );
wfLoadExtension( 'ParserFunctions' );

// SemanticSchemas
wfLoadExtension( 'SemanticSchemas', '/mw-user-extensions/SemanticSchemas/extension.json' );

// Debugging
$wgDebugLogGroups['semanticschemas'] = '/var/log/mediawiki/semanticschemas.log';
$wgShowExceptionDetails = true;
$wgDebugLogFile = '/var/log/mediawiki/debug.log';

// SMW Configuration
$smwgChangePropagationProtection = false;
$smwgEnabledDeferredUpdate = false;
$smwgAutoSetupStore = false;
$smwgQMaxInlineLimit = 500;

// PageForms Configuration
$wgPageFormsAllowCreateInRestrictedNamespaces = true;
$wgPageFormsLinkAllRedLinksToForms = true;
$wgPageFormsFormCacheType = CACHE_NONE;
$wgNamespacesWithSemanticLinks[NS_CATEGORY] = true;

// Cache
$wgCacheDirectory = "$IP/cache-semanticschemas";

// Skin
wfLoadSkin( 'Vector' );
$wgDefaultSkin = 'vector';
