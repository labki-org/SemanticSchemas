# Phase 5: Property Resolution - Research

**Researched:** 2026-02-02
**Domain:** Multi-category property resolution in PHP (MediaWiki extension)
**Confidence:** HIGH

## Summary

Phase 5 introduces a `MultiCategoryResolver` that accepts one or more category names, resolves their inherited properties via the existing `InheritanceResolver`, then merges and deduplicates across categories. The research focused on understanding the existing codebase patterns to design an approach that fits naturally.

The codebase already has all the building blocks: `InheritanceResolver` handles C3 linearization for single-category chains, `CategoryModel` has required/optional property lists with silent promotion, and properties are identified by globally-unique wiki page titles making datatype conflicts impossible by design. The new resolver is a composition layer on top of existing infrastructure, not a replacement.

**Primary recommendation:** Create a single `MultiCategoryResolver` class in `src/Schema/` that accepts category names plus a pre-built `InheritanceResolver`, calls `getEffectiveCategory()` for each, then merges results into a `ResolvedPropertySet` value object with source attribution and required/optional status.

## Standard Stack

### Core

No external libraries needed. This phase is pure PHP using existing project classes.

| Class | Location | Purpose | Why Standard |
|-------|----------|---------|--------------|
| `InheritanceResolver` | `src/Schema/InheritanceResolver.php` | C3 linearization, single-category inheritance | Already proven; reuse, do not duplicate |
| `CategoryModel` | `src/Schema/CategoryModel.php` | Immutable category value object | Properties stored as string[] (required/optional) |
| `SubobjectModel` | `src/Schema/SubobjectModel.php` | Immutable subobject value object | Same required/optional pattern as properties |
| `PHPUnit\Framework\TestCase` | vendor | Unit testing | Project standard (PHPUnit 9.6) |

### Supporting

| Class | Location | Purpose | When to Use |
|-------|----------|---------|-------------|
| `WikiCategoryStore` | `src/Store/WikiCategoryStore.php` | Reads CategoryModels from wiki | Build the category map for InheritanceResolver |
| `NamingHelper` | `src/Util/NamingHelper.php` | Property-to-parameter naming | Reference only -- downstream consumers use this |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| New `ResolvedPropertySet` class | Raw arrays | Structured object is cleaner, provides typed accessors, matches project's value-object pattern |
| Accept `CategoryModel[]` input | Accept `string[]` category names | String names are simpler for callers (API, UI) but need the InheritanceResolver to be passed in; accept both |
| Separate subobject resolver | Unified resolver | Subobjects follow identical rules per CONTEXT.md -- single resolver handles both |

## Architecture Patterns

### Recommended Project Structure

New files only:
```
src/Schema/
    MultiCategoryResolver.php    # The resolver (new)
    ResolvedPropertySet.php      # Result value object (new)
tests/phpunit/unit/Schema/
    MultiCategoryResolverTest.php  # Unit tests (new)
```

### Pattern 1: Composition over Inheritance

**What:** `MultiCategoryResolver` takes an `InheritanceResolver` as a dependency, not extending it
**When to use:** Always -- the resolver is a cross-category merge layer, not an extension of single-category inheritance

This mirrors the existing pattern in `SpecialSemanticSchemas.php` lines 244-249 where `InheritanceResolver` is composed into the workflow:

```php
// Existing pattern (lines 244-249 of SpecialSemanticSchemas.php):
$categoryMap = $this->buildCategoryMap( $categoryStore );
$resolver = new InheritanceResolver( $categoryMap );
$effective = $resolver->getEffectiveCategory( $categoryName );
```

New pattern extends this naturally:

```php
// Phase 5 pattern:
$categoryMap = $this->buildCategoryMap( $categoryStore );
$inheritanceResolver = new InheritanceResolver( $categoryMap );
$multiResolver = new MultiCategoryResolver( $inheritanceResolver );
$resolved = $multiResolver->resolve( [ 'Person', 'Student', 'Researcher' ] );
```

### Pattern 2: Immutable Result Value Object

**What:** `ResolvedPropertySet` is a read-only value object holding the merged result
**When to use:** Always -- matches `CategoryModel` and `PropertyModel` immutable pattern

The result object should provide:
- List of all resolved properties (deduplicated)
- For each property: required status and source categories
- List of all resolved subobjects (deduplicated, same structure)
- List of contributing category names

```php
class ResolvedPropertySet {
    // Properties
    public function getRequiredProperties(): array;     // string[]
    public function getOptionalProperties(): array;     // string[]
    public function getAllProperties(): array;           // string[]
    public function getPropertySources( string $prop ): array;  // string[] category names
    public function isSharedProperty( string $prop ): bool;

    // Subobjects (identical API)
    public function getRequiredSubobjects(): array;
    public function getOptionalSubobjects(): array;
    public function getAllSubobjects(): array;
    public function getSubobjectSources( string $sub ): array;
    public function isSharedSubobject( string $sub ): bool;

    // Metadata
    public function getCategoryNames(): array;          // string[] all input categories
}
```

