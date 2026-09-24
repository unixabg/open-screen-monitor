#!/bin/bash
# osm-trackers.sh — identify likely third-party tracking domains in the OSM filter log
#
# IMPORTANT LIMITATIONS — read before using these numbers:
#   * Only request types in filterviaserverDefaultFilterTypes are logged
#     (typically main_frame, sub_frame, xmlhttprequest). Classic image
#     tracking pixels are NOT captured unless 'image' is in that list.
#     Results are therefore a FLOOR, not a complete picture.
#   * This shows that a network contact occurred. It does NOT show what
#     data was transmitted, or whether any student data was involved.
#   * Server-side tracking (vendor backend reports to a tracker directly)
#     is completely invisible to this method.
#   * Domain matching is heuristic. Verify anything you plan to act on.
#
# Usage:
#   ./osm-trackers.sh                          # yesterday, all initiators
#   ./osm-trackers.sh 2026-09-16               # specific date
#   ./osm-trackers.sh 2026-09-16 vendor.com    # only while on vendor.com

DATE="${1:-$(date -d 'yesterday' +%Y-%m-%d)}"
INITIATOR="${2:-}"
DB="osm"
DBUSER="osmreport"   # see contrib/README.md - read-only reporting user

# Common third-party analytics, advertising and session-recording domains.
# Extend this list as you identify others in your environment.
TRACKERS="doubleclick.net|google-analytics.com|googletagmanager.com|googlesyndication.com|googleadservices.com|facebook.com/tr|connect.facebook.net|scorecardresearch.com|hotjar.com|segment.io|segment.com|mixpanel.com|amplitude.com|fullstory.com|mouseflow.com|crazyegg.com|optimizely.com|newrelic.com|bugsnag.com|sentry.io|branch.io|adsrvr.org|criteo.com|taboola.com|outbrain.com|quantserve.com|bluekai.com|demdex.net|omtrdc.net|adobedtm.com|clarity.ms|bing.com/action|analytics.tiktok.com|snap.licdn.com|ads.linkedin.com|pixel.wp.com|matomo|piwik"

if [ -n "$INITIATOR" ]; then
    INITCLAUSE="AND initiator LIKE '%${INITIATOR}%'"
    SCOPE="pages on ${INITIATOR}"
else
    INITCLAUSE=""
    SCOPE="all browsing"
fi

echo "OSM Third-Party Tracking Summary"
echo "================================"
echo "Date:  $DATE"
echo "Scope: $SCOPE"
echo ""
echo "NOTE: Only logged request types are visible (see script header)."
echo "      These numbers are a floor, not a complete picture."
echo ""

mysql -u "$DBUSER" -p "$DB" -e "
SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(url,'/',3),'/',-1) AS tracker_domain,
       COUNT(*)                 AS requests,
       COUNT(DISTINCT username) AS users,
       COUNT(DISTINCT SUBSTRING_INDEX(SUBSTRING_INDEX(initiator,'/',3),'/',-1)) AS source_sites
FROM tbl_filter_log
WHERE date = '$DATE'
$INITCLAUSE
AND url REGEXP '$TRACKERS'
GROUP BY tracker_domain
ORDER BY requests DESC;"

echo ""
echo "Top source sites contacting trackers:"
echo ""

mysql -u "$DBUSER" -p "$DB" -e "
SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(initiator,'/',3),'/',-1) AS source_site,
       COUNT(*)                 AS tracker_requests,
       COUNT(DISTINCT username) AS users,
       COUNT(DISTINCT SUBSTRING_INDEX(SUBSTRING_INDEX(url,'/',3),'/',-1)) AS distinct_trackers
FROM tbl_filter_log
WHERE date = '$DATE'
$INITCLAUSE
AND url REGEXP '$TRACKERS'
AND initiator != ''
GROUP BY source_site
ORDER BY tracker_requests DESC
LIMIT 40;"
