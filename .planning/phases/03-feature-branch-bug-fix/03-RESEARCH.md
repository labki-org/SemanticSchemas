# Phase 3: Feature Branch + Bug Fix - Research

**Researched:** 2026-02-02
**Domain:** PHP model constructors, inheritance resolution, schema validation (MediaWiki extension)
**Confidence:** HIGH

## Summary

This phase fixes a crash bug where CategoryModel and SubobjectModel constructors throw `InvalidArgumentException` when a property or subobject appears in both the `required` and `optional` arrays. The fix involves three coordinated changes: (1) promote-to-required silently in constructors, (2) promote during inheritance merge, and (3) change SchemaValidator from error to warning for the same-list overlap.

The codebase is well-structured with clear separation of concerns. The crash points are isolated to two constructor `array_intersect` checks (CategoryModel lines 120-126 for properties, lines 138-144 for subobjects, and SubobjectModel lines 83-89 for its properties). The merge logic in `CategoryModel::mergeWithParent()` already handles cross-category promotion correctly (required wins over optional via `array_diff` at lines 255-261 and 270-276). The SchemaValidator has a parallel check in `validateRequiredOptionalBuckets()` at lines 302-314 that produces an error -- this must become a warning.

**Primary recommendation:** Change the two model constructors to silently promote overlapping items to required (remove from optional), change SchemaValidator to emit a warning instead of an error, and add corresponding unit tests. The existing `mergeWithParent()` logic is already correct and needs no changes.

## Standard Stack

No new libraries needed. All changes are internal to existing PHP classes.

### Core Files to Modify

| File | Class | Purpose | Change Type |
|------|-------|---------|-------------|
| `src/Schema/CategoryModel.php` | CategoryModel | Immutable category value object | Constructor: promote instead of throw |
| `src/Schema/SubobjectModel.php` | SubobjectModel | Immutable subobject value object | Constructor: promote instead of throw |
| `src/Schema/SchemaValidator.php` | SchemaValidator | Schema validation with errors/warnings | Change overlap from error to warning |
| `tests/phpunit/unit/Schema/CategoryModelTest.php` | CategoryModelTest | Unit tests for CategoryModel | Update + add conflict promotion tests |
| `tests/phpunit/unit/Schema/SchemaValidatorTest.php` | SchemaValidatorTest | Unit tests for SchemaValidator | Update + add warning-not-error tests |

### Files That Need NO Changes

| File | Class | Why Unchanged |
|------|-------|---------------|
| `src/Schema/InheritanceResolver.php` | InheritanceResolver | Already delegates to `mergeWithParent()` which handles cross-category conflicts correctly |
| `src/Schema/SchemaLoader.php` | SchemaLoader | Only parses JSON/YAML, does not validate or construct models |
| `src/Special/SpecialSemanticSchemas.php` | SpecialSemanticSchemas | Already displays warnings from validator (validate tab at lines 1184-1187) |
| `src/Schema/OntologyInspector.php` | OntologyInspector | Uses `validateSchema()` which returns errors; warnings handled via `generateWarnings()` |
| `src/Store/WikiCategoryStore.php` | WikiCategoryStore | Reads from SMW and constructs CategoryModel -- conflicts would be cleaned before reaching here |

## Architecture Patterns

### Current Crash Points (Exact Code)

**CategoryModel constructor (properties), lines 120-126:**
```php
$dup = array_intersect( $this->requiredProperties, $this->optionalProperties );
if ( $dup !== [] ) {
    throw new InvalidArgumentException(
        "Category '{$name}' has properties listed as both required and optional: " .
        implode( ', ', $dup )
    );
}
```

**CategoryModel constructor (subobjects), lines 138-144:**
```php
$dupSG = array_intersect( $this->requiredSubobjects, $this->optionalSubobjects );
if ( $dupSG !== [] ) {
    throw new InvalidArgumentException(
        "Category '{$name}' has subobjects listed as both required and optional: " .
        implode( ', ', $dupSG )
    );
}
```

**SubobjectModel constructor (properties), lines 83-89:**
```php
$overlap = array_intersect( $this->requiredProperties, $this->optionalProperties );
if ( $overlap !== [] ) {
    throw new InvalidArgumentException(
        "Subobject '{$name}' has properties listed as both required and optional: "
        . implode( ', ', $overlap )
    );
}
```

### Fix Pattern: Silent Promotion

Replace the throw with a silent promotion. The pattern for all three locations is identical:

```php
// BEFORE: throw on overlap
$dup = array_intersect( $this->requiredProperties, $this->optionalProperties );
if ( $dup !== [] ) {
    throw new InvalidArgumentException( ... );
}

// AFTER: promote to required silently
$this->optionalProperties = array_values(
    array_diff( $this->optionalProperties, $this->requiredProperties )
);
```

