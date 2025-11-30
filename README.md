# StructureSync

StructureSync is a MediaWiki extension that treats Categories and Properties as an ontology backbone, providing:

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

## Installation

1. Clone this repository to your MediaWiki `extensions` directory:
   ```bash
   cd extensions/
   git clone https://github.com/yourrepo/StructureSync.git
   ```

2. Install Composer dependencies:
   ```bash
   cd StructureSync
   composer install --no-dev
   ```

3. Add to your `LocalSettings.php`:
   ```php
   wfLoadExtension( 'StructureSync' );
   ```

4. Run the update script:
   ```bash
   php maintenance/update.php
   ```

## Configuration

No configuration is required for basic usage. The extension works out of the box once installed.

## Usage

### Special Page

Access the main interface at `Special:StructureSync` which provides:

- **Overview**: View all categories and their status
- **Export**: Export your ontology schema to JSON or YAML
- **Import**: Import a schema from file or text
- **Validate**: Check schema consistency
- **Generate**: Regenerate templates and forms
- **Diff**: Compare schema file with current wiki state
- **Hierarchy**: Visualize category inheritance trees and inherited properties

### Category Hierarchy Visualization

StructureSync provides powerful tools to visualize category inheritance:

- **Special:StructureSync/hierarchy**: Interactive hierarchy viewer
- **Parser function**: `{{#structuresync_hierarchy:}}` to embed on category pages
- **API endpoint**: `api.php?action=structuresync-hierarchy&category=NAME`

The visualization shows:
- Complete inheritance tree with parent/grandparent relationships
- All inherited properties grouped by source category
- Visual distinction between required (red) and optional (green) properties

📖 **[Complete Hierarchy Documentation](docs/hierarchy-visualization.md)**

### Maintenance Scripts

#### Export Schema
```bash
php extensions/StructureSync/maintenance/exportOntology.php --format=json --output=schema.json
```

#### Import Schema
```bash
php extensions/StructureSync/maintenance/importOntology.php --input=schema.json
```

#### Validate
```bash
php extensions/StructureSync/maintenance/validateOntology.php
```

#### Regenerate Artifacts
```bash
php extensions/StructureSync/maintenance/regenerateArtifacts.php --category=Person
```

## Schema Format

Subobjects live in the `Subobject:` namespace. They define structured, repeatable groups of properties (think “Publication Author”, “Funding Line”, etc.) using the same Semantic annotations as categories (`[[Has required property::Property:Foo]]`, `[[Has optional property::Property:Bar]]`). Categories reference these subobjects via `[[Has required subgroup::Subobject:Name]]` and `[[Has optional subgroup::Subobject:Name]]`. Pages then include the auto-generated `Template:<Category>_<Subobject>` templates to store multiple entries.

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

StructureSync uses these SMW properties to store schema metadata on wiki pages:

- **Has parent category** (Type: Page) - Links to parent categories
- **Has required property** (Type: Page) - Required properties for this category
- **Has optional property** (Type: Page) - Optional properties for this category
- **Has required subgroup** (Type: Page) - Required repeatable subobject definitions
- **Has optional subgroup** (Type: Page) - Optional repeatable subobject definitions
- **Has subgroup type** (Type: Page) - Tags each stored subobject instance with the Subobject schema it follows
- **Has display header property** (Type: Page) - Properties shown in the page header
- **Has display section name** (Type: Text) - Name of a display section
- **Has display section property** (Type: Page) - Properties in a display section
- **Has form section name** (Type: Text) - Name of a form section
- **Has form section property** (Type: Page) - Properties in a form section

## Generated Artifacts

For each category, StructureSync generates:

1. **Template:{Category}/semantic** - Stores semantic data (auto-generated, always overwritten)
2. **Template:{Category}** - Dispatcher template (auto-generated, always overwritten)
3. **Template:{Category}/display** - Display template (generated once as stub, never overwritten)
4. **Template:{Category}_{Subobject}** - One per declared subgroup for `{{#subobject:...}}` storage
5. **Form:{Category}** - PageForms form (auto-generated, always overwritten, including subgroup widgets)

## Multiple Inheritance

Categories can have multiple parents. Property inheritance uses:

- **Union**: All ancestor properties are inherited
- **Required propagation**: If any ancestor marks a property as required, it stays required
- **Child override**: Child categories can override inherited settings

## License

This extension is licensed under GPL-3.0-or-later. See LICENSE file for details.

## Support

- Report issues on GitHub
- Documentation: https://www.mediawiki.org/wiki/Extension:StructureSync

