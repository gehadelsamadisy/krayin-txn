#!/usr/bin/env bash
# Add (or refresh) the nightly backup in this user's crontab: every day at 02:15.
source "$(dirname "$0")/lib.sh"

LINE="15 2 * * * cd $ROOT && ./scripts/backup.sh nightly >> data/backups/backup.log 2>&1"
( crontab -l 2>/dev/null | grep -vF "cd $ROOT && ./scripts/backup.sh" || true; echo "$LINE" ) | crontab -
log "Nightly backup scheduled:"
crontab -l | grep -F "$ROOT"