This mirrors the existing `mergeWithParent()` pattern at line 255-261 which already does the same array_diff to ensure required wins.

### SchemaValidator Change Pattern

**Current behavior in `validateRequiredOptionalBuckets()`, lines 302-314:**
```php
$duplicates = array_intersect(
    array_map( 'strval', $required ),
    array_map( 'strval', $optional )
);
if ( !empty( $duplicates ) ) {
    $itemType = ucfirst( $referenceType ) . 's';
    $errors[] = $this->formatError(
        $entityType,
        $entityName,
        "$itemType cannot be both required and optional: " . implode( ', ', $duplicates ),
        'Remove duplicates from either list'
    );
}
```

**New behavior:** Move from `$errors[]` to `$warnings[]`, use `formatWarning()` instead of `formatError()`:
```php
if ( !empty( $duplicates ) ) {
    $itemType = ucfirst( $referenceType ) . 's';
    $warnings[] = $this->formatWarning(
        $entityType,
        $entityName,
        "$itemType listed as both required and optional will be promoted to required: "
        . implode( ', ', $duplicates )
    );
}
```

### Existing Warning Format (for Discretion Matching)

The `formatWarning()` method (line 184) produces:
```
Category 'CategoryName': issue description
```

Existing warnings generated by `generateWarnings()` (lines 739-783) use a slightly different format:
```
Category 'Name': no properties defined
Category 'Name': missing display configuration
Property 'Name': not used by any category
```

The promotion warning should follow the `formatWarning()` helper pattern since it's used in the structured validation path (not the post-hoc `generateWarnings()` path).

### Existing Warning Display in UI

The validate tab (SpecialSemanticSchemas lines 1184-1187) already shows warnings:
```php
if ( !empty( $result['warnings'] ) ) {
    $body .= Html::element( 'h3', [], $this->msg( 'semanticschemas-validate-warnings' )->text() );
    $body .= $this->renderList( $result['warnings'] );
}
```

Note: The validate tab calls `validateSchema()` which only returns errors (line 43-46), plus a separate `generateWarnings()` call. But `validateSchemaWithSeverity()` returns both errors and warnings. The OntologyInspector at line 149 uses `validateSchema()` (errors only) then calls `generateWarnings()` separately at line 150. The new promotion warnings should flow through `validateSchemaWithSeverity()` -- they will automatically appear to any consumer using that method.

**Key insight for the planner:** The OntologyInspector currently does NOT see warnings from `validateSchemaWithSeverity()` because it calls `validateSchema()` + `generateWarnings()` separately. To make the new promotion warnings visible on the validate tab, the OntologyInspector should be updated to use `validateSchemaWithSeverity()` instead. This is a minor change but important for meeting the requirement that warnings are "visible on Special:SemanticSchemas page during import."

### Merge Logic Already Correct

The `mergeWithParent()` method at line 247-314 already handles cross-category promotion:
```php
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
```

The `array_diff` ensures anything in mergedRequired is removed from mergedOptional. This means if Parent has prop X as required and Child has prop X as optional, after merge X is only in required. This is already correct -- no changes needed.

### Test Pattern (Existing)

Tests use PHPUnit 9.6 with `TestCase` base class. No MediaWiki test infrastructure needed for unit tests. Pattern:

```php
namespace MediaWiki\Extension\SemanticSchemas\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\SemanticSchemas\Schema\CategoryModel
 */
class CategoryModelTest extends TestCase {
    public function testSomething(): void {
        // Arrange
        $model = new CategoryModel( 'Name', [ ... ] );
        // Assert
        $this->assertEquals( ..., $model->getX() );
    }
}
```

Existing tests that must be UPDATED (not deleted):
- `testDuplicateRequiredOptionalPropertyThrowsException` -- must now test promotion instead of exception
- `testDuplicateRequiredOptionalSubobjectThrowsException` -- must now test promotion instead of exception

### Git Branching Pattern

The feature branch `multi-category-page-creation` should be created from current `main`. All phase 3 (and subsequent v0.2.0 phase) work goes on this branch. The roadmap requirement WF-01 specifies: "All v0.2.0 work done in a feature branch, delivered as a pull request to main."

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Array deduplication | Custom loop | `array_values(array_diff(...))` | Matches existing `mergeWithParent()` pattern exactly |
| Warning formatting | Custom string building | `$this->formatWarning()` | Existing helper in SchemaValidator, already used for other warnings |
| Cross-category conflict detection | New checker class | Existing `mergeWithParent()` + `InheritanceResolver` | Already handles required-wins-over-optional correctly |

