#!/usr/bin/env bash
# Update MySQL to a newer PATCH version of the same series, on purpose and
# separately from Krayin.
#
#   ./scripts/update-mysql.sh 8.4.13
#
# Allowed: 8.4.11 -> 8.4.13 (MySQL upgrades its files in place).
# Refused: another series (8.4 -> 9.x) or a downgrade. Those need a dump,
# a new MySQL in a separate data folder, a restore and a test.
source "$(dirname "$0")/lib.sh"

require_env
NEW=${1:-}
[[ "$NEW" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "Usage: $0 <exact version, e.g. 8.4.13>"
CUR=$(env_get MYSQL_VERSION | grep . || echo 8.4.11)

[ "${NEW%.*}" = "${CUR%.*}" ] || die "Only patch updates within ${CUR%.*}.x are done in place ($CUR -> $NEW refused)."
[ "$(printf '%s\n%s\n' "$CUR" "$NEW" | sort -V | tail -1)" = "$NEW" ] && [ "$NEW" != "$CUR" ] \
    || die "$NEW is not newer than $CUR. Downgrades need a restore from a dump."

log "Downloading mysql:$NEW"
docker pull "mysql:$NEW"

log "Backing up before the MySQL update"
SNAP=$("$ROOT/scripts/backup.sh" pre-mysql-$NEW | tail -1)

if grep -q '^MYSQL_VERSION=' .env; then
    sed -i "s|^MYSQL_VERSION=.*|MYSQL_VERSION=$NEW|" .env
else
    printf '\nMYSQL_VERSION=%s\n' "$NEW" >> .env
fi

log "Restarting MySQL on $NEW (Krayin keeps running and reconnects)"
compose up -d krayin-mysql
if ! wait_healthy krayin-mysql 300; then
    sed -i "s|^MYSQL_VERSION=.*|MYSQL_VERSION=$CUR|" .env
    die "MySQL $NEW did not become healthy (data/logs/mysql/error.log). .env is set back to $CUR; if MySQL won't start on $CUR either, restore $SNAP into a fresh data folder."
fi
mysql_root -N -e "SELECT CONCAT('MySQL now: ', VERSION())"
log "Done. Backup from just before: $SNAP"
