#!/usr/bin/env bash
set -euo pipefail

#
# StructureSync — MediaWiki test environment setup script (SQLite)
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

# ---------------- CONFIG ----------------

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CACHE_BASE="$(get_cache_dir)"
MW_DIR="${MW_DIR:-$CACHE_BASE/mediawiki-StructureSync-test}"
EXT_DIR="${EXT_DIR:-$SCRIPT_DIR/..}"
MW_BRANCH=REL1_44
MW_PORT=8889
MW_ADMIN_USER=Admin
MW_ADMIN_PASS=dockerpass

CONTAINER_WIKI="/var/www/html/w"
CONTAINER_LOG_DIR="/var/log/structuresync"
CONTAINER_LOG_FILE="$CONTAINER_LOG_DIR/structuresync.log"
LOG_DIR="$EXT_DIR/logs"

echo "==> Using MW directory: $MW_DIR"

# ---------------- RESET ENV (FULL) ----------------

if [ -d "$MW_DIR" ]; then
    cd "$MW_DIR"
    echo "==> Shutting down existing containers and removing volumes..."
    docker compose down -v || true
fi

echo "==> Ensuring MediaWiki core exists..."
if [ ! -d "$MW_DIR/.git" ]; then
    mkdir -p "$(dirname "$MW_DIR")"
    git clone https://gerrit.wikimedia.org/r/mediawiki/core.git "$MW_DIR"
fi

cd "$MW_DIR"

echo "==> Resetting MediaWiki core to clean $MW_BRANCH..."
git fetch --all
git checkout "$MW_BRANCH"
git reset --hard "$MW_BRANCH"
git clean -fdx
git submodule update --init --recursive || true

# ---------------- DOCKER ENV ----------------

cat > "$MW_DIR/.env" <<EOF
MW_SCRIPT_PATH=/w
MW_SERVER=http://localhost:$MW_PORT
MW_DOCKER_PORT=$MW_PORT
MEDIAWIKI_USER=$MW_ADMIN_USER
MEDIAWIKI_PASSWORD=$MW_ADMIN_PASS
MW_DOCKER_UID=$(id -u)
MW_DOCKER_GID=$(id -g)
EOF

echo "==> Starting MW containers..."
docker compose up -d

echo "==> Installing composer deps (core only)..."
docker compose exec -T mediawiki composer update --no-interaction --no-progress

echo "==> Running MediaWiki install script..."
# LocalSettings.php must not reference SMW yet
docker compose exec -T mediawiki bash -lc "rm -f $CONTAINER_WIKI/LocalSettings.php"
docker compose exec -T mediawiki /bin/bash /docker/install.sh

echo "==> Fixing SQLite permissions..."
docker compose exec -T mediawiki bash -lc "chmod -R o+rwx $CONTAINER_WIKI/cache/sqlite || true"

# ---------------- EXTENSION & LOG MOUNTS ----------------

echo "==> Preparing host log directory..."
mkdir -p "$LOG_DIR"
chmod 777 "$LOG_DIR" || true

echo "==> Writing docker-compose.override.yml..."
cat > "$MW_DIR/docker-compose.override.yml" <<EOF
services:
  mediawiki:
    user: "$(id -u):$(id -g)"
    volumes:
      - $EXT_DIR:/var/www/html/w/extensions/StructureSync:cached
      - $LOG_DIR:$CONTAINER_LOG_DIR
EOF

echo "==> Restarting with StructureSync mount..."
docker compose down
docker compose up -d

# ---------------- INSTALL SEMANTIC MEDIAWIKI ----------------

echo "==> Installing SMW via composer..."
docker compose exec -T mediawiki bash -lc "
  cd $CONTAINER_WIKI
  composer require mediawiki/semantic-media-wiki:'~6.0' --no-progress
"

