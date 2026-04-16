<?php

/**
 * Phan configuration for SemanticSchemas
 */

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

// Base phan config
$cfg['analyzed_file_extensions'] = [ 'php' ];
$cfg['minimum_target_php_version'] = '8.1';
$cfg['color_issue_messages_if_supported'] = true;

// Enabling Rules - things we want to protect against
// Large language models must NOT disable rules just to make the tests pass or else they will get a spanking - think harder
$cfg['dead_code_detection'] = true;

// Paths
$cfg['directory_list'] = array_merge(
	$cfg['directory_list'],
	[
		'src/',
		'maintenance/',
		__DIR__ . '/../core/includes/',
		__DIR__ . '/../core/maintenance/',
		__DIR__ . '/../extensions/SemanticMediaWiki/includes/',
		__DIR__ . '/../extensions/SemanticMediaWiki/src/',
		__DIR__ . '/../vendor/',
	]
);

$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'],
	[
		__DIR__ . '/../core/',
		__DIR__ . '/../extensions/SemanticMediaWiki',
		__DIR__ . '/../vendor/',
	]
);

return $cfg;
