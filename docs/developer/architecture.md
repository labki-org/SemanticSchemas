# SemanticSchemas Source Code Documentation

## Overview

SemanticSchemas is a MediaWiki extension that provides schema-driven ontology management for Semantic MediaWiki (SMW) and PageForms. It treats schema definitions as the source of truth and generates wiki pages (categories, properties, forms, templates) as compiled artifacts.

## Architecture

### Core Principles

1. **Schema as Source of Truth**: Schema definitions (YAML/JSON) define the ontology structure
2. **Generated Artifacts**: Wiki pages are automatically generated from schema
3. **Separation of Concerns**: Data storage (semantic), presentation (display), and editing (forms) are separated
4. **Inheritance Support**: Categories support multiple inheritance via C3 linearization
5. **User Customization**: Display templates are editable stubs that won't be overwritten

### Three-Template System

Each category generates three templates:

- **Dispatcher** (`Template:<Category>`): Entry point that coordinates the other two
- **Semantic** (`Template:<Category>/semantic`): Stores SMW data using `{{#set:...}}`
- **Display** (`Template:<Category>/display`): Renders property values (user-editable stub)

## Module Structure

### Display Module (`Display/`)

Handles rendering of property values and display sections.

- **DisplayRenderer**: Renders property values with formatting, templates, and built-in types
- **DisplaySpecBuilder**: Builds effective display specifications by merging inherited sections

Key Features:
- Property value rendering with display templates
- Built-in display types (email, URL, image, boolean)
- Section-based layouts with inheritance
- Template variable substitution

### Generator Module (`Generator/`)

Generates wiki pages (templates, forms, displays) from schema.

- **TemplateGenerator**: Creates semantic and dispatcher templates
- **FormGenerator**: Generates PageForms forms with inheritance-aware sections
- **DisplayStubGenerator**: Creates user-editable display template stubs
- **PropertyInputMapper**: Maps SMW datatypes to PageForms input types

Key Features:
- Consistent parameter naming across all generators
- Inheritance-aware form sections
- Required/optional field handling
- Autocomplete and dropdown support

### Parser Module (`Parser/`)

MediaWiki parser function integration.

- **DisplayParserFunctions**: Registers and handles custom parser functions
  - `{{#SemanticSchemasRenderAllProperties:Category}}`
  - `{{#SemanticSchemasRenderSection:Category|Section}}`

Key Features:
- Argument validation and error handling
- Logging for failed render attempts
- Integration with display renderer

### Schema Module (`Schema/`)

Core schema models and operations.

- **CategoryModel**: Immutable category definition with properties and metadata
- **PropertyModel**: Immutable property definition with datatype and constraints (enhanced validation)
- **InheritanceResolver**: C3 linearization for multiple inheritance
- **SchemaLoader**: JSON/YAML parsing with gzip support and security limits
- **SchemaImporter**: Imports schema into wiki with topological sorting
- **SchemaExporter**: Exports current wiki state to schema with error recovery
- **SchemaValidator**: Validates schema with severity levels (errors + warnings)
- **SchemaComparer**: Compares two schemas and generates diffs

Key Features:
- C3 linearization for consistent inheritance
- Comprehensive validation with actionable errors and warnings
- Severity-based validation (errors vs warnings)
- Custom validation hooks for extensions
- Import/export with dry-run support and error recovery
- Gzip compression support for large schemas
- File size limits for security (10MB default)
- Enhanced datatype validation with warnings for unknown types
- Naming convention validation and suggestions
- Dirty state tracking

### Special Module (`Special/`)

Administrative UI for schema management.

- **SpecialSemanticSchemas**: Central control panel (Special:SemanticSchemas)

Features:
- Overview dashboard with status indicators
- Export to JSON/YAML with operation logging
- Import from file or text with progress indicators
- Validation and diff tools
- Template/form generation with per-category progress
- Sync state tracking
- Rate limiting (20 operations/hour, bypassable by sysops)
- Comprehensive audit logging for all operations
- CSRF protection on all form submissions

