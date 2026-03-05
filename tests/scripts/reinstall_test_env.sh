#!/bin/bash
set -e

# Determine script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"

echo "==> Shutting down existing containers and removing volumes..."
docker compose down -v

echo "==> Building images..."
docker compose build

echo "==> Starting new environment..."
docker compose up -d

echo "==> Waiting for MW to be ready (entrypoint handles first-run setup)..."
sleep 30

echo "==> Running SemanticSchemas Config Installer..."
docker compose exec wiki php /mw-user-extensions/SemanticSchemas/maintenance/installConfig.php

echo "==> Environment ready!"
echo "Visit http://localhost:8889"
