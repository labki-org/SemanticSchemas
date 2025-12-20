# SemanticSchemas Documentation

Welcome to the SemanticSchemas documentation! This directory contains comprehensive documentation organized by audience and topic.

## Documentation Structure

### Getting Started
New to SemanticSchemas? Start here:

- **[Quick Start Guide](getting-started/QUICKSTART.md)** - Create your first ontology and start using SemanticSchemas
- **[Installation Guide](getting-started/installation.md)** - Detailed installation instructions

### User Guide
For administrators and end users:

- **[Supported Properties](user-guide/SUPPORTED_PROPERTIES.md)** - Complete reference for all semantic properties
  - Category properties (inheritance, display, properties, subobjects)
  - Property properties (datatypes, constraints, display configuration)
  - Subobject properties (metadata, required/optional properties)
  - Examples and best practices

- **[Display Properties](user-guide/DISPLAY_PROPERTIES.md)** - Guide to property display configuration
  - Custom display templates
  - Display patterns and types
  - Rendering priority order

- **[Hierarchy Visualization Guide](user-guide/hierarchy-visualization.md)** - Complete guide to visualizing category inheritance
  - Using the Special page interface
  - Embedding visualizations in category pages
  - API access for developers
  - Understanding the display
  - Troubleshooting

- **[Form Preview Setup](user-guide/form-preview-setup.md)** - Add live hierarchy preview to category creation forms
  - Dynamic preview as users add parent categories
  - Shows inheritance hierarchy
  - Displays inherited properties count
  - Step-by-step setup instructions

### Developer Documentation
For developers extending SemanticSchemas:

- **[Architecture Guide](developer/architecture.md)** - Complete technical architecture documentation
  - Module structure and organization
  - Data flow diagrams
  - Key concepts and design decisions
  - Extension points

- **[Implementation Summary](developer/IMPLEMENTATION.md)** - What has been built and completed
  - Component overview
  - Architecture highlights
  - File structure reference

- **[Contributing Guide](developer/contributing.md)** - How to contribute to SemanticSchemas
  - Coding standards
  - Testing requirements
  - Pull request process

### Reference
Quick lookups and common tasks:

- **[Quick Reference](reference/quick-reference.md)** - Common commands and workflows

### Maintenance
For system administrators:

- **[Maintenance Tasks](maintenance/RUN_JOBS.md)** - Maintenance job commands and scripts

## Quick Links

### For Administrators

- [Schema Management](../README.md#usage) - Export/Import schemas
- [Validation](../README.md#validate) - Check schema consistency
- [Template Generation](../README.md#generate) - Regenerate templates and forms
- [Schema Comparison](../README.md#diff) - Compare schema versions

### For Developers

- [Architecture Overview](developer/architecture.md) - Understand the codebase structure
- [Extension Points](developer/architecture.md#extension-points) - How to extend SemanticSchemas
- [API Documentation](user-guide/hierarchy-visualization.md#api-usage) - API endpoints and usage
- [Testing Guide](../tests/README.md) - Set up and run tests

## Finding Documentation

### By Task

- **Installing SemanticSchemas** → [Getting Started](getting-started/QUICKSTART.md#installation)
- **Creating a category** → [Quick Start](getting-started/QUICKSTART.md#creating-your-first-ontology)
- **Visualizing hierarchy** → [Hierarchy Visualization](user-guide/hierarchy-visualization.md)
- **Customizing display** → [Display Properties](user-guide/DISPLAY_PROPERTIES.md)
- **Exporting schema** → [Main README](../README.md#maintenance-scripts)
- **Understanding architecture** → [Architecture Guide](developer/architecture.md)

### By Audience

- **New users** → Start with [Quick Start Guide](getting-started/QUICKSTART.md)
- **Administrators** → See [User Guide](user-guide/) section
- **Developers** → See [Developer Documentation](developer/) section
- **System admins** → See [Maintenance](maintenance/) section

## Related Resources

- **[Main README](../README.md)** - Extension overview and installation
- **[Test Environment Setup](../tests/README.md)** - Set up test environment
- **[Implementation Notes](developer/IMPLEMENTATION.md)** - Technical details

## Need Help?

- Check the [troubleshooting section](user-guide/hierarchy-visualization.md#troubleshooting)
- Review [test examples](../tests/scripts/populate_test_data.sh)
- Check [implementation notes](developer/IMPLEMENTATION.md)

---

**Note**: This documentation is for SemanticSchemas version 0.1.0 and later.
