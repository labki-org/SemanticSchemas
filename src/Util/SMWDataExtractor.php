<?php

namespace MediaWiki\Extension\SemanticSchemas\Util;

/**
 * SMWDataExtractor
 * -----------------
 * Shared trait for extracting values from SMW SemanticData objects.
 *
 * Used by WikiCategoryStore and WikiPropertyStore to avoid code duplication
 * in SMW data extraction logic.
 */
trait SMWDataExtractor {

	/**
	 * Fetch a single value from semantic data.
	 *
	 * @param \SMW\SemanticData $semanticData
	 * @param string $propName Property label
	 * @param string $type Value type: 'text', 'property', 'category', 'subobject', 'page'
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
	 * @param string $type Value type: 'text', 'property', 'category', 'subobject', 'page'
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
	 * Extract a value from a SMW DataItem based on type.
	 *
	 * @param \SMW\DataItem $di
	 * @param string $type Value type: 'text', 'property', 'category', 'subobject', 'page'
	 * @return string|null
	 */
	protected function smwExtractValue( $di, string $type ): ?string {
		if ( $di instanceof \SMWDIBlob || $di instanceof \SMWDIString ) {
			return trim( $di->getString() );
		}

		if ( $di instanceof \SMW\DIWikiPage ) {
			$t = $di->getTitle();
			if ( !$t ) {
				return null;
			}

			$text = str_replace( '_', ' ', $t->getText() );

			switch ( $type ) {
				case 'property':
					// SMW Property namespace is usually 102
					return $t->getNamespace() === 102 ? $text : null;

				case 'category':
					// Category namespace is 14
					return $t->getNamespace() === 14 ? $text : null;

				case 'subobject':
					// Accept any namespace for subobjects
					return $text;

				case 'page':
					return $t->getPrefixedText();

				default:
					return $text;
			}
		}

		return null;
	}
}
