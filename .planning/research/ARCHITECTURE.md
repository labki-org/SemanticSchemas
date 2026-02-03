# Architecture: Multi-Category Page Creation Integration

**Domain:** MediaWiki extension (SemanticSchemas)
**Researched:** 2026-02-02
**Confidence:** HIGH

## Executive Summary

Multi-category page creation requires integrating with SemanticSchemas' existing three-template system, C3 linearization inheritance resolver, PageForms composite form architecture, and tabbed Special page UI. This document outlines integration points, new components needed, and recommended build order based on existing architectural patterns.

**Key architectural decision confirmed:** Multiple template calls per page (one per category) with conditional `#set` to prevent empty value overwrites. This leverages MediaWiki's ParserFunctions extension and Semantic MediaWiki's property assignment semantics.

## Integration Points with Existing Components

### 1. InheritanceResolver (C3 Linearization)

**Current Responsibilities:**
- Compute deterministic ancestor chains for single-category inheritance
- Detect circular inheritance
- Merge CategoryModels in correct order (child → parent → root)
- Cache ancestor chains

**Integration Point:**
The existing `InheritanceResolver` is designed for single-category inheritance hierarchies. Multi-category page creation needs **cross-category** resolution.

**Recommended Approach:** Create new `MultiCategoryResolver` service (separate class)

**Why separate:**
- Different responsibility: Resolving properties across multiple unrelated categories vs single inheritance chain
- Different data structure: Array of categories vs single category with parents
- InheritanceResolver uses C3 linearization for single hierarchy; multi-category needs property union/intersection logic
- Separation of concerns: InheritanceResolver stays focused on inheritance, MultiCategoryResolver handles composition

**Location:** `src/Service/MultiCategoryResolver.php`

**Signature:**
```php
class MultiCategoryResolver {
    public function __construct(
        private InheritanceResolver $inheritanceResolver,
        private WikiCategoryStore $categoryStore
    ) {}

    /**
     * Resolve effective properties across multiple categories.
     *
     * @param string[] $categoryNames List of category names
     * @return array{
     *   shared: PropertyModel[],
     *   categorySpecific: array<string, PropertyModel[]>,
     *   conflicts: array
     * }
     */
    public function resolveMultiCategoryProperties(array $categoryNames): array;

    /**
     * Get linearized ancestor chains for all categories.
     *
     * @param string[] $categoryNames
     * @return array<string, string[]> Map of category name to ancestor chain
     */
    public function getAncestorChains(array $categoryNames): array;
}
```

**Integration:** Delegates to existing `InheritanceResolver::getEffectiveCategory()` for each category, then performs cross-category analysis.

### 2. TemplateGenerator

**Current Responsibilities:**
- Generate semantic templates (`Template:Category/semantic`) with `{{#set:...}}`
- Generate dispatcher templates (`Template:Category`)
- Generate subobject templates

**Integration Point:**
Existing `TemplateGenerator::generateSemanticTemplate()` generates:
```wikitext
{{#set:
 | Property1 = {{{Property1|}}}
 | Property2 = {{{Property2|}}}
}}
```

**Required Enhancement:** Support conditional `#set` for multi-category templates

**Modification Required:** Add new method to existing class (no new class needed)

**New Method:**
```php
/**
 * Generate semantic template for multi-category context.
 *
 * Uses conditional #set to prevent empty values from overwriting
 * values set by other category templates.
 *
 * @param CategoryModel $category
 * @param string[] $sharedProperties Properties that appear in multiple categories
 * @return string
 */
public function generateConditionalSemanticTemplate(
    CategoryModel $category,
    array $sharedProperties
): string;
```

**Generated Output:**
```wikitext
{{#set:
 | Property1 = {{#if:{{{Property1|}}}|{{{Property1|}}}|}}
 | Property2 = {{{Property2|}}}
}}
```

**Mechanism:** MediaWiki's `#if` parser function checks if parameter is non-empty. Empty else branch prevents assignment when parameter is empty, allowing previous template's value to persist.

