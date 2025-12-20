# StructureSync Test Environment

This directory contains the configuration and scripts for running a local MediaWiki development environment for StructureSync, built on top of the [**Labki Platform**](https://github.com/labki-org/labki-platform).

## Prerequisites

- Docker & Docker Compose
- `ghcr.io/labki-org/labki-platform:latest` image (ensure you have rights to pull or build locally).

## Quick Start

The easiest way to start (or reset) the environment is using the helper script:

```bash
bash ./tests/scripts/reinstall_test_env.sh
```

or in wsl

```bash
chmod +x ./tests/scripts/reinstall_test_env.sh
./tests/scripts/reinstall_test_env.sh
```

This will:
1.  Destroy any existing containers and volumes.
2.  Start a fresh MediaWiki instance and Database.
3.  Mount the extension and configuration (removing conflicting `vendor/` directories).
4.  Restart the container to load extensions.

Once running, access the wiki at:
**http://localhost:8889**

- **Admin User**: `Admin`
- **Password**: `dockerpass`

## Configuration

The environment configuration is controlled by **`tests/LocalSettings.test.php`**.

This file is mounted into the container at `/mw-config/LocalSettings.user.php` and is automatically included by the platform. Use this file to:
- Enable/Disable `StructureSync` (`wfLoadExtension(...)`).
- Change extension settings.
- Configure debugging.

> [!NOTE]
> StructureSync requires `load_composer_autoloader` to be **false** in `extension.json` to reside peacefully with the platform dependencies.

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
docker compose exec wiki php maintenance/run.php eval 'echo ExtensionRegistry::getInstance()->isLoaded("StructureSync") ? "Loaded" : "Not Loaded";'
```
