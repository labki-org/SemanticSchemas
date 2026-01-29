<?php

namespace MediaWiki\Extension\SemanticSchemas\Util;

/**
 * NamingHelper
 * ------------
 * Shared utility functions for name transformations across SemanticSchemas.
 *
 * This class consolidates naming logic that was previously duplicated across:
 * - FormGenerator
 * - TemplateGenerator
 * - DisplayStubGenerator
 * - DisplayRenderer
 *
 * Centralization Benefits:
 * -----------------------
 * - Ensures consistency: All generators use identical transformations
 * - Single source of truth: Changes propagate automatically
 * - Easier testing: One place to test naming logic
 * - Better documentation: Transformations documented once
 *
 * CRITICAL: Any changes to these methods affect all template/form/display
 * generation. Test thoroughly after modifications.
 */
class NamingHelper {
	/**
	 * Convert SMW property name to template parameter name.
	 *
	 * This transformation is used consistently across all generators to ensure
	 * parameter names match between templates, forms, and displays.
	 *
	 * Transformation Rules:
	 * 1. Remove "Has " prefix (case-sensitive)
	 * 2. Replace ":" with "_" (for namespace-prefixed properties)
	 * 3. Convert to lowercase
	 * 4. Replace spaces with underscores
	 * 5. Trim whitespace
	 *
	 * Examples:
	 *   "Has full name"  → "full_name"
	 *   "Foaf:name"      → "foaf_name"
	 *   "Has Person"     → "person"
	 *   "Research Area"  → "research_area"
	 *
	 * Edge Cases:
	 *   ""               → ""
	 *   "Has "           → ""
	 *   "  Has  name  "  → "name"
	 *
	 * @param string $propertyName SMW property name
	 * @return string Normalized parameter name for use in templates
	 */
	public static function propertyToParameter( string $propertyName ): string {
		$param = $propertyName;

		// Remove "Has " or "Is " prefix (case-sensitive, with space/underscore)
		if ( str_starts_with( $param, 'Has ' ) ) {
			$param = substr( $param, 4 );
		} elseif ( str_starts_with( $param, 'Has_' ) ) {
			$param = substr( $param, 4 );
		} elseif ( str_starts_with( $param, 'Is ' ) ) {
			$param = substr( $param, 3 );
		} elseif ( str_starts_with( $param, 'Is_' ) ) {
			$param = substr( $param, 3 );
		}

		// Replace namespace separator with underscore
		$param = str_replace( ':', '_', $param );

		// Normalize: lowercase, trim, replace spaces
		$param = strtolower( trim( $param ) );
		$param = str_replace( ' ', '_', $param );

		return $param;
	}

	/**
	 * Auto-generate a human-readable label from a property name.
	 *
	 * This is used as a fallback when no explicit label is provided in the schema.
	 *
	 * Transformation Rules:
	 * 1. Strip "Has " or "Has_" prefix
	 * 2. Replace underscores with spaces
	 * 3. Capitalize first letter of each word
	 *
	 * Examples:
	 *   "Has full name"      → "Full Name"
	 *   "Has_research_area"  → "Research Area"
	 *   "research_topic"     → "Research Topic"
	 *   "Has department"     → "Department"
	 *
	 * @param string $propertyName Property name to generate label from
	 * @return string Human-readable label
	 */
	public static function generatePropertyLabel( string $propertyName ): string {
		$clean = $propertyName;

		// Strip "Has " or "Has_" prefix
		if ( str_starts_with( $clean, 'Has ' ) ) {
			$clean = substr( $clean, 4 );
		} elseif ( str_starts_with( $clean, 'Has_' ) ) {
			$clean = substr( $clean, 4 );
		}

		// Strip "Is " or "Is_" prefix
		if ( str_starts_with( $clean, 'Is ' ) ) {
			$clean = substr( $clean, 3 );
		} elseif ( str_starts_with( $clean, 'Is_' ) ) {
			$clean = substr( $clean, 3 );
		}

		$clean = str_replace( '_', ' ', $clean );
		return ucwords( $clean );
	}
}
