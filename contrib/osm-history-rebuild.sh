#!/bin/bash
# osm-history-rebuild.sh — rebuild the browsing history table (tbl_filter_log) by copying
# its rows into a fresh table and swapping the two.
#
# For a table whose indexes are damaged ("Index for table 'tbl_filter_log' is corrupt"),
# whose index builds fail, or that should give deleted space back to the disk. The new
# table gets every index of the current schema and only as much disk as its rows need.
#
# Usage (as root):
#   ./osm-history-rebuild.sh status     # where things stand; changes nothing
#   ./osm-history-rebuild.sh copy       # 1. copy rows into tbl_filter_log_new, build its indexes
#   ./osm-history-rebuild.sh swap       # 2. copy the last rows, swap the tables, compare them
#   ./osm-history-rebuild.sh abandon    # drop tbl_filter_log_new and start over
#
# copy  only reads the live table. It runs for hours on a large table, in small batches,
#       and can be stopped and run again: it continues after the last row it copied.
#       Options: BATCH=200000 ids per batch, PAUSE=0.1 seconds between batches,
#       TMP_DIR=/path for the index build's sort files when MariaDB's own temporary
#       directory is too small (owned by mysql, outside the MariaDB data directory, with
#       room for the indexes; needs MariaDB's root to be reachable with plain "mysql"),
#       SKIP_TO=id to step over an id range that cannot be read.
# swap  takes seconds plus the comparison. The live table becomes tbl_filter_log_old and
#       is kept; drop it yourself once you are satisfied (that returns its disk space):
#         mysql osm -e "DROP TABLE tbl_filter_log_old"
#
# Needs free disk for the new table next to the old one until the old one is dropped.
# Do not run osm-archive.sh while a rebuild is in progress.
# See contrib/README.md.

set -euo pipefail

DATA_DIR="${DATA_DIR:-/var/www/osm-data}"
BATCH="${BATCH:-200000}"
PAUSE="${PAUSE:-0.1}"
TMP_DIR="${TMP_DIR:-}"
SKIP_TO="${SKIP_TO:-}"
# ids the swapped-in table skips, so rows written to the old table in the moment of the
# swap can never get the same id as rows written to the new one
ID_GAP=1000000

readonly LIVE="tbl_filter_log"
readonly NEW="tbl_filter_log_new"
readonly OLD="tbl_filter_log_old"
# (not named COLUMNS: bash keeps the terminal width in that variable)
readonly FIELDS="id,ip,username,deviceid,action,type,url,date,time,initiator"
# the secondary indexes of the current schema (sample_config/setup.sql)
readonly INDEXES="ADD INDEX filterlogdate (date), ADD INDEX idx_date_time_username (date, time, username), ADD INDEX idx_deviceid_date (deviceid, date, username, time), ADD INDEX idx_date_username (date, username)"
readonly INDEX_COUNT=4

fail(){ echo "osm-history-rebuild: ERROR: $*" >&2; exit 1; }
isnum(){ case "$1" in ''|*[!0-9]*) return 1;; esac; }

[ "$(id -u)" = 0 ] || fail "run as root (db.json is readable only by www-data and root)"
[ -r "$DATA_DIR/db.json" ] || fail "cannot read $DATA_DIR/db.json"
isnum "$BATCH" && [ "$BATCH" -ge 1 ] || fail "BATCH must be a number of ids"
case "$PAUSE" in ''|*[!0-9.]*) fail "PAUSE must be a number of seconds";; esac
[ -z "$SKIP_TO" ] || isnum "$SKIP_TO" || fail "SKIP_TO must be an id"

dbval(){ php -r '$c=json_decode(file_get_contents($argv[1]),true); echo $c[$argv[2]] ?? "";' "$DATA_DIR/db.json" "$1"; }
DB_HOST=$(dbval hostname)
DB_USER=$(dbval user)
DB_NAME=$(dbval dbname)
DB_PASS=$(dbval password)
[ -n "$DB_USER" ] && [ -n "$DB_NAME" ] || fail "could not read user/dbname from $DATA_DIR/db.json"

# MYSQL_PWD keeps the password off the command line (ps)
sql(){ MYSQL_PWD="$DB_PASS" mysql -h "${DB_HOST:-localhost}" -u "$DB_USER" --batch --skip-column-names \
	--default-character-set=utf8mb4 "$DB_NAME" -e "$1"; }
[ "$(sql "SELECT 1")" = 1 ] || fail "cannot connect to database $DB_NAME as $DB_USER"

