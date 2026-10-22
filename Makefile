# Makefile for Open Screen Monitor
#
# Fresh install on Debian trixie (as root):
#   git clone https://github.com/unixabg/open-screen-monitor.git
#   cd open-screen-monitor
#   make install            (HTTPS on 443 with a self signed cert, see `make cert`)
#   cp /path/to/client_secret.json /var/www/osm-data/ && make data
#   make check
#
# Any variable below can be overridden, e.g.
#   make install OSM_DIR=/srv/osm PHP_VER=8.3

SHELL := /bin/bash
.ONESHELL:
.SHELLFLAGS := -ec

PHP_VER    ?= 8.4
OSM_DIR    ?= /var/www/osm
DATA_DIR   ?= /var/www/osm-data
DB_NAME    ?= osm
DB_USER    ?= osm
WEB_USER   ?= www-data
NGINX_SITE ?= /etc/nginx/sites-enabled/osm
# auto: drop the [::] listen when the kernel has no IPv6 (no /proc/net/if_inet6)
NGINX_IPV6 ?= auto
# HTTPS only: nginx reads $(CERT_DIR)/server.cer and server.key
CERT_DIR   ?= /var/www/cert
OSM_HOST   ?= $(shell hostname -f 2>/dev/null || hostname)
CERT_DAYS  ?= 3650
# make letsencrypt: HTTP-01 on a port 80 listener that is up only while certbot validates
# make fpm: drop-in files, so the packaged www.conf and php.ini stay stock
# (a locally edited conffile can make unattended-upgrades hold back PHP updates)
FPM_MAX_CHILDREN ?= 20
FPM_START        ?= 4
FPM_MIN_SPARE    ?= 2
FPM_MAX_SPARE    ?= 6
OSM_TZ           ?= $(or $(shell readlink /etc/localtime 2>/dev/null | sed -n 's|.*/zoneinfo/||p'),UTC)
# Values given on the command line (or in the environment) are applied as given;
# otherwise make fpm never lowers what a server is already tuned to.
FPM_EXPLICIT     := $(if $(filter command% environment%,$(foreach v,FPM_MAX_CHILDREN FPM_START FPM_MIN_SPARE FPM_MAX_SPARE,$(origin $(v)))),yes,no)
FPM_POOL_FILE     = /etc/php/$(PHP_VER)/fpm/pool.d/zz-osm.conf
FPM_INI_FILE      = /etc/php/$(PHP_VER)/fpm/conf.d/99-osm.ini
LE_EMAIL     ?=
LE_TEST      ?= no
LE_SERVER    ?=
LE_CERT_NAME ?= osm
ACME_DIR     ?= /var/www/acme
LE_HOOKS     := /etc/letsencrypt/renewal-hooks
ACME_SITE    := /etc/nginx/sites-available/osm-acme

PACKAGES = nginx mariadb-server curl openssl \
	php$(PHP_VER)-fpm php$(PHP_VER)-xml php$(PHP_VER)-curl \
	php$(PHP_VER)-odbc php$(PHP_VER)-mysql php$(PHP_VER)-zip

SRC_DIR := $(CURDIR)

.PHONY: all help deps install install-files cert letsencrypt letsencrypt-remove nginx fpm data db check upgrade uninstall purge

all: help

