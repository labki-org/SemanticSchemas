<?php

namespace MediaWiki\Extension\StructureSync\Api;

use ApiBase;
use MediaWiki\Extension\StructureSync\Service\CategoryHierarchyService;

/**
 * ApiStructureSyncHierarchy
 * --------------------------
 * API module for retrieving category hierarchy visualization data.
 * 
 * This is the first API module for the StructureSync extension.
 * 
 * Endpoint: api.php?action=structuresync-hierarchy&category=CategoryName
 * 
 * Returns:
 * {
 *   "structuresync-hierarchy": {
 *     "rootCategory": "Category:CategoryName",
 *     "nodes": {
 *       "Category:CategoryName": {
 *         "title": "Category:CategoryName",
 *         "parents": ["Category:Parent1", ...]
 *       },
 *       ...
 *     },
 *     "inheritedProperties": [
 *       {
 *         "propertyTitle": "Property:Has email",
 *         "sourceCategory": "Category:Person",
 *         "required": true
 *       },
 *       ...
 *     ]
 *   }
 * }
 * 
 * Usage:
 * - Called by frontend JS (ext.structuresync.hierarchy.js)
 * - Used in Special:StructureSync/hierarchy tab
 * - Used in Category page hierarchy displays
 * - Used in PageForms hierarchy preview
 */
class ApiStructureSyncHierarchy extends ApiBase
{
    /**
     * Execute the API request.
     */
    public function execute()
    {
        $params = $this->extractRequestParams();
        $categoryName = $params['category'];
        $parentCategories = $params['parents'] ?? null;

        // Remove "Category:" prefix if present
        $categoryName = preg_replace('/^Category:/i', '', $categoryName);

        // Get hierarchy data from service
        $service = new CategoryHierarchyService();
        
        // If parent categories are provided, use virtual mode (for form preview)
        if ($parentCategories !== null && is_array($parentCategories) && count($parentCategories) > 0) {
            // Clean up parent category names
            $cleanParents = array_map(function($parent) {
                return preg_replace('/^Category:/i', '', trim($parent));
            }, $parentCategories);
            $cleanParents = array_filter($cleanParents); // Remove empty strings
            
            $hierarchyData = $service->getVirtualHierarchyData($categoryName, array_values($cleanParents));
        } else {
            $hierarchyData = $service->getHierarchyData($categoryName);
        }

        // Convert boolean 'required' values to integers for reliable JSON encoding
        // (MediaWiki API can strip boolean false values)
        if (isset($hierarchyData['inheritedProperties'])) {
            foreach ($hierarchyData['inheritedProperties'] as $key => &$prop) {
                if (isset($prop['required'])) {
                    $prop['required'] = $prop['required'] ? 1 : 0;
                }
            }
            unset($prop); // Break the reference
        }

        // Return result
        $result = $this->getResult();
        $result->addValue(null, $this->getModuleName(), $hierarchyData);
    }

    /**
     * Define allowed parameters.
     *
     * @return array
     */
    public function getAllowedParams()
    {
        return [
            'category' => [
                self::PARAM_TYPE => 'string',
                self::PARAM_REQUIRED => true,
                self::PARAM_HELP_MSG => 'structuresync-api-param-category',
            ],
            'parents' => [
                self::PARAM_TYPE => 'string',
                self::PARAM_REQUIRED => false,
                self::PARAM_ISMULTI => true,
                self::PARAM_HELP_MSG => 'structuresync-api-param-parents',
            ],
        ];
    }

    /**
     * Example queries for API help.
     *
     * @return array
     */
    protected function getExamplesMessages()
    {
        return [
            'action=structuresync-hierarchy&category=PhDStudent'
                => 'apihelp-structuresync-hierarchy-example-1',
            'action=structuresync-hierarchy&category=Category:GraduateStudent'
                => 'apihelp-structuresync-hierarchy-example-2',
            'action=structuresync-hierarchy&category=NewCategory&parents=Faculty|Person'
                => 'apihelp-structuresync-hierarchy-example-3',
        ];
    }

    /**
     * Indicate that this API module requires read access.
     *
     * @return string
     */
    public function needsToken()
    {
        return false;
    }

    /**
     * Indicate that GET requests are allowed.
     *
     * @return bool
     */
    public function isReadMode()
    {
        return true;
    }
}