## Common Pitfalls

### Pitfall 1: Breaking the Immutability Contract
**What goes wrong:** CategoryModel and SubobjectModel are documented as immutable. The fix must not add setters or mutation methods.
**Why it happens:** Temptation to add a `promoteToRequired()` method on the model.
**How to avoid:** Do the promotion in the constructor before assignment to `$this->optionalProperties`. The constructor already normalizes lists via `self::normalizeList()` -- the promotion is just another normalization step.
**Warning signs:** Any new public method that changes state after construction.

### Pitfall 2: Double-Warning from SchemaValidator
**What goes wrong:** `validateRequiredOptionalBuckets()` emits warning, AND `generateWarnings()` might also flag the same issue.
**Why it happens:** Two separate validation paths can detect the same overlap.
**How to avoid:** Only emit the warning in `validateRequiredOptionalBuckets()`. Check that `generateWarnings()` does not separately flag the same overlap (it currently does not -- it only checks for empty properties, missing display config, and unused properties).
**Warning signs:** Duplicate warning messages in the UI.

### Pitfall 3: Forgetting SubobjectModel
**What goes wrong:** Fix CategoryModel but forget SubobjectModel has the same pattern.
**Why it happens:** The context mentions "properties" and "subobjects" for CategoryModel, but SubobjectModel has its own required/optional property overlap check.
**How to avoid:** FIX-02 explicitly covers SubobjectModel. Apply the same promotion pattern.
**Warning signs:** SubobjectModel still throws on overlap after the fix.

### Pitfall 4: OntologyInspector Not Showing Warnings
**What goes wrong:** New warnings from `validateSchemaWithSeverity()` are not visible on the validate tab.
**Why it happens:** OntologyInspector calls `validateSchema()` (errors only) then `generateWarnings()` separately. The new promotion warnings are in `validateSchemaWithSeverity()` warnings, which `validateSchema()` discards.
**How to avoid:** Update OntologyInspector to use `validateSchemaWithSeverity()` and merge both warning sources.
**Warning signs:** Warnings appear in programmatic API but not on Special:SemanticSchemas/validate.

### Pitfall 5: Test Expectations Still Assert Exception
**What goes wrong:** Existing tests `testDuplicateRequiredOptionalPropertyThrowsException` and `testDuplicateRequiredOptionalSubobjectThrowsException` will fail after the fix.
**Why it happens:** These tests use `$this->expectException(InvalidArgumentException::class)` for behavior that is now silent promotion.
**How to avoid:** Replace these tests with tests that verify the promoted model state (required contains the item, optional does not).
**Warning signs:** PHPUnit failures on the existing test suite.

### Pitfall 6: Schema Cleanup Scope
**What goes wrong:** Cleanup only fixes direct conflicts but not inherited ones.
**Why it happens:** Misunderstanding the decision "Auto-clean schema files during import."
**How to avoid:** The auto-clean happens at model construction time (constructor promotion). Since `mergeWithParent()` already handles inherited conflicts, the constructor promotion handles direct conflicts. Together they cover both cases.
**Warning signs:** Inherited conflicts still producing warnings post-cleanup.

## Code Examples

### Example 1: CategoryModel Constructor Promotion (Properties)

```php
// Source: CategoryModel.php constructor, replacing lines 120-126
// Promote overlapping properties to required (required wins)
$this->optionalProperties = array_values(
    array_diff( $this->optionalProperties, $this->requiredProperties )
);
```

### Example 2: CategoryModel Constructor Promotion (Subobjects)

```php
// Source: CategoryModel.php constructor, replacing lines 138-144
// Promote overlapping subobjects to required (required wins)
$this->optionalSubobjects = array_values(
    array_diff( $this->optionalSubobjects, $this->requiredSubobjects )
);
```

### Example 3: SubobjectModel Constructor Promotion

```php
// Source: SubobjectModel.php constructor, replacing lines 83-89
// Promote overlapping properties to required (required wins)
$this->optionalProperties = array_values(
    array_diff( $this->optionalProperties, $this->requiredProperties )
);
```

### Example 4: SchemaValidator Warning

```php
// Source: SchemaValidator.php validateRequiredOptionalBuckets(), replacing lines 306-314
if ( !empty( $duplicates ) ) {
    $itemType = ucfirst( $referenceType ) . 's';
    $warnings[] = $this->formatWarning(
        $entityType,
        $entityName,
        "$itemType listed as both required and optional will be promoted to required: "
        . implode( ', ', $duplicates )
    );
}
```

### Example 5: Updated Test (CategoryModel Promotion)

