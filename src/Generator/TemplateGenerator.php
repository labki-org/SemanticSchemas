<?php

namespace MediaWiki\Extension\SemanticSchemas\Generator;

use InvalidArgumentException;
use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\PropertyModel;
use MediaWiki\Extension\SemanticSchemas\Schema\SubobjectModel;
use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;
use MediaWiki\Extension\SemanticSchemas\Store\WikiSubobjectStore;
use MediaWiki\Extension\SemanticSchemas\Util\NamingHelper;

/**
 * Generates core templates for proper Semantic MediaWiki functioning.
 *
 * Responsibilities:
 *   - Semantic template: Template:<Category>/semantic (stores data via #set)
 *   - Dispatcher template: Template:<Category> (coordinates form, storage, and display)
 *   - Subobject templates: Template:Subobject/<Name> (for nested data)
 */
class TemplateGenerator {

	private PageCreator $pageCreator;
	private WikiSubobjectStore $subobjectStore;
	private WikiPropertyStore $propertyStore;

	public function __construct(
		?PageCreator $pageCreator = null,
		?WikiSubobjectStore $subobjectStore = null,
		?WikiPropertyStore $propertyStore = null
	) {
		$this->pageCreator = $pageCreator ?? new PageCreator();
		$this->subobjectStore = $subobjectStore ?? new WikiSubobjectStore();
		$this->propertyStore = $propertyStore ?? new WikiPropertyStore();
	}

	/* =====================================================================
	 * PROPERTY VALUE EXPRESSION HELPER
	 * ===================================================================== */

	/**
	 * Generate the property line for a semantic template's #set block.
	 *
	 * All property values are wrapped in {{#if:...}} guards to prevent empty
	 * values from being stored. Multi-value properties use |+sep=, parameter.
	 *
	 * For Page-type properties with allowedNamespace, this adds the namespace
	 * prefix to ensure SMW correctly interprets values as page references.
	 *
	 * @param string $propertyName The SMW property name
	 * @param string $param The template parameter name
	 * @return string|null The wikitext for the property line, or null if handled elsewhere
	 */
	private function generatePropertyLine( string $propertyName, string $param ): ?string {
		$propModel = $this->propertyStore->readProperty( $propertyName );

		// Default: #if-guarded property = value (property not found in store)
		if ( !$propModel instanceof PropertyModel ) {
			return ' | ' . $propertyName . ' = {{#if:{{{' . $param . '|}}}|{{{' . $param . '|}}}|}}';
		}

		$allowedNamespace = $propModel->getAllowedNamespace();
		$isPageType = $propModel->isPageType();
		$allowsMultipleValues = $propModel->allowsMultipleValues();

		// Multi-value Page property with namespace: handled via inline annotations outside #set
		if ( $isPageType && $allowedNamespace !== null && $allowedNamespace !== '' && $allowsMultipleValues ) {
			return null;
		}

		// Single-value Page property with namespace: conditional namespace prefix
		if ( $isPageType && $allowedNamespace !== null && $allowedNamespace !== '' ) {
			return ' | ' . $propertyName . ' = {{#if:{{{' . $param . '|}}}|' .
				$allowedNamespace . ':{{{' . $param . '|}}}|}}';
		}

		// Multi-value property (non-Page or Page without namespace): #if guard with +sep
		if ( $allowsMultipleValues ) {
			return ' | ' . $propertyName . ' = {{#if:{{{' . $param . '|}}}|{{{' . $param . '|}}}|}}|+sep=,';
		}

		// Single-value property (non-Page or Page without namespace): #if guard
		return ' | ' . $propertyName . ' = {{#if:{{{' . $param . '|}}}|{{{' . $param . '|}}}|}}';
	}

	/**
	 * Generate inline annotation for multi-value Page property with namespace prefix.
	 *
	 * Uses [[Property::Namespace:Value]] syntax which creates separate property values.
	 * Wrapped in #if guard to prevent processing when parameter is empty.
	 *
	 * @param string $propertyName The SMW property name
	 * @param string $param The template parameter name
	 * @param string $allowedNamespace The namespace to prefix values with
	 * @return string The wikitext for inline annotations
	 */
	private function generateInlineAnnotation(
		string $propertyName,
		string $param,
		string $allowedNamespace
	): string {
		// Use #arraymap to create [[Property::Namespace:Value]] for each value, wrapped in #if
		return '{{#if:{{{' . $param . '|}}}|{{#arraymap:{{{' . $param .
			'|}}}|,|@@item@@|[[' . $propertyName . '::' . $allowedNamespace . ':@@item@@]]|}}|}}';
	}

	/* =====================================================================
	 * SEMANTIC TEMPLATE  (Template:<Category>/semantic)
	 * ===================================================================== */

