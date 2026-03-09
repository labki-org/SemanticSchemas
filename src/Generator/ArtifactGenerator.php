<?php

namespace MediaWiki\Extension\SemanticSchemas\Generator;

use MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel;
use MediaWiki\Extension\SemanticSchemas\Schema\InheritanceResolver;

/**
 * Orchestrates artifact generation (templates, forms, display stubs) for a category.
 *
 * Consolidates the three-generator pattern used by the Special page
 * and maintenance scripts.
 */
class ArtifactGenerator {

	private TemplateGenerator $templateGenerator;
	private FormGenerator $formGenerator;
	private DisplayStubGenerator $displayGenerator;

	public function __construct(
		TemplateGenerator $templateGenerator,
		FormGenerator $formGenerator,
		DisplayStubGenerator $displayGenerator
	) {
		$this->templateGenerator = $templateGenerator;
		$this->formGenerator = $formGenerator;
		$this->displayGenerator = $displayGenerator;
	}

	/**
	 * Generate all artifacts for a resolved (effective) category.
	 *
	 * @param CategoryModel $effective Category with inheritance resolved
	 * @param bool $forceDisplay If true, always generate/update display stub;
	 *   if false (default), only generate if no user customization exists.
	 * @return array{
	 *   success: bool,
	 *   errors: string[],
	 *   templateResult: array,
	 *   formSuccess: bool,
	 *   displayResult: array
	 * }
	 */
	public function generateForCategory(
		CategoryModel $effective,
		bool $forceDisplay = false
	): array {
		$result = [
			'success' => true,
			'errors' => [],
			'templateResult' => [],
			'formSuccess' => false,
			'displayResult' => [],
		];

		$result['templateResult'] = $this->templateGenerator->generateAllTemplates( $effective );
		if ( !$result['templateResult']['success'] ) {
			$result['success'] = false;
			$result['errors'] = $result['templateResult']['errors'] ?? [ 'Template generation failed' ];
			return $result;
		}

		$result['formSuccess'] = $this->formGenerator->generateAndSaveForm( $effective );
		if ( !$result['formSuccess'] ) {
			$result['success'] = false;
			$result['errors'][] = 'Failed to save form';
			return $result;
		}

		if ( $forceDisplay ) {
			$result['displayResult'] = $this->displayGenerator->generateOrUpdateDisplayStub( $effective );
		} else {
			$result['displayResult'] = $this->displayGenerator->generateIfAllowed( $effective );
		}

		return $result;
	}

	/**
	 * Generate artifacts for all (or a subset of) categories using inheritance resolution.
	 *
	 * Constructs an InheritanceResolver from the full category map, then generates
	 * artifacts for each target category. The full map is always used for resolver
	 * context even when generating a subset.
	 *
	 * @param array<string,CategoryModel> $categoryMap All categories keyed by name
	 * @param bool $forceDisplay If true, always generate/update display stubs
	 * @param string[]|null $onlyNames If set, only generate for these categories
	 * @return array{
	 *   results: array<string,array>,
	 *   successCount: int,
	 *   totalCount: int,
	 *   errors: string[]
	 * }
	 */
	public function generateAll(
		array $categoryMap,
		bool $forceDisplay = false,
		?array $onlyNames = null
	): array {
		$resolver = new InheritanceResolver( $categoryMap );

		$results = [];
		$successCount = 0;
		$errors = [];

		$names = $onlyNames ?? array_keys( $categoryMap );

		foreach ( $names as $name ) {
			try {
				$effective = $resolver->getEffectiveCategory( $name );
				$genResult = $this->generateForCategory( $effective, $forceDisplay );
				$results[$name] = $genResult;
				if ( $genResult['success'] ) {
					$successCount++;
				} else {
					$errors[] = "Failed to generate artifacts for '$name': "
						. implode( ', ', $genResult['errors'] );
				}
			} catch ( \Exception $e ) {
				$results[$name] = [
					'success' => false,
					'errors' => [ $e->getMessage() ],
				];
				$errors[] = "Failed to generate artifacts for '$name': " . $e->getMessage();
			}
		}

		return [
			'results' => $results,
			'successCount' => $successCount,
			'totalCount' => count( $names ),
			'errors' => $errors,
		];
	}

	/**
	 * Check whether the semantic template exists for a category.
	 */
	public function semanticTemplateExists( string $categoryName ): bool {
		return $this->templateGenerator->semanticTemplateExists( $categoryName );
	}

	/**
	 * Check whether the form exists for a category.
	 */
	public function formExists( string $categoryName ): bool {
		return $this->formGenerator->formExists( $categoryName );
	}

	/**
	 * Check whether the display stub exists for a category.
	 */
	public function displayStubExists( string $categoryName ): bool {
		return $this->displayGenerator->displayStubExists( $categoryName );
	}
}
