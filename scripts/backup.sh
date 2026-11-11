#!/usr/bin/env bash
# Back up the Krayin database and uploads into data/backups/.
#
#   ./scripts/backup.sh [label]       e.g. ./scripts/backup.sh before-cleanup
#
# Keeps BACKUP_KEEP_DAYS (default 14) days. Nightly: ./scripts/install-backup-cron.sh
# Prints the database backup's path on the last line.
source "$(dirname "$0")/lib.sh"

require_env
DB=$(env_get DB_DATABASE)
TS=$(date +%Y%m%d-%H%M%S)
LABEL=${1:+-$1}
mkdir -p data/backups

OUT="data/backups/${DB}-${TS}${LABEL}.sql.gz"
if ! docker exec -e MYSQL_PWD="$(env_get MYSQL_ROOT_PASSWORD)" krayin-mysql \
        mysqldump -uroot --single-transaction --routines --triggers --events --hex-blob \
        --no-tablespaces --default-character-set=utf8mb4 "$DB" | gzip > "$OUT.part"; then
    rm -f "$OUT.part"
    die "Database backup failed (is krayin-mysql running?)"
fi
mv "$OUT.part" "$OUT"

# Uploads, read straight from the host folder (works whether Krayin runs or not).
STORE="data/backups/storage-${TS}${LABEL}.tar.gz"
if docker run --rm -v "$ROOT/data/storage:/s:ro" --entrypoint tar "$(mysql_image)" czf - -C /s app > "$STORE.part"; then
    mv "$STORE.part" "$STORE"
else
    rm -f "$STORE.part"
    echo "WARNING: uploads (data/storage/app) could not be archived" >&2
fi

# Settings: .env (keys, passwords, APP_KEY), MySQL config, Krayin version, deploy state.
# Private to this user: it holds secrets.
CONF="data/backups/config-${TS}${LABEL}.tar.gz"
( umask 077; tar czf "$CONF" .env mysql/conf.d KRAYIN_VERSION $( [ -d .deploy ] && echo .deploy ) )

find data/backups -maxdepth 1 -type f \( -name '*.sql.gz' -o -name '*.tar.gz' \) \
    -mtime +"$(env_get BACKUP_KEEP_DAYS | grep . || echo 14)" -delete

echo "$OUT"
