# SemanticSchemas: Multi-Category Page Creation

## What This Is

SemanticSchemas is a MediaWiki extension providing schema-driven ontology management. It integrates with Semantic MediaWiki and PageForms, treating schema definitions as the single source of truth and generating wiki pages (categories, properties, forms, templates) as compiled artifacts. The current milestone adds multi-category page creation — users select multiple categories from a hierarchy tree and create pages that belong to all selected categories, with intelligent property deduplication.

## Core Value

Schema definitions are the single source of truth; all wiki artifacts (categories, properties, templates, forms) are generated from schemas with correct semantic behavior.

## Current Milestone: v0.2.0 Multi-Category Page Creation

**Goal:** Enable users to create pages belonging to multiple categories with a single form, intelligently deduplicating shared properties.

**Target features:**
- Conditional `#set` in semantic templates (prevents empty value overwrites in multi-template pages)
- Multi-category property resolution service (resolve, identify shared, deduplicate)
- Composite form generator (single PageForms form with multiple `{{{for template}}}` blocks)
- "Create Page" tab on `Special:SemanticSchemas` (hierarchy tree with checkboxes, live preview)
- API endpoint for multi-category property resolution (powers live preview)

## Requirements

### Validated

- Schema-driven wiki page generation (categories, properties, templates, forms) — existing
- Three-template system (dispatcher/semantic/display) for category pages — existing
- Property display templates (`Template:Property/Default`, `Template:Property/Email`, `Template:Property/Link`) — existing
- PropertyModel with `getRenderTemplate()` for template selection — existing
- FormGenerator creates PageForms forms from category schemas — existing
- DisplayStubGenerator produces display templates — existing
- Multiple inheritance resolution via C3 linearization — existing
- Base configuration installer (properties, categories, templates) — existing
- Page-type properties render values as wiki links — v0.1.2
- Multiple values (comma-separated) each render as separate links — v0.1.2
- Namespace from `Allows_value_from_namespace` prepended to link target — v0.1.2
- Fallback logic: Has_template → custom, else Has_type=Page → Page template, else Default — v0.1.2
- Template:Property/Page with #arraymap for multi-value handling — v0.1.2

### Active

- [ ] Conditional `#set` in semantic templates — only store non-empty values
- [ ] Multi-category property resolution — resolve properties across categories, deduplicate shared
- [ ] Composite form generation — single form with multiple template sections
- [ ] "Create Page" tab — hierarchy tree selection with live property preview
- [ ] Multi-category API endpoint — property resolution for UI preview

### Out of Scope

- Changing how SMW stores semantic data — SMW handles this correctly already
- Custom link formatting per-property — use Has_template for custom cases
- Hide namespace prefix in display (REQ-F01) — deferred to future milestone if needed
- Drag-and-drop category ordering in form — unnecessary complexity for v0.2.0
- Inline page creation from composite form — use existing FormEdit workflow
- Custom display template per composite form — reuse existing display stubs

## Context

**Current State:** Shipped v0.1.2 (Page-type property display). Starting v0.2.0 (multi-category page creation).
**Tech stack:** MediaWiki extension, PHP 8.1+, Semantic MediaWiki, PageForms.

**Architecture:**
- Three-template system: Dispatcher / Semantic / Display per category
- Schema as source of truth with hash-based dirty detection
- C3 linearization for multiple inheritance resolution
- Generation-time resolution (not render-time)

**Key files for v0.2.0:**
- `src/Generator/TemplateGenerator.php` — conditional `#set` changes
- `src/Generator/FormGenerator.php` — pattern reference for composite form
- `src/Special/SpecialSemanticSchemas.php` — new tab + handlers
- `src/Schema/InheritanceResolver.php` — C3 linearization reused by resolver
- `src/Api/ApiSemanticSchemasHierarchy.php` — pattern for new API
- `resources/ext.semanticschemas.hierarchy.js` — tree rendering base
- `extension.json` — module registration

## Constraints

- **Tech stack**: MediaWiki extension, PHP 8.1+, Semantic MediaWiki, PageForms
- **Compatibility**: Must work with existing generated forms and templates; existing per-category forms continue working
- **Multi-value**: Must handle comma-separated property values
- **PageForms**: Composite forms use standard PageForms `{{{for template}}}` syntax — no PageForms patches

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Display template approach (not PHP generator) | Keeps rendering logic declarative and wiki-editable | Good |
| Comma-separated link display for multi-values | Consistent with existing value display pattern | Good |
| Fallback hierarchy: Has_template → Page template → Default | Preserves custom template override while adding smart defaults | Good |
| Two-phase structure | Small scope (~48 LOC) doesn't warrant more phases | Good |
| Template-first approach | Template must exist before selection logic can use it | Good |
| Use `@@item@@` variable | Avoids #arraymap collision with property names containing "x" | Good |
| Use `&#32;` for space | PageForms #arraymap trims whitespace from output delimiter | Good |
| Leading colon for wikilinks | Bypasses MediaWiki namespace prefix scanning for dynamic values | Good |
| Check hasTemplate FIRST | Preserve custom template override priority in fallback chain | Good |
| Namespace prefix in DisplayStubGenerator | Transform display values at generation-time, not render-time | Good |

| Multiple template calls per page | One template per category — clean separation, reuses existing generators | — Pending |
| Conditional `#set` | Prevents empty values from overwriting in multi-template pages | — Pending |
| Shared properties in first template | Avoids duplicate form fields; conditional `#set` handles the rest | — Pending |
| PageForms composite form | Generate Form: page with multiple `{{{for template}}}`, redirect to FormEdit | — Pending |
| Existing forms untouched | Multi-category is additive; per-category forms continue working | — Pending |

---
*Last updated: 2026-02-02 after v0.2.0 milestone start*