### Pattern 3: Silent Promotion for Required/Optional Conflicts

**What:** When a property is required in any category, promote to required in the merged result without warning
**When to use:** Always -- established in Phase 3 (CONTEXT.md confirms)

This directly mirrors `CategoryModel` constructor (line 121):
```php
// From CategoryModel constructor (lines 120-123):
$this->optionalProperties = array_values(
    array_diff( $this->optionalProperties, $this->requiredProperties )
);
```

And `mergeWithParent()` (lines 248-255):
```php
// From CategoryModel::mergeWithParent (lines 248-255):
$mergedOptional = array_values( array_diff(
    array_unique( array_merge(
        $parent->getOptionalProperties(),
        $this->optionalProperties
    ) ),
    $mergedRequired
) );
```

The resolver uses the same `array_diff` pattern for cross-category merge.

### Pattern 4: Single Entry Point for All Resolution

**What:** Even single-category pages go through `MultiCategoryResolver`
**When to use:** Always -- CONTEXT.md explicitly states "resolver is always the entry point"

This means the single-category case is just `resolve( [ 'Person' ] )` and returns properties as-is from the effective category.

### Anti-Patterns to Avoid

- **Duplicating C3 logic:** The `InheritanceResolver` already handles C3 linearization. The multi-category resolver must NOT re-implement this. It calls `getEffectiveCategory()` which returns a fully-merged `CategoryModel` per category.

- **Checking datatype conflicts:** Properties are wiki-global entities. Two categories referencing "Has name" reference the same `Property:Has name` with the same datatype. CONTEXT.md explicitly states: "No datatype conflict checking needed -- impossible by design, skip the check entirely." Requirement RESO-05 is satisfied by the design constraint, not by code.

- **Special-casing diamond inheritance:** Diamond patterns (two categories sharing a parent) just produce shared properties in the merge. The deduplication handles this naturally. No special diamond detection needed.

- **Sorting properties alphabetically in the resolver:** The resolver preserves C3 order within each category. Sorting is the generators' responsibility (as seen in `TemplateGenerator.generateSemanticTemplate()` line 125: `sort( $props )`).

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Single-category inheritance resolution | Custom parent traversal | `InheritanceResolver::getEffectiveCategory()` | Already handles C3, caching, circular detection |
| Property deduplication | Custom equality check | `array_unique()` on property name strings | Properties are identified by unique wiki page titles |
| Required/optional promotion | Custom conflict resolution | `array_diff()` pattern from CategoryModel | Proven pattern, used in both constructor and mergeWithParent() |
| Datatype conflict detection | Conflict checker class | Nothing (skip entirely) | Impossible by design -- properties are wiki-global |

**Key insight:** The property resolution algorithm is straightforward because the hard work (C3 linearization, parent merging, silent promotion within a category) is already done by `InheritanceResolver` and `CategoryModel`. The multi-category resolver only needs to merge the *results* of those operations across categories.

## Common Pitfalls

### Pitfall 1: Forgetting Subobjects

**What goes wrong:** Implementing property resolution but forgetting that subobjects follow identical rules
**Why it happens:** Requirements mention "properties" prominently; subobjects are easy to overlook
**How to avoid:** CONTEXT.md states "Subobjects handled identically to properties -- same deduplication, same merging rules, same output structure." Handle both in the same merge loop or parallel identical logic.
**Warning signs:** Test suite only covers properties, not subobjects

### Pitfall 2: Double-Flattening Inherited Properties

**What goes wrong:** Calling `getEffectiveCategory()` AND manually walking parent chains, resulting in duplicates
**Why it happens:** Not understanding that `getEffectiveCategory()` already returns the fully-merged model
**How to avoid:** Call `getEffectiveCategory()` once per category. Its result already includes all inherited properties and subobjects with correct required/optional status.
**Warning signs:** Duplicate properties in output, incorrect required status

### Pitfall 3: Property Ordering Assumptions

**What goes wrong:** Assuming properties need to be in a specific order in the resolver output
**Why it happens:** RESO-03 mentions "C3 linearization precedence" which sounds like the resolver must enforce ordering
**How to avoid:** `getEffectiveCategory()` already returns properties in the order they were accumulated through C3 merge. The resolver preserves this per-category order. Cross-category ordering is: process categories in input order, deduplicate by first-seen. Generators handle final sort.
**Warning signs:** Tests depend on specific property order across categories

### Pitfall 4: Empty Category Input

**What goes wrong:** Resolver crashes or returns unexpected structure for zero-length input
**Why it happens:** Edge case not considered
**How to avoid:** Empty array input returns an empty `ResolvedPropertySet`. Single-category input returns that category's properties wrapped in the result object.
**Warning signs:** No test for empty input

