# Customizing Category Display

SemanticSchemas renders each content page through a **layered template
system**. The default layout is generated automatically from the
category's schema; you can opt into customization at four levels of
increasing scope.

## How rendering works

When a page in `Category:Book` renders, the auto-generated
`Template:Book` dispatcher:

1. Calls `{{Book/semantic | … }}` — emits `[[Has X::Y]]` annotations so
   SMW stores the data.
2. Composes the display from three shared primitives: a
   `Category/table-header` call, one `Category/property-row` per
   effective property (label and value expression baked in), and a
   `Category/table-footer` call with backlinks pre-resolved.
3. Emits a `#ask` block per subobject category, projecting values into
   the auto-generated `<Subcat>/subobject-row` template (same
   header / rows / footer shape).

All primitives live on shared wiki pages — edit them to change the
site-wide look. See the [Primitive reference](#primitive-reference) for
the full inventory. The dispatcher itself is regenerated automatically
when a category's schema changes (`maintenance/regenerateArtifacts.php`);
you don't edit it.

## Levels of customization

### Level 1 — Default

Set `Has display format = table` (or `sidebox`) on the category. Done.
You get the standard layout: header row, one row per effective
property, optional backlinks, optional subobject sections.

### Level 2 — Per-property or per-field render template

**Use case**: "`Has email` should render as a `mailto:` link;
`Has website` as an external link."

Set `Has render template` at whichever level fits:

| Level | Where | Scope |
|-------|-------|-------|
| Property-level default | On the Property page itself | Every category that uses the property |
| Field-level override | On the `{{Property field/subobject}}` call inside one category | That category only |

```wikitext
{{!-- Property:Has email — property-level default --}}
[[Has type::Email]]
[[Has render template::Property/Email]]
```

```wikitext
{{!-- Category:Staff — field-level override --}}
{{Property field/subobject
 | for_property = Has email
 | has_render_template = Property/ObfuscatedEmail
}}
```

**Resolution order** (generator bakes whichever wins into the row):

1. Field-level `has_render_template`
2. Property-level `Has render template`
3. `Property/Page` auto-default for Page-typed properties
4. Bare value

Shipped renderers:

| Template           | Purpose                                             |
|--------------------|-----------------------------------------------------|
| `Property/Default` | Plain text (default for non-Page types)             |
| `Property/Page`    | Wikilinks (auto-selected for Page-typed properties) |
| `Property/Email`   | `[mailto:value value]`                              |
| `Property/Link`    | `[value value]` (external URL)                      |

Write your own by creating `Template:Property/<Name>` that takes a
single `value` parameter.

### Level 3 — Fully custom display template

**Use case**: "Textbooks should render with a custom card layout, not
a property table."

On the category, set:

```wikitext
[[Has display template::Template:TextbookDisplay]]
```

`Has display template` overrides `Has display format`: when a custom
template is set, the default `Category/table` (or `Category/sidebox`)
call is suppressed. Your template receives every field as a named
parameter:

```wikitext
{{!-- Template:TextbookDisplay --}}
<includeonly>
<div class="textbook-card">
  <h2>{{{has_title|}}} — {{{has_edition|}}}</h2>
  <p>{{Property/Page | value={{{has_author|}}} }}</p>

  {{Category/table-header | category={{{category|Textbook}}} | label=Details}}
  {{Category/property-row | prop=Has ISBN    | value={{{has_isbn|}}}}}
  {{Category/property-row | prop=Has subject | value={{{has_subject|}}}}}
  {{Category/table-footer | category={{{category|Textbook}}} | subobjects=no | backlinks=no}}

  {{Category/subobject-list | category=Chapter | page={{FULLPAGENAME}} }}
</div>
</includeonly>
```

Compose any of the primitives in the [Primitive reference](#primitive-reference).

### Level 4 — Custom subobject display template

**Use case**: "Show all of a Book's Chapters as rows of one unified
table, not as a separate mini-table per chapter."

Default behavior: the dispatcher generates one `<Subcat>/subobject-row`
per subobject type, which renders each instance as its own labeled
table. Great for a handful of complex subobjects; noisy for many
simple ones.

Declare the override at either level (parallel to Level 2):

| Level | Where | Scope |
|-------|-------|-------|
| Category-level default | On `Category:Chapter` | Wherever Chapter appears as a subobject |
| Per-parent override | On the `{{Subobject field/subobject}}` call in the parent category | One parent only |

```wikitext
{{!-- Category:Chapter — category-level default --}}
[[Has subobject display template::ChapterTable]]
```

```wikitext
{{!-- Category:Anthology — per-parent override --}}
{{Subobject field/subobject
 | for_category = Chapter
 | has_subobject_display_template = AnthologyChapterGrid
}}
```

**Resolution order**:

1. Parent's Subobject field `has_subobject_display_template`
2. Subobject category's own `Has subobject display template`
3. Auto-generated `<Subcat>/subobject-row` (per-instance mini-tables)

When either custom template is set, the dispatcher replaces the
per-instance pipeline with a single call:

```wikitext
{{ChapterTable | category=Chapter | page={{FULLPAGENAME}} }}
```

Your template does its own `#ask` and decides how to frame the
results. The SMW `format=template` idiom lets you split the rendering
across four small templates to share the frame across rows:

```wikitext
{{!-- Template:ChapterTable --}}
<includeonly>{{#ask: [[-Has subobject::{{{page|{{FULLPAGENAME}}}}}]] [[Category:{{{category|}}}]]
 | ?Has chapter title=title
 | ?Has chapter summary=summary
 | format=template
 | introtemplate=ChapterTable/open
 | template=ChapterTable/row
 | outrotemplate=ChapterTable/close
 | named args=yes
 | sort=Has sort order
 | order=asc
}}</includeonly>
```

```wikitext
{{!-- Template:ChapterTable/open --}}
<includeonly>{| class="wikitable source-semanticschemas"
! Title !! Summary</includeonly>
```

```wikitext
{{!-- Template:ChapterTable/row --}}
<includeonly>
|-
| {{{title|}}}
| {{{summary|}}}</includeonly>
```

```wikitext
{{!-- Template:ChapterTable/close --}}
<includeonly>
|}</includeonly>
```

SMW invokes `introtemplate`/`outrotemplate` once each — but *only*
when the `#ask` yields ≥1 result. Parent pages that inherit the field
but declare no instances render nothing: no orphan header, no empty
frame, zero extra queries.

## Primitive reference

These templates are stable and meant to be called from custom display
wikitext. Costs noted per call per page render.

### Value renderers

Take values as parameters; no SMW lookups.

```wikitext
{{Property/Page  | value=Frank Herbert, J. R. R. Tolkien}}
{{Property/Email | value=author@example.org}}
{{Property/Link  | value=https://example.org}}
```

### Frame + row primitives

Open/close the standard frame with the category title row baked in;
emit rows between them.

```wikitext
{{Category/table-header | category=Book | label=Book }}
{{Category/property-row | label=Title        | value={{{has_title|}}} }}
{{Category/property-row | prop=Has author    | value={{Property/Page | value={{{has_author|}}} }} }}
{{Category/backlink-row | prop=Has author    | label=Authored }}
{{Category/backlink-row | prop=Has reviewer  | label=Reviewed }}
{{Category/table-footer | category=Book | subobjects=no | backlinks=no }}
```

| Primitive                 | Parameters                                          | Cost                                                 |
|---------------------------|-----------------------------------------------------|------------------------------------------------------|
| `Category/table-header`   | `category`, `label`                                 | Free                                                 |
| `Category/table-footer`   | `category`, `page`, `subobjects`, `backlinks`, `backlink_section` | Free                                                 |
| `Category/sidebox-header` | same as `table-header`                              | Free                                                 |
| `Category/sidebox-footer` | same as `table-footer`                              | Free                                                 |
| `Category/property-row`   | `value` + either `label` (baked) or `prop` (dynamic)| Free with `label`; 1 `#show` with `prop`             |
| `Category/backlink-row`   | `prop`, `label`                                     | 1 `#ask` count + 1 `#ask` list (inherent)            |

Rows are hidden when the value is empty or no backlinks exist. Use
`prop=` in hand-written templates to pick up the site-wide display
label without hard-coding it.

### Subobject rendering

```wikitext
{{Category/subobject-list | category=Chapter | page={{FULLPAGENAME}} }}
```

Renders all `Category:Chapter` subobjects on the page through the
auto-generated `Chapter/subobject-row`. One `#ask` total.

### Cross-page value lookup

When you need a property value from *another* page (or a specific
subobject fragment):

```wikitext
{{Property/value | prop=Has title }}                  ← reads {{FULLPAGENAME}}
{{Property/value | prop=Has title | page=Dune }}      ← reads Dune
{{Property/value | prop=Has chapter title | page=Dune#_abc123 }}
```

Thin wrapper around `{{#show:page|?Prop}}`. **Costs one SMW query and
one persistent query-dependency subobject per call** — use sparingly
on rendered pages.

### Whole-category displays — dynamic discovery

```wikitext
{{Category/table   | category=Book | page={{FULLPAGENAME}} }}
{{Category/sidebox | category=Book | page={{FULLPAGENAME}} }}
```

Compose `table-header` / `sidebox-header` + `Category/display-rows`
(ancestor-walk + per-property `#show`) + footer. Useful for
prototyping; pays ~5 queries per inheritance level + ~3 queries per
property. Production wikitext should rely on the auto-generated
dispatcher instead.

## Worked example: tool inventory with required training

A wiki tracks rooms full of equipment. Each tool page
(`[[Drill press]]`, `[[Laser cutter]]`) carries a
`[[Has training required::Power tool safety]]` annotation. Each room
is a `Category:ToolRoom` page listing which tools are present:

```wikitext
{{!-- Category:ToolRoom --}}
{{Property field/subobject | for_property = Has tool | is_required = true }}
{{Property field/subobject | for_property = Has capacity | is_required = false }}
[[Has display format::table]]
[[Category:SemanticSchemas-managed]]
```

The default display lists `Has tool` as comma-separated wikilinks
(Page-typed fields use `Property/Page` automatically), but doesn't
tell the safety officer what training each tool requires.

### Path A — change one field's renderer

Use when the change is local to one field. The default table layout
stays.

Write a renderer that expands each tool with its training:

```wikitext
{{!-- Template:Property/ToolWithTraining --}}
<includeonly>{{#arraymap:{{{value|}}}|,|@@t@@|
'''[[@@t@@]]''' — training: {{Property/value|prop=Has training required|page=@@t@@}}
|<br/>}}</includeonly>
```

Point the field at it on the category page:

```wikitext
{{Property field/subobject
 | for_property = Has tool
 | is_required = true
 | has_render_template = Property/ToolWithTraining
}}
```

The dispatcher regenerates with the renderer wrapping the tool value;
every other field still renders the default way. The same renderer
composes — another category can attach it to its own `Has equipment`
field for identical behavior.

### Path B — replace the entire ToolRoom display

Use when the layout itself diverges — separate sections, custom HTML,
conditional content across fields.

```wikitext
{{!-- Category:DangerousToolRoom --}}
[[Subcategory of::Category:ToolRoom]]
{{Property field/subobject | for_property = Has tool | is_required = true }}
{{Property field/subobject | for_property = Has hazard level | is_required = true }}
{{Property field/subobject | for_property = Has capacity | is_required = false }}
[[Has display template::Template:DangerousToolRoom/display]]
[[Category:SemanticSchemas-managed]]
```

Every field arrives as a parameter, so own-page values cost zero
queries:

```wikitext
{{!-- Template:DangerousToolRoom/display --}}
<includeonly>
<div style="border: 2px solid #c00; padding: 1em;">
== {{PAGENAME}} — {{{has_hazard_level|}}} hazard ==

=== Tools and required training ===
{| class="wikitable"
! Tool !! Required training !! Trained users
{{#arraymap:{{{has_tool|}}}|,|@@t@@|{{!}}-
{{!}} [[@@t@@]]
{{!}} {{Property/value|prop=Has training required|page=@@t@@}}
{{!}} <small>{{#ask:[[Has completed training::{{Property/value|prop=Has training required|page=@@t@@}}]]|format=count}} users</small>
|\n}}
|}

=== Room properties ===
{| class="wikitable"
{{Category/property-row | label=Capacity | value={{{has_capacity|}}} }}
{{Category/property-row | label=Hazard level | value={{{has_hazard_level|}}} }}
|}

{{Category/backlink-row | prop=Has location | label=Activities scheduled here }}
</div>
</includeonly>
```

`{{{has_tool|}}}` is free (template parameter); `{{Property/value}}`
pays one `#show` per tool (unavoidable — training lives on tool
pages); `Category/property-row` and `Category/backlink-row` reuse the
site-wide look.

### Choosing between paths

| Path | Scope of change | Files to author |
|------|-----------------|-----------------|
| A — field renderer | One field's value expression | 1 template + 1 annotation on the field |
| B — custom display | The whole category's layout | 1 template + `Has display template::…` on the category |

Path A composes — multiple fields can each have their own renderer,
and the default table still hosts them. Reach for Path B when the
layout itself diverges, not just one cell.

## Performance notes

The dispatcher's value, label, ancestor, and inverse-label lookups
all resolve at **template-generation time**. Rendering a content page
costs roughly one `#ask` per declared subobject category, one
`#ask`-count + one `#ask`-list per backlink property, and SMW's
per-annotation storage diff. See the Primitive reference for
per-primitive costs; `{{#show:…}}`, `{{Property/value|…}}`, and
`{{Category/render-reverse}}` each pay one query + one persistent
query-dependency subobject per call on every re-render.

## Notes on upgrades

- All shipped base-config templates have `replaceable: true`. Direct
  edits are overwritten on next install/update. To customize globally,
  copy to a different name (e.g. `Template:MyWiki/property-row`) and
  reference that.
- Per-category overrides via `Has display template` and per-field
  `Has render template` are stored as category / subobject metadata
  and survive base-config refresh.