echo "==> Enabling SMW..."
docker compose exec -T mediawiki bash -lc "
  sed -i '/SemanticMediaWiki/d' $CONTAINER_WIKI/LocalSettings.php
  {
    echo ''
    echo '// === Semantic MediaWiki ==='
    echo 'wfLoadExtension(\"SemanticMediaWiki\");'
    echo 'enableSemantics(\"localhost\");'
    echo ''
    echo '// Disable SMW change propagation protection'
    echo '// This prevents pages from being locked during semantic data updates.'
    echo '// Without this, creating categories with parent categories triggers'
    echo '// change propagation on Category:Category and other parent categories,'
    echo '// causing edit locks that persist even after job queue completes.'
    echo '// This is safe for development and most production wikis.'
    echo '\$smwgChangePropagationProtection = false;'
    echo ''
    echo '// Prevent transaction errors with PageForms + SMW'
    echo '// Disabling deferred updates prevents transaction conflicts during form submission'
    echo '\$smwgEnabledDeferredUpdate = false;'
    echo '\$smwgQMaxInlineLimit = 500;'
  } >> $CONTAINER_WIKI/LocalSettings.php
"

echo "==> Running MW updater for SMW..."
docker compose exec -T mediawiki php maintenance/update.php --quick

echo "==> Initializing SMW store..."
docker compose exec -T mediawiki php extensions/SemanticMediaWiki/maintenance/setupStore.php --nochecks

# ---------------- INSTALL PAGEFORMS ----------------

echo "==> Installing PageForms..."
docker compose exec -T mediawiki bash -lc "
  cd $CONTAINER_WIKI/extensions
  if [ ! -d PageForms ]; then
    git clone https://gerrit.wikimedia.org/r/mediawiki/extensions/PageForms.git PageForms
  fi
  cd PageForms
  git fetch --all
  git checkout REL1_44 || git checkout master
  git submodule update --init --recursive || true
  composer install --no-dev --no-progress || true
"

echo "==> Enabling PageForms..."
docker compose exec -T mediawiki bash -lc "
  sed -i '/PageForms/d' $CONTAINER_WIKI/LocalSettings.php
  {
    echo ''
    echo '// === PageForms ==='
    echo 'wfLoadExtension(\"PageForms\");'
    echo '\$wgPageFormsAllowCreateInRestrictedNamespaces = true;'
    echo '\$wgPageFormsLinkAllRedLinksToForms = true;'
    echo '\$wgPageFormsFormCacheType = CACHE_NONE;'
    echo ''
    echo '// Enable \"Edit with form\" links on Category namespace pages'
    echo '\$wgNamespacesWithSemanticLinks[NS_CATEGORY] = true;'
    echo ''
    echo '// Add \"Edit with form\" tab for Category namespace pages'
    echo '\$wgHooks[\"SkinTemplateNavigation::Universal\"][] = function ( \$skin, &\$links ) {'
    echo '    \$title = \$skin->getTitle();'
    echo '    if ( \$title && \$title->inNamespace( NS_CATEGORY ) && \$title->exists() ) {'
    echo '        \$form = \"Category\";'
    echo '        \$links[\"views\"][\"formedit\"] = ['
    echo '            \"class\" => false,'
    echo '            \"text\"  => \"Edit with form\",'
    echo '            \"href\"  => SpecialPage::getTitleFor( \"FormEdit\", \$form . \"/\" . \$title->getPrefixedText() )->getLocalURL(),'
    echo '        ];'
    echo '    }'
    echo '};'
  } >> $CONTAINER_WIKI/LocalSettings.php
"

echo "==> Running MW updater for PageForms..."
docker compose exec -T mediawiki php maintenance/update.php --quick

# ---------------- ENABLE PARSER FUNCTIONS ----------------

echo "==> Enabling ParserFunctions..."
docker compose exec -T mediawiki bash -lc "
  sed -i '/ParserFunctions/d' $CONTAINER_WIKI/LocalSettings.php
  {
    echo ''
    echo '// === ParserFunctions ==='
    echo 'wfLoadExtension(\"ParserFunctions\");'
  } >> $CONTAINER_WIKI/LocalSettings.php
