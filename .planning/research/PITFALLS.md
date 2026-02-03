# Domain Pitfalls: Multi-Category Page Creation in MediaWiki/SMW/PageForms

**Domain:** MediaWiki extension with Semantic MediaWiki and PageForms integration
**Researched:** 2026-02-02
**Context:** Adding multi-category page creation to existing SemanticSchemas extension
**Confidence:** MEDIUM (WebSearch-based findings with architectural analysis of existing codebase)

## Critical Pitfalls

Mistakes that cause data loss, conflicts, or require significant rework.

---

### Pitfall 1: Property Collision Without Conditional #set

**What goes wrong:**
When multiple categories share a property name (e.g., "Name"), and a page has both categories, BOTH semantic templates will be called. If both templates unconditionally execute `{{#set:Name={{{Name|}}}}}`, the later template assignment overwrites the earlier one. However, PageForms only passes the property value to ONE template (the one where the field appears in the form). The second template receives an empty value and overwrites the valid data with empty string.

**Why it happens:**
- PageForms' composite form shows shared properties only once (in first template's section)
- PageForms passes parameters only to the template section where the field appears
- SMW `#set` processes sequentially during template expansion
- Later assignments overwrite earlier ones (no "last value wins" warning)

**Consequences:**
- Data loss: Valid property values overwritten with empty strings
- Inconsistent data: Which template "wins" depends on dispatcher call order
- Silent failure: No error messages, just missing data
- Debugging nightmare: Appears intermittently based on template order

**Prevention:**
1. **Conditional #set pattern** (REQUIRED for shared properties):
   ```wiki
   {{#if:{{{PropertyName|}}}|{{#set:PropertyName={{{PropertyName|}}}}}|}}
   ```

2. **Apply to semantic templates** (`Template:<Category>/semantic`):
   - Wrap EVERY property assignment in conditional
   - Only store if parameter has a value
   - Template without the form field gets empty param, skips storage

3. **Architecture decision**:
   - Modify TemplateGenerator::generateSemanticTemplate()
   - Add optional mode: conditional vs unconditional #set
   - Default to conditional for multi-category scenarios

**Detection:**
- Test: Create page with 2 categories sharing a property
- Check: Special:Browse/PageName shows property value
- Edit: Change the shared property value
- Re-check: If value disappears, collision occurred

**Phase implications:**
- Phase 1 (Form generation): Must decide which template shows shared fields
- Phase 2 (Template generation): Must generate conditional #set in semantic templates
- Phase 3 (Testing): Must verify no data loss across all category combinations

---

### Pitfall 2: ParserFunctions #if Whitespace Trimming

**What goes wrong:**
MediaWiki's ParserFunctions (#if, #ifeq, #ifexpr, etc.) automatically trim spaces and newlines from the "then" and "else" branches. When using conditional #set with multi-value properties or formatted output, this trimming can:
- Break list separators (commas, semicolons)
- Remove intentional spacing in display templates
- Cause template parameters to concatenate unexpectedly

**Why it happens:**
Built-in behavior of Extension:ParserFunctions. All parser functions with names starting "#if" trim whitespace by design.

**Consequences:**
- Multi-value properties lose their separators: `"Value1,Value2"` becomes `"Value1Value2"`
- Display formatting breaks when using #if for conditional sections
- Template debugging becomes confusing due to invisible whitespace removal

**Prevention:**
1. **For preserving whitespace**:
   - Use `{{#if:condition|<nowiki> </nowiki>then|<nowiki> </nowiki>else}}`
   - Or use wrapper template like `{{If|condition|then|else}}` (meta-template pattern)

2. **For multi-value properties**:
   - Use SMW's `+sep` parameter instead of #if for separator logic
   - Example: `{{#set:Property={{{Param|}}}|+sep=,}}`

3. **For display templates**:
   - Avoid #if for layout decisions if whitespace matters
   - Use CSS classes with conditional class names instead
   - Example: `<div class="{{#if:{{{Param|}}}|has-value|no-value}}">`

**Detection:**
- Property values concatenate without expected separators
- Display template layout collapses or has missing spaces
- Comparing template output to expected format shows whitespace differences

