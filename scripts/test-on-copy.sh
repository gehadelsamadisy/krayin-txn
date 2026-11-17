#!/usr/bin/env bash
# Test the code in this checkout on a COPY of the live database. The live
# Krayin and its database are never touched.
#
#   ./scripts/test-on-copy.sh
#
# 1. builds an image from this checkout and copies the live database (fresh
#    backup) into a scratch database;
# 2. starts a test container on the copy with email, SMS, Cal.com and
#    Geocodio switched off;
# 3. shows the leads per stage, runs the pending migrations, shows them again;
# 4. runs tests/pipeline-check.php (rolled back, nothing kept);
# 5. undoes the migrations it ran and checks every lead is back in exactly
#    the stage it started in, and the stages are as before;
# 6. removes the test container and the scratch database.
source "$(dirname "$0")/lib.sh"

require_env
require_set DB_PASSWORD MYSQL_ROOT_PASSWORD

BASE=$(tr -d '[:space:]' < KRAYIN_VERSION)
VER=${BASE#*:}; VER=${VER%%@*}
TAG="$VER-copytest-$(git rev-parse --short HEAD)"
C=krayin-copy-test

log "Building txn-krayin:$TAG from this checkout"
docker build --build-arg KRAYIN_BASE="$BASE" -t "txn-krayin:$TAG" -f docker/Dockerfile .

start_mysql
DB=$(env_get DB_DATABASE)
TDB="${DB}_copy_test"

remove_test() {
    docker rm -f "$C" >/dev/null 2>&1 || true
    mysql_root -e "DROP DATABASE IF EXISTS \`$TDB\`;" >/dev/null 2>&1 || true
}
remove_test   # leftovers of an earlier run
trap 'remove_test; docker image rm "txn-krayin:$TAG" >/dev/null 2>&1 || true' EXIT

log "Copying the live database into '$TDB'"
SNAP=$("$ROOT/scripts/backup.sh" copy-test | tail -1)
mysql_root -e "CREATE DATABASE \`$TDB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gunzip -c "$SNAP" | mysql_root "$TDB"

# Leads per stage, readable.
stage_table() {
    mysql_root -t "$TDB" -e "
        SELECT p.name AS pipeline, s.sort_order AS pos, s.code, s.name AS stage, COUNT(l.id) AS leads
        FROM lead_pipeline_stages s
        JOIN lead_pipelines p ON p.id = s.lead_pipeline_id
        LEFT JOIN leads l ON l.lead_pipeline_stage_id = s.id
        GROUP BY s.id ORDER BY p.id, s.sort_order"
}
# Fingerprints: every lead with its pipeline and stage code; every stage as it is.
leads_fp() { mysql_root -N "$TDB" -e "SELECT l.id, l.lead_pipeline_id, s.code FROM leads l LEFT JOIN lead_pipeline_stages s ON s.id = l.lead_pipeline_stage_id ORDER BY l.id" | md5sum | cut -c1-12; }
stages_fp() { mysql_root -N "$TDB" -e "SELECT lead_pipeline_id, code, name, probability, sort_order FROM lead_pipeline_stages ORDER BY lead_pipeline_id, code" | md5sum | cut -c1-12; }
lead_count() { mysql_root -N "$TDB" -e "SELECT COUNT(*) FROM leads"; }

log "BEFORE: leads per stage"
stage_table
LEADS_BEFORE=$(leads_fp); STAGES_BEFORE=$(stages_fp); COUNT_BEFORE=$(lead_count)

NET=$(docker inspect -f '{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}' krayin-mysql | awk '{print $1}')
log "Starting the test container on the copy (email, SMS, Cal.com, Geocodio off)"
docker run -d --name "$C" --network "$NET" \
    -v "$ROOT/.env:/var/www/laravel-crm/.env:ro" \
    -e TXN_INIT=1 -e DB_HOST=krayin-mysql -e DB_DATABASE="$TDB" \
    -e DB_USERNAME=root -e DB_PASSWORD="$(env_get MYSQL_ROOT_PASSWORD)" \
    -e MAIL_MAILER=log -e QUEUE_CONNECTION=sync \
    -e CALCOM_API_KEY= -e GEOCODIO_API_KEY= -e LEAD_DB_HOST=127.0.0.1 \
    -e TWILIO_ACCOUNT_SID= -e TWILIO_AUTH_TOKEN= -e TWILIO_MESSAGING_SERVICE_SID= -e TWILIO_FROM_NUMBER= \
    "txn-krayin:$TAG" >/dev/null
wait_healthy "$C" 300 || { docker logs --tail 40 "$C"; die "The test container did not become healthy."; }

artisan() { docker exec -u www-data "$C" php artisan "$@"; }

log "Pending migrations"
PENDING=$(artisan migrate:status | grep -c 'Pending' || true)
artisan migrate:status | grep 'Pending' || echo "  none"

log "Running the $PENDING pending migration(s) on the copy"
artisan migrate --force

log "AFTER: leads per stage"
stage_table
COUNT_AFTER=$(lead_count)

ok=1
[ "$COUNT_AFTER" = "$COUNT_BEFORE" ] || { echo "  FAIL: $COUNT_BEFORE leads before, $COUNT_AFTER after"; ok=0; }

log "Pipeline check"
docker exec "$C" mkdir -p /var/www/laravel-crm/tests
docker cp tests/pipeline-check.php "$C:/var/www/laravel-crm/tests/pipeline-check.php"
docker exec -u www-data -e HOME=/tmp "$C" php tests/pipeline-check.php || ok=0

if [ "$PENDING" -gt 0 ]; then
    log "Undoing the $PENDING migration(s) this test ran"
    artisan migrate:rollback --step="$PENDING" --force
fi

log "AFTER UNDO: leads per stage"
stage_table
[ "$(leads_fp)" = "$LEADS_BEFORE" ] && echo "  every lead is back in the stage it started in" \
    || { echo "  FAIL: some leads are not back where they started"; ok=0; }
[ "$(stages_fp)" = "$STAGES_BEFORE" ] && echo "  the stages are exactly as before" \
    || { echo "  FAIL: the stages differ from before"; ok=0; }

[ $ok = 1 ] || die "Test on the copy FAILED (see above). Nothing was deployed; the live Krayin is unchanged."
log "Test on the copy passed. The live Krayin is unchanged; deploy with ./scripts/deploy.sh"
