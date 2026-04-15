<?php

namespace MediaWiki\Extension\SemanticSchemas\Util;

use MediaWiki\Extension\SemanticSchemas\Schema\FieldDeclaration;

/**
 * SMWDataExtractor
 * -----------------
 * Shared trait for extracting values from SMW SemanticData objects.
 *
 * Used by WikiCategoryStore to avoid code duplication
 * in SMW data extraction logic.
 */
trait SMWDataExtractor {

	/**
	 * Fetch a single value from semantic data.
	 *
	 * @param \SMW\SemanticData $semanticData
	 * @param string $propName Property label
	 * @param string $type Value type: 'text', 'property', 'category', 'page'
	 * @return string|null
	 */
	protected function smwFetchOne( $semanticData, string $propName, string $type = 'text' ): ?string {
		$vals = $this->smwFetchMany( $semanticData, $propName, $type );
		return $vals[0] ?? null;
	}

	/**
	 * Fetch multiple values from semantic data.
	 *
	 * @param \SMW\SemanticData $semanticData
	 * @param string $propName Property label
	 * @param string $type Value type: 'text', 'property', 'category', 'page'
	 * @return array
	 */
	protected function smwFetchMany( $semanticData, string $propName, string $type = 'text' ): array {
		try {
			$prop = \SMW\DIProperty::newFromUserLabel( $propName );
			$items = $semanticData->getPropertyValues( $prop );
		} catch ( \Throwable $e ) {
			return [];
		}

		$out = [];
		foreach ( $items as $di ) {
			$v = $this->smwExtractValue( $di, $type );
			if ( $v !== null ) {
				$out[] = $v;
			}
		}
		return $out;
	}

	/**
	 * Fetch a boolean value from semantic data.
	 *
	 * @param \SMW\SemanticData $semanticData
	 * @param string $propName Property label
	 * @return bool
	 */
	protected function smwFetchBoolean( $semanticData, string $propName ): bool {
		try {
			$prop = \SMW\DIProperty::newFromUserLabel( $propName );
			$items = $semanticData->getPropertyValues( $prop );
		} catch ( \Throwable $e ) {
			return false;
		}

		foreach ( $items as $di ) {
			if ( $di instanceof \SMWDIBoolean ) {
				return $di->getBoolean();
			}
			if ( $di instanceof \SMWDINumber ) {
				return $di->getNumber() > 0;
			}
			if ( $di instanceof \SMWDIBlob || $di instanceof \SMWDIString ) {
				$v = strtolower( trim( $di->getString() ) );
				if ( in_array( $v, [ '1', 'true', 'yes', 'y', 't' ], true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Load properties declaratively from a model's SMW_PROPERTIES map.
	 *
	 * Each entry maps: fieldName => [smwPropertyLabel, type]
	 * Types: 'text', 'property', 'category', 'page' (single value),
	 *        'boolean', 'text[]', 'property[]' etc. (multi-value with [] suffix).
	 *
	 * @param \SMW\SemanticData $semanticData
	 * @param array $propertyMap fieldName => [smwLabel, type]
	 * @return array fieldName => value
	 */
	protected function smwLoadProperties( $semanticData, array $propertyMap ): array {
		$out = [];
		foreach ( $propertyMap as $field => [ $smwLabel, $type ] ) {
			if ( str_ends_with( $type, '[]' ) ) {
				$out[$field] = $this->smwFetchMany(
					$semanticData, $smwLabel, substr( $type, 0, -2 )
				);
			} elseif ( $type === 'boolean' ) {
				$out[$field] = $this->smwFetchBoolean( $semanticData, $smwLabel );
			} else {
				$out[$field] = $this->smwFetchOne( $semanticData, $smwLabel, $type );
			}
		}
		return $out;
	}

	/**
	 * Read ordered FieldDeclaration[] from SMW subobjects attached to a page.
	 *
	 * Iterates sub-semantic-data, filters by @category membership, extracts the
	 * reference property value and the "Is required" boolean, and sorts by
	 * the "Has sort order" property to preserve declaration order.
	 *
	 * @param \SMW\SemanticData $semanticData The parent page's semantic data
	 * @param string $fieldType FieldDeclaration type constant (TYPE_PROPERTY or TYPE_SUBOBJECT)
	 * @return FieldDeclaration[] Ordered list of field declarations
	 */
	protected function smwFetchFieldReferences(
		$semanticData,
		string $fieldType
	): array {
		$config = FieldDeclaration::FIELD_CONFIG[$fieldType];
		$categoryName = $config['category'];
		$referenceProperty = $config['referenceProperty'];
		$referenceType = strtolower( $config['namespacePrefix'] );

		$entries = [];
		$instProp = new \SMW\DIProperty( '_INST' );
		$expectedKey = str_replace( ' ', '_', $categoryName );

		foreach ( $semanticData->getSubSemanticData() as $subData ) {
			$categories = $subData->getPropertyValues( $instProp );
			$matchesCategory = false;
			foreach ( $categories as $cat ) {
				if ( $cat instanceof \SMW\DIWikiPage && $cat->getDBKey() === $expectedKey ) {
					$matchesCategory = true;
					break;
				}
			}
			if ( !$matchesCategory ) {
				continue;
			}

			$ref = $this->smwFetchOne( $subData, $referenceProperty, $referenceType );
			if ( $ref !== null ) {
				$required = $this->smwFetchBoolean( $subData, 'Is required' );
				$sortOrder = (int)( $this->smwFetchOne( $subData, 'Has sort order' ) ?? 0 );
				$entries[] = [
					'field' => new FieldDeclaration( $ref, $required, $fieldType ),
					'sort' => $sortOrder,
				];
			}
		}

		usort( $entries, static fn ( $a, $b ) => $a['sort'] <=> $b['sort'] );

		return array_map( static fn ( $e ) => $e['field'], $entries );
	}

	/**
	 * Extract a value from a SMW DataItem based on type.
	 *
	 * The $type parameter acts as a namespace assertion: when the DataItem is a
	 * DIWikiPage, the method checks that the page belongs to the expected namespace
	 * (e.g. SMW_NS_PROPERTY for 'property', NS_CATEGORY for 'category'). If the
	 * namespace does not match, null is returned. This prevents misconfigured or
	 * cross-namespace annotations from being silently accepted as valid values.
	 *
	 * Supported types:
	 *   - 'text'      — returns the page text (no namespace check)
	 *   - 'property'  — requires SMW_NS_PROPERTY
	 *   - 'category'  — requires NS_CATEGORY
	 *   - 'page'      — returns prefixed text (no namespace check)
	 *
	 * @param \SMW\DataItem $di
	 * @param string $type Value type: 'text', 'property', 'category', 'page'
	 * @return string|null The extracted value, or null if the DataItem type or namespace doesn't match
	 */
	protected function smwExtractValue( $di, string $type ): ?string {
		if ( $di instanceof \SMWDIBlob || $di instanceof \SMWDIString ) {
			return trim( $di->getString() );
		}

		if ( $di instanceof \SMWDINumber ) {
			return (string)$di->getNumber();
		}

		if ( $di instanceof \SMW\DIWikiPage ) {
			$t = $di->getTitle();
			if ( !$t ) {
				return null;
			}

			$text = str_replace( '_', ' ', $t->getText() );

			switch ( $type ) {
				case 'property':
					return $t->getNamespace() === SMW_NS_PROPERTY ? $text : null;

				case 'category':
					return $t->getNamespace() === NS_CATEGORY ? $text : null;

				case 'page':
					return $t->getPrefixedText();

				default:
					return $text;
			}
		}

		return null;
	}
}
