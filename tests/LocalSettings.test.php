<?php
// Server Config
$wgServer = 'http://localhost:8889';

// Load Platform Extensions Manualy (since we disabled auto-loading)

// Load SMW
wfLoadExtension('SemanticMediaWiki');
enableSemantics('localhost'); // Required to activate SMW

// SMW Satellites
wfLoadExtension('SemanticResultFormats');
// SemanticCompoundQueries might be autoloaded by SMW or Composer, but explicit load is safe if in vendor
wfLoadExtension('SemanticCompoundQueries');
wfLoadExtension('SemanticExtraSpecialProperties');

// Core/Utility Extensions
wfLoadExtension('PageForms');
wfLoadExtension('ParserFunctions');
wfLoadExtension('Maps');
wfLoadExtension('Mermaid');
wfLoadExtension('Bootstrap');

// Labki Extensions (Git Cloned into extensions/)
wfLoadExtension('MsUpload');
wfLoadExtension('PageSchemas');
wfLoadExtension('Lockdown');

// Load SemanticSchemas
wfLoadExtension('SemanticSchemas', '/mw-user-extensions/SemanticSchemas/extension.json');

// Configuration
$wgDebugLogGroups['semanticschemas'] = '/var/log/mediawiki/semanticschemas.log';
$wgShowExceptionDetails = true;
$wgDebugDumpSql = false;
$wgDebugLogFile = '/var/log/mediawiki/debug.log'; // Send other logs to file instead of stdout

// SMW Configuration (from old script)
// enableSemantics('localhost'); // Already called above
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

// Example Hook for PageForms on Category
$wgHooks['SkinTemplateNavigation::Universal'][] = function ($skin, &$links) {
    $title = $skin->getTitle();
    if ($title && $title->inNamespace(NS_CATEGORY) && $title->exists()) {
        $form = "Category";
        $links['views']['formedit'] = [
            'class' => false,
            'text' => "Edit with form",
            'href' => SpecialPage::getTitleFor("FormEdit", $form . "/" . $title->getPrefixedText())->getLocalURL(),
        ];
    }
};

// skin
wfLoadSkin('Citizen');
wfLoadSkin('Vector');
$wgDefaultSkin = 'vector';
