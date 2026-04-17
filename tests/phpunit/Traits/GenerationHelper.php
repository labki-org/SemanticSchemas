<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Traits;

use MediaWiki\Extension\SemanticSchemas\Schema\FieldModel;

trait GenerationHelper {
	/**
	 * Generate the wikitext subobject block for this field declaration.
	 *
	 * Uses anonymous subobjects (SMW assigns a stable hash-based ID) with an
	 * explicit sort order property so that #ask queries can preserve ordering.
	 *
	 * @param int $index 1-based position index used for sort ordering
	 * @return string Complete {{#subobject:...}} block
	 */
	public static function fieldToWikitext( FieldModel $field, int $index ): string {
		$config = FieldModel::FIELD_CONFIG[$field->getFieldType()];

		$lines = [];
		$lines[] = '{{#subobject:';
		$lines[] = ' |@category=' . $config['category'];
		$lines[] = ' | ' . $config['referenceProperty'] . ' = ' . $config['namespacePrefix'] . ':' . $field->getName();
		$lines[] = ' | Is required = ' . ( $field->isRequired() ? 'true' : 'false' );
		$lines[] = ' | Has sort order = ' . $index;
		$lines[] = '}}';

		return implode( "\n", $lines );
	}
}
