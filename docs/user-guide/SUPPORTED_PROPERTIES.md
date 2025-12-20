# Supported Semantic Properties

This document outlines all semantic properties supported by SemanticSchemas for Categories, Properties, and Subobjects. These properties are used to define schema metadata and control behavior.

## Categories

Category pages support the following semantic properties to define their schema:

### Basic Metadata

#### `Property:Display label`
- **Type:** Text
- **Purpose:** Human-readable label for the category
- **Default:** Category name (auto-generated)
- **Example:**
  ```wiki
  [[Display label::Person]]
  ```

#### `Property:Has description`
- **Type:** Text
- **Purpose:** Description of what this category represents
- **Example:**
  ```wiki
  [[Has description::Represents a person in the system]]
  ```

#### `Property:Has target namespace`
- **Type:** Text
- **Purpose:** Target namespace for pages in this category
- **Example:**
  ```wiki
  [[Has target namespace::Main]]
  ```

### Inheritance

#### `Property:Has parent category`
- **Type:** Page (Category namespace)
- **Purpose:** Defines parent categories for inheritance
- **Multiple:** Yes (supports multiple inheritance)
- **Example:**
  ```wiki
  [[Has parent category::Category:Entity]]
  [[Has parent category::Category:Living Thing]]
  ```

### Properties

#### `Property:Has required property`
- **Type:** Page (Property namespace)
- **Purpose:** Properties that must be filled when creating a page in this category
- **Multiple:** Yes
- **Example:**
  ```wiki
  [[Has required property::Property:Has full name]]
  [[Has required property::Property:Has email]]
  ```

#### `Property:Has optional property`
- **Type:** Page (Property namespace)
- **Purpose:** Properties that may be filled when creating a page in this category
- **Multiple:** Yes
- **Example:**
  ```wiki
  [[Has optional property::Property:Has phone]]
  [[Has optional property::Property:Has website]]
  ```

### Subobjects

#### `Property:Has required subobject`
- **Type:** Page (Subobject namespace)
- **Purpose:** Subobjects that must be present on pages in this category
- **Multiple:** Yes
- **Example:**
  ```wiki
  [[Has required subobject::Subobject:Publication]]
  ```

#### `Property:Has optional subobject`
- **Type:** Page (Subobject namespace)
- **Purpose:** Subobjects that may be present on pages in this category
- **Multiple:** Yes
- **Example:**
  ```wiki
  [[Has optional subobject::Subobject:Award]]
  ```

### Display Configuration

#### `Property:Has display format`
- **Type:** Text
- **Purpose:** Controls how properties are rendered in the display template
- **Allowed Values:**
  - `Sections` (default) - Properties grouped in sections with headers
  - `Table` - All properties in a single HTML table
  - `Side infobox` - Properties in a right-aligned infobox table
  - `Plain text` - Simple list format
- **Example:**
  ```wiki
  [[Has display format::Table]]
  ```

#### `Property:Has display header property`
- **Type:** Page (Property namespace)
- **Purpose:** Properties to display prominently at the top of the page
- **Multiple:** Yes
- **Example:**
  ```wiki
  [[Has display header property::Property:Has full name]]
  [[Has display header property::Property:Has title]]
  ```

#### Display Sections (Subobjects)

Display sections are defined using subobjects with the following structure:

```wiki
{{#subobject:display_section_0
|Has display section name=Contact Information
|Has display section property=Property:Has email
|Has display section property=Property:Has phone
}}
```

**Subobject Properties:**
- `Property:Has display section name` (Text) - Name of the display section
- `Property:Has display section property` (Property) - Properties to include in this section

### Complete Category Example

```wiki
Category:Person
----
<!-- SemanticSchemas Start -->
[[Display label::Person]]
[[Has description::Represents a person in the system]]
[[Has parent category::Category:Entity]]
[[Has required property::Property:Has full name]]
[[Has required property::Property:Has email]]
[[Has optional property::Property:Has phone]]
[[Has optional property::Property:Has website]]
[[Has optional subobject::Subobject:Publication]]
[[Has display format::Table]]
[[Has display header property::Property:Has full name]]

{{#subobject:display_section_0
|Has display section name=Contact Information
|Has display section property=Property:Has email
|Has display section property=Property:Has phone
}}

{{#subobject:display_section_1
|Has display section name=Professional Information
|Has display section property=Property:Has title
|Has display section property=Property:Has department
}}
<!-- SemanticSchemas End -->
```

---

## Properties

Property pages support the following semantic properties to define their schema:

### Required Properties