"

echo "==> Running MW updater for ParserFunctions..."
docker compose exec -T mediawiki php maintenance/update.php --quick

# ---------------- STRUCTURESYNC ----------------

echo "==> Verifying StructureSync extension directory..."
docker compose exec -T mediawiki bash -lc "
  if [ ! -d $CONTAINER_WIKI/extensions/StructureSync ]; then
    echo 'ERROR: StructureSync extension directory not found!'
    exit 1
  fi
  if [ ! -f $CONTAINER_WIKI/extensions/StructureSync/extension.json ]; then
    echo 'ERROR: StructureSync extension.json not found!'
    exit 1
  fi
  echo '✓ StructureSync extension directory found'
"

echo "==> Removing vendor/ inside StructureSync (avoid merge/pollution)..."
docker compose exec -T mediawiki bash -lc "
  rm -rf $CONTAINER_WIKI/extensions/StructureSync/vendor || true
"

echo "==> Installing StructureSync dependencies (isolated)..."
docker compose exec -T mediawiki bash -lc "
  cd $CONTAINER_WIKI/extensions/StructureSync
  composer install --no-dev --no-progress --ignore-platform-reqs || true
"

echo "==> Removing vendor/ again to protect core vendor..."
docker compose exec -T mediawiki bash -lc "
  rm -rf $CONTAINER_WIKI/extensions/StructureSync/vendor || true
"

echo "==> Enabling StructureSync..."
docker compose exec -T mediawiki bash -lc "
  sed -i '/StructureSync/d' $CONTAINER_WIKI/LocalSettings.php
  {
    echo ''
    echo '// === StructureSync ==='
    echo 'wfLoadExtension(\"StructureSync\");'
    echo '\$wgDebugLogGroups[\"structuresync\"] = \"$CONTAINER_LOG_FILE\";'
    echo ''
    echo '// Note: Subobject namespace semantic annotations are enabled automatically'
    echo '// via the SetupAfterCache hook in StructureSyncSetupHooks.php'
  } >> $CONTAINER_WIKI/LocalSettings.php
"

echo "==> Running MW updater for StructureSync..."
docker compose exec -T mediawiki php maintenance/update.php --quick

# ---------------- CACHE DIRECTORY ----------------

echo "==> Setting cache directory..."
docker compose exec -T mediawiki bash -lc "
  sed -i '/wgCacheDirectory/d' $CONTAINER_WIKI/LocalSettings.php
  sed -i '/\\$IP = __DIR__/a \$wgCacheDirectory = \"\$IP/cache-structuresync\";' $CONTAINER_WIKI/LocalSettings.php
"

# ---------------- REBUILD L10N ----------------

echo "==> Rebuilding LocalisationCache..."
docker compose exec -T mediawiki php maintenance/rebuildLocalisationCache.php --force

# ---------------- TEST EXTENSION LOAD ----------------

echo "==> Testing StructureSync loading..."
docker compose exec -T mediawiki php -r "
define('MW_INSTALL_PATH','/var/www/html/w');
\$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once MW_INSTALL_PATH . '/includes/WebStart.php';
echo ExtensionRegistry::getInstance()->isLoaded('StructureSync')
    ? \"✓ StructureSync loaded\n\"
    : \"ERROR: StructureSync NOT loaded\n\";
"

echo "==> Logging test..."
docker compose exec -T mediawiki php -r "
wfDebugLog('structuresync', 'StructureSync test log '.date('H:i:s'));
echo \"OK\n\";
"

docker compose exec -T mediawiki tail -n 5 "$CONTAINER_LOG_FILE" || echo "No log yet."

# ---------------- COMPLETE ----------------

echo ""
echo "========================================"
echo " DONE — StructureSync test environment ready "
echo "========================================"
echo "Visit http://localhost:$MW_PORT/w"
echo "Admin: $MW_ADMIN_USER / $MW_ADMIN_PASS"
echo "Logs: $LOG_DIR"
