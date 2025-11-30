<?php

namespace MediaWiki\Extension\StructureSync\Store;

use MediaWiki\Extension\StructureSync\Schema\SubobjectModel;
use MediaWiki\Title\Title;

/**
 * WikiSubobjectStore
 * ------------------
 * Handles reading/writing Subobject pages that define repeatable property groups.
 *
 * Subobject pages are stored in the Subobject: namespace and described purely by
 * Semantic MediaWiki annotations (no special headings or list syntax required):
 *   [[Has description::...]]
 *   [[Has required property::Property:Foo]]
 *   [[Has optional property::Property:Bar]]
 */
class WikiSubobjectStore {

	/** @var PageCreator */
	private $pageCreator;

	/** Schema content markers reused for deterministic hashing */
	private const MARKER_START = '<!-- StructureSync Start -->';
	private const MARKER_END = '<!-- StructureSync End -->';

	public function __construct( PageCreator $pageCreator = null ) {
		$this->pageCreator = $pageCreator ?? new PageCreator();
	}

	public function readSubobject( string $subobjectName ): ?SubobjectModel {
		$title = $this->pageCreator->makeTitle( $subobjectName, NS_SUBOBJECT );
		if ( $title === null || !$this->pageCreator->pageExists( $title ) ) {
			return null;
		}

		$data = $this->querySubobjectFromSMW( $title );
		
		// Fallback to parsing wikitext directly if SMW hasn't processed the page yet
		// or if properties are empty (which can happen if SMW data isn't available)
		if ( empty( $data['properties']['required'] ) && empty( $data['properties']['optional'] ) ) {
			$pageContent = $this->pageCreator->getPageContent( $title );
			if ( $pageContent !== null ) {
				$parsedData = $this->parseSubobjectFromWikitext( $pageContent, $title->getText() );
				// Merge parsed data, preferring SMW data for description if available
				if ( !empty( $parsedData['properties']['required'] ) || !empty( $parsedData['properties']['optional'] ) ) {
					$data['properties'] = $parsedData['properties'];
				}
				if ( empty( $data['description'] ) && !empty( $parsedData['description'] ) ) {
					$data['description'] = $parsedData['description'];
				}
			}
		}
		
		return new SubobjectModel( $subobjectName, $data );
	}

	/**
	 * Parse subobject properties directly from wikitext.
	 * 
	 * This is used as a fallback when SMW hasn't processed the page yet.
	 * Scans the entire page content for [[Has required property::Property:...]] 
	 * and [[Has optional property::Property:...]] annotations.
	 *
	 * @param string $pageContent Full page wikitext
	 * @param string $defaultLabel Default label if not found in content
	 * @return array Parsed subobject data
	 */
	private function parseSubobjectFromWikitext( string $pageContent, string $defaultLabel ): array {
		$data = [
			'label' => $defaultLabel,
			'description' => '',
			'properties' => [
				'required' => [],
				'optional' => [],
			],
		];

		// Extract description
		if ( preg_match_all( '/\[\[Has description::([^\]]+)\]\]/', $pageContent, $matches ) ) {
			$data['description'] = trim( $matches[1][0] ?? '' );
		}

		// Extract required properties: [[Has required property::Property:PropertyName]]
		if ( preg_match_all( '/\[\[Has required property::Property:([^\]]+)\]\]/', $pageContent, $matches ) ) {
			foreach ( $matches[1] as $propertyName ) {
				$propertyName = trim( $propertyName );
				if ( $propertyName !== '' ) {
					// Normalize: convert underscores to spaces
					$propertyName = str_replace( '_', ' ', $propertyName );
					$data['properties']['required'][] = $propertyName;
				}
			}
		}

		// Extract optional properties: [[Has optional property::Property:PropertyName]]
		if ( preg_match_all( '/\[\[Has optional property::Property:([^\]]+)\]\]/', $pageContent, $matches ) ) {
			foreach ( $matches[1] as $propertyName ) {
				$propertyName = trim( $propertyName );
				if ( $propertyName !== '' ) {
					// Normalize: convert underscores to spaces
					$propertyName = str_replace( '_', ' ', $propertyName );
					$data['properties']['optional'][] = $propertyName;
				}
			}
		}

		return $data;
	}

