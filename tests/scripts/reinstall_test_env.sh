#!/bin/bash
#
# Reinstall the SemanticSchemas Docker test environment from scratch.
#
# Tears down all containers/volumes and rebuilds a fresh MediaWiki instance
# with SMW and SemanticSchemas. Mirrors the real admin installation flow:
#   1. Install MediaWiki + database
#   2. Set up SMW store (via entrypoint)
#   3. Run update.php (triggers SemanticSchemas base config auto-install)
#   4. Optionally drain the job queue so SMW annotations are processed
#
# Flags:
#   --skip-install   Skip update.php — wiki starts with no base config.
#                    Use to test the Special:SemanticSchemas install guidance UX.
#   --run-jobs       Drain the job queue after install. Required for SMW to
#                    process property types/annotations. Without this, artifact
#                    generation may produce incorrect results (e.g. wrong input types).
#   --no-jobrunner   Stop the background jobrunner container after setup.
#                    Useful when you want to control job execution manually.
#
# Examples:
#   ./reinstall_test_env.sh                          # Default: install + jobrunner running
#   ./reinstall_test_env.sh --run-jobs --no-jobrunner # Full setup, drain jobs, no background runner
#   ./reinstall_test_env.sh --skip-install --no-jobrunner  # Bare wiki for install UX testing
#
set -e

SKIP_INSTALL=false
NO_JOBRUNNER=false
RUN_JOBS=false
for arg in "$@"; do
	case "$arg" in
		--skip-install) SKIP_INSTALL=true ;;
		--no-jobrunner) NO_JOBRUNNER=true ;;
		--run-jobs) RUN_JOBS=true ;;
		--help|-h) sed -n '2,/^set -e/{ /^#/s/^# \?//p }' "$0"; exit 0 ;;
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
	echo "==> Skipping update.php (--skip-install) — base config will NOT be installed."
	echo "    Use this mode to test the Special:SemanticSchemas install guidance UX."
else
	# Base config is auto-installed during update.php via SMW hook.
	echo "==> Running update.php (triggers SemanticSchemas auto-install)..."
	docker compose exec wiki php maintenance/run.php update --quick
fi

if [ "$RUN_JOBS" = true ]; then
	echo "==> Running job queue (--run-jobs)..."
	docker compose exec wiki php maintenance/run.php runJobs
fi

if [ "$NO_JOBRUNNER" = true ]; then
	echo "==> Stopping jobrunner (--no-jobrunner)..."
	docker compose stop jobrunner
fi

echo "==> Environment ready!"
echo "Visit http://localhost:8889"
