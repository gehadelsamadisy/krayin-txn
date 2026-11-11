#!/usr/bin/env bash
# Replace the Krayin database with a backup. Krayin is stopped meanwhile.
#
#   ./scripts/restore.sh data/backups/krayin-20261112-021500-nightly.sql.gz
source "$(dirname "$0")/lib.sh"

require_env
F=${1:-}
[ -n "$F" ] && [ -f "$F" ] || die "Usage: $0 <backup .sql.gz>   (backups are in data/backups/)"

DB=$(env_get DB_DATABASE)
echo "This REPLACES the whole '$DB' database with: $F"
echo "Krayin is stopped during the restore and started again afterwards."
read -r -p "Type the database name ($DB) to continue: " answer
[ "$answer" = "$DB" ] || die "Cancelled. Nothing was changed."

log "Stopping Krayin"
docker stop krayin-scheduler krayin-queue krayin >/dev/null 2>&1 || true

start_mysql
log "Restoring $F"
mysql_root -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON \`$DB\`.* TO '$(env_get DB_USERNAME)'@'%';"
gunzip -c "$F" | mysql_root "$DB"
log "Restored: $(db_table_count) tables"

log "Starting Krayin"
# shellcheck disable=SC2046
compose_app up -d $(app_services)
wait_healthy krayin 300 || die "Krayin didn't come back healthy. See: docker logs krayin"
log "Done"
