#!/bin/bash
set -e

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

echo "==> Environment ready!"
echo "Visit http://localhost:8889"
