# Open Screen Monitor (osm)
## An open-source screen monitoring tool with url filtering and policy enforcement features for Chrome.
- Initial concepts and work created by Andrew Coursen AndrewCoursen@gmail.com.
- Code contributed to the open source community by Andrew Coursen under the GNU General Public License v3.0.
- Project created at GitHub by Richard Nelson unixabg@gmail.com.

### chrome-extension
- The extension to be added to the target Chrome browser.

### php
- The server side to listen and manage the connections, presentation, and operations for open-screen-monitor.

### Authentication
- OAuth 2.0 with Google https://console.developers.google.com/

### Getting started
- Clone the repository
- Configure the chrome-extension for your deployment (FIXME)
- Install the server side PHP (see Example Setup below, `make install`)
- Make the data directory **Attention - DO NOT make this folder a public access folder from the web!**
 - The defaults will attempt to add osm to your domain name. So something like osm.yourdomain.com .
   - https://osm.yourdomain.com/ is the default location for the osm php files
   - ../osm-data is the default data location relative to the default osm php files
- Configure OAuth access to your Organizational Unit of your domain
 - Go to  https://console.developers.google.com/
 - Create a new API Project with name OpenScreenMonitor
 - Once created open the OSMOAuth project and enable the Admin SDK & Classroom API
 - Create the APIs Credentials of type OAuth client ID
  - Configure OAuth consent screen: yourDomain - OpenScreenMonitor
  - Application type: Web application
  - Set Authorized JavaScript origins and Authorized redirect URIs
  - After you choose Create you should get an OAuth Client screen with the following information:
   - Here is your client ID
   - Here is your client secret
  - Now from the OSMClient edit button you will be able to choose DOWNLOAD JSON to the osm-data folder
    - **Please be mindful** to apply appropiate security and permissions to the client_secret.json file

### Google Admin Console — Extension Policy URL

When force-installing the extension via `ExtensionInstallForcelist` in Google Admin Console,
the policy URL **must** point to the update manifest XML, not directly to the CRX file:

**Correct:**
```
inheliheabkbamkaddmmebjmphdmlmbe;https://osm.yourdomain.com/?extfile=xml
```

**Incorrect:**
```
inheliheabkbamkaddmmebjmphdmlmbe;https://osm.yourdomain.com/?extfile=crx
```

Chrome uses the XML manifest to check the current version and locate the CRX download URL.
Pointing directly to the CRX works for fresh installs only — extension updates will fail
silently on persistent profiles. Ephemeral Chromebooks may appear to work with the CRX URL
since they reinstall fresh on every login, masking the issue.

### Other notes (ymmv)
- If using mode user on pristine install, you will need to create a permissions.tsv in the osm-data folder.
  - Create an entry for your admin user something like: username@yourdomain.com<TAB>admin
  - Remember to make set the permissions correct on the file.
- Self Signed Certificate
 - **Note: Officially Signed Certificates are recommended for safety and security**
 - If you choose to use a self signed certificate you can install it in the Google Admin Console
   - Device management > Networks > Certificates
   - Choose the OU you wish to modify
   - Upload your web server Self Signed Certificate
     - (Enable) Use this certificate as an HTTPS certificate authority

 - During testing if you set the subjectAltName="DNS:osm.xxx,DNS:osm" for your
 certificate creation there were no extra settings required. Otherwise the next
 setting seemed to make Chrome not complain about the certificate
   - Device management > Chrome > User Settings
    - Choose the OU you wish to modify
    - Scroll down to Security section
    - Locate the Local Trust Anchors Certificates
    - Local Anchors Common Name Fallback
     - Allow
 - Example API Call
  - curl --insecure  --header "X-OSMKEY: test" "https://localhost/?route=Monitor\API&action=online"

FIXME

### Configuration

Configuration is managed through the Config Editor at `/?route=Admin\Config`. Values are stored
in `tbl_config` and override the defaults defined in `php/Tools/Config.php`.

Key configuration options:

| Key | Default | Description |
|---|---|---|
| `allowedUserDomains` | _(empty)_ | Comma-separated list of permitted email domains for extension uploads. Empty accepts all. Example: `example.com,anotherschool.org` |
| `enableGoogleClassroom` | `false` | Enable Google Classroom integration on the index page |
| `enableOneRoster` | `false` | Enable OneRoster roster sync and class monitoring |
| `enableLab` | `true` | Enable device lab monitoring |
| `screenscrape` | `false` | Enable page text scanning for keyword triggers |
| `screenscrapeTime` | `20000` | Interval in milliseconds between screenscrape checks |
| `deviceLastUserLookback` | `7` | Number of days to look back when finding last user of a device |
| `allTeachersGetBypass` | `true` | Automatically grant bypass monitoring permission to all teachers |
| `showNonEnterpriseDevices` | `false` | Show non-enterprise device catch-all option on index page |
| `debug` | `true` | Write full request/response data to disk — disable in production |

| `filterViaServer` | `false` | Enable server-side URL filtering |
| `sessionTimeout` | `28800` | Web session timeout in seconds (default 8 hours) |
| `bypassTimeout` | `28800` | Bypass group timeout in seconds (default 8 hours) |
| `userGroupTimeout` | `28800` | User group session timeout in seconds (default 8 hours) |

> **Note:** `debug` defaults to `true` which is useful during initial setup to verify the extension is communicating correctly. Check `osm-data/clients/debug-in/` to inspect incoming data. **Disable debug mode before going to production** as it writes full request data including screenshots to disk.


### Server-Side URL Filtering

OSM includes a powerful server-side URL filtering system that intercepts and evaluates every
browser request made by managed Chrome devices. Filtering is enabled via `filterViaServer = true`
in the Config Editor at `/?route=Admin\Config`. Rules are managed at `/?route=Admin\Serverfilter`.

#### How Filtering Works

Every time a managed Chrome device makes a web request (page load, background API call, image, etc.),
the OSM extension sends the request details to the OSM server before allowing it to proceed.
The server evaluates the request against the configured filter rules and returns an action.
The extension executes that action — allowing, blocking, redirecting, or notifying — in real time.

This happens transparently and typically within milliseconds. The student sees either the page
load normally, a block page, or a Chrome notification depending on the action taken.

#### Rule Evaluation Order

Rules are evaluated in **descending priority order** — the highest priority number is evaluated first.

- The **first matching rule wins** for ALLOW, BLOCK, BLOCKPAGE, and BLOCKNOTIFY actions.
  Once a match is found, evaluation stops.
- **All matching TRIGGER rules fire** — unlike other actions, every TRIGGER rule that matches
  the request will send its alert, not just the first one.
- **TRIGGER_EXEMPT** stops TRIGGER processing. Use it to suppress alerts for specific URLs
  that would otherwise match a broader TRIGGER rule.

**Example:** A TRIGGER rule at priority 10 alerts on any social media visit. A TRIGGER_EXEMPT
rule at priority 20 (evaluated first) suppresses the alert for an approved educational social
media page. The exempt rule fires first, stops TRIGGER processing, and no alert is sent.

#### URL Matching Modes

The URL field supports three matching modes:

- **Substring (default)** — matches if the request URL starts with the value.
  `https://example.com` matches `https://example.com/any/path` but not `https://www.example.com/`.

- **`simple:`** — matches the domain and any subdomain, ignoring the path.
  `simple:example.com` matches `https://example.com/page`, `https://www.example.com/page`,
  and `https://subdomain.example.com/anything`.

- **`regex:`** — full regular expression match against the entire URL including query string.
  `regex:.*\.example\.com.*` matches any URL containing `.example.com`.
  `regex:^https://accounts\.google\.com/o/oauth2/.*redirect_uri=https%3A%2F%2Fosm\.example\.com.*$`
  matches a specific OAuth redirect flow.

- **Leave blank** — matches all URLs. Useful when scoping by Username or Resource Type alone.

> **Technical note:** Dots in regex patterns must be escaped as `\.` to match a literal period.
> An unescaped `.` matches any character. `simple:example.com` and `regex:.*\.example\.com.*`
> are both safer than `regex:.*example.com.*` which would also match `exampleXcom`.

#### Resource Types

Resource Type limits a rule to a specific category of browser request. Leave blank to use
the default filter types configured in `filterviaserverDefaultFilterTypes` (typically
`main_frame`, `sub_frame`, and `xmlhttprequest`).

