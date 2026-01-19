# SemanticSchemas: Page-Type Property Display

## What This Is

Enhancement to SemanticSchemas extension to properly render Page-type property values as clickable wiki links in display templates. When a property has `Has_type::Page`, its values display as wiki links with the correct namespace prefix derived from `Allows_value_from_namespace`.

## Core Value

Page-type property values render as clickable wiki links, making the semantic relationships between pages visible and navigable.

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

(None — ready for next milestone)

### Out of Scope

- Changing how SMW stores semantic data — SMW handles this correctly already
- Custom link formatting per-property — use Has_template for custom cases
- Non-Page datatypes — this work is specific to Page-type properties
- Hide namespace prefix in display (REQ-F01) — deferred to future milestone if needed

## Context

**Current State:** Shipped v0.1.2 with 48 LOC across 3 files (PHP/JSON).
**Tech stack:** MediaWiki extension, PHP 8.1+, Semantic MediaWiki, PageForms.

**Implementation:**
- `Template:Property/Page` renders Page-type values as wikilinks via #arraymap
- `PropertyModel.getRenderTemplate()` implements three-tier fallback chain
- `DisplayStubGenerator` adds namespace prefix to Page-type display values

**Files modified:**
- `resources/extension-config.json` — Template:Property/Page definition
- `src/Schema/PropertyModel.php` — Smart template fallback
- `src/Generator/DisplayStubGenerator.php` — Namespace prefix transformation

## Constraints

- **Tech stack**: MediaWiki extension, PHP 8.1+, Semantic MediaWiki, PageForms
- **Compatibility**: Must work with existing generated forms and templates
- **Multi-value**: Must handle comma-separated property values

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

---
*Last updated: 2026-01-19 after v0.1.2 milestone*
