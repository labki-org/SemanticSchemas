<?php
// CI test settings for SemanticSchemas integration tests.
// Loaded by the CI workflow after install.php generates LocalSettings.php.

wfLoadExtension( 'SemanticMediaWiki' );
enableSemantics( 'localhost' );
wfLoadExtension( 'PageForms' );
wfLoadExtension( 'ParserFunctions' );
wfLoadExtension( 'SemanticSchemas' );

$smwgChangePropagationProtection = false;
$smwgEnabledDeferredUpdate = false;
$smwgAutoSetupStore = false;
$smwgQMaxInlineLimit = 500;

$wgPageFormsAllowCreateInRestrictedNamespaces = true;
$wgPageFormsLinkAllRedLinksToForms = true;
$wgPageFormsFormCacheType = CACHE_NONE;
$wgNamespacesWithSemanticLinks[NS_CATEGORY] = true;

$wgShowExceptionDetails = true;
