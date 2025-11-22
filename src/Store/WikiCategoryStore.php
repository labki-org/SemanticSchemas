<?php

namespace MediaWiki\Extension\StructureSync\Store;

use MediaWiki\Extension\StructureSync\Schema\CategoryModel;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * Handles reading and writing Category pages with schema metadata
 */
class WikiCategoryStore {

    /** @var PageCreator */
    private $pageCreator;

    /** Schema content markers */
    private const MARKER_START = '<!-- StructureSync Schema Start -->';
    private const MARKER_END   = '<!-- StructureSync Schema End -->';

    public function __construct( PageCreator $pageCreator = null ) {
        $this->pageCreator = $pageCreator ?? new PageCreator();
    }

    /**
     * Read a category from the wiki
     *
     * @param string $categoryName Category name (without "Category:" prefix)
     * @return CategoryModel|null
     */
    public function readCategory( string $categoryName ): ?CategoryModel {
        $title = $this->pageCreator->makeTitle( $categoryName, NS_CATEGORY );
        if ( $title === null || !$this->pageCreator->pageExists( $title ) ) {
            return null;
        }

        $content = $this->pageCreator->getPageContent( $title );
        if ( $content === null ) {
            return null;
        }

        // Parse structural metadata inside the markers
        $data = $this->parseCategoryContent( $content, $categoryName );

        // Could read from SMW later; for now parsing-only
        return new CategoryModel( $categoryName, $data );
    }

    /**
     * Parse category metadata from page content
     *
     * NOTE: **Corrected behavior**
     * - DO NOT extract parent categories from [[Category:...]] tags.
     * - ONLY use [[Has parent category::Category:Foo]].
     */
    private function parseCategoryContent( string $content, string $categoryName ): array {

        $data = [
            'parents' => [],
            'properties' => [
                'required' => [],
                'optional' => [],
            ],
            'display' => [],
            'forms' => [],
        ];

        // Extract ONLY semantic parent categories
        preg_match_all(
            '/\[\[Has parent category::Category:([^\]]+)\]\]/',
            $content,
            $matches
        );
        if ( !empty( $matches[1] ) ) {
            $data['parents'] = array_map( 'trim', $matches[1] );
        }

        // Extract required properties
        preg_match_all(
            '/\[\[Has required property::Property:([^\]]+)\]\]/',
            $content,
            $matches
        );
        if ( !empty( $matches[1] ) ) {
            $data['properties']['required'] = array_map( 'trim', $matches[1] );
        }

        // Extract optional properties
        preg_match_all(
            '/\[\[Has optional property::Property:([^\]]+)\]\]/',
            $content,
            $matches
        );
        if ( !empty( $matches[1] ) ) {
            $data['properties']['optional'] = array_map( 'trim', $matches[1] );
        }

        // Description extraction unchanged
        $data['label'] = $categoryName;
        $data['description'] = $this->extractDescription( $content );

        return $data;
    }

    /**
     * Extract description from content
     */
    private function extractDescription( string $content ): string {
        $lines = explode( "\n", $content );
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if (
                $line !== '' &&
                !str_starts_with( $line, '[[' ) &&
                !str_starts_with( $line, '{{' ) &&
                !str_starts_with( $line, '<!--' ) &&
                !str_starts_with( $line, '=' )
            ) {
                return $line;
            }
        }
        return '';
    }

    /**
     * Write a category to the wiki
     */
    public function writeCategory( CategoryModel $category ): bool {

        $title = $this->pageCreator->makeTitle( $category->getName(), NS_CATEGORY );
        if ( $title === null ) {
            return false;
        }

        $existingContent = $this->pageCreator->getPageContent( $title ) ?? '';

        $schemaContent = $this->generateSchemaMetadata( $category );

        // Write inside markers
        $newContent = $this->pageCreator->updateWithinMarkers(
            $existingContent,
            $schemaContent,
            self::MARKER_START,
            self::MARKER_END
        );

        // Add tracking category
        $tracking = '[[Category:StructureSync-managed]]';
        if ( strpos( $newContent, $tracking ) === false ) {
            $newContent .= "\n$tracking";
        }

        $summary = "StructureSync: Update category schema metadata";

        return $this->pageCreator->createOrUpdatePage( $title, $newContent, $summary );
    }

    /**
     * Generate schema metadata
     *
     * NOTE: **Corrected behavior**
     * - DO NOT write `[[Category:Parent]]` into the categories.
     * - Only emit semantic metadata.
     */
    private function generateSchemaMetadata( CategoryModel $category ): string {

        $lines = [];

        // Description (optional)
        if ( $category->getDescription() !== '' ) {
            $lines[] = $category->getDescription();
            $lines[] = '';
        }

        // Parents
        foreach ( $category->getParents() as $parent ) {
            $lines[] = "[[Has parent category::Category:$parent]]";
        }
        if ( !empty( $category->getParents() ) ) {
            $lines[] = '';
        }

        // Required props
        if ( $req = $category->getRequiredProperties() ) {
            $lines[] = '=== Required Properties ===';
            foreach ( $req as $prop ) {
                $lines[] = "[[Has required property::Property:$prop]]";
            }
            $lines[] = '';
        }

        // Optional props
        if ( $opt = $category->getOptionalProperties() ) {
            $lines[] = '=== Optional Properties ===';
            foreach ( $opt as $prop ) {
                $lines[] = "[[Has optional property::Property:$prop]]";
            }
            $lines[] = '';
        }

        // Display sections
        $sections = $category->getDisplaySections();
        if ( !empty( $sections ) ) {
            $lines[] = '=== Display Configuration ===';

            foreach ( $sections as $idx => $sec ) {
                $lines[] = "{{#subobject:display_section_$idx";
                $name = $sec['name'] ?? '';
                $lines[] = "|Has display section name=" . ( $name !== null ? (string)$name : '' );

                if ( !empty( $sec['properties'] ) ) {
                    foreach ( $sec['properties'] as $p ) {
                        $pSafe = $p !== null ? (string)$p : '';
                        if ( $pSafe !== '' ) {
                            $lines[] = "|Has display section property=Property:$pSafe";
                        }
                    }
                }

                $lines[] = "}}";
            }

            $lines[] = '';
        }

        return implode( "\n", $lines );
    }

    /**
     * Get all Category: pages
     */
    public function getAllCategories(): array {

        $categories = [];
        $lb  = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbr = $lb->getConnection( DB_REPLICA );

        $res = $dbr->newSelectQueryBuilder()
            ->select( 'page_title' )
            ->from( 'page' )
            ->where( [ 'page_namespace' => NS_CATEGORY ] )
            ->caller( __METHOD__ )
            ->fetchResultSet();

        foreach ( $res as $row ) {
            $name = str_replace( '_', ' ', $row->page_title );
            $cat  = $this->readCategory( $name );
            if ( $cat !== null ) {
                $categories[$name] = $cat;
            }
        }

        return $categories;
    }

    public function categoryExists( string $categoryName ): bool {
        $title = $this->pageCreator->makeTitle( $categoryName, NS_CATEGORY );
        return $title !== null && $this->pageCreator->pageExists( $title );
    }
}
