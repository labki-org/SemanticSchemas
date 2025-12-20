# Display Property Configuration

## Overview

Properties can now define their display behavior using semantic properties instead of wikitext sections. This provides a cleaner, more maintainable approach to customizing how property values are rendered.

## Meta-Properties

Three new meta-properties control display behavior:

### 1. `Property:Has display template`
- **Type:** Text
- **Purpose:** Defines a custom HTML/wikitext template for displaying the property's value
- **Placeholder:** Use `{{{value}}}` for the property value
- **Additional placeholders:** `{{{property}}}` for property name, `{{{page}}}` for page title

**Example:**
```wiki
[[Has display template::<div class="custom-style">{{{value}}}</div>]]
```

### 2. `Property:Has display type`
- **Type:** Text
- **Purpose:** Specifies a built-in display type for rendering
- **Allowed values:** Email, URL, Image, Boolean, none
- **Built-in behaviors:**
  - `Email`: Renders as `[mailto:value value]`
  - `URL`: Renders as `[value Website]`
  - `Image`: Renders as `[[File:value|thumb|200px]]`
  - `Boolean`: Renders as "Yes" or "No"
  - `none`: Skip built-in rendering (useful with custom templates)

**Example:**
```wiki
[[Has display type::Email]]
```

### 3. `Property:Has display pattern`
- **Type:** Page (Property namespace)
- **Purpose:** References another property to reuse its display template
- **Use case:** Create reusable display patterns

**Example:**
```wiki
[[Has display pattern::Property:Email]]
```

## Priority Order

When rendering a property value, the system checks in this order:

1. **Display Template** (highest priority)
   - If `Has display template` is set, use it
2. **Display Pattern**
   - If `Has display pattern` is set, look up that property's template
3. **Display Type**
   - If `Has display type` is set, use built-in rendering or look up a pattern property
4. **Default** (lowest priority)
   - HTML-escape the value and display as plain text

## Examples

### Example 1: Custom Biography Display

```wiki
Property:Has biography
----
<!-- SemanticSchemas Start -->
[[Has type::Text]]
[[Has description::Biography or description text.]]
[[Has display template::<div class="bio-block" style="background: #f9f9f9; padding: 10px; border-left: 3px solid #0066cc; margin: 10px 0;"><div class="bio-label" style="font-weight: bold; color: #0066cc; margin-bottom: 5px;">Biography</div><div class="bio-value" style="white-space: pre-wrap;">{{{value}}}</div></div>]]
[[Has display type::none]]
<!-- SemanticSchemas End -->
```

### Example 2: Email Pattern Property

```wiki
Property:Email
----
<!-- SemanticSchemas Start -->
[[Has type::Text]]
[[Has description::Display pattern for rendering email addresses.]]
[[Has display template::[mailto:{{{value}}} {{{value}}}] ]]
[[Has display type::none]]
<!-- SemanticSchemas End -->

[[Category:Display Patterns]]
```

### Example 3: Using a Display Pattern

```wiki
Property:Has email
----
<!-- SemanticSchemas Start -->
[[Has type::Email]]
[[Has description::Email address.]]
[[Display label::Email]]
[[Has display pattern::Property:Email]]
<!-- SemanticSchemas End -->
```

### Example 4: Using Built-in Display Type

```wiki
Property:Has active status
----
<!-- SemanticSchemas Start -->
[[Has type::Boolean]]
[[Has description::Whether the person is currently active.]]
[[Has display type::Boolean]]
<!-- SemanticSchemas End -->
```

## Migration from Wikitext Sections

**Old approach (deprecated):**
```wiki
=== Display template ===
<div>Custom HTML</div>

=== Display type ===
Email
```

**New approach:**
```wiki
[[Has display template::<div>Custom HTML</div>]]
[[Has display type::Email]]
```

## Implementation Details

### Code Flow

1. **WikiPropertyStore** reads the display properties from SMW:
   - `Has display template` → `display.template`
   - `Has display type` → `display.type`
   - `Has display pattern` → `display.fromProperty`

2. **PropertyModel** provides accessors:
   - `getDisplayTemplate()`
   - `getDisplayType()`
   - `getDisplayPattern()`

3. **DisplayRenderer** applies them in priority order:
   - `renderValue()` checks template → pattern → type → default

### Files Modified

- `src/Store/WikiPropertyStore.php` - Reads display properties from SMW
- `tests/scripts/populate_test_data.sh` - Creates meta-properties and examples
- `src/Display/DisplayRenderer.php` - Already correctly implements the rendering logic

## Testing

After running the updated test data script:

1. Check `Property:Has biography` - should have custom styled display
2. Check `Property:Has email` - should render as mailto links via pattern
3. Check `Property:Has active status` - should render as Yes/No
4. View pages like `John_Doe` - biography should have custom styling

## Benefits

✅ **Semantic:** Display config is queryable via SMW
✅ **Reusable:** Create pattern properties for common displays
✅ **Maintainable:** No wikitext parsing required
✅ **Flexible:** Supports templates, patterns, and built-in types
✅ **Clean:** No special section markers needed