help:
	@echo "Open Screen Monitor make targets (run as root):"
	echo "  install    deps + install-files + cert + nginx + data + db"
	echo "  deps       apt install nginx, mariadb and php$(PHP_VER) packages"
	echo "  install-files  copy php/ and sample_config/ to $(OSM_DIR)"
	echo "  cert       self signed cert for $(OSM_HOST) in $(CERT_DIR) (skipped if one exists)"
	echo "  letsencrypt  replace the cert with one from Let's Encrypt for OSM_HOST; port 80 is"
	echo "             opened only while validating, at issue and at each renewal (LE_TEST=yes: dry run)"
	echo "  letsencrypt-remove  delete the Let's Encrypt cert, hooks and port 80 site (needs CONFIRM=yes)"
	echo "  nginx      install the HTTPS-only nginx site (skipped if $(NGINX_SITE) exists;"
	echo "             IPv4-only if the kernel has no IPv6, or with NGINX_IPV6=no)"
	echo "  fpm        php$(PHP_VER)-fpm pool size and timezone ($(OSM_TZ)) as drop-in files; keeps a"
	echo "             higher existing worker count unless FPM_MAX_CHILDREN=... is given (default $(FPM_MAX_CHILDREN))"
	echo "  data       create $(DATA_DIR) and fix ownership/permissions"
	echo "  db         create database and $(DATA_DIR)/db.json (skipped if db.json exists)"
	echo "  check      smoke test the install"
	echo "  upgrade    replace the code in $(OSM_DIR), keep data and database"
	echo "  uninstall  remove code and nginx site, keep data and database"
	echo "  purge      uninstall + delete $(DATA_DIR) and the database (needs CONFIRM=yes)"

deps:
	@echo "Installing packages ..."
	export DEBIAN_FRONTEND=noninteractive
	apt-get update
	apt-get -y install $(PACKAGES)

install: deps install-files cert nginx fpm data db
	@echo
	echo "Open Screen Monitor installed."
	if [ ! -f "$(DATA_DIR)/client_secret.json" ]; then
		echo "Next: copy client_secret.json from the Google console to $(DATA_DIR)/,"
		echo "      then run 'make data' to set its permissions and 'make check'."
	else
		echo "Next: run 'make check'."
	fi

# The code is swapped in as a whole so files removed upstream do not linger.
# It stays owned by root; the web server only writes to $(DATA_DIR).
install-files:
	@if [ "$$(realpath -m "$(SRC_DIR)")" = "$$(realpath -m "$(OSM_DIR)")" ]; then
		echo "Source is $(OSM_DIR); nothing to copy."
		exit 0
	fi
	echo "Installing files to $(OSM_DIR) ..."
	mkdir -p "$(OSM_DIR)"
	for d in php sample_config; do
		rm -rf "$(OSM_DIR)/$$d.new" "$(OSM_DIR)/$$d.old"
		cp -a "$(SRC_DIR)/$$d" "$(OSM_DIR)/$$d.new"
		[ -e "$(OSM_DIR)/$$d" ] && mv "$(OSM_DIR)/$$d" "$(OSM_DIR)/$$d.old"
		mv "$(OSM_DIR)/$$d.new" "$(OSM_DIR)/$$d"
		rm -rf "$(OSM_DIR)/$$d.old"
	done
	chown -R root:root "$(OSM_DIR)/php" "$(OSM_DIR)/sample_config"

# Put your own server.cer (full chain) and server.key in $(CERT_DIR) before
# `make install` to use them instead; replace them later and `service nginx reload`.
cert:
	@if [ -f "$(CERT_DIR)/server.cer" ] && [ -f "$(CERT_DIR)/server.key" ]; then
		echo "Certificate exists in $(CERT_DIR); leaving it alone."
		exit 0
	fi
	host="$(OSM_HOST)"
	san="DNS:$$host"
	[ "$${host%%.*}" != "$$host" ] && san="$$san,DNS:$${host%%.*}"
	echo "Creating self signed certificate for $$host ($$san) ..."
	mkdir -p "$(CERT_DIR)"
	chmod 755 "$(CERT_DIR)"
	umask 077
	openssl req -x509 -newkey rsa:4096 -sha256 -days $(CERT_DAYS) -nodes \
		-keyout "$(CERT_DIR)/server.key" -out "$(CERT_DIR)/server.cer" \
		-subj "/CN=$$host" -addext "subjectAltName=$$san"
	chmod 600 "$(CERT_DIR)/server.key"
	chmod 644 "$(CERT_DIR)/server.cer"
	echo "  wrote $(CERT_DIR)/server.cer and server.key"
	echo "  Chromebooks need this cert trusted in Google Admin (see README, Self Signed Certificate)."

