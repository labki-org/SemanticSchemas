<?php

/**
 * Phan configuration for SemanticSchemas
 */

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['directory_list'] = array_merge(
	$cfg['directory_list'],
	[
		'src/',
		'maintenance/',
		'../../extensions/SemanticMediaWiki',
		'../../extensions/PageForms',
	]
);

$cfg['exclude_analysis_directory_list'] = array_merge(
	$cfg['exclude_analysis_directory_list'],
	[
		'../../extensions/SemanticMediaWiki',
		'../../extensions/PageForms',
		'vendor/',
	]
);

$cfg['analyzed_file_extensions'] = ['php'];

return $cfg;

