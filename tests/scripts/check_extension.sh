#!/usr/bin/env bash

set -euo pipefail

#
# Diagnostic script to check why StructureSync isn't loading
#

get_cache_dir() {
    case "$(uname -s)" in
        Darwin*) echo "$HOME/Library/Caches/structuresync" ;;
        MINGW*|MSYS*|CYGWIN*)
            local appdata="${LOCALAPPDATA:-$HOME/AppData/Local}"
            echo "$appdata/structuresync"
            ;;
        *) echo "${XDG_CACHE_HOME:-$HOME/.cache}/structuresync" ;;
    esac
}

CACHE_BASE="$(get_cache_dir)"
MW_DIR="${MW_DIR:-$CACHE_BASE/mediawiki-StructureSync-test}"
CONTAINER_WIKI="/var/www/html/w"

if [ ! -d "$MW_DIR" ]; then
    echo "ERROR: MediaWiki directory not found at: $MW_DIR"
    exit 1
fi

cd "$MW_DIR"

echo "========================================"
echo "StructureSync Extension Diagnostic"
echo "========================================"
echo ""

echo "1. Checking extension directory..."
docker compose exec -T mediawiki bash -lc "
  if [ -d $CONTAINER_WIKI/extensions/StructureSync ]; then
    echo '✓ Extension directory exists'
    ls -la $CONTAINER_WIKI/extensions/StructureSync/ | head -5
  else
    echo '✗ Extension directory NOT found!'
    exit 1
  fi
"

echo ""
echo "2. Checking extension.json..."
docker compose exec -T mediawiki bash -lc "
  if [ -f $CONTAINER_WIKI/extensions/StructureSync/extension.json ]; then
    echo '✓ extension.json exists'
    php -r \"
      \\\$json = json_decode(file_get_contents('$CONTAINER_WIKI/extensions/StructureSync/extension.json'), true);
      if (json_last_error() === JSON_ERROR_NONE) {
        echo '✓ extension.json is valid JSON' . PHP_EOL;
        echo '  Name: ' . (\\\$json['name'] ?? 'missing') . PHP_EOL;
        echo '  Version: ' . (\\\$json['version'] ?? 'missing') . PHP_EOL;
      } else {
        echo '✗ extension.json is INVALID: ' . json_last_error_msg() . PHP_EOL;
        exit(1);
      }
    \"
  else
    echo '✗ extension.json NOT found!'
    exit 1
  fi
"

echo ""
echo "3. Checking composer dependencies..."
docker compose exec -T mediawiki bash -lc "
  if [ -d $CONTAINER_WIKI/extensions/StructureSync/vendor ]; then
    echo '✓ Composer vendor directory exists'
    if [ -f $CONTAINER_WIKI/extensions/StructureSync/vendor/autoload.php ]; then
      echo '✓ Composer autoload.php exists'
    else
      echo '✗ Composer autoload.php NOT found!'
    fi
  else
    echo '✗ Composer vendor directory NOT found!'
    echo '  Run: cd $CONTAINER_WIKI/extensions/StructureSync && composer install'
  fi
"

echo ""
echo "4. Checking LocalSettings.php..."
docker compose exec -T mediawiki bash -lc "
  if grep -q 'wfLoadExtension.*StructureSync' $CONTAINER_WIKI/LocalSettings.php; then
    echo '✓ StructureSync found in LocalSettings.php'
    grep 'StructureSync' $CONTAINER_WIKI/LocalSettings.php | head -3
  else
    echo '✗ StructureSync NOT found in LocalSettings.php!'
    exit 1
  fi
"

echo ""
echo "5. Checking extension dependencies..."
docker compose exec -T mediawiki bash -lc "
  if grep -q 'SemanticMediaWiki' $CONTAINER_WIKI/LocalSettings.php; then
    echo '✓ SemanticMediaWiki is loaded'
  else
    echo '✗ SemanticMediaWiki is NOT loaded!'
  fi
  if grep -q 'PageForms' $CONTAINER_WIKI/LocalSettings.php; then
    echo '✓ PageForms is loaded'
  else
    echo '✗ PageForms is NOT loaded!'
  fi
"

echo ""
echo "6. Testing extension loading..."
docker compose exec -T mediawiki php -r "
define('MW_INSTALL_PATH','$CONTAINER_WIKI');
\$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once MW_INSTALL_PATH . '/includes/WebStart.php';

\$registry = ExtensionRegistry::getInstance();
\$loaded = \$registry->getLoadedExtensions();

echo 'Loaded extensions: ' . implode(', ', \$loaded) . PHP_EOL;

if (in_array('StructureSync', \$loaded)) {
  echo '✓ StructureSync extension IS loaded!' . PHP_EOL;
} else {
  echo '✗ StructureSync extension is NOT loaded!' . PHP_EOL;
  echo '' . PHP_EOL;
  echo 'Checking for errors...' . PHP_EOL;
  
  // Try to load it manually to see error
  try {
    wfLoadExtension('StructureSync');
    echo '✓ Manual load succeeded' . PHP_EOL;
  } catch (Exception \$e) {
    echo '✗ Manual load failed: ' . \$e->getMessage() . PHP_EOL;
  }
}
"

echo ""
echo "========================================"
echo "Diagnostic complete!"
echo "========================================"

