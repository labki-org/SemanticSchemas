# Requirements: SemanticSchemas v0.2.0

**Defined:** 2026-02-02
**Core Value:** Schema definitions are the single source of truth; all wiki artifacts are generated from schemas

## v0.2.0 Requirements

Requirements for multi-category page creation milestone. Each maps to roadmap phases.

### Workflow

- [x] **WF-01**: All v0.2.0 work done in a feature branch, delivered as a pull request to main

### Required/Optional Conflict Resolution (Bug Fix)

- [x] **FIX-01**: CategoryModel resolves required+optional conflict by promoting to required instead of throwing
- [x] **FIX-02**: SubobjectModel resolves required+optional conflict by promoting to required instead of throwing
- [x] **FIX-03**: SchemaValidator warns (not errors) when a property appears in both required and optional
- [x] **FIX-04**: When combining categories, if a property is required in any category and optional in another, required wins
- [x] **FIX-05**: When combining categories, if a subobject is required in any category and optional in another, required wins
- [x] **FIX-06**: Existing schemas that don't have conflicts continue working identically

### Conditional Templates

- [x] **TMPL-01**: All semantic templates wrap `#set` calls in `#if` conditions to prevent empty value overwrites
- [x] **TMPL-02**: Existing single-category templates continue working after conditional `#set` change
- [x] **TMPL-03**: Multi-value properties use `+sep` parameter instead of manual separators inside `#if` blocks

### Property Resolution

- [x] **RESO-01**: MultiCategoryResolver resolves properties across multiple selected categories
- [x] **RESO-02**: Shared properties (same name across categories) are identified and deduplicated
- [x] **RESO-03**: Property ordering follows C3 linearization precedence within each category
- [x] **RESO-04**: Each resolved property includes source attribution (which category defines it)
- [x] **RESO-05**: Resolver detects conflicting property datatypes across categories and reports errors
- [x] **RESO-06**: When a property is required in any selected category, it is required in the composite form

### Composite Form Generation

- [x] **FORM-01**: CompositeFormGenerator produces a single PageForms form with multiple `{{{for template}}}` blocks
- [x] **FORM-02**: Shared properties appear once in the first template section; conditional `#set` handles storage in other templates
- [x] **FORM-03**: Each template section has a label identifying the category
- [x] **FORM-04**: All selected categories are assigned on page save via `[[Category:X]]` wikilinks
- [x] **FORM-05**: Generated composite form is saved as a wiki Form: page

### API Endpoint

- [x] **API-01**: API endpoint accepts multiple category names and returns resolved/deduplicated property data
- [x] **API-02**: API response includes shared properties, category-specific properties, and any conflicts
- [x] **API-03**: API is registered in extension.json and follows existing ApiBase pattern

### Create Page UI

- [x] **UI-01**: New Special page (e.g., `Special:CreateSemanticPage`) accessible to users with edit permissions
- [x] **UI-02**: Collapsible hierarchy tree with checkboxes for category selection
- [x] **UI-03**: Live AJAX preview shows merged/deduplicated properties as categories are selected
- [x] **UI-04**: Page name input field for the new page
- [x] **UI-05**: Submit triggers composite form generation and redirects to Special:FormEdit
- [x] **UI-06**: JavaScript module registered via ResourceLoader in extension.json

### State Management

- [x] **STATE-01**: StateManager uses template-level hashing instead of page-level hashing
- [x] **STATE-02**: Dirty detection correctly handles multi-category pages (no false positives)
- [x] **STATE-03**: Existing single-category state tracking continues working after refactor

## Future Requirements

Deferred to later milestones. Tracked but not in current roadmap.

### Enhanced UX

- **UX-01**: Template section collapse/expand (accordion) in composite forms
- **UX-02**: Quick-create presets for common category combinations
- **UX-03**: Category removal data cleanup for existing multi-category pages

### Advanced Validation

- **VAL-01**: Dry-run validation checking all constraints before page creation
- **VAL-02**: Conditional property visibility based on category selection

### Bulk Operations

- **BULK-01**: Create multiple pages with same category combination

## Out of Scope

Explicitly excluded. Documented to prevent scope creep.

| Feature | Reason |
|---------|--------|
| Auto-select parent categories | Explicit selection only — user decides category membership |
| Merge schemas into single template | Maintain category identity with separate `{{{for template}}}` blocks |
| Inline page creation | Use existing FormEdit workflow — no need to reinvent |
| Custom display template per composite form | Reuse existing display stubs per category |
| Drag-and-drop category ordering | Unnecessary complexity for v0.2.0 |
| Subobject deduplication across categories | Complex edge case — defer to post-MVP |

## Traceability

Which phases cover which requirements. Updated during roadmap creation.

| Requirement | Phase | Status |
|-------------|-------|--------|
| WF-01 | Phase 3 | Complete |
| FIX-01 | Phase 3 | Complete |
| FIX-02 | Phase 3 | Complete |
| FIX-03 | Phase 3 | Complete |
| FIX-04 | Phase 3 | Complete |
| FIX-05 | Phase 3 | Complete |
| FIX-06 | Phase 3 | Complete |
| TMPL-01 | Phase 4 | Complete |
| TMPL-02 | Phase 4 | Complete |
| TMPL-03 | Phase 4 | Complete |
| RESO-01 | Phase 5 | Complete |
| RESO-02 | Phase 5 | Complete |
| RESO-03 | Phase 5 | Complete |
| RESO-04 | Phase 5 | Complete |
| RESO-05 | Phase 5 | Complete |
| RESO-06 | Phase 5 | Complete |
| FORM-01 | Phase 6 | Complete |
| FORM-02 | Phase 6 | Complete |
| FORM-03 | Phase 6 | Complete |
| FORM-04 | Phase 6 | Complete |
| FORM-05 | Phase 6 | Complete |
| API-01 | Phase 7 | Complete |
| API-02 | Phase 7 | Complete |
| API-03 | Phase 7 | Complete |
| UI-01 | Phase 8 | Complete |
| UI-02 | Phase 8 | Complete |
| UI-03 | Phase 8 | Complete |
| UI-04 | Phase 8 | Complete |
| UI-05 | Phase 8 | Complete |
| UI-06 | Phase 8 | Complete |
| STATE-01 | Phase 9 | Complete |
| STATE-02 | Phase 9 | Complete |
| STATE-03 | Phase 9 | Complete |

**Coverage:**
- v0.2.0 requirements: 33 total
- Mapped to phases: 33
- Unmapped: 0

**Phase distribution:**
- Phase 3: 7 requirements (WF-01, FIX-01 through FIX-06)
- Phase 4: 3 requirements (TMPL-01 through TMPL-03)
- Phase 5: 6 requirements (RESO-01 through RESO-06)
- Phase 6: 5 requirements (FORM-01 through FORM-05)
- Phase 7: 3 requirements (API-01 through API-03)
- Phase 8: 6 requirements (UI-01 through UI-06)
- Phase 9: 3 requirements (STATE-01 through STATE-03)

---
*Requirements defined: 2026-02-02*
*Last updated: 2026-02-03 — all requirements complete*
