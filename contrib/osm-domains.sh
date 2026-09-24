#!/bin/bash
# osm-domains.sh — domain summary from the OSM filter log
#
# Usage:
#   ./osm-domains.sh                        # today, main_frame
#   ./osm-domains.sh 2026-08-25             # specific date
#   ./osm-domains.sh 2026-08-25 27          # date + username prefix (class of 2027)
#   ./osm-domains.sh 2026-08-25 "" all      # date, all users, all resource types
#
# Args: [date] [username-prefix] [all|main_frame]

DATE="${1:-$(date +%Y-%m-%d)}"
PREFIX="${2:-}"
TYPE="${3:-main_frame}"
DB="osm"
DBUSER="osmreport"   # see contrib/README.md - read-only reporting user

if [ "$TYPE" = "all" ]; then
    TYPECLAUSE=""
else
    TYPECLAUSE="AND type = '$TYPE'"
fi

if [ -n "$PREFIX" ]; then
    USERCLAUSE="AND username LIKE '${PREFIX}%'"
else
    USERCLAUSE=""
fi

echo "OSM Domain Summary"
echo "=================="
echo "Date:          $DATE"
echo "Resource type: $TYPE"
echo "User prefix:   ${PREFIX:-<all users>}"
echo ""

mysql -u "$DBUSER" -p "$DB" -e "
SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(url,'/',3),'/',-1) AS domain,
       SUM(CASE WHEN action = 'ALLOW' THEN 1 ELSE 0 END)  AS allowed,
       SUM(CASE WHEN action IN ('BLOCK','BLOCKPAGE','BLOCKNOTIFY') THEN 1 ELSE 0 END) AS blocked,
       COUNT(*)                    AS total,
       COUNT(DISTINCT username)    AS users
FROM tbl_filter_log
WHERE date = '$DATE'
$TYPECLAUSE
$USERCLAUSE
GROUP BY domain
ORDER BY total DESC;"
