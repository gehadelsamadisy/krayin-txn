#!/bin/bash
# Startup for the Krayin containers (replaces the stock entrypoint, which
# rewrites .env with sed and runs optimize:clear, wiping the Geocodio cache).
#
# - requires the .env mounted from the host (read-only)
# - TXN_INIT=1 (web container only): prepares the storage folder and seeds the
#   logo, favicon and Krayin's "installed" marker without overwriting anything
# - waits for MySQL, then runs the container's command
# - every artisan call runs as www-data, so no root-owned cache files appear
set -euo pipefail

APP=/var/www/laravel-crm
cd "$APP"
log() { echo "[txn-entrypoint] $(date -u '+%F %T') $*"; }

if [ ! -f .env ]; then
    log "ERROR: no .env at $APP/.env. Mount it from the host (see docker-compose.yml)."
    exit 1
fi
# Krayin runs as www-data (uid/gid 33). If it can't read .env, Laravel silently
# falls back to defaults (127.0.0.1, database "forge").
if ! runuser -u www-data -- test -r .env; then
    log "ERROR: www-data (uid 33) can't read .env. On the host run:"
    log "       sudo chown \$USER:33 .env && chmod 640 .env"
    exit 1
fi

export MYSQL_AUTOSTART=false

if [ "${TXN_INIT:-0}" = "1" ]; then
    log "Preparing storage"
    mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
             storage/framework/views storage/framework/testing storage/logs bootstrap/cache
    cp -a --update=none /opt/txn/storage-seed/. storage/
    chown -R www-data:www-data storage bootstrap/cache
fi

log "Waiting for the database"
for i in $(seq 1 90); do
    if err=$(runuser -u www-data -- php -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        Illuminate\Support\Facades\DB::connection()->getPdo();' 2>&1); then
        log "Database reachable"
        break
    fi
    if [ "$i" -eq 90 ]; then
        log "ERROR: can't connect to the database after 90 s. Last error:"
        printf '%s\n' "$err" | grep -v '^\s*$' | tail -5
        log "Check .env (DB_* values; values with spaces need quotes) and the krayin-mysql container."
        exit 1
    fi
    sleep 1
done

if [ "${TXN_INIT:-0}" = "1" ]; then
    # Not cache:clear / optimize:clear: the file cache holds paid Geocodio lookups.
    runuser -u www-data -- php artisan config:clear >/dev/null
    runuser -u www-data -- php artisan route:clear >/dev/null
    runuser -u www-data -- php artisan view:clear >/dev/null
fi

log "Starting: $*"
exec "$@"