#### `Property:Has type`
- **Type:** Text
- **Purpose:** SMW datatype for this property
- **Required:** Yes
- **Allowed Values:** Text, Page, Date, Number, Email, URL, Boolean, Code, Geographic coordinate, Quantity, Temperature, Telephone number, Annotation URI, External identifier, Keyword, Monolingual text, Record, Reference
- **Example:**
  ```wiki
  [[Has type::Text]]
  ```

### Basic Metadata

#### `Property:Display label`
- **Type:** Text
- **Purpose:** Human-readable label for the property
- **Default:** Auto-generated from property name (removes "Has " prefix, capitalizes)
- **Example:**
  ```wiki
  [[Display label::Full Name]]
  ```

#### `Property:Has description`
- **Type:** Text
- **Purpose:** Description of what this property represents
- **Example:**
  ```wiki
  [[Has description::The person's full legal name]]
  ```

### Value Constraints

#### `Property:Allows value`
- **Type:** Text
- **Purpose:** Enumeration of allowed values (creates dropdown in forms)
- **Multiple:** Yes
- **Example:**
  ```wiki
  [[Allows value::Active]]
  [[Allows value::Inactive]]
  [[Allows value::Pending]]
  ```

#### `Property:Allows multiple values`
- **Type:** Boolean
- **Purpose:** Whether this property can have multiple values
- **Example:**
  ```wiki
  [[Allows multiple values::true]]
  ```

#### `Property:Has domain and range`
- **Type:** Page (Category namespace)
- **Purpose:** For Page-type properties, restricts values to pages in this category
- **Example:**
  ```wiki
  [[Has domain and range::Category:Person]]
  ```

#### `Property:Subproperty of`
- **Type:** Page (Property namespace)
- **Purpose:** Defines this property as a subproperty of another property
- **Example:**
  ```wiki
  [[Subproperty of::Property:Has contact information]]
  ```

#### `Property:Allows value from category`
- **Type:** Text
- **Purpose:** For autocomplete, restricts suggestions to pages in this category
- **Example:**
  ```wiki
  [[Allows value from category::Person]]
  ```

#### `Property:Allows value from namespace`
- **Type:** Text
- **Purpose:** For autocomplete, restricts suggestions to pages in this namespace
- **Example:**
  ```wiki
  [[Allows value from namespace::Main]]
  ```

### Display Configuration

#### `Property:Has display template`
- **Type:** Text
- **Purpose:** Custom HTML/wikitext template for displaying the property value
- **Placeholders:**
  - `{{{value}}}` - The property value
  - `{{{property}}}` - The property name
  - `{{{page}}}` - The current page title
- **Example:**
  ```wiki
  [[Has display template::<div class="custom-style">{{{value}}}</div>]]
  ```

#### `Property:Has display type`
- **Type:** Text
- **Purpose:** Built-in display type for rendering
- **Allowed Values:** Email, URL, Image, Boolean, or any property name (for pattern lookup)
- **Built-in behaviors:**
  - `Email`: Renders as `[mailto:value value]`
  - `URL`: Renders as `[value Website]`
  - `Image`: Renders as `[[File:value|thumb|200px]]`
  - `Boolean`: Renders as "Yes" or "No"
- **Example:**
  ```wiki
  [[Has display type::Email]]
  ```

#### `Property:Has display pattern`
- **Type:** Page (Property namespace)
- **Purpose:** References another property to reuse its display template
- **Use case:** Create reusable display patterns
- **Example:**
  ```wiki
  [[Has display pattern::Property:Email]]
  ```

**Note:** Display configuration follows priority order: Template → Pattern → Type → Default. See [Display Properties Documentation](DISPLAY_PROPERTIES.md) for details.

### Complete Property Example

```wiki
Property:Has email
----
<!-- SemanticSchemas Start -->
[[Has type::Email]]
[[Display label::Email Address]]
[[Has description::Primary email address for contact]]
[[Allows multiple values::false]]
[[Has display type::Email]]
<!-- SemanticSchemas End -->
```

---

## Subobjects

Subobject pages support the following semantic properties to define their schema:

### Basic Metadata

#### `Property:Display label`
- **Type:** Text
- **Purpose:** Human-readable label for the subobject
- **Default:** Subobject name (auto-generated)
- **Example:**
  ```wiki
  [[Display label::Publication]]
  ```

#### `Property:Has description`
- **Type:** Text
- **Purpose:** Description of what this subobject represents
- **Example:**
  ```wiki
  [[Has description::A publication or research paper]]
  ```

### Properties

