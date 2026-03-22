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
# Setup/reset Docker test environment (see --help for all flags)
bash tests/scripts/reinstall_test_env.sh

# Full setup: install + drain jobs + stop background jobrunner
bash tests/scripts/reinstall_test_env.sh --run-jobs --no-jobrunner

# Bare wiki for testing install guidance UX
bash tests/scripts/reinstall_test_env.sh --skip-install --no-jobrunner

# Populate test data
bash tests/scripts/populate_test_data.sh

# View logs
docker compose logs -f wiki
```

Access wiki at http://localhost:8889 (Admin/DockerPass123!)

## Architecture

### Modular Template System
Each category generates three templates:
1. **Semantic** (`Template:<Category>/semantic`) — Stores **own** declared properties only via `{{#set:...}}` + stamps `[[Category:<Category>]]`
2. **Dispatcher** (`Template:<Category>`) — Chains ancestor semantic templates, then calls display. For a category with parents, the dispatcher calls `Parent/semantic`, then `Child/semantic`, then `Child/display`
3. **Display** (`Template:<Category>/display`) — Renders all properties grouped by origin category (user-editable stub)

### Inheritance vs Composition

**Inheritance** (IS-A, schema-time): Declared via `parents: [...]` in the schema. A child category's dispatcher chains all ancestor semantic templates automatically. One form, one display, one page template call. Properties grouped under category subheadings.

**Composition** (HAS-ROLES-IN, page-time): Multiple category dispatchers placed on a single wiki page. Each category maintains its own display, form, and semantic storage independently. Users edit each category via `Special:FormEdit/CategoryName/PageName`.

Both rely on modular semantic templates (own-properties-only + own category stamp):
- Inheritance: pre-generated dispatcher assembles the chain
- Composition: the page itself assembles by including multiple dispatchers

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

- **Special page**: `Special:SemanticSchemas` - Main admin interface for import/export/validation/create
- **API**: `api.php?action=semanticschemas-hierarchy` - Hierarchy data for visualization
- **Parser functions**: `{{#SemanticSchemasRenderAllProperties:Category}}`, `{{#SemanticSchemasRenderSection:Category|Section}}`
- **Maintenance scripts**: `maintenance/regenerateArtifacts.php`

### Custom Namespace

Defines namespace 3300/3301 (Subobject/Subobject_talk) for subobject storage with semantic annotations enabled.

### Base Configuration

The extension requires foundational wiki pages (meta-properties, meta-categories, display templates) to be installed before use. These are managed by SMW's built-in content import system.

**How it works:**
- `resources/base-config/semanticschemas.vocab.json` — JSON manifest declaring all base config pages
- `resources/base-config/{templates,properties,subobjects,categories}/` — `.wikitext` source files
- `src/Hooks/SemanticSchemasSetupHooks.php` registers the base-config directory via `$smwgImportFileDirs`
- Running `php maintenance/run.php update` triggers SMW's importer, which creates/updates all pages automatically
- `Special:SemanticSchemas` checks for sentinel page `Property:Has type` to show/hide the install guidance banner
