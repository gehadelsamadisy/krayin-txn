#!/usr/bin/env bash
# Update to a new official Krayin version, keeping the TXN edits.
#
#   ./scripts/update-krayin.sh 2.3.0
#
# 1. downloads webkul/krayin:<version>
# 2. records Krayin's new copies of the files TXN changes (branch "upstream")
# 3. Git merges them into the TXN edits; stops if both changed the same lines
# 4. tests the result on a COPY of the database (email/SMS/APIs off)
# 5. only if the test passes: backs up and switches the live Krayin (deploy.sh)
# Nothing live changes until step 5.
source "$(dirname "$0")/lib.sh"

require_env
NEW=${1:-}
[ -n "$NEW" ] || die "Usage: $0 <Krayin version, e.g. 2.3.0>   (versions: https://hub.docker.com/r/webkul/krayin/tags)"
[ -z "$(git status --porcelain)" ] || die "There are uncommitted changes in $ROOT. Commit or undo them first (git status)."

# The update records Krayin's files as Git commits; a fresh server has no Git name yet.
git config user.name >/dev/null || git config user.name "Krayin server ($(hostname))"
git config user.email >/dev/null || git config user.email "krayin@$(hostname).local"

# Start from the latest main and upstream on GitHub (if reachable).
if git fetch -q origin 2>/dev/null; then
    git switch -q main && git merge -q --ff-only origin/main 2>/dev/null || true
    if git show-ref -q --verify refs/heads/upstream; then
        git switch -q upstream && git merge -q --ff-only origin/upstream 2>/dev/null || true
    fi
fi
git switch -q main

log "Downloading webkul/krayin:$NEW"
docker pull "webkul/krayin:$NEW"
DIGEST=$(docker image inspect -f '{{range .RepoDigests}}{{println .}}{{end}}' "webkul/krayin:$NEW" | grep '^webkul/krayin@' | head -1 | cut -d@ -f2)
[ -n "$DIGEST" ] || die "Couldn't read the image digest of webkul/krayin:$NEW"
BASE="webkul/krayin:$NEW@$DIGEST"

# --- 2. Krayin's new copies of the overridden files -> branch upstream -------
log "Recording Krayin $NEW's versions of the files TXN changes"
git switch -q upstream
docker run --rm --user "$(id -u):$(id -g)" -v "$ROOT:/repo" --entrypoint bash "webkul/krayin:$NEW" -c '
    cd /var/www/laravel-crm
    tr -d "\r" < /repo/overrides.txt | while read -r f; do
        [ -z "$f" ] && continue
        if [ -f "$f" ]; then
            mkdir -p "/repo/overlay/$(dirname "$f")"
            cp "$f" "/repo/overlay/$f"
        else
            echo "  note: $f no longer exists in Krayin '"$NEW"'"
        fi
    done'
git add -A overlay
CHANGED=$(git diff --cached --name-only | sed 's#^overlay/##')
if [ -n "$CHANGED" ]; then
    git commit -q -m "Krayin $NEW: stock copies of the files TXN changes"
    echo "Krayin $NEW changed these files that TXN also changes:"
    printf '  %s\n' $CHANGED
else
    echo "Krayin $NEW didn't change any of the files TXN changes."
fi
git switch -q main

# --- name clashes: TXN "new" files that Krayin now also ships -----------------
NEWFILES=$(git diff --name-only --diff-filter=A upstream main -- overlay | sed 's#^overlay/##')
CLASH=$(printf '%s\n' $NEWFILES | docker run --rm -i --entrypoint bash "webkul/krayin:$NEW" -c \
    'cd /var/www/laravel-crm; while read -r f; do [ -n "$f" ] && [ -f "$f" ] && echo "$f"; done' || true)
if [ -n "$CLASH" ]; then
    echo "Krayin $NEW now has its own file at these TXN paths (TXN's copy would hide Krayin's):"
    printf '  %s\n' $CLASH
    die "Add them to overrides.txt (commit), then run this again so they're merged too. Nothing was deployed."
fi

# --- 3. merge -----------------------------------------------------------------
BR="update/$NEW"
git branch -D "$BR" >/dev/null 2>&1 || true
git switch -q -c "$BR" main
log "Merging Krayin $NEW into the TXN edits"
if ! git merge -q --no-edit -m "Merge Krayin $NEW into the TXN edits" upstream; then
    echo "Both Krayin $NEW and TXN changed the same lines in:"
    git diff --name-only --diff-filter=U | sed 's/^/  /'
    git merge --abort
    git switch -q main
    git branch -D "$BR" >/dev/null
    die "Nothing was deployed. Merge those files by hand (git switch -c $BR && git merge upstream, fix, commit, merge into main), then run this again."