	/**
	 * Generate content for the semantic template.
	 *
	 * @param CategoryModel $category
	 * @return string
	 */
	public function generateSemanticTemplate( CategoryModel $category ): string {
		$name = trim( $category->getName() );
		if ( $name === '' ) {
			throw new InvalidArgumentException( "Category name cannot be empty" );
		}

		$props = $category->getAllProperties();
		sort( $props );

		$out = [];
		$out[] = '<noinclude>';
		$out[] = '<!-- AUTO-GENERATED by SemanticSchemas - DO NOT EDIT MANUALLY -->';
		$out[] = 'Semantic template for Category:' . ( $name ?? '' );
		$out[] = '</noinclude><includeonly>';
		$out[] = '{{#set:';

		// Track properties that need inline annotations (multi-value Page with namespace)
		$inlineAnnotations = [];

		foreach ( $props as $p ) {
			$param = NamingHelper::propertyToParameter( $p );
			$line = $this->generatePropertyLine( $p, $param );

			if ( $line !== null ) {
				$out[] = $line;
			} else {
				// Property needs inline annotation - get the namespace
				$propModel = $this->propertyStore->readProperty( $p );
				if ( $propModel instanceof PropertyModel ) {
					$allowedNamespace = $propModel->getAllowedNamespace();
					if ( $allowedNamespace !== null && $allowedNamespace !== '' ) {
						$inlineAnnotations[] = $this->generateInlineAnnotation( $p, $param, $allowedNamespace );
					}
				}
			}
		}

		$out[] = '}}';

		// Add inline annotations for multi-value Page properties
		foreach ( $inlineAnnotations as $annotation ) {
			$out[] = $annotation;
		}

		$out[] = '';
		$out[] = '[[Category:' . ( $name ?? '' ) . ']]';
		$out[] = '</includeonly>';

		return implode( "\n", $out );
	}

	/* =====================================================================
	 * DISPATCHER TEMPLATE (Template:<Category>)
	 * ===================================================================== */

	/**
	 * Generate a template call with property parameter passthrough.
	 *
	 * @param string $templateName Template name to call
	 * @param array $props List of property names
	 * @return array Lines of wikitext
	 */
	private function generateTemplateCall( string $templateName, array $props ): array {
		$out = [];
		$out[] = '{{' . $templateName;
		foreach ( $props as $p ) {
			$param = NamingHelper::propertyToParameter( $p );
			$out[] = ' | ' . $param . ' = {{{' . $param . '|}}}';
		}
		$out[] = '}}';
		return $out;
	}

	/**
	 * Generate content for the dispatcher template.
	 *
	 * @param CategoryModel $category
	 * @return string
	 */
	public function generateDispatcherTemplate( CategoryModel $category ): string {
		$name = trim( $category->getName() );
		if ( $name === '' ) {
			throw new InvalidArgumentException( "Category name cannot be empty" );
		}

		$props = $category->getAllProperties();
		sort( $props );

		$cat = $name;

		$out = [];
		$out[] = '<noinclude>';
		$out[] = '<!-- AUTO-GENERATED by SemanticSchemas - DO NOT EDIT MANUALLY -->';
		$out[] = 'Dispatcher template for Category:' . $cat;
		$out[] = '</noinclude><includeonly>';
		$out[] = '{{#default_form:' . $cat . '}}';
		$out[] = '';

		/* Semantic storage */
		$out = array_merge( $out, $this->generateTemplateCall( $cat . '/semantic', $props ) );
		$out[] = '';

		/* Display template (delegated to static display template) */
		$out = array_merge( $out, $this->generateTemplateCall( $cat . '/display', $props ) );
		$out[] = '';

		/* Subobject Sections */
		$out = array_merge(
			$out,
			$this->generateSubobjectDisplaySections( $category )
		);

		$out[] = '</includeonly>';

		return implode( "\n", $out );
	}

	/* =====================================================================
	 * SUBOBJECT TEMPLATES  (Template:Subobject/<Name>)
	 * ===================================================================== */

	private function generateSubobjectTemplate( SubobjectModel $sub ): string {
		$name = $sub->getName() ?? '';

		$out = [];
		$out[] = '<noinclude>';
		$out[] = '<!-- AUTO-GENERATED by SemanticSchemas - DO NOT EDIT MANUALLY -->';
		$out[] = 'Subobject semantic template for Subobject:' . $name;
		$out[] = '</noinclude><includeonly>';

		$out[] = '{{#subobject:';
		$out[] = ' | Has subobject type = Subobject:' . $name;

		$props = array_merge(
			$sub->getRequiredProperties(),
			$sub->getOptionalProperties()
		);

		foreach ( $props as $p ) {
			$param = NamingHelper::propertyToParameter( $p );
			$line = $this->generatePropertyLine( $p, $param );
			// For subobjects, skip multi-value Page properties (null return)
			// as inline annotations don't work with #subobject
			if ( $line !== null ) {
				$out[] = $line;
			}
		}

		$out[] = '}}';
		$out[] = '</includeonly>';

		return implode( "\n", $out );
	}

	private function generateSubobjectRowTemplate( SubobjectModel $sub ): string {
		$name = $sub->getName() ?? '';
		$props = $sub->getAllProperties();

		$out = [];
		$out[] = '<noinclude>Auto-generated row template for subobject ' . $name . '</noinclude>';
		$out[] = '<includeonly>';
		$out[] = '|-';

		$i = 2;
		foreach ( $props as $p ) {
			$out[] = '| {{{' . $i . '|}}}';
			$i++;
		}

		$out[] = '</includeonly>';

		return implode( "\n", $out );
	}

