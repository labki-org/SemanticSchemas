#!/bin/bash
#
# Run SemanticSchemas tests inside Docker MediaWiki environment.
#
# Unit tests use our vendor PHPUnit with standalone bootstrap.
# Integration tests use MW's PHPUnit test runner.
#
# Usage:
#   ./tests/scripts/run-docker-tests.sh [unit|integration] [phpunit-args...]
#
# Examples:
#   ./tests/scripts/run-docker-tests.sh                    # Run unit tests (default)
#   ./tests/scripts/run-docker-tests.sh unit               # Run unit tests
#   ./tests/scripts/run-docker-tests.sh integration        # Run integration tests
#   ./tests/scripts/run-docker-tests.sh unit --filter SchemaLoader
#   ./tests/scripts/run-docker-tests.sh integration --testdox
#

set -e

# Check if Docker is running
if ! docker compose ps wiki 2>/dev/null | grep -qE "(running|Up)"; then
    echo "ERROR: Docker wiki container is not running."
    echo "Start it with: docker compose up -d"
    exit 1
fi

# Determine test type
TEST_TYPE="${1:-unit}"
if [[ "$TEST_TYPE" == "unit" || "$TEST_TYPE" == "integration" ]]; then
    shift
else
    TEST_TYPE="unit"
fi

EXT_PATH="/mw-user-extensions/SemanticSchemas"

if [[ "$TEST_TYPE" == "unit" ]]; then
    echo "Running SemanticSchemas UNIT tests..."
    echo ""
    docker compose exec -T wiki php "$EXT_PATH/vendor/bin/phpunit" \
        --configuration "$EXT_PATH/tests/phpunit/unit.xml" \
        "$@"
else
    echo "Running SemanticSchemas INTEGRATION tests..."
    echo "(Tests marked @group Broken are excluded)"
    echo ""
    docker compose exec -T -w /var/www/html -e MW_INSTALL_PATH=/var/www/html wiki composer phpunit -- \
        --configuration "$EXT_PATH/tests/phpunit/integration.xml" \
        --exclude-group Broken \
        "$@"
fi

echo ""
echo "Tests completed."
