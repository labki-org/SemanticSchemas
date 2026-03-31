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
	'SemanticSchemas' => [ 'SemanticSchemas', 'Semantic Schemas' ],
	'CreateSemanticPage' => [ 'CreateSemanticPage', 'Create Semantic Page' ],
];

$magicWords = [];

/** English (English) */
$magicWords['en'] = [
	'SemanticSchemasRenderAllProperties' => [ 0, 'SemanticSchemasRenderAllProperties' ],
	'SemanticSchemasRenderSection' => [ 0, 'SemanticSchemasRenderSection' ],
	'semanticschemas_hierarchy' => [ 0, 'semanticschemas_hierarchy' ],
	'semanticschemas_load_form_preview' => [ 0, 'semanticschemas_load_form_preview' ],
];