	/* =====================================================================
	 * SUBOBJECT DISPLAY
	 * ===================================================================== */

	private function generateSubobjectDisplaySections( CategoryModel $category ): array {
		$required = $category->getRequiredSubobjects();
		$optional = $category->getOptionalSubobjects();

		$all = array_unique( array_merge( $required, $optional ) );
		if ( empty( $all ) ) {
			return [];
		}

		$out = [];

		foreach ( $all as $subName ) {
			$sub = $this->subobjectStore->readSubobject( $subName );
			if ( !$sub instanceof SubobjectModel ) {
				wfLogWarning( "SemanticSchemas: Missing subobject definition '$subName'" );
				continue;
			}

			$label = $sub->getLabel() ?: $sub->getName();
			$props = $sub->getAllProperties();
			if ( empty( $props ) ) {
				continue;
			}

			$out[] = '=== ' . ( $label ?? '' ) . ' ===';
			$out[] = '';
			$out[] = '{| class="wikitable ss-subobject-table"';
			$out[] = '|-';

			/* header row */
			foreach ( $props as $p ) {
				$lab = NamingHelper::generatePropertyLabel( $p );
				$out[] = '! ' . ( $lab ?? '' );
			}

			/* SMW #ask invocation */
			$askQuery = '{{#ask: [[-Has subobject::{{FULLPAGENAME}}]] '
				. '[[Has subobject type::Subobject:' . ( $subName ?? '' ) . ']]';
			$out[] = $askQuery;

			foreach ( $props as $p ) {
				$out[] = ' | ?' . ( $p ?? '' );
			}

			$rowTemplate = 'Subobject/' . ( $subName ?? '' ) . '/row';

			$out[] = ' | format=template';
			$out[] = ' | template=' . $rowTemplate;
			$out[] = ' | default=<tr><td colspan="' . count( $props ) . '">No entries yet.</td></tr>';
			$out[] = '}}';
			$out[] = '|}';
			$out[] = '';
		}

		return $out;
	}

	/* =====================================================================
	 * PUBLIC: FULL TEMPLATE GENERATION ENTRYPOINT
	 * ===================================================================== */

	/**
	 * Generate all artifacts for a category (semantic, dispatcher, subobjects).
	 *
	 * @param CategoryModel $category
	 * @return array{success: bool, errors: string[]}
	 */
	public function generateAllTemplates( CategoryModel $category ): array {
		$errors = [];
		$name = $category->getName();

		/* Category semantic template */
		try {
			$content = $this->generateSemanticTemplate( $category );
			$this->updateTemplate( $name . '/semantic', $content );
		} catch ( \Exception $e ) {
			$errors[] = "Error generating semantic template for $name: " . $e->getMessage();
		}

		/* Dispatcher template */
		try {
			$content = $this->generateDispatcherTemplate( $category );
			$this->updateTemplate( $name, $content );
		} catch ( \Exception $e ) {
			$errors[] = "Error generating dispatcher template for $name: " . $e->getMessage();
		}

		/* Subobject templates */
		$subs = array_unique( array_merge(
			$category->getRequiredSubobjects(),
			$category->getOptionalSubobjects()
		) );

		foreach ( $subs as $subName ) {
			try {
				$sub = $this->subobjectStore->readSubobject( $subName );
				if ( !$sub instanceof SubobjectModel ) {
					$errors[] = "Missing subobject '$subName'";
					continue;
				}

				/* semantic template */
				$content = $this->generateSubobjectTemplate( $sub );
				$this->updateTemplate( 'Subobject/' . $sub->getName(), $content );

				/* row template */
				$rowContent = $this->generateSubobjectRowTemplate( $sub );
				$this->updateTemplate( 'Subobject/' . $sub->getName() . '/row', $rowContent );

			} catch ( \Exception $e ) {
				$errors[] = "Error generating templates for subobject '$subName': " . $e->getMessage();
			}
		}

		return [
			'success' => empty( $errors ),
			'errors' => $errors
		];
	}

	/* =====================================================================
	 * BASIC UPDATE WRAPPER
	 * ===================================================================== */

	private function updateTemplate( string $name, string $content ): bool {
		if ( trim( $name ) === '' ) {
			throw new InvalidArgumentException( "Template name cannot be empty" );
		}

		$title = $this->pageCreator->makeTitle( $name, NS_TEMPLATE );
		if ( !$title ) {
			wfLogWarning( "SemanticSchemas: Failed to create Title for template '$name'" );
			return false;
		}

		return $this->pageCreator->createOrUpdatePage(
			$title,
			$content,
			'SemanticSchemas: Auto-generated template'
		);
	}

	/**
	 * Check if the semantic template exists for a category.
	 *
	 * @param string $categoryName
	 * @return bool
	 */
	public function semanticTemplateExists( string $categoryName ): bool {
		$templateName = trim( $categoryName ) . '/semantic';
		$title = $this->pageCreator->makeTitle( $templateName, NS_TEMPLATE );
		return $title && $this->pageCreator->pageExists( $title );
	}
}
