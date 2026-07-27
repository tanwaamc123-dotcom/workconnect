#!/bin/zsh
set -euo pipefail

ROOT="$(cd -- "$(dirname -- "$0")/.." && pwd)"
DATABASE="$ROOT/storage/workconnect.sqlite"
BACKUP_DIR="${BACKUP_DIR:-$ROOT/storage/private/backups}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
STAMP="$(date +%Y%m%d-%H%M%S)"

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

publish_backup() {
  if [[ "${BACKUP_OFFSITE_REQUIRED:-0}" == "1" ]]; then
    php "$ROOT/scripts/upload-backup.php" "$TARGET" "$TARGET.sha256"
  fi
  php "$ROOT/scripts/record-job.php" --job=backup --status=ok --detail="file=$(basename "$TARGET")"
  print "$TARGET"
}

if [[ -n "${DATABASE_URL:-}" ]]; then
  command -v pg_dump >/dev/null || { print -u2 'pg_dump is required for PostgreSQL backups.'; exit 1; }
  command -v pg_restore >/dev/null || { print -u2 'pg_restore is required for PostgreSQL backup verification.'; exit 1; }
  TARGET="$BACKUP_DIR/workconnect-$STAMP.dump"
  pg_dump --dbname="$DATABASE_URL" --format=custom --no-owner --no-acl --file="$TARGET"
  pg_restore --list "$TARGET" >/dev/null
  shasum -a 256 "$TARGET" > "$TARGET.sha256"
  chmod 600 "$TARGET" "$TARGET.sha256"
  find "$BACKUP_DIR" -type f \( -name 'workconnect-*.dump' -o -name 'workconnect-*.dump.sha256' \) -mtime "+$RETENTION_DAYS" -delete
  publish_backup
  exit 0
fi

TARGET="$BACKUP_DIR/workconnect-$STAMP.sqlite"
[[ -f "$DATABASE" ]] || { print -u2 'Database not found.'; exit 1; }

sqlite3 "$DATABASE" ".timeout 10000" ".backup '$TARGET'"
sqlite3 "$TARGET" 'PRAGMA journal_mode=DELETE;' >/dev/null
[[ "$(sqlite3 "$TARGET" 'PRAGMA integrity_check;')" == "ok" ]] || { rm -f "$TARGET"; print -u2 'Backup integrity check failed.'; exit 1; }
shasum -a 256 "$TARGET" > "$TARGET.sha256"
chmod 600 "$TARGET" "$TARGET.sha256"
find "$BACKUP_DIR" -type f -name 'workconnect-*.sqlite*' -mtime "+$RETENTION_DAYS" -delete
publish_backup
