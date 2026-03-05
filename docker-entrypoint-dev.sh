#!/bin/bash
set -e

: "${MW_DB_HOST:=db}"
: "${MW_DB_NAME:=wiki}"
: "${MW_DB_USER:=wiki}"
: "${MW_DB_PASSWORD:=wiki_pass}"
: "${MW_ADMIN_USER:=Admin}"
: "${MW_ADMIN_PASS:=dockerpass}"
: "${MW_SERVER:=http://localhost:8889}"

MW_DIR="/var/www/html"

# Wait for database to be ready
echo "Waiting for database at $MW_DB_HOST..."
until mysqladmin ping -h "$MW_DB_HOST" -u "$MW_DB_USER" -p"$MW_DB_PASSWORD" --silent 2>/dev/null; do
	sleep 2
done
echo "Database is ready."

# First-run setup: install MW if no LocalSettings.php exists
if [ ! -f "$MW_DIR/LocalSettings.php" ]; then
	echo "==> Running MediaWiki install..."
	php "$MW_DIR/maintenance/install.php" \
		--dbserver="$MW_DB_HOST" \
		--dbname="$MW_DB_NAME" \
		--dbuser="$MW_DB_USER" \
		--dbpass="$MW_DB_PASSWORD" \
		--pass="$MW_ADMIN_PASS" \
		--server="$MW_SERVER" \
		--scriptpath="" \
		"SemanticSchemasDev" \
		"$MW_ADMIN_USER"

	# Include user settings file
	cat >> "$MW_DIR/LocalSettings.php" <<'EOF'

# Load user settings
if ( file_exists( '/mw-config/LocalSettings.user.php' ) ) {
	require_once '/mw-config/LocalSettings.user.php';
}
EOF

	echo "==> Running update.php..."
	php "$MW_DIR/maintenance/update.php" --quick

	echo "==> Setting up SMW store..."
	php "$MW_DIR/extensions/SemanticMediaWiki/maintenance/setupStore.php"

	echo "==> First-run setup complete."
fi

exec apache2-foreground
