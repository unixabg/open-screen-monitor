# Makefile

all: build

build:
	@echo "Building the Open Screen Monitor chrome extension."

	zip osm.zip -j chrome-extension/*

install:
	@echo "Installing Open Screen Monitor ..."

	# Installing php related files
	mkdir -p $(DESTDIR)/var/www/html/osm
	cp -a php/* $(DESTDIR)/var/www/html/osm
	chown -R www-data:www-data $(DESTDIR)/var/www/html/osm

	# Installing osm-data
	mkdir -p $(DESTDIR)/var/www/osm-data
	chown -R www-data:www-data $(DESTDIR)/var/www/osm-data

	@echo " done."

upgrade:
	@echo "Upgrading Open Screen Monitor ..."

	# Upgrading php related files
	rm -rf $(DESTDIR)/var/www/html/osm/*
	cp -a php/* $(DESTDIR)/var/www/html/osm
	chown -R www-data:www-data $(DESTDIR)/var/www/html/osm

	@echo " done."

uninstall:
	@echo "Uninstalling Open Screen Monitor ..."

	# Uninstalling html executables
	rm -rf $(DESTDIR)/var/www/html/osm

	# Uninstalling osm-data
	rm -rf $(DESTDIR)/var/www/osm-data

	@echo " done."

clean:

distclean:

reinstall: uninstall install
