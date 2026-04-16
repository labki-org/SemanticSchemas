<?php

/**
 * Phan configuration for SemanticSchemas
 */

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['analyzed_file_extensions'] = [ 'php' ];
$cfg['minimum_target_php_version'] = '8.1';

$cfg['directory_list'] = array_merge(
	$cfg['directory_list'],
	[
		'src/',
		'maintenance/',
		__DIR__ . '/../core/includes/',
		__DIR__ . '/../extensions/SemanticMediaWiki/includes/',
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
