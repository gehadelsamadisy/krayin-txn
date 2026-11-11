#!/usr/bin/env bash
# Shared helpers for the Krayin scripts. Sourced, not run.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

log() { printf '\n==> %s\n' "$*"; }
die() { printf '\nERROR: %s\n' "$*" >&2; exit 1; }

# Value of KEY in .env (last occurrence, surrounding quotes removed).
env_get() {
    local v
    v=$(grep -E "^$1=" .env 2>/dev/null | tail -1 | cut -d= -f2- || true)
    v="${v%\"}"; v="${v#\"}"; v="${v%\'}"; v="${v#\'}"
    printf '%s' "$v"
}

require_env() {
    [ -f .env ] || die ".env not found. Create it: cp .env.example .env && sudo chown \$USER:33 .env && chmod 640 .env && nano .env"
    # The containers read .env as www-data (gid 33); nobody else should.
    local g m
    g=$(stat -c %g .env 2>/dev/null || echo 33)
    m=$(stat -c %a .env 2>/dev/null || echo 640)
    if [ "$g" != "33" ] || [ "${m:1:1}" -lt 4 ] 2>/dev/null; then
        die ".env must be readable by Krayin's www-data group (33). Run: sudo chown \$USER:33 .env && chmod 640 .env"
    fi
}

require_set() {
    local k v
    for k in "$@"; do
        v=$(env_get "$k")
        [ -n "$v" ] && [ "$v" != "CHANGE_ME" ] || die "Set $k in .env first."
    done
}

compose() { docker compose "$@"; }

# Services to (re)start: the scheduler only when KRAYIN_SCHEDULER=true.
app_services() {
    if [ "$(env_get KRAYIN_SCHEDULER)" = "true" ]; then
        echo "krayin krayin-queue krayin-scheduler"
    else
        echo "krayin krayin-queue"
    fi
}
compose_app() {
    if [ "$(env_get KRAYIN_SCHEDULER)" = "true" ]; then
        docker compose --profile scheduler "$@"
    else
        docker compose "$@"
    fi
}

# Wait until a container reports healthy (or is running, if it has no healthcheck).
wait_healthy() {
    local c=$1 timeout=${2:-180} s i
    for ((i = 0; i < timeout; i += 3)); do
        s=$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$c" 2>/dev/null || echo missing)
        # With a healthcheck: starting/healthy/unhealthy. Without: running/exited.
        case "$s" in
            healthy|running) return 0 ;;
            unhealthy|exited|dead) return 1 ;;
        esac
        sleep 3
    done
    return 1
}

# mysql client as root inside the MySQL container (password via env, never on the command line).
mysql_root() {
    docker exec -i -e MYSQL_PWD="$(env_get MYSQL_ROOT_PASSWORD)" krayin-mysql mysql -uroot "$@"
}

db_table_count() {
    mysql_root -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$(env_get DB_DATABASE)'"
}

start_mysql() {
    mkdir -p data/mysql data/storage data/backups
    log "Starting MySQL"
    compose up -d krayin-mysql
    wait_healthy krayin-mysql 240 || die "MySQL did not become healthy. See: docker logs krayin-mysql"
}