exists(){ [ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$1'")" = 1 ]; }
# highest id of a table, 0 when empty; read from the end of the primary key, so instant
max_id(){ sql "SELECT IFNULL(MAX(id),0) FROM $1"; }
secondary_indexes(){ sql "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$1' AND INDEX_NAME <> 'PRIMARY'"; }
human(){ numfmt --to=iec-i --suffix=B "$1" 2>/dev/null || echo "$1 bytes"; }
size_of(){ human "$(sql "SELECT IFNULL(SUM(DATA_LENGTH+INDEX_LENGTH+DATA_FREE),0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$1'")"; }

status(){
	echo "$LIVE (live): highest id $(max_id "$LIVE"), $(secondary_indexes "$LIVE") of $INDEX_COUNT indexes, $(size_of "$LIVE") on disk"
	if exists "$NEW"; then
		echo "$NEW (copy in progress): copied up to id $(max_id "$NEW"), $(secondary_indexes "$NEW") of $INDEX_COUNT indexes, $(size_of "$NEW") on disk"
	else
		echo "$NEW: not started"
	fi
	if exists "$OLD"; then
		echo "$OLD (the table before the last swap): $(size_of "$OLD") on disk; drop it to free that space"
	fi
}

# one run at a time, and not together with the archive
exec 9>"/run/osm-history-rebuild.lock"
flock -n 9 || fail "another osm-history-rebuild is already running"

# Copy the live rows with from < id <= upto into the new table, a batch of ids at a time.
# Reads through the primary key only, so damaged secondary indexes are never used.
# READ COMMITTED: the copy must not hold locks that make the site's inserts wait.
# INSERT IGNORE: a row that turns up twice in a damaged table is kept once and counted.
# Sets COPIED (rows written) and DUPLICATES.
COPIED=0
DUPLICATES=0
copy_range(){
	local from="$1" upto="$2" to out rows warn
	while [ "$from" -lt "$upto" ]; do
		to=$(( from + BATCH ))
		[ "$to" -le "$upto" ] || to="$upto"
		out=$(sql "SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED;
			INSERT IGNORE INTO $3 ($FIELDS) SELECT $FIELDS FROM $4 FORCE INDEX (PRIMARY) WHERE id > $from AND id <= $to;
			SELECT ROW_COUNT(), @@warning_count;") \
			|| fail "could not copy ids $from to $to. Nothing is lost: run the same command again to retry. If these ids cannot be read at all, step over them with SKIP_TO=$to"
		read -r rows warn <<< "$out"
		COPIED=$(( COPIED + rows ))
		if [ "$warn" -gt 0 ]; then
			DUPLICATES=$(( DUPLICATES + warn ))
			echo "  ids $from-$to: $warn rows were already there (read twice from the damaged table); each kept once"
		fi
		from="$to"
		# progress, about every 50 batches
		if [ $(( (from / BATCH) % 50 )) -eq 0 ]; then echo "  copied up to id $from of $upto"; fi
		sleep "$PAUSE"
	done
}

case "${1:-}" in
status)
	status
	;;

abandon)
	exists "$NEW" || fail "there is no $NEW to drop"
	sql "DROP TABLE $NEW"
	echo "Dropped $NEW. The live table was not touched."
	;;

copy)
	exists "$OLD" && fail "$OLD exists from an earlier swap. Drop it first (mysql $DB_NAME -e \"DROP TABLE $OLD\") so the disk has room"
	if ! exists "$NEW"; then
		# same columns as the live table; no secondary indexes yet, so the copy only
		# appends and the indexes are built in one sorted pass afterwards
		sql "CREATE TABLE $NEW LIKE $LIVE"
		for INDEX_NAME in $(sql "SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$NEW' AND INDEX_NAME <> 'PRIMARY'"); do
			sql "ALTER TABLE $NEW DROP INDEX \`$INDEX_NAME\`"
		done
		echo "Created $NEW"
	fi

	FROM=$(max_id "$NEW")
	if [ "$FROM" = 0 ]; then
		# nothing copied yet: start just below the lowest id still in the live table
		FROM=$(sql "SELECT IFNULL(MIN(id),1) - 1 FROM $LIVE")
	fi
	if [ -n "$SKIP_TO" ] && [ "$SKIP_TO" -gt "$FROM" ]; then
		echo "Stepping over ids $FROM to $SKIP_TO as asked (SKIP_TO); those rows are not copied"
		FROM="$SKIP_TO"
	fi
	UPTO=$(max_id "$LIVE")
	echo "Copying ids $FROM to $UPTO from $LIVE into $NEW"
	copy_range "$FROM" "$UPTO" "$NEW" "$LIVE"
	echo "Copied $COPIED rows"

	if [ "$(secondary_indexes "$NEW")" -lt "$INDEX_COUNT" ]; then
		echo "Building the indexes on $NEW (one pass over the copied rows; no progress is shown)"
		# a partly indexed copy (an earlier build stopped) starts its indexes again
		for INDEX_NAME in $(sql "SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$NEW' AND INDEX_NAME <> 'PRIMARY'"); do
			sql "ALTER TABLE $NEW DROP INDEX \`$INDEX_NAME\`"
		done
		if [ -n "$TMP_DIR" ]; then
			# choosing the sort directory needs the FILE privilege, which the OSM database
			# user does not have: this one statement runs as MariaDB's root over the local
			# socket (how "mysql" connects for the system's root user on Debian)
			[ -d "$TMP_DIR" ] || fail "TMP_DIR $TMP_DIR does not exist"
			build(){ mysql --batch "$DB_NAME" -e "SET SESSION innodb_tmpdir = '$TMP_DIR'; ALTER TABLE $NEW $INDEXES"; }
		else
			build(){ sql "ALTER TABLE $NEW $INDEXES"; }
		fi
		build \
			|| fail "the index build on $NEW failed (see above). The live table was not touched and the copied rows are kept; run 'copy' again to retry the build. If it was out of temporary space, set TMP_DIR"
		echo "Indexes built"
	fi

	# rows the site added while the copy and the index build ran
	FROM=$(max_id "$NEW")
	UPTO=$(max_id "$LIVE")
	COPIED=0
	copy_range "$FROM" "$UPTO" "$NEW" "$LIVE"
	echo "Copied $COPIED rows added meanwhile"
	echo
	status
	echo
	echo "The copy is ready. At a quiet moment run:  $0 swap"
	;;

swap)
	exists "$NEW" || fail "there is no $NEW; run '$0 copy' first"
	exists "$OLD" && fail "$OLD already exists; drop it or rename it first"
	[ "$(secondary_indexes "$NEW")" -eq "$INDEX_COUNT" ] || fail "$NEW does not have its indexes yet; run '$0 copy' to finish it"

	# bring the copy up to date, so only a moment's rows are left for after the swap
	copy_range "$(max_id "$NEW")" "$(max_id "$LIVE")" "$NEW" "$LIVE"
	LAST=$(max_id "$NEW")

	# new rows in the swapped-in table start well above anything the old one can still
	# receive before the rename takes effect
	sql "ALTER TABLE $NEW AUTO_INCREMENT = $(( $(max_id "$LIVE") + ID_GAP ))"
	FIRST_NEW_ID=$(( $(max_id "$LIVE") + 1 ))
	# both renames happen as one step: there is no moment without a $LIVE table
	sql "SET SESSION lock_wait_timeout = 30; RENAME TABLE $LIVE TO $OLD, $NEW TO $LIVE" || fail "the swap did not happen; both tables are as they were"
	echo "Swapped: the rebuilt table is now $LIVE, the previous one is $OLD"

	# rows that reached the old table between the last copy and the rename. From here
	# nothing writes to it, so its highest id is final
	OLD_MAX=$(max_id "$OLD")
	COPIED=0
	copy_range "$LAST" "$OLD_MAX" "$LIVE" "$OLD"
	echo "Copied the last $COPIED rows"

	echo "Comparing the two tables (reads both in full; this can take a while)"
	IN_OLD=$(sql "SELECT COUNT(*) FROM $OLD FORCE INDEX (PRIMARY)")
	IN_NEW=$(sql "SELECT COUNT(*) FROM $LIVE FORCE INDEX (PRIMARY) WHERE id <= $OLD_MAX")
	echo "  rows in $OLD: $IN_OLD"
	echo "  the same rows in $LIVE: $IN_NEW"
	if [ "$IN_OLD" = "$IN_NEW" ]; then
		echo "  the counts match"
	else
		echo "  THE COUNTS DIFFER by $(( IN_OLD - IN_NEW )). $OLD still holds everything; do not drop it until this is understood."
		echo "  (a row the damaged table returned twice is counted twice there and kept once here)"
	fi
	sql "INSERT INTO tbl_log SET timestamp = NOW(), ip = '', username = 'osm-history-rebuild', type = 'history.rebuild', targetid = 'swap', data = '{\"old\":$IN_OLD,\"new\":$IN_NEW}'" \
		|| echo "osm-history-rebuild: warning: could not write the history.rebuild entry to tbl_log" >&2
	echo
	status
	echo
	echo "New history rows start at id $(( FIRST_NEW_ID + ID_GAP - 1 )) or above."
	echo "When you are satisfied, free the old table's disk space with:"
	echo "  mysql $DB_NAME -e \"DROP TABLE $OLD\""
	;;

*)
	echo "Usage: $0 status | copy | swap | abandon   (see the top of this file)"
	exit 1
	;;
esac
