#!/bin/bash
# osm-archive.sh — move old browsing history (tbl_filter_log) out of the database into files
#
# Usage (as root):
#   ./osm-archive.sh                              # report only: size, oldest day; changes nothing
#   KEEP_DAYS=60 ./osm-archive.sh                 # archive every day older than 60 days
#   KEEP_DAYS=60 DRY_RUN=1 ./osm-archive.sh       # list the days that would be archived
#   KEEP_DAYS=60 MAX_DAYS=10 ./osm-archive.sh     # stop after 10 days (spread a first run out)
#   ARCHIVE_DIR=/mnt/nas/osm-history KEEP_DAYS=60 ./osm-archive.sh
#
# Each day older than KEEP_DAYS becomes one file,
#   $ARCHIVE_DIR/YYYY/osm_history_YYYY-MM-DD.tsv.gz
# a gzip'd tab separated file with a header line and one row per line (tabs, newlines and
# backslashes inside a value are written as \t \n \\, the format LOAD DATA reads back).
# A day's rows are deleted from the database only after its file is complete: written to a
# temporary file, row count compared with the database, gzip tested, then moved into place.
# Rows are deleted in batches of BATCH (default 50000) with PAUSE seconds (default 0.2)
# between batches, so the running site is never locked out for long.
#
# Safe to interrupt and to schedule: a run that stops part way continues with the same day
# next time and never exports a day twice. Failures exit non-zero so cron or a systemd
# timer reports them. Archive files are never deleted by this script.
# See contrib/README.md for scheduling, searching and restoring.

set -euo pipefail

DATA_DIR="${DATA_DIR:-/var/www/osm-data}"
ARCHIVE_DIR="${ARCHIVE_DIR:-/var/backups/osm/history}"
KEEP_DAYS="${KEEP_DAYS:-}"
MAX_DAYS="${MAX_DAYS:-0}"
BATCH="${BATCH:-50000}"
PAUSE="${PAUSE:-0.2}"
DRY_RUN="${DRY_RUN:-}"
TABLE="tbl_filter_log"
COLUMNS="id,ip,username,deviceid,action,type,url,date,time,initiator"

fail(){ echo "osm-archive: ERROR: $*" >&2; exit 1; }
isnum(){ case "$1" in ''|*[!0-9]*) return 1;; esac; }

[ "$(id -u)" = 0 ] || fail "run as root (db.json is readable only by www-data and root)"
[ -r "$DATA_DIR/db.json" ] || fail "cannot read $DATA_DIR/db.json"
isnum "$MAX_DAYS" || fail "MAX_DAYS must be a number of days (0 = no limit)"
isnum "$BATCH" && [ "$BATCH" -ge 1 ] || fail "BATCH must be a number of rows"
case "$PAUSE" in ''|*[!0-9.]*) fail "PAUSE must be a number of seconds";; esac

# read the connection from db.json (php is installed with OSM and parses JSON properly)
dbval(){ php -r '$c=json_decode(file_get_contents($argv[1]),true); echo $c[$argv[2]] ?? "";' "$DATA_DIR/db.json" "$1"; }
DB_HOST=$(dbval hostname)
DB_USER=$(dbval user)
DB_NAME=$(dbval dbname)
DB_PASS=$(dbval password)
[ -n "$DB_USER" ] && [ -n "$DB_NAME" ] || fail "could not read user/dbname from $DATA_DIR/db.json"

# MYSQL_PWD keeps the password off the command line (ps)
# sql: one value or tab separated row, no header. dump: header + rows, streamed (--quick)
# so a day of several million rows is never held in memory
sql(){ MYSQL_PWD="$DB_PASS" mysql -h "${DB_HOST:-localhost}" -u "$DB_USER" --batch --skip-column-names \
	--default-character-set=utf8mb4 "$DB_NAME" -e "$1"; }
dump(){ MYSQL_PWD="$DB_PASS" mysql -h "${DB_HOST:-localhost}" -u "$DB_USER" --batch --quick \
	--default-character-set=utf8mb4 "$DB_NAME" -e "$1"; }

[ "$(sql "SELECT 1")" = 1 ] || fail "cannot connect to database $DB_NAME as $DB_USER"

human(){ numfmt --to=iec-i --suffix=B "$1" 2>/dev/null || echo "$1 bytes"; }

# what is there now. TABLE_ROWS is InnoDB's estimate; an exact count of a very large table
# takes minutes. DATA_FREE is space inside the table file that deleted rows left for new
# rows: deleting does not shrink the file, the space is reused
report(){
	local size free rows oldest
	read -r size free rows < <(sql "SELECT DATA_LENGTH+INDEX_LENGTH+DATA_FREE, DATA_FREE, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$TABLE'") \
		|| fail "cannot read the size of $TABLE"
	oldest=$(sql "SELECT IFNULL(MIN(date),'') FROM $TABLE")
	echo "Browsing history ($TABLE): about $rows rows, $(human "$size") on disk, $(human "$free") of it free for new rows"
	echo "Oldest day in the database: ${oldest:-none}"
}

if [ -z "$KEEP_DAYS" ]; then
	report
	echo
	echo "Nothing was changed. To archive every day older than N days:  KEEP_DAYS=N $0"
	echo "To list those days first:  KEEP_DAYS=N DRY_RUN=1 $0"
	exit 0
