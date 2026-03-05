# SemanticSchemas Test Environment

This directory contains the configuration and scripts for running a local MediaWiki development environment for SemanticSchemas.

The environment uses a custom `Dockerfile` (in the repo root) based on `php:8.3-apache` that downloads MediaWiki from Git and installs Semantic MediaWiki + PageForms via Composer.

## Prerequisites

- Docker & Docker Compose

## Quick Start

The easiest way to start (or reset) the environment is using the helper script:

```bash
bash ./tests/scripts/reinstall_test_env.sh
```

This will:
1.  Destroy any existing containers and volumes.
2.  Build the MediaWiki Docker image.
3.  Start a fresh MediaWiki instance and Database.
4.  Run the entrypoint which handles `install.php`, `update.php`, and SMW `setupStore.php` automatically.
5.  Install the SemanticSchemas base configuration.

Once running, access the wiki at:
**http://localhost:8889**

- **Admin User**: `Admin`
- **Password**: `dockerpass`

## Configuration

The environment configuration is controlled by **`tests/LocalSettings.test.php`**.

This file is mounted into the container at `/mw-config/LocalSettings.user.php` and included by the entrypoint-generated `LocalSettings.php`. Use this file to:
- Enable/Disable extensions.
- Change extension settings.
- Configure debugging.

## Running Tests

```bash
# Unit tests (default)
bash tests/scripts/run-docker-tests.sh unit --testdox

# Integration tests
bash tests/scripts/run-docker-tests.sh integration --testdox

# Filter to specific test
bash tests/scripts/run-docker-tests.sh unit --filter SchemaLoader
```

## Common Operations

### View Logs
```bash
docker compose logs -f wiki
```

### Populate Test Data
To seed the wiki with test properties, templates, and forms:
```bash
bash tests/scripts/populate_test_data.sh
```

### Check Extension Status
```bash
docker compose exec wiki php maintenance/run.php eval 'echo ExtensionRegistry::getInstance()->isLoaded("SemanticSchemas") ? "Loaded" : "Not Loaded";'
```
