<?php

namespace MediaWiki\Extension\StructureSync\Schema;

use MediaWiki\Extension\StructureSync\Store\WikiCategoryStore;
use MediaWiki\Extension\StructureSync\Store\WikiPropertyStore;
use MediaWiki\Extension\StructureSync\Store\StateManager;
use MediaWiki\Extension\StructureSync\Store\PageHashComputer;
use MediaWiki\Extension\StructureSync\Store\PageCreator;
use MediaWiki\Extension\StructureSync\Store\WikiSubobjectStore;
use MediaWiki\Title\Title;

/**
 * SchemaExporter
 * --------------
 * Converts the current wiki ontology into a structured schema array.
 *
 * IMPORTANT:
 *   - By default, exportToArray() exports the raw schema (no inheritance expansion).
 *   - Inherited expansion is only optional and intended ONLY for generation/debugging.
 *
 * Output is deterministic and suitable for comparison/diffing.
 */
class SchemaExporter {

    /** @var WikiCategoryStore */
    private $categoryStore;

    /** @var WikiPropertyStore */
    private $propertyStore;

	/** @var WikiSubobjectStore */
	private $subobjectStore;

    /** @var InheritanceResolver|null */
    private $inheritanceResolver;

    /** @var StateManager */
    private $stateManager;

    /** @var PageHashComputer */
    private $hashComputer;

    /** @var PageCreator */
    private $pageCreator;

    /** @var string */
    private const SCHEMA_VERSION = '1.0';

    public function __construct(
        WikiCategoryStore $categoryStore = null,
        WikiPropertyStore $propertyStore = null,
		WikiSubobjectStore $subobjectStore = null,
        InheritanceResolver $inheritanceResolver = null,
        StateManager $stateManager = null,
        PageHashComputer $hashComputer = null,
        PageCreator $pageCreator = null
    ) {
        $this->categoryStore = $categoryStore ?? new WikiCategoryStore();
        $this->propertyStore = $propertyStore ?? new WikiPropertyStore();
		$this->subobjectStore = $subobjectStore ?? new WikiSubobjectStore();
        $this->inheritanceResolver = $inheritanceResolver; // Optional injection
        $this->stateManager = $stateManager ?? new StateManager();
        $this->hashComputer = $hashComputer ?? new PageHashComputer();
        $this->pageCreator = $pageCreator ?? new PageCreator();
    }

	/**
	 * Export the wiki ontology to an array structure.
	 *
	 * Options:
	 *   - includeInherited (bool): If true, expand inherited properties
	 *   - continueOnError (bool): If true, skip failed items rather than aborting
	 *   - errorCallback (callable): Optional callback for error reporting
	 *
	 * @param bool $includeInherited  If true, expand inherited properties.
	 * @param array $options Additional export options
	 * @return array Schema with optional 'exportErrors' key
	 */
	public function exportToArray( bool $includeInherited = false, array $options = [] ): array {
		$continueOnError = $options['continueOnError'] ?? true;
		$errorCallback = $options['errorCallback'] ?? null;

		$categories = $this->categoryStore->getAllCategories();
		$properties = $this->propertyStore->getAllProperties();
		$subobjects = $this->subobjectStore->getAllSubobjects();

		// Stabilize key ordering for deterministic diffs
		ksort( $categories );
		ksort( $properties );
		ksort( $subobjects );

		$schema = [
			'schemaVersion' => self::SCHEMA_VERSION,
			'categories'    => [],
			'properties'    => [],
			'subobjects'    => [],
		];

		$exportErrors = [];

		// -------------------------------------------------------------
		// CATEGORY EXPORT
		// -------------------------------------------------------------
		if ( $includeInherited && !empty( $categories ) ) {
			$resolver = $this->inheritanceResolver ?? new InheritanceResolver( $categories );

			foreach ( $categories as $name => $category ) {
				try {
					$effective = $resolver->getEffectiveCategory( $name );
					$schema['categories'][$name] = $effective->toArray();
				} catch ( \RuntimeException $e ) {
					$error = "Category '$name': " . $e->getMessage();
					$exportErrors[] = $error;

					wfLogWarning( "StructureSync export: $error" );

					if ( $errorCallback !== null && is_callable( $errorCallback ) ) {
						call_user_func( $errorCallback, 'category', $name, $e );
					}

					if ( $continueOnError ) {
						// Fall back to raw category
						$schema['categories'][$name] = $category->toArray();
					} else {
						throw new \RuntimeException( "Export failed: $error", 0, $e );
					}
				}
			}
		}
		else {
			foreach ( $categories as $name => $category ) {
				try {
					$schema['categories'][$name] = $category->toArray();
				} catch ( \Exception $e ) {
					$error = "Category '$name': " . $e->getMessage();
					$exportErrors[] = $error;

					wfLogWarning( "StructureSync export: $error" );

					if ( $errorCallback !== null && is_callable( $errorCallback ) ) {
						call_user_func( $errorCallback, 'category', $name, $e );
					}

					if ( !$continueOnError ) {
						throw new \RuntimeException( "Export failed: $error", 0, $e );
					}
				}
			}
		}

		// -------------------------------------------------------------
		// PROPERTY EXPORT
		// -------------------------------------------------------------
		foreach ( $properties as $name => $property ) {
			try {
				$schema['properties'][$name] = $property->toArray();
			} catch ( \Exception $e ) {
				$error = "Property '$name': " . $e->getMessage();
				$exportErrors[] = $error;

				wfLogWarning( "StructureSync export: $error" );

				if ( $errorCallback !== null && is_callable( $errorCallback ) ) {
					call_user_func( $errorCallback, 'property', $name, $e );
				}

				if ( !$continueOnError ) {
					throw new \RuntimeException( "Export failed: $error", 0, $e );
				}
			}
		}

		// -------------------------------------------------------------
		// SUBOBJECT EXPORT
		// -------------------------------------------------------------
		foreach ( $subobjects as $name => $subobject ) {
			try {
				$schema['subobjects'][$name] = $subobject->toArray();
			} catch ( \Exception $e ) {
				$error = "Subobject '$name': " . $e->getMessage();
				$exportErrors[] = $error;
				wfLogWarning( "StructureSync export: $error" );
				if ( !$continueOnError ) {
					throw new \RuntimeException( "Export failed: $error", 0, $e );
				}
			}
		}

		// Include errors in result if any occurred
		if ( !empty( $exportErrors ) ) {
			$schema['exportErrors'] = $exportErrors;
		}

		return $schema;
	}