### Pitfall 5: MediaWiki Dependency in Unit Tests

**What goes wrong:** Tests fail outside MediaWiki because resolver depends on MW services
**Why it happens:** Accidentally importing WikiCategoryStore or other MW-dependent classes
**How to avoid:** `MultiCategoryResolver` depends ONLY on `InheritanceResolver` and `CategoryModel` (pure PHP). The caller builds the `InheritanceResolver` from `WikiCategoryStore`. Tests create `CategoryModel` and `InheritanceResolver` directly, no MW mocking needed.
**Warning signs:** Tests need `$this->markTestSkipped( 'requires MediaWiki' )`

## Code Examples

### Example 1: MultiCategoryResolver Construction and Use

```php
// Source: Derived from existing SpecialSemanticSchemas.php patterns (lines 244-266)

// Caller builds category map (this happens in SpecialPage or API)
$categoryStore = new WikiCategoryStore();
$categoryMap = [];
foreach ( $categoryStore->getAllCategories() as $cat ) {
    $categoryMap[$cat->getName()] = $cat;
}

// Create resolvers
$inheritanceResolver = new InheritanceResolver( $categoryMap );
$multiResolver = new MultiCategoryResolver( $inheritanceResolver );

// Resolve for selected categories
$resolved = $multiResolver->resolve( [ 'Person', 'Researcher' ] );

// Use results
$requiredProps = $resolved->getRequiredProperties();   // string[]
$optionalProps = $resolved->getOptionalProperties();   // string[]
$sources = $resolved->getPropertySources( 'Has name' ); // ['Person', 'Researcher']
$isShared = $resolved->isSharedProperty( 'Has name' );  // true
```

### Example 2: Cross-Category Merge Algorithm

```php
// Source: Derived from CategoryModel::mergeWithParent() pattern (lines 241-308)

// For each category, get the effective (fully inherited) model
$allRequired = [];
$allOptional = [];
$propertySources = [];

foreach ( $categoryNames as $name ) {
    $effective = $this->inheritanceResolver->getEffectiveCategory( $name );

    // Collect required
    foreach ( $effective->getRequiredProperties() as $prop ) {
        if ( !in_array( $prop, $allRequired, true ) ) {
            $allRequired[] = $prop;
        }
        $propertySources[$prop][] = $name;
    }

    // Collect optional
    foreach ( $effective->getOptionalProperties() as $prop ) {
        if ( !in_array( $prop, $allRequired, true )
             && !in_array( $prop, $allOptional, true ) ) {
            $allOptional[] = $prop;
        }
        $propertySources[$prop][] = $name;
    }
}

// Silent promotion: remove from optional anything that's in required
$allOptional = array_values( array_diff( $allOptional, $allRequired ) );

// Deduplicate sources
foreach ( $propertySources as $prop => $sources ) {
    $propertySources[$prop] = array_values( array_unique( $sources ) );
}
```

### Example 3: Unit Test Pattern (No MediaWiki Dependencies)