# Let's Encrypt over HTTP-01. Port 80 (forwarded from the router) must reach this
# server, but nginx only listens on it while certbot validates: the open/close
# scripts below wrap this first request and, as certbot renewal hooks, every
# renewal. The certificate is linked into $(CERT_DIR), so the nginx site is unchanged.
letsencrypt:
	@host="$(OSM_HOST)"
	case "$$host" in
		*.*) ;;
		*) echo "ERROR: OSM_HOST must be the public DNS name: make letsencrypt OSM_HOST=osm.yourdomain.com" >&2; exit 1;;
	esac
	if [ ! -e "$(NGINX_SITE)" ]; then
		echo "ERROR: $(NGINX_SITE) not found; run 'make install' first." >&2
		exit 1
	fi
	echo "Installing certbot ..."
	DEBIAN_FRONTEND=noninteractive apt-get -y -q install certbot >/dev/null
	mkdir -p "$(ACME_DIR)" "$(LE_HOOKS)/pre" "$(LE_HOOKS)/post" "$(LE_HOOKS)/deploy"
	chmod 755 "$(ACME_DIR)"
	listen6="	listen [::]:80;"
	ipv6="$(NGINX_IPV6)"
	if [ "$$ipv6" = auto ]; then
		[ -e /proc/net/if_inet6 ] && ipv6=yes || ipv6=no
	fi
	[ "$$ipv6" = no ] && listen6=""
	cat > "$(ACME_SITE)" <<EOF
	# Open Screen Monitor: temporary port 80 listener for Let's Encrypt HTTP-01.
	# Linked into sites-enabled only while certbot validates; see $(LE_HOOKS).
	server {
		listen 80;
	$$listen6
		server_name $$host;
		location /.well-known/acme-challenge/ {
			root $(ACME_DIR);
		}
		location / {
			return 444;
		}
	}
	EOF
	cat > "$(LE_HOOKS)/pre/osm-open-port-80" <<EOF
	#!/bin/sh
	# Open Screen Monitor: enable the port 80 ACME listener before validation.
	ln -sf "$(ACME_SITE)" /etc/nginx/sites-enabled/osm-acme
	nginx -t -q && service nginx reload >/dev/null
	sleep 2
	EOF
	cat > "$(LE_HOOKS)/post/osm-close-port-80" <<EOF
	#!/bin/sh
	# Open Screen Monitor: remove the port 80 ACME listener after validation.
	rm -f /etc/nginx/sites-enabled/osm-acme
	nginx -t -q && service nginx reload >/dev/null
	sleep 2
	EOF
	cat > "$(LE_HOOKS)/deploy/osm-reload-nginx" <<EOF
	#!/bin/sh
	# Open Screen Monitor: load the renewed certificate.
	service nginx reload >/dev/null
	EOF
	chmod 755 "$(LE_HOOKS)"/pre/osm-open-port-80 "$(LE_HOOKS)"/post/osm-close-port-80 "$(LE_HOOKS)"/deploy/osm-reload-nginx
	args="certonly --webroot -w $(ACME_DIR) -d $$host --cert-name $(LE_CERT_NAME) --non-interactive --agree-tos --keep-until-expiring"
	if [ -n "$(LE_EMAIL)" ]; then args="$$args --email $(LE_EMAIL)"; else args="$$args --register-unsafely-without-email"; fi
	[ -n "$(LE_SERVER)" ] && args="$$args --server $(LE_SERVER)"
	[ "$(LE_TEST)" = yes ] && args="$$args --dry-run"
	log=$$(mktemp)
	token="osm-preflight-$$$$"
	trap 'rm -f "$$log" "$(ACME_DIR)/.well-known/acme-challenge/$$token"; "$(LE_HOOKS)/post/osm-close-port-80"; echo "Port 80 closed."' EXIT
	echo "Opening port 80 for validation ..."
	"$(LE_HOOKS)/pre/osm-open-port-80"
	# preflight: our own listener must serve a challenge file on port 80 locally
	mkdir -p "$(ACME_DIR)/.well-known/acme-challenge"
	echo "$$token" > "$(ACME_DIR)/.well-known/acme-challenge/$$token"
	got=$$(curl -s --noproxy '*' --max-time 5 -H "Host: $$host" "http://127.0.0.1/.well-known/acme-challenge/$$token" || true)
	if [ "$$got" != "$$token" ]; then
		echo "ERROR: the port 80 listener on this server is not serving challenge files." >&2
		echo "       Something else may hold port 80 here; check: ss -ltnp 'sport = :80'" >&2
		exit 1
	fi
	echo "  port 80 listener answers locally"
	set +e
	certbot $$args 2>&1 | tee "$$log"
	rc=$${PIPESTATUS[0]}
	set -e
	if [ "$$rc" -ne 0 ]; then
		echo >&2
		if grep -q 'Invalid response from https://' "$$log"; then
			echo "Port 80 for $$host was redirected to HTTPS before reaching this server: something" >&2
			echo "else answers port 80 on your public IP (an old NAT forward or a redirect on the firewall)." >&2
			echo "Point the TCP 80 forward at this server. Test from outside: curl -sI http://$$host/" >&2
		elif grep -qi 'Timeout during connect' "$$log"; then
			echo "Let's Encrypt could not connect to port 80 on $$host, but the listener answers here," >&2
			echo "so the firewall is not passing TCP 80 to this server: check the NAT forward and the" >&2
			echo "WAN pass rule for port 80 (they should match the ones for 443)." >&2
		elif grep -qi 'Connection refused' "$$log"; then
			echo "Port 80 on $$host refused the connection: the forward points at a host where nothing" >&2
			echo "listens on 80. Check the forward's target IP is this server." >&2
		elif grep -qiE 'DNS problem|NXDOMAIN|no valid A records' "$$log"; then
			echo "DNS problem: $$host must resolve publicly to your public IP." >&2
		else
			echo "certbot failed (output above). If validation of $$host failed, check that:" >&2
			echo "  - $$host resolves publicly to your router's public IP" >&2
			echo "  - the router forwards TCP 80 to this server (443 too, for clients outside)" >&2
			echo "  - no firewall blocks port 80 in front of this server" >&2
		fi
		echo "Retest without using up rate limits: make letsencrypt LE_TEST=yes OSM_HOST=$$host" >&2
		exit 1
	fi
	if [ "$(LE_TEST)" = yes ]; then
		echo "Dry run passed. Get the real certificate with: make letsencrypt OSM_HOST=$$host"
		exit 0
	fi
	live=/etc/letsencrypt/live/$(LE_CERT_NAME)
	mkdir -p "$(CERT_DIR)"
	for pair in "server.cer fullchain.pem" "server.key privkey.pem"; do
		set -- $$pair
		f="$(CERT_DIR)/$$1"
		if [ -e "$$f" ] && [ ! -L "$$f" ]; then mv "$$f" "$$f.selfsigned"; fi
		ln -sfn "$$live/$$2" "$$f"
	done
	nginx -t
	service nginx reload
	echo "Let's Encrypt certificate installed for $$host:"
	echo "  $(CERT_DIR)/server.cer -> $$live/fullchain.pem"
	echo "  renewals run from certbot.timer and open port 80 only while validating;"
	echo "  keep the router forwarding TCP 80 to this server so they can succeed."

