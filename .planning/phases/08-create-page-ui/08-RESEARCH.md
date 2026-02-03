# Phase 8: Create Page UI - Research

**Researched:** 2026-02-02
**Domain:** MediaWiki Special Page + JavaScript UI (hierarchy tree, AJAX property preview, FormEdit redirect)
**Confidence:** HIGH

## Summary

Phase 8 builds Special:CreateSemanticPage -- a user-facing page where users select categories from a hierarchy tree, see a live preview of merged/deduplicated properties, name the page, and submit to be redirected to Special:FormEdit with a generated composite form. The API endpoint (Phase 7, `semanticschemas-multicategory`) and composite form generation (Phase 6, `CompositeFormGenerator`) are already built.

The codebase already contains mature patterns for everything needed: a SpecialPage class (`SpecialSemanticSchemas`), multiple ResourceLoader JS modules with AJAX API calls, debounced updates (`formpreview.js` with 500ms debounce), tree rendering (`hierarchy.js`), and CSS using the project's design token system (`ext.semanticschemas.styles.css`). The new page follows these exact patterns. No new libraries or frameworks are needed.

The critical finding is that the existing `semanticschemas-multicategory` API does NOT currently return property datatypes or namespace information, both of which the UI requires per CONTEXT.md decisions. The API must be enhanced before the UI can display datatypes and detect namespace conflicts.

**Primary recommendation:** Build the new Special page as a separate PHP class (`SpecialCreateSemanticPage`) with a companion JS module (`ext.semanticschemas.createpage`), following the exact codebase patterns already established. Extend the multi-category API to include datatype and namespace data.

## Standard Stack

The established libraries/tools for this domain:

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| MediaWiki SpecialPage | MW 1.39+ | PHP Special page class | Already used by SpecialSemanticSchemas; provides permission checks, OutputPage, i18n |
| MediaWiki ResourceLoader | MW 1.39+ | JS/CSS module registration and loading | All existing JS modules use this; registered in extension.json |
| mw.Api | MW core | AJAX calls to MediaWiki API | Used by hierarchy.js and formpreview.js for all API interactions |
| jQuery | MW bundled | DOM manipulation and event handling | All existing JS modules use jQuery; bundled with MediaWiki |
| mw.msg | MW core | Internationalized message strings | All existing JS uses this for user-facing text |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| mw.util.getUrl | MW core | Generate wiki page URLs | Used for building links in tree and preview (existing pattern) |
| MediaWiki Html class | MW core | Safe HTML generation in PHP | Used by SpecialSemanticSchemas for all server-rendered HTML |
| mw.Title | MW core | Page title manipulation (namespace, exists check) | Page name validation and existence checking |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Custom jQuery tree | OOUI widgets | OOUI has NO tree widget; OOUI is in maintenance mode (Codex is recommended for new MW development). Custom tree matches existing hierarchy.js pattern exactly |
| Custom CSS | OOUI styles | OOUI styling would conflict with existing design system (--ss-* CSS variables). Custom CSS matches codebase aesthetic |
| Codex design system | For brand-new MW development | Codex is the recommended replacement for OOUI but requires Vue.js integration overhead. Overkill for an extension targeting MW 1.39+ where the existing codebase uses vanilla JS + jQuery |

**Installation:**
No new packages needed. Everything is already available in MediaWiki core and the existing extension.

## Architecture Patterns

### Recommended Project Structure
```
src/
  Special/
    SpecialCreateSemanticPage.php    # New SpecialPage class
  Api/
    ApiSemanticSchemasMultiCategory.php  # Enhanced (add datatype + namespace)
resources/
  ext.semanticschemas.createpage.js   # Main JS module
  ext.semanticschemas.createpage.css  # CSS styles
i18n/
  en.json                             # Additional messages
  qqq.json                            # Message documentation
extension.json                        # New SpecialPage + ResourceModule registration
SemanticSchemas.alias.php             # Alias for new special page
```

### Pattern 1: SpecialPage Registration (extension.json)
**What:** Register a new Special page alongside the existing one
**When to use:** Always -- this is the MediaWiki standard for extension special pages
**Example:**
```json
// Source: Existing extension.json pattern in this codebase
{
  "SpecialPages": {
    "SemanticSchemas": "MediaWiki\\Extension\\SemanticSchemas\\Special\\SpecialSemanticSchemas",
    "CreateSemanticPage": "MediaWiki\\Extension\\SemanticSchemas\\Special\\SpecialCreateSemanticPage"
  }
}
```
Confidence: HIGH -- follows existing pattern in this codebase.