	/**
	 * Export only a subset of categories (and the properties they use).
	 *
	 * @param string[] $categoryNames
	 * @param array $options Export options (continueOnError, errorCallback)
	 * @return array
	 */
	public function exportCategories( array $categoryNames, array $options = [] ): array {
		$continueOnError = $options['continueOnError'] ?? true;
		$errorCallback = $options['errorCallback'] ?? null;

		$schema = [
			'schemaVersion' => self::SCHEMA_VERSION,
			'categories'    => [],
			'properties'    => [],
			'subobjects'    => [],
		];

		$exportErrors = [];
		$usedProperties = [];
		$usedSubobjects = [];

		foreach ( $categoryNames as $name ) {
			try {
				$category = $this->categoryStore->readCategory( $name );
				if ( !$category ) {
					$exportErrors[] = "Category '$name': not found";
					continue;
				}

				$schema['categories'][$name] = $category->toArray();
				$usedProperties = array_merge(
					$usedProperties,
					$category->getAllProperties()
				);
				$usedSubobjects = array_merge(
					$usedSubobjects,
					$category->getRequiredSubgroups(),
					$category->getOptionalSubgroups()
				);
			} catch ( \Exception $e ) {
				$error = "Category '$name': " . $e->getMessage();
				$exportErrors[] = $error;

				wfLogWarning( "StructureSync export: $error" );

				if ( $errorCallback !== null && is_callable( $errorCallback ) ) {
					call_user_func( $errorCallback, 'category', $name, $e );
				}

				if ( !$continueOnError ) {
					throw new \RuntimeException( "Export failed: $error", 0, $e );
				}
			}
		}

		// Deduplicate + sort for stability
		$usedProperties = array_unique( $usedProperties );
		sort( $usedProperties );

		foreach ( $usedProperties as $propertyName ) {
			try {
				$property = $this->propertyStore->readProperty( $propertyName );
				if ( $property ) {
					$schema['properties'][$propertyName] = $property->toArray();
				} else {
					$exportErrors[] = "Property '$propertyName': not found";
				}
			} catch ( \Exception $e ) {
				$error = "Property '$propertyName': " . $e->getMessage();
				$exportErrors[] = $error;

				wfLogWarning( "StructureSync export: $error" );

				if ( $errorCallback !== null && is_callable( $errorCallback ) ) {
					call_user_func( $errorCallback, 'property', $propertyName, $e );
				}

				if ( !$continueOnError ) {
					throw new \RuntimeException( "Export failed: $error", 0, $e );
				}
			}
		}

		$usedSubobjects = array_unique( array_filter( $usedSubobjects ) );
		sort( $usedSubobjects );

		foreach ( $usedSubobjects as $subobjectName ) {
			try {
				$subobject = $this->subobjectStore->readSubobject( $subobjectName );
				if ( $subobject ) {
					$schema['subobjects'][$subobjectName] = $subobject->toArray();
				} else {
					$exportErrors[] = "Subobject '$subobjectName': not found";
				}
			} catch ( \Exception $e ) {
				$error = "Subobject '$subobjectName': " . $e->getMessage();
				$exportErrors[] = $error;

				if ( !$continueOnError ) {
					throw new \RuntimeException( "Export failed: $error", 0, $e );
				}
			}
		}

		if ( !empty( $exportErrors ) ) {
			$schema['exportErrors'] = $exportErrors;
		}

		return $schema;
	}