# Separate from purge on purpose: Let's Encrypt limits how many certificates can be
# issued for the same name per week, so purge keeps the certificate for reuse.
letsencrypt-remove:
	@if [ "$(CONFIRM)" != yes ]; then
		echo "letsencrypt-remove deletes the Let's Encrypt certificate, its renewal hooks and the" >&2
		echo "port 80 site. Getting a new one later counts against Let's Encrypt's weekly limits." >&2
		echo "Run: make letsencrypt-remove CONFIRM=yes" >&2
		exit 1
	fi
	echo "Removing Let's Encrypt setup ..."
	if command -v certbot >/dev/null && [ -d /etc/letsencrypt/live/$(LE_CERT_NAME) ]; then
		certbot delete --cert-name $(LE_CERT_NAME) --non-interactive
	fi
	rm -f "$(LE_HOOKS)/pre/osm-open-port-80" "$(LE_HOOKS)/post/osm-close-port-80" \
		"$(LE_HOOKS)/deploy/osm-reload-nginx" /etc/nginx/sites-enabled/osm-acme "$(ACME_SITE)"
	for f in server.cer server.key; do
		if [ -L "$(CERT_DIR)/$$f" ] && readlink "$(CERT_DIR)/$$f" | grep -q '^/etc/letsencrypt/'; then
			rm -f "$(CERT_DIR)/$$f"
			if [ -f "$(CERT_DIR)/$$f.selfsigned" ]; then mv "$(CERT_DIR)/$$f.selfsigned" "$(CERT_DIR)/$$f"; fi
		fi
	done
	if [ -e "$(NGINX_SITE)" ]; then
		$(MAKE) --no-print-directory cert
		nginx -t && service nginx reload
	fi
	echo "Let's Encrypt setup removed."