| Resource Type | What it covers | Common use |
|---|---|---|
| `main_frame` | Top-level page navigations — typing a URL, clicking a link | Most blocking rules |
| `sub_frame` | Iframes and embedded frames within a page | Blocking embedded content |
| `xmlhttprequest` | Background API and AJAX calls | Blocking data exfiltration |
| `script` | JavaScript files loaded by pages | Advanced content blocking |
| `image` | Image files | Rarely needed |
| `media` | Audio and video files | Blocking media streaming |
| `stylesheet` | CSS files | Rarely needed |
| `SCREENSCRAPE` | Page text content scan — not a URL filter | Keyword monitoring |
| _(blank)_ | Uses `filterviaserverDefaultFilterTypes` from config | General purpose rules |

**Tip:** Using `main_frame` for blocking rules is usually sufficient and most efficient —
it catches page navigations without evaluating every background request. Add `xmlhttprequest`
if you need to block API-level access to a service (e.g. a student using a service's API
directly rather than its website).

#### Filter Modes

Each device group can operate in one of two filter modes, configured per group:

**defaultallow** — everything is permitted unless a BLOCK or BLOCKPAGE rule explicitly matches.
Use this for general monitoring where you want to allow most browsing but block specific sites.
This is the simplest mode to start with.

**defaultdeny** — everything is blocked unless an ALLOW rule explicitly matches.
Use this for controlled environments like testing sessions or specific class activities
where you want to permit only a defined set of sites. This mode is more restrictive
and requires carefully defined ALLOW rules to function correctly.

#### App Grouping for Classroom Management

App grouping is one of OSM's most powerful classroom management features. It allows
administrators to define named sets of allowed URLs (apps) and assign them to specific
groups or classrooms operating in **defaultdeny** mode.

**How it works:**
1. Define an app in the filter rules — a set of ALLOW rules with the same `App Name` value
   (e.g. `math-tools`)
2. Enable that app for specific groups/classrooms via the App management interface
3. Students in those groups only have access to the URLs permitted by their enabled apps
4. Different classrooms can have different app sets active simultaneously

**Example — Classroom activity management:**

A school has three concurrent classes:

| Class | App Enabled | Accessible Sites |
|---|---|---|
| Math test | `math-tools` | Khan Academy, Desmos, approved calculator |
| Reading | `reading-tools` | Approved reading platform, dictionary |
| Free period | `general-browsing` | Broader set of educational sites |

Each classroom's Chromebooks automatically get the correct restrictions based on their
group assignment — no manual intervention needed per device.

**Defining an app:**
Add ALLOW rules with a matching `App Name` value:
```
Action: ALLOW | URL: simple:khanacademy.org  | App Name: math-tools
Action: ALLOW | URL: simple:desmos.com       | App Name: math-tools
Action: ALLOW | URL: simple:wolframalpha.com | App Name: math-tools
```

Then enable `math-tools` for the relevant classroom groups. Students in those groups
can access Khan Academy, Desmos, and Wolfram Alpha — and nothing else if the group
is in `defaultdeny` mode.

#### Common Rule Examples

**Block a site for all users:**
```
URL: simple:youtube.com | Action: BLOCKPAGE | Priority: 10
```

**Allow a site for all users (in defaultdeny mode):**
```
URL: simple:google.com | Action: ALLOW | Priority: 10
```

**Block a site for one specific user:**
```
URL: simple:reddit.com | Action: BLOCKPAGE | Username: student@example.com | Priority: 10
```

**Block a site for all students matching a pattern (class of 2027):**
```
URL: simple:reddit.com | Action: BLOCKPAGE | Username: regex:^27[a-z]+@example\.com$ | Priority: 10
```

**Alert admin when a student visits a specific site:**
```
URL: simple:example.com | Action: TRIGGER | App Name: admin@example.com | Priority: 10
```

**Alert admin when a keyword appears on a page:**
```
Resource Type: SCREENSCRAPE | Action: TRIGGER | Initiator: 1,badword|otherword | App Name: admin@example.com
```

**Block page containing a keyword:**
```
Resource Type: SCREENSCRAPE | Action: BLOCKPAGE | Initiator: 3,badword
```

**Suppress a trigger for a specific safe URL (evaluated before the TRIGGER rule):**
```
URL: https://safepage.com | Action: TRIGGER_EXEMPT | Priority: 20
```
_(TRIGGER rule at Priority: 10 — TRIGGER_EXEMPT fires first since 20 > 10)_

#### Rule Testing Tips

After adding or editing a rule, verify it is working as expected:

