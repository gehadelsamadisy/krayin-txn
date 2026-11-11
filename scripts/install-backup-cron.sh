#!/usr/bin/env bash
# Add (or refresh) the scheduled jobs in this user's crontab:
#   every day at 02:15     backup (database, uploads, settings)
#   every day at 02:45     copy the backups off this server (once BACKUP_REMOTE is set)
#   1st of month at 03:30  restore test of the newest backup (scratch database)
source "$(dirname "$0")/lib.sh"

BACKUP="15 2 * * * cd $ROOT && ./scripts/backup.sh nightly >> data/backups/backup.log 2>&1"
OFFSITE="45 2 * * * cd $ROOT && ./scripts/offsite-backup.sh >> data/backups/offsite.log 2>&1"
RESTORE="30 3 1 * * cd $ROOT && ./scripts/test-restore.sh >> data/backups/restore-test.log 2>&1"
( crontab -l 2>/dev/null | grep -vF "cd $ROOT && ./scripts/" || true; echo "$BACKUP"; echo "$OFFSITE"; echo "$RESTORE" ) | crontab -
log "Scheduled:"
crontab -l | grep -F "$ROOT"
