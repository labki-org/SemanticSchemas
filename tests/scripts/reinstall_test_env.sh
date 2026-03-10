#!/bin/bash
set -e

SKIP_INSTALL=false
for arg in "$@"; do
	case "$arg" in
		--skip-install) SKIP_INSTALL=true ;;
	esac
done

echo "==> Shutting down existing containers and removing volumes..."
docker compose down -v

echo "==> Building images..."
docker compose build

echo "==> Starting new environment..."
docker compose up -d

echo "==> Waiting for MW to be ready..."
for i in $(seq 1 60); do
	if docker compose exec -T wiki curl -sf http://localhost/api.php?action=query > /dev/null 2>&1; then
		echo "MW is ready."
		break
	fi
	if [ "$i" -eq 60 ]; then
		echo "ERROR: MediaWiki did not become ready in time."
		docker compose logs wiki
		exit 1
	fi
	sleep 2
done

if [ "$SKIP_INSTALL" = true ]; then
	echo "==> Skipping config installation (--skip-install)"
else
	echo "==> Running SemanticSchemas Config Installer..."
	docker compose exec wiki php /mw-user-extensions/SemanticSchemas/maintenance/installConfig.php
fi

echo "==> Environment ready!"
echo "Visit http://localhost:8889"