1. Have a test device trigger the rule (visit the target URL as the target user)
2. Go to `/?route=Monitor\Filterlog`
3. Search by date and Action (e.g. `BLOCKPAGE`, `TRIGGER`, `BLOCKNOTIFY`)
4. Confirm the expected entries appear with the correct URL, username, and action

If a rule is not firing, check:
- **Priority** — a higher-priority rule may be matching first and stopping evaluation
- **Resource Type** — the request type may not match your rule's resource type setting
- **Username** — verify the regex or exact match against the actual email format
- **URL matching mode** — substring matching requires the URL to start with the value,
  not just contain it anywhere

> **Full field reference** is available in the admin UI at `/?route=Admin\Serverfilteredit`
> when adding or editing a rule.

### Storage Recommendations

OSM writes frequently to `$dataDir/clients/` — every device upload (default every 9 seconds)
writes approximately 10 files including a screenshot, session data, tabs, and metadata.
Storage performance directly affects how well the server keeps up with active clients.

**SSD or NVMe is strongly recommended for the system drive.**

A typical mid-range SATA SSD (~500MB/s sequential, ~80,000 IOPS random) with OSM's
~10 file writes per upload at 9 second intervals handles approximately:

- **~500 concurrent clients** — comfortable, plenty of headroom
- **~1000 concurrent clients** — manageable, watch MySQL buffer pool
- **~2000+ concurrent clients** — start considering NVMe or a dedicated drive for `$dataDir/clients/`

NVMe pushes these limits significantly higher — you will likely hit PHP-FPM or MySQL
limits before storage becomes a concern.

**For platter (HDD) based systems** — mount `$dataDir/clients/` on a dedicated SSD or NVMe
drive to separate high-frequency TempDB writes from the main system. A ramdisk (tmpfs)
is also an option for maximum performance but session and screenshot data is lost on reboot:

```bash
# /etc/fstab — ramdisk for clients directory (adjust size for your deployment)
tmpfs /var/www/osm-data/clients tmpfs defaults,size=2G 0 0
```

Size guide for ramdisk:
- 500 clients: 1G
- 1000 clients: 2G
- 2000 clients: 4G

> **Note:** If `debug` is enabled in `config.php` the server writes full request and
> response JSON for every upload. This significantly increases I/O load and should
> not be left enabled on production platter-based systems.

### PHP Configuration

The default PHP settings are conservative and will need tuning for production deployments.
The following settings are a recommended starting point tested with approximately 700 concurrent users.

`make install` (or `make fpm` on its own) writes OSM's PHP settings to two drop-in files
and leaves the packaged `php.ini` and `pool.d/www.conf` untouched. Keep it that way: a
locally edited packaged config file can make a later PHP update stop at a config file
prompt, and unattended-upgrades then skips that update.

- `/etc/php/8.4/fpm/pool.d/zz-osm.conf` sets the pool size (default 20 workers):
  `make fpm FPM_MAX_CHILDREN=100 FPM_START=20 FPM_MIN_SPARE=10 FPM_MAX_SPARE=40`
- `/etc/php/8.4/fpm/conf.d/99-osm.ini` sets `date.timezone` to the system timezone
  (override with `OSM_TZ=America/Chicago`). Without it PHP logs in UTC.

`make fpm` tests the configuration with `php-fpm8.4 -t` before reloading and removes its
files again if php-fpm rejects them. `make check` reports the worker count and timezone, and
warns when the php-fpm log shows the worker limit was reached.

`max_execution_time` and `post_max_size` are already set for OSM in the nginx site
(`fastcgi_param PHP_VALUE`). Other `php.ini` settings, such as a larger `memory_limit` for
large classroom data, can go in `99-osm.ini`:

```ini
; Increase memory limit for screenshot processing and large classroom data
memory_limit = 256M
```

Then reload PHP-FPM with `systemctl reload php8.4-fpm`.

**PHP-FPM pool settings for larger deployments** (pass these to `make fpm`, or put them in `zz-osm.conf`):
```ini
; Tested with 500-800 concurrent users on a 64GB server
pm = dynamic
pm.max_children = 100
pm.start_servers = 20
pm.min_spare_servers = 10
pm.max_spare_servers = 40
pm.max_requests = 500
```

**Capacity estimates** based on `pm.max_children` (assumes ~75ms avg request time, 9 second upload interval):

