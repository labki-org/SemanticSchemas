# Feature Landscape: Multi-Category Page Creation

**Domain:** Wiki ontology management / Schema-driven page creation
**Researched:** 2026-02-02
**Confidence:** MEDIUM (WebSearch verified with official PageForms patterns, C3 linearization established pattern)

## Executive Summary

Multi-category page creation allows users to create wiki pages that simultaneously belong to multiple categories (e.g., a page that is both "Person" and "Employee"). This is a fundamental feature in ontology management systems where instances frequently have multiple types. The core challenge is property deduplication—when two categories share properties like "Name," the form should show the field only once while ensuring both templates receive the data.

PageForms supports this through multiple `{{{for template}}}` blocks in a single form. The key differentiator for SemanticSchemas is leveraging the existing C3 linearization infrastructure to intelligently determine property precedence and generate a clean, deduplicated form experience.

---

## Table Stakes

Features users expect in multi-category page creation. Missing any of these makes the feature feel incomplete.

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| **Category multi-select UI** | Standard pattern for selecting multiple items | Low | Checkboxes or multi-select dropdown for category selection |
| **Property deduplication** | Users expect "Name" field once, not twice | Medium | When Property appears in multiple categories, show once in generated form |
| **Form preview before creation** | Users want to see what fields will appear | Low | PageForms includes built-in preview via `<div id="wikiPreview">` tag |
| **All categories assigned on save** | Page must be member of all selected categories | Low | Generate `[[Category:X]]` wikilink for each selected category |
| **Required field validation** | Prevent submission with missing required fields | Low | PageForms `mandatory=true` parameter handles this |
| **Cancel/back button** | Allow aborting multi-category page creation | Low | Standard form pattern, PageForms includes cancel button |
| **Property ordering preservation** | Fields appear in predictable order | Medium | Use C3 linearization order or explicit section definitions |
| **Save and preview buttons** | Standard wiki editing workflow | Low | PageForms standard inputs already include these |

### Implementation Notes

**Property Deduplication Algorithm:**
```
For each selected category in C3 linearization order:
  For each property in category:
    If property NOT already in form:
      Add field to form under this category's template section
    Else:
      Skip (already shown in earlier template)
```

**Category Assignment:**
The generated form should include hidden or auto-populated category membership. MediaWiki standard: `[[Category:CategoryName]]` wikilinks in page content.

---

## Differentiators

Features that set SemanticSchemas apart from manual wiki editing or other schema systems.

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| **Automatic precedence via C3** | No manual conflict resolution needed | Medium | Leverage existing C3 linearization to determine property precedence across categories |
| **Visual category hierarchy preview** | Users see inheritance relationships before creating | Medium | Extend existing hierarchy visualization to show selected categories and their ancestors |
| **Smart property type conflict detection** | Warn if same property has different datatypes | Medium | "Name" is Text in Person but Page in Employee—flag this |
| **Template section collapse/expand** | Large multi-category forms become manageable | Low | Accordion UI for each category's properties section |
| **Quick-create common combinations** | "Create Person+Employee" as one-click action | Low | Preset multi-category selections for common patterns |
| **Dry-run validation** | Check all constraints before page creation | High | Validate required properties, type constraints, parent category requirements |
| **Property source attribution** | Show which category each property comes from | Low | Help text: "Name (from Person)" in form field labels |
| **Inheritance chain display** | Show full property lineage | Medium | "Email → Contact Info → Person" for inherited properties |
| **Conditional property visibility** | Show/hide properties based on category selection | High | If "Employee" selected, show "Employee ID"; if deselected, hide it |
| **Bulk category assignment** | Create multiple pages with same categories | High | Out of scope for MVP; defer to future |

### Implementation Recommendations

