#!/bin/zsh
set -euo pipefail

ROOT="$(cd -- "$(dirname -- "$0")/.." && pwd)"
SOURCE="${1:-}"
DATABASE="$ROOT/storage/workconnect.sqlite"

[[ -n "$SOURCE" && -f "$SOURCE" ]] || { print -u2 'Usage: scripts/restore-db.sh /path/to/backup.sqlite-or-dump'; exit 1; }
if [[ "$SOURCE" == *.dump ]]; then
  [[ -n "${DATABASE_URL:-}" ]] || { print -u2 'DATABASE_URL is required to restore a PostgreSQL dump.'; exit 1; }
  command -v pg_dump >/dev/null || { print -u2 'pg_dump is required for the safety backup.'; exit 1; }
  command -v pg_restore >/dev/null || { print -u2 'pg_restore is required for PostgreSQL restore.'; exit 1; }
  if [[ -f "$SOURCE.sha256" ]]; then
    (cd "${SOURCE:h}" && shasum -a 256 -c "${SOURCE:t}.sha256")
  fi
  pg_restore --list "$SOURCE" >/dev/null
  SAFETY="${SOURCE:h}/workconnect-before-restore-$(date +%Y%m%d-%H%M%S).dump"
  pg_dump --dbname="$DATABASE_URL" --format=custom --no-owner --no-acl --file="$SAFETY"
  pg_restore --dbname="$DATABASE_URL" --clean --if-exists --no-owner --no-acl --exit-on-error "$SOURCE"
  print "Restored successfully. Safety copy: $SAFETY"
  exit 0
fi

[[ "$(sqlite3 "$SOURCE" 'PRAGMA integrity_check;')" == "ok" ]] || { print -u2 'Backup integrity check failed.'; exit 1; }
if [[ -f "$SOURCE.sha256" ]]; then
  (cd "${SOURCE:h}" && shasum -a 256 -c "${SOURCE:t}.sha256")
fi

SAFETY="$DATABASE.before-restore-$(date +%Y%m%d-%H%M%S)"
sqlite3 "$DATABASE" ".backup '$SAFETY'"
sqlite3 "$SOURCE" ".backup '$DATABASE'"
[[ "$(sqlite3 "$DATABASE" 'PRAGMA integrity_check;')" == "ok" ]] || { cp "$SAFETY" "$DATABASE"; print -u2 'Restore failed; original database was restored.'; exit 1; }
print "Restored successfully. Safety copy: $SAFETY"
