# StructureSync Test Environment

This directory contains scripts to set up a Docker-based MediaWiki test environment for StructureSync.

## Quick Start

```bash
# Set up the test environment (takes 5-10 minutes first time)
./tests/scripts/setup_mw_test_env.sh

# Populate with test data
./tests/scripts/populate_test_data.sh

# Or do both in one command
POPULATE_TEST_DATA=1 ./tests/scripts/setup_mw_test_env.sh
```

## What Gets Installed

The setup script creates a complete MediaWiki test environment with:

- **MediaWiki 1.39** (matching extension requirements)
- **SemanticMediaWiki 4.x** (required dependency)
- **PageForms 5.7** (required dependency)
- **StructureSync extension** (mounted from your local directory)
- **SQLite database** (lightweight, file-based)
- **Docker containers** (mediawiki, no external DB needed)

## Test Environment Details

- **URL**: http://localhost:8889/w
- **Admin User**: Admin
- **Admin Password**: dockerpass
- **Port**: 8889 (configurable via MW_PORT)
- **Cache Directory**: Platform-specific (see `get_cache_dir()` in script)
  - Linux: `~/.cache/structuresync/mediawiki-StructureSync-test`
  - macOS: `~/Library/Caches/structuresync/mediawiki-StructureSync-test`
  - Windows: `%LOCALAPPDATA%\structuresync\mediawiki-StructureSync-test`

## Scripts

### setup_mw_test_env.sh

Main setup script that:
1. Clones/updates MediaWiki core (REL1_39)
2. Starts Docker containers
3. Installs MediaWiki with SQLite
4. Installs SemanticMediaWiki and PageForms
5. Mounts StructureSync extension
6. Installs StructureSync's Composer dependencies (symfony/yaml)
7. Creates basic schema properties
8. Sets up logging

**Environment Variables**:
- `MW_DIR` - Override MediaWiki directory (default: cache directory)
- `MW_PORT` - Override port (default: 8889)
- `POPULATE_TEST_DATA` - Auto-populate test data if set to "1"

### populate_test_data.sh

Populates the test environment with sample data:
- 7 test properties (Has full name, Has email, Has advisor, etc.)
- 3 categories with schema (Person, LabMember, GraduateStudent)
- Templates and forms for all categories
- 2 example pages (John Doe, Jane Smith)
- Exported test schema (tests/test-schema.json)

Can be run standalone or via `POPULATE_TEST_DATA=1 setup_mw_test_env.sh`

## Common Tasks

### Access the Wiki
```bash
open http://localhost:8889/w
# Or visit http://localhost:8889/w in your browser
```

### View StructureSync Special Page
```bash
open http://localhost:8889/w/index.php/Special:StructureSync
```

### Run Maintenance Scripts
```bash
# Get shell in container
cd ~/.cache/structuresync/mediawiki-StructureSync-test
docker compose exec mediawiki bash

# Inside container:
cd /var/www/html/w/extensions/StructureSync

# Export schema
php maintenance/exportOntology.php --format=json

# Import schema
php maintenance/importOntology.php --input=tests/fixtures/test-schema.json --dry-run

# Validate ontology
php maintenance/validateOntology.php --show-warnings

# Regenerate artifacts
php maintenance/regenerateArtifacts.php --category=Person --generate-display
```

### View Logs
```bash
# Extension logs
tail -f logs/structuresync.log

# MediaWiki logs
cd ~/.cache/structuresync/mediawiki-StructureSync-test
docker compose logs -f mediawiki
```

### Stop Environment
```bash
cd ~/.cache/structuresync/mediawiki-StructureSync-test
docker compose down
```

### Reset Environment
```bash
# This will delete all data and start fresh
rm -rf ~/.cache/structuresync/mediawiki-StructureSync-test
./tests/scripts/setup_mw_test_env.sh
```

### Modify Extension Code
Changes to files in the StructureSync extension directory are immediately reflected in the Docker container (volume mount). Just refresh the page or run scripts to see changes.

To reload PHP changes:
```bash
cd ~/.cache/structuresync/mediawiki-StructureSync-test
docker compose restart mediawiki
```

## Troubleshooting

### Port Already in Use
If port 8889 is busy:
```bash
MW_PORT=8890 ./tests/scripts/setup_mw_test_env.sh
```

### Permission Errors
Ensure your user can run Docker without sudo:
```bash
sudo usermod -aG docker $USER
# Log out and back in
```

### Extension Not Loading
Check LocalSettings.php:
```bash
cd ~/.cache/structuresync/mediawiki-StructureSync-test
docker compose exec mediawiki cat /var/www/html/w/LocalSettings.php | grep StructureSync
```

Should show:
```php
wfLoadExtension( "StructureSync" );
```

### Composer Dependencies Missing
If symfony/yaml is missing:
```bash
cd ~/.cache/structuresync/mediawiki-StructureSync-test
docker compose exec mediawiki bash -c "cd /var/www/html/w/extensions/StructureSync && composer install"
```

### Database Issues
Reset the database:
```bash
cd ~/.cache/structuresync/mediawiki-StructureSync-test
docker compose down -v
./tests/setup_mw_test_env.sh
```

## Test Schema Example

After running `populate_test_data.sh`, you'll have a test schema at `tests/fixtures/test-schema.json`:

```json
{
  "schemaVersion": "1.0",
  "categories": {
    "Person": {
      "parents": [],
      "properties": {
        "required": ["Has full name", "Has email"],
        "optional": ["Has phone", "Has biography"]
      },
      "display": {
        "sections": [
          {
            "name": "Contact Information",
            "properties": ["Has email", "Has phone"]
          }
        ]
      }
    },
    "GraduateStudent": {
      "parents": ["Person", "LabMember"],
      "properties": {
        "required": ["Has advisor"],
        "optional": ["Has cohort year"]
      }
    }
  }
}
```

## Testing Workflow

1. **Make changes** to StructureSync code
2. **Test via web UI** at http://localhost:8889/w/index.php/Special:StructureSync
3. **Test via CLI** using maintenance scripts
4. **Check logs** in `logs/structuresync.log`
5. **Iterate** without restarting (just refresh)

## Architecture Notes

- Uses Docker Compose from MediaWiki core (docker-compose.yml)
- SQLite for simplicity (no separate DB container)
- Extension mounted as volume (live code editing)
- Logs mounted as volume (easy access from host)
- User/group IDs matched (no permission issues)

## Cleaning Up

```bash
# Stop and remove containers
cd ~/.cache/structuresync/mediawiki-StructureSync-test
docker compose down -v

# Remove entire test environment
rm -rf ~/.cache/structuresync/

# Remove logs
rm -rf logs/
```

## CI/CD Usage

The setup script can be used in CI pipelines:

```bash
# Non-interactive mode
export CI=1
./tests/scripts/setup_mw_test_env.sh

# Run tests
./tests/scripts/run_tests.sh  # (if you create test scripts)
```

