# SemanticSchemas: Page-Type Property Display

## What This Is

Enhancement to SemanticSchemas extension to properly render Page-type property values as clickable wiki links in display templates. When a property has `Has_type::Page`, its values should display as wiki links with the correct namespace prefix derived from `Allows_value_from_namespace`.

## Core Value

Page-type property values render as clickable wiki links, making the semantic relationships between pages visible and navigable.

## Requirements

### Validated

<!-- Existing capabilities from codebase -->

- ✓ Schema-driven wiki page generation (categories, properties, templates, forms) — existing
- ✓ Three-template system (dispatcher/semantic/display) for category pages — existing
- ✓ Property display templates (`Template:Property/Default`, `Template:Property/Email`, `Template:Property/Link`) — existing
- ✓ PropertyModel with `getRenderTemplate()` for template selection — existing
- ✓ FormGenerator creates PageForms forms from category schemas — existing
- ✓ DisplayStubGenerator produces display templates — existing
- ✓ Multiple inheritance resolution via C3 linearization — existing
- ✓ Base configuration installer (properties, categories, templates) — existing

### Active

- [ ] Page-type properties render values as wiki links
- [ ] Multiple values (comma-separated) each render as separate links
- [ ] Namespace from `Allows_value_from_namespace` prepended to link target
- [ ] Fallback logic: Has_template → custom, else Has_type=Page → Page template, else Default
- [ ] Investigate/fix namespace stripping in value storage (if needed)

### Out of Scope

- Changing how SMW stores semantic data — SMW handles this correctly already
- Custom link formatting per-property — use Has_template for custom cases
- Non-Page datatypes — this work is specific to Page-type properties

## Context

**Current behavior**: Property values are stored correctly by SMW (visible in Special:Browse with proper namespace prefixes). However, display templates render values as plain text, not as clickable wiki links.

**Example**: A category page shows `required_property=Has parent category, Has target namespace` as plain text, but should show `[[Property:Has parent category]], [[Property:Has target namespace]]`.

**Existing display templates**:
- `Template:Property/Default` — plain text rendering
- `Template:Property/Email` — mailto: links
- `Template:Property/Link` — external URLs

**Missing**: A `Template:Property/Page` (or similar) that renders values as wiki links with namespace handling.

**Investigation needed**: Determine where/if namespace stripping occurs. The cleanest solution may be preserving full `Namespace:Name` format throughout.

## Constraints

- **Tech stack**: MediaWiki extension, PHP 8.1+, Semantic MediaWiki, PageForms
- **Compatibility**: Must work with existing generated forms and templates
- **Multi-value**: Must handle comma-separated property values

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Display template approach (not PHP generator) | Keeps rendering logic declarative and wiki-editable | — Pending |
| Comma-separated link display for multi-values | Consistent with existing value display pattern | — Pending |
| Fallback hierarchy: Has_template → Page template → Default | Preserves custom template override while adding smart defaults | — Pending |

---
*Last updated: 2026-01-19 after initialization*