**Phase implications:**
- Phase 2 (Template generation): Document whitespace behavior in comments
- Phase 3 (Testing): Test multi-value properties with conditional #set
- Phase 4 (Display stubs): Avoid #if in display templates where formatting matters

**Sources:**
- [Help:Extension:ParserFunctions - MediaWiki](https://www.mediawiki.org/wiki/Help:Extension:ParserFunctions)
- [Template:If - Meta-Wiki](https://meta.wikimedia.org/wiki/Template:If)

---

### Pitfall 3: PageForms One-Category-Per-Page Philosophy

**What goes wrong:**
PageForms is architecturally designed for one category per page. The documentation explicitly states: "The general Page Forms approach is to only have one category per page, with this category being set by the main template in the page." Multi-category pages conflict with PageForms' assumptions about:
- Form-to-category associations (#default_form)
- "Edit with form" tab detection
- Category-based form autocomplete
- Template-to-category 1:1 mapping

**Why it happens:**
Intentional design decision in PageForms. The extension assumes Wikipedia's approach (many categories as tags) vs structured data approach (one primary type).

**Consequences:**
- #default_form only works for ONE category per page
- "Edit with form" tab may not appear (can't determine which form)
- Multiple category tags in templates break form detection
- Autocomplete suggests wrong forms for multi-category pages

**Prevention:**
1. **DO NOT use multiple [[Category:X]] tags** in generated templates
   - Only the PRIMARY category should have category tag
   - Other categories should be represented as SMW properties only

2. **Use property-based category membership**:
   ```wiki
   Property: "Has Type" = "Person|Employee"
   ```
   Then query: `{{#ask: [[Has Type::Person]] [[Has Type::Employee]] }}`

3. **Pick ONE category as primary** for form association:
   - Primary category gets #default_form and [[Category:X]] tag
   - Secondary categories stored as properties
   - Form shows all properties from both categories

4. **Alternative: Use tree input for hierarchical tagging**:
   - PageForms supports tree input for multi-level category selection
   - Allows "hierarchical tagging" without breaking form detection
   - Field: `{{{field|Categories|input type=tree|top category=Root}}}`

**Detection:**
- "Edit with form" tab missing on multi-category pages
- Special:FormEdit redirects to wrong form
- Category page doesn't show #forminput widget
- Pages appear in category but form doesn't load

**Phase implications:**
- Phase 1 (Architecture): Decide primary vs secondary category distinction
- Phase 2 (Form generation): Only show properties, not multiple category tags
- Phase 3 (Template generation): Only dispatcher calls category tag for primary
- Phase 5 (UI): Category selection widget must indicate primary vs secondary

**Sources:**
- [Extension:Page Forms/Page Forms and templates - MediaWiki](https://www.mediawiki.org/wiki/Extension:Page_Forms/Page_Forms_and_templates)

---

### Pitfall 4: SMW #set Template Parameter Limitations

**What goes wrong:**
SMW's `{{#set:...}}` parser function only allows ONE template parameter per #set invocation. The documentation states: "Only the first template specified needs to be added... a second template added to other properties will be ignored." If you try to use multiple templates in a single #set block, only the first template parameter is honored, and subsequent templates are silently ignored.

**Why it happens:**
Limitation of #set's template parameter parsing. The template parameter applies to the entire #set block, not per-property.

**Consequences:**
- Properties intended for different templates all use first template
- Silent failure: No error, just wrong template association
- Subobject grouping breaks if multiple subobject types in one #set
- Template-scoped queries fail to distinguish property origins

**Prevention:**
1. **Use separate #set calls** for different template associations:
   ```wiki
   {{#set:template=Template:Person
    | Name = {{{Name|}}}
    | BirthDate = {{{BirthDate|}}}
   }}
   {{#set:template=Template:Employee
    | EmployeeID = {{{EmployeeID|}}}
    | Department = {{{Department|}}}
   }}
   ```

2. **In SemanticSchemas context**:
   - Each category's semantic template has own #set block
   - No shared #set between categories
   - Dispatcher calls each semantic template separately

3. **For subobjects**:
   - Use #subobject instead of #set with template parameter
   - Each subobject gets its own #subobject invocation

**Detection:**
- SMW queries with template filter return unexpected results
- Properties appear under wrong template in Special:Browse
- Subobjects don't group correctly

**Phase implications:**
- Phase 2 (Template generation): Verify each semantic template has independent #set
- Phase 3 (Testing): Query properties by template parameter
- Not a blocker: Current architecture already isolates #set per category

**Sources:**
- [Working with the #set "template" parameter - semantic-mediawiki.org](https://www.semantic-mediawiki.org/wiki/Help:Setting_values/Working_with_the_template_parameter)

---

### Pitfall 5: StateManager Hash Conflicts with Multi-Template Pages

**What goes wrong:**
SemanticSchemas uses SHA256 hashes to detect external modifications (StateManager::setPageHashes). When a page includes multiple templates, the page content hash changes even when only ONE category's schema changed. This triggers false-positive "external modification" warnings for ALL categories on that page.

**Why it happens:**
- StateManager tracks page content hash (whole page)
- Multi-category page = multiple templates in one page
- Changing Category A's schema regenerates its template
- Page including both A and B gets updated (A's template changed)
- Page hash changes, triggers dirty flag for Category B (false positive)

**Consequences:**
- False warnings: "Category B modified externally" when only A changed
- Dirty flag cascades across categories sharing pages
- Regeneration triggers unnecessary rewrites
- StateManager loses ability to detect REAL external edits

**Prevention:**
1. **Template-level hashing** instead of page-level:
   - Track hash of EACH template independently
   - Compare template content, not final page content
   - Only flag dirty if template was modified outside SemanticSchemas

2. **StateManager architecture change**:
   ```php
   // Current: pageHashes['Page'] = hash(page content)
   // Needed: templateHashes['Template:Person/semantic'] = hash(template content)
   ```

3. **Dirty detection for multi-category scenario**:
   - Check ALL templates used by a category
   - Flag dirty only if ANY template has mismatched hash
   - Don't check pages, check source templates

**Detection:**
- Import category A schema
- Edit page with categories A and B manually
- Check dirty status: Category B shows dirty (false positive)
- Check state JSON: pageHashes shows changed hash for multi-category page

**Phase implications:**
- Phase 4 (State tracking): Refactor StateManager for template-level hashing
- Phase 5 (Dirty detection): Update isDirty() logic for multi-template pages
- High priority: Without this, dirty detection becomes unreliable

**Architectural impact:**
- Requires StateManager.php modifications
- Changes state JSON structure (migration needed?)
- Affects all import/export operations

---

## Moderate Pitfalls

Mistakes that cause delays, technical debt, or UX issues but are fixable.

---

### Pitfall 6: FormEdit URL Parameter Complexity

**What goes wrong:**
Special:FormEdit requires both form name AND target page in URL: `Special:FormEdit?form=<form>&target=<page>`. For multi-category pages, determining WHICH form to use becomes ambiguous:
- Page exists with Category A form
- User wants to add Category B
- Which form to show? A? B? Composite?
- Wrong form = missing fields

**Why it happens:**
PageForms assumes one form per page. Multi-category scenario isn't directly supported.

**Consequences:**
- "Edit with form" tab loads wrong form
- Users see incomplete field set
- No UI to select "add another category to this page"
- Form links in category pages may be incorrect

**Prevention:**
1. **Generate composite forms** for known category combinations:
   - Form:Person+Employee (includes both category templates)
   - Use `{{{for template|Person}}}...{{{end template}}}{{{for template|Employee}}}...{{{end template}}}`

2. **Create form selection UI**:
   - Special page: "Add Category to Page"
   - Dropdown: Select which category to add
   - Button: Opens correct form with ?form=X&target=PageName

3. **Form naming convention**:
   - Primary form: "Person" (matches category)
   - Composite form: "Person+Employee" (alphabetical order)
   - Page property: "Available forms" = ["Person", "Person+Employee"]

4. **#default_form strategy**:
   - Set to PRIMARY category's form only
   - Don't set #default_form for composite pages
   - Require explicit form selection via UI

**Detection:**
- Create Person page
- Try to edit and add Employee category
- Check if form shows Employee fields
- If not, FormEdit loaded wrong form

**Phase implications:**
- Phase 1 (Form generation): Decide composite form strategy
- Phase 3 (UI): Build form selection interface
- Phase 4 (Testing): Test all form combinations

---

### Pitfall 7: JavaScript Tree Widget State Persistence

**What goes wrong:**
PageForms tree input type uses JavaScript library (Fancytree or jsTree) for hierarchical checkbox selection. After page refresh or form re-render, checkbox states may not persist:
- User selects categories in tree
- Form validation fails (other field empty)
- Page reloads form with error message
- Tree widget resets, selections lost

**Why it happens:**
- Tree widget state stored in JavaScript, not form input
- PageForms may not correctly serialize tree state to hidden input
- Browser back button doesn't restore JS widget state
- Form preview feature doesn't preserve tree selections

**Consequences:**
- Poor UX: Users must re-select categories after validation errors
- Data loss: Partial form completion lost on refresh
- Frustration: Multi-level tree requires many clicks to restore
- Bug reports: "Form keeps clearing my selections"

**Prevention:**
1. **Test tree input persistence extensively**:
   - Select items, trigger validation error, check if selected
   - Use browser back button, check selection state
   - Use form preview, check if tree maintains state

2. **Alternative input types for category selection**:
   - `input type=tokens` (comma-separated, better persistence)
   - `input type=checkboxes` (flat list, guaranteed persistence)
   - `input type=listbox|size=10` (multi-select dropdown)

3. **JavaScript debugging**:
   - Check PageForms version (tree library changed in 2020+)
   - Inspect hidden input value (should contain selections)
   - Test with PageForms latest version (bug fixes)

4. **Workaround: Session storage**:
   - Add custom JS to save tree state to localStorage
   - Restore state on page load
   - Clear on successful form submission

**Detection:**
- Fill form partially, submit with validation error
- Check if tree widget selections preserved
- Test across browsers (Chrome, Firefox, Safari)

**Phase implications:**
- Phase 3 (Form inputs): Choose tree vs alternative input type
- Phase 5 (Testing): Extensive testing of form validation + refresh
- Phase 6 (JS customization): May need custom persistence layer

**Sources:**
- [Extension:Page Forms/Input types - MediaWiki](https://www.mediawiki.org/wiki/Extension:Page_Forms/Input_types)
- Discussion: Tree input library changed from Fancytree to jsTree (2020)

---

### Pitfall 8: Performance Degradation with Multiple Template Calls

**What goes wrong:**
PageForms performs poorly when pages have many template invocations. Users report "blank page" when editing forms with 300+ template calls, "Maximum execution time of 30 seconds exceeded" errors, and 20+ second page loads even after reducing templates.

**Why it happens:**
- PageForms parses entire page to populate form
- Each template invocation = parser expansion + DB queries
- Multi-category pages = more templates per page
- SMW properties in templates = additional DB queries
- Subobjects multiply the template count

**Consequences:**
- FormEdit timeouts for complex pages
- Slow form loading (poor UX)
- Server resource exhaustion
- Need to increase PHP memory/execution limits

**Prevention:**
1. **Minimize template invocations**:
   - Avoid deep template nesting
   - Limit subobjects per page (consider pagination)
   - Use #set instead of inline annotations where possible

2. **PHP configuration tuning**:
   ```php
   max_execution_time = 60  // Increase from 30
   memory_limit = 256M      // Increase from 128M
   post_max_size = 32M      // For large form submissions
   ```

3. **Caching strategies**:
   - Enable MediaWiki parser cache
   - Use object caching (Redis/Memcached)
   - PageForms form definition cache (automatic)

4. **Architecture decisions**:
   - Limit maximum categories per page (e.g., 5)
   - Limit subobject instances (e.g., 50 max)
   - Show warning when approaching limits

5. **Alternative for large datasets**:
   - Use SMW #ask queries instead of storing all data on one page
   - Split data across multiple pages (e.g., Person page + Employment history page)
   - Use subobject pagination

**Detection:**
- Monitor FormEdit page load times
- Check PHP error logs for timeout errors
- Test with pages having 10+ categories
- Measure parser time: ?action=purge&debug=1

**Phase implications:**
- Phase 1 (Architecture): Define maximum categories per page
- Phase 3 (Testing): Performance testing with complex pages
- Phase 4 (Limits): Implement UI warnings for complexity limits
- Phase 6 (Documentation): Document performance characteristics

**Sources:**
- [Extension:Page Forms/Common problems - MediaWiki](https://www.mediawiki.org/wiki/Extension:Page_Forms/Common_problems)
- Community reports: Blank page with 300+ templates, 30 second timeout

---

### Pitfall 9: Multiple-Instance Template Duplication Bug

**What goes wrong:**
PageForms has a known bug where "the entire changed block of a multiple-instance template field gets duplicated - for example, adding just one item to a blank field containing a multiple results in the same field being duplicated." This particularly affects subobjects in multi-category forms.

**Why it happens:**
Bug in PageForms' JavaScript handling of multiple-instance template blocks. The "Add another" button duplicates more than intended.

**Consequences:**
- Users click "Add another" for subobject
- TWO instances appear instead of one
- Data gets duplicated on save
- Subobject tables show duplicate rows
- Users must manually delete unwanted instances

**Prevention:**
1. **Test PageForms version**:
   - Check if bug exists in current version
   - Update to latest PageForms release
   - Check Phabricator for bug reports/fixes

2. **Workaround: Custom JavaScript**:
   - Hook into PageForms' "add instance" event
   - Debounce/throttle clicks on "Add another" button
   - Validate instance count before adding

3. **UI guidance**:
   - Warn users about duplication in form instructions
   - Add "Remove" buttons prominently
   - Show instance count: "3 entries"

4. **Testing protocol**:
   - Test "Add another" button in all browsers
   - Test rapid clicking (stress test)
   - Test with nested multiple-instance templates
   - Test with validation errors (re-render scenario)

**Detection:**
- Open form with multiple-instance template
- Click "Add another" button
- Count how many instances appear (should be 1, might be 2+)
- Save and check page content

**Phase implications:**
- Phase 2 (Form generation): Avoid nested multiple-instance templates
- Phase 5 (Testing): Test subobject duplication extensively
- Phase 6 (JS): May need custom JavaScript to prevent duplication

**Sources:**
- [Extension:Page Forms/Common problems - MediaWiki](https://www.mediawiki.org/wiki/Extension:Page_Forms/Common_problems)
- Community reports: Duplication bug with multiple-instance templates

---

## Minor Pitfalls

Mistakes that cause annoyance but are easily fixable.

---

### Pitfall 10: Property Parameter Name Collisions

**What goes wrong:**
Template parameters are case-sensitive, but SMW property names are case-insensitive. NamingHelper::propertyToParameter() converts property names to parameters, but doesn't handle collisions:
- Property: "Has Type" → Parameter: "Has_Type" (current logic)
- Property: "Has_Type" → Parameter: "Has_Type" (collision!)
- Two properties map to same parameter name

**Why it happens:**
Parameter naming convention doesn't account for multiple properties with similar names differing only in punctuation/spacing.

**Consequences:**
- Second property overwrites first in template parameter passing
- Form shows only one field for two properties
- Data loss for one property
- Debugging confusion

**Prevention:**
1. **Schema validation**: Disallow property names that would collide after parameter conversion
   ```php
   // In SchemaValidator
   $paramNames = [];
   foreach ($properties as $prop) {
       $param = NamingHelper::propertyToParameter($prop);
       if (isset($paramNames[$param])) {
           $errors[] = "Property collision: '$prop' and '{$paramNames[$param]}' both map to parameter '$param'";
       }
       $paramNames[$param] = $prop;
   }
   ```

2. **Naming conventions**: Document that property names should be unique after parameter conversion
   - Avoid: "Has Type" and "Has_Type" in same schema
   - Use: "HasType" and "HasTypeAlternate"

**Detection:**
- Form shows fewer fields than expected
- Property value doesn't save
- Check template source: same parameter name appears twice

**Phase implications:**
- Phase 1 (Validation): Add parameter collision check to SchemaValidator
- Low priority: Rare edge case, easy to fix in schema

---

### Pitfall 11: Inline Annotation Limitations with #subobject

**What goes wrong:**
TemplateGenerator uses inline annotations (`[[Property::Namespace:Value]]`) for multi-value Page properties with namespace prefixes. However, the code comments note: "For subobjects, skip multi-value Page properties (null return) as inline annotations don't work with #subobject."

**Why it happens:**
SMW's #subobject doesn't process inline annotations in the same way as page-level properties. Inline annotations create properties on the page, not on the subobject.

**Consequences:**
- Multi-value Page properties in subobjects don't get namespace prefix
- Values stored without namespace may not link correctly
- SMW queries fail to find namespace-qualified values
- Inconsistent behavior between category properties and subobject properties

**Prevention:**
1. **Document limitation** in schema docs:
   - Subobject properties should not be multi-value Page type with namespace
   - Or, accept that namespace prefix won't be applied

2. **Validation warning**:
   ```php
   // In SchemaValidator for subobjects
   if ($prop->isPageType() && $prop->allowsMultipleValues() && $prop->getAllowedNamespace()) {
       $warnings[] = "Subobject property '{$prop->getName()}' is multi-value Page with namespace - namespace prefix will not be applied";
   }
   ```

3. **Alternative approach**:
   - Use SMW's array map within #subobject (if supported)
   - Or, limit multi-value Page properties to category level only

**Detection:**
- Subobject has multi-value Page property
- Values saved without namespace prefix
- Queries expecting namespace-qualified values return nothing

**Phase implications:**
- Phase 1 (Validation): Add warning for this combination
- Phase 4 (Documentation): Document subobject property limitations
- Low impact: Edge case, workarounds available

---

### Pitfall 12: #default_form Ambiguity

**What goes wrong:**
PageForms' `{{#default_form:CategoryName}}` tag associates a form with a category. For multi-category pages, multiple #default_form tags might appear (one per category template). PageForms only honors ONE #default_form per page, leading to ambiguity about which form the "Edit with form" tab uses.

**Why it happens:**
Each category's dispatcher template includes `{{#default_form:<Category>}}`. Multi-category page includes multiple dispatcher templates, each with #default_form.

**Consequences:**
- Unpredictable form selection for "Edit with form" tab
- May load wrong form (missing fields for some categories)
- User confusion about which form is active
- Form switching requires URL manipulation

**Prevention:**
1. **Only primary category sets #default_form**:
   - Modify dispatcher template generation
   - Add parameter: `|isPrimary=true` to dispatcher
   - Only output #default_form if isPrimary

2. **Remove #default_form from all dispatcher templates**:
   - Rely on explicit form selection via UI
   - Remove from TemplateGenerator::generateDispatcherTemplate()
   - Build form selection special page

3. **Use composite forms**:
   - Generate Form:Person+Employee with both templates
   - Page uses composite form as default
   - #default_form:Person+Employee

**Detection:**
- Create page with 2 categories
- Check "Edit with form" tab target
- Verify which form loads (check URL)
- Manually test if correct fields appear

**Phase implications:**
- Phase 2 (Template generation): Decide #default_form strategy
- Phase 3 (Forms): Implement chosen approach
- Medium impact: Affects form UX significantly

---

## Phase-Specific Warnings

| Phase Topic | Likely Pitfall | Mitigation Strategy |
|-------------|---------------|---------------------|
| **Phase 1: Schema Design** | Property naming collisions after parameter conversion | Add validation: Check NamingHelper::propertyToParameter() doesn't create duplicates |
| **Phase 2: Template Generation** | Unconditional #set overwrites shared properties | Implement conditional #set mode: `{{#if:{{{Param\|}}}}\|...store...\|}}` |
| **Phase 3: Form Generation** | Multiple {{{for template}}} blocks but PageForms expects one category | Choose primary category for [[Category:X]] tag; others are properties only |
| **Phase 4: Composite Forms** | FormEdit can't determine which form to load | Build form selection UI or generate composite forms for common combinations |
| **Phase 5: State Tracking** | StateManager page-level hashing causes false positives | Refactor to template-level hashing; track template content, not page content |
| **Phase 6: JavaScript UI** | Tree widget doesn't persist state after validation error | Test extensively; consider alternative input types (tokens, checkboxes) |
| **Phase 7: Performance Testing** | FormEdit times out with many categories/subobjects | Set maximum limits (5 categories, 50 subobjects); implement warnings |
| **Phase 8: Integration Testing** | Property values disappear after edit | Test every category combination; verify conditional #set works correctly |

---

## Integration Pitfalls with Existing System

Specific risks when adding multi-category to the current SemanticSchemas architecture.

---

### Pitfall 13: Three-Template System Complexity Multiplier

**What goes wrong:**
SemanticSchemas' three-template system (dispatcher/semantic/display) is designed for one category per page. Multi-category pages multiply complexity:
- Page with 2 categories = 6 template calls (3 per category)
- Each dispatcher coordinates 2 other templates
- Template call order matters for property collisions
- Display template coordination becomes complex

**Why it happens:**
Architecture assumes linear dispatcher → semantic → display flow per category.

**Consequences:**
- Template parsing overhead increases linearly with categories
- Dispatcher templates need coordination logic (which displays what?)
- Display stubs may duplicate output (both show same property)
- Debugging requires tracing through 6+ template calls

**Prevention:**
1. **Shared display template** for common properties:
   - Property appears in both categories? Show once.
   - Create logic to detect "already displayed"
   - Use parser variables: `{{#vardefine:shown_Name|true}}`

2. **Dispatcher coordination**:
   - First dispatcher shows all properties
   - Second dispatcher skips shared properties
   - Or, generate "composite dispatcher" for multi-category pages

3. **Limit display duplication**:
   - Display templates check "already rendered" flag
   - User-editable display stubs warn about multi-category coordination

**Detection:**
- Create Person+Employee page
- Check rendered output: duplicated property displays?
- Count template calls: purge?action=purge&debug=1
- Performance: measure render time

**Phase implications:**
- Phase 2 (Template generation): Design dispatcher coordination
- Phase 3 (Display logic): Implement deduplication
- High complexity: May require architecture changes

---

### Pitfall 14: InheritanceResolver with Multiple Category Roots

**What goes wrong:**
SemanticSchemas uses InheritanceResolver with C3 linearization for single-inheritance hierarchies. Multi-category pages introduce multiple inheritance:
- Person inherits from Entity
- Employee inherits from Role
- Person+Employee page has two root categories
- C3 linearization algorithm may produce inconsistent MRO

**Why it happens:**
C3 linearization designed for single inheritance tree. Multiple roots = ambiguous resolution order.

**Consequences:**
- Property resolution order unpredictable
- Inherited properties may conflict
- MRO depends on category declaration order
- Schema validation may not catch conflicts

**Prevention:**
1. **Validate against multiple inheritance**:
   - Check if selected categories share any ancestors
   - Warn if inheritance hierarchies overlap
   - Disallow conflicting inheritance paths

2. **Explicit property override resolution**:
   - User specifies which category's version wins
   - Schema syntax: `"propertyOverrides": {"Name": "Person"}`
   - Form generation uses specified category's property definition

3. **Flat category model for multi-category**:
   - Disable inheritance when multiple categories selected
   - Use properties directly without inherited resolution
   - Simpler but loses inheritance benefits

**Detection:**
- Select categories with different inheritance chains
- Check property list: conflicts? duplicates?
- Verify MRO consistency across operations

**Phase implications:**
- Phase 1 (Architecture): Decide inheritance strategy for multi-category
- Phase 2 (Validation): Implement conflict detection
- High complexity: May require InheritanceResolver modifications

---

### Pitfall 15: StateManager Dirty Flag Cascades

**What goes wrong:**
When category A's schema changes, all pages using A get regenerated. If those pages ALSO have category B, B's dirty flag triggers even though B's schema didn't change. This cascades: regenerating B triggers C, and so on.

**Why it happens:**
StateManager checks page content hashes. Multi-category pages have shared fate: changing one category affects all categories on that page.

**Consequences:**
- Single schema change triggers full system regeneration
- Dirty flags become meaningless (everything always dirty)
- Performance: regeneration takes much longer
- False positives prevent detecting real external edits

**Prevention:**
1. **Template-level dirty tracking** (see Pitfall 5):
   - Track template hashes, not page hashes
   - Only flag category dirty if ITS templates changed

2. **Dependency graph tracking**:
   - Track which categories appear together on pages
   - When A changes, mark A dirty (not B)
   - Regenerate only A's artifacts

3. **Smarter dirty detection**:
   - Compare schema hash, not page hash
   - StateManager::sourceSchemaHash per category
   - Only regenerate if source schema changed

**Detection:**
- Import category A
- Check StateManager: which categories now dirty?
- Should be: only A
- Bug if: A and all categories on same pages as A

**Phase implications:**
- Phase 4 (State tracking): Critical to fix before launch
- Phase 5 (Testing): Test dirty detection with multi-category
- Blocks: Without fix, dirty detection unreliable

---

## Summary: Critical Path for Multi-Category Success

**Must address before launch:**
1. Conditional #set for shared properties (Pitfall 1)
2. StateManager template-level hashing (Pitfall 5)
3. One primary category for #default_form (Pitfall 3)
4. FormEdit form selection strategy (Pitfall 6)

**Should address during development:**
5. ParserFunctions whitespace handling (Pitfall 2)
6. Performance limits and warnings (Pitfall 8)
7. Property parameter collision validation (Pitfall 10)
8. Inheritance resolver multi-root strategy (Pitfall 14)

**Can defer to post-MVP:**
9. Tree widget state persistence (Pitfall 7)
10. Multiple-instance template duplication (Pitfall 9)
11. Inline annotation subobject limitations (Pitfall 11)
12. Display template deduplication (Pitfall 13)

---

## Research Confidence Assessment

| Pitfall | Confidence | Basis |
|---------|-----------|-------|
| 1-4 | MEDIUM | WebSearch findings + MediaWiki/SMW/PageForms documentation patterns |
| 5 | HIGH | Direct codebase analysis (StateManager.php, TemplateGenerator.php) |
| 6-9 | MEDIUM | WebSearch findings + PageForms community reports |
| 10-12 | HIGH | Direct codebase analysis (NamingHelper, TemplateGenerator) |
| 13-15 | HIGH | Architectural analysis of existing SemanticSchemas patterns |

**Overall confidence: MEDIUM-HIGH**
- HIGH for pitfalls derived from existing codebase analysis
- MEDIUM for pitfalls based on WebSearch findings (need official doc verification)
- All pitfalls tested against SemanticSchemas architecture for relevance

---

## Sources

**Official Documentation:**
- [Extension:Page Forms/Page Forms and templates - MediaWiki](https://www.mediawiki.org/wiki/Extension:Page_Forms/Page_Forms_and_templates)
- [Extension:Page Forms/Defining forms - MediaWiki](https://www.mediawiki.org/wiki/Extension:Page_Forms/Defining_forms)
- [Extension:Page Forms/Common problems - MediaWiki](https://www.mediawiki.org/wiki/Extension:Page_Forms/Common_problems)
- [Extension:Page Forms/Input types - MediaWiki](https://www.mediawiki.org/wiki/Extension:Page_Forms/Input_types)
- [Help:Extension:ParserFunctions - MediaWiki](https://www.mediawiki.org/wiki/Help:Extension:ParserFunctions)
- [Help:Setting values using #set - semantic-mediawiki.org](https://www.semantic-mediawiki.org/wiki/Help:Setting_values)
- [Working with the #set "template" parameter - semantic-mediawiki.org](https://www.semantic-mediawiki.org/wiki/Help:Setting_values/Working_with_the_template_parameter)

**Codebase Analysis:**
- `/home/daharoni/dev/SemanticSchemas/src/Generator/TemplateGenerator.php`
- `/home/daharoni/dev/SemanticSchemas/src/Generator/FormGenerator.php`
- `/home/daharoni/dev/SemanticSchemas/src/Store/StateManager.php`

**Community Reports:**
- PageForms performance issues with 300+ templates (WebSearch findings)
- Tree input JavaScript library compatibility discussions (2020+)
- Multiple-instance template duplication bug reports