| `pm.max_children` | Comfortable Max | Hard Limit |
|---|---|---|
| `5` (Debian package default) | ~70 users | ~150 users |
| `20` (`make fpm` default) | ~280 users | ~600 users |
| `50` | ~700 users | ~1,500 users |
| `100` | ~1,500 users | ~3,000 users |
| `150` | ~2,000 users | ~4,000 users |

> **Note:** If screenscrape is enabled for all users it adds a request every 20 seconds per
> device on top of the 9-second upload cycle, increasing request rate by ~45%. Factor this
> in when planning capacity for large deployments.

Verify settings after reload:
```bash
php-fpm8.4 -tt 2>&1 | grep "pm"
```

### MariaDB Tuning

Default MariaDB settings are conservative and will need tuning for production deployments.
The following settings are recommended based on production experience with ~700 concurrent users
on a server with 64GB RAM shared across several containers.

Edit `/etc/mysql/mariadb.conf.d/50-server.cnf`:

```ini
# Thread pool — more efficient than one-thread-per-connection under high concurrency
thread_handling         = pool-of-threads
thread_pool_size        = 16
thread_cache_size       = 100
max_connections         = 200

# InnoDB buffer pool — most important setting for read performance.
# Size this against your DAILY ROW VOLUME, not just total RAM. See the
# sizing guidance below the config block.
innodb_buffer_pool_size        = 24G

# Reduce fsync on every commit for write performance.
# Setting 2 flushes once per second instead of per transaction.
# Risk: lose up to 1 second of transactions on unexpected power failure.
# Acceptable for OSM's filter log insert workload.
innodb_flush_log_at_trx_commit = 2

# Larger log file reduces checkpoint flush frequency
innodb_log_file_size           = 512M

# Temp tables — slightly larger for complex GROUP BY queries in usage reports
tmp_table_size                 = 64M
max_heap_table_size            = 64M
```

After making changes restart MariaDB:
```bash
systemctl restart mariadb
```

**Verify buffer pool size took effect:**
```bash
mysql -u root -p -e "SHOW VARIABLES LIKE 'innodb_buffer_pool_size';"
```

**Monitor buffer pool hit ratio** — should be 99%+ after warmup under production load:
```bash
mysql -u root -p -e "SELECT (1 - (SELECT variable_value FROM information_schema.global_status WHERE variable_name = 'Innodb_buffer_pool_reads') / (SELECT variable_value FROM information_schema.global_status WHERE variable_name = 'Innodb_buffer_pool_read_requests')) * 100 AS hit_ratio_pct;"
```

**Sizing the buffer pool**

The buffer pool caches table and index pages in memory. Reads that hit the pool are fast;
reads that miss go to disk. Because `tbl_filter_log` grows quickly, the pool needs to be
sized against how much *recent history* you want cached, not just as a percentage of RAM.

Production experience on a 64GB server:

| Buffer pool | Filter log size | Result |
|---|---|---|
| 128M (default) | 1.5GB | 83% hit ratio — frequent disk reads, slow reports |
| 8G | 9.4GB (~1.8M rows/day) | 99% hit ratio for recent dates, but only ~1 day cached |
| 24G | 9.4GB+ (~4M rows/day) | Several days cached — historical queries much faster |

A concrete example of why this matters: a full-day domain summary query took **62 seconds**
against an uncached historical date with an 8G pool, and **10 seconds** once the same data
was cached in a 24G pool. The query and indexes were identical — the difference was entirely
disk reads versus memory.

**Guidance:**
- Estimate your daily row volume (`SELECT COUNT(*) FROM tbl_filter_log WHERE date = CURDATE()`)
- Decide how many days of history should stay fast (reports typically query the last 7-30 days)
- Size the pool to hold that working set, up to roughly 50-70% of RAM dedicated to MariaDB
- On a shared server, account for other containers

> **Note:** Changing `innodb_buffer_pool_size` requires a full MariaDB **restart**, not a reload.
> Expect the hit ratio to start low and climb over the first hour as the pool warms up.
> The `thread_pool_size` should roughly match your PHP-FPM `pm.max_children` setting.

**Growth planning:** at ~4M rows per day during a school term, `tbl_filter_log` can add
several GB per month. Plan a retention policy — many districts purge annually during summer
break, keeping 12-18 months of history.

### Example Setup (tested in debian trixie systemd-nspawn container as root)

The `Makefile` does the whole server setup: packages, code, an HTTPS-only nginx
site (port 443, nothing on port 80), a certificate, `osm-data` and the database.
Run `make help` for the individual targets.

