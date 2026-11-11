#!/usr/bin/env bash
# Load a database export (e.g. from the old Krayin) into this machine's MySQL.
#
#   ./scripts/import-initial-data.sh krayin-handover.sql.gz
#
# Asks before replacing a database that already has tables.
source "$(dirname "$0")/lib.sh"

require_env
require_set DB_PASSWORD MYSQL_ROOT_PASSWORD

F=${1:-}
[ -n "$F" ] && [ -f "$F" ] || die "Usage: $0 <export.sql or export.sql.gz>"

start_mysql
DB=$(env_get DB_DATABASE)
n=$(db_table_count)
if [ "$n" -gt 0 ]; then
    echo "The database '$DB' already has $n tables. Importing replaces its contents."
    read -r -p "Type the database name ($DB) to continue: " answer
    [ "$answer" = "$DB" ] || die "Cancelled. Nothing was changed."
    mysql_root -e "DROP DATABASE \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON \`$DB\`.* TO '$(env_get DB_USERNAME)'@'%';"
fi

log "Importing $F into '$DB'"
case "$F" in
    *.gz) gunzip -c "$F" ;;
    *)    cat "$F" ;;
esac | mysql_root "$DB"

log "Done: $(db_table_count) tables in '$DB'"
echo "Next: ./scripts/deploy.sh"
echo "The export contains customer data; delete it when Krayin is running: rm '$F'"
