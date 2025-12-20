#!/bin/bash
set -e

# Determine script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"

echo "==> Shutting down existing containers and removing volumes..."
docker compose down -v

echo "==> Starting new environment..."
docker compose up -d

echo "==> Waiting for MW to be ready..."
echo "==> Waiting for MW to be ready (giving DB time to init)..."
sleep 20

# echo "==> Populating Test Data..."
# # set MW_DIR to repo root so populate script uses current docker-compose.yml
# export MW_DIR="$REPO_ROOT"
# bash "$SCRIPT_DIR/populate_test_data.sh"

echo "==> Running StructureSync Config Installer..."
docker compose exec wiki php /mw-user-extensions/StructureSync/maintenance/installConfig.php

# echo "==> Running SMW setupStore (manual trigger)..."
# docker compose exec wiki php extensions/SemanticMediaWiki/maintenance/setupStore.php --nochecks

echo "==> Environment ready!"
echo "Visit http://localhost:8889"