fi
isnum "$KEEP_DAYS" && [ "$KEEP_DAYS" -ge 1 ] || fail "KEEP_DAYS must be a number of days, 1 or more"

# days before CUTOFF are archived; today and the KEEP_DAYS days before it stay
CUTOFF=$(date -d "-$KEEP_DAYS days" +%F)

next_day(){ sql "SELECT IFNULL(MIN(date),'') FROM $TABLE WHERE date > '$1' AND date < '$CUTOFF'"; }
count_day(){ sql "SELECT COUNT(*) FROM $TABLE WHERE date = '$1'"; }
# rows in an archive file: its lines minus the header
count_file(){ local n; n=$(gzip -dc "$1" | wc -l); echo $(( n > 0 ? n - 1 : 0 )); }

if [ -n "$DRY_RUN" ]; then
	report
	echo "Would archive every day before $CUTOFF to $ARCHIVE_DIR:"
	DAY="0000-00-00"; N=0
	while :; do
		DAY=$(next_day "$DAY") || fail "could not read the next day to archive"
		[ -n "$DAY" ] || break
		echo "  $DAY  $(count_day "$DAY") rows"
		N=$((N+1))
		if [ "$MAX_DAYS" -gt 0 ] && [ "$N" -ge "$MAX_DAYS" ]; then echo "  (stopping at MAX_DAYS=$MAX_DAYS)"; break; fi
	done
	[ "$N" -gt 0 ] || echo "  nothing: no history older than $KEEP_DAYS days"
	exit 0
fi

umask 077
mkdir -p "$ARCHIVE_DIR"
chmod 700 "$ARCHIVE_DIR"

# one run at a time
exec 9>"$ARCHIVE_DIR/.lock"
flock -n 9 || fail "another osm-archive is already running"

TMP=""
trap '[ -z "$TMP" ] || rm -f "$TMP"' EXIT

DAYS_DONE=0
ROWS_DONE=0
DAY="0000-00-00"
while :; do
	DAY=$(next_day "$DAY") || fail "could not read the next day to archive"
	[ -n "$DAY" ] || break
	if [ "$MAX_DAYS" -gt 0 ] && [ "$DAYS_DONE" -ge "$MAX_DAYS" ]; then
		echo "Stopping at MAX_DAYS=$MAX_DAYS; older history remains, run again to continue."
		break
	fi

	OUT="$ARCHIVE_DIR/${DAY%%-*}/osm_history_$DAY.tsv.gz"
	mkdir -p "$(dirname "$OUT")"
	IN_DB=$(count_day "$DAY")

	if [ -f "$OUT" ]; then
		# an earlier run wrote this file and was stopped while deleting; the file is
		# complete, so only the delete is left. More rows in the database than in the
		# file means they did not come from that run (e.g. a restore), so stop
		gzip -t "$OUT" || fail "$OUT is damaged; move it aside and run again"
		IN_FILE=$(count_file "$OUT")
		[ "$IN_DB" -le "$IN_FILE" ] || fail "$DAY has $IN_DB rows in the database but $OUT holds $IN_FILE; move the file aside and run again"
		echo "$DAY: continuing an interrupted run, $IN_DB of $IN_FILE rows still to delete"
	else
		TMP="$OUT.tmp.$$"
		dump "SELECT $COLUMNS FROM $TABLE WHERE date = '$DAY'" | gzip > "$TMP" \
			|| fail "export of $DAY failed; nothing was deleted"
		gzip -t "$TMP" || fail "archive of $DAY did not verify; nothing was deleted"
		IN_FILE=$(count_file "$TMP")
		[ "$IN_FILE" = "$IN_DB" ] && [ "$IN_FILE" = "$(count_day "$DAY")" ] \
			|| fail "$DAY: archive holds $IN_FILE rows but the database has $IN_DB; nothing was deleted"
		mv -f "$TMP" "$OUT"
		TMP=""
		# make sure the file is on disk before its rows leave the database
		sync "$OUT" 2>/dev/null || sync
	fi

	# delete in batches; each is its own short transaction
	while :; do
		GONE=$(sql "DELETE FROM $TABLE WHERE date = '$DAY' LIMIT $BATCH; SELECT ROW_COUNT();") \
			|| fail "delete of $DAY failed part way; its archive $OUT is complete, run again to continue"
		[ "$GONE" -gt 0 ] || break
		sleep "$PAUSE"
	done

	echo "$DAY: $IN_FILE rows -> $OUT ($(du -h "$OUT" | cut -f1))"
	DAYS_DONE=$((DAYS_DONE+1))
	ROWS_DONE=$((ROWS_DONE+IN_FILE))
done

if [ "$DAYS_DONE" -gt 0 ]; then
	# shows in the admin Log Viewer as type history.archive
	sql "INSERT INTO tbl_log SET timestamp = NOW(), ip = '', username = 'osm-archive', type = 'history.archive', targetid = 'before $CUTOFF', data = '{\"days\":$DAYS_DONE,\"rows\":$ROWS_DONE}'" \
		|| echo "osm-archive: warning: could not write the history.archive entry to tbl_log" >&2
	echo "Archived $DAYS_DONE days, $ROWS_DONE rows, to $ARCHIVE_DIR"
else
	echo "Nothing to archive: no history older than $KEEP_DAYS days (before $CUTOFF)"
fi
report
