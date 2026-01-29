<?php

namespace MediaWiki\Extension\SemanticSchemas\Schema;

use InvalidArgumentException;

/**
 * Immutable schema-level representation of a Category.
 *
 * Canonical schema structure:
 *
 *   name: string
 *   parents: string[]
 *   label: string
 *   description: string
 *   targetNamespace: string|null
 *   renderAs: string|null - TemplateFormat category reference
 *
 *   properties:
 *     required: string[]
 *     optional: string[]
 *
 *   subobjects:
 *     required: string[]
 *     optional: string[]
 *
 *   display:
 *     header: string[]
 *     sections: [
 *        [ 'name' => string, 'properties' => string[] ],
 *     ]
 *
 *   forms:
 *     sections: [
 *        [ 'name' => string, 'properties' => string[] ],
 *     ]
 *
 * Fully immutable. No parsing or backward-compatibility.
 */
class CategoryModel {

	private string $name;
	private array $parents;

	private string $label;
	private string $description;

	private ?string $targetNamespace;
	private ?string $renderAs;

	private array $requiredProperties;
	private array $optionalProperties;

	private array $requiredSubobjects;
	private array $optionalSubobjects;

	private array $displayConfig;
	private array $formConfig;

	/* -------------------- New Display System -------------------- */

	/** @var ?PropertyModel Property defining the display format */
	private ?PropertyModel $displayTemplateProperty = null;

	/** @var ?string Raw wikitext from the display template property */
	private ?string $displayTemplateSource = null;

	/** @var PropertyModel[] All properties to be displayed */
	private array $displayProperties = [];

	/** @var array<string, PropertyModel[]> Properties grouped by section */
	private array $displaySectionModels = [];

	/* -------------------------------------------------------------------------
	 * CONSTRUCTOR
	 * ------------------------------------------------------------------------- */

	public function __construct( string $name, array $data = [] ) {
		$name = trim( $name );
		if ( $name === '' ) {
			throw new InvalidArgumentException( "Category name cannot be empty." );
		}
		if ( preg_match( '/[<>{}|#]/', $name ) ) {
			throw new InvalidArgumentException( "Category '{$name}' contains invalid characters." );
		}
		$this->name = $name;

		/* -------------------- Parents -------------------- */

		$this->parents = self::normalizeList( $data['parents'] ?? [] );
		foreach ( $this->parents as $p ) {
			if ( $p === $name ) {
				throw new InvalidArgumentException( "Category '{$name}' cannot be its own parent." );
			}
		}

		/* -------------------- Metadata -------------------- */

		$this->label = (string)( $data['label'] ?? $name );
		$this->description = (string)( $data['description'] ?? '' );

		$ns = $data['targetNamespace'] ?? null;
		$this->targetNamespace = ( $ns !== null && trim( $ns ) !== '' ) ? trim( $ns ) : null;

		/* -------------------- Render Format -------------------- */

		$renderAs = $data['renderAs'] ?? null;
		$this->renderAs = ( $renderAs !== null && trim( $renderAs ) !== '' ) ? trim( $renderAs ) : null;

		/* -------------------- Properties -------------------- */

		$props = $data['properties'] ?? [];
		if ( !is_array( $props ) ) {
			throw new InvalidArgumentException( "Category '{$name}': 'properties' must be an array." );
		}

		$this->requiredProperties = self::normalizeList( $props['required'] ?? [] );
		$this->optionalProperties = self::normalizeList( $props['optional'] ?? [] );

		$dup = array_intersect( $this->requiredProperties, $this->optionalProperties );
		if ( $dup !== [] ) {
			throw new InvalidArgumentException(
				"Category '{$name}' has properties listed as both required and optional: " .
				implode( ', ', $dup )
			);
		}

		/* -------------------- Subobjects -------------------- */

		$subs = $data['subobjects'] ?? [];
		if ( !is_array( $subs ) ) {
			throw new InvalidArgumentException( "Category '{$name}': 'subobjects' must be an array." );
		}

		$this->requiredSubobjects = self::normalizeList( $subs['required'] ?? [] );
		$this->optionalSubobjects = self::normalizeList( $subs['optional'] ?? [] );

		$dupSG = array_intersect( $this->requiredSubobjects, $this->optionalSubobjects );
		if ( $dupSG !== [] ) {
			throw new InvalidArgumentException(
				"Category '{$name}' has subobjects listed as both required and optional: " .
				implode( ', ', $dupSG )
			);
		}

		/* -------------------- Display Config -------------------- */

		$display = $data['display'] ?? [];
		if ( !is_array( $display ) ) {
			throw new InvalidArgumentException( "Category '{$name}': 'display' must be an array." );
		}
		$this->displayConfig = $display;

		/* -------------------- Form Config -------------------- */

		$forms = $data['forms'] ?? [];
		if ( !is_array( $forms ) ) {
			throw new InvalidArgumentException( "Category '{$name}': 'forms' must be an array." );
		}
		$this->formConfig = $forms;
	}