nginx: cert
	@echo "Configuring nginx ..."
	if [ -e "$(NGINX_SITE)" ]; then
		echo "  $(NGINX_SITE) exists; leaving it alone."
	else
		sed -e 's#/var/www/osm/php/public#$(OSM_DIR)/php/public#' \
			-e 's#/var/www/osm-data/#$(DATA_DIR)/#' \
			-e 's#php8\.4-fpm#php$(PHP_VER)-fpm#' \
			-e 's#/var/www/cert/#$(CERT_DIR)/#' \
			"$(SRC_DIR)/sample_config/nginx-site" > "$(NGINX_SITE)"
		ipv6="$(NGINX_IPV6)"
		if [ "$$ipv6" = auto ]; then
			[ -e /proc/net/if_inet6 ] && ipv6=yes || ipv6=no
		fi
		if [ "$$ipv6" = no ]; then
			sed -i '/^[[:space:]]*listen \[::\]:/d' "$(NGINX_SITE)"
			echo "  no IPv6: removed the [::] listen lines (override with NGINX_IPV6=yes)"
		fi
		rm -f /etc/nginx/sites-enabled/default
	fi
	nginx -t
	if service nginx status >/dev/null 2>&1; then
		service nginx reload
	else
		service nginx restart
	fi

# Pool size and timezone go in our own files instead of the packaged www.conf and
# php.ini. The config is tested before reloading and our files are removed again
# if php-fpm rejects them, so a bad value cannot leave php-fpm stopped.
fpm:
	@echo "Configuring php$(PHP_VER)-fpm ..."
	# current effective [www] pool settings, from all files php-fpm loads
	pool(){ php-fpm$(PHP_VER) -tt 2>&1 | sed 's/^\[[^]]*\] NOTICE: //' \
		| awk -v k="$$1" '/^\[www\]$$/{w=1;next} /^\[/{w=0} w && $$1==k {print $$3; exit}'; }
	cur_mode=$$(pool pm); cur_max=$$(pool pm.max_children)
	cur_start=$$(pool pm.start_servers); cur_min=$$(pool pm.min_spare_servers); cur_spare=$$(pool pm.max_spare_servers)
	if [ "$(FPM_EXPLICIT)" = yes ]; then
		mode=dynamic; max=$(FPM_MAX_CHILDREN); start=$(FPM_START); min=$(FPM_MIN_SPARE); spare=$(FPM_MAX_SPARE)
		why="as given"
		if [ -n "$$cur_max" ] && [ "$$max" -lt "$$cur_max" ]; then why="lowered from $$cur_max as given"; fi
	elif [ -n "$$cur_max" ] && { [ -e "$(FPM_POOL_FILE)" ] || [ "$$cur_max" -gt $(FPM_MAX_CHILDREN) ]; }; then
		mode=$$cur_mode; max=$$cur_max; start=$$cur_start; min=$$cur_min; spare=$$cur_spare
		why="kept the existing setting; change with make fpm FPM_MAX_CHILDREN=..."
	else
		mode=dynamic; max=$(FPM_MAX_CHILDREN); start=$(FPM_START); min=$(FPM_MIN_SPARE); spare=$(FPM_MAX_SPARE)
		why="default, raised from $${cur_max:-unknown}"
	fi
	# keep the previous files so a rejected change can be undone exactly
	for f in "$(FPM_POOL_FILE)" "$(FPM_INI_FILE)"; do
		rm -f "$$f.osm-bak"
		if [ -e "$$f" ]; then cp -p "$$f" "$$f.osm-bak"; fi
	done
	cat > "$(FPM_POOL_FILE)" <<EOF
	; Open Screen Monitor (make fpm): pool size. Overrides the same settings in
	; www.conf, which is left as packaged. Change with make fpm FPM_MAX_CHILDREN=...
	; (make fpm keeps these values on later runs unless new ones are given.)
	[www]
	pm = $$mode
	pm.max_children = $$max
	pm.start_servers = $$start
	pm.min_spare_servers = $$min
	pm.max_spare_servers = $$spare
	EOF
	cat > "$(FPM_INI_FILE)" <<EOF
	; Open Screen Monitor (make fpm): overrides php.ini, which is left as packaged.
	date.timezone = "$(OSM_TZ)"
	EOF
	if ! out=$$(php-fpm$(PHP_VER) -t 2>&1); then
		echo "$$out" >&2
		for f in "$(FPM_POOL_FILE)" "$(FPM_INI_FILE)"; do
			if [ -e "$$f.osm-bak" ]; then mv -f "$$f.osm-bak" "$$f"; else rm -f "$$f"; fi
		done
		echo "ERROR: php-fpm rejected these settings; put the previous ones back, php-fpm left as it was." >&2
		exit 1
	fi
	rm -f "$(FPM_POOL_FILE).osm-bak" "$(FPM_INI_FILE).osm-bak"
	echo "  workers: $$max max ($$why), timezone: $(OSM_TZ)"
	if dpkg -V php$(PHP_VER)-fpm php$(PHP_VER)-common 2>/dev/null | grep -qE ' c /etc/php/.*/fpm/(php\.ini|pool\.d/www\.conf)$$'; then
		echo "  note: the packaged php.ini or www.conf has local edits (see dpkg -V php$(PHP_VER)-fpm)."
		echo "        The settings above override them; restoring the stock file keeps upgrades prompt-free."
	fi
	if service php$(PHP_VER)-fpm status >/dev/null 2>&1; then
		service php$(PHP_VER)-fpm reload
	else
		service php$(PHP_VER)-fpm restart
	fi

