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

### Level 2 — Per-field render template

**Use case**: "`Has email` should render as a `mailto:` link;
`Has website` as an external link."

On the Field declaration (a `Subobject field` or `Property field`
attached to the category), set `Has render template`:

```wikitext
{{Property field/subobject
 | for_property = Has email
 | has_render_template = Property/Email
}}
```

The dispatcher bakes the chosen render template around the value:

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
on the field.

### Level 3 — Fully custom display template

**Use case**: "Textbooks should render with a custom card layout, not
a property table."

On the category, set:

```wikitext
[[Has display format::none]]
[[Has display template::Template:TextbookDisplay]]
```

`Has display format = none` suppresses the default `Category/table`
call, then `Has display template` swaps in your template. Your template
receives every field as a named parameter:

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
