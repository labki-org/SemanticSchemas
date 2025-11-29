# StructureSync Documentation

Welcome to the StructureSync documentation!

## Getting Started

- **[Main README](../README.md)** - Installation and basic usage
- **[Quick Reference](quick-reference.md)** - Common commands and workflows
- **[Implementation Notes](../IMPLEMENTATION.md)** - Technical architecture

## Features

### Category Hierarchy Visualization

- **[Hierarchy Visualization Guide](hierarchy-visualization.md)** - Complete guide to visualizing category inheritance
  - Using the Special page interface
  - Embedding visualizations in category pages
  - API access for developers
  - Understanding the display
  - Troubleshooting

- **[Form Preview Setup](form-preview-setup.md)** - Add live hierarchy preview to category creation forms
  - Dynamic preview as users add parent categories
  - Shows inheritance hierarchy
  - Displays inherited properties count
  - Step-by-step setup instructions

## For Administrators

### Schema Management

- Export/Import schemas (see [Main README](../README.md))
- Validate category structures
- Generate templates and forms
- Compare schema versions

### Testing

- **[Test Environment Setup](../tests/setup_mw_test_env.sh)** - Set up test environment
- **[Test Data](../tests/populate_test_data.sh)** - Populate test data with examples

## For Developers

### Architecture

The extension follows MediaWiki best practices:

- **Backend Services**: PHP services for hierarchy resolution (`CategoryHierarchyService`)
- **API Modules**: RESTful API endpoints (`ApiStructureSyncHierarchy`)
- **Frontend Components**: ResourceLoader modules for UI
- **Parser Functions**: WikiText integration (`{{#structuresync_hierarchy:}}`)

### Key Components

```
src/
├── Api/                    # API modules
│   └── ApiStructureSyncHierarchy.php
├── Service/                # Business logic services
│   └── CategoryHierarchyService.php
├── Schema/                 # Schema models and resolvers
│   ├── CategoryModel.php
│   └── InheritanceResolver.php
├── Parser/                 # Parser functions
│   └── DisplayParserFunctions.php
└── Special/                # Special pages
    └── SpecialStructureSync.php
```

### Extending

To add new features:

1. **Backend**: Create services in `src/Service/`
2. **API**: Add API modules in `src/Api/`
3. **Frontend**: Add ResourceLoader modules in `resources/`
4. **Register**: Update `extension.json`

## Contributing

When contributing, please:

1. Follow MediaWiki coding conventions
2. Add tests for new features
3. Update documentation
4. Test with multiple browsers
5. Verify no linter errors

## Need Help?

- Check the [troubleshooting section](hierarchy-visualization.md#troubleshooting)
- Review [test examples](../tests/populate_test_data.sh)
- Check [implementation notes](../IMPLEMENTATION.md)

---

**Note**: This documentation is for StructureSync version 0.1.0 and later.

