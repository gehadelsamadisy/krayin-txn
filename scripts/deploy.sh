#!/usr/bin/env bash
# Build Krayin (official image + overlay/) and start or refresh the containers.
#
#   ./scripts/deploy.sh            git pull, build, back up, switch, migrate
#   ./scripts/deploy.sh --no-pull  same, without git pull
#
# If the new version doesn't come up healthy, the previous one is put back.
source "$(dirname "$0")/lib.sh"

require_env
require_set DB_PASSWORD MYSQL_ROOT_PASSWORD

if [ -z "$(env_get APP_KEY)" ] || [ "$(env_get APP_KEY)" = "CHANGE_ME" ]; then
    key="base64:$(openssl rand -base64 32)"
    sed -i "s|^APP_KEY=.*|APP_KEY=${key}|" .env
    log "Generated a new APP_KEY in .env"
fi

if [ "${1:-}" != "--no-pull" ]; then
    log "Getting the latest code"
    git pull --ff-only
fi

BASE=$(tr -d '[:space:]' < KRAYIN_VERSION)
VER=${BASE#*:}; VER=${VER%%@*}
TAG="$VER-$(git rev-parse --short HEAD)"

log "Building txn-krayin:$TAG (Krayin $VER + TXN edits)"
docker build --build-arg KRAYIN_BASE="$BASE" -t "txn-krayin:$TAG" -f docker/Dockerfile .

start_mysql
[ "$(db_table_count)" -gt 0 ] 2>/dev/null \
    || die "The database is empty. Load your data first: ./scripts/import-initial-data.sh <export.sql.gz>"

mkdir -p .deploy
PREV=$(cat .deploy/current-tag 2>/dev/null || true)
if [ -n "$PREV" ]; then
    log "Backing up before switching"
    "$ROOT/scripts/backup.sh" pre-deploy
fi

docker tag "txn-krayin:$TAG" txn-krayin:current
log "Starting Krayin"
# shellcheck disable=SC2046
compose_app up -d $(app_services)

if wait_healthy krayin 300 && docker exec -u www-data krayin php artisan migrate --force; then
    echo "$PREV" > .deploy/previous-tag
    echo "$TAG" > .deploy/current-tag
    compose_app ps
    log "Krayin $TAG is running: http://127.0.0.1:$(env_get KRAYIN_HTTP_PORT | grep . || echo 8080)/admin"
else
    if [ -n "$PREV" ]; then
        log "The new version failed; putting $PREV back"
        docker tag "txn-krayin:$PREV" txn-krayin:current
        # shellcheck disable=SC2046
        compose_app up -d $(app_services)
        echo "If the database was already changed, restore the pre-deploy backup in data/backups/ with ./scripts/restore.sh"
    fi
    die "Deploy failed. See: docker logs krayin"
fi