### Pattern 2: SpecialPage PHP Class
**What:** New SpecialPage subclass with `execute()` method that checks permissions, renders HTML, and loads JS module
**When to use:** This is the standard pattern for all MediaWiki Special pages
**Example:**
```php
// Source: Pattern from existing SpecialSemanticSchemas.php
class SpecialCreateSemanticPage extends SpecialPage {
    public function __construct() {
        // 'edit' permission matches the API endpoint requirement
        parent::__construct('CreateSemanticPage', 'edit');
    }

    public function execute($subPage) {
        $this->setHeaders();
        $this->checkPermissions();
        $output = $this->getOutput();

        // Dependency checks (same as existing special page)
        if (!defined('SMW_VERSION')) { /* error */ }
        if (!defined('PF_VERSION')) { /* error */ }

        // Load JS module
        $output->addModules('ext.semanticschemas.createpage');
        $output->addModuleStyles('ext.semanticschemas.styles');

        // Render server-side HTML skeleton
        $output->addHTML($this->renderPageStructure());
    }
}
```
Confidence: HIGH -- follows existing SpecialSemanticSchemas pattern exactly.

### Pattern 3: ResourceLoader JS Module
**What:** Client-side JS registered as a ResourceLoader module with explicit dependencies
**When to use:** For all client-side interactivity
**Example:**
```json
// Source: Existing ext.semanticschemas.hierarchy module pattern
{
  "ext.semanticschemas.createpage": {
    "scripts": ["resources/ext.semanticschemas.createpage.js"],
    "styles": ["resources/ext.semanticschemas.createpage.css"],
    "dependencies": [
      "mediawiki.api",
      "mediawiki.util",
      "mediawiki.Title",
      "jquery"
    ],
    "messages": [
      "semanticschemas-createpage-title",
      "semanticschemas-createpage-empty-state",
      "... other i18n keys"
    ],
    "localBasePath": "",
    "remoteExtPath": "SemanticSchemas",
    "targets": ["desktop", "mobile"]
  }
}
```
Confidence: HIGH -- exact pattern used by all 4 existing modules.

### Pattern 4: AJAX API Call with Debounce
**What:** Call `semanticschemas-multicategory` API with debounced trigger on category selection changes
**When to use:** Every time user checks/unchecks a category checkbox
**Example:**
```javascript
// Source: Pattern from existing formpreview.js (line 29-30, 573-596)
var UPDATE_DELAY = 300; // ms debounce
var updateTimer = null;

function onSelectionChanged() {
    if (updateTimer !== null) {
        clearTimeout(updateTimer);
    }
    updateTimer = setTimeout(function () {
        var selectedCategories = getSelectedCategories();
        if (selectedCategories.length === 0) {
            showEmptyState();
            return;
        }
        fetchPreview(selectedCategories);
    }, UPDATE_DELAY);
}

function fetchPreview(categories) {
    var api = new mw.Api();
    api.get({
        action: 'semanticschemas-multicategory',
        categories: categories.join('|'),
        format: 'json'
    }).done(function (response) {
        var data = response['semanticschemas-multicategory'];
        renderPropertyPreview(data);
    }).fail(function (code, result) {
        showError(result.error?.info || code);
    });
}
```
Confidence: HIGH -- debounce pattern already used in formpreview.js.

### Pattern 5: Tree Rendering with Checkboxes
**What:** Recursive tree builder extending the existing hierarchy tree pattern with checkboxes
**When to use:** Category selection tree on the left panel
**Example:**
```javascript
// Source: Adapted from existing hierarchy.js renderHierarchyTree (line 52-134)
function buildCheckboxNode(title, nodes, depth) {
    var node = nodes[title];
    if (!node) return null;

    var displayName = title.replace(/^Category:/, '');
    var $li = $('<li>');
    var $content = $('<span>').addClass('ss-create-node-content');

    // Collapse toggle (if has children)
    var parents = Array.isArray(node.parents) ? node.parents : [];
    if (parents.length) {
        var collapsed = depth > 0; // First level expanded, rest collapsed
        $content.append(
            $('<span>')
                .addClass('ss-hierarchy-toggle')
                .attr({ role: 'button', tabindex: 0, 'aria-expanded': !collapsed })
                .text(collapsed ? '\u25B6' : '\u25BC')
        );
    }

    // Checkbox
    var $checkbox = $('<input>')
        .attr({ type: 'checkbox', 'data-category': displayName })
        .addClass('ss-create-checkbox');
    $content.append($checkbox, ' ', $('<span>').text(displayName));
    $li.append($content);

    // Children (recursive)
    if (parents.length) {
        var $ul = $('<ul>').addClass('ss-hierarchy-tree-nested');
        if (depth > 0) $ul.hide(); // Collapsed by default
        parents.forEach(function (p) {
            var $child = buildCheckboxNode(p, nodes, depth + 1);
            if ($child) $ul.append($child);
        });
        $li.append($ul);
    }

    return $li;
}
```
Confidence: HIGH -- adapts existing tree rendering from hierarchy.js.

