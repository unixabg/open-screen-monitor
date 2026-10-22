#!/bin/bash
# osm-backup.sh — back up OSM's configuration (filters, groups, labs, settings)
#
# Usage (as root):
#   ./osm-backup.sh                          # writes /var/backups/osm/osm_backup_YYYYMMDD.tar.gz
#   BACKUP_DIR=/mnt/nas/osm ./osm-backup.sh  # somewhere else, e.g. off the server
#   KEEP_DAYS=90 ./osm-backup.sh             # keep backups for 90 days (default 30)
#
# Each backup is one .tar.gz holding:
#   osm.sql              every table except the two logs (tbl_filter_log, tbl_log),
#                        including dbSchemaVersion in tbl_config
#   client_secret.json   Google OAuth client, needed to sign in on a rebuilt server
#   permissions.tsv      if present
#   extension/key.pem    the extension signing key; Chrome derives the extension ID from
#                        it, so a rebuilt server needs it to keep the same ID
# db.json is left out on purpose: make install creates a new one with a new password.
#
# The dump goes to a temporary file and only replaces the day's backup when everything
# succeeded, so a failed run never overwrites a good backup. Failures exit non-zero so
# cron or a systemd timer reports them. Backups older than KEEP_DAYS are deleted.
# See contrib/README.md for restoring and scheduling.

set -euo pipefail

DATA_DIR="${DATA_DIR:-/var/www/osm-data}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/osm}"
KEEP_DAYS="${KEEP_DAYS:-30}"
TABLES="tbl_config tbl_filter_entry tbl_filter_entry_group tbl_group_config
	tbl_lab_device tbl_lab_permission tbl_oneroster"

fail(){ echo "osm-backup: ERROR: $*" >&2; exit 1; }

[ "$(id -u)" = 0 ] || fail "run as root (db.json and client_secret.json are readable only by www-data and root)"
[ -r "$DATA_DIR/db.json" ] || fail "cannot read $DATA_DIR/db.json"
case "$KEEP_DAYS" in ''|*[!0-9]*) fail "KEEP_DAYS must be a number of days";; esac

# read the connection from db.json (php is installed with OSM and parses JSON properly)
dbval(){ php -r '$c=json_decode(file_get_contents($argv[1]),true); echo $c[$argv[2]] ?? "";' "$DATA_DIR/db.json" "$1"; }
DB_HOST=$(dbval hostname)
DB_USER=$(dbval user)
DB_NAME=$(dbval dbname)
DB_PASS=$(dbval password)
[ -n "$DB_USER" ] && [ -n "$DB_NAME" ] || fail "could not read user/dbname from $DATA_DIR/db.json"

umask 077
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

# one run at a time
exec 9>"$BACKUP_DIR/.lock"
flock -n 9 || fail "another osm-backup is already running"

STAMP=$(date +%Y%m%d)
OUT="$BACKUP_DIR/osm_backup_$STAMP.tar.gz"
WORK=$(mktemp -d "$BACKUP_DIR/.work.XXXXXX")
trap 'rm -rf "$WORK" "$WORK.tar.gz"' EXIT

# MYSQL_PWD keeps the password off the command line (ps); --single-transaction gives a
# consistent snapshot of the InnoDB tables without locking out the running site
# shellcheck disable=SC2086
MYSQL_PWD="$DB_PASS" mysqldump --single-transaction -h "${DB_HOST:-localhost}" -u "$DB_USER" \
	"$DB_NAME" $TABLES > "$WORK/osm.sql" \
	|| fail "mysqldump failed; previous backups were not touched"
tail -n 1 "$WORK/osm.sql" | grep -q '^-- Dump completed' || fail "dump is incomplete"

for f in client_secret.json permissions.tsv; do
	if [ -f "$DATA_DIR/$f" ]; then cp -p "$DATA_DIR/$f" "$WORK/"; fi
done

EXT_ID=""
if [ -f "$DATA_DIR/extension/key.pem" ]; then
	mkdir "$WORK/extension"
	cp -p "$DATA_DIR/extension/key.pem" "$WORK/extension/"
	# same ID the extension build computes: sha256 of the public key, first 16 bytes as a-p
	EXT_ID=$(openssl pkey -in "$DATA_DIR/extension/key.pem" -pubout -outform DER \
		| sha256sum | cut -c1-32 | tr 0-9a-f a-p) || fail "could not read extension/key.pem"
else
	echo "osm-backup: warning: no $DATA_DIR/extension/key.pem yet (extension never built)" >&2
fi

( cd "$WORK" && tar -czf "$WORK.tar.gz" -- * ) || fail "tar failed"
mv -f "$WORK.tar.gz" "$OUT"

# retention: only our own files, and never the backup just written
find "$BACKUP_DIR" -maxdepth 1 -name 'osm_backup_*.tar.gz' ! -name "$(basename "$OUT")" \
	-mtime +"$KEEP_DAYS" -delete

echo "Backup complete: $OUT ($(du -h "$OUT" | cut -f1))"
if [ -n "$EXT_ID" ]; then echo "Extension ID: $EXT_ID"; fi