    /**
     * Gather statistics about current ontology.
     *
     * @return array
     */
    public function getStatistics(): array {
        $categories = $this->categoryStore->getAllCategories();
        $properties = $this->propertyStore->getAllProperties();
        $subobjects = $this->subobjectStore->getAllSubobjects();

        $stats = [
            'categoryCount'            => count( $categories ),
            'propertyCount'            => count( $properties ),
            'subobjectCount'           => count( $subobjects ),
            'categoriesWithParents'    => 0,
            'categoriesWithProperties' => 0,
            'categoriesWithDisplay'    => 0,
            'categoriesWithForms'      => 0,
            'categoriesWithSubgroups'  => 0,
        ];

        foreach ( $categories as $cat ) {
            if ( $cat->getParents() ) {
                $stats['categoriesWithParents']++;
            }
            if ( $cat->getAllProperties() ) {
                $stats['categoriesWithProperties']++;
            }
            if ( $cat->getDisplayConfig() ) {
                $stats['categoriesWithDisplay']++;
            }
            if ( $cat->getFormConfig() ) {
                $stats['categoriesWithForms']++;
            }
            if ( $cat->getRequiredSubgroups() || $cat->getOptionalSubgroups() ) {
                $stats['categoriesWithSubgroups']++;
            }
        }

        return $stats;
    }

    /**
     * Validate the current wiki ontology using SchemaValidator.
     * Also checks for pages modified outside StructureSync.
     *
     * @return array ['errors' => [...], 'warnings' => [...], 'modifiedPages' => [...]]
     */
    public function validateWikiState(): array {
        $schema = $this->exportToArray( false );
        $validator = new SchemaValidator();

        $errors = $validator->validateSchema( $schema );
        $warnings = $validator->generateWarnings( $schema );
        $modifiedPages = [];

        // Check for pages modified outside StructureSync
        $storedHashes = $this->stateManager->getPageHashes();
        if ( !empty( $storedHashes ) ) {
            $currentHashes = [];
            $pagesToCheck = [];

            // Use revision timestamps as quick filter
            foreach ( $storedHashes as $pageName => $hashes ) {
                $storedGenerated = $hashes['generated'] ?? '';
                if ( $storedGenerated === '' ) {
                    continue;
                }

                // Parse page name to get namespace and title
               	if ( strpos( $pageName, 'Category:' ) === 0 ) {
                    $titleText = substr( $pageName, 9 );
                    $namespace = NS_CATEGORY;
                } elseif ( strpos( $pageName, 'Property:' ) === 0 ) {
                    $titleText = substr( $pageName, 9 );
                    $namespace = defined( 'SMW_NS_PROPERTY' ) ? \SMW_NS_PROPERTY : NS_MAIN;
                } else {
                    continue;
                }

                $title = $this->pageCreator->makeTitle( $titleText, $namespace );
                if ( !$title || !$title->exists() ) {
                    // Page was deleted
                    $modifiedPages[] = $pageName;
                    continue;
                }

                // Quick check: compare revision info if available
                $revInfo = $this->hashComputer->getPageRevisionInfo( $title );
                if ( $revInfo === null ) {
                    continue;
                }

                // For now, we'll compute hash for all pages
                // In a future optimization, we could store last revision ID and skip if unchanged
                $pagesToCheck[] = [
                    'pageName' => $pageName,
                    'title' => $title,
                    'namespace' => $namespace,
                ];
            }

            // Compute current hashes for pages to check
            foreach ( $pagesToCheck as $pageInfo ) {
                $title = $pageInfo['title'];
                $pageName = $pageInfo['pageName'];
                $namespace = $pageInfo['namespace'];

                $content = $this->pageCreator->getPageContent( $title );
                if ( $content === null ) {
                    continue;
                }

                if ( $namespace === NS_CATEGORY ) {
                    $hash = $this->hashComputer->computeCategoryHash( $content );
                } else {
                    $hash = $this->hashComputer->computePropertyHash( $content );
                }

                $currentHashes[$pageName] = $hash;
            }

            // Compare with stored hashes
            $modifiedPages = $this->stateManager->comparePageHashes( $currentHashes );

            // Update current hashes in state
            if ( !empty( $currentHashes ) ) {
                $this->stateManager->updateCurrentHashes( $currentHashes );
            }

            // Mark as dirty if there are modifications
            if ( !empty( $modifiedPages ) ) {
                $this->stateManager->setDirty( true );
                $warnings[] = 'Pages modified outside StructureSync: ' . implode( ', ', $modifiedPages );
            }
        }

        return [
            'errors' => $errors,
            'warnings' => $warnings,
            'modifiedPages' => $modifiedPages,
        ];
    }
}