```php
// Source: CategoryModelTest.php, replacing testDuplicateRequiredOptionalPropertyThrowsException
public function testDuplicateRequiredOptionalPropertyPromotesToRequired(): void {
    $model = new CategoryModel( 'TestCategory', [
        'properties' => [
            'required' => [ 'Has name' ],
            'optional' => [ 'Has name', 'Has email' ],
        ],
    ] );

    $this->assertContains( 'Has name', $model->getRequiredProperties() );
    $this->assertNotContains( 'Has name', $model->getOptionalProperties() );
    $this->assertContains( 'Has email', $model->getOptionalProperties() );
}
```

### Example 6: Updated Test (SchemaValidator Warning)

```php
// Source: SchemaValidatorTest.php
public function testDuplicateRequiredOptionalPropertyEmitsWarningNotError(): void {
    $schema = $this->getValidSchema();
    $schema['categories']['TestCategory']['properties'] = [
        'required' => [ 'Has name' ],
        'optional' => [ 'Has name', 'Has description' ],
    ];

    $result = $this->validator->validateSchemaWithSeverity( $schema );
    $this->assertEmpty( $result['errors'], 'Should not produce errors for overlap' );

    $hasPromotionWarning = false;
    foreach ( $result['warnings'] as $warning ) {
        if ( stripos( $warning, 'promoted to required' ) !== false ) {
            $hasPromotionWarning = true;
            break;
        }
    }
    $this->assertTrue( $hasPromotionWarning, 'Should warn about promotion' );
}
```

### Example 7: OntologyInspector Update

```php
// Source: OntologyInspector.php validateWikiState(), replacing lines 148-150
$validationResult = $validator->validateSchemaWithSeverity( $schema );
$errors = $validationResult['errors'];
$warnings = array_merge( $validationResult['warnings'], $validator->generateWarnings( $schema ) );
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Throw on required/optional overlap | Silent promotion to required | This phase | Crash fix, backward compatible |
| SchemaValidator errors for overlap | SchemaValidator warns for overlap | This phase | Import succeeds with warning |

**No deprecated items** -- this is a bug fix, not a library migration.

## Open Questions

1. **Cross-category warning visibility in import flow**
   - What we know: The validate tab calls OntologyInspector which uses `validateSchema()` (errors only). The new warnings flow through `validateSchemaWithSeverity()`.
   - What's unclear: Whether there is a separate import flow (beyond the validate tab) that the user sees during schema import. The Special page does not appear to have a direct "import" action in the current code -- schemas are loaded from wiki state, not from uploaded files.
   - Recommendation: Update OntologyInspector to use `validateSchemaWithSeverity()`. This is the primary path through which the validate tab renders results.

2. **SchemaValidator `checkCircularDependencies()` constructs CategoryModels**
   - What we know: At line 721, the circular dependency checker constructs `CategoryModel` objects from raw schema data. After the fix, these constructors will no longer throw on overlaps.
   - What's unclear: Whether this changes any behavior in the circular dependency path.
   - Recommendation: No concern. The `checkCircularDependencies()` method catches `InvalidArgumentException` and `TypeError` via try/catch at line 722 and simply skips those categories. After the fix, the constructor will succeed (silently promoting), which is better -- it means circular dependency detection works even when there are overlaps.

## Sources

### Primary (HIGH confidence)

All findings are derived from direct code examination of the repository at commit `a6231a2`:

- `src/Schema/CategoryModel.php` -- Constructor validation, mergeWithParent() logic
- `src/Schema/SubobjectModel.php` -- Constructor validation
- `src/Schema/SchemaValidator.php` -- validateRequiredOptionalBuckets(), formatWarning(), generateWarnings()
- `src/Schema/InheritanceResolver.php` -- getEffectiveCategory(), mergeWithParent() delegation
- `src/Schema/OntologyInspector.php` -- validateWikiState() calling validateSchema() + generateWarnings()
- `src/Special/SpecialSemanticSchemas.php` -- showValidate() warning display
- `tests/phpunit/unit/Schema/CategoryModelTest.php` -- Existing test patterns
- `tests/phpunit/unit/Schema/SchemaValidatorTest.php` -- Existing test patterns
- `tests/phpunit/unit/Schema/InheritanceResolverTest.php` -- Existing test patterns

### Secondary (MEDIUM confidence)

None needed -- all research is based on direct source code examination.

### Tertiary (LOW confidence)

None.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH -- all changes are internal code modifications, no new dependencies
- Architecture: HIGH -- exact line numbers identified in source, patterns verified in existing codebase
- Pitfalls: HIGH -- identified through direct comparison of code paths and test expectations

**Research date:** 2026-02-02
**Valid until:** Indefinite (this is codebase-specific research based on current commit, not library research)
