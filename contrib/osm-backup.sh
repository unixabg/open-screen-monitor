#!/bin/bash

DB_HOST=$(grep -o '"hostname":"[^"]*"' /var/www/osm-data/db.json | cut -d'"' -f4)
DB_USER=$(grep -o '"user":"[^"]*"' /var/www/osm-data/db.json | cut -d'"' -f4)
DB_PASS=$(grep -o '"password":"[^"]*"' /var/www/osm-data/db.json | cut -d'"' -f4)

mkdir -p /var/www/osm-data/backups

mysqldump -h $DB_HOST -u $DB_USER -p$DB_PASS osm \
  tbl_config tbl_filter_entry tbl_filter_entry_group tbl_group_config \
  tbl_lab_device tbl_lab_permission tbl_oneroster \
  > /var/www/osm-data/backups/osm_backup_$(date +%Y%m%d).sql

echo "Backup complete: osm_backup_$(date +%Y%m%d).sql"
