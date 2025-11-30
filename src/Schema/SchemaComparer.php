<?php

namespace MediaWiki\Extension\StructureSync\Schema;

/**
 * SchemaComparer
 * --------------
 * Compares two StructureSync schema arrays and produces a structured diff.
 *
 * Conventions:
 *   - $schemaA is treated as the "new" schema (e.g. from an imported file)
 *   - $schemaB is treated as the "old" schema (e.g. current wiki state)
 *
 * The top-level compare() result has this shape:
 *
 * [
 *   'categories' => [
 *     'added'    => [ [ 'name' => 'X', 'data' => [...] ], ... ],
 *     'removed'  => [ [ 'name' => 'Y', 'data' => [...] ], ... ],
 *     'modified' => [ [
 *         'name' => 'Z',
 *         'diff' => [ ... field-by-field diffs ... ],
 *         'old'  => [...],   // from $schemaB
 *         'new'  => [...],   // from $schemaA
 *     ], ... ],
 *     'unchanged' => [ 'Name1', 'Name2', ... ],
 *   ],
 *   'properties' => [ ... same structure ... ],
 * ]
 */
class SchemaComparer {

	/**
	 * Compare two full schema arrays and return a structured diff.
	 *
	 * @param array $schemaA "New" schema (usually from file)
	 * @param array $schemaB "Old" schema (usually from wiki)
	 * @return array
	 */
	public function compare( array $schemaA, array $schemaB ): array {
		$categoriesA = $schemaA['categories'] ?? [];
		$categoriesB = $schemaB['categories'] ?? [];

		$propertiesA = $schemaA['properties'] ?? [];
		$propertiesB = $schemaB['properties'] ?? [];
		$subobjectsA = $schemaA['subobjects'] ?? [];
		$subobjectsB = $schemaB['subobjects'] ?? [];

		return [
			'categories' => $this->compareCategories( $categoriesA, $categoriesB ),
			'properties' => $this->compareProperties( $propertiesA, $propertiesB ),
			'subobjects' => $this->compareSubobjects( $subobjectsA, $subobjectsB ),
		];
	}

	/**
	 * Compare categories between two schema maps.
	 *
	 * @param array<string,array> $categoriesA  name => data (new)
	 * @param array<string,array> $categoriesB  name => data (old)
	 * @return array
	 */
	private function compareCategories( array $categoriesA, array $categoriesB ): array {
		$added = [];
		$removed = [];
		$modified = [];
		$unchanged = [];

		$allNames = array_unique( array_merge(
			array_keys( $categoriesA ),
			array_keys( $categoriesB )
		) );
		sort( $allNames );

		foreach ( $allNames as $categoryName ) {
			$inA = array_key_exists( $categoryName, $categoriesA );
			$inB = array_key_exists( $categoryName, $categoriesB );

			if ( $inA && !$inB ) {
				// New category introduced in schemaA
				$added[] = [
					'name' => $categoryName,
					'data' => $categoriesA[$categoryName],
				];
				continue;
			}

			if ( !$inA && $inB ) {
				// Category removed in schemaA
				$removed[] = [
					'name' => $categoryName,
					'data' => $categoriesB[$categoryName],
				];
				continue;
			}

			// Exists in both; compare field-by-field
			$dataA = $categoriesA[$categoryName] ?? [];
			$dataB = $categoriesB[$categoryName] ?? [];

			$diff = $this->diffCategoryData( $dataA, $dataB );
			if ( !empty( $diff ) ) {
				$modified[] = [
					'name' => $categoryName,
					'diff' => $diff,
					'old'  => $dataB,
					'new'  => $dataA,
				];
			} else {
				$unchanged[] = $categoryName;
			}
		}

		return [
			'added'     => $added,
			'removed'   => $removed,
			'modified'  => $modified,
			'unchanged' => $unchanged,
		];
	}

