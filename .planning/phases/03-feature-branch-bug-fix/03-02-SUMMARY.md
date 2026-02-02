# Phase 3 Plan 02: Validator Warning for Required/Optional Overlap

**One-liner:** SchemaValidator emits warning (not error) for required/optional overlap; OntologyInspector surfaces warnings via validateSchemaWithSeverity()

## What Was Done

### Task 1: Change SchemaValidator overlap from error to warning
- Modified `validateRequiredOptionalBuckets()` in SchemaValidator.php to emit `formatWarning()` instead of `formatError()` when items appear in both required and optional lists
- Warning message now says "promoted to required" to match model behavior from Plan 01
- Updated OntologyInspector.php `validateWikiState()` to use `validateSchemaWithSeverity()` instead of separate `validateSchema()` + `generateWarnings()` calls, ensuring promotion warnings appear on the validate tab
- Commit: `84d9259`

### Task 2: Update SchemaValidator tests for warning behavior
- Replaced `testSubobjectWithDuplicatePropertyListsReturnsError` with `testSubobjectWithDuplicatePropertyListsReturnsWarningNotError`
- Added `testCategoryDuplicateRequiredOptionalPropertyReturnsWarningNotError`
- Added `testCategoryDuplicateRequiredOptionalSubobjectReturnsWarningNotError`
- Added `testNoOverlapProducesNoPromotionWarning`
- Added `testValidateSchemaErrorsOnlyMethodExcludesWarnings`
- All 29 SchemaValidatorTest tests pass (57 assertions); full Schema suite 145 tests (249 assertions)
- Commit: `78018aa`

## Decisions Made

| Decision | Rationale |
|----------|-----------|
| Warning uses "promoted to required" wording | Matches model behavior from Plan 01; informative rather than prescriptive |
| OntologyInspector uses validateSchemaWithSeverity() | validateSchema() discards warnings; the old separate generateWarnings() call missed promotion warnings |
| Removed separate generateWarnings() call in OntologyInspector | validateSchemaWithSeverity() already calls generateWarnings() internally (line 92); removing avoids double-counting |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fixed risky tests with no assertions**
- **Found during:** Task 2
- **Issue:** `testNoOverlapProducesNoPromotionWarning` and `testValidateSchemaErrorsOnlyMethodExcludesWarnings` looped over empty arrays, never executing an assertion, causing PHPUnit "risky test" warnings
- **Fix:** Added explicit assertion before the loop (`assertEmpty` for no-overlap; `assertTrue` for promotion warning existence before checking errors-only method)
- **Files modified:** tests/phpunit/unit/Schema/SchemaValidatorTest.php
- **Commit:** `78018aa`

## Files Modified

| File | Change |
|------|--------|
| `src/Schema/SchemaValidator.php` | formatWarning instead of formatError for overlap |
| `src/Schema/OntologyInspector.php` | validateSchemaWithSeverity() replacing validateSchema() + generateWarnings() |
| `tests/phpunit/unit/Schema/SchemaValidatorTest.php` | Replaced error test, added 5 new warning tests |

## Verification Results

| Check | Result |
|-------|--------|
| `composer test` | All green (lint, minus-x, phpcs) |
| `SchemaValidatorTest` | 29 tests, 57 assertions, 0 failures |
| Full Schema suite | 145 tests, 249 assertions, 0 failures |
| `promoted to required` count in SchemaValidator.php | 1 (single warning emission point) |
| `validateSchemaWithSeverity` count in OntologyInspector.php | 1 (new method call) |
| Old `formatError.*both required and optional` in SchemaValidator.php | 0 (removed) |
| Error-based overlap tests in SchemaValidatorTest.php | 0 (replaced with warning tests) |

## Next Phase Readiness

Phase 3 is now complete (both plans done). Ready for Phase 4 (Conditional Templates) which depends on Phase 3.

**Duration:** ~2 minutes
**Completed:** 2026-02-02
