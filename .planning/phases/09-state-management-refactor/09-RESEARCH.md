# Phase 9: State Management Refactor - Research

**Researched:** 2026-02-03
**Domain:** Internal refactoring of StateManager/PageHashComputer for template-level hashing
**Confidence:** HIGH

## Summary

This phase refactors the StateManager's hash-based dirty detection from page-level hashing (Category, Property, Subobject pages) to template-level hashing (Template:X, Template:X/semantic, Template:X/display, Form:X). The current system hashes schema model data (via `CategoryModel::toArray()`, `PropertyModel::toArray()`, `SubobjectModel::toArray()`) and stores it in `MediaWiki:SemanticSchemasState.json`. This works for single-category pages but creates false-positive dirty warnings for multi-category pages because a change to one category's schema invalidates the entire page hash even though only that category's template changed.

The refactor is purely internal -- no new libraries, no new external dependencies. It modifies how hashes are computed and stored so that each generated template is tracked independently. This means when Category:Person's schema changes, only `Template:Person/semantic` is flagged as dirty, not `Template:Employee/semantic` (even though they may appear on the same multi-category page).

The key insight from codebase analysis: the current `computeAllSchemaHashes()` in SpecialSemanticSchemas hashes schema entities (Category, Property, Subobject) but the generated artifacts are templates and forms. The mismatch means dirty detection tracks "did the input schema change?" rather than "did the output template content change?" The refactor should hash the actual generated artifacts (templates, forms) rather than the schema models, OR hash per-category schema data independently and associate it with the correct template keys.

**Primary recommendation:** Hash generated template content directly (SHA256 of the wikitext output from generators) and key state entries by template page name (`Template:Category/semantic`, `Template:Category`, `Form:Category`), not by schema entity name (`Category:Name`).

## Standard Stack

No new libraries needed. This is an internal refactoring phase.

### Core
| Component | Location | Purpose | Role in Refactor |
|-----------|----------|---------|------------------|
| StateManager | `src/Store/StateManager.php` | State JSON persistence | Primary refactor target -- change key structure |
| PageHashComputer | `src/Store/PageHashComputer.php` | SHA256 hash computation | Add template content hashing methods |
| TemplateGenerator | `src/Generator/TemplateGenerator.php` | Generates semantic/dispatcher templates | Source of content to hash |
| FormGenerator | `src/Generator/FormGenerator.php` | Generates PageForms forms | Source of content to hash |
| CompositeFormGenerator | `src/Generator/CompositeFormGenerator.php` | Multi-category form generation | Source of composite form content |
| OntologyInspector | `src/Schema/OntologyInspector.php` | Validation + dirty detection | Consumer of hash comparison |
| SpecialSemanticSchemas | `src/Special/SpecialSemanticSchemas.php` | Admin UI / generation flow | Calls computeAllSchemaHashes + setPageHashes |

### Supporting
| Component | Location | Purpose | When Used |
|-----------|----------|---------|-----------|
| PageCreator | `src/Store/PageCreator.php` | Wiki page read/write | Reading existing template content for hashing |
| CategoryModel | `src/Schema/CategoryModel.php` | Immutable category data | Input to template generation |
| RegenerateArtifacts | `maintenance/regenerateArtifacts.php` | CLI regeneration | Needs hash updates added |

## Architecture Patterns

### Current Architecture (Before Refactor)

```
Schema Import/Generate Flow:
  1. Generate templates and forms (TemplateGenerator, FormGenerator)
  2. computeAllSchemaHashes() hashes schema MODELS (CategoryModel.toArray(), etc.)
  3. StateManager.setPageHashes() stores: "Category:Name" => hash(model)
  4. Keys: "Category:Person", "Property:Has name", "Subobject:Display section"

Validation Flow:
  1. OntologyInspector.validateWikiState() reads current model from wiki
  2. Hashes current model: hash(CategoryModel.toArray())
  3. Compares against stored hashes
  4. If different => "modified outside SemanticSchemas"
```

**Problem with multi-category pages:**
- Category:Person and Category:Employee share property "Has name"
- Changing Category:Person's schema changes hash("Category:Person")
- But Template:Person/semantic and Template:Employee/semantic are separate
- The system has no way to know WHICH template was actually affected
- Result: both templates are flagged as potentially dirty

### Recommended Architecture (After Refactor)

