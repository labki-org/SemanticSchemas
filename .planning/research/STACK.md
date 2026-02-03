# Technology Stack

**Project:** SemanticSchemas Multi-Category Page Creation
**Researched:** 2026-02-02
**Confidence:** MEDIUM

## Executive Summary

Multi-category page creation requires NO new external dependencies. The existing MediaWiki/SMW/PageForms stack already provides all necessary capabilities. Key findings:

1. **PageForms natively supports multiple templates** via multiple `{{{for template}}}` blocks
2. **SMW conditional #set** works with ParserFunctions `#if` (with caveats around evaluation)
3. **Tree checkbox UI** can be custom-built with existing jQuery/OOUI or leverage PageForms' bundled jstree
4. **API endpoints** follow existing ApiBase pattern already used in SemanticSchemas

## Current Stack (Unchanged)

### Core Platform
| Technology | Version | Purpose | Status |
|------------|---------|---------|--------|
| MediaWiki | >= 1.39.0 | Wiki platform | Already in use |
| PHP | >= 8.1.0 | Server-side language | Already in use |
| Semantic MediaWiki | Latest (required) | Semantic data storage | Already in use |
| PageForms | Latest (required) | Form generation | Already in use |
| ParserFunctions | Core extension | Conditional logic (#if, #ifeq) | Assumed present |

### Frontend Libraries
| Library | Version | Purpose | Status |
|---------|---------|---------|--------|
| jQuery | Core (always loaded) | DOM manipulation | Already in use |
| OOUI | Core library | UI widgets | Already in use |
| mediawiki.api | Core module | AJAX API calls | Already in use |
| mediawiki.util | Core module | URL/title utilities | Already in use |

### PHP Libraries
| Library | Version | Purpose | Status |
|---------|---------|---------|--------|
| symfony/yaml | ^5.0\|^6.0 | YAML parsing | Already in use |

## Stack Additions for Multi-Category Features

**NONE REQUIRED.** All capabilities exist in current stack.

### What We're NOT Adding

| Library | Why Not |
|---------|---------|
| jstree (standalone) | PageForms already bundles jstree 3.x; can reuse or build custom |
| Fancy Tree | Replaced by jstree in PageForms 5.0+ (2020) |
| jQuery UI | Being removed from PageForms; OOUI is the modern alternative |
| Semantic Scribunto | Lua scripting overkill for conditional #set use cases |
| External tree libraries | Custom tree with existing jQuery/CSS is sufficient |

## Integration Patterns for New Features

### 1. PageForms Multiple Template Syntax

**Capability:** Multiple `{{{for template}}}` blocks in single form

**Documentation source:** [Extension:Page Forms/Defining forms](https://www.mediawiki.org/wiki/Extension:Page_Forms/Defining_forms)

**Syntax:**
```
{{{for template|Category1}}}
  {{{field|Property1}}}
  {{{field|Property2}}}
{{{end template}}}

{{{for template|Category2}}}
  {{{field|Property3}}}
  {{{field|Property4}}}
{{{end template}}}
```

**Confidence:** HIGH - Verified in official PageForms documentation

**Integration point:** `FormGenerator.php` already generates single `{{{for template}}}` blocks. Extend to generate multiple blocks when multi-category mode is active.

**Known limitation:** Each template writes to the same page. Property name conflicts require disambiguation or property reuse logic.

### 2. SMW Conditional #set Pattern

**Capability:** Conditionally set semantic properties based on parameter presence

**Documentation source:**
- [Help:Parser functions and conditional values](https://www.semantic-mediawiki.org/wiki/Help:Parser_functions_and_conditional_values)
- [Help:Setting values using #set](https://www.semantic-mediawiki.org/wiki/Help:Setting_values)

**Syntax:**
```php
{{#if:{{{PropertyName|}}}|{{#set:Property={{{PropertyName}}}}}|}}
```

**CRITICAL LIMITATION:** MediaWiki's parser evaluates ALL parameters before passing to functions. This means:

```php
// BAD - Both branches evaluate, sets property twice
{{#if:{{{param|}}}
  |{{#set:Prop=yes}}
  |{{#set:Prop=no}}
}}

// GOOD - Only sets when parameter exists
{{#if:{{{param|}}}|{{#set:Prop={{{param}}}}}|}}
```

**Workaround for empty values:**
- Use `{{{param|}}}` with trailing pipe to default to empty string
- Test parameter presence: `{{#if:{{{param|}}}|...}}`
- For "empty vs undefined", use: `{{#ifeq:{{{param|+}}}|{{{param|-}}}|defined|undefined}}`

**Confidence:** MEDIUM - Pattern works but requires careful handling of edge cases

**Integration point:** `TemplateGenerator.php` semantic template generation. Wrap each `{{#set:...}}` in conditional when generating multi-category templates.

**Reference:** [Help:Parser functions in templates](https://www.mediawiki.org/wiki/Help:Parser_functions_in_templates)

### 3. Tree Checkbox UI Options

**Need:** Hierarchical category tree with checkboxes for multi-selection

**Option A: Custom implementation with existing stack**

**Recommended approach.** Extend current hierarchy visualization code.

**Assets available:**
- `ext.semanticschemas.hierarchy.js` - Already renders category trees with toggle
- jQuery (core) - DOM manipulation
- OOUI CheckboxMultiselectInputWidget - Available but NO native tree support

**Implementation:**
```javascript
// Extend existing renderHierarchyTree() function
// Add checkbox to each node
const $checkbox = $('<input>')
  .attr('type', 'checkbox')
  .attr('name', 'categories[]')
  .val(categoryName);
```

**Confidence:** HIGH - Existing code already renders trees, checkboxes are trivial addition

**Option B: Reuse PageForms jstree**

**Alternative if complex tree interactions needed.**

**Asset location:** PageForms bundles jstree at `libs/foreign/jstree/jstree.js` (MIT license, by Ivan Bozhanov)

**Version:** jstree 3.x (bundled since PageForms 5.0, December 2020)

**Usage:** Would require loading PageForms' jstree module or bundling separately

**Confidence:** MEDIUM - jstree exists but integration path unclear

**Documentation:**
- [PageForms replaced FancyTree with jstree in 5.0](https://www.mediawiki.org/wiki/Extension_talk:Page_Forms/Archive_July_to_September_2020)
- [jstree official site](https://www.jstree.com/)

**Option C: PageForms "tree" input type**

**NOT RECOMMENDED for this use case.**

**Why not:** PageForms' tree input is designed for form fields, not for category selection in Special page UI. Would require form submission pattern, not AJAX API pattern.

**Documentation:** [Extension:Page Forms/Input types](https://www.mediawiki.org/wiki/Extension:Page_Forms/Input_types)

### 4. MediaWiki API Endpoint Pattern

**Need:** New API endpoint for multi-category property resolution

**Pattern:** Action API module (already used in SemanticSchemas)

**Existing example:** `ApiSemanticSchemasHierarchy.php`

**Registration in extension.json:**
```json
"APIModules": {
  "semanticschemas-multicategory": "MediaWiki\\Extension\\SemanticSchemas\\Api\\ApiSemanticSchemasMultiCategory"
}
```

**Implementation pattern:**
```php
class ApiSemanticSchemasMultiCategory extends ApiBase {
  public function execute() {
    $params = $this->extractRequestParams();
    $categories = $params['categories']; // array

    // Resolve properties from multiple categories
    $resolver = new MultiCategoryPropertyResolver();
    $properties = $resolver->resolve($categories);

    $this->getResult()->addValue(
      null,
      $this->getModuleName(),
      $properties
    );
  }

  public function getAllowedParams() {
    return [
      'categories' => [
        ApiBase::PARAM_TYPE => 'string',
        ApiBase::PARAM_ISMULTI => true,
        ApiBase::PARAM_REQUIRED => true,
      ],
    ];
  }
}
```

**Confidence:** HIGH - Exact pattern already in use

**Documentation:**
- [API:Extensions](https://www.mediawiki.org/wiki/API:Extensions)
- Existing implementation at `src/Api/ApiSemanticSchemasHierarchy.php`

**Alternative: REST API**

**NOT RECOMMENDED for this milestone.**

**Why not:**
- Action API is simpler and consistent with existing codebase
- REST API requires MediaWiki 1.34+ and different patterns
- Action API modules register via `APIModules` key (simpler)

**When to consider REST:** If building public/external API in future milestones

**Documentation:** [API:REST API/Extensions](https://www.mediawiki.org/wiki/API:REST_API/Extensions)

## ResourceLoader Module Configuration

**Need:** Register new JS module for multi-category page creation UI

**Pattern:** Add to `extension.json` ResourceModules section

**Recommended module:**
```json
"ext.semanticschemas.multicategory": {
  "scripts": [
    "resources/ext.semanticschemas.multicategory.js"
  ],
  "styles": [
    "resources/ext.semanticschemas.multicategory.css"
  ],
  "dependencies": [
    "mediawiki.api",
    "mediawiki.util",
    "jquery",
    "ext.semanticschemas.hierarchy"
  ],
  "messages": [
    "semanticschemas-multicategory-select-categories",
    "semanticschemas-multicategory-create-page",
    "..."
  ]
}
```

**Key dependencies:**
- `mediawiki.api` - For AJAX API calls (NOT jQuery.ajax, use mw.Api())
- `mediawiki.util` - For URL/title utilities
- `jquery` - Core (don't declare, always present, but safe to list)
- `ext.semanticschemas.hierarchy` - Reuse existing tree rendering

**Important notes:**
- jQuery and mediawiki.base are ALWAYS loaded, but declaring them is safe
- Use `mw.Api()` not `$.ajax()` for MediaWiki API calls
- OOUI modules: `oojs-ui-core`, `oojs-ui-widgets`, `oojs-ui-windows` if needed

**Documentation:**
- [ResourceLoader/Core modules](https://www.mediawiki.org/wiki/ResourceLoader/Core_modules)
- [ResourceLoader/Developing with ResourceLoader](https://www.mediawiki.org/wiki/ResourceLoader/Developing_with_ResourceLoader)

**Confidence:** HIGH - Standard ResourceLoader pattern

## OOUI Widgets for Tab UI

**Need:** Tab interface for "Create Page" in category view

**Existing pattern:** `SpecialSemanticSchemas.php` already uses OOUI for tabs

**Available widgets:**
- `OOUI\TabSelectWidget` - Tab selector (PHP)
- `OOUI\IndexLayout` - Tabbed panel layout (PHP)
- `OO.ui.TabSelectWidget` - Tab selector (JS)
- `OO.ui.IndexLayout` - Tabbed panel layout (JS)

**Existing usage in codebase:**
Check `SpecialSemanticSchemas.php` for tab implementation patterns. The extension already uses OOUI for tabbed interfaces.

**Infusing pattern:**
```php
// PHP side
$widget = new OOUI\CheckboxMultiselectInputWidget([
  'infusable' => true,
  'id' => 'category-selector',
  'options' => $categoryOptions,
]);

// JS side
OO.ui.infuse($('#category-selector'));
```

**Important:** OOUI is in maintenance mode (replaced by Codex), but fully supported and already in use in this extension.

**Documentation:**
- [OOUI/Using OOUI in MediaWiki](https://www.mediawiki.org/wiki/OOUI/Using_OOUI_in_MediaWiki)
- [OOUI Demos](https://doc.wikimedia.org/oojs-ui/master/demos/)

**Confidence:** HIGH - Already in use in extension

## Security and Best Practices

### API Security
- Use `ApiBase::PARAM_TYPE` validation for all parameters
- Use `ApiBase::PARAM_ISMULTI` for array parameters
- Optional: Enable auth check via `$wgSemanticSchemasRequireApiAuth`
- Existing pattern: `checkUserRightsAny('read')` in hierarchy API

### Parser Function Safety
- NEVER use user input directly in #set without sanitization
- MediaWiki templates auto-escape HTML, but validate property names
- Use `{{{param|}}}` pattern to default to empty string

### Rate Limiting
- Existing: 20 operations/hour per user (configurable)
- Extend to multi-category operations if expensive
- Sysops exempt via 'protect' permission

### Logging
- Use existing `ManualLogEntry('semanticschemas', 'generate')` pattern
- Log multi-category page creation operations

## Testing Infrastructure

**Existing capabilities:**
- PHPUnit: `vendor/bin/phpunit`
- Docker test environment: `tests/scripts/reinstall_test_env.sh`
- Test wiki: http://localhost:8889

**Test coverage needed:**
1. FormGenerator with multiple templates
2. Conditional #set template generation
3. Multi-category API endpoint
4. Property deduplication logic

**No new test infrastructure required.**

## Version Compatibility

| Component | Minimum | Tested | Notes |
|-----------|---------|--------|-------|
| MediaWiki | 1.39.0 | 1.44 | Per composer.json dev deps |
| PHP | 8.1.0 | 8.1 | Per composer.json |
| SMW | Any recent | 4.x+ | Extension requires SMW_VERSION defined |
| PageForms | Any recent | 5.0+ | Extension requires PF_VERSION defined |

**PageForms 5.0+ (Dec 2020):** Uses jstree instead of FancyTree

**Confidence:** HIGH for stated versions

## Key Decision Rationale

### Why custom tree over jstree?
1. **Already have tree code:** `ext.semanticschemas.hierarchy.js` renders trees
2. **Simple need:** Checkboxes, not complex drag/drop/edit
3. **No dependency management:** Custom code, no version tracking
4. **Consistency:** Matches existing hierarchy visualization

### Why Action API over REST API?
1. **Consistency:** Extension already uses Action API
2. **Simpler:** ApiBase pattern well-established
3. **Sufficient:** No external API consumers
4. **MW version:** REST API patterns vary by MW version

### Why NOT Semantic Scribunto?
1. **Overkill:** Lua scripting for simple conditionals
2. **Complexity:** New language/extension dependency
3. **ParserFunctions sufficient:** #if handles the use case
4. **Known pattern:** #if + #set is documented SMW pattern

## Implementation Checklist

Stack-related implementation tasks:

- [ ] Extend FormGenerator.php to output multiple `{{{for template}}}` blocks
- [ ] Extend TemplateGenerator.php to wrap `{{#set:...}}` in `{{#if:...}}`
- [ ] Create `ApiSemanticSchemasMultiCategory.php` following ApiBase pattern
- [ ] Create `ext.semanticschemas.multicategory` ResourceLoader module
- [ ] Extend hierarchy tree renderer to add checkboxes
- [ ] Register new API module in extension.json
- [ ] Add i18n messages for multi-category UI

**All tasks use existing stack components.**

## Sources

### PageForms
- [Extension:Page Forms/Defining forms](https://www.mediawiki.org/wiki/Extension:Page_Forms/Defining_forms)
- [Extension:Page Forms/Page Forms and templates](https://www.mediawiki.org/wiki/Extension:Page_Forms/Page_Forms_and_templates)
- [Extension:Page Forms/Input types](https://www.mediawiki.org/wiki/Extension:Page_Forms/Input_types)
- [PageForms version history (jstree adoption)](https://www.mediawiki.org/wiki/Extension_talk:Page_Forms/Archive_July_to_September_2020)

### Semantic MediaWiki
- [Help:Parser functions and conditional values](https://www.semantic-mediawiki.org/wiki/Help:Parser_functions_and_conditional_values)
- [Help:Setting values using #set](https://www.semantic-mediawiki.org/wiki/Help:Setting_values)
- [Working with the #set template parameter](https://www.semantic-mediawiki.org/wiki/Help:Setting_values/Working_with_the_template_parameter)

### MediaWiki Core
- [Help:Parser functions in templates](https://www.mediawiki.org/wiki/Help:Parser_functions_in_templates)
- [API:Extensions](https://www.mediawiki.org/wiki/API:Extensions)
- [API:REST API/Extensions](https://www.mediawiki.org/wiki/API:REST_API/Extensions)
- [ResourceLoader/Core modules](https://www.mediawiki.org/wiki/ResourceLoader/Core_modules)
- [ResourceLoader/Developing with ResourceLoader](https://www.mediawiki.org/wiki/ResourceLoader/Developing_with_ResourceLoader)

### OOUI
- [OOUI/Using OOUI in MediaWiki](https://www.mediawiki.org/wiki/OOUI/Using_OOUI_in_MediaWiki)
- [OOUI/Widgets/Inputs](https://www.mediawiki.org/wiki/OOUI/Widgets/Inputs)
- [OOUI/Widgets/Selects and Options](https://www.mediawiki.org/wiki/OOUI/Widgets/Selects_and_Options)
- [OOUI Demos](https://doc.wikimedia.org/oojs-ui/master/demos/)

### jstree
- [jstree official site](https://www.jstree.com/)
- [jstree GitHub repository](https://github.com/vakata/jstree)

## Confidence Assessment

| Area | Confidence | Reason |
|------|------------|--------|
| PageForms multi-template | HIGH | Verified in official documentation, standard feature |
| SMW conditional #set | MEDIUM | Pattern works but parser evaluation subtleties exist |
| Tree UI implementation | HIGH | Existing code provides foundation, checkboxes are straightforward |
| API endpoint pattern | HIGH | Exact pattern already implemented in codebase |
| ResourceLoader modules | HIGH | Standard MediaWiki pattern, well-documented |
| OOUI widgets | HIGH | Already in use in SpecialSemanticSchemas.php |

**Overall confidence: MEDIUM** - All components verified, but SMW conditional patterns require careful testing for edge cases (empty vs undefined parameters).

## Risk Assessment

| Risk | Severity | Mitigation |
|------|----------|------------|
| Conditional #set edge cases | Medium | Comprehensive testing with empty/null/undefined parameters |
| Property name conflicts | Low | Design phase: decide on disambiguation strategy |
| Parser performance (many #if) | Low | SMW/ParserFunctions handle this, but monitor |
| Tree checkbox accessibility | Low | Use semantic HTML, ARIA attributes |

## Open Questions for Design Phase

These are NOT stack issues, but design decisions that affect implementation:

1. **Property conflict resolution:** When two categories define same property, how to handle in multi-template form?
   - Option A: Reuse property (same field, both templates write it)
   - Option B: Disambiguate (e.g., Category1_PropertyName vs Category2_PropertyName)

2. **Conditional #set strategy:** When to use conditional wrapping?
   - Option A: Always wrap (safe but verbose)
   - Option B: Only wrap when property is category-specific (requires tracking)

3. **Tree checkbox behavior:** How to handle parent/child category selection?
   - Option A: Independent (can select parent without child)
   - Option B: Cascade (selecting parent selects all children)

4. **API response format:** What data structure for multi-category properties?
   - See FEATURES.md and ARCHITECTURE.md for recommendations
