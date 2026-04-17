<?php

namespace MediaWiki\Extension\SemanticSchemas\Schema;

use InvalidArgumentException;
use MediaWiki\Extension\SemanticSchemas\Util\NamingHelper;

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
 *   properties: FieldModel[]
 *   subobjects: FieldModel[]
 *
 *   display:
 *     format: string|null
 *
 *   forms:
 *     sections: [
 *        [ 'name' => string, 'properties' => string[] ],
 *     ]
 *
 * Schema data is immutable after construction. For the fully merged
 * (inherited) view, see EffectiveCategoryModel returned by InheritanceResolver.
 */
class CategoryModel {

	private string $name;
	private array $parents;

	private string $label;
	private string $description;

	private ?string $targetNamespace;
	private ?string $renderAs;

	/** @var FieldModel[] */
	private array $propertyFields;

	/** @var FieldModel[] */
	private array $subobjectFields;

	/** @var string[] Property names whose incoming links to show as backlinks. */
	private array $backlinksFor;

	private array $displayConfig;
	private array $formConfig;

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

		$this->parents = NamingHelper::normalizeList( $data['parents'] ?? [] );
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
		FieldModel::validateNoDuplicates( $props, "Category '{$name}'" );
		$this->propertyFields = $props;

		/* -------------------- Subobjects -------------------- */

		$subs = $data['subobjects'] ?? [];
		if ( !is_array( $subs ) ) {
			throw new InvalidArgumentException( "Category '{$name}': 'subobjects' must be an array." );
		}
		FieldModel::validateNoDuplicates( $subs, "Category '{$name}'" );
		$this->subobjectFields = $subs;

		/* -------------------- Backlinks -------------------- */

		$this->backlinksFor = NamingHelper::normalizeList( $data['backlinksFor'] ?? [] );

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

	/* -------------------- Property Fields -------------------- */

	/** @return FieldModel[] */
	public function getPropertyFields(): array {
		return $this->propertyFields;
	}

	/* -------------------- Subobject Fields -------------------- */

	/** @return FieldModel[] */
	public function getSubobjectFields(): array {
		return $this->subobjectFields;
	}

	public function hasSubobjects(): bool {
		return $this->subobjectFields !== [];
	}

	/* -------------------- Backlinks -------------------- */

	/** @return string[] */
	public function getBacklinksFor(): array {
		return $this->backlinksFor;
	}

	/* -------------------- Display + Forms -------------------- */

	public function getDisplayConfig(): array {
		return $this->displayConfig;
	}

	public function getDisplayFormat(): ?string {
		return $this->displayConfig['format'] ?? null;
	}

	public function getFormConfig(): array {
		return $this->formConfig;
	}

	/* -------------------------------------------------------------------------
	 * MERGING (CATEGORY + PARENT)
	 * ------------------------------------------------------------------------- */

	public function mergeWithParent( CategoryModel $parent ): EffectiveCategoryModel {
		/* -------------------- Properties -------------------- */

		$mergedProps = self::mergeFieldModels(
			$parent->getPropertyFields(),
			$this->propertyFields
		);

		/* -------------------- Subobjects -------------------- */

		$mergedSubs = self::mergeFieldModels(
			$parent->getSubobjectFields(),
			$this->subobjectFields
		);

		/* -------------------- Backlinks -------------------- */

		$mergedBacklinksFor = array_values( array_unique( array_merge(
			$parent->getBacklinksFor(),
			$this->backlinksFor
		) ) );

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

		/* -------------------- Merged Model -------------------- */

		return new EffectiveCategoryModel(
			$this->name,
			[
				'parents' => $this->parents,
				'label' => $this->label,
				'description' => $this->description,
				'targetNamespace' => $this->targetNamespace,
				'renderAs' => $this->renderAs ?? $parent->getRenderAs(),
				'properties' => $mergedProps,
				'subobjects' => $mergedSubs,
				'backlinksFor' => $mergedBacklinksFor,
				'display' => $mergedDisplay,
				'forms' => $mergedForms,
			]
		);
	}

	/**
	 * Merge parent and child field declarations.
	 *
	 * If a field appears in both, required wins (child can promote optional to required).
	 * Order: required fields first, then optional.
	 *
	 * @param FieldModel[] $parentFields
	 * @param FieldModel[] $childFields
	 * @return FieldModel[]
	 */
	private static function mergeFieldModels( array $parentFields, array $childFields ): array {
		$required = [];
		$optional = [];

		foreach ( array_merge( $parentFields, $childFields ) as $field ) {
			$name = $field->getName();
			if ( $field->isRequired() ) {
				$required[$name] = $field;
				unset( $optional[$name] );
			} elseif ( !isset( $required[$name] ) ) {
				$optional[$name] = $field;
			}
		}

		return array_values( array_merge( $required, $optional ) );
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

		if ( isset( $child['format'] ) ) {
			$merged['format'] = trim( (string)$child['format'] );
		}

		if ( isset( $child['templateProperty'] ) ) {
			$merged['templateProperty'] = $child['templateProperty'];
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
	 * EXPORT
	 * ------------------------------------------------------------------------- */

	public function toArray(): array {
		$out = [
			'parents' => $this->parents,
			'label' => $this->label,
			'description' => $this->description,
			'properties' => $this->propertyFields,
		];

		if ( $this->hasSubobjects() ) {
			$out['subobjects'] = $this->subobjectFields;
		}

		if ( $this->backlinksFor !== [] ) {
			$out['backlinksFor'] = $this->backlinksFor;
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
}