	/**
	 * Compare properties between two schema maps.
	 *
	 * @param array<string,array> $propertiesA name => data (new)
	 * @param array<string,array> $propertiesB name => data (old)
	 * @return array
	 */
	private function compareProperties( array $propertiesA, array $propertiesB ): array {
		$added = [];
		$removed = [];
		$modified = [];
		$unchanged = [];

		$allNames = array_unique( array_merge(
			array_keys( $propertiesA ),
			array_keys( $propertiesB )
		) );
		sort( $allNames );

		foreach ( $allNames as $propertyName ) {
			$inA = array_key_exists( $propertyName, $propertiesA );
			$inB = array_key_exists( $propertyName, $propertiesB );

			if ( $inA && !$inB ) {
				$added[] = [
					'name' => $propertyName,
					'data' => $propertiesA[$propertyName],
				];
				continue;
			}

			if ( !$inA && $inB ) {
				$removed[] = [
					'name' => $propertyName,
					'data' => $propertiesB[$propertyName],
				];
				continue;
			}

			$dataA = $propertiesA[$propertyName] ?? [];
			$dataB = $propertiesB[$propertyName] ?? [];

			$diff = $this->diffPropertyData( $dataA, $dataB );
			if ( !empty( $diff ) ) {
				$modified[] = [
					'name' => $propertyName,
					'diff' => $diff,
					'old'  => $dataB,
					'new'  => $dataA,
				];
			} else {
				$unchanged[] = $propertyName;
			}
		}

		return [
			'added'     => $added,
			'removed'   => $removed,
			'modified'  => $modified,
			'unchanged' => $unchanged,
		];
	}

	/**
	 * @param array<string,array> $subobjectsA
	 * @param array<string,array> $subobjectsB
	 * @return array
	 */
	private function compareSubobjects( array $subobjectsA, array $subobjectsB ): array {
		$added = [];
		$removed = [];
		$modified = [];
		$unchanged = [];

		$allNames = array_unique( array_merge(
			array_keys( $subobjectsA ),
			array_keys( $subobjectsB )
		) );
		sort( $allNames );

		foreach ( $allNames as $subobjectName ) {
			$inA = array_key_exists( $subobjectName, $subobjectsA );
			$inB = array_key_exists( $subobjectName, $subobjectsB );

			if ( $inA && !$inB ) {
				$added[] = [
					'name' => $subobjectName,
					'data' => $subobjectsA[$subobjectName],
				];
				continue;
			}

			if ( !$inA && $inB ) {
				$removed[] = [
					'name' => $subobjectName,
					'data' => $subobjectsB[$subobjectName],
				];
				continue;
			}

			$dataA = $subobjectsA[$subobjectName] ?? [];
			$dataB = $subobjectsB[$subobjectName] ?? [];

			$diff = $this->diffSubobjectData( $dataA, $dataB );
			if ( !empty( $diff ) ) {
				$modified[] = [
					'name' => $subobjectName,
					'diff' => $diff,
					'old'  => $dataB,
					'new'  => $dataA,
				];
			} else {
				$unchanged[] = $subobjectName;
			}
		}

		return [
			'added'     => $added,
			'removed'   => $removed,
			'modified'  => $modified,
			'unchanged' => $unchanged,
		];
	}