```php
// Source: Matches InheritanceResolverTest.php and CategoryModelTest.php patterns

class MultiCategoryResolverTest extends TestCase {

    public function testSingleCategoryReturnsItsProperties(): void {
        $map = [
            'Person' => new CategoryModel( 'Person', [
                'properties' => [
                    'required' => [ 'Has name' ],
                    'optional' => [ 'Has email' ],
                ],
            ] ),
        ];
        $ir = new InheritanceResolver( $map );
        $resolver = new MultiCategoryResolver( $ir );

        $result = $resolver->resolve( [ 'Person' ] );

        $this->assertEquals( [ 'Has name' ], $result->getRequiredProperties() );
        $this->assertEquals( [ 'Has email' ], $result->getOptionalProperties() );
    }

    public function testSharedPropertyDeduplication(): void {
        $map = [
            'Person' => new CategoryModel( 'Person', [
                'properties' => [ 'required' => [ 'Has name' ], 'optional' => [] ],
            ] ),
            'Employee' => new CategoryModel( 'Employee', [
                'properties' => [ 'required' => [ 'Has name', 'Has badge' ], 'optional' => [] ],
            ] ),
        ];
        $ir = new InheritanceResolver( $map );
        $resolver = new MultiCategoryResolver( $ir );

        $result = $resolver->resolve( [ 'Person', 'Employee' ] );

        // "Has name" appears once, attributed to both
        $allProps = $result->getAllProperties();
        $this->assertCount( 1, array_keys( array_filter(
            $allProps, fn( $p ) => $p === 'Has name'
        ) ) );
        $this->assertTrue( $result->isSharedProperty( 'Has name' ) );
        $this->assertFalse( $result->isSharedProperty( 'Has badge' ) );
    }

    public function testOptionalPromotedToRequiredAcrossCategories(): void {
        $map = [
            'Person' => new CategoryModel( 'Person', [
                'properties' => [ 'required' => [], 'optional' => [ 'Has phone' ] ],
            ] ),
            'Employee' => new CategoryModel( 'Employee', [
                'properties' => [ 'required' => [ 'Has phone' ], 'optional' => [] ],
            ] ),
        ];
        $ir = new InheritanceResolver( $map );
        $resolver = new MultiCategoryResolver( $ir );

        $result = $resolver->resolve( [ 'Person', 'Employee' ] );

        $this->assertContains( 'Has phone', $result->getRequiredProperties() );
        $this->assertNotContains( 'Has phone', $result->getOptionalProperties() );
    }
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Single-category generation only | Multi-category resolution planned (Phase 5) | v0.2.0 | New class required |
| Properties always sorted alphabetically | C3 order preserved per category, sort at generation time | Existing pattern | Resolver preserves insertion order |
| No cross-category merging | `MultiCategoryResolver` merges across categories | v0.2.0 | New capability |

**Existing patterns that remain unchanged:**
- `CategoryModel::mergeWithParent()` -- still used for single-category inheritance
- `InheritanceResolver::getEffectiveCategory()` -- still the per-category entry point
- Silent promotion via `array_diff` -- same algorithm, now applied cross-category
- Generators sort properties themselves -- resolver does not sort

## Open Questions

1. **Property ordering across categories**
   - What we know: Within each category, `getEffectiveCategory()` returns properties in C3-accumulation order. Generators currently sort alphabetically.
   - What's unclear: When merging across categories, should the first category's properties come before the second's? Or interleaved?
   - Recommendation: Use input-order precedence (first category's properties first, then unique properties from second, etc.). This is the simplest and matches the `array_merge` + `array_unique` pattern. Generators can re-sort as needed.

2. **RESO-05 requirement vs. CONTEXT.md decision**
   - What we know: RESO-05 says "Resolver detects conflicting property datatypes across categories and reports errors." CONTEXT.md says "No datatype conflict checking needed -- impossible by design, skip the check entirely."
   - What's unclear: Whether RESO-05 should be explicitly marked as "satisfied by design" or if it needs any code at all.
   - Recommendation: Mark RESO-05 as satisfied by design in the implementation. Include a brief doc comment in the resolver explaining why no check is needed. No runtime check code.

3. **Source attribution granularity**
   - What we know: CONTEXT.md leaves this to Claude's discretion. Options: (a) list all contributing category names, (b) boolean shared flag only.
   - Recommendation: Store full source lists (all contributing category names per property). The `isSharedProperty()` method derives from `count(sources) > 1`. This is more informative for downstream consumers (Phase 6 form generation, Phase 8 UI) at negligible cost.

## Sources

### Primary (HIGH confidence)

- `src/Schema/InheritanceResolver.php` -- C3 linearization algorithm, `getEffectiveCategory()` API
- `src/Schema/CategoryModel.php` -- Immutable model, `mergeWithParent()`, required/optional lists, silent promotion pattern
- `src/Schema/SubobjectModel.php` -- Identical required/optional structure to CategoryModel properties
- `src/Generator/TemplateGenerator.php` -- Downstream consumer, shows how properties are used (sorted, iterated)
- `src/Generator/FormGenerator.php` -- Downstream consumer, shows required/optional separation
- `src/Special/SpecialSemanticSchemas.php` -- Integration point, shows how InheritanceResolver is created and used
- `src/Store/WikiCategoryStore.php` -- Shows `getAllCategories()` pattern for building category map
- `tests/phpunit/unit/Schema/InheritanceResolverTest.php` -- Test patterns, PHPUnit style conventions
- `tests/phpunit/unit/Schema/CategoryModelTest.php` -- Test patterns, assertion style
- `tests/phpunit/bootstrap.php` -- Bootstrap mocks, MediaWiki constants
- `phpunit.xml.dist` -- Test configuration (PHPUnit 9.6, unit suite)

### Secondary (MEDIUM confidence)

- `.planning/phases/05-property-resolution/05-CONTEXT.md` -- User decisions constraining design
- `.planning/STATE.md` -- Prior phase decisions (Phase 3 silent promotion, Phase 4 conditional templates)
- `.planning/PROJECT.md` -- Milestone context and key decisions

### Tertiary (LOW confidence)

None -- all findings are from direct codebase analysis.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH -- all classes are in the codebase and thoroughly inspected
- Architecture: HIGH -- patterns derived directly from existing code (InheritanceResolver, CategoryModel, test files)
- Pitfalls: HIGH -- identified from actual codebase structure and existing test patterns

**Research date:** 2026-02-02
**Valid until:** No expiration (codebase-internal research, no external dependencies)