fi
echo "$BASE" > KRAYIN_VERSION
git add KRAYIN_VERSION
# Same version again (a rehearsal) changes nothing: no commit then.
git diff --cached --quiet || git commit -q -m "Use Krayin $NEW"

# --- 4. test on a copy of the database ----------------------------------------
log "Testing Krayin $NEW on a copy of the database"
TAG="$NEW-test-$(git rev-parse --short HEAD)"
docker build --build-arg KRAYIN_BASE="$BASE" -t "txn-krayin:$TAG" -f docker/Dockerfile .

start_mysql
DB=$(env_get DB_DATABASE)
TDB="${DB}_update_test"
SNAP=$("$ROOT/scripts/backup.sh" pre-update | tail -1)
mysql_root -e "DROP DATABASE IF EXISTS \`$TDB\`; CREATE DATABASE \`$TDB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gunzip -c "$SNAP" | mysql_root "$TDB"

NET=$(docker inspect -f '{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}' krayin-mysql | awk '{print $1}')
cleanup_test() {
    docker rm -f krayin-update-test >/dev/null 2>&1 || true
    mysql_root -e "DROP DATABASE IF EXISTS \`$TDB\`;" || true
}
cleanup_test_keep_db() { docker rm -f krayin-update-test >/dev/null 2>&1 || true; }
cleanup_test_keep_db
# Real environment variables beat .env in Laravel: point at the copy and
# switch off everything that could reach customers or paid APIs.
docker run -d --name krayin-update-test --network "$NET" \
    -v "$ROOT/.env:/var/www/laravel-crm/.env:ro" \
    -e TXN_INIT=1 -e DB_HOST=krayin-mysql -e DB_DATABASE="$TDB" \
    -e DB_USERNAME=root -e DB_PASSWORD="$(env_get MYSQL_ROOT_PASSWORD)" \
    -e MAIL_MAILER=log -e QUEUE_CONNECTION=sync \
    -e CALCOM_API_KEY= -e GEOCODIO_API_KEY= \
    -e TWILIO_ACCOUNT_SID= -e TWILIO_AUTH_TOKEN= -e TWILIO_MESSAGING_SERVICE_SID= -e TWILIO_FROM_NUMBER= \
    "txn-krayin:$TAG" >/dev/null

ok=1
wait_healthy krayin-update-test 300 || { echo "  container did not become healthy"; ok=0; }
if [ $ok = 1 ]; then
    docker exec -u www-data krayin-update-test php artisan migrate --force || { echo "  migrations failed"; ok=0; }
fi
if [ $ok = 1 ]; then
    code=$(docker exec krayin-update-test curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/admin/login)
    [ "$code" = 200 ] || { echo "  login page returned $code"; ok=0; }
    docker exec -u www-data krayin-update-test php artisan route:list >/dev/null || { echo "  route list failed"; ok=0; }
fi
if [ $ok = 1 ] && [ -x tests/feature-checks.sh ]; then
    tests/feature-checks.sh krayin-update-test || { echo "  TXN feature checks failed"; ok=0; }
fi

if [ $ok != 1 ]; then
    docker logs --tail 40 krayin-update-test || true
    cleanup_test
    git switch -q main
    die "Krayin $NEW failed the test on the copy. The live Krayin is untouched. The merged code is kept on branch $BR for inspection."
fi
cleanup_test
log "Test passed"

# --- 5. go live -----------------------------------------------------------------
git switch -q main
git merge -q --ff-only "$BR"
git branch -q -d "$BR"
"$ROOT/scripts/deploy.sh" --no-pull

# --- 6. verify -----------------------------------------------------------------
log "Verifying"
compose_app ps
code=$(docker exec krayin curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/admin/login || true)
echo "login page: $code"
echo "--- last lines of the Krayin log (data/logs/krayin):"
tail -n 20 "$(ls -t data/logs/krayin/*.log 2>/dev/null | head -1)" 2>/dev/null || echo "(no log lines yet)"

log "Krayin $NEW is live. Backup from just before the update: $SNAP"
echo "If something is wrong: ./scripts/rollback.sh  (and ./scripts/restore.sh $SNAP if the database changed)"
echo "Send the update to GitHub so every copy has it:  git push origin main upstream"
