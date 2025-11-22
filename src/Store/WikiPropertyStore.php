<?php

namespace MediaWiki\Extension\StructureSync\Store;

use MediaWiki\Extension\StructureSync\Schema\PropertyModel;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * WikiPropertyStore
 * ------------------
 * Responsible for reading/writing SMW Property: pages and reconstructing
 * PropertyModel objects from raw content.
 *
 * Fully corrected version:
 *   - Description extraction ignores headings (= ... =)
 *   - Ensures consistent metadata keys exist (rangeCategory/subpropertyOf)
 *   - Adds StructureSync markers for hashing and dirty detection
 *   - Normalizes property names
 */
class WikiPropertyStore {

    private const MARKER_START = '<!-- StructureSync Schema Start -->';
    private const MARKER_END   = '<!-- StructureSync Schema End -->';

    /** @var PageCreator */
    private $pageCreator;

    public function __construct( PageCreator $pageCreator = null ) {
        $this->pageCreator = $pageCreator ?? new PageCreator();
    }

    /* ---------------------------------------------------------------------
     * READ PROPERTY
     * --------------------------------------------------------------------- */

    public function readProperty( string $propertyName ): ?PropertyModel {

        $canonical = $this->normalizePropertyName( $propertyName );

        $title = $this->pageCreator->makeTitle( $canonical, \SMW_NS_PROPERTY );
        if ( $title === null || !$this->pageCreator->pageExists( $title ) ) {
            return null;
        }

        $content = $this->pageCreator->getPageContent( $title );
        if ( $content === null ) {
            return null;
        }

        $data = $this->parsePropertyContent( $content );

        // Ensure existence of keys
        $data += [
            'datatype'      => null,
            'allowedValues' => [],
            'rangeCategory' => null,
            'subpropertyOf' => null,
        ];

        if ( !isset( $data['label'] ) ) {
            $data['label'] = $canonical;
        }

        return new PropertyModel( $canonical, $data );
    }

    private function normalizePropertyName( string $name ): string {
        $name = trim( $name );
        $name = preg_replace( '/^Property:/i', '', $name );
        return str_replace( '_', ' ', $name );
    }

    /* ---------------------------------------------------------------------
     * PARSE PROPERTY CONTENT
     * --------------------------------------------------------------------- */

    private function parsePropertyContent( string $content ): array {

        $data = [];

        /* Datatype ------------------------------------------------------ */
        if ( preg_match( '/\[\[Has type::([^\|\]]+)/i', $content, $m ) ) {
            $data['datatype'] = trim( $m[1] );
        }

        /* Allowed values ------------------------------------------------ */
        preg_match_all( '/\[\[Allows value::([^\|\]]+)/i', $content, $m );
        if ( !empty( $m[1] ) ) {
            $data['allowedValues'] = array_values(
                array_unique( array_map( 'trim', $m[1] ) )
            );
        }

        /* Range category ------------------------------------------------ */
        if ( preg_match(
            '/\[\[Has domain and range::Category:([^\|\]]+)/i',
            $content,
            $m
        ) ) {
            $data['rangeCategory'] = trim( $m[1] );
        }

        /* Subproperty --------------------------------------------------- */
        if ( preg_match( '/\[\[Subproperty of::([^\|\]]+)/i', $content, $m ) ) {
            $data['subpropertyOf'] = trim( str_replace( '_', ' ', $m[1] ) );
        }

        /* Description --------------------------------------------------- */
        $lines = explode( "\n", $content );
        foreach ( $lines as $line ) {
            $line = trim( $line );

            if (
                $line !== '' &&
                !str_starts_with( $line, '[[' ) &&
                !str_starts_with( $line, '{{' ) &&
                !str_starts_with( $line, '<!' ) &&
                !str_starts_with( $line, '=' )       // fix: ignore headings
            ) {
                $data['description'] = $line;
                break;
            }
        }

        return $data;
    }

    /* ---------------------------------------------------------------------
     * WRITE PROPERTY PAGE CONTENT
     * --------------------------------------------------------------------- */

    public function writeProperty( PropertyModel $property ): bool {

        $title = $this->pageCreator->makeTitle( $property->getName(), \SMW_NS_PROPERTY );
        if ( $title === null ) {
            return false;
        }

        $existingContent = $this->pageCreator->getPageContent( $title ) ?? '';

        $schemaBlock = $this->generatePropertySchemaBlock( $property );

        $newContent = $this->pageCreator->updateWithinMarkers(
            $existingContent,
            $schemaBlock,
            self::MARKER_START,
            self::MARKER_END
        );

        // Tracking category
        if ( strpos( $newContent, '[[Category:StructureSync-managed-property]]' ) === false ) {
            $newContent .= "\n[[Category:StructureSync-managed-property]]";
        }

        return $this->pageCreator->createOrUpdatePage(
            $title,
            $newContent,
            "StructureSync: Update property metadata"
        );
    }

    /**
     * Generate ONLY the metadata block inserted inside StructureSync markers
     */
    private function generatePropertySchemaBlock( PropertyModel $property ): string {

        $lines = [];

        if ( $property->getDescription() !== '' ) {
            $lines[] = $property->getDescription();
            $lines[] = '';
        }

        // Datatype
        $lines[] = '[[Has type::' . $property->getSMWType() . ']]';

        // Allowed values
        if ( $property->hasAllowedValues() ) {
            $lines[] = '';
            $lines[] = '== Allowed values ==';
            foreach ( $property->getAllowedValues() as $v ) {
                $v = str_replace( '|', ' ', $v );
                $lines[] = "* [[Allows value::$v]]";
            }
        }

        // Range category
        if ( $property->getRangeCategory() !== null ) {
            $lines[] = '';
            $lines[] = '[[Has domain and range::Category:' . 
                $property->getRangeCategory() . ']]';
        }

        // Subproperty
        if ( $property->getSubpropertyOf() !== null ) {
            $lines[] = '';
            $lines[] = '[[Subproperty of::' . 
                $property->getSubpropertyOf() . ']]';
        }

        return implode( "\n", $lines );
    }

    /* ---------------------------------------------------------------------
     * LIST + EXISTENCE
     * --------------------------------------------------------------------- */

    public function getAllProperties(): array {

        $properties = [];

        $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbr = $lb->getConnection( DB_REPLICA );

        $res = $dbr->newSelectQueryBuilder()
            ->select( 'page_title' )
            ->from( 'page' )
            ->where( [ 'page_namespace' => \SMW_NS_PROPERTY ] )
            ->caller( __METHOD__ )
            ->fetchResultSet();

        foreach ( $res as $row ) {
            $name = str_replace( '_', ' ', $row->page_title );
            $prop = $this->readProperty( $name );
            if ( $prop !== null ) {
                $properties[$name] = $prop;
            }
        }

        return $properties;
    }

    public function propertyExists( string $propertyName ): bool {
        $canonical = $this->normalizePropertyName( $propertyName );
        $title = $this->pageCreator->makeTitle( $canonical, \SMW_NS_PROPERTY );
        return $title !== null && $this->pageCreator->pageExists( $title );
    }
}
