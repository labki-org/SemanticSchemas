# Installation Guide

This guide provides detailed installation instructions for SemanticSchemas.

## Requirements

- MediaWiki 1.39+
- PHP 7.4+
- [SemanticMediaWiki](https://www.semantic-mediawiki.org/)
- [PageForms](https://www.mediawiki.org/wiki/Extension:Page_Forms)
- [ParserFunctions](https://www.mediawiki.org/wiki/Extension:ParserFunctions)

## Installation Steps

### Step 1: Clone the Extension

Clone this repository to your MediaWiki `extensions` directory:

```bash
cd extensions/
git clone https://github.com/yourrepo/SemanticSchemas.git
```

### Step 2: Install Composer Dependencies

Install PHP dependencies via Composer:

```bash
cd SemanticSchemas
composer install --no-dev
```

### Step 3: Enable in LocalSettings.php

Add the following line to your `LocalSettings.php`:

```php
wfLoadExtension( 'SemanticSchemas' );
```

### Step 4: Run Database Updates

Run the MediaWiki update script to set up the extension and install base configuration:

```bash
php maintenance/update.php
```

This automatically installs all required base configuration pages via SMW's built-in content importer:
- **Templates:** `Template:Property/Default`, `Template:Property/Email`, `Template:Property/Link`, `Template:Property/Page`
- **Properties:** ~24 meta-properties like `Has type`, `Has description`, etc.
- **Subobjects:** `Display section` for organizing property display
- **Categories:** `Category`, `Property`, `Subobject` meta-categories

These pages are defined in `resources/base-config/` and serve as the foundation for all schema management.

> **Note:** If base config pages are missing or outdated, re-running `update.php` will create or replace them automatically.

## Configuration

No additional configuration is required for basic usage. The extension works out of the box once the base configuration is installed.

## Verification

To verify the installation was successful:

1. Visit `Special:Version` and confirm SemanticSchemas is listed
2. Visit `Special:SemanticSchemas` to access the main interface
3. Check that SemanticMediaWiki and PageForms are also installed and enabled
4. Verify the base configuration is installed:
   - No "Install Base Configuration" banner should appear on the overview page
   - `Property:Has type` should exist (visit `Special:Browse/Property:Has_type`)
   - `Template:Property/Default` should exist
   - `Category:Category` should exist

## Troubleshooting

### Base Configuration Installation Issues

If base configuration pages are missing after running `update.php`:

- **Banner still visible on Special:SemanticSchemas:** Re-run `php maintenance/run.php update` to trigger SMW's content importer. The importer creates any missing pages automatically.

- **Import errors during update.php:** Check the MediaWiki error log for details. Common causes include:
  - Database permission issues
  - SMW not fully initialized (ensure SMW's setup has completed)
  - Insufficient PHP memory (increase `memory_limit` in php.ini)

### Extension Not Appearing

- Check that the extension directory is readable
- Verify `LocalSettings.php` has the correct path
- Check MediaWiki error logs

### Composer Dependencies Missing

If you see errors about missing dependencies:

```bash
cd extensions/SemanticSchemas
composer install --no-dev
```

### Database Update Errors

If the update script fails:

- Ensure you have proper database permissions
- Check MediaWiki version compatibility
- Review error messages for specific issues

## Next Steps

After installation:

1. See the [Quick Start Guide](QUICKSTART.md) to create your first ontology
2. Read the [User Guide](../user-guide/) for detailed usage instructions
3. Check the [Main README](../../README.md) for overview and examples

