<?php

namespace MediaWiki\Extension\StructureSync\Api;

use ApiBase;
use MediaWiki\Extension\StructureSync\Service\CategoryHierarchyService;

/**
 * ApiStructureSyncHierarchy
 * --------------------------
 * Returns category hierarchy data for StructureSync.
 *
 * Endpoint:
 *   api.php?action=structuresync-hierarchy&category=Name
 *
 * Supports:
 *   - Real category lookup
 *   - Virtual lookup (via ?parents[]=Parent1&parents[]=Parent2)
 */
class ApiStructureSyncHierarchy extends ApiBase {

    /**
     * Execute the API request.
     */
    public function execute() {
        $params         = $this->extractRequestParams();
        $categoryName   = $this->stripPrefix( $params['category'] );
        $parentList     = $params['parents'] ?? [];

        $service        = new CategoryHierarchyService();

        if ( !empty( $parentList ) ) {
            // Virtual mode: form preview request
            $cleanParents = $this->sanitizeParentList( $parentList );
            $data = $service->getVirtualHierarchyData( $categoryName, $cleanParents );
        } else {
            // Normal mode
            $data = $service->getHierarchyData( $categoryName );
        }

        // Convert required=true/false → integers (MediaWiki drops boolean false keys)
        $this->normalizeRequiredFlags( $data );

        // Add result
        $this->getResult()->addValue(
            null,
            $this->getModuleName(),
            $data
        );
    }

    /* =====================================================================
     * INPUT SANITIZATION
     * ===================================================================== */

    /**
     * Strip "Category:" prefix if present.
     */
    private function stripPrefix( string $name ): string {
        return preg_replace( '/^Category:/i', '', trim( $name ) );
    }

    /**
     * Sanitize parent categories array.
     *
     * @param array $parents
     * @return array Clean, normalized parent names
     */
    private function sanitizeParentList( array $parents ): array {
        $clean = [];

        foreach ( $parents as $parent ) {
            if ( !is_string( $parent ) ) {
                continue;
            }
            $p = $this->stripPrefix( $parent );
            if ( $p !== '' ) {
                $clean[] = $p;
            }
        }

        return $clean;
    }

    /* =====================================================================
     * REQUIRED FLAG NORMALIZATION
     * ===================================================================== */

    /**
     * Convert required flags from bool → int (1/0) for JSON reliability.
     */
    private function normalizeRequiredFlags( array &$data ): void {

        $convertList = function( array &$items, string $key ) {
            foreach ( $items as &$entry ) {
                if ( isset( $entry[$key] ) ) {
                    $entry[$key] = $entry[$key] ? 1 : 0;
                }
            }
            unset( $entry );
        };

        if ( isset( $data['inheritedProperties'] ) ) {
            $convertList( $data['inheritedProperties'], 'required' );
        }

        if ( isset( $data['inheritedSubgroups'] ) ) {
            $convertList( $data['inheritedSubgroups'], 'required' );
        }
    }

    /* =====================================================================
     * API METADATA
     * ===================================================================== */

    public function getAllowedParams() {
        return [
            'category' => [
                self::PARAM_TYPE       => 'string',
                self::PARAM_REQUIRED   => true,
                self::PARAM_HELP_MSG   => 'structuresync-api-param-category',
            ],
            'parents' => [
                self::PARAM_TYPE       => 'string',
                self::PARAM_ISMULTI    => true,
                self::PARAM_REQUIRED   => false,
                self::PARAM_HELP_MSG   => 'structuresync-api-param-parents',
            ],
        ];
    }

    protected function getExamplesMessages() {
        return [
            'action=structuresync-hierarchy&category=PhDStudent'
                => 'apihelp-structuresync-hierarchy-example-1',
            'action=structuresync-hierarchy&category=Category:GraduateStudent'
                => 'apihelp-structuresync-hierarchy-example-2',
            'action=structuresync-hierarchy&category=NewCategory&parents=Faculty|Person'
                => 'apihelp-structuresync-hierarchy-example-3',
        ];
    }

    public function needsToken() {
        return false; // Read-only public API
    }

    public function isReadMode() {
        return true;
    }
}