	/* -------------------------------------------------------------------------
	 * ACCESSORS (read-only)
	 * ------------------------------------------------------------------------- */

	public function getName(): string {
		return $this->name;
	}

	public function getParents(): array {
		return $this->parents;
	}

	public function getLabel(): string {
		return $this->label !== '' ? $this->label : $this->name;
	}

	public function getDescription(): string {
		return $this->description;
	}

	public function getTargetNamespace(): ?string {
		return $this->targetNamespace;
	}

	public function getRenderAs(): ?string {
		return $this->renderAs;
	}

	/* -------------------- Properties -------------------- */

	public function getRequiredProperties(): array {
		return $this->requiredProperties;
	}

	public function getOptionalProperties(): array {
		return $this->optionalProperties;
	}

	public function getAllProperties(): array {
		return array_values( array_unique(
			array_merge( $this->requiredProperties, $this->optionalProperties )
		) );
	}

	/* -------------------- Subobjects -------------------- */

	public function getRequiredSubobjects(): array {
		return $this->requiredSubobjects;
	}

	public function getOptionalSubobjects(): array {
		return $this->optionalSubobjects;
	}

	public function hasSubobjects(): bool {
		return $this->requiredSubobjects !== [] || $this->optionalSubobjects !== [];
	}

	/* -------------------- Display + Forms -------------------- */

	public function getDisplayConfig(): array {
		return $this->displayConfig;
	}

	public function getDisplayHeaderProperties(): array {
		return $this->displayConfig['header'] ?? [];
	}

	public function getDisplayFormat(): ?string {
		return $this->displayConfig['format'] ?? null;
	}

	public function getDisplaySections(): array {
		return $this->displayConfig['sections'] ?? [];
	}

	public function getFormConfig(): array {
		return $this->formConfig;
	}

	/* -------------------------------------------------------------------------
	 * MERGING (CATEGORY + PARENT)
	 * ------------------------------------------------------------------------- */

	public function mergeWithParent( CategoryModel $parent ): CategoryModel {
		/* -------------------- Properties -------------------- */

		$mergedRequired = array_values( array_unique( array_merge(
			$parent->getRequiredProperties(),
			$this->requiredProperties
		) ) );

		$mergedOptional = array_values( array_diff(
			array_unique( array_merge(
				$parent->getOptionalProperties(),
				$this->optionalProperties
			) ),
			$mergedRequired
		) );

		/* -------------------- Subobjects -------------------- */

		$mergedRequiredSG = array_values( array_unique( array_merge(
			$parent->getRequiredSubobjects(),
			$this->requiredSubobjects
		) ) );

		$mergedOptionalSG = array_values( array_diff(
			array_unique( array_merge(
				$parent->getOptionalSubobjects(),
				$this->optionalSubobjects
			) ),
			$mergedRequiredSG
		) );

		/* -------------------- Display -------------------- */

		$mergedDisplay = self::mergeDisplayConfigs(
			$parent->getDisplayConfig(),
			$this->displayConfig
		);

		/* -------------------- Forms -------------------- */

		$mergedForms = self::mergeFormConfigs(
			$parent->getFormConfig(),
			$this->formConfig
		);

		/* -------------------- New CategoryModel -------------------- */

		return new self(
			$this->name,
			[
				'parents' => $this->parents,
				'label' => $this->label,
				'description' => $this->description,
				'targetNamespace' => $this->targetNamespace,
				'renderAs' => $this->renderAs ?? $parent->getRenderAs(),
				'properties' => [
					'required' => $mergedRequired,
					'optional' => $mergedOptional,
				],
				'subobjects' => [
					'required' => $mergedRequiredSG,
					'optional' => $mergedOptionalSG,
				],
				'display' => $mergedDisplay,
				'forms' => $mergedForms,
			]
		);
	}