### Pattern 6: FormEdit Redirect URL
**What:** Build redirect URL to Special:FormEdit after composite form is generated
**When to use:** On form submission
**Example:**
```javascript
// Source: PageForms documentation + existing tests/LocalSettings.test.php (line 61)
// URL format: Special:FormEdit/<form_name>/<target_page>
// OR: Special:FormEdit?form=<form>&target=<page>

function buildFormEditUrl(formName, pageName, namespace) {
    var targetPage = namespace ? namespace + ':' + pageName : pageName;
    // Path format is simpler and standard in this codebase
    return mw.util.getUrl(
        'Special:FormEdit/' + formName + '/' + targetPage
    );
}
```
Confidence: HIGH -- FormEdit URL format is well-documented (Special:FormEdit/FormName/PageName).

### Anti-Patterns to Avoid
- **Do NOT use OOUI for the tree widget:** OOUI has no tree component. Building a tree from OOUI checkboxes would create unnecessary complexity and conflict with the existing design system.
- **Do NOT make the tree a separate API call:** The `semanticschemas-hierarchy` API already returns the full node tree. Reuse its data structure for the checkbox tree.
- **Do NOT implement form generation on the client side:** The server-side `CompositeFormGenerator` already handles this. The JS should POST to trigger server-side generation, then redirect.
- **Do NOT auto-select children when parent is checked:** CONTEXT.md explicitly locks this as "No auto-select on parent check."
- **Do NOT add a search/filter input:** CONTEXT.md explicitly locks this as "Tree browsing only -- no search/filter input."

## Don't Hand-Roll

Problems that look simple but have existing solutions:

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Category hierarchy tree | Custom tree data fetcher | `semanticschemas-hierarchy` API | Already returns full node graph with parent-child relationships |
| Property resolution | Client-side merge logic | `semanticschemas-multicategory` API | Already handles C3 linearization, deduplication, required promotion |
| Composite form creation | Client-side form builder | `CompositeFormGenerator::generateAndSaveCompositeForm()` | Already handles shared properties, template sections, form naming |
| Form naming convention | Custom naming | `CompositeFormGenerator::getCompositeFormName()` | Alphabetical sort + "+" join is locked decision from Phase 6 |
| Page existence check | Custom AJAX endpoint | `mw.Api` with `action=query&titles=...` | Standard MW API; check for `missing` property in response |
| URL construction | Manual string concatenation | `mw.util.getUrl()` | Handles wiki path configuration, special characters, etc. |
| i18n messages | Hardcoded strings | `mw.msg()` in JS, `$this->msg()` in PHP | Standard MW i18n; all existing modules use this pattern |
| Permission check | Custom permission logic | `parent::__construct('CreateSemanticPage', 'edit')` | MediaWiki handles permission checks automatically |

**Key insight:** Phase 6 and Phase 7 already built the entire backend. Phase 8 is purely a UI layer that wires existing APIs into a user-facing Special page. Zero new backend logic is needed beyond extending the multi-category API response.

## Common Pitfalls

