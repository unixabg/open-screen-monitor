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
; Increase max children for high concurrency (700+ users)
pm.max_children = 50
pm.start_servers = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 20
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