	/**
	 * Field-level diff for a single category record.
	 *
	 * @param array $dataA New data (schemaA)
	 * @param array $dataB Old data (schemaB)
	 * @return array Differences keyed by field name
	 */
	private function diffCategoryData( array $dataA, array $dataB ): array {
		$diff = [];

		// parents (order-insensitive)
		$parentsA = $dataA['parents'] ?? [];
		$parentsB = $dataB['parents'] ?? [];
		if ( $this->arraysDiffer( $parentsA, $parentsB ) ) {
			$diff['parents'] = [
				'old' => $parentsB,
				'new' => $parentsA,
			];
		}

		// label
		$labelA = $dataA['label'] ?? '';
		$labelB = $dataB['label'] ?? '';
		if ( $labelA !== $labelB ) {
			$diff['label'] = [
				'old' => $labelB,
				'new' => $labelA,
			];
		}

		// description
		$descA = $dataA['description'] ?? '';
		$descB = $dataB['description'] ?? '';
		if ( $descA !== $descB ) {
			$diff['description'] = [
				'old' => $descB,
				'new' => $descA,
			];
		}

		// properties: required / optional (order-insensitive)
		$propsA = $dataA['properties'] ?? [];
		$propsB = $dataB['properties'] ?? [];

		$requiredA = $propsA['required'] ?? [];
		$requiredB = $propsB['required'] ?? [];
		if ( $this->arraysDiffer( $requiredA, $requiredB ) ) {
			$diff['properties']['required'] = [
				'old' => $requiredB,
				'new' => $requiredA,
			];
		}

		$optionalA = $propsA['optional'] ?? [];
		$optionalB = $propsB['optional'] ?? [];
		if ( $this->arraysDiffer( $optionalA, $optionalB ) ) {
			$diff['properties']['optional'] = [
				'old' => $optionalB,
				'new' => $optionalA,
			];
		}

		// display config (treated as order-sensitive; layout semantics matter)
		$displayA = $dataA['display'] ?? [];
		$displayB = $dataB['display'] ?? [];
		if ( $this->deepDiffer( $displayA, $displayB ) ) {
			$diff['display'] = [
				'old' => $displayB,
				'new' => $displayA,
			];
		}

		// forms config (order-sensitive; form layout)
		$formsA = $dataA['forms'] ?? [];
		$formsB = $dataB['forms'] ?? [];
		if ( $this->deepDiffer( $formsA, $formsB ) ) {
			$diff['forms'] = [
				'old' => $formsB,
				'new' => $formsA,
			];
		}

		// Forward-compatible: any extra keys not explicitly handled above
		$knownKeys = [ 'parents', 'label', 'description', 'properties', 'display', 'forms' ];
		$extraA = array_diff_key( $dataA, array_flip( $knownKeys ) );
		$extraB = array_diff_key( $dataB, array_flip( $knownKeys ) );

		if ( $this->deepDiffer( $extraA, $extraB ) ) {
			$diff['extra'] = [
				'old' => $extraB,
				'new' => $extraA,
			];
		}

		return $diff;
	}

	/**
	 * Field-level diff for a single property record.
	 *
	 * @param array $dataA New data (schemaA)
	 * @param array $dataB Old data (schemaB)
	 * @return array Differences keyed by field name
	 */
	private function diffPropertyData( array $dataA, array $dataB ): array {
		$diff = [];

		// datatype
		$datatypeA = $dataA['datatype'] ?? '';
		$datatypeB = $dataB['datatype'] ?? '';
		if ( $datatypeA !== $datatypeB ) {
			$diff['datatype'] = [
				'old' => $datatypeB,
				'new' => $datatypeA,
			];
		}

		// label
		$labelA = $dataA['label'] ?? '';
		$labelB = $dataB['label'] ?? '';
		if ( $labelA !== $labelB ) {
			$diff['label'] = [
				'old' => $labelB,
				'new' => $labelA,
			];
		}

		// description
		$descA = $dataA['description'] ?? '';
		$descB = $dataB['description'] ?? '';
		if ( $descA !== $descB ) {
			$diff['description'] = [
				'old' => $descB,
				'new' => $descA,
			];
		}

		// allowed values (order-insensitive)
		$allowedA = $dataA['allowedValues'] ?? [];
		$allowedB = $dataB['allowedValues'] ?? [];
		if ( $this->arraysDiffer( $allowedA, $allowedB ) ) {
			$diff['allowedValues'] = [
				'old' => $allowedB,
				'new' => $allowedA,
			];
		}

		// rangeCategory
		$rangeA = $dataA['rangeCategory'] ?? null;
		$rangeB = $dataB['rangeCategory'] ?? null;
		if ( $rangeA !== $rangeB ) {
			$diff['rangeCategory'] = [
				'old' => $rangeB,
				'new' => $rangeA,
			];
		}

		// subpropertyOf (newly added)
		$subA = $dataA['subpropertyOf'] ?? null;
		$subB = $dataB['subpropertyOf'] ?? null;
		if ( $subA !== $subB ) {
			$diff['subpropertyOf'] = [
				'old' => $subB,
				'new' => $subA,
			];
		}

		// Forward-compatible: any extra keys not explicitly handled
		$knownKeys = [ 'datatype', 'label', 'description', 'allowedValues', 'rangeCategory', 'subpropertyOf' ];
		$extraA = array_diff_key( $dataA, array_flip( $knownKeys ) );
		$extraB = array_diff_key( $dataB, array_flip( $knownKeys ) );

		if ( $this->deepDiffer( $extraA, $extraB ) ) {
			$diff['extra'] = [
				'old' => $extraB,
				'new' => $extraA,
			];
		}

		return $diff;
	}

