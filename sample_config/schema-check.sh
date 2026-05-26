#!/bin/bash
# schema-check.sh
# Compares the running OSM database schema against sample_config/setup.sql
# and checks the applied schema version against Upgrade.php.
# Run from the root of the open-screen-monitor repository.
# Usage: bash sample_config/schema-check.sh [db_name] [db_user]

set -e

DB_NAME="${1:-osm}"
DB_USER="${2:-root}"
COMPARE_DB="osm_schema_check_$$"
SCRIPT_DIR="$(dirname "$0")"
SETUP_SQL="$SCRIPT_DIR/setup.sql"
UPGRADE_PHP="$SCRIPT_DIR/../php/Route/Admin/Upgrade.php"
LIVE_DUMP="/tmp/osm_live_schema_$$.sql"
SETUP_DUMP="/tmp/osm_setup_schema_$$.sql"

cleanup(){
    echo "Cleaning up..."
    mysql -u "$DB_USER" -p -e "DROP DATABASE IF EXISTS \`$COMPARE_DB\`;" 2>/dev/null || true
    rm -f "$LIVE_DUMP" "$SETUP_DUMP"
}
trap cleanup EXIT

if [ ! -f "$SETUP_SQL" ]; then
    echo "ERROR: Cannot find setup.sql at $SETUP_SQL"
    exit 1
fi

echo "OSM Schema Check"
echo "================"
echo "Live database : $DB_NAME"
echo "Comparing to  : $SETUP_SQL"
echo ""

# Schema version check
echo "Schema Version Check"
echo "--------------------"
echo -n "Enter password for version check: "
read -rs DB_PASS
echo ""

APPLIED=$(mysql -u "$DB_USER" -p"$DB_PASS" -s -N "$DB_NAME" \
    -e "SELECT value FROM tbl_config WHERE name = 'dbSchemaVersion';" 2>/dev/null)
APPLIED="${APPLIED:-not set}"

if [ -f "$UPGRADE_PHP" ]; then
    REQUIRED=$(grep -o 'DB_SCHEMA_VERSION = [0-9]*' "$UPGRADE_PHP" | grep -o '[0-9]*$' || echo "unknown")
else
    REQUIRED="unknown (Upgrade.php not found)"
fi

echo "Applied schema version : $APPLIED"
echo "Required schema version: $REQUIRED"
if [ "$APPLIED" = "$REQUIRED" ]; then
    echo "Schema version is up to date."
else
    echo "WARNING: Schema version mismatch. Visit /?route=Admin\\Upgrade for instructions."
fi
echo ""

# Schema structure comparison
echo "Creating temporary comparison database: $COMPARE_DB"
mysql -u "$DB_USER" -p"$DB_PASS" -e "CREATE DATABASE \`$COMPARE_DB\`;"
mysql -u "$DB_USER" -p"$DB_PASS" "$COMPARE_DB" < "$SETUP_SQL"

echo "Dumping schemas..."
mysqldump -u "$DB_USER" -p"$DB_PASS" --no-data --compact "$DB_NAME"    > "$LIVE_DUMP"
mysqldump -u "$DB_USER" -p"$DB_PASS" --no-data --compact "$COMPARE_DB" > "$SETUP_DUMP"

echo ""
echo "Diff (live vs setup.sql):"
echo "--------------------------"
if diff "$LIVE_DUMP" "$SETUP_DUMP"; then
    echo ""
    echo "No differences found. Schema is in sync with setup.sql."
else
    echo ""
    echo "Differences found. Lines marked with '<' are in the live database"
    echo "only. Lines marked with '>' are in setup.sql only."
fi
