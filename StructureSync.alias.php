<?php
/**
 * Aliases for special pages
 *
 * @file
 * @ingroup Extensions
 */

if ( !defined( 'NS_SUBOBJECT' ) ) {
	define( 'NS_SUBOBJECT', 3300 );
}
if ( !defined( 'NS_SUBOBJECT_TALK' ) ) {
	define( 'NS_SUBOBJECT_TALK', 3301 );
}

if ( !defined( 'PF_NS_FORM' ) ) {
	define( 'PF_NS_FORM', 106 );
}

$specialPageAliases = [];

/** English (English) */
$specialPageAliases['en'] = [
	'StructureSync' => ['StructureSync', 'Structure Sync'],
];

$magicWords = [];

/** English (English) */
$magicWords['en'] = [
	'StructureSyncRenderAllProperties' => [0, 'StructureSyncRenderAllProperties'],
	'StructureSyncRenderSection' => [0, 'StructureSyncRenderSection'],
	'structuresync_hierarchy' => [0, 'structuresync_hierarchy'],
	'structuresync_load_form_preview' => [0, 'structuresync_load_form_preview'],
];

