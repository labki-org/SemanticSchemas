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

Run the MediaWiki update script to set up the extension:

```bash
php maintenance/update.php
```

### Step 5: Install Base Configuration

SemanticSchemas requires a set of foundational wiki pages (meta-categories, meta-properties, and display templates) to function properly. These pages define the schema structure that the extension uses to manage your ontology.

**Via the Web Interface (Recommended):**

1. Navigate to `Special:SemanticSchemas`
2. You will see a banner prompting you to install the base configuration
3. Click **"Install Base Configuration"**
4. The automated installer will create pages in 5 layers:
   - **Layer 0: Templates** - Property display templates (e.g., `Template:Property/Default`)
   - **Layer 1: Property Types** - Registers property datatypes with SMW
   - **Layer 2: Property Annotations** - Adds labels, descriptions, and constraints
   - **Layer 3: Subobjects** - Creates subobject type definitions
   - **Layer 4: Categories** - Creates meta-categories (Category, Property, Subobject)
5. Wait for each layer to complete before the next begins (the UI handles this automatically)

**Via Command Line:**

Alternatively, you can install via the maintenance script:

```bash
php extensions/SemanticSchemas/maintenance/installConfig.php
```

**What Gets Installed:**

The base configuration includes:
- **Templates:** `Template:Property/Default`, `Template:Property/Email`, `Template:Property/Link`
- **Properties:** ~25 meta-properties like `Has type`, `Has description`, etc.
- **Subobjects:** `Display section` for organizing property display
- **Categories:** `Category`, `Property`, `Subobject` meta-categories

These pages are defined in `resources/extension-config.json` and serve as the foundation for all schema management.

> **Note:** If installation is interrupted or fails partway through, the "Install Base Configuration" button will remain visible until all layers complete successfully. You can safely re-run the installation to complete any missing layers.

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

If the base configuration installation fails or is interrupted:

- **Install button still visible after installation:** This means some layers didn't complete. Simply click "Install Base Configuration" again to retry. The installer checks all layers (templates, properties, subobjects, categories) and will only mark installation as complete when everything is present.

- **Layer fails with errors:** Check the MediaWiki error log for details. Common causes include:
  - Database permission issues
  - SMW job queue not processing (run `php maintenance/runJobs.php`)
  - Insufficient PHP memory (increase `memory_limit` in php.ini)

- **"Waiting for SMW jobs" stuck:** The installer waits for Semantic MediaWiki's job queue to process between layers. If this takes too long:
  ```bash
  php maintenance/runJobs.php --maxjobs=100
  ```

- **Manual recovery:** If the web installer consistently fails, use the maintenance script:
  ```bash
  php extensions/SemanticSchemas/maintenance/installConfig.php --force
  ```

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

