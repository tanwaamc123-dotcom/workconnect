#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

mkdir -p storage/private/uploads storage/private/mail storage/private/logs storage/private/backups tmp

if [[ "$(uname -s)" == "Darwin" ]] && id daemon >/dev/null 2>&1; then
    for directory in storage tmp; do
        chmod +a "daemon allow read,write,execute,delete,append,readattr,writeattr,readextattr,writeextattr,readsecurity,file_inherit,directory_inherit" "$directory" 2>/dev/null || true
    done
fi

owner="$(id -un)"
find storage tmp -user "$owner" -type d -exec chmod 750 {} +
find storage tmp -user "$owner" -type f -exec chmod 640 {} +

printf '[PASS] Restricted storage/tmp permissions while retaining the web-server ACL where supported.\n'
