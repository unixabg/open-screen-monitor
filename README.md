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
- Install the server side PHP
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

Edit `/etc/php/8.4/fpm/php.ini` (adjust version as needed):

```ini
; Increase execution time for large filter log queries and Google Classroom sync
max_execution_time = 300

; Increase memory limit for screenshot processing and large classroom data
memory_limit = 256M

; post_max_size covers screenshot uploads from the extension (base64 encoded JPEG
; plus tab data). OSM does not use PHP file uploads so upload_max_filesize is
; not relevant — post_max_size is what matters. 32M provides comfortable headroom.
post_max_size = 32M

; Set to your local timezone for correct log timestamps
date.timezone = "America/Chicago"
```

After making changes reload PHP-FPM:
```bash
systemctl reload php8.4-fpm
```

**PHP-FPM pool settings** — for larger deployments also review `/etc/php/8.4/fpm/pool.d/www.conf`:
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
| `50` (default) | ~700 users | ~1,500 users |
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
# Set to 50-70% of RAM dedicated to MariaDB. With a small pool, reads hit disk
# instead of memory — OSM's filter log hit ratio dropped to 83% at 128MB.
# At 8GB on a 64GB server the hit ratio climbed to 99%+ after warmup.
innodb_buffer_pool_size        = 8G

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

> **Note:** Scale `innodb_buffer_pool_size` to your available RAM. A general guideline is
> 50-70% of RAM dedicated to MariaDB. On a shared server, account for other containers.
> The `thread_pool_size` should roughly match your PHP-FPM `pm.max_children` setting.

### Example Setup (tested in debian trixie systemd-nspawn container as root)

```
#get the code
git clone https://github.com/unixabg/open-screen-monitor.git --branch next /var/www/osm/

#install dependencies
apt -y install nginx php8.4-fpm php8.4-xml php8.4-curl php8.4-odbc php8.4-mysql php8.4-zip mariadb-server git

#configure nginx
rm -r /etc/nginx/sites-enabled/default
cp /var/www/osm/sample_config/nginx-site /etc/nginx/sites-enabled/osm
service nginx restart

#setup osm-dir
mkdir /var/www/osm-data
chown www-data:www-data /var/www/osm-data

#add client secret from google
nano /var/www/osm-data/client_secret.json

#setup db
DBPASS=`cat /proc/sys/kernel/random/uuid`

mysql << EOL
DROP DATABASE IF EXISTS osm;
CREATE DATABASE osm;

DROP USER IF EXISTS osm@localhost;
CREATE USER 'osm'@'localhost' IDENTIFIED BY '${DBPASS}';
GRANT ALL PRIVILEGES ON osm.* TO 'osm'@'localhost';
FLUSH PRIVILEGES;
EOL

mysql -h localhost -u osm -p${DBPASS} osm < /var/www/osm/sample_config/setup.sql

echo "{\"hostname\":\"localhost\",\"user\":\"osm\",\"password\":\"${DBPASS}\",\"dbname\":\"osm\"}" > /var/www/osm-data/db.json
```
