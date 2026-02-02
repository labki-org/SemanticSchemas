# SemanticSchemas

SemanticSchemas is a MediaWiki extension that treats Categories and Properties as an ontology backbone, providing:

- Schema management for categories, properties, and repeatable subobjects
- Automatic template and form generation
- Multiple inheritance support
- Import/export functionality for schemas in JSON/YAML formats
- Validation and diff tools

## Requirements

- MediaWiki 1.39+
- PHP 7.4+
- [SemanticMediaWiki](https://www.semantic-mediawiki.org/)
- [PageForms](https://www.mediawiki.org/wiki/Extension:Page_Forms)
- [ParserFunctions](https://www.mediawiki.org/wiki/Extension:ParserFunctions)

## Installation

For detailed installation instructions, see the [Installation Guide](docs/getting-started/installation.md).

Quick start:

1. Clone to your MediaWiki `extensions` directory
2. Install Composer dependencies: `composer install --no-dev`
3. Add `wfLoadExtension( 'SemanticSchemas' );` to `LocalSettings.php`
4. Run `php maintenance/run.php SemanticSchemas:InstallConfig` to install necessary pages
5. Run `php maintenance/update.php`

## Configuration

No configuration is required for basic usage. The extension works out of the box once installed.

## Usage

### Special Page

Access the main interface at `Special:SemanticSchemas` which provides:

- **Overview**: View all categories and their status
- **Export**: Export your ontology schema to JSON or YAML
- **Import**: Import a schema from file or text
- **Validate**: Check schema consistency
- **Generate**: Regenerate templates and forms
- **Diff**: Compare schema file with current wiki state
- **Hierarchy**: Visualize category inheritance trees and inherited properties

### Category Hierarchy Visualization

SemanticSchemas provides powerful tools to visualize category inheritance:

- **Special:SemanticSchemas/hierarchy**: Interactive hierarchy viewer
- **Parser function**: `{{#semanticschemas_hierarchy:}}` to embed on category pages
- **API endpoint**: `api.php?action=semanticschemas-hierarchy&category=NAME`

The visualization shows:
- Complete inheritance tree with parent/grandparent relationships
- All inherited properties grouped by source category
- Visual distinction between required (red) and optional (green) properties

📖 **[Complete Hierarchy Documentation](docs/user-guide/hierarchy-visualization.md)**

### Schema Operations

Export, import, and validation operations are available through the **Special:SemanticSchemas** page in the wiki interface:

- **Export**: Navigate to Special:SemanticSchemas → Export tab
- **Import**: Navigate to Special:SemanticSchemas → Import tab
- **Validate**: Navigate to Special:SemanticSchemas → Validate tab
- **Generate**: Navigate to Special:SemanticSchemas → Generate tab

#### Maintenance Scripts

For command-line operations:

```bash
# Regenerate templates and forms for all categories
php extensions/SemanticSchemas/maintenance/regenerateArtifacts.php

# Regenerate for a specific category
php extensions/SemanticSchemas/maintenance/regenerateArtifacts.php --category=Person
```

## Schema Format

Subobjects live in the `Subobject:` namespace. They define structured, repeatable groups of properties (think “Publication Author”, “Funding Line”, etc.) using the same Semantic annotations as categories (`[[Has required property::Property:Foo]]`, `[[Has optional property::Property:Bar]]`). Categories reference these subobjects via `[[Has required subobject::Subobject:Name]]` and `[[Has optional subobject::Subobject:Name]]`. Pages then include the auto-generated `Template:<Category>_<Subobject>` templates to store multiple entries.

### JSON Structure

```json
{
  "schemaVersion": "1.0",
  "categories": {
    "Person": {
      "parents": [],
      "label": "Person",
      "description": "An individual person",
      "properties": {
        "required": ["Has full name", "Has email"],
        "optional": ["Has biography"]
      },
      "display": {
        "header": ["Has full name"],
        "sections": [
          {
            "name": "Details",
            "properties": ["Has email"]
          }
        ]
      },
      "forms": {
        "sections": [
          {
            "name": "Basic Information",
            "properties": ["Has full name", "Has email"]
          }
        ]
      }
    }
  },
  "subobjects": {
    "PublicationAuthor": {
      "label": "Publication Author",
      "description": "Repeatable author entry",
      "properties": {
        "required": ["Has author", "Has author order"],
        "optional": ["Is co-first author", "Is corresponding author"]
      }
    }
  },
  "properties": {
    "Has full name": {
      "datatype": "Text",
      "label": "Full name",
      "description": "The person's full name"
    }
  }
}
```

## SMW Property Vocabulary

SemanticSchemas uses these SMW properties to store schema metadata on wiki pages:

- **Has parent category** (Type: Page) - Links to parent categories
- **Has required property** (Type: Page) - Required properties for this category
- **Has optional property** (Type: Page) - Optional properties for this category
- **Has required subobject** (Type: Page) - Required repeatable subobject definitions
- **Has optional subobject** (Type: Page) - Optional repeatable subobject definitions
- **Has subobject type** (Type: Page) - Tags each stored subobject instance with the Subobject schema it follows
- **Has display header property** (Type: Page) - Properties shown in the page header
- **Has display section name** (Type: Text) - Name of a display section
- **Has display section property** (Type: Page) - Properties in a display section
- **Has form section name** (Type: Text) - Name of a form section
- **Has form section property** (Type: Page) - Properties in a form section

## Generated Artifacts

For each category, SemanticSchemas generates:

1. **Template:{Category}/semantic** - Stores semantic data (auto-generated, always overwritten)
2. **Template:{Category}** - Dispatcher template (auto-generated, always overwritten)
3. **Template:{Category}/display** - Display template (generated once as stub, never overwritten)
4. **Template:{Category}_{Subobject}** - One per declared subobject for `{{#subobject:...}}` storage
5. **Form:{Category}** - PageForms form (auto-generated, always overwritten, including subobject widgets)

## Multiple Inheritance

Categories can have multiple parents. Property inheritance uses:

- **Union**: All ancestor properties are inherited
- **Required propagation**: If any ancestor marks a property as required, it stays required
- **Child override**: Child categories can override inherited settings

## License

This extension is licensed under GPL-3.0-or-later. See LICENSE file for details.

## Documentation

Comprehensive documentation is available in the [`docs/`](docs/) directory:

- **[Getting Started](docs/getting-started/QUICKSTART.md)** - Create your first ontology
- **[User Guide](docs/user-guide/)** - Complete guides for administrators
  - [Supported Properties](docs/user-guide/SUPPORTED_PROPERTIES.md)
  - [Display Properties](docs/user-guide/DISPLAY_PROPERTIES.md)
  - [Hierarchy Visualization](docs/user-guide/hierarchy-visualization.md)
- **[Developer Documentation](docs/developer/)** - For contributors and extenders
  - [Architecture Guide](docs/developer/architecture.md)
  - [Contributing Guide](docs/developer/contributing.md)
- **[Reference](docs/reference/)** - Quick reference and lookup

See the [Documentation Index](docs/README.md) for a complete overview.

## Support

- Report issues on GitHub
- Documentation: https://www.mediawiki.org/wiki/Extension:SemanticSchemas
- Check the [troubleshooting section](docs/user-guide/hierarchy-visualization.md#troubleshooting)