	/* -------------------------------------------------------------------------
	 * MERGE HELPERS
	 * ------------------------------------------------------------------------- */

	private static function mergeDisplayConfigs( array $parent, array $child ): array {
		if ( $parent === [] ) {
			return $child;
		}
		if ( $child === [] ) {
			return $parent;
		}

		$merged = $parent;

		if ( isset( $child['header'] ) ) {
			$merged['header'] = self::normalizeList( $child['header'] );
		}

		if ( isset( $child['format'] ) ) {
			$merged['format'] = trim( (string)$child['format'] );
		}

		if ( isset( $child['sections'] ) ) {
			$mergedSections = $parent['sections'] ?? [];

			foreach ( $child['sections'] as $c ) {
				$found = false;

				foreach ( $mergedSections as &$m ) {
					if ( ( $m['name'] ?? null ) === ( $c['name'] ?? null ) ) {
						$m = $c;
						$found = true;
						break;
					}
				}

				if ( !$found ) {
					$mergedSections[] = $c;
				}
			}

			$merged['sections'] = $mergedSections;
		}

		return $merged;
	}

	private static function mergeFormConfigs( array $parent, array $child ): array {
		if ( $parent === [] ) {
			return $child;
		}
		if ( $child === [] ) {
			return $parent;
		}

		$merged = $parent;

		if ( isset( $child['sections'] ) ) {
			$merged['sections'] = $child['sections'];
		}

		return $merged;
	}

	/* -------------------------------------------------------------------------
	 * UTILITIES
	 * ------------------------------------------------------------------------- */

	private static function normalizeList( array $list ): array {
		$out = [];
		foreach ( $list as $v ) {
			$v = trim( (string)$v );
			if ( $v !== '' && !in_array( $v, $out, true ) ) {
				$out[] = $v;
			}
		}
		return $out;
	}

	/* -------------------------------------------------------------------------
	 * EXPORT
	 * ------------------------------------------------------------------------- */

	public function toArray(): array {
		$out = [
			'parents' => $this->parents,
			'label' => $this->label,
			'description' => $this->description,
			'properties' => [
				'required' => $this->requiredProperties,
				'optional' => $this->optionalProperties,
			],
		];

		if ( $this->hasSubobjects() ) {
			$out['subobjects'] = [
				'required' => $this->requiredSubobjects,
				'optional' => $this->optionalSubobjects,
			];
		}

		if ( $this->displayConfig !== [] ) {
			$out['display'] = $this->displayConfig;
		}

		if ( $this->formConfig !== [] ) {
			$out['forms'] = $this->formConfig;
		}

		if ( $this->targetNamespace !== null ) {
			$out['targetNamespace'] = $this->targetNamespace;
		}

		if ( $this->renderAs !== null ) {
			$out['renderAs'] = $this->renderAs;
		}

		return $out;
	}

	/* -------------------------------------------------------------------------
	 * NEW DISPLAY SYSTEM ACCESSORS
	 * ------------------------------------------------------------------------- */

	public function setDisplayTemplateProperty( ?PropertyModel $property ): void {
		$this->displayTemplateProperty = $property;
	}

	public function getDisplayTemplateProperty(): ?PropertyModel {
		return $this->displayTemplateProperty;
	}
}
