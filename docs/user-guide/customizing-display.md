# Customizing Category Display

SemanticSchemas renders each content page through a **layered template
system**. The default layout is generated automatically from the
category's schema; you can opt into customization at three levels of
increasing scope.

## How rendering works

When a content page in `Category:Book` renders, it invokes the
auto-generated `Template:Book` dispatcher, which:

1. Calls `{{Book/semantic | …}}` — emits `[[Has X::Y]]` annotations so
   SMW stores the data.
2. Calls `{{Category/table | category=Book | label=… | props=… | val_X=… | label_X=… }}`
   with **the category's effective schema baked into parameters**. No
   SMW lookup needed for property values on the same page — they come
   from the template arguments the user passed.
3. Inlines a `#ask` block per subobject category (`=== Chapter ===`
   etc.), projecting subobject values straight into the auto-generated
   `<Subcat>/subobject-row` template.
4. Inlines the backlinks block (when the category declares
   `Show backlinks for`), with the inverse property labels resolved at
   generation time.

`Category/table` (and the floating-sidebar variant `Category/sidebox`)
compose three building blocks: `Category/display-header`,
`Category/property-row`, and `Category/render-reverse`. All three are
documented below as reusable primitives.

The dispatcher is regenerated automatically when a category's schema
changes (`maintenance/regenerateArtifacts.php`). You don't edit it.

## Levels of customization

### Level 1 — Default

Set `Has display format = table` (or `sidebox`) on the category. Done.
You get the standard layout: header row, one row per effective
property, optional backlinks, optional subobject sections.

### Level 2 — Per-property or per-field render template

**Use case**: "`Has email` should render as a `mailto:` link;
`Has website` as an external link."

You can set `Has render template` at two levels:

**Property-level default** — on the Property page itself. Applies
wherever the property is used, in every category:

```wikitext
{{!-- Property:Has email --}}
[[Has type::Email]]
[[Has render template::Property/Email]]
```

**Field-level override** — on the `{{Property field/subobject}}` call
inside a specific category. Applies only in that category; overrides
the property's default when set:

```wikitext
{{!-- Category:Staff --}}
{{Property field/subobject
 | for_property = Has email
 | has_render_template = Property/ObfuscatedEmail
}}
```

Priority when the generator bakes the value expression:

1. Field-level `has_render_template` (per-category override)
2. Property-level `Has render template` (per-property default)
3. `Property/Page` auto-default for Page-typed properties
4. Bare value

The dispatcher bakes whichever wins around the value:

```wikitext
val_has_email = {{Property/Email | value={{{has_email|}}} }}
```

Shipped value renderers:

| Template          | Purpose                                  |
|-------------------|------------------------------------------|
| `Property/Default`| Plain text (the default for non-Page types) |
| `Property/Page`   | Wikilinks (auto-selected for Page-typed properties) |
| `Property/Email`  | `[mailto:value value]`                   |
| `Property/Link`   | `[value value]` (external URL)           |

Write your own by creating `Template:Property/<Name>` that takes a
single `value` parameter. Then reference it via `Has render template`
at whichever level makes sense — property-level for "this property
always looks like this", field-level for category-specific variations.

### Level 3 — Fully custom display template

**Use case**: "Textbooks should render with a custom card layout, not
a property table."

On the category, set:

```wikitext
[[Has display template::Template:TextbookDisplay]]
```

`Has display template` overrides `Has display format`: when a custom
template is set, the default `Category/table` (or `Category/sidebox`)
call is suppressed and only your template is called, regardless of the
format value. Your template receives every field as a named parameter:

```wikitext
{{!-- Template:TextbookDisplay --}}
<includeonly>
<div class="textbook-card">
  <h2>{{{has_title|}}} — {{{has_edition|}}}</h2>
  <p>{{Property/Page | value={{{has_author|}}} }}</p>
  <p>ISBN: {{{has_isbn|}}}</p>
  {{Category/property-row | label=Subject | value={{{has_subject|}}} }}
  {{Category/subobject-list | category=Chapter | page={{FULLPAGENAME}} }}
</div>
</includeonly>
```

You can compose any of the primitives below.

## Primitive reference

These templates are stable and meant to be called from custom display
wikitext.

### Value renderers — cheap

Take values as parameters; no SMW lookups.

```wikitext
{{Property/Page  | value=Frank Herbert, J. R. R. Tolkien}}
  → Frank Herbert, J. R. R. Tolkien     (each item linked)

{{Property/Email | value=author@example.org}}
  → mailto link

{{Property/Link  | value=https://example.org}}
  → external link
```

### Row primitives — cheap

Render a single row inside a wikitable. Hidden when the value is empty
or no backlinks exist.

```wikitext
{| class="wikitable"
{{Category/property-row | label=Title  | value={{{has_title|}}} }}
{{Category/property-row | label=Author | value={{Property/Page | value={{{has_author|}}} }} }}
{{Category/backlink-row | prop=Has author    | label=Authored }}
{{Category/backlink-row | prop=Has reviewer  | label=Reviewed }}
|}
```

`Category/backlink-row` runs one `#ask` count + one `#ask` list per
call (inherent to "who points to me").

### Subobject rendering

```wikitext
{{Category/subobject-list | category=Chapter | page={{FULLPAGENAME}} }}
```

Renders all `Category:Chapter` subobjects on the given page through
the auto-generated `Chapter/subobject-row` template (which uses the
same baked-params fast path as the top-level dispatcher). One `#ask`
total, regardless of how many chapters.

### Cross-page value lookup — expensive per use

When you need a property value from *another* page (or a specific
subobject fragment):

```wikitext
{{Property/value | prop=Has title }}                  ← reads {{FULLPAGENAME}}
{{Property/value | prop=Has title | page=Dune }}      ← reads Dune
{{Property/value | prop=Has chapter title | page=Dune#_abc123 }}
```