```
#get the code
apt -y install git make
git clone https://github.com/unixabg/open-screen-monitor.git --branch next
cd open-screen-monitor

#optional: use your own certificate instead of a generated self signed one
#mkdir -p /var/www/cert && cp fullchain.pem /var/www/cert/server.cer && cp privkey.pem /var/www/cert/server.key

#install packages, code (/var/www/osm), cert, nginx site, /var/www/osm-data and database
make install OSM_HOST=osm.yourdomain.com

#add client secret from google, then fix its permissions
cp /path/to/client_secret.json /var/www/osm-data/
make data

#verify
make check
```

- HTTPS only: if `/var/www/cert/server.cer` and `server.key` do not exist, `make cert`
  creates a self signed certificate for `OSM_HOST` (default `hostname -f`) with
  `subjectAltName=DNS:osm.yourdomain.com,DNS:osm`. Chromebooks must trust it, see
  Self Signed Certificate above. To switch to a real certificate later, replace the two
  files and run `service nginx reload`. Use https:// for the OAuth JavaScript origins
  and redirect URIs.
- Let's Encrypt: `make letsencrypt OSM_HOST=osm.yourdomain.com` (optionally `LE_EMAIL=you@yourdomain.com`)
  replaces the self signed certificate with a Let's Encrypt one.
  - Needs `osm.yourdomain.com` to resolve publicly to your router, and the router to
    forward TCP 80 (and 443 for clients outside) to this server.
  - Port 80 is only open while Let's Encrypt validates: nginx enables a listener that
    serves just `/.well-known/acme-challenge/` and closes it again, both for this first
    certificate and for every renewal (certbot renewal hooks in
    `/etc/letsencrypt/renewal-hooks`). Keep the port 80 forward in place so renewals work.
  - Before asking Let's Encrypt, it checks that its port 80 listener serves a test file
    locally. On failure it explains the common causes: an old NAT forward that redirects
    port 80 to HTTPS, a firewall that does not pass TCP 80, a forward to the wrong IP, or DNS.
  - Try it first with `LE_TEST=yes`, a dry run against Let's Encrypt staging that does not
    count against rate limits or change the certificate.
  - `/var/www/cert/server.cer` and `server.key` become links into `/etc/letsencrypt/live/osm/`;
    the self signed pair is kept as `*.selfsigned`. Chromebooks trust the certificate
    without the Google Admin certificate setup.
- Existing installs on port 80: `rm /etc/nginx/sites-enabled/osm && make nginx`
  installs the HTTPS-only site (an existing site is otherwise left alone).
- `make install` is safe to re-run. It will not replace an existing nginx site
  (`/etc/nginx/sites-enabled/osm`) or touch an existing database; if `db.json`
  is missing but the `osm` database exists, it stops instead of dropping it.
- The database password is generated and written to `/var/www/osm-data/db.json`
  (mode 640, owned by www-data).
- Defaults can be overridden, e.g. `make install PHP_VER=8.3 OSM_DIR=/srv/osm`.
  Variables: `PHP_VER`, `OSM_DIR`, `DATA_DIR`, `DB_NAME`, `DB_USER`, `WEB_USER`, `NGINX_SITE`, `NGINX_IPV6`, `CERT_DIR`, `OSM_HOST`, `CERT_DAYS`, `LE_EMAIL`, `LE_TEST`, `FPM_MAX_CHILDREN`, `FPM_START`, `FPM_MIN_SPARE`, `FPM_MAX_SPARE`, `OSM_TZ`.
- IPv4-only hosts: if the kernel has no IPv6, the nginx site is installed
  without the `[::]:443` listen line. Force it either way with `NGINX_IPV6=no` or `NGINX_IPV6=yes`.
- Upgrading: `git pull && make upgrade`, then if the schema version changed, sign in
  as an admin and open `/?route=Admin\Upgrade`.
- `make uninstall` removes the code and nginx site but keeps data and database.
  `make purge CONFIRM=yes` also deletes `/var/www/osm-data` and the database. It keeps a
  Let's Encrypt certificate, because Let's Encrypt limits how many certificates for the same
  name it issues per week; after a reinstall `make letsencrypt` reuses it.
  `make letsencrypt-remove CONFIRM=yes` deletes the certificate, its renewal hooks and the
  port 80 site, and goes back to a self signed certificate.