### Store Module (`Store/`)

Persistence layer for reading/writing wiki pages.

- **WikiCategoryStore**: Reads/writes Category pages with semantic annotations
- **WikiPropertyStore**: Reads/writes Property pages with semantic annotations
- **PageCreator**: Low-level page creation and update
- **PageHashComputer**: Computes hashes for dirty detection
- **StateManager**: Manages sync state in MediaWiki:SemanticSchemasState.json

Key Features:
- Marker-based section management
- Hash-based dirty detection
- Semantic annotation parsing
- State persistence

### Util Module (`Util/`)

Shared utilities and helpers.

- **NamingHelper**: Centralized naming transformations
  - Property name → parameter name conversion
  - Label generation
  - Name validation

## Data Flow

### Import Flow

```
YAML/JSON Schema
    ↓
SchemaLoader (parse)
    ↓
SchemaValidator (validate)
    ↓
SchemaImporter (topological sort)
    ↓
WikiCategoryStore / WikiPropertyStore (write pages)
    ↓
TemplateGenerator / FormGenerator / DisplayStubGenerator (generate artifacts)
    ↓
StateManager (update state)
```

### Export Flow

```
Wiki Pages (Category/Property)
    ↓
WikiCategoryStore / WikiPropertyStore (read pages)
    ↓
SchemaExporter (build schema)
    ↓
SchemaLoader (serialize)
    ↓
YAML/JSON Schema
```

### Render Flow

```
Page View
    ↓
Dispatcher Template
    ↓
Semantic Template (stores data) + Display Template (renders view)
    ↓
DisplayParserFunctions (#SemanticSchemasRenderAllProperties)
    ↓
DisplaySpecBuilder (build spec with inheritance)
    ↓
DisplayRenderer (render values)
    ↓
HTML Output
```

## Key Concepts

### Inheritance