data:
	@echo "Setting up $(DATA_DIR) ..."
	mkdir -p "$(DATA_DIR)"
	chown $(WEB_USER):$(WEB_USER) "$(DATA_DIR)"
	chmod 750 "$(DATA_DIR)"
	for f in client_secret.json db.json permissions.tsv extension/key.pem; do
		if [ -f "$(DATA_DIR)/$$f" ]; then
			chown $(WEB_USER):$(WEB_USER) "$(DATA_DIR)/$$f"
			chmod 640 "$(DATA_DIR)/$$f"
		fi
	done

# Never drops anything: refuses to run if the database exists without db.json.
db: data
	@if [ -f "$(DATA_DIR)/db.json" ]; then
		echo "$(DATA_DIR)/db.json exists; skipping database setup."
		exit 0
	fi
	echo "Creating database $(DB_NAME) ..."
	if [ -n "$$(mysql -N -e "SHOW DATABASES LIKE '$(DB_NAME)'")" ]; then
		echo "ERROR: database $(DB_NAME) exists but $(DATA_DIR)/db.json is missing." >&2
		echo "       Restore db.json, or drop the database yourself first." >&2
		exit 1
	fi
	DBPASS=$$(cat /proc/sys/kernel/random/uuid)
	mysql <<EOL
	CREATE DATABASE $(DB_NAME);
	CREATE OR REPLACE USER '$(DB_USER)'@'localhost' IDENTIFIED BY '$${DBPASS}';
	GRANT ALL PRIVILEGES ON $(DB_NAME).* TO '$(DB_USER)'@'localhost';
	FLUSH PRIVILEGES;
	EOL
	mysql -h localhost -u $(DB_USER) -p"$${DBPASS}" $(DB_NAME) < "$(SRC_DIR)/sample_config/setup.sql"
	umask 077
	echo "{\"hostname\":\"localhost\",\"user\":\"$(DB_USER)\",\"password\":\"$${DBPASS}\",\"dbname\":\"$(DB_NAME)\"}" > "$(DATA_DIR)/db.json"
	chown $(WEB_USER):$(WEB_USER) "$(DATA_DIR)/db.json"
	chmod 640 "$(DATA_DIR)/db.json"
	echo "  wrote $(DATA_DIR)/db.json"