**High-Value, Low-Complexity (implement first):**
1. **Automatic precedence via C3** - Already have InheritanceResolver, extend to multi-category context
2. **Property source attribution** - Add annotation in FormGenerator field descriptions
3. **Quick-create common combinations** - Store presets in extension config or user preferences
4. **Template section collapse/expand** - Add CSS/JS to generated forms

**High-Value, Medium-Complexity (implement after MVP):**
1. **Visual category hierarchy preview** - Extend existing hierarchy visualization API
2. **Smart property type conflict detection** - Run during form generation, show warnings
3. **Inheritance chain display** - Query InheritanceResolver for full lineage

**Lower Priority (defer to post-V1):**
1. **Conditional property visibility** - Requires JavaScript form state management
2. **Dry-run validation** - Complex validation engine needed
3. **Bulk category assignment** - Different feature scope entirely

---

## Anti-Features

Features to explicitly NOT build. Common mistakes in multi-category page creation.

| Anti-Feature | Why Avoid | What to Do Instead |
|--------------|-----------|-------------------|
| **Allow duplicate property entries** | Creates confusion and data inconsistency | Enforce deduplication—show property once, bind to first template |
| **No category order/precedence** | Arbitrary property precedence frustrates users | Use C3 linearization for deterministic ordering |
| **Freeform category entry** | Creates typos, orphaned categories | Restrict to existing categories via dropdown/autocomplete |
| **Allow conflicting property types** | "Name" as Text in one category, Page in another breaks semantics | Detect conflicts during form generation, refuse to generate form with conflicts |
| **Hide which category contributes which property** | Users lose understanding of schema structure | Show property source attribution inline |
| **Merge all properties into single flat list** | Loses semantic meaning of category boundaries | Use `{{{for template}}}` sections to maintain category identity |
| **Allow circular category dependencies** | Person → Employee → Person breaks C3 linearization | Validate category DAG before allowing multi-category selection |
| **Auto-select all parent categories** | Overly prescriptive; user may want Person but not Contact | Let user explicitly select categories; show parents for context only |
| **Generate single merged template** | Destroys semantic separation of categories | Use multiple `{{{for template}}}` blocks—one per category |
| **Allow property value conflicts** | If property already has value from Category A, don't let Category B overwrite | Use `#if` in semantic templates to prevent empty overwrites |

### Key Architectural Decisions

**DO:** Generate multi-template forms with distinct `{{{for template|CategoryA}}}` and `{{{for template|CategoryB}}}` sections.

**DO NOT:** Merge schemas into single composite template. This destroys traceability and makes schema updates impossible.

**DO:** Use C3 linearization order to determine which template gets which property.

**DO NOT:** Allow user to manually reorder templates—this breaks deterministic behavior.

---

## Feature Dependencies

```
Category Multi-Select UI
  ↓
Category DAG Validation (no cycles)
  ↓
C3 Linearization Resolution
  ↓
Property Deduplication Algorithm
  ↓
Multi-Template Form Generation
  ↓
Page Creation with Multiple Category Memberships
```

**Critical Path:**
1. User selects multiple categories
2. System validates categories form valid DAG
3. System resolves property precedence via C3 linearization
4. System generates form with deduplicated properties
5. User fills form and submits
6. System creates page with all category memberships

**Existing Infrastructure:**
- **InheritanceResolver** - Already implements C3 linearization for single-category inheritance
- **FormGenerator** - Already generates PageForms markup
- **CategoryModel** - Already tracks properties (required/optional)
- **PropertyModel** - Already defines datatypes and constraints

**New Components Needed:**
- **MultiCategoryFormGenerator** - Extends FormGenerator to handle multiple categories
- **PropertyDeduplicator** - Tracks which properties already added to form
- **CategorySelectionUI** - UI component for selecting multiple categories
- **ConflictDetector** - Validates property type consistency across categories

---

## MVP Recommendation

For MVP, prioritize core functionality over advanced features.

### Phase 1: Core Multi-Category Page Creation

