# Customizing Category Display

SemanticSchemas renders each content page's category data through a
**layered template system**. Every layer is overridable, so you can
customize at whichever grain matches what you're trying to do.

This guide walks through the three most common use cases, from simplest
to most involved.

## Overview of the layers

When a content page in `Category:Book` renders, here is what happens:

```
{{Book}}                                                 (PHP-generated dispatcher)
  └── {{Category/table | category=Book}}                 Tier C: orchestrator
       ├── {{Category/display-header | category=Book}}
       ├── {{Category/display-rows   | category=Book}}   composes:
       │    ├── {{Category/effective-properties}}        Tier A: data
       │    └── {{Category/property-row   | prop=…}}     Tier B: row renderer
       │         ├── {{Property/label | prop=…}}         Tier A: data
       │         └── {{Property/value | prop=…}}         Tier A: data
       ├── {{Category/render-reverse | …}}
       └── {{Category/subobjects     | …}}               composes:
            ├── {{Category/effective-subobject-types}}   Tier A: data
            └── {{Category/subobject-instances|…}}       Tier A: iteration
                 └── {{Category/subobject-instance|…}}   Tier B: instance row
                      └── {{Category/table|…}}           (recursive)
```

- **Tier A** templates return *strings* (data). No markup.
- **Tier B** templates return *wikitext fragments* (rows, sections).
  These are the primary override points.
- **Tier C** templates return *whole displays* (table, sidebox). These
  compose A + B.

## Level 1 — Default: do nothing

`Category:Book` with `Has display format = table` gets the default
`{| wikitable` layout. No action needed.

## Level 2 — Customize one property's row

**Use case**: "`Has_email` should render as a `mailto:` link, bold."

Create `Template:Category/property-row/Has_email`:

```wikitext
<includeonly>{{!}}-
! {{Property/label|prop={{{prop}}}}}
{{!}} '''[mailto:{{Property/value|prop={{{prop}}}|page={{{page}}}}} {{Property/value|prop={{{prop}}}|page={{{page}}}}}]'''
</includeonly>
```

That's it. `Category/property-row` auto-dispatches to this template
whenever `prop=Property:Has_email` via `#ifexist`. No other template
needs to change; no category needs its schema touched.

**How it works**: The base `Category/property-row.wikitext` contains:

```wikitext
{{#ifexist:Template:Category/property-row/{{PAGENAME:{{{prop}}}}}
  | {{Category/property-row/{{PAGENAME:{{{prop}}}}} | prop=… | page=… }}
  | <default render>
}}
```

The dispatch is structural — create the override page and it takes
effect the next time the calling page is reparsed.

## Level 3 — Customize all rows globally

**Use case**: "Every row should have a help-icon cell."

Edit `Template:Category/property-row` directly. Your changes apply to
every category wiki-wide. On upgrade, SemanticSchemas will not overwrite
your changes if `replaceable: true` is off for this template in the
vocab manifest — but note that base-config is ordinarily replaceable,
so the safest pattern is to document local changes and restore them on
upgrade.

## Level 4 — Write a fully custom display

**Use case**: "I want chapters rendered as cards, not as nested tables."

Create your own display template and point the category at it via
`Has display template`, optionally combined with `Has display format = none`
to suppress the default.

```wikitext
{{!-- Template:BookDisplay --}}
<includeonly>
<div class="book-layout">
  <h1>{{Property/value|prop=Has_title|page={{FULLPAGENAME}}}}</h1>
  <p><em>by {{Property/value|prop=Has_author|page={{FULLPAGENAME}}}}</em></p>

  <h2>Properties</h2>
  {{#arraymap:{{Category/effective-properties|category={{{category}}}}}|,|@@p@@|
    <div class="prop">
      <label>{{Property/label|prop=@@p@@}}:</label>
      <span>{{Property/value|prop=@@p@@|page={{FULLPAGENAME}}}}</span>
    </div>
  |\n}}

  <h2>Chapters</h2>
  {{Category/subobject-instances
    | subcat=Category:Chapter
    | page={{FULLPAGENAME}}
    | row-template=BookChapterCard
  }}
</div>
</includeonly>
```

And `Template:BookChapterCard` (SMW passes the subobject fragment as `{{{1}}}`):

```wikitext
<includeonly>
<div class="chapter-card">
  <h3>{{Property/value|prop=Has_chapter_title|page={{{1}}}}}</h3>
  <p>{{Property/value|prop=Has_summary|page={{{1}}}}}</p>
</div>
</includeonly>
```

Then on `Category:Book` set:

```
[[Has display template::Template:BookDisplay]]
[[Has display format::none]]
```

You get total layout freedom. SemanticSchemas owns the query complexity
(ancestor walks, inverse `#ask` patterns); you own the presentation.

## Primitive reference

### Data primitives (Tier A)

| Template | Purpose | Returns |
|---|---|---|
| `{{Category/ancestors\|category=X}}` | Category + all ancestors | `Category:X\|\|Category:Parent\|\|...` |
| `{{Category/effective-properties\|category=X}}` | All effective property page names | `Property:A,Property:B,...` |
| `{{Category/effective-subobject-types\|category=X}}` | All effective subobject categories | `Category:Chapter,Category:Review` |
| `{{Category/subobject-instances\|subcat=…\|page=…\|row-template=…}}` | Iterate instances, dispatch to row template | rendered instances |
| `{{Category/label\|category=X}}` | Display label with fallback | `"Book"` |
| `{{Property/label\|prop=Property:X}}` | Display label with fallback | `"Author"` |
| `{{Property/value\|prop=Property:X\|page=Y}}` | Property value on a page or subobject | `"Jane Doe"` |

### Element renderers (Tier B, overridable)

| Template | Purpose | Override via |
|---|---|---|
| `{{Category/property-row\|prop=…\|page=…}}` | One table row | edit globally, OR create `Template:Category/property-row/<PropName>` |
| `{{Category/subobject-instance\|…}}` | One subobject instance | edit globally, OR pass a different `row-template=` to `Category/subobject-instances` |

### Containers (Tier C)

| Template | Purpose |
|---|---|
| `{{Category/table\|category=…}}` | Full wikitable display |
| `{{Category/sidebox\|category=…}}` | Floating sidebar display |

## Notes on customization and upgrades

- The shipped base-config templates are marked `replaceable: true`, so
  they will be restored on extension upgrade. For permanent custom
  versions, **copy** a base template to a different name
  (`Template:MyWiki/property-row`) and reference that from a custom
  `Has display template` — don't rely on edits to the shipped templates
  surviving upgrades.
- Per-property override templates
  (`Template:Category/property-row/<Prop>`) are **not** shipped and
  therefore will not be replaced on upgrade. This is the safest path
  for targeted customization.
- The primitives compose uniformly for pages and subobject fragments:
  `{{Property/value|prop=X|page=MyBook}}` and
  `{{Property/value|prop=X|page=MyBook#_abc123}}` work identically, so
  your custom row templates can render both without special-casing.
