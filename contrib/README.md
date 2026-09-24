# contrib

Helper scripts for OSM administrators. These are **not part of the application** —
they are command-line conveniences for reporting and maintenance, contributed as-is.

Each script lists its tested status below. Read the header comments in any script
before running it, especially regarding what the data does and does not represent.

---

## Recommended: create a read-only reporting user

The reporting scripts only need `SELECT`. Running them as `root` gives an ad-hoc
script full control of the database — a typo while editing a query could damage
data. Create a dedicated read-only user instead:

```sql
CREATE USER 'osmreport'@'localhost' IDENTIFIED BY 'CHANGE-THIS-TO-A-STRONG-PASSWORD';
GRANT SELECT ON osm.* TO 'osmreport'@'localhost';
```

From the command line:

```bash
mysql -u root -p -e "
CREATE USER 'osmreport'@'localhost' IDENTIFIED BY 'CHANGE-THIS-TO-A-STRONG-PASSWORD';
GRANT SELECT ON osm.* TO 'osmreport'@'localhost';"
```

Verify it works and is genuinely read-only:

```bash
# should succeed
mysql -u osmreport -p osm -e "SELECT COUNT(*) FROM tbl_filter_log WHERE date = CURDATE();"

# should fail with a permissions error
mysql -u osmreport -p osm -e "DELETE FROM tbl_filter_log WHERE id = 0;"
```

Each script has a `DBUSER` variable near the top — set it to `osmreport`.

> **Note:** any script that writes cannot use the read-only user. Run those as
> `root` deliberately.

### Treat the reporting password as sensitive

Read-only limits what the account can *change*, not what it can *see*. Anyone with
a shell account on the server who knows the `osmreport` password can read the entire
filter log — every student's browsing history, every URL, every device association.
That is sensitive student data and likely subject to your district's records and
privacy policies.

Practical guidance:

- Use a strong, unique password — not one shared with other services
- Share it only with staff who are authorized to view student browsing records
- Enter the password at the `-p` prompt rather than storing it in a file or
  passing it on the command line, where it lands in shell history and is visible
  in `ps` output to every user on the box
- If a person with shell access leaves or changes roles, rotate the password:

```sql
ALTER USER 'osmreport'@'localhost' IDENTIFIED BY 'new-password-here';
```

---

## Scripts

### osm-domains.sh — domain summary report

Lists every domain visited on a given day with allowed/blocked counts and how many
distinct users hit it. Useful for answering "what are students actually browsing"
and for spotting unexpected destinations.

```bash
./osm-domains.sh                        # today, main_frame only
./osm-domains.sh 2026-09-16             # specific date
./osm-domains.sh 2026-09-16 27          # only usernames starting with 27
./osm-domains.sh 2026-09-16 "" all      # all users, all resource types
```

Read-only. Expect 10-60 seconds on a full school day depending on whether the
data is cached in the InnoDB buffer pool.

**Status:** _(update after testing)_

---

### osm-trackers.sh — third-party tracking summary

Identifies requests to known analytics, advertising and session-recording domains,
and shows which sites triggered them. Intended for student-privacy review of the
edtech stack.

```bash
./osm-trackers.sh                          # yesterday, all browsing
./osm-trackers.sh 2026-09-16               # specific date
./osm-trackers.sh 2026-09-16 vendor.com    # only while students were on vendor.com
```

Read-only. **Slow** — uses `REGEXP` against every row, which cannot use an index.
Measured at roughly 9-10 minutes for a full school day of ~4 million rows. Consider
running it unattended and redirecting output to a file:

```bash
./osm-trackers.sh 2026-09-16 > /tmp/trackers-2026-09-16.txt
```

**Important:** these numbers are a floor, not a complete picture. OSM only logs the
request types listed in `filterviaserverDefaultFilterTypes` — typically
`main_frame`, `sub_frame` and `xmlhttprequest`. Classic image tracking pixels are
invisible unless `image` is added to that list, which significantly increases log
volume. The script also shows only that a network contact occurred; it says nothing
about what data was transmitted. Server-side tracking is undetectable by this method.

**Status:** _(update after testing)_

---

## Adding your own

These scripts are deliberately simple — a few shell variables and a SQL query.
If you build something useful for your deployment, the same pattern applies:
parameterize the date and database connection at the top, document what the
output means, and note the limitations honestly.