### Pitfall 1: Multi-Category API Missing Datatype and Namespace Data
**What goes wrong:** The `semanticschemas-multicategory` API currently returns `name`, `title`, `required`, `shared`, and `sources` for each property, but NOT the `datatype`. The CONTEXT.md decision says "Each property shows: name, datatype, and required/optional status." Similarly, the API returns nothing about category target namespaces, but the UI needs this for namespace conflict detection.
**Why it happens:** The Phase 7 API was designed for property resolution, not UI display. Datatype info lives in `PropertyModel` (via `WikiPropertyStore`), and namespace info lives in `CategoryModel::getTargetNamespace()`.
**How to avoid:** Extend `ApiSemanticSchemasMultiCategory` to:
1. Look up `WikiPropertyStore::getAllProperties()` and include `datatype` in each property entry
2. Look up `CategoryModel::getTargetNamespace()` for each resolved category and include in response
**Warning signs:** Preview area shows property names without datatypes; namespace picker never appears.

### Pitfall 2: Hierarchy API Returns "Parents" in Reverse Direction
**What goes wrong:** The hierarchy API uses "parents" to mean "categories that inherit FROM this one" (children in the tree sense), not "categories this one inherits from." This is the SemanticSchemas convention where parent categories are the ones being inherited from (more specific categories list their parents).
**Why it happens:** Category model stores `parents` as "who I inherit from" but the tree node structure in the API inverts this for display: `node.parents` lists the child categories that reference this node as a parent.
**How to avoid:** Test with actual API data. The tree root is the most general category, and `node.parents` lists categories that inherit from it (i.e., child nodes in the tree display).
**Warning signs:** Tree appears inverted or categories show up under wrong parents.

### Pitfall 3: FormEdit Composite Form Must Exist Before Redirect
**What goes wrong:** User clicks Submit, JS redirects to `Special:FormEdit/Employee+Person/NewPage`, but the composite form `Form:Employee+Person` doesn't exist yet, causing a 404 or blank form.
**Why it happens:** Form generation is asynchronous -- the wiki page must be created/saved before the redirect can work.
**How to avoid:** The submit flow must:
1. POST to a server-side endpoint that calls `CompositeFormGenerator::generateAndSaveCompositeForm()`
2. Wait for success response
3. ONLY THEN redirect to FormEdit
**Warning signs:** "Form not found" error on FormEdit page; blank form without fields.

### Pitfall 4: Race Condition on Rapid Category Toggle
**What goes wrong:** User rapidly checks/unchecks categories, triggering multiple API calls. Earlier API responses arrive after later ones, showing stale data.
**Why it happens:** Network latency varies; there is no guarantee responses arrive in order.
**How to avoid:** Use a request counter or AbortController pattern. Each new request increments a counter; when a response arrives, check if its counter matches the current one. If not, discard it. The existing formpreview.js uses only debounce (no abort), which is simpler but leaves a narrow race window.
**Warning signs:** Preview flickers or shows properties from a stale selection.

### Pitfall 5: Page Name Conflicts Across Namespaces
**What goes wrong:** User types "John Smith" but selected categories have different target namespaces. The page could be `John Smith` (main), `Research:John Smith`, or something else. If no namespace resolution UI appears, the form creates the page in an unexpected namespace.
**Why it happens:** Each category can define a `targetNamespace` in its schema. When multiple categories are selected with different target namespaces, the conflict must be surfaced.
**How to avoid:** After each API response, check the returned category namespace data. If namespaces differ across categories, show a namespace picker. If all agree, use that namespace silently.
**Warning signs:** Pages end up in wrong namespace; users confused about where their page was created.

### Pitfall 6: CSRF Token Not Required for Read API but Required for Form Generation
**What goes wrong:** The multi-category API is read-only (`needsToken()` returns false), but the form generation step that saves the composite form wiki page IS a write operation that needs a CSRF token.
**Why it happens:** The preview API is read-only, but form submission triggers a write. Developers may forget the write step needs different handling.
**How to avoid:** For the submission flow, either: (a) use a server-side POST handler in the SpecialPage that generates the form internally (server already has the user session), or (b) use `mw.Api().postWithEditToken()` for the write action.
**Warning signs:** "Bad CSRF token" errors on submit; form not saved to wiki.

## Code Examples

Verified patterns from the existing codebase:

