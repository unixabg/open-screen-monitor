# Makefile for Open Screen Monitor
#
# Fresh install on Debian trixie (as root):
#   git clone https://github.com/unixabg/open-screen-monitor.git --branch next
#   cd open-screen-monitor
#   make install
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
# auto: drop the [::]:80 listen when the kernel has no IPv6 (no /proc/net/if_inet6)
NGINX_IPV6 ?= auto

PACKAGES = nginx mariadb-server curl \
	php$(PHP_VER)-fpm php$(PHP_VER)-xml php$(PHP_VER)-curl \
	php$(PHP_VER)-odbc php$(PHP_VER)-mysql php$(PHP_VER)-zip

SRC_DIR := $(CURDIR)

.PHONY: all help deps install install-files nginx data db check upgrade uninstall purge

all: help

help:
	@echo "Open Screen Monitor make targets (run as root):"
	echo "  install    deps + install-files + nginx + data + db"
	echo "  deps       apt install nginx, mariadb and php$(PHP_VER) packages"
	echo "  install-files  copy php/ and sample_config/ to $(OSM_DIR)"
	echo "  nginx      install the nginx site (skipped if $(NGINX_SITE) exists;"
	echo "             IPv4-only if the kernel has no IPv6, or with NGINX_IPV6=no)"
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

install: deps install-files nginx data db
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

nginx:
	@echo "Configuring nginx ..."
	if [ -e "$(NGINX_SITE)" ]; then
		echo "  $(NGINX_SITE) exists; leaving it alone."
	else
		sed -e 's#/var/www/osm/php/public#$(OSM_DIR)/php/public#' \
			-e 's#/var/www/osm-data/#$(DATA_DIR)/#' \
			-e 's#php8\.4-fpm#php$(PHP_VER)-fpm#' \
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

data:
	@echo "Setting up $(DATA_DIR) ..."
	mkdir -p "$(DATA_DIR)"
	chown $(WEB_USER):$(WEB_USER) "$(DATA_DIR)"
	chmod 750 "$(DATA_DIR)"
	for f in client_secret.json db.json permissions.tsv; do
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
	body=$$(curl -s --noproxy '*' -w '\n%{http_code}' http://localhost/ || true)
	code=$${body##*$$'\n'}
	case "$$code" in 200|302) ok "GET / -> $$code";; *) ko "GET / -> $$code";; esac
	if grep -qiE 'Missing|Fatal error|Parse error|Uncaught' <<<"$$body"; then
		ko "page error: $$(grep -oiE '(Missing|Fatal error|Parse error|Uncaught).{0,100}' <<<"$$body" | head -1)"
	fi
	code=$$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' http://localhost/config.php || true)
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
