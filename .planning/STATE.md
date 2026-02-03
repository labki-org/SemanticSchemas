# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-02-02)

**Core value:** Schema definitions are the single source of truth; all wiki artifacts are generated from schemas
**Current focus:** Phase 9 - State Management Refactor (next)

## Current Position

Phase: 9 of 9 (State Management Refactor) — IN PROGRESS
Plan: 2 of 3+ in current phase
Status: In progress
Last activity: 2026-02-03 — Completed 09-02-PLAN.md (Template Hash Integration)

Progress: [█████████░] 94% (16 of 17+ plans across all phases)

## Performance Metrics

**Velocity:**
- Total plans completed: 16 (3 v0.1.2 baseline + 13 v0.2.0)
- Average duration: 3 min (v0.2.0 plans)
- Total execution time: 0.8 hours (v0.2.0)

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 1. Property Display Template | 2/2 | Complete | v0.1.2 |
| 2. Smart Fallback Logic | 1/1 | Complete | v0.1.2 |
| 3. Feature Branch + Bug Fix | 2/2 | Complete | 2 min |
| 4. Conditional Templates | 1/1 | Complete | 2 min |
| 5. Property Resolution | 1/1 | Complete | 2 min |
| 6. Composite Form Generation | 1/1 | Complete | 5 min |
| 7. API Endpoint | 1/1 | Complete | 3 min |
| 8. Create Page UI | 3/3 | Complete | 6 min |
| 9. State Management Refactor | 2/3+ | In Progress | 2 min |

**Recent Trend:**
- Phase 9 plan 09-01 added template-level hash tracking infrastructure (1 min)
- Plan 09-02 integrated template hashes into generation, validation, and maintenance flows (2 min)
- All tests and linting passing consistently

*Updated after each plan completion*

## Accumulated Context

### Decisions

Decisions are logged in PROJECT.md Key Decisions table.
Recent decisions affecting current work:

- v0.1.2: Template-first approach (Template:Property/Page must exist before selection logic can use it)
- v0.1.2: Use leading colon for wikilinks (bypasses MediaWiki namespace prefix scanning)
- v0.1.2: Namespace prefix in DisplayStubGenerator (transform display values at generation-time)
- v0.2.0 Pending: Multiple template calls per page (one template per category — clean separation)
- **v0.2.0 Phase 4-01:** Conditional `#set` (ALL properties wrapped in #if guards to prevent empty values)
- **v0.2.0 Phase 4-01:** Multi-value separator (use |+sep=, parameter for proper SMW list handling)
- **v0.2.0 Phase 6-01:** Shared properties in first template (appear once to avoid duplicate form fields)
- **v0.2.0 Phase 6-01:** First-section aggregation (shared + first-category-specific properties)
- **v0.2.0 Phase 6-01:** Inheritance over composition (CompositeFormGenerator extends FormGenerator)
- **v0.2.0 Phase 6-01:** Alphabetical form naming (Category1+Category2, deterministic)
- **v0.2.0 Phase 3-01:** Silent promotion pattern using array_diff for required/optional conflicts
- **v0.2.0 Phase 3-01:** Constructor promotion mirrors mergeWithParent() pattern for consistency
- **v0.2.0 Phase 3-02:** Warning uses "promoted to required" wording (matches model behavior)
- **v0.2.0 Phase 3-02:** OntologyInspector uses validateSchemaWithSeverity() (avoids double-counting warnings)
- **v0.2.0 Phase 5-01:** Properties are wiki-global entities (datatype conflicts impossible by design)
- **v0.2.0 Phase 5-01:** Composition over inheritance (MultiCategoryResolver composes InheritanceResolver)
- **v0.2.0 Phase 5-01:** Symmetric property/subobject handling (same deduplication, same promotion)
- **v0.2.0 Phase 5-01:** Source attribution via getPropertySources/getSubobjectSources maps
- **v0.2.0 Phase 7-01:** API requires edit permission (matches Phase 8 Create Page UI flow)
- **v0.2.0 Phase 7-01:** Fail entire request on invalid category (no partial resolution for simpler error handling)
- **v0.2.0 Phase 7-01:** Integer boolean flags (1/0 not true/false for JSON reliability)
- **v0.2.0 Phase 7-01:** Case-insensitive Category: prefix stripping (user-friendly input handling)
- **v0.2.0 Phase 8-01:** Datatype fallback to 'Page' (defensive handling when property not in store)
- **v0.2.0 Phase 8-01:** Categories as object array (changed from string array to include targetNamespace)
- **v0.2.0 Phase 8-02:** Root category embedding via data-root-category attribute (JS tree initialization)
- **v0.2.0 Phase 8-02:** POST handler branching: 1 category (FormGenerator) vs 2+ (CompositeFormGenerator)
- **v0.2.0 Phase 8-02:** Alphabetical root selection if multiple roots (deterministic behavior)
- **v0.2.0 Phase 8-03:** Embedded tree data (PHP embeds JSON data attribute, JS reads — avoids API call)
- **v0.2.0 Phase 8-03:** Inverted parent→children (build children index client-side, O(n))
- **v0.2.0 Phase 8-03:** Multi-root forest (categories with missing parents treated as roots)
- **v0.2.0 Phase 8-03:** Shared properties own box (separate {{{for template}}} block labeled "Shared Properties")
- **v0.2.0 Phase 8-03:** Protected $pageCreator (changed from private for subclass access)
- **v0.2.0 Phase 8-03:** Throwable catch (PHP Errors bypass \Exception, causing invalid JSON responses)
- **v0.2.0 Phase 9-01:** Template hash structure (associative arrays with 'generated' hash + category attribution)
- **v0.2.0 Phase 9-01:** Public content hashing (hashContentString() exposes hashContent() for raw wikitext)
- **v0.2.0 Phase 9-01:** Backward compatibility via array_merge (old state without templateHashes gets empty array)
- **v0.2.0 Phase 9-02:** computeAllTemplateHashes method hashes semantic template, dispatcher, and form content
- **v0.2.0 Phase 9-02:** Template hash computation occurs after page hash computation in processGenerate
- **v0.2.0 Phase 9-02:** OntologyInspector.validateWikiState() checks template staleness after page hash validation
- **v0.2.0 Phase 9-02:** Maintenance script supports single-category and all-categories template hash updates

### Pending Todos

None yet.

### Blockers/Concerns

None — Phase 8 complete, full interactive Create Page UI working end-to-end.

**Known risks from research:**
- Property collision without conditional `#set` (RESOLVED in Phase 4)
- StateManager hash conflicts with multi-template pages (addressed in Phase 9)
- Template system needs updates for multi-category page content rendering (follow-up after Phase 9)

## Session Continuity

Last session: 2026-02-03
Stopped at: Completed 09-02-PLAN.md (Template Hash Integration)
Resume file: None
