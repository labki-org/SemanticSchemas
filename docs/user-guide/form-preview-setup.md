# Setting Up Hierarchy Preview in Category Forms

This guide explains how to add dynamic hierarchy preview to your category creation forms.

## Overview

When creating new categories via `Form:Category`, users can see a live preview of:
- The inheritance hierarchy (parent relationships)
- Properties that will be inherited (with required/optional counts)

The preview updates automatically as users add or change parent categories.

## Automatic Setup (Recommended)

**Good news!** If your category schema has a parent category (`[[Category:SomeParentCategory]]`), the hierarchy preview is **automatically injected** when forms are regenerated via `Special:SemanticSchemas/regenerate`.

When `FormGenerator` detects that a category has a parent category, it will automatically:
1. Add `{{#semanticschemas_load_form_preview:}}` to load the module
2. Add the preview container `<div>` with proper configuration
3. Position the preview after the form fields
4. 
If automatic injection works for your use case, you can skip the manual setup below!

## Manual Setup Instructions

For custom forms or manual configuration, follow these steps:

### Step 1: Create Form:Category

If you don't already have a `Form:Category` page, create one at:
```
Special:FormEdit/Category
```

### Step 2: Add the Preview Container

Add this HTML div somewhere in your form where you want the preview to appear:

```html
<div id="ss-form-hierarchy-preview"></div>
```

**Recommended location**: After the parent category field, before the properties section.

### Step 3: Load the ResourceLoader Module

Add this to the `<noinclude>` section of your form:

```wiki
<noinclude>
{{#invoke:ResourceLoader|load|ext.semanticschemas.hierarchy.formpreview}}
</noinclude>
```

Alternatively, add it via JavaScript in MediaWiki:Common.js:
```javascript
// Load on Form:Category page
if (mw.config.get('wgCanonicalNamespace') === 'Form' && mw.config.get('wgTitle') === 'Category') {
    mw.loader.load('ext.semanticschemas.hierarchy.formpreview');
}
```

## Complete Form:Category Example

Here's a complete example of a category creation form with hierarchy preview:

```wiki
<noinclude>
This is the form for creating new categories.

{{#forminput:form=Category}}

[[Category:Forms]]
</noinclude><includeonly>
{{{for template|Category}}}

{| class="formtable"
! Category name:
| {{{field|Category|input type=text|size=50|mandatory}}}
|-
! Label:
| {{{field|Label|input type=text|size=50}}}
|-
! Description:
| {{{field|Description|input type=textarea|rows=3|cols=50}}}
|-
! Parent categories:
| {{{field|Parents|input type=tokens|values from category=Category}}}
|}

'''Hierarchy Preview:'''
<div id="ss-form-hierarchy-preview"></div>

=== Required Properties ===
{{{field|Required properties|input type=tokens|values from namespace=Property}}}

=== Optional Properties ===
{{{field|Optional properties|input type=tokens|values from namespace=Property}}}

{{{end template}}}

{{{standard input|save}}} {{{standard input|preview}}} {{{standard input|changes}}} {{{standard input|cancel}}}
</includeonly>
```

## Advanced Configuration

### Custom Field IDs

If your form uses non-standard field names, specify them via data attributes:

```html
<div id="ss-form-hierarchy-preview" 
     data-parent-field="custom-parent-field-id"
     data-category-field="custom-category-field-id"></div>
```

### Field Name Detection

The preview module automatically detects fields with these patterns:
- Parent field: Contains "parent" or "Parent" in the name
- Category field: Named "page_name", "category_name", or "Category"

## How It Works

1. **User types parent categories**: In the "Parents" field (comma-separated or one per line)
2. **JavaScript watches for changes**: Detects input with 500ms debounce
3. **API call is made**: `action=semanticschemas-hierarchy&category=NewCategory&parents=Parent1|Parent2`
4. **Preview updates**: Shows hierarchy tree and inherited properties count

## Features

### Hierarchy Tree
- Shows the new category (marked with "(new)" badge)
- Displays parent categories
- Shows grandparents and full inheritance chain
- Visual tree structure with indentation

### Inherited Properties Summary
- Count of required properties
- Count of optional properties
- Color-coded (red for required, green for optional)

## Styling

The preview uses these CSS classes (can be customized):
- `.ss-preview-wrapper` - Main preview container
- `.ss-preview-section` - Each section (tree, properties)
- `.ss-preview-node-virtual` - The new (virtual) category
- `.ss-preview-count-required` - Required properties badge
- `.ss-preview-count-optional` - Optional properties badge

Add custom CSS in MediaWiki:Common.css:
```css
#ss-form-hierarchy-preview {
    background: your-custom-color;
    /* ... */
}
```

## Troubleshooting

### Preview doesn't appear
- Check that `#ss-form-hierarchy-preview` div exists
- Verify the ResourceLoader module is loaded (F12 → Console)
- Check browser console for JavaScript errors

### Field not detected
- Add explicit data attributes to the preview div
- Check the field's `name` attribute matches the patterns

### API errors
- Ensure parent category names are correct (case-sensitive)
- Check that parent categories exist in the wiki
- Non-existent parents will be skipped (no error)

### No properties shown
- This is normal if parent categories have no properties defined
- Check parent categories have SemanticSchemas schema annotations

## See Also

- [Hierarchy Visualization Guide](hierarchy-visualization.md)
- [Quick Reference](../reference/quick-reference.md)
- [Main README](../../README.md)