SemanticSchemas uses **C3 linearization** (same as Python's Method Resolution Order) for consistent multiple inheritance:

- **Monotonicity**: Superclasses appear after subclasses
- **Local Precedence**: Parent order is respected
- **Consistency**: No ambiguous orderings

Example:
```
PhDStudent extends [GraduateStudent, Researcher]
GraduateStudent extends [Student]
Researcher extends [Person]
Student extends [Person]

Result: PhDStudent → GraduateStudent → Student → Researcher → Person
```

### Property Requirements

- **Required**: Property must have a value (enforced by PageForms mandatory flag)
- **Optional**: Property may be empty
- **Inheritance**: Once required by any ancestor, stays required (child cannot demote)

### Display Configuration

Display sections control how properties are rendered:

```yaml
display:
  sections:
    - name: "Contact Information"
      properties:
        - "Has email"
        - "Has phone"
```

Sections are inherited and merged by name. Child sections override parent sections.

### State Tracking

SemanticSchemas tracks two types of state:

1. **Dirty Flag**: Set when schema is imported but artifacts not yet generated
2. **Page Hashes**: SHA256 hashes of generated content to detect external modifications

State is stored in `MediaWiki:SemanticSchemasState.json`.

## Naming Conventions

### Property to Parameter Transformation

Consistent across all generators (via `NamingHelper::propertyToParameter()`):

1. Remove "Has " prefix
2. Replace ":" with "_"
3. Convert to lowercase
4. Replace spaces with underscores

Examples:
- `Has full name` → `full_name`
- `Foaf:name` → `foaf_name`

### Template Naming

- Dispatcher: `Template:<CategoryName>`
- Semantic: `Template:<CategoryName>/semantic`
- Display: `Template:<CategoryName>/display`
- Form: `Form:<CategoryName>`

## Error Handling

### Validation Errors

Schema validation catches:
- Missing required fields
- Invalid references (properties, categories)
- Circular dependencies
- Malformed configurations

### Runtime Errors

Runtime errors are logged via `wfLogWarning()`:
- Failed template expansion
- Invalid category names
- Missing properties
- Page creation failures

### User Feedback

UI provides actionable error messages:
- What went wrong
- Which entity (category/property) caused the error
- Suggested fixes when possible

## Performance Considerations

### Caching

- **InheritanceResolver**: Memoizes ancestor chains
- **StateManager**: Caches state in memory during request

### Optimization Tips

1. Inject pre-built InheritanceResolver when possible
2. Use batch operations for multiple categories
3. Avoid regenerating artifacts unless necessary (check dirty flag)
4. Consider lazy loading for large category hierarchies

### Known Bottlenecks

- Loading all categories for InheritanceResolver (10-50ms for 100+ categories)
- Generating templates/forms for large ontologies
- Parsing semantic annotations from wiki pages

## Testing

### Unit Test Targets

Recommended test coverage:

- **NamingHelper**: Property name transformations
- **InheritanceResolver**: C3 linearization with various hierarchies
- **SchemaValidator**: Validation logic for all error cases
- **SchemaComparer**: Diff generation accuracy
- **PropertyInputMapper**: Datatype to input type mappings

### Integration Test Scenarios

- Full import/export round-trip
- Template generation and rendering
- Inheritance resolution with complex hierarchies
- State tracking and dirty detection

## Extension Points

### Custom Display Types

Add new display types by:
1. Extending `DisplayRenderer::renderBuiltInDisplayType()`
2. Or using display templates (preferred for flexibility)

### Custom Validation Rules

Extend `SchemaValidator` to add custom validation logic.

### Custom Input Types

Extend `PropertyInputMapper::getInputType()` for new SMW datatypes.

## Dependencies

### Required Extensions

- **Semantic MediaWiki** (SMW_VERSION): Property and category semantics
- **PageForms** (PF_VERSION): Form generation and editing

### PHP Requirements

- PHP 8.0+ (uses `str_starts_with()`, typed properties)
- MediaWiki 1.39+

### External Libraries

- **symfony/yaml**: YAML parsing and serialization

## File Organization

```
src/
├── Display/           # Rendering logic
├── Generator/         # Artifact generation
├── Parser/            # Parser functions
├── Schema/            # Core models and operations
├── Special/           # Admin UI
├── Store/             # Persistence layer
└── Util/              # Shared utilities
```

## Maintenance Notes

### Adding New Fields to Schema

1. Update `CategoryModel` or `PropertyModel` with new field
2. Update `SchemaValidator` to validate new field
3. Update `WikiCategoryStore` or `WikiPropertyStore` to read/write field
4. Update generators if field affects output
5. Update documentation and schema examples

### Modifying Inheritance Behavior

Changes to inheritance must:
1. Update `InheritanceResolver` algorithm
2. Update `CategoryModel::mergeWithParent()` logic
3. Update documentation with new behavior
4. Consider backward compatibility

### Changing Naming Conventions

Changes to naming (property→parameter) must:
1. Update `NamingHelper::propertyToParameter()`
2. Regenerate all templates/forms (breaking change)
3. Update documentation
4. Consider migration path for existing wikis

## Known Limitations

1. **No Transaction Support**: Import/export is not atomic (future enhancement)
2. **Synchronous Operations**: Large imports block request (consider job queue)
3. **No Undo**: Template regeneration is destructive for semantic/dispatcher templates
4. **Single Schema Version**: No schema migration support yet

## Future Enhancements

See inline TODO comments in code for specific improvement opportunities:

- Extract shared parameter normalization to NamingHelper (done!)
- Add transaction support for atomic imports
- Implement async job queue for large operations
- Add schema version migration support
- Extract SpecialSemanticSchemas table rendering to separate class
- Add progress callbacks for long operations
- Implement caching for frequently accessed properties

## Contributing

When contributing to SemanticSchemas:

1. Follow existing code style and patterns
2. Add PHPDoc comments for all public methods
3. Update this README for architectural changes
4. Add validation for new schema fields
5. Ensure backward compatibility or document breaking changes
6. Add error logging for failure cases
7. Test with both JSON and YAML schema formats

## License

See main extension LICENSE file.

## Contact

For questions or issues, see the main extension documentation.