#### `Property:Has required property`
- **Type:** Page (Property namespace)
- **Purpose:** Properties that must be filled for each subobject instance
- **Multiple:** Yes
- **Example:**
  ```wiki
  [[Has required property::Property:Has title]]
  [[Has required property::Property:Has publication date]]
  ```

#### `Property:Has optional property`
- **Type:** Page (Property namespace)
- **Purpose:** Properties that may be filled for each subobject instance
- **Multiple:** Yes
- **Example:**
  ```wiki
  [[Has optional property::Property:Has DOI]]
  [[Has optional property::Property:Has abstract]]
  ```

### Complete Subobject Example

```wiki
Subobject:Publication
----
<!-- SemanticSchemas Start -->
[[Display label::Publication]]
[[Has description::A publication or research paper]]
[[Has required property::Property:Has title]]
[[Has required property::Property:Has publication date]]
[[Has optional property::Property:Has DOI]]
[[Has optional property::Property:Has abstract]]
[[Has optional property::Property:Has journal]]
<!-- SemanticSchemas End -->
```

---

## Property Reference Summary

### Categories

| Property | Type | Multiple | Required | Description |
|----------|------|----------|----------|-------------|
| `Display label` | Text | No | No | Human-readable label |
| `Has description` | Text | No | No | Category description |
| `Has target namespace` | Text | No | No | Target namespace |
| `Has parent category` | Category | Yes | No | Parent categories |
| `Has required property` | Property | Yes | No | Required properties |
| `Has optional property` | Property | Yes | No | Optional properties |
| `Has required subobject` | Subobject | Yes | No | Required subobjects |
| `Has optional subobject` | Subobject | Yes | No | Optional subobjects |
| `Has display format` | Text | No | No | Display format (Table, Side infobox, Plain text, Sections) |
| `Has display header property` | Property | Yes | No | Header properties |
| `Has display section name` | Text | No | No | Display section name (in subobject) |
| `Has display section property` | Property | Yes | No | Properties in section (in subobject) |

### Properties

| Property | Type | Multiple | Required | Description |
|----------|------|----------|----------|-------------|
| `Has type` | Text | No | **Yes** | SMW datatype |
| `Display label` | Text | No | No | Human-readable label |
| `Has description` | Text | No | No | Property description |
| `Allows value` | Text | Yes | No | Allowed enumeration values |
| `Allows multiple values` | Boolean | No | No | Allow multiple values |
| `Has domain and range` | Category | No | No | Category restriction for Page type |
| `Subproperty of` | Property | No | No | Parent property |
| `Allows value from category` | Text | No | No | Autocomplete category filter |
| `Allows value from namespace` | Text | No | No | Autocomplete namespace filter |
| `Has display template` | Text | No | No | Custom display template |
| `Has display type` | Text | No | No | Built-in display type |
| `Has display pattern` | Property | No | No | Reference to pattern property |

### Subobjects

| Property | Type | Multiple | Required | Description |
|----------|------|----------|----------|-------------|
| `Display label` | Text | No | No | Human-readable label |
| `Has description` | Text | No | No | Subobject description |
| `Has required property` | Property | Yes | No | Required properties |
| `Has optional property` | Property | Yes | No | Optional properties |

---

## Notes

### SemanticSchemas Markers

All schema metadata should be placed between SemanticSchemas markers:

```wiki
<!-- SemanticSchemas Start -->
[[Property::Value]]
<!-- SemanticSchemas End -->
```

This ensures that:
- SemanticSchemas can identify and manage the metadata
- User-added content outside the markers is preserved
- The metadata can be updated programmatically

### Inheritance

- **Categories:** Support multiple inheritance via `Has parent category`
- **Properties:** Support single inheritance via `Subproperty of`
- **Subobjects:** No inheritance support

### Display Format Priority

For properties, display configuration is applied in this priority order:

1. **Display Template** (highest priority) - Custom template defined on the property
2. **Display Pattern** - Template from referenced property
3. **Display Type** - Built-in rendering or pattern property lookup
4. **Default** (lowest priority) - HTML-escaped plain text

### Best Practices

1. **Use descriptive labels:** Always set `Display label` for user-facing names
2. **Document everything:** Use `Has description` to explain purpose
3. **Leverage inheritance:** Use parent categories to share common properties
4. **Create patterns:** Use `Has display pattern` for reusable display templates
5. **Validate constraints:** Use enumeration values or category restrictions for data quality

---

## Related Documentation

- [Display Properties](DISPLAY_PROPERTIES.md) - Detailed guide to display configuration
- [Quick Reference](../reference/quick-reference.md) - Common commands and workflows
- [Main README](../../README.md) - Installation and basic usage

