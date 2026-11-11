#!/usr/bin/env bash
# Go back to the Krayin version that ran before the last deploy.
#
#   ./scripts/rollback.sh
#
# Only the program goes back. If the newer version changed the database
# (migrations), also restore the backup taken just before it (shown below).
source "$(dirname "$0")/lib.sh"

require_env
CUR=$(cat .deploy/current-tag 2>/dev/null || true)
PREV=$(cat .deploy/previous-tag 2>/dev/null || true)
[ -n "$PREV" ] || die "No previous version recorded (.deploy/previous-tag)."
docker image inspect "txn-krayin:$PREV" >/dev/null 2>&1 || die "Image txn-krayin:$PREV no longer exists on this machine."

log "Switching from $CUR back to $PREV"
docker tag "txn-krayin:$PREV" txn-krayin:current
# shellcheck disable=SC2046
compose_app up -d $(app_services)
wait_healthy krayin 300 || die "Krayin $PREV didn't come up healthy. See: docker logs krayin"

echo "$CUR" > .deploy/previous-tag
echo "$PREV" > .deploy/current-tag
log "Krayin $PREV is running"

LAST=$(ls -t data/backups/*pre-deploy*.sql.gz data/backups/*pre-update*.sql.gz 2>/dev/null | head -1 || true)
[ -n "$LAST" ] && echo "If the database needs to go back too: ./scripts/restore.sh $LAST"
echo "Note: the code in this folder is still the newer version; the next deploy.sh builds it again."