### PHP SpecialPage Skeleton
```php
// Source: Pattern from src/Special/SpecialSemanticSchemas.php
namespace MediaWiki\Extension\SemanticSchemas\Special;

use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;

class SpecialCreateSemanticPage extends SpecialPage {
    public function __construct() {
        parent::__construct('CreateSemanticPage', 'edit');
    }

    public function execute($subPage) {
        $this->setHeaders();
        $this->checkPermissions();
        $output = $this->getOutput();

        if (!defined('SMW_VERSION')) {
            $output->addHTML(Html::errorBox(
                $this->msg('semanticschemas-error-no-smw')->parse()
            ));
            return;
        }
        if (!defined('PF_VERSION')) {
            $output->addHTML(Html::errorBox(
                $this->msg('semanticschemas-error-no-pageforms')->parse()
            ));
            return;
        }

        $output->addModules('ext.semanticschemas.createpage');
        $output->addModuleStyles('ext.semanticschemas.styles');

        // Render HTML skeleton with containers for JS to populate
        $output->addHTML($this->renderLayout());
    }

    protected function getGroupName() {
        return 'wiki';
    }
}
```

### JS Module IIFE Pattern
```javascript
// Source: Pattern from resources/ext.semanticschemas.hierarchy.js (line 12, 476-477)
(function (mw, $) {
    'use strict';

    var UPDATE_DELAY = 300;
    var updateTimer = null;

    // Helper: i18n messages
    var msg = function (name) { return mw.msg(name); };

    // State
    var selectedCategories = {};

    // ... module code ...

    $(function () {
        // Auto-init when DOM ready
        init();
    });
}(mediaWiki, jQuery));
```

### Chip/Tag List for Selected Categories
```javascript
// Source: Claude's Discretion recommendation
function renderChipList($container, categories) {
    $container.empty();
    categories.forEach(function (cat) {
        var $chip = $('<span>')
            .addClass('ss-create-chip')
            .append(
                $('<span>').addClass('ss-create-chip-label').text(cat),
                $('<button>')
                    .addClass('ss-create-chip-remove')
                    .attr({ type: 'button', 'aria-label': msg('semanticschemas-createpage-remove-category') })
                    .text('\u00D7') // multiplication sign
                    .on('click', function () {
                        deselectCategory(cat);
                    })
            );
        $container.append($chip);
    });
}
```

### Page Existence Check via Standard MW API
```javascript
// Source: MediaWiki API documentation (api.php?action=query)
function checkPageExists(pageName, namespace) {
    var fullTitle = namespace ? namespace + ':' + pageName : pageName;
    return new mw.Api().get({
        action: 'query',
        titles: fullTitle,
        format: 'json'
    }).then(function (response) {
        var pages = response.query.pages;
        for (var id in pages) {
            return !pages[id].hasOwnProperty('missing');
        }
        return false;
    });
}
```

