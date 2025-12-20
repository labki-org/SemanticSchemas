# Installation Guide

This guide provides detailed installation instructions for SemanticSchemas.

## Requirements

- MediaWiki 1.39+
- PHP 7.4+
- [SemanticMediaWiki](https://www.semantic-mediawiki.org/)
- [PageForms](https://www.mediawiki.org/wiki/Extension:Page_Forms)

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

## Configuration

No configuration is required for basic usage. The extension works out of the box once installed.

## Verification

To verify the installation was successful:

1. Visit `Special:Version` and confirm SemanticSchemas is listed
2. Visit `Special:SemanticSchemas` to access the main interface
3. Check that SemanticMediaWiki and PageForms are also installed and enabled

## Troubleshooting

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