	/**
	 * Query SMW store for semantic annotations attached to this subobject.
	 *
	 * @param Title $title
	 * @return array
	 */
	private function querySubobjectFromSMW( Title $title ): array {
		$data = [
			'label' => $title->getText(),
			'description' => '',
			'properties' => [
				'required' => [],
				'optional' => [],
			],
		];

		try {
			$store = \SMW\StoreFactory::getStore();
			$subject = \SMW\DIWikiPage::newFromTitle( $title );
			$semanticData = $store->getSemanticData( $subject );
		} catch ( \Exception $e ) {
			return $data;
		}

		$descriptions = $this->getPropertyValues( $semanticData, 'Has description', 'text' );
		if ( !empty( $descriptions ) ) {
			$data['description'] = $descriptions[0];
		}

		$data['properties']['required'] = $this->getPropertyValues(
			$semanticData,
			'Has required property',
			'property'
		);
		$data['properties']['optional'] = $this->getPropertyValues(
			$semanticData,
			'Has optional property',
			'property'
		);

		return $data;
	}

	/**
	 * @param \SMW\SemanticData $semanticData
	 * @param string $propertyName
	 * @param string $type
	 * @return array
	 */
	private function getPropertyValues( \SMW\SemanticData $semanticData, string $propertyName, string $type = 'text' ): array {
		try {
			$property = \SMW\DIProperty::newFromUserLabel( $propertyName );
			$values = [];
			foreach ( $semanticData->getPropertyValues( $property ) as $dataItem ) {
				$value = $this->extractValueFromDataItem( $dataItem, $type );
				if ( $value !== null ) {
					$values[] = $value;
				}
			}
			return $values;
		} catch ( \Exception $e ) {
			return [];
		}
	}

	/**
	 * @param \SMWDataItem $dataItem
	 * @param string $type
	 * @return string|null
	 */
	private function extractValueFromDataItem( \SMWDataItem $dataItem, string $type ): ?string {
		if ( $dataItem instanceof \SMW\DIWikiPage ) {
			$title = $dataItem->getTitle();
			if ( !$title ) {
				return null;
			}

		if ( $type === 'property' && $title->getNamespace() === \SMW_NS_PROPERTY ) {
			// Normalize property name: convert underscores to spaces
			// MediaWiki stores page titles with underscores, but SMW property names use spaces
			return str_replace( '_', ' ', $title->getText() );
		}

			if ( $type === 'subobject' && $title->getNamespace() === NS_SUBOBJECT ) {
				return $title->getText();
			}

			return $title->getText();
		}

		if ( $dataItem instanceof \SMWDIBlob || $dataItem instanceof \SMWDIString ) {
			return $dataItem->getString();
		}

		return null;
	}

	public function writeSubobject( SubobjectModel $subobject ): bool {
		$title = $this->pageCreator->makeTitle( $subobject->getName(), NS_SUBOBJECT );
		if ( !$title ) {
			return false;
		}

		$content = $this->buildSubobjectContent( $subobject );
		$summary = 'StructureSync: Update subobject schema metadata';

		return $this->pageCreator->createOrUpdatePage( $title, $content, $summary );
	}

	/**
	 * Generate wikitext content for a subobject page.
	 *
	 * @param SubobjectModel $subobject
	 * @return string
	 */
	private function buildSubobjectContent( SubobjectModel $subobject ): string {
		$lines = [];
		$lines[] = self::MARKER_START;

		if ( $subobject->getDescription() !== '' ) {
			$lines[] = '[[Has description::' . $subobject->getDescription() . ']]';
			$lines[] = '';
		}

		foreach ( $subobject->getRequiredProperties() as $property ) {
			$lines[] = '[[Has required property::Property:' . $property . ']]';
		}

		if ( $subobject->getRequiredProperties() ) {
			$lines[] = '';
		}

		foreach ( $subobject->getOptionalProperties() as $property ) {
			$lines[] = '[[Has optional property::Property:' . $property . ']]';
		}

		$lines[] = self::MARKER_END;
		$lines[] = '[[Category:StructureSync-managed]]';

		return implode( "\n", array_filter(
			$lines,
			static function ( $line ) {
				return $line !== null;
			}
		) );
	}

	/**
	 * @return array<string,SubobjectModel>
	 */
	public function getAllSubobjects(): array {
		$subobjects = [];

		$lb = \MediaWiki\MediaWikiServices::getInstance()->getDBLoadBalancer();
		$dbr = $lb->getConnection( DB_REPLICA );

		$res = $dbr->newSelectQueryBuilder()
			->select( 'page_title' )
			->from( 'page' )
			->where( [ 'page_namespace' => NS_SUBOBJECT ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		foreach ( $res as $row ) {
			$name = str_replace( '_', ' ', $row->page_title );
			$model = $this->readSubobject( $name );
			if ( $model ) {
				$subobjects[$name] = $model;
			}
		}

		return $subobjects;
	}

	public function subobjectExists( string $subobjectName ): bool {
		$title = $this->pageCreator->makeTitle( $subobjectName, NS_SUBOBJECT );
		return $title !== null && $this->pageCreator->pageExists( $title );
	}
}