	/**
	 * @param array $dataA
	 * @param array $dataB
	 * @return array
	 */
	private function diffSubobjectData( array $dataA, array $dataB ): array {
		$diff = [];

		if ( ( $dataA['label'] ?? '' ) !== ( $dataB['label'] ?? '' ) ) {
			$diff['label'] = [
				'old' => $dataB['label'] ?? '',
				'new' => $dataA['label'] ?? '',
			];
		}

		if ( ( $dataA['description'] ?? '' ) !== ( $dataB['description'] ?? '' ) ) {
			$diff['description'] = [
				'old' => $dataB['description'] ?? '',
				'new' => $dataA['description'] ?? '',
			];
		}

		$propsA = $dataA['properties'] ?? [];
		$propsB = $dataB['properties'] ?? [];

		$reqA = $propsA['required'] ?? [];
		$reqB = $propsB['required'] ?? [];
		if ( $this->arraysDiffer( $reqA, $reqB ) ) {
			$diff['properties']['required'] = [
				'old' => $reqB,
				'new' => $reqA,
			];
		}

		$optA = $propsA['optional'] ?? [];
		$optB = $propsB['optional'] ?? [];
		if ( $this->arraysDiffer( $optA, $optB ) ) {
			$diff['properties']['optional'] = [
				'old' => $optB,
				'new' => $optA,
			];
		}

		return $diff;
	}

	/**
	 * Check if two "flat" arrays differ, ignoring order.
	 * Intended for simple lists of scalars (e.g., required properties).
	 *
	 * @param array $a
	 * @param array $b
	 * @return bool
	 */
	private function arraysDiffer( array $a, array $b ): bool {
		if ( count( $a ) !== count( $b ) ) {
			return true;
		}

		// Normalize values as strings to prevent minor type differences
		$aNorm = array_map( 'strval', $a );
		$bNorm = array_map( 'strval', $b );

		sort( $aNorm );
		sort( $bNorm );

		return $aNorm !== $bNorm;
	}

	/**
	 * Deep comparison of complex structures (arrays/scalars).
	 *
	 * Order-sensitive by design, since this is currently used for layout-like
	 * structures (display/forms) and "extra" blobs where we assume order matters.
	 *
	 * @param mixed $a
	 * @param mixed $b
	 * @return bool True if different
	 */
	private function deepDiffer( $a, $b ): bool {
		// For now, treat structural order as meaningful.
		// If we later need order-insensitive nested comparison,
		// we can add a normalize() step here.
		return json_encode( $a ) !== json_encode( $b );
	}

	/**
	 * Generate a human-readable summary from compare() output.
	 *
	 * @param array $diff Result from compare()
	 * @return string
	 */
	public function generateSummary( array $diff ): string {
		$lines = [];

		$catDiff  = $diff['categories'] ?? [];
		$propDiff = $diff['properties'] ?? [];

		// Categories
		$lines[] = 'Categories:';
		$lines[] = '  Added: '     . count( $catDiff['added']     ?? [] );
		$lines[] = '  Removed: '   . count( $catDiff['removed']   ?? [] );
		$lines[] = '  Modified: '  . count( $catDiff['modified']  ?? [] );
		$lines[] = '  Unchanged: ' . count( $catDiff['unchanged'] ?? [] );

		// Properties
		$lines[] = '';
		$lines[] = 'Properties:';
		$lines[] = '  Added: '     . count( $propDiff['added']     ?? [] );
		$lines[] = '  Removed: '   . count( $propDiff['removed']   ?? [] );
		$lines[] = '  Modified: '  . count( $propDiff['modified']  ?? [] );
		$lines[] = '  Unchanged: ' . count( $propDiff['unchanged'] ?? [] );

		return implode( "\n", $lines );
	}
}