```
State JSON structure BEFORE:
{
  "pageHashes": {
    "Category:Person": { "generated": "sha256:abc...", "current": "sha256:abc..." },
    "Property:Has name": { "generated": "sha256:def...", "current": "sha256:def..." }
  }
}

State JSON structure AFTER:
{
  "templateHashes": {
    "Template:Person": { "generated": "sha256:...", "category": "Person" },
    "Template:Person/semantic": { "generated": "sha256:...", "category": "Person" },
    "Form:Person": { "generated": "sha256:...", "category": "Person" },
    "Template:Person/display": { "generated": "sha256:...", "category": "Person" }
  },
  "pageHashes": {
    "Category:Person": { "generated": "sha256:...", "current": "sha256:..." },
    "Property:Has name": { "generated": "sha256:...", "current": "sha256:..." }
  }
}
```

### Pattern 1: Hash Generated Content, Not Input Models

**What:** Compute hash of the actual wikitext content that generators produce, not the schema model data.

**When to use:** Always, for template/form hash tracking.

**Rationale:** The generator output is what actually gets written to the wiki page. If the generator produces identical output for two different model states (e.g., a comment change in description that doesn't affect template wikitext), hashing the model would create false positives. Hashing the output is semantically correct -- "did the generated artifact change?"

**Example:**
```php
// In TemplateGenerator or a coordinating method:
$semanticContent = $this->generateSemanticTemplate( $category );
$hash = hash( 'sha256', $semanticContent );
// Store: "Template:CategoryName/semantic" => "sha256:$hash"
```

### Pattern 2: Separate Schema Hashes from Template Hashes

**What:** Keep the existing `pageHashes` for schema entity tracking (Category, Property, Subobject pages) AND add a separate `templateHashes` section for generated artifact tracking.

**When to use:** The schema hashes serve a different purpose (detecting external edits to Category:/Property: pages) than template hashes (detecting regeneration needs).

**Rationale:** The current `pageHashes` system answers "has someone edited Category:Person outside SemanticSchemas?" This is valuable and should be preserved. The new `templateHashes` answers "does Template:Person/semantic match what we last generated?" These are orthogonal concerns.

### Pattern 3: Category Attribution on Template Hashes

**What:** Each template hash entry includes a `category` field indicating which category schema produced it.

**When to use:** Always, to enable per-category dirty detection.

**Rationale:** When Category:Person's schema changes, we need to know which templates to flag as needing regeneration. By attributing each template hash to its source category, we can compute: "Person schema changed => Template:Person, Template:Person/semantic, Form:Person need regeneration" without touching Employee's templates.

### Anti-Patterns to Avoid

- **Hashing the wiki page content instead of the generated content:** Wiki pages may have content outside SemanticSchemas markers. Hashing the full page content would create false positives from user edits to non-managed sections.
- **Replacing pageHashes entirely:** The existing `pageHashes` system tracks schema entity pages (Category:X, Property:Y). It should be preserved for backward compatibility and because it serves a different detection purpose.
- **Coupling hash computation to page creation:** Hash computation should be separate from writing to wiki -- compute hash from the generated string, not by reading the page after writing.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| SHA256 hashing | Custom hash function | PHP's `hash('sha256', $content)` | Already used in PageHashComputer, proven |
| JSON state persistence | File-based storage | MediaWiki page (existing pattern) | StateManager already uses MediaWiki:SemanticSchemasState.json |
| Template content generation | Re-implementing generation | Existing generators' output | TemplateGenerator/FormGenerator already produce the content |
| State migration | Complex migration script | Forward-compatible merge in getState() | The array_merge pattern in getState() already handles missing keys |

## Common Pitfalls

### Pitfall 1: Breaking Backward Compatibility of State JSON

**What goes wrong:** Changing the state JSON structure breaks existing installations that have the old format.
**Why it happens:** The `getDefaultState()` returns a structure that `getState()` merges with stored data. If the stored data has `pageHashes` but the new code expects `templateHashes`, old data is lost.
**How to avoid:** Use the existing `array_merge($defaults, $stored)` pattern in `getState()`. Add `templateHashes` to `getDefaultState()` as an empty array. Old `pageHashes` data is preserved automatically. New code reads `templateHashes`; old code still reads `pageHashes`.
**Warning signs:** Tests for single-category state tracking fail after refactor.

### Pitfall 2: False Positives from Generator Non-Determinism

**What goes wrong:** Generators produce slightly different output for the same input (whitespace, property ordering), causing hashes to differ even when nothing meaningful changed.
**Why it happens:** Array iteration order, whitespace variations, or non-deterministic sort results.
**How to avoid:** The generators already sort properties (`sort($props)` in TemplateGenerator). Verify this is consistent. Hash the exact output string from the generator, which is deterministic if inputs are sorted.
**Warning signs:** Running generation twice without schema changes produces different hashes.

### Pitfall 3: Missing Template Types in Hash Tracking

**What goes wrong:** Only hashing semantic templates but forgetting dispatcher templates, forms, or subobject templates.
**Why it happens:** The three-template system (dispatcher, semantic, display) plus forms means each category produces 3-4 artifacts. It is easy to miss one.
**How to avoid:** Enumerate all generated artifact types per category:
  - `Template:Category` (dispatcher)
  - `Template:Category/semantic` (semantic)
  - `Template:Category/display` (display stub -- only if generated)
  - `Form:Category` (form)
  - `Template:Subobject/Name` (subobject semantic, per subobject)
  - `Template:Subobject/Name/row` (subobject row, per subobject)
**Warning signs:** Some template modifications go undetected.

### Pitfall 4: Composite Form Hash Attribution

**What goes wrong:** Composite forms (e.g., Form:Employee+Person) are generated from multiple categories. Attributing it to a single category is wrong.
**Why it happens:** Composite forms are a new artifact type that doesn't map to a single category.
**How to avoid:** Allow `templateHashes` entries to have multiple categories in the attribution, or use the composite form name as its own entity. When any contributing category changes, the composite form should be flagged.
**Warning signs:** Changing one category in a composite form doesn't flag the form for regeneration.

### Pitfall 5: Display Template Special Case

**What goes wrong:** Display templates (`Template:Category/display`) are user-editable stubs, not fully auto-generated. Tracking their hash the same way as auto-generated templates creates false dirty warnings.
**Why it happens:** DisplayStubGenerator generates initial content but users customize it.
**How to avoid:** Either exclude display templates from auto-generated template hashing, or only track the initial generation hash and mark them as "user-editable" in the state. The current system already handles this distinction since display templates are only generated when explicitly requested (`generate-display` flag).
**Warning signs:** User edits to display templates trigger dirty warnings.

## Code Examples

### Current Hash Flow (lines 1322-1346 of SpecialSemanticSchemas.php)

```php
// Current: hashes schema MODELS
private function computeAllSchemaHashes(): array {
    $hashComputer = new PageHashComputer();
    $pageHashes = [];

    foreach ( $categoryStore->getAllCategories() as $category ) {
        $name = $category->getName();
        $pageHashes["Category:$name"] = $hashComputer->computeCategoryModelHash( $category );
    }
    // ... same for properties and subobjects
    return $pageHashes;
}
```

### Proposed Template Hash Flow

```php
// New: hashes generated TEMPLATE CONTENT
private function computeAllTemplateHashes(
    array $categories,
    TemplateGenerator $templateGen,
    FormGenerator $formGen,
    InheritanceResolver $resolver
): array {
    $templateHashes = [];

    foreach ( $categories as $category ) {
        $effective = $resolver->getEffectiveCategory( $category->getName() );
        $name = $category->getName();

        // Hash semantic template content
        $semanticContent = $templateGen->generateSemanticTemplate( $effective );
        $templateHashes["Template:$name/semantic"] = [
            'generated' => 'sha256:' . hash( 'sha256', $semanticContent ),
            'category' => $name,
        ];

        // Hash dispatcher template content
        $dispatcherContent = $templateGen->generateDispatcherTemplate( $effective );
        $templateHashes["Template:$name"] = [
            'generated' => 'sha256:' . hash( 'sha256', $dispatcherContent ),
            'category' => $name,
        ];

        // Hash form content
        $formContent = $formGen->generateForm( $effective );
        $templateHashes["Form:$name"] = [
            'generated' => 'sha256:' . hash( 'sha256', $formContent ),
            'category' => $name,
        ];
    }

    return $templateHashes;
}
```

### Proposed StateManager Changes

```php
// New default state structure
private function getDefaultState(): array {
    return [
        'dirty' => false,
        'lastChangeTimestamp' => null,
        'generated' => null,
        'sourceSchemaHash' => null,
        'pageHashes' => [],        // PRESERVED: schema entity tracking
        'templateHashes' => [],    // NEW: generated artifact tracking
    ];
}

// New method for template hashes
public function setTemplateHashes( array $hashes ): bool {
    $state = $this->getState();
    $templateHashes = $state['templateHashes'] ?? [];

    foreach ( $hashes as $templateName => $hashData ) {
        $templateHashes[$templateName] = $hashData;
    }

    $state['templateHashes'] = $templateHashes;
    $state['generated'] = wfTimestamp( TS_ISO_8601 );
    return $this->saveState( $state );
}

// New method: get templates needing regeneration after schema change
public function getStaleTemplates( array $currentTemplateHashes ): array {
    $state = $this->getState();
    $stored = $state['templateHashes'] ?? [];
    $stale = [];

    foreach ( $currentTemplateHashes as $templateName => $hashData ) {
        $storedHash = $stored[$templateName]['generated'] ?? '';
        if ( $storedHash !== $hashData['generated'] ) {
            $stale[] = $templateName;
        }
    }

    // Templates that were stored but no longer generated
    foreach ( $stored as $templateName => $hashData ) {
        if ( !isset( $currentTemplateHashes[$templateName] ) ) {
            $stale[] = $templateName;
        }
    }

    return $stale;
}
```

### Proposed OntologyInspector Changes

```php
// In validateWikiState():
// Keep existing schema hash comparison for Category/Property/Subobject pages
// Add template hash comparison for generated artifacts

$templateHashes = $state['templateHashes'] ?? [];
if ( !empty( $templateHashes ) ) {
    $currentTemplateHashes = $this->computeCurrentTemplateHashes();
    $staleTemplates = $this->stateManager->getStaleTemplates( $currentTemplateHashes );

    if ( !empty( $staleTemplates ) ) {
        $warnings[] = 'Templates needing regeneration: ' . implode( ', ', $staleTemplates );
    }
}
```

## State of the Art

| Old Approach (Current) | New Approach (Phase 9) | Impact |
|------------------------|------------------------|--------|
| Page-level hashing of schema models | Template-level hashing of generated content | Eliminates false-positive dirty detection for multi-category pages |
| Single `pageHashes` map | Dual `pageHashes` + `templateHashes` maps | Preserves backward compatibility while adding new capability |
| Hash(CategoryModel.toArray()) | Hash(generator output string) | Semantically correct: tracks actual artifact content, not input data |
| Global dirty flag | Per-template staleness detection | Finer-grained regeneration decisions |

## Open Questions

1. **Should the maintenance script also update template hashes?**
   - What we know: `regenerateArtifacts.php` currently does not call StateManager at all
   - What's unclear: Whether this is intentional or an oversight
   - Recommendation: Add template hash updates to the maintenance script for consistency, but this may be a follow-up task rather than part of the core refactor

2. **How should composite form hashes be attributed?**
   - What we know: Composite forms are named like `Form:Employee+Person` and depend on multiple categories
   - What's unclear: Whether to attribute to all contributing categories or treat as its own entity
   - Recommendation: Store with `categories: ["Employee", "Person"]` (array instead of single `category`) so any change to a contributing category flags the composite form

3. **Should display template stubs be tracked in templateHashes?**
   - What we know: Display templates are user-editable and only generated on explicit request
   - What's unclear: Whether tracking their initial generation hash is useful
   - Recommendation: Exclude display templates from `templateHashes` since they are designed to be user-modified. Keep them tracked only in `pageHashes` for the existing "modified outside" detection.

## Sources

### Primary (HIGH confidence)
- Codebase analysis of `src/Store/StateManager.php` (259 lines) -- full hash tracking implementation
- Codebase analysis of `src/Store/PageHashComputer.php` (197 lines) -- hash computation methods
- Codebase analysis of `src/Schema/OntologyInspector.php` (225 lines) -- validation + dirty detection consumer
- Codebase analysis of `src/Special/SpecialSemanticSchemas.php` (lines 1322-1465) -- generation flow with hash computation
- Codebase analysis of `src/Generator/TemplateGenerator.php` (453 lines) -- template content generation
- Codebase analysis of `src/Generator/FormGenerator.php` (412 lines) -- form content generation
- Codebase analysis of `src/Generator/CompositeFormGenerator.php` (347 lines) -- multi-category form generation

### Secondary (HIGH confidence)
- `.planning/STATE.md` -- accumulated decisions and known risks
- `.planning/ROADMAP.md` -- Phase 9 requirements and success criteria

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH -- entirely internal code, no external dependencies
- Architecture: HIGH -- directly analyzed the current hash flow and identified precise problem/solution
- Pitfalls: HIGH -- derived from actual code patterns and multi-category page behavior

**Research date:** 2026-02-03
**Valid until:** No expiration (internal codebase analysis, no external dependencies)
