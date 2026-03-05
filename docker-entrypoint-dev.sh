#!/bin/bash
set -e

: "${MW_DB_HOST:=db}"
: "${MW_DB_NAME:=wiki}"
: "${MW_DB_USER:=wiki}"
: "${MW_DB_PASSWORD:=wiki_pass}"
: "${MW_ADMIN_USER:=Admin}"
: "${MW_ADMIN_PASS:=dockerpass}"

# Wait for database to be ready
echo "Waiting for database at $MW_DB_HOST..."
until mysqladmin ping -h "$MW_DB_HOST" -u "$MW_DB_USER" -p"$MW_DB_PASSWORD" --silent 2>/dev/null; do
	sleep 2
done
echo "Database is ready."

# First-run setup: install MW if no LocalSettings.php exists
if [ ! -f /var/www/html/LocalSettings.php ]; then
	echo "==> Running MediaWiki install..."
	php /var/www/html/maintenance/install.php \
		--dbserver="$MW_DB_HOST" \
		--dbname="$MW_DB_NAME" \
		--dbuser="$MW_DB_USER" \
		--dbpass="$MW_DB_PASSWORD" \
		--pass="$MW_ADMIN_PASS" \
		--server="http://localhost:8889" \
		--scriptpath="" \
		"SemanticSchemasDev" \
		"$MW_ADMIN_USER"

	# Include user settings file
	cat >> /var/www/html/LocalSettings.php <<'EOF'

# Load user settings
if ( file_exists( '/mw-config/LocalSettings.user.php' ) ) {
	require_once '/mw-config/LocalSettings.user.php';
}
EOF

	echo "==> Running update.php..."
	php /var/www/html/maintenance/update.php --quick

	echo "==> Setting up SMW store..."
	php /var/www/html/extensions/SemanticMediaWiki/maintenance/setupStore.php

	echo "==> First-run setup complete."
fi

exec apache2-foreground
