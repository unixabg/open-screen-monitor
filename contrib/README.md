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

### osm-backup.sh — configuration backup

Backs up everything an administrator sets up in OSM: filter entries and groups, group
settings, labs and lab permissions, OneRoster data and `tbl_config` (including
`dbSchemaVersion`), together with `client_secret.json`, `permissions.tsv` and the
extension signing key `extension/key.pem` from `osm-data`. The two log tables,
`tbl_filter_log` and `tbl_log`, are left out because of their size. `db.json` is left
out because `make install` creates a new one.

The signing key matters: Chrome derives the extension ID from it, and the ID is what the
Google Admin console force-installs. Without the old key a rebuilt server generates a new
one, so the extension gets a new ID and devices will not take it as an update. Each run
prints the ID the key gives, to compare with the ID in the Admin console. The built
`ext.crx`, `ext.zip` and `update.xml` are not backed up; they are rebuilt from the key.

```bash
./osm-backup.sh                          # /var/backups/osm/osm_backup_YYYYMMDD.tar.gz
BACKUP_DIR=/mnt/nas/osm ./osm-backup.sh  # another location, e.g. a mounted share
KEEP_DAYS=90 ./osm-backup.sh             # keep 90 days of backups (default 30)
```

Writes, so run it as `root`. It reads the database connection from `osm-data/db.json`,
and passes the password through the environment rather than the command line.

- **Safe to schedule:** the dump is written to a temporary file and replaces the day's
  backup only when everything succeeded. A failure (database down, wrong password, full
  disk) leaves earlier backups untouched and exits non-zero, so the scheduler reports it.
  Running it twice on the same day replaces that day's backup.
- **Consistent:** `--single-transaction` takes a snapshot without locking the running site.
- **Sensitive:** backups hold the Google client secret, the extension signing key and lab
  and roster data. Anyone with the signing key can sign an extension that Chrome accepts
  as OSM. The backup
  directory is created `700 root` and each backup `600`; keep any copies just as private.
- **Location:** the default `/var/backups/osm` is outside `osm-data`, so `make purge` does
  not delete it. It is still on the same server; copy backups elsewhere (`BACKUP_DIR` on
  a mounted share, or rsync) so they survive the loss of the server.
- Old backups are deleted after `KEEP_DAYS`; only `osm_backup_*.tar.gz` files are touched.

#### Scheduling with a systemd timer

A minimal Debian install has no cron, so use a systemd timer. Copy the script out of the
git checkout so a later `git pull` cannot change what runs as root unreviewed:

```bash
install -m 755 contrib/osm-backup.sh /usr/local/sbin/osm-backup

cat > /etc/systemd/system/osm-backup.service <<'EOF'
[Unit]
Description=Open Screen Monitor configuration backup
After=mariadb.service

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/osm-backup
# Environment=BACKUP_DIR=/mnt/nas/osm KEEP_DAYS=30
EOF

cat > /etc/systemd/system/osm-backup.timer <<'EOF'
[Unit]
Description=Daily Open Screen Monitor configuration backup

[Timer]
OnCalendar=*-*-* 02:15
Persistent=true

[Install]
WantedBy=timers.target
EOF

systemctl daemon-reload
systemctl enable --now osm-backup.timer
systemctl start osm-backup.service      # run one now
systemctl status osm-backup.service --no-pager
```

`Persistent=true` runs a missed backup at the next boot. Check results with
`systemctl list-timers osm-backup.timer` and `journalctl -u osm-backup.service`.

#### Restoring

On the same server, or on a new one after `make install`:

```bash
mkdir -m 700 /root/osm-restore
tar -xzf /var/backups/osm/osm_backup_YYYYMMDD.tar.gz -C /root/osm-restore
mysql osm < /root/osm-restore/osm.sql
cp /root/osm-restore/client_secret.json /var/www/osm-data/
cp /root/osm-restore/permissions.tsv /var/www/osm-data/ 2>/dev/null || true
if [ -f /root/osm-restore/extension/key.pem ]; then
	install -d -o www-data -g www-data -m 750 /var/www/osm-data/extension
	install -o www-data -g www-data -m 640 /root/osm-restore/extension/key.pem /var/www/osm-data/extension/
fi
make data       # ownership and permissions of the copied files
make check
rm -rf /root/osm-restore
```

Restore the key **before** building the extension on a new server; a build without it
creates a new key and a new extension ID. Then sign in as an admin, open Build Extension
(`/?route=Admin\Buildextension`) and choose **(Re)Build CRX**. The `appid` in
`https://osm.yourdomain.com/?extfile=xml` should match the extension ID the backup printed
and the one in the Admin console.

The restore replaces those seven tables with the backup's contents; the log tables are
not touched. If the backup came from an older OSM version, `make check` reports a schema
version mismatch: sign in as an admin and open `/?route=Admin\Upgrade`.

**Status:** tested on a scratch install: backup, failure handling, retention and a
restore after `make purge` and reinstall, including the extension key keeping the same
extension ID.

---

## Adding your own

These scripts are deliberately simple — a few shell variables and a SQL query.
If you build something useful for your deployment, the same pattern applies:
parameterize the date and database connection at the top, document what the
output means, and note the limitations honestly.