check:
	@fail=0
	ok(){ echo "  [PASS] $$*"; }
	ko(){ echo "  [FAIL] $$*"; fail=1; }
	echo "Checking Open Screen Monitor install ..."
	nginx -t >/dev/null 2>&1 && ok "nginx config valid" || ko "nginx config invalid (run nginx -t)"
	[ -S /run/php/php$(PHP_VER)-fpm.sock ] && ok "php$(PHP_VER)-fpm socket" || ko "php$(PHP_VER)-fpm socket missing"
	for f in db.json client_secret.json; do
		if su -s /bin/sh $(WEB_USER) -c "test -r '$(DATA_DIR)/$$f'"; then
			ok "$$f readable by $(WEB_USER)"
			m=$$(stat -c %a "$(DATA_DIR)/$$f")
			[ $$(( 0$$m & 7 )) -eq 0 ] || ko "$$f is world-readable (mode $$m); run 'make data'"
		else
			ko "$(DATA_DIR)/$$f missing or not readable by $(WEB_USER)"
		fi
	done
	su -s /bin/sh $(WEB_USER) -c "test -w '$(DATA_DIR)'" && ok "$(DATA_DIR) writable by $(WEB_USER)" || ko "$(DATA_DIR) not writable by $(WEB_USER)"
	applied=$$(mysql -N $(DB_NAME) -e "SELECT value FROM tbl_config WHERE name='dbSchemaVersion'" 2>/dev/null || true)
	required=$$(grep -o 'DB_SCHEMA_VERSION = [0-9]*' "$(OSM_DIR)/php/Route/Admin/Upgrade.php" | grep -o '[0-9]*$$' || true)
	[ -n "$$applied" ] && [ "$$applied" = "$$required" ] && ok "schema version $$applied" \
		|| ko "schema version is '$$applied', code requires '$$required' (see /?route=Admin\\Upgrade)"
	if [ -f "$(CERT_DIR)/server.cer" ]; then
		if openssl x509 -checkend $$((14*86400)) -noout -in "$(CERT_DIR)/server.cer" >/dev/null; then
			ok "certificate valid past 14 days ($$(openssl x509 -enddate -noout -in "$(CERT_DIR)/server.cer" | cut -d= -f2))"
		else
			ko "certificate in $(CERT_DIR) expires within 14 days or has expired"
		fi
	else
		ko "no certificate at $(CERT_DIR)/server.cer (run 'make cert')"
	fi
	# -k: a self signed cert is expected; trust is set up on the Chromebooks
	body=$$(curl -sk --noproxy '*' -w '\n%{http_code}' https://localhost/ || true)
	code=$${body##*$$'\n'}
	case "$$code" in 200|302) ok "GET https://localhost/ -> $$code";; *) ko "GET https://localhost/ -> $$code";; esac
	if grep -qiE 'Missing|Fatal error|Parse error|Uncaught' <<<"$$body"; then
		ko "page error: $$(grep -oiE '(Missing|Fatal error|Parse error|Uncaught).{0,100}' <<<"$$body" | head -1)"
	fi
	code=$$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' --max-time 5 http://localhost/ || true)
	if [ -L "$(CERT_DIR)/server.cer" ] && readlink "$(CERT_DIR)/server.cer" | grep -q '^/etc/letsencrypt/'; then
		if systemctl is-enabled certbot.timer >/dev/null 2>&1; then
			ok "Let's Encrypt certificate; certbot.timer enabled"
		else
			echo "  [WARN] Let's Encrypt certificate but certbot.timer is not enabled; renewals will not run"
		fi
	fi
	n=$$(php-fpm$(PHP_VER) -tt 2>&1 | sed -n 's/.*pm\.max_children = \([0-9]*\).*/\1/p' | head -1)
	if [ -n "$$n" ] && [ "$$n" -ge 10 ]; then ok "php-fpm pm.max_children $$n"
	else echo "  [WARN] php-fpm pm.max_children is $${n:-unknown}; run 'make fpm' (Debian ships 5)"; fi
	if grep -q 'reached pm.max_children' /var/log/php$(PHP_VER)-fpm.log 2>/dev/null; then
		echo "  [WARN] php-fpm log shows the worker limit was reached; consider make fpm FPM_MAX_CHILDREN=..."
	fi
	tz=$$(php-fpm$(PHP_VER) -i 2>/dev/null | sed -n 's/^date.timezone => \([^ ]*\).*/\1/p')
	[ "$$tz" = "$(OSM_TZ)" ] && ok "php timezone $$tz" \
		|| echo "  [WARN] php timezone is '$${tz:-unset}', system is $(OSM_TZ); run 'make fpm'"
	[ "$$code" = 000 ] && ok "nothing answers on port 80" \
		|| echo "  [WARN] something answers http://localhost/ ($$code); OSM itself only listens on 443"
	code=$$(curl -sk --noproxy '*' -o /dev/null -w '%{http_code}' https://localhost/config.php || true)
	[ "$$code" = 404 ] && ok "config.php not served" || ko "config.php returned $$code"
	if [ -s "$(DATA_DIR)/error.log" ]; then ko "$(DATA_DIR)/error.log has entries"; fi
	exit $$fail

upgrade: install-files
	@echo "Code upgraded. Data in $(DATA_DIR) and the database were not touched."
	echo "If the schema version changed, sign in as an admin and open /?route=Admin\\Upgrade"

uninstall:
	@echo "Uninstalling Open Screen Monitor (keeping $(DATA_DIR) and database) ..."
	rm -f "$(NGINX_SITE)"
	nginx -t >/dev/null 2>&1 && { service nginx reload 2>/dev/null || true; }
	if [ -e "$(FPM_POOL_FILE)" ] || [ -e "$(FPM_INI_FILE)" ]; then
		rm -f "$(FPM_POOL_FILE)" "$(FPM_INI_FILE)"
		service php$(PHP_VER)-fpm reload 2>/dev/null || true
	fi
	if [ "$$(realpath -m "$(SRC_DIR)")" != "$$(realpath -m "$(OSM_DIR)")" ]; then
		rm -rf "$(OSM_DIR)/php" "$(OSM_DIR)/sample_config"
		rmdir --ignore-fail-on-non-empty "$(OSM_DIR)" 2>/dev/null || true
	fi

purge:
	@if [ "$(CONFIRM)" != yes ]; then
		echo "purge deletes $(DATA_DIR) and database $(DB_NAME). Run: make purge CONFIRM=yes" >&2
		exit 1
	fi
	$(MAKE) --no-print-directory uninstall
	rm -rf "$(DATA_DIR)"
	mysql -e "DROP DATABASE IF EXISTS $(DB_NAME); DROP USER IF EXISTS '$(DB_USER)'@'localhost';"
	echo "Purged."
	if [ -d /etc/letsencrypt/live/$(LE_CERT_NAME) ]; then
		echo "Kept the Let's Encrypt certificate and its renewal; after a reinstall, make letsencrypt"
		echo "reuses it. To delete it too: make letsencrypt-remove CONFIRM=yes"
	fi
