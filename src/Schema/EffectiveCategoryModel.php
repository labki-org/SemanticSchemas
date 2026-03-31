<?php

namespace MediaWiki\Extension\SemanticSchemas\Schema;

/**
 * A CategoryModel that has been through inheritance resolution.
 *
 * Contains the fully merged properties, subobjects, and display/form config
 * from the entire ancestor chain. Returned by InheritanceResolver::getEffectiveCategory().
 *
 * This is a type-level marker: methods that accept EffectiveCategoryModel
 * declare that they need the merged view, while methods accepting CategoryModel
 * operate on a single category's own declared data.
 */
class EffectiveCategoryModel extends CategoryModel {
}