### Namespace Conflict Detection
```javascript
// Source: Architecture pattern based on CategoryModel.getTargetNamespace()
function detectNamespaceConflict(apiResponse) {
    var namespaces = {};
    var categories = apiResponse.categories || [];

    categories.forEach(function (cat) {
        if (cat.targetNamespace) {
            namespaces[cat.targetNamespace] = namespaces[cat.targetNamespace] || [];
            namespaces[cat.targetNamespace].push(cat.name);
        }
    });

    var uniqueNamespaces = Object.keys(namespaces);
    // No conflict if 0 or 1 distinct namespace
    if (uniqueNamespaces.length <= 1) {
        return { conflict: false, namespace: uniqueNamespaces[0] || null };
    }
    return { conflict: true, namespaces: namespaces };
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| OOUI for all new MW UI | Codex design system (Vue.js) | 2024 | OOUI is in maintenance mode. Codex is recommended for new Wikimedia development. However, for non-Wikimedia extensions targeting MW 1.39+, vanilla JS + jQuery remains fully supported and is what this codebase uses |
| `$wgSpecialPages` in PHP | `SpecialPages` in extension.json | MW 1.25+ | Registration moved to extension.json; this codebase already uses it |
| Custom AJAX endpoints | `mw.Api` wrapper | Long-standing | All existing modules already use `new mw.Api()` |

**Deprecated/outdated:**
- OOUI: In maintenance mode. Don't adopt for new widgets in this codebase. The existing codebase uses custom CSS with design tokens, which is more appropriate.
- `wfSpecialPage()`: Old registration method. Use extension.json `SpecialPages`.

## Discretion Recommendations

Research-backed recommendations for areas left to Claude's discretion:

### Shared vs Category-Specific Property Distinction
**Recommendation: Group properties by source category with a "Shared" section on top.**
Rationale: The multi-category API already returns a `shared` flag (1/0) and `sources` array per property. Grouping shared properties into a dedicated section at the top of the preview (with a distinct background color, e.g., the accent-50 color) makes it immediately clear which properties appear in multiple categories. Category-specific properties then appear in per-category groups below. This matches the existing `renderPropertiesByCategory` pattern in hierarchy.js.

### Page Name Input Positioning
**Recommendation: Place page name input BELOW the tree and preview area, adjacent to the submit button.**
Rationale: The user workflow is (1) select categories, (2) review properties, (3) name the page, (4) submit. Placing the name input at the bottom follows this natural flow. The submit button should be immediately below the page name input.

### Page Name Clash Behavior
**Recommendation: Warn but allow when page already exists.**
Rationale: Users may legitimately want to edit an existing page through FormEdit. Blocking would prevent this valid use case. Show a warning message (yellow notification) when the page exists: "A page with this name already exists. Submitting will open it for editing." Use the standard `action=query&titles=...` API with debounced checking on the name input.

### Namespace Picker Widget
**Recommendation: Radio buttons when conflict exists; hidden when no conflict.**
Rationale: Namespace conflicts typically involve 2-3 options (not dozens), making radio buttons clearer than a dropdown. When all categories agree on namespace (or none specify one), don't show any picker -- just use the agreed namespace silently. When conflict exists, show radio buttons with category names as context (e.g., "Main namespace (used by: Person)" vs "Research (used by: Scientist)").

### API Error Feedback
**Recommendation: Inline replacement in the preview area.**
Rationale: The existing hierarchy.js and formpreview.js both use inline error replacement (`renderError` function, line 42-43 of hierarchy.js). Toast notifications would introduce a new pattern. Inline errors are contextually clear -- the preview area shows what went wrong.

### Loading State
**Recommendation: Opacity reduction with loading text (existing pattern).**
Rationale: The existing `ss-hierarchy-loading` CSS class uses `opacity: 0.5; pointer-events: none;` (hierarchy.css line 12-15). This is the codebase standard. Add a brief text indicator like the existing "Loading hierarchy..." pattern.

### Overall Styling
**Recommendation: Custom CSS using the existing design system (--ss-* variables).**
Rationale: All existing modules use the project's custom design tokens (slate palette, accent teal, radius tokens). OOUI styling would clash visually. The `ext.semanticschemas.styles.css` file defines all tokens. The new module should import/depend on `ext.semanticschemas.styles` for tokens and add component-specific styles.

## API Enhancement Requirements

The `semanticschemas-multicategory` API must be enhanced to support the UI:

### Current Response Format
```json
{
  "semanticschemas-multicategory": {
    "categories": ["Person", "Employee"],
    "properties": [
      { "name": "Full name", "title": "Property:Full name", "required": 1, "shared": 1, "sources": ["Person", "Employee"] }
    ],
    "subobjects": [
      { "name": "Publication", "title": "Subobject:Publication", "required": 0, "shared": 0, "sources": ["Person"] }
    ]
  }
}
```

### Required Additions
```json
{
  "semanticschemas-multicategory": {
    "categories": [
      { "name": "Person", "targetNamespace": null },
      { "name": "Employee", "targetNamespace": "Staff" }
    ],
    "properties": [
      { "name": "Full name", "title": "Property:Full name", "required": 1, "shared": 1, "sources": ["Person", "Employee"], "datatype": "Text" }
    ],
    "subobjects": [...]
  }
}
```

Changes needed:
1. **`categories`**: Change from array of strings to array of objects with `name` and `targetNamespace`
2. **`properties[].datatype`**: Add datatype field from `WikiPropertyStore` / `PropertyModel::getDatatype()`

Confidence: HIGH -- the data sources exist (`CategoryModel::getTargetNamespace()`, `PropertyModel::getDatatype()` via `WikiPropertyStore`). The API just needs to call them.

## Form Generation Flow

The submission flow requires careful sequencing:

1. **User clicks Submit** (client-side)
2. **JS validates** page name is non-empty and at least one category is selected
3. **JS sends POST** to a server-side handler (new action on the SpecialPage or a new API)
4. **Server calls** `CompositeFormGenerator::generateAndSaveCompositeForm($resolved)` which:
   - Generates form wikitext via `generateCompositeForm()`
   - Gets form name via `getCompositeFormName()` (alphabetical sort + "+" join)
   - Saves to `Form:Category1+Category2` wiki page
5. **Server responds** with the form name
6. **JS redirects** to `Special:FormEdit/<formName>/<namespacedPageName>`

The form name is deterministic: categories sorted alphabetically, joined with `+`. Example: categories "Employee" and "Person" produce form name "Employee+Person".

FormEdit URL format: `Special:FormEdit/Employee+Person/Staff:John_Smith` (if namespace is "Staff") or `Special:FormEdit/Employee+Person/John_Smith` (if no namespace).

## Open Questions

Things that could not be fully resolved:

1. **Server-side form generation endpoint**
   - What we know: `CompositeFormGenerator::generateAndSaveCompositeForm()` exists and works. It needs a `ResolvedPropertySet` which requires `MultiCategoryResolver::resolve()`.
   - What's unclear: Should this be a new API module (`semanticschemas-createpage`) or a POST handler on the SpecialPage itself? Both patterns exist in MediaWiki. API module is more testable; SpecialPage POST is simpler for a single-use action.
   - Recommendation: Use a POST action on the SpecialPage class (parameter `action=create`), which matches how `SpecialSemanticSchemas` handles `action=generate-form` (line 147). This avoids registering a new API module for a single-use action.

2. **Tree data source**
   - What we know: The `semanticschemas-hierarchy` API returns a full tree from a SINGLE root category. For the category selection tree, we need ALL categories.
   - What's unclear: Should we load the full hierarchy from one API call (passing the root category) or fetch all categories differently?
   - Recommendation: Call `semanticschemas-hierarchy` with the root category (which returns the full tree). The root can be determined server-side and embedded as a data attribute. Alternatively, a new lightweight endpoint that returns just the category names + parents structure (without properties) could be added, but the existing endpoint's data is sufficient.

3. **Handling of categories with no target namespace**
   - What we know: `targetNamespace` is nullable. Most categories probably have `null` (main namespace).
   - What's unclear: When some categories have a namespace and others have null, does that count as a "conflict"?
   - Recommendation: Treat null as "main namespace" (NS_MAIN = 0). A conflict exists when distinct non-equal namespace values are present across selected categories. If all categories have null, no conflict. If some have null and some have "Research", that IS a conflict (main vs Research).

## Sources

### Primary (HIGH confidence)
- **Existing codebase** -- `src/Special/SpecialSemanticSchemas.php`, `resources/ext.semanticschemas.hierarchy.js`, `resources/ext.semanticschemas.hierarchy.formpreview.js`, `src/Api/ApiSemanticSchemasMultiCategory.php`, `src/Generator/CompositeFormGenerator.php`, `extension.json` -- all patterns directly from the working codebase
- **CategoryModel** -- `src/Schema/CategoryModel.php` -- `getTargetNamespace()` method confirmed
- **PropertyModel** -- `src/Schema/PropertyModel.php` -- `getDatatype()` method confirmed
- **WikiPropertyStore** -- `src/Store/WikiPropertyStore.php` -- `getAllProperties()` returns PropertyModel with datatypes

### Secondary (MEDIUM confidence)
- [OOUI/Using OOUI in MediaWiki](https://www.mediawiki.org/wiki/OOUI/Using_OOUI_in_MediaWiki) -- Confirmed OOUI is in maintenance mode, Codex is recommended
- [MediaWiki Manual: Special pages](https://www.mediawiki.org/wiki/Manual:Special_pages) -- SpecialPage registration patterns
- [PageForms: Linking to forms](https://www.mediawiki.org/wiki/Extension:Page_Forms/Linking_to_forms) -- FormEdit URL format: `Special:FormEdit/<form>/<page>`
- [PageForms: Special pages](https://www.mediawiki.org/wiki/Extension:Page_Forms/Special_pages) -- FormEdit behavior and parameters
- [MediaWiki API:Query](https://www.mediawiki.org/wiki/API:Query) -- Page existence check via `action=query&titles=...`

### Tertiary (LOW confidence)
- None -- all findings verified against codebase or official documentation.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH -- everything comes from existing codebase patterns and MW core
- Architecture: HIGH -- all patterns are direct adaptations of existing code in this project
- Pitfalls: HIGH -- identified from code analysis (API gaps) and documented MW behaviors
- API enhancement: HIGH -- verified that data sources exist but response format needs extending
- Discretion recommendations: MEDIUM -- based on UX reasoning and codebase consistency, but involve subjective choices

**Research date:** 2026-02-02
**Valid until:** 2026-03-02 (stable -- MW and existing codebase are well-understood)
