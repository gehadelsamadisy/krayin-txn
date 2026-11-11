#!/usr/bin/env bash
# Copy data/backups to a place OFF this server (another server, a NAS,
# Azure Blob, S3, ...), using rclone in a container. Runs nightly after the
# backup (install-backup-cron.sh). Does nothing until it's set up.
#
# Set up once:
#   1. Create the rclone config (asks questions; choose "sftp" for another
#      server/NAS or "azureblob" for Azure Blob Storage):
#        mkdir -p rclone && docker run --rm -it -v "$PWD/rclone:/config/rclone" rclone/rclone:1.68.2 config
#   2. In .env:  BACKUP_REMOTE=<remote name>:<folder>     e.g. BACKUP_REMOTE=azure:krayin-backups
#   3. Test:     ./scripts/offsite-backup.sh
#
# Copies new files and keeps the remote's own copies (it never deletes there),
# so an accident on this server can't wipe the off-site copy.
source "$(dirname "$0")/lib.sh"

require_env
REMOTE=$(env_get BACKUP_REMOTE)
if [ -z "$REMOTE" ]; then
    echo "Off-site backup not set up (BACKUP_REMOTE is empty in .env). See the top of $0."
    exit 0
fi
[ -f rclone/rclone.conf ] || die "rclone/rclone.conf missing. Create it: see the top of $0."
[ -d data/backups ] || die "No data/backups folder yet. Run ./scripts/backup.sh first."

log "Copying data/backups to $REMOTE"
docker run --rm \
    -v "$ROOT/rclone:/config/rclone:ro" \
    -v "$ROOT/data/backups:/backups:ro" \
    rclone/rclone:1.68.2 copy /backups "$REMOTE" \
    --include '*.sql.gz' --include '*.tar.gz' --transfers 2 --stats-one-line -v
log "Off-site copy done ($(date '+%F %T'))"
