# Category Hierarchy Visualization

The StructureSync extension provides a powerful hierarchy visualization feature that shows category inheritance trees and inherited properties. This helps you understand how properties flow through your category structure.

## Table of Contents

- [Overview](#overview)
- [Using the Special Page](#using-the-special-page)
- [Embedding in Category Pages](#embedding-in-category-pages)
- [API Usage](#api-usage)
- [Understanding the Display](#understanding-the-display)
- [Examples](#examples)
- [Troubleshooting](#troubleshooting)

---

## Overview

The hierarchy visualization displays:

1. **Inheritance Tree**: Shows parent categories, grandparents, and the complete ancestor chain
2. **Inherited Properties**: Lists all properties inherited from each ancestor
3. **Required vs Optional**: Visual distinction with color coding:
   - **Red/pink background**: Required properties
   - **Green background**: Optional properties

The feature uses C3 linearization (same as Python's MRO) to resolve multiple inheritance correctly.

---

## Using the Special Page

The easiest way to visualize category hierarchies is through the dedicated Special page tab.

### Access

Navigate to: **Special:StructureSync/hierarchy**

Or click the **"Hierarchy"** tab from any Special:StructureSync page.

### Steps

1. Enter a category name in the input field (with or without "Category:" prefix)
   - Examples: `Faculty`, `Category:PhDStudent`, `GraduateStudent`

2. Click **"Show Hierarchy"**

3. The page displays:
   - **Inheritance Tree**: Nested list showing the category and all its parents
   - **Inherited Properties Table**: Grouped by source category

### URL Parameters

You can also link directly to a specific category:

```
Special:StructureSync/hierarchy?category=PhDStudent
```

This is useful for:
- Bookmarking specific hierarchies
- Creating wiki links to visualizations
- Sharing with team members

---

## Embedding in Category Pages

You can embed the hierarchy visualization directly on Category pages using a parser function.

### Syntax

```wiki
{{#structuresync_hierarchy:}}
```

### Usage

1. **Edit a Category page** (e.g., `Category:Faculty`)

2. **Add the parser function** anywhere in the page content:

```wiki
This category defines Faculty members in our organization.

== Category Hierarchy ==
{{#structuresync_hierarchy:}}

== Pages in this Category ==
<!-- Normal category content continues -->
```

3. **Save the page**

The hierarchy visualization will automatically appear, showing the inheritance tree and properties for that specific category.

### Features

- **Automatic detection**: The parser function automatically detects which category page it's on
- **Collapsible**: The section is collapsible by default (using MediaWiki's `mw-collapsible` class)
- **No parameters needed**: Simply add `{{#structuresync_hierarchy:}}` - it figures out the rest

### Where to Add

Common locations for the parser function:

- **Top of category page**: After the description, before the content
- **In a dedicated section**: Under a "Hierarchy" or "Inheritance" heading
- **In category templates**: Add to your category template for automatic inclusion

### Example Category Page

```wiki
The Faculty category represents teaching and research faculty members.

== Structure ==
{{#structuresync_hierarchy:}}

== Requirements ==
All Faculty pages must include:
* Full name
* Department
* Institution
* Email address

== Pages in this category ==
```

---

## API Usage

For developers and advanced users, the hierarchy data is available via MediaWiki's API.

### Endpoint

```
api.php?action=structuresync-hierarchy&category=CATEGORYNAME&format=json
```

### Parameters

| Parameter | Required | Description |
|-----------|----------|-------------|
| `action` | Yes | Must be `structuresync-hierarchy` |
| `category` | Yes | Category name (with or without "Category:" prefix) |
| `format` | No | Response format: `json` (default), `jsonfm`, `xml`, etc. |

### Example Request

```bash
curl "http://your-wiki.org/api.php?action=structuresync-hierarchy&category=Faculty&format=json"
```

### Response Structure

```json
{
  "structuresync-hierarchy": {
    "rootCategory": "Category:Faculty",
    "nodes": {
      "Category:Faculty": {
        "title": "Category:Faculty",
        "parents": ["Category:Person"]
      },
      "Category:Person": {
        "title": "Category:Person",
        "parents": []
      }
    },
    "inheritedProperties": [
      {
        "propertyTitle": "Property:Has department",
        "sourceCategory": "Category:Faculty",
        "required": 1
      },
      {
        "propertyTitle": "Property:Has full name",
        "sourceCategory": "Category:Person",
        "required": 1
      },
      {
        "propertyTitle": "Property:Has biography",
        "sourceCategory": "Category:Person",
        "required": 0
      }
    ]
  }
}
```

### Field Descriptions

**nodes**: Map of category titles to node data
- `title`: Full category title with "Category:" prefix
- `parents`: Array of parent category titles

**inheritedProperties**: Array of property objects
- `propertyTitle`: Full property title with "Property:" prefix
- `sourceCategory`: Which category contributed this property
- `required`: `1` for required properties, `0` for optional properties

### Use Cases

- **External documentation**: Generate documentation from your wiki structure
- **Validation tools**: Check category hierarchy consistency
- **Custom visualizations**: Build alternative UI displays
- **Integration**: Connect with other systems or tools
- **Analysis**: Analyze property distribution across categories

---

## Understanding the Display

### Inheritance Tree

The tree shows categories from most specific (your category) to most general (root ancestors):

```
PhDStudent
└── GraduateStudent
    ├── Person
    └── LabMember
```

**Reading the tree:**
- Top node is the category you queried
- Each branch shows a parent relationship
- Multiple parents appear as multiple branches (multiple inheritance)

### Properties Display

The properties section offers **two viewing modes** via tabs:

#### By Category Tab (Default)
Properties grouped by their source category:

| Source Category | Properties |
|----------------|------------|
| Category:Faculty | Has department (required)<br>Has institution (required)<br>Has office location (optional) |
| Category:Person | Has full name (required)<br>Has email (required)<br>Has phone (optional) |

#### By Type Tab
Properties grouped by required/optional status:

**Required Properties (5)**
- Has full name (Person)
- Has email (Person)
- Has department (Faculty)
- Has institution (Faculty)
- Has office hours (Faculty)

**Optional Properties (2)**
- Has phone (Person)
- Has office location (Faculty)

**Color coding:**
- **Red/pink background + left border**: Required properties
- **Green background + left border**: Optional properties
- Required/optional badges in "By Category" view

**Clickable links:**
- Category names link to category pages
- Property names link to property pages
- Source categories (in parentheses) are clickable in "By Type" view

---

## Examples

### Example 1: Simple Single Inheritance

**Category: Undergraduate**
- Parent: Student → Person

**Inherited Properties:**
- From Student: advisor (required), academic level (required)
- From Person: full name (required), email (required), biography (optional)

### Example 2: Multiple Inheritance

**Category: GraduateStudent**
- Parents: Person + LabMember

**Inherited Properties:**
- From GraduateStudent: advisor (required), thesis title (optional)
- From Person: full name (required), email (required)
- From LabMember: lab role (required), start date (required)

### Example 3: Deep Hierarchy (4 Levels)

**Category: PhDStudent**
```
PhDStudent
└── GraduateStudent
    ├── Person
    └── LabMember
```

Properties inherited from all 4 levels are displayed and grouped.

### Example 4: Diamond Pattern

**Category: PI (Principal Investigator)**
```
PI
├── Faculty
│   └── Person
└── LabMember
```

Person appears in the hierarchy but properties are only listed once (C3 linearization handles this correctly).

---

## Troubleshooting

### Hierarchy Not Displaying

**Problem**: Blank page or "No hierarchy data available"

**Solutions:**
1. **Hard refresh your browser**: Press `Ctrl+Shift+R` (Windows/Linux) or `Cmd+Shift+R` (Mac)
2. **Check category exists**: Verify the category page exists on your wiki
3. **Check browser console**: Open Developer Tools (F12) and check for JavaScript errors
4. **Verify extension is loaded**: Go to `Special:Version` and confirm StructureSync is listed

### Parser Function Not Working

**Problem**: `{{#structuresync_hierarchy:}}` shows as plain text

**Solutions:**
1. **Check namespace**: Parser function only works on Category namespace pages
2. **Clear parser cache**: Add `?action=purge` to the URL
3. **Verify installation**: Ensure the magic word is registered in `StructureSync.alias.php`

### Properties Not Showing Colors

**Problem**: All properties look the same, no red/green backgrounds

**Solutions:**
1. **Hard refresh**: Clear browser cache
2. **Check CSS loading**: Verify `ext.structuresync.hierarchy.css` is loaded in page source
3. **Check browser compatibility**: Use a modern browser (Chrome, Firefox, Safari, Edge)

### API Returns Empty Data

**Problem**: API call works but returns empty `inheritedProperties`

**Possible causes:**
1. Category has no properties defined
2. Category has no parent categories
3. Semantic MediaWiki data needs refresh: run `php maintenance/run.php SMW\rebuildData.php`

### Performance Issues

**Problem**: Slow loading on large category hierarchies

**Solutions:**
1. The service caches results internally
2. For very large wikis, consider:
   - Limiting depth of hierarchies
   - Breaking up large multiple inheritance chains
   - Using database indices on SMW tables

---

## Best Practices

### When to Use Each Method

**Use the Special Page when:**
- Exploring different categories interactively
- Comparing multiple category structures
- You need a quick overview without editing pages

**Use the Parser Function when:**
- Documenting a specific category structure
- You want persistent visualization on the category page
- End users need to understand inheritance without visiting Special pages

**Use the API when:**
- Building external tools or integrations
- Generating automated documentation
- Analyzing large-scale category structures
- Creating custom visualizations

### Documentation Tips

1. **Add hierarchy display to important categories**: Faculty, Student, Project, etc.
2. **Link to the Special page in your wiki's help pages**
3. **Include examples in your wiki's style guide**
4. **Document your category structure** using the visualizations as reference

### Performance Tips

1. **Avoid extremely deep hierarchies** (>10 levels) - they're hard to understand
2. **Limit multiple inheritance** to 2-3 parents where possible
3. **Use clear, consistent naming** for categories and properties
4. **Document complex inheritance patterns** on your wiki

---

## Related Documentation

- [StructureSync Overview](../README.md)
- [Category Schema Design](category-schema.md) _(if exists)_
- [Property Definition](property-definition.md) _(if exists)_
- [Form Generation](form-generation.md) _(if exists)_

---

## Support

For issues, questions, or feature requests:

1. Check the [main README](../README.md)
2. Review [test examples](../tests/populate_test_data.sh)
3. Examine the [implementation notes](../IMPLEMENTATION.md)

---

**Last Updated**: November 2024
**Extension Version**: StructureSync 0.1.0