**Include:**
1. Category multi-select UI (checkboxes on Special:SemanticSchemas)
2. Property deduplication (show each property once)
3. Multi-template form generation (`{{{for template}}}` blocks)
4. C3-based property precedence
5. Page creation with multiple category memberships
6. Form preview (leverage PageForms built-in)
7. Required field validation (leverage PageForms)
8. Basic error messaging (conflict detection)

**Defer to Post-MVP:**
- Visual category hierarchy preview (nice-to-have, not blocking)
- Property source attribution (helpful but not critical)
- Template section collapse/expand (UX enhancement)
- Quick-create presets (convenience feature)
- Smart property type conflict detection with detailed diagnostics
- Inheritance chain display
- Conditional property visibility
- Dry-run validation beyond basic checks

### MVP Success Criteria

A user can:
1. Select multiple categories from existing schema
2. See a generated form with all properties (deduplicated)
3. Fill out the form with no duplicate fields
4. Preview the page before saving
5. Create the page with all category memberships correctly assigned
6. Edit the page later using the same multi-category form

### Post-MVP Enhancements

**Phase 2: Enhanced UX**
- Property source attribution ("Name (from Person)")
- Template section collapse/expand
- Quick-create presets for common combinations
- Visual hierarchy preview

**Phase 3: Advanced Validation**
- Property type conflict detection with warnings
- Inheritance chain display
- Dry-run validation engine

**Phase 4: Dynamic Forms**
- Conditional property visibility based on category selection
- Real-time form regeneration when categories change

---

## Technical Patterns from Ecosystem

### PageForms Multiple Template Pattern

**Standard PageForms syntax for multiple templates:**

```wikitext
{{{for template|Person}}}
{{{field|Name|property=Name}}}
{{{field|Email|property=Email}}}
{{{end template}}}

{{{for template|Employee}}}
{{{field|Employee ID|property=Employee ID}}}
{{{field|Department|property=Department}}}
{{{end template}}}
```

**Key insight:** PageForms natively supports multiple templates. Each template creates its own template call on the page.

