#!/usr/bin/env bash

set -euo pipefail

#
# Fix Parsoid compatibility issues in MediaWiki 1.44
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

echo "==> Fixing Parsoid compatibility issues..."

# Fix 1: SpecialVersion.php - getPFragmentHandlerKeys() method check
echo "  Fixing SpecialVersion.php..."
docker compose exec -T mediawiki bash -c "
  cd $CONTAINER_WIKI
  sed -i 's/foreach ( \$siteConfig->getPFragmentHandlerKeys() as \$key )/foreach ( (method_exists( \$siteConfig, \"getPFragmentHandlerKeys\" ) ? \$siteConfig->getPFragmentHandlerKeys() : []) as \$key )/' includes/specials/SpecialVersion.php
"

# Fix 2: DataAccess.php - preprocessWikitext method signature
echo "  Fixing DataAccess.php method signature..."
docker compose exec -T mediawiki bash -c "
  cd $CONTAINER_WIKI
  # Add return type to match interface (parameter stays untyped to match interface)
  sed -i '387s/) {/): string|PFragment {/' includes/parser/Parsoid/Config/DataAccess.php
"

echo "==> Clearing cache..."
docker compose exec -T mediawiki bash -c "rm -rf $CONTAINER_WIKI/cache/*"

echo ""
echo "✓ Parsoid compatibility fixes applied!"
echo "  Try accessing Special:Version now"

