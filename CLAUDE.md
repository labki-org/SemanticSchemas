# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

SemanticSchemas is a MediaWiki extension that provides schema-driven ontology management. It integrates with Semantic MediaWiki (SMW) and PageForms, treating schema definitions (JSON/YAML) as the single source of truth and automatically generating wiki pages (categories, properties, forms, templates) as compiled artifacts.

## Development Commands

```bash
# Run all checks (parallel-lint, minus-x check, phpcs)
composer test

# Auto-fix code formatting (minus-x fix, phpcbf)
composer run fix

# Static analysis with Phan
composer run phan

# Run PHPUnit tests
php vendor/bin/phpunit

# Run specific test file
php vendor/bin/phpunit tests/phpunit/YourTest.php
```

## Test Environment

```bash
# Setup/reset Docker test environment
bash ./tests/scripts/reinstall_test_env.sh

# Populate test data
bash tests/scripts/populate_test_data.sh

# View logs
docker compose logs -f wiki
```

Access wiki at http://localhost:8889 (Admin/dockerpass)

## Architecture

### Three-Template System
Each category generates three templates:
1. **Dispatcher** (`Template:<Category>`) - Entry point coordinating the other two
2. **Semantic** (`Template:<Category>/semantic`) - Stores SMW data with `{{#set:...}}`
3. **Display** (`Template:<Category>/display`) - Renders properties (user-editable stub)

### Key Data Flows

**Import**: Schema → SchemaLoader → SchemaValidator → WikiStore → Generators → StateManager

**Export**: Wiki Pages → WikiStore → SchemaExporter → JSON/YAML

### Core Directory Structure

- `src/Schema/` - Core models and operations (CategoryModel, PropertyModel, SubobjectModel, InheritanceResolver, SchemaValidator, SchemaLoader)
- `src/Store/` - Wiki persistence layer (WikiCategoryStore, WikiPropertyStore, PageCreator, StateManager)
- `src/Generator/` - Template/form generation (TemplateGenerator, FormGenerator, DisplayStubGenerator)
- `src/Special/` - Main admin UI (SpecialSemanticSchemas.php at ~1,345 lines)
- `src/Api/` - API endpoints for hierarchy data
- `src/Parser/` - Custom parser functions for display rendering

### Design Principles

- **Schema as Source of Truth** - All wiki state derives from schema definitions
- **Immutable Models** - CategoryModel, PropertyModel are read-only value objects
- **C3 Linearization** - Consistent multiple inheritance resolution via InheritanceResolver
- **Hash-based Dirty Detection** - SHA256 hashes in StateManager detect external modifications

### Entry Points

- **Special page**: `Special:SemanticSchemas` - Main admin interface for import/export/validation
- **API**: `api.php?action=semanticschemas-hierarchy` - Hierarchy data for visualization
- **Parser functions**: `{{#SemanticSchemasRenderAllProperties:Category}}`, `{{#SemanticSchemasRenderSection:Category|Section}}`
- **Maintenance scripts**: `maintenance/installConfig.php`, `maintenance/regenerateArtifacts.php`

### Custom Namespace

Defines namespace 3300/3301 (Subobject/Subobject_talk) for subobject storage with semantic annotations enabled.

### Base Configuration

The extension requires foundational wiki pages to be installed before use. These are defined in `resources/extension-config.json` and installed via `Special:SemanticSchemas` or `maintenance/installConfig.php`.

**Installation layers (in order):**
- Layer 0: Property display templates (`Template:Property/Default`, `Template:Property/Email`, `Template:Property/Link`)
- Layer 1: Property type declarations (registers datatypes with SMW)
- Layer 2: Full property annotations (labels, descriptions, constraints)
- Layer 3: Subobject definitions (`Display section`)
- Layer 4: Meta-categories (`Category`, `Property`, `Subobject`)

**Key files:**
- `resources/extension-config.json` - Defines all base configuration items
- `src/Schema/ExtensionConfigInstaller.php` - Handles layer-by-layer installation
- `src/Api/ApiSemanticSchemasInstall.php` - API endpoint for UI-driven installation

The `isInstalled()` method checks ALL layers before hiding the install button, ensuring partial installations can be completed.