**Source:** [Extension:Page Forms/Page Forms and templates - MediaWiki](https://www.mediawiki.org/wiki/Extension:Page_Forms/Page_Forms_and_templates)

### Property Deduplication Strategy

**Problem:** If both Person and Employee have "Name" property, naive approach creates two Name fields.

**Solution:** Track which properties already added to form. When processing second category, skip properties already shown.

**Implementation:**
```php
$seenProperties = [];
foreach ($categories as $category) {
    foreach ($category->getAllProperties() as $property) {
        if (!in_array($property, $seenProperties)) {
            $formFields[] = generateField($property);
            $seenProperties[] = $property;
        }
    }
}
```

**Semantic Template Consideration:** Use conditional `#set` to prevent empty overwrites.

```wikitext
{{#if: {{{Name|}}} | {{#set:Name={{{Name}}} }} }}
```

This ensures that if Person template already set "Name," Employee template won't overwrite with empty value.

**Source:** Inferred from [Extension:Page Forms/Defining forms](https://www.mediawiki.org/wiki/Extension:Page_Forms/Defining_forms) and Semantic MediaWiki `#set` documentation.

### Form Preview Pattern

**PageForms built-in preview:** Forms include `<div id="wikiPreview">` element that renders preview inline without navigation.

**Standard form inputs include:**
- Save button
- Preview button
- Show changes button
- Cancel button

**Implementation:** FormGenerator already includes these via `{{{standard input|preview}}}`.

**Source:** [Extension:Page Forms/Defining forms - MediaWiki](https://www.mediawiki.org/wiki/Extension:Page_Forms/Defining_forms) (WebSearch verification, LOW confidence—unable to fetch full docs)

### C3 Linearization for Property Precedence

**Why C3:** SemanticSchemas already uses C3 linearization (via InheritanceResolver) for single-category inheritance. Extend this to multi-category context.

**C3 guarantees:**
1. **Monotonicity** - Superclass order preserved in subclass
2. **Consistency** - Same order regardless of traversal path
3. **Local precedence** - Direct parents take precedence over indirect

**Application to multi-category forms:**
- Treat selected categories as "parents" of the new page
- Run C3 linearization to get deterministic category order
- Process categories in C3 order for property deduplication

**Source:** [C3 linearization - Wikipedia](https://en.m.wikipedia.org/wiki/C3_linearization), [Understanding C3 Linearization - Medium](https://medium.com/@mehmetyaman/understanding-c3-linearization-the-algorithm-behind-python-and-soliditys-method-resolution-order-f386b9a10696)

### Category Selection UI Patterns

**Common patterns in wiki/ontology systems:**
- **Checkboxes** - Simple, allows multiple selection, clear visual state
- **Multi-select dropdown** - Compact, searchable, good for large category lists
- **Autocomplete tags** - Flexible, discoverable, good UX for power users
- **Hierarchical tree** - Shows category relationships, allows parent/child selection

**Recommendation for SemanticSchemas:** Start with checkboxes (simple), upgrade to autocomplete tags post-MVP.

**Source:** [Categorization design pattern](https://ui-patterns.com/patterns/categorization), [FormWizard - Meta-Wiki](https://meta.wikimedia.org/wiki/Meta:FormWizard)

### Validation Patterns

**Client-side validation best practices (2026):**
- **Inline/real-time validation** - Show errors immediately as user types
- **Review before submit** - Intermediary step to check for mistakes
- **Specific error messages** - "Email is required" not "Invalid input"
- **Accessibility** - ARIA labels, screen reader support

**MediaWiki context:** PageForms `mandatory=true` provides basic required field validation. For advanced validation, would need custom JavaScript.

**Recommendation:** Rely on PageForms built-in validation for MVP. Add custom validation post-MVP if needed.

**Source:** [Form Validation Best Practices - Clearout](https://clearout.io/blog/form-validation/), [Review Before Submit - FormAssembly](https://help.formassembly.com/help/preview-before-submit)

---

## Property Conflict Scenarios

### Scenario 1: Shared Property, Same Datatype

**Categories:**
- Person: `Name` (Text)
- Employee: `Name` (Text)

**Resolution:** Show "Name" field once in Person template section. Employee template doesn't include Name field in form, but still receives value via shared semantic property.

**Risk:** LOW - Standard deduplication handles this.

---

### Scenario 2: Shared Property, Different Datatypes

**Categories:**
- Person: `Organization` (Text)
- Employee: `Organization` (Page)

**Resolution:** CONFLICT - Cannot generate form. Show error: "Property 'Organization' has conflicting datatypes (Text vs Page). Fix schema before creating multi-category page."

**Risk:** HIGH - Semantic MediaWiki cannot have same property with multiple types.

**Detection:** Run during form generation. Query PropertyStore for each property, compare datatypes.

---

### Scenario 3: Property Name Collision, Different Semantics

**Categories:**
- Contact: `Email` (stores contact email)
- System User: `Email` (stores login email)

**Resolution:** AMBIGUOUS - This is a schema design problem, not a technical conflict. Both use Email (Email datatype), but semantically different.

**Recommendation:** Encourage schema designers to use distinct property names: `Contact Email` vs `Login Email`.

**Risk:** MEDIUM - Can generate form, but user may be confused about what "Email" means.

---

### Scenario 4: Required in One, Optional in Another

**Categories:**
- Person: `Name` (required)
- Author: `Name` (optional)

**Resolution:** Treat as REQUIRED (most restrictive wins). Show "Name" field with required marker (`*`).

**Risk:** LOW - Users expect required fields to be enforced.

---

### Scenario 5: Inherited Property Duplication

**Categories:**
- Person: inherits `Email` from Contact
- Employee: inherits `Email` from Person (which inherits from Contact)

**Resolution:** Show "Email" once. C3 linearization already handles this via InheritanceResolver.

**Risk:** LOW - Existing infrastructure handles inherited properties.

---

## Open Questions for Phase-Specific Research

1. **Subobject handling in multi-category pages** - If Person has "Publications" subobject and Author has "Publications" subobject, how to deduplicate? (Likely needs deep research flag)

2. **Form caching** - Do dynamically generated multi-category forms need to be cached? Performance implications?

3. **Edit form behavior** - When editing existing multi-category page, does PageForms auto-detect all templates and populate all fields correctly? (Needs testing)

4. **Category removal** - If user edits page to remove a category, what happens to properties unique to that category? (Data cleanup strategy needed)

5. **Permission model** - Should multi-category page creation require special permissions? (Probably not for MVP)

---

## Confidence Assessment

| Topic | Confidence | Reasoning |
|-------|------------|-----------|
| PageForms multiple templates | MEDIUM | WebSearch found official docs, but unable to fetch full content. Verified via search result snippets. |
| C3 linearization | HIGH | Well-established algorithm, used in Python/Solidity. Wikipedia and academic sources confirm. |
| Property deduplication | MEDIUM | Inferred from PageForms patterns and SemanticSchemas existing architecture. No explicit documentation found. |
| Conflict detection | LOW | No specific guidance found for SMW property type conflicts in multi-template forms. Inferred from SMW property constraints. |
| Form preview | MEDIUM | WebSearch found PageForms preview feature, but unable to verify full functionality. |
| Validation patterns | HIGH | Multiple authoritative sources on form validation best practices (2026). |
| UI patterns | MEDIUM | General form builder patterns found, but wiki-specific guidance limited. |

---

## Sources

### Official Documentation
- [Extension:Page Forms/Page Forms and templates - MediaWiki](https://www.mediawiki.org/wiki/Extension:Page_Forms/Page_Forms_and_templates)
- [Extension:Page Forms/Defining forms - MediaWiki](https://www.mediawiki.org/wiki/Extension:Page_Forms/Defining_forms)
- [Extension:Page Schemas - MediaWiki](https://www.mediawiki.org/wiki/Extension:Page_Schemas)

### Algorithm & Architecture
- [C3 linearization - Wikipedia](https://en.m.wikipedia.org/wiki/C3_linearization)
- [Understanding C3 Linearization - Medium](https://medium.com/@mehmetyaman/understanding-c3-linearization-the-algorithm-behind-python-and-soliditys-method-resolution-order-f386b9a10696)
- [Empowering OWL with Overriding Inheritance - ResearchGate](https://www.researchgate.net/publication/221250984_Empowering_OWL_with_Overriding_Inheritance_Conflict_Resolution_and_Non-monotonic_Reasoning)

### UI/UX Best Practices
- [Form Validation Best Practices - Clearout](https://clearout.io/blog/form-validation/)
- [Review Before Submit - FormAssembly](https://help.formassembly.com/help/preview-before-submit)
- [Categorization Design Pattern](https://ui-patterns.com/patterns/categorization)
- [FormWizard - Meta-Wiki](https://meta.wikimedia.org/wiki/Meta:FormWizard)

### Ontology & Semantic Web
- [Classes and Individuals - Owlready2](https://owlready2.readthedocs.io/en/latest/class.html)
- [Ontology Best Practices - OSF Wiki](http://wiki.opensemanticframework.org/index.php/Ontology_Best_Practices)
- [Wikidata: WikiProject Ontology/Modelling](https://www.wikidata.org/wiki/Wikidata:WikiProject_Ontology/Modelling)

### Form Builder Patterns
- [Build Data Integrations - Form.io](https://form.io/build-data-integrations-to-multiple-disparate-data-sources/)
- [Dynamically Add Form Fields - Formidable Forms](https://formidableforms.com/features/dynamically-add-form-fields/)