This is a thin wrapper around `{{#show:page|?Prop}}` and pays one
SMW query per call. Each `#show` also persists a query-dependency
subobject in SMW, so use sparingly in hot-rendering paths.

### Whole-category displays

```wikitext
{{Category/table   | category=Book | page={{FULLPAGENAME}} }}
{{Category/sidebox | category=Book | page={{FULLPAGENAME}} }}
```

When called without baked params, these walk the category's ancestor
chain via `Category/ancestors` and discover every effective property
through SMW lookups. Useful for prototyping a display, but pays
~5 queries per inheritance level + ~3 queries per property. Prefer
the auto-generated `{{Book|…}}` dispatcher in production wikitext.

## Worked example: tool inventory with required training

Suppose a wiki tracks rooms full of equipment. Each piece of equipment
has its own page (`[[Drill press]]`, `[[Laser cutter]]`) with a
`[[Has training required::Power tool safety]]` annotation. Each room
is a `Category:ToolRoom` page that lists which tools are present:

```wikitext
{{!-- Category:ToolRoom --}}
{{Property field/subobject | for_property = Has tool | is_required = true }}
{{Property field/subobject | for_property = Has capacity | is_required = false }}
[[Has display format::table]]
[[Category:SemanticSchemas-managed]]
```

The default display gives one row per field, with `Has tool` showing
as a comma-separated list of wikilinks (because Page-typed fields use
`Property/Page` automatically). That's serviceable but doesn't tell
the safety officer what training a tool requires until they click in.

You have two ways to enrich the rendering, depending on scope.

### Path A — change one field's renderer

Use this when the change is local to one field and applies wherever
that field appears. The default table layout stays.

Write a renderer template that takes the comma-separated value and
expands each item with its training:

```wikitext
{{!-- Template:Property/ToolWithTraining --}}
<includeonly>{{#arraymap:{{{value|}}}|,|@@t@@|
'''[[@@t@@]]''' — training: {{Property/value|prop=Has training required|page=@@t@@}}
|<br/>}}</includeonly>
```

Then point the field at it on the category page:

```wikitext
{{Property field/subobject
 | for_property = Has tool
 | is_required = true
 | has_render_template = Property/ToolWithTraining
}}
```

That's the entire change. The dispatcher regenerates with
`val_has_tool={{Property/ToolWithTraining|value={{{has_tool|}}} }}`
baked in; every other field still renders the default way. Cost per
render is the same shape as before plus one `Property/value` lookup
per tool listed (each is a `#show`).

The same renderer is reusable: another category can attach
`has_render_template = Property/ToolWithTraining` to its own
`Has equipment` field and get identical behavior.

### Path B — replace the entire ToolRoom display

Use this when the layout itself is different — separate sections,
custom HTML, conditional content based on multiple fields.

```wikitext
{{!-- Category:DangerousToolRoom --}}
[[Subcategory of::Category:ToolRoom]]
{{Property field/subobject | for_property = Has tool | is_required = true }}
{{Property field/subobject | for_property = Has hazard level | is_required = true }}
{{Property field/subobject | for_property = Has capacity | is_required = false }}
[[Has display template::Template:DangerousToolRoom/display]]
[[Category:SemanticSchemas-managed]]
```

Then write the display template. Every field arrives as a parameter,
so own-page values cost zero queries:

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

Notice what each piece does:

- `{{{has_tool|}}}` — the page's tool list, free (it's a template parameter).
- `{{Property/value|prop=Has training required|page=@@t@@}}` — cross-page lookup for each tool, one `#show` each. Unavoidable since training data lives on tool pages.
- `{{Category/property-row | … }}` — composes a standard row with the same look as the default layout. Hidden when value is empty.
- `{{Category/backlink-row | … }}` — composes a backlink section for "what links here via Has location".

Adding a fourth field's customization here means editing this one
template — no chain of templates to copy through.

### When to choose which

| Path | Scope of change | Files to author |
|------|-----------------|-----------------|
| A — field renderer | One field's value expression | 1 template + 1 annotation on the existing field |
| B — custom display | The whole category's layout | 1 template + 1 annotation (`Has display template::…`) on the category; `Has display format` is ignored when a custom template is set |

Path A composes — multiple fields can each have their own renderer,
and the default table still hosts them. Reach for Path B when the
layout itself diverges, not just one cell.

## Performance notes

The auto-generated dispatcher's value lookups, label lookups, ancestor
walks, and inverse-label lookups are all resolved at **template-
generation time**, not at page-render time. The cost of rendering a
content page is roughly:

- One `#ask` per subobject category that the page declares
- One `#ask` count + one `#ask` list per declared backlink property
  (only counted when results exist)
- SMW's per-annotation storage diff (proportional to total properties
  emitted, including subobjects)

Anything you author that uses `{{#show:…}}`, `{{Property/value|…}}`,
or `{{Category/render-reverse}}` (dynamic discovery) adds ~one
SMW query and one persistent query-dependency subobject per call.
For a page rendered many times, each such call re-fires on every
re-render.

When customizing, prefer **passing values as parameters** (the
dispatcher's pattern) over **looking values up at render time**. The
shipped row and value primitives all take values as parameters; the
custom display template you write receives every field as a parameter
already.

## Notes on upgrades

- All shipped base-config templates have `replaceable: true`. Editing
  them directly will be overwritten on next install/update. To
  customize globally, copy to a different name (e.g.
  `Template:MyWiki/property-row`) and reference that.
- Per-category overrides via `Has display template` are stored as
  category metadata and are **not** affected by base-config refresh.
  This is the recommended customization path.
- Per-field render templates via `Has render template` are stored on
  the field's subobject and survive upgrades.