**Source:** [MediaWiki Help:Parser functions in templates](https://www.mediawiki.org/wiki/Help:Parser_functions_in_templates) — Confirmed that `{{#if:{{{param|}}}|value|}}` pattern with empty else branch is standard for conditional assignments.

### 3. FormGenerator

**Current Responsibilities:**
- Generate PageForms form markup (`Form:Category`)
- Automatic field generation from CategoryModel
- Required/optional property sections
- Subobject repeatable blocks
- Namespace support

**Integration Point:**
Existing `FormGenerator::generateForm()` generates:
```wikitext
{{{for template|Category}}}
... fields ...
{{{end template}}}
```

**Required Enhancement:** Generate composite forms with multiple template blocks

**Recommended Approach:** Create new `CompositeFormGenerator` class that composes existing FormGenerator

**Why separate class:**
- FormGenerator is 400+ lines focused on single-category forms
- Composite form generation has different logic (property deduplication, multiple template blocks, category selection UI)
- Preserves existing single-category form generation (backward compatibility)
- Clear separation: FormGenerator = single template, CompositeFormGenerator = multiple templates

**Location:** `src/Generator/CompositeFormGenerator.php`

**Signature:**
```php
class CompositeFormGenerator {
    public function __construct(
        private FormGenerator $singleFormGenerator,
        private MultiCategoryResolver $multiCategoryResolver
    ) {}

    /**
     * Generate composite form for multiple categories.
     *
     * @param string[] $categoryNames
     * @param array $propertyResolution From MultiCategoryResolver
     * @return string Form wikitext
     */
    public function generateCompositeForm(
        array $categoryNames,
        array $propertyResolution
    ): string;
}
```

**Generated Output:**
```wikitext
{{{info|page name=<page name>}}}

<!-- Shared properties (shown once) -->
'''Shared Properties:'''
{| class="formtable"
  ... shared fields ...
|}

<!-- Category 1 specific -->
{{{for template|Category1}}}
'''Category1 Properties:'''
{| class="formtable"
  ... category1-specific fields ...
|}
{{{end template}}}

<!-- Category 2 specific -->
{{{for template|Category2}}}
'''Category2 Properties:'''
{| class="formtable"
  ... category2-specific fields ...
|}
{{{end template}}}
```

**Source:** [PageForms Extension:Page Forms/Defining forms](https://www.mediawiki.org/wiki/Extension:Page_Forms/Defining_forms) — Confirmed that multiple `{{{for template}}}...{{{end template}}}` blocks are supported in a single form.

### 4. SpecialSemanticSchemas (Tab Integration)

**Current Structure:**
- Navigation tabs rendered in `showNavigation()`
- Tab array: `['overview', 'validate', 'generate', 'hierarchy']`
- Action dispatch in `execute()` via switch statement
- Each tab has corresponding `show*()` method

**Integration Point:** Add new "Multi-Category" tab

**Modification Required:** Extend existing class (no new class needed)

**Changes:**
1. Add tab to `showNavigation()`:
```php
'multicategory' => [
    'label' => $this->msg('semanticschemas-multicategory')->text(),
    'subtext' => $this->msg('semanticschemas-tab-multicategory-subtext')->text(),
]
```

2. Add dispatch case in `execute()`:
```php
case 'multicategory':
    $this->showMultiCategory();
    break;
```

3. Add new method:
```php
private function showMultiCategory(): void {
    // Render category selection UI
    // Handle form generation request
    // Display generated form
}
```

**Pattern Match:** Follows exact same pattern as existing tabs (generate, validate, hierarchy). No architectural deviation.

### 5. API Endpoints

**Current Structure:**
- `ApiSemanticSchemasHierarchy.php` extends `ApiBase`
- Registered in `extension.json` under `APIModules`
- Returns hierarchy data for visualization
- Read-only, no CSRF token required

**Integration Point:** Add new API endpoint for multi-category resolution

**Recommended Approach:** Create new API module following existing pattern

**Location:** `src/Api/ApiSemanticSchemasMultiCategory.php`

**Signature:**
```php
class ApiSemanticSchemasMultiCategory extends ApiBase {
    public function execute(): void;

    // Returns:
    // {
    //   "categories": ["Category1", "Category2"],
    //   "sharedProperties": [...],
    //   "categoryProperties": {"Category1": [...], "Category2": [...]},
    //   "conflicts": [...]
    // }
}
```

**Registration in extension.json:**
```json
"APIModules": {
    "semanticschemas-hierarchy": "MediaWiki\\Extension\\SemanticSchemas\\Api\\ApiSemanticSchemasHierarchy",
    "semanticschemas-multicategory": "MediaWiki\\Extension\\SemanticSchemas\\Api\\ApiSemanticSchemasMultiCategory"
}
```

**Usage:** JavaScript in Special page tab calls API to resolve properties before form generation.

**Pattern Match:** Identical structure to `ApiSemanticSchemasHierarchy` — read-only, public, returns JSON data for UI.

## New Components Required

### 1. MultiCategoryResolver (Service)

**Purpose:** Resolve properties across multiple unrelated categories

**Dependencies:**
- `InheritanceResolver` (for per-category effective resolution)
- `WikiCategoryStore` (for loading category definitions)
- `WikiPropertyStore` (for property metadata)

**Key Methods:**
- `resolveMultiCategoryProperties()` — Union/intersection logic
- `getAncestorChains()` — Linearized chains for all categories
- `detectPropertyConflicts()` — Find same property with different constraints

**Output:** Property resolution data structure for form generation

### 2. CompositeFormGenerator (Generator)

**Purpose:** Generate PageForms composite forms with multiple template blocks

**Dependencies:**
- `FormGenerator` (for individual template sections)
- `MultiCategoryResolver` (for property resolution)
- `PropertyInputMapper` (for field generation)

**Key Methods:**
- `generateCompositeForm()` — Main generation entry point
- `generateSharedPropertiesSection()` — Shared fields shown once
- `generateCategorySection()` — Category-specific template block

**Output:** Complete form wikitext ready for `Form:` namespace

### 3. ApiSemanticSchemasMultiCategory (API)

**Purpose:** Provide multi-category resolution data for UI

**Dependencies:**
- `MultiCategoryResolver` (for resolution logic)

**Endpoint:** `api.php?action=semanticschemas-multicategory&categories=Cat1|Cat2|Cat3`

**Output:** JSON with shared/specific properties and conflicts

### 4. JavaScript UI Component

**Purpose:** Category selection interface with live property preview

**Location:** `resources/ext.semanticschemas.multicategory.js`

**Dependencies:**
- `mediawiki.api` (for API calls)
- `jquery.ui.autocomplete` (for category selection)

**Functionality:**
- Multi-select category dropdown with autocomplete
- Live property preview (shared/specific)
- Conflict warnings
- Generate form button

**Pattern Match:** Follows same structure as existing `ext.semanticschemas.hierarchy.js` — modular, uses `mw.Api()`, renders into containers.

## Data Flow Changes

### Current Flow (Single Category)

```
User creates page
  ↓
PageForms displays Form:Category
  ↓
User fills fields
  ↓
PageForms saves with {{Category|Property1=value1|Property2=value2}}
  ↓
Template:Category/semantic stores with {{#set: Property1={{{Property1|}}}|...}}
  ↓
SMW indexes properties
```

### New Flow (Multi-Category)

```
User visits Special:SemanticSchemas/multicategory
  ↓
Selects categories: [Category1, Category2]
  ↓
JavaScript calls api.php?action=semanticschemas-multicategory&categories=Cat1|Cat2
  ↓
API returns {shared: [...], categorySpecific: {...}}
  ↓
User clicks "Generate Form"
  ↓
POST to Special:SemanticSchemas/multicategory
  ↓
Server: CompositeFormGenerator.generateCompositeForm()
  ↓
Creates Form:Category1_Category2 with multiple template blocks
  ↓
Redirects to Special:FormEdit/Category1_Category2/NewPage
  ↓
User fills form
  ↓
PageForms saves page with:
  {{Category1|Property1=value1|Shared1=value1}}
  {{Category2|Property2=value2|Shared1=value2}}
  ↓
Template:Category1/semantic: {{#set: Property1={{{Property1|}}}|Shared1={{#if:{{{Shared1|}}}|{{{Shared1|}}}|}}}}
Template:Category2/semantic: {{#set: Property2={{{Property2|}}}|Shared1={{#if:{{{Shared1|}}}|{{{Shared1|}}}|}}}}
  ↓
SMW indexes all properties (conditional #set prevents overwrites)
```

### Key Differences

1. **Form Generation:** User-initiated from Special page (not auto-generated per category)
2. **Form Storage:** Composite forms stored as `Form:Category1_Category2` (naming convention TBD)
3. **Template Calls:** Multiple template calls per page (one per category)
4. **Property Assignment:** Conditional `#set` for shared properties
5. **API Layer:** New endpoint for property resolution

## Conditional #set Mechanism (Critical Detail)

### Problem

When multiple templates set the same property:
```wikitext
{{Category1|Shared=value1}}
{{Category2|Shared=}}
```

Standard `#set` overwrites:
```wikitext
Template:Category1/semantic: {{#set: Shared={{{Shared|}}}}}  → Sets "value1"
Template:Category2/semantic: {{#set: Shared={{{Shared|}}}}}  → Overwrites with empty
```

Result: Property value lost.

### Solution

Conditional `#set` with empty else branch:
```wikitext
Template:Category1/semantic: {{#set: Shared={{#if:{{{Shared|}}}|{{{Shared|}}}|}}}}
Template:Category2/semantic: {{#set: Shared={{#if:{{{Shared|}}}|{{{Shared|}}}|}}}}
```

**Behavior:**
- Category1 template: `{{{Shared|}}}` → "value1" (non-empty) → `#if` true → assigns "value1"
- Category2 template: `{{{Shared|}}}` → "" (empty) → `#if` false → empty else branch → **no assignment**

**Result:** Property retains "value1" from first template.

**Source:** [SMW Help:Parser functions and conditional values](https://www.semantic-mediawiki.org/wiki/Help:Parser_functions_and_conditional_values) — Confirmed that `{{#set:...}}` respects conditional logic and empty else branch prevents overwrite.

**Implementation Note:** `TemplateGenerator::generateConditionalSemanticTemplate()` wraps shared properties in `{{#if:{{{param|}}}|{{{param|}}}|}}` pattern.

## Suggested Build Order

Based on dependency analysis and existing architectural patterns:

### Phase 1: Foundation (No UI)

**Goal:** Core resolution logic and data structures

1. **MultiCategoryResolver** (`src/Service/MultiCategoryResolver.php`)
   - Depends on: InheritanceResolver (exists), WikiCategoryStore (exists)
   - No UI dependency
   - Can be unit tested in isolation
   - Output: Property resolution data structure

2. **Unit Tests** (`tests/phpunit/Service/MultiCategoryResolverTest.php`)
   - Test property union/intersection
   - Test conflict detection
   - Test ancestor chain aggregation

**Deliverable:** Tested service that resolves multi-category properties

### Phase 2: Template Generation

**Goal:** Generate conditional semantic templates and composite forms

3. **TemplateGenerator Enhancement**
   - Add `generateConditionalSemanticTemplate()` method
   - Depends on: MultiCategoryResolver (Phase 1)
   - Modify existing class (no new files)

4. **CompositeFormGenerator** (`src/Generator/CompositeFormGenerator.php`)
   - Depends on: FormGenerator (exists), MultiCategoryResolver (Phase 1)
   - Generates composite form wikitext

5. **Integration Tests**
   - Test conditional template generation
   - Test composite form structure
   - Verify `#if` syntax correctness

**Deliverable:** Generators that produce valid composite forms and conditional templates

### Phase 3: API Layer

**Goal:** Expose multi-category resolution via API

6. **ApiSemanticSchemasMultiCategory** (`src/Api/ApiSemanticSchemasMultiCategory.php`)
   - Depends on: MultiCategoryResolver (Phase 1)
   - Follows existing API pattern (ApiSemanticSchemasHierarchy)

7. **API Registration** (`extension.json`)
   - Add to `APIModules` section
   - Add i18n messages

**Deliverable:** Working API endpoint for property resolution

### Phase 4: Special Page Tab

**Goal:** User-facing UI for composite form generation

8. **SpecialSemanticSchemas Extension**
   - Add tab to `showNavigation()`
   - Add `showMultiCategory()` method
   - Add form generation handler
   - Depends on: CompositeFormGenerator (Phase 2), API (Phase 3)

9. **JavaScript UI Component** (`resources/ext.semanticschemas.multicategory.js`)
   - Category selection with autocomplete
   - API integration for live preview
   - Form generation trigger
   - Follows pattern from `ext.semanticschemas.hierarchy.js`

10. **ResourceModule Registration** (`extension.json`)
    - Add JavaScript module
    - Add CSS styles
    - Add i18n messages

**Deliverable:** Complete user-facing feature with UI

### Phase 5: Polish

**Goal:** Edge cases, documentation, testing

11. **Edge Case Handling**
    - Empty category list
    - Non-existent categories
    - Circular dependencies (unlikely but possible)
    - Property type conflicts

12. **Documentation**
    - User guide for multi-category pages
    - Developer docs for MultiCategoryResolver
    - Schema examples

13. **End-to-End Tests**
    - Selenium/browser tests for form flow
    - API integration tests
    - Template rendering tests in MediaWiki

**Deliverable:** Production-ready feature

## Architectural Constraints

### 1. MediaWiki Extension Patterns

**Constraint:** All new code must follow MediaWiki extension conventions
- Namespace: `MediaWiki\Extension\SemanticSchemas\`
- PSR-4 autoloading in `src/`
- Registration in `extension.json`

### 2. Semantic MediaWiki Compatibility

**Constraint:** Must respect SMW's property assignment semantics
- Properties assigned via `#set` parser function
- Multiple `#set` calls on same property = last one wins (hence conditional logic)
- Property types must match across categories

### 3. PageForms Compatibility

**Constraint:** Must generate valid PageForms syntax
- `{{{for template|Name}}}...{{{end template}}}` blocks
- Field definitions: `{{{field|param|property=Property|...}}}`
- Multiple templates per form supported (confirmed)

### 4. Backward Compatibility

**Constraint:** Existing single-category functionality must not break
- InheritanceResolver unchanged (new service separate)
- FormGenerator unchanged (composition not modification)
- TemplateGenerator extended (new method, existing methods unchanged)
- Existing forms continue working

### 5. Performance

**Constraint:** Multi-category resolution can be expensive
- **Mitigation:** API endpoint (async, no page load blocking)
- **Mitigation:** Cache property resolution results in StateManager
- **Mitigation:** Limit max categories per composite form (suggest 5)

## Integration Checklist

Before proceeding with implementation:

- [x] Integration points with InheritanceResolver identified (separate service)
- [x] Integration points with TemplateGenerator identified (new method)
- [x] Integration points with FormGenerator identified (separate class)
- [x] Integration points with SpecialSemanticSchemas identified (new tab)
- [x] Integration points with API layer identified (new endpoint)
- [x] Conditional `#set` mechanism verified with MediaWiki docs
- [x] PageForms composite form pattern verified with official docs
- [x] Build order accounts for dependencies
- [x] Backward compatibility preserved
- [x] New components follow existing architectural patterns

## Patterns to Follow

### Pattern 1: Service Layer Separation

**What:** Business logic in `src/Service/`, not in generators or Special pages
**Example:** `MultiCategoryResolver` is service, not part of `CompositeFormGenerator`
**Why:** Testability, reusability, clear boundaries

### Pattern 2: Composition Over Modification

**What:** New classes compose existing ones rather than modifying them
**Example:** `CompositeFormGenerator` uses `FormGenerator`, doesn't extend it
**Why:** Backward compatibility, clear intent, minimal risk

### Pattern 3: API-First for Complex UI

**What:** Complex UI logic backed by API endpoint
**Example:** Multi-category property resolution via API, JavaScript consumes it
**Why:** Separation of concerns, testability, progressive enhancement

### Pattern 4: Follow Existing File Naming

**What:** Match existing naming conventions exactly
**Example:** `ApiSemanticSchemasMultiCategory` (not `MultiCategoryAPI`)
**Why:** Consistency, autoloading, maintainability

## Anti-Patterns to Avoid

### Anti-Pattern 1: Modifying Core Models

**What:** Adding multi-category logic to `CategoryModel` or `PropertyModel`
**Why bad:** Models are immutable value objects; multi-category is operational concern
**Instead:** Keep logic in `MultiCategoryResolver` service

### Anti-Pattern 2: Monolithic Form Generator

**What:** Adding multi-category logic to existing `FormGenerator`
**Why bad:** Single Responsibility Principle violation, complicates existing code
**Instead:** Separate `CompositeFormGenerator` that composes `FormGenerator`

### Anti-Pattern 3: Synchronous Property Resolution

**What:** Resolving multi-category properties during page load
**Why bad:** Performance impact, blocks rendering
**Instead:** Async API endpoint, JavaScript-driven UI

### Anti-Pattern 4: Template Overwriting

**What:** Generating new versions of existing category templates
**Why bad:** Breaks existing single-category pages, backward compatibility violated
**Instead:** Conditional templates only for multi-category context, OR enhancement to existing templates with conditional logic for shared properties

## Open Questions for Phase-Specific Research

The following questions should be researched during specific phases:

### Phase 1 (MultiCategoryResolver)

**Question:** How to handle property type conflicts?
- Example: Category1 defines "Name" as Text, Category2 defines "Name" as Page
- **Research needed:** SMW property type registry, conflict resolution strategies

### Phase 2 (Form Generation)

**Question:** What naming convention for composite forms?
- Options: `Form:Category1_Category2`, `Form:Composite/Category1_Category2`, dynamic hash
- **Research needed:** PageForms form discovery mechanism, collision avoidance

### Phase 3 (API)

**Question:** Should API cache resolution results?
- Trade-off: Performance vs staleness
- **Research needed:** MediaWiki caching best practices, cache invalidation

### Phase 4 (UI)

**Question:** How to handle category selection UX?
- Multi-select dropdown? Drag-and-drop? Tag input?
- **Research needed:** MediaWiki UI patterns, accessibility requirements

## Sources

- [MediaWiki Help:Parser functions in templates](https://www.mediawiki.org/wiki/Help:Parser_functions_in_templates)
- [MediaWiki Help:Extension:ParserFunctions](https://www.mediawiki.org/wiki/Help:Extension:ParserFunctions)
- [PageForms Extension:Page Forms/Defining forms](https://www.mediawiki.org/wiki/Extension:Page_Forms/Defining_forms)
- [PageForms Extension:Page Forms/Page Forms and templates](https://www.mediawiki.org/wiki/Extension:Page_Forms/Page_Forms_and_templates)
- [SMW Help:Parser functions and conditional values](https://www.semantic-mediawiki.org/wiki/Help:Parser_functions_and_conditional_values)
- [SMW Help:Setting values](https://www.semantic-mediawiki.org/wiki/Help:Setting_values)

## Confidence Assessment

| Area | Confidence | Reason |
|------|------------|--------|
| Conditional #set mechanism | HIGH | Verified with official MediaWiki and SMW documentation |
| PageForms composite forms | HIGH | Verified with official PageForms documentation |
| Integration with InheritanceResolver | HIGH | Code reviewed, separation of concerns clear |
| Integration with TemplateGenerator | HIGH | Code reviewed, method extension straightforward |
| Integration with FormGenerator | HIGH | Code reviewed, composition pattern identified |
| Integration with Special page | HIGH | Code reviewed, tab pattern established |
| API endpoint pattern | HIGH | Code reviewed, existing pattern clear |
| Build order | MEDIUM | Dependencies identified but implementation complexity varies |
| Property conflict resolution | MEDIUM | Mechanism clear but edge cases need phase-specific research |
