#!/bin/zsh
set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1/WorkConnect}"
TMP="$(mktemp -d)"
COOKIE_JAR="$TMP/cookies.txt"
STREAM_PID=""

cleanup() {
  if [[ -n "$STREAM_PID" ]]; then
    kill "$STREAM_PID" 2>/dev/null || true
  fi
  rm -rf "$TMP"
}
trap cleanup EXIT

login="$(curl -sS -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/?page=login")"
csrf="$(print "$login" | perl -ne 'if (/name="csrf_token"\s+value="([^"]+)"/) { print $1; exit }')"
curl -sS -b "$COOKIE_JAR" -c "$COOKIE_JAR" -o /dev/null \
  --data-urlencode "csrf_token=$csrf" \
  --data-urlencode action=login \
  --data-urlencode "email=customer@workconnect.test" \
  --data-urlencode 'password=Demo1234!' \
  "$BASE_URL/?page=login"

curl -sS --max-time 8 -b "$COOKIE_JAR" "$BASE_URL/?page=stream" >"$TMP/stream.txt" 2>/dev/null &
STREAM_PID="$!"
sleep 1

started="$(php -r 'echo microtime(true);')"
code="$(curl -sS -b "$COOKIE_JAR" -o "$TMP/dashboard.html" -w '%{http_code}' "$BASE_URL/?page=dashboard")"
finished="$(php -r 'echo microtime(true);')"
elapsed="$(awk -v start="$started" -v finish="$finished" 'BEGIN { printf "%.3f", finish-start }')"

[[ "$code" == "200" ]] || { print -u2 "[FAIL] Dashboard returned HTTP $code while realtime stream was open."; exit 1; }
grep -q 'workspace-page theme-dark' "$TMP/dashboard.html" || { print -u2 '[FAIL] Dashboard content was incomplete.'; exit 1; }
awk -v elapsed="$elapsed" 'BEGIN { exit !(elapsed < 2.5) }' || {
  print -u2 "[FAIL] Realtime stream held the PHP session lock for ${elapsed}s."
  exit 1
}

print "[PASS] Realtime stream releases the PHP session lock (${elapsed}s)."
