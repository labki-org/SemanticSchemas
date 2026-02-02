---
phase: 07-api-endpoint
plan: 01
subsystem: api
tags: [api, multi-category, property-resolution, mediawiki]
requires:
  - phase: 05-multi-category-resolution
    provides: MultiCategoryResolver for property resolution logic
  - phase: 05-multi-category-resolution
    provides: ResolvedPropertySet for result encapsulation
  - phase: 05-multi-category-resolution
    provides: InheritanceResolver for per-category resolution
key-decisions:
  - id: require-edit-permission
    decision: Require 'edit' permission to call the API
    rationale: Phase 8 Create Page UI requires edit permission, so API should match
  - id: fail-on-invalid-category
    decision: Fail entire request if any category is invalid
    rationale: No partial resolution - easier error handling for clients
  - id: integer-boolean-flags
    decision: Use integers (1/0) not booleans for required/shared flags
    rationale: MediaWiki API drops boolean false keys, integers are more reliable
  - id: category-prefix-stripping
    decision: Strip "Category:" prefix case-insensitively
    rationale: User-friendly - accepts both "Person" and "Category:Person"
tech-stack:
  added: []
  patterns:
    - MediaWiki APIModules registration pattern
    - Integer boolean flags for JSON reliability
    - Testable helper class for unit testing API formatting without MediaWiki
key-files:
  created:
    - src/Api/ApiSemanticSchemasMultiCategory.php
    - tests/phpunit/unit/Api/ApiSemanticSchemasMultiCategoryTest.php
  modified:
    - extension.json
    - i18n/en.json
duration: 3 minutes
completed: 2026-02-02
---

# Phase 07 Plan 01: API Endpoint Summary

**One-liner:** Multi-category property resolution API endpoint delegating to MultiCategoryResolver with pipe-separated input and integer boolean flags.

## Performance

**Duration:** 3 minutes
**Tasks completed:** 2/2 (100%)
**Commits:** 2 task commits + 1 metadata commit
**Tests added:** 7 test cases, 42 assertions
**Linting:** All checks pass (phpcs, parallel-lint, minus-x)

## Accomplishments

Created a fully functional API endpoint for multi-category property resolution that bridges Phase 5's resolution logic with Phase 8's Create Page UI needs.

**Core capabilities:**
- Accepts pipe-separated category names via `categories` parameter
- Strips "Category:" prefix automatically (case-insensitive)
- Validates all categories exist before resolution
- Delegates to MultiCategoryResolver for property/subobject resolution
- Returns flat arrays with per-item metadata (name, title, required, shared, sources)
- Uses integer boolean flags (1/0) for JSON reliability
- Requires edit permission for access control

**API registration:**
- Registered in extension.json as `semanticschemas-multicategory`
- All i18n messages added to en.json
- Three examples showing single/multi-category and prefix usage

**Testing:**
- 7 unit test cases covering formatting, shared detection, prefix stripping
- TestableApiHelper pattern avoids ApiBase dependency in unit tests
- All tests pass with 42 assertions

## Task Commits

| Task | Commit  | Description                                               |
| ---- | ------- | --------------------------------------------------------- |
| 1    | 2fb274d | feat(07-01): create API module, register, and add i18n   |
| 2    | 6711da4 | test(07-01): create unit tests for API response formatting |

## Files Created

**src/Api/ApiSemanticSchemasMultiCategory.php** (219 lines)
- Extends ApiBase following ApiSemanticSchemasHierarchy pattern
- execute() method: permission check, normalize input, validate, resolve, format
- formatProperties() / formatSubobjects(): convert ResolvedPropertySet to API response
- stripPrefix(): case-insensitive "Category:" removal
- validateCategories(): fail entire request if any category invalid
- getAllowedParams(): PARAM_ISMULTI with limits 10/20
- getExamplesMessages(): 3 usage examples

**tests/phpunit/unit/Api/ApiSemanticSchemasMultiCategoryTest.php** (328 lines)
- 7 test cases covering formatting logic
- TestableApiHelper replicates formatting methods for unit testing
- Tests: required/optional, shared detection, integer flags, prefix stripping, empty resolution

## Files Modified

**extension.json**
- Added APIModules entry for `semanticschemas-multicategory`

**i18n/en.json**
- Added 5 message keys:
  - `semanticschemas-api-param-categories`
  - `apihelp-semanticschemas-multicategory-summary`
  - `apihelp-semanticschemas-multicategory-example-1/2/3`
  - `apierror-semanticschemas-invalidcategories`

## Decisions Made

**1. Require edit permission**
- Decision: Call `checkUserRightsAny( 'edit' )` in execute()
- Rationale: Phase 8 Create Page UI requires edit permission, so API should match
- Impact: Only editors can query property resolution (matches form creation flow)

**2. Fail entire request on invalid category**
- Decision: `validateCategories()` calls `dieWithError()` if any category invalid
- Rationale: No partial resolution - simpler error handling for clients
- Impact: Client gets clear error message listing invalid categories

**3. Integer boolean flags**
- Decision: Use integers (1/0) not booleans for required/shared flags
- Rationale: MediaWiki API drops boolean false keys from JSON output
- Implementation: Ternary `count( $sources ) > 1 ? 1 : 0`
- Verification: Unit tests use `assertIsInt()` and `assertContains( [0, 1] )`

**4. Case-insensitive prefix stripping**
- Decision: `preg_replace( '/^Category:/i', '', trim( $name ) )`
- Rationale: User-friendly - accepts "Person", "Category:Person", "category:Person"
- Impact: More flexible input handling, reduces client-side validation burden

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

**Unit testing API modules (resolved):**
- Issue: ApiBase requires MediaWiki integration, can't be instantiated in unit tests
- Solution: Created TestableApiHelper class that replicates formatting logic
- Result: Pure unit tests with no MediaWiki dependencies, 7 tests with 42 assertions

## Next Phase Readiness

**Phase 08 (Create Page UI) is ready to proceed.**

The API endpoint provides exactly what Phase 8 needs:
- ✅ Accepts pipe-separated category names from form UI
- ✅ Returns resolved properties with required/shared flags
- ✅ Returns resolved subobjects with required/shared flags
- ✅ Source attribution for each item
- ✅ Validation and error messages
- ✅ Edit permission requirement matches form creation flow

**API usage from JavaScript:**
```javascript
new mw.Api().get({
  action: 'semanticschemas-multicategory',
  categories: 'Person|Employee'
}).done(function(data) {
  // data['semanticschemas-multicategory'].properties[]
  // data['semanticschemas-multicategory'].subobjects[]
  // Each item has: name, title, required, shared, sources
});
```

**Response structure:**
```json
{
  "categories": ["Person", "Employee"],
  "properties": [
    {
      "name": "Has name",
      "title": "Property:Has name",
      "required": 1,
      "shared": 1,
      "sources": ["Person", "Employee"]
    }
  ],
  "subobjects": [...]
}
```

No blockers or concerns for Phase 8 integration.
