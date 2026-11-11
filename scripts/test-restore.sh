#!/usr/bin/env bash
# Prove the latest backup can be restored, without touching the live database.
#
#   ./scripts/test-restore.sh [backup.sql.gz]     (default: newest in data/backups)
#
# Restores into a scratch database, compares table and row counts with the
# live one, then drops the scratch database. Runs monthly from cron.
source "$(dirname "$0")/lib.sh"

require_env
F=${1:-$(ls -t data/backups/*.sql.gz 2>/dev/null | head -1 || true)}
[ -n "$F" ] && [ -f "$F" ] || die "No backup found in data/backups (run ./scripts/backup.sh first)."

DB=$(env_get DB_DATABASE)
T="${DB}_restore_test"
log "Restoring $F into scratch database '$T'"
mysql_root -e "DROP DATABASE IF EXISTS \`$T\`; CREATE DATABASE \`$T\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
trap 'mysql_root -e "DROP DATABASE IF EXISTS \`$T\`;" >/dev/null 2>&1 || true' EXIT
gunzip -c "$F" | mysql_root "$T"

count() {
    mysql_root -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$1'"
}
rows() {
    # Exact row count of the main tables (the backup can be older than live data).
    mysql_root -N -e "SELECT (SELECT COUNT(*) FROM \`$1\`.persons), (SELECT COUNT(*) FROM \`$1\`.leads), (SELECT COUNT(*) FROM \`$1\`.activities)"
}

LIVE_T=$(count "$DB"); REST_T=$(count "$T")
echo "tables   live: $LIVE_T   restored: $REST_T"
echo "persons / leads / activities   live: $(rows "$DB" | tr '\t' '/')   restored: $(rows "$T" | tr '\t' '/')"
[ "$REST_T" -gt 0 ] && [ "$REST_T" -eq "$LIVE_T" ] || die "Restore test FAILED: table count differs."
log "Restore test passed ($(date '+%F %T'))"
