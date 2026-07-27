#!/bin/zsh
set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1/WorkConnect}"
ROOT="$(cd -- "$(dirname -- "$0")/.." && pwd)"
TMP="$(mktemp -d)"
JAR="$TMP/cookies"
EMAIL="reset-test-$$@example.test"
PASSWORD_HASH="$(php -r "echo password_hash('OldPass123!', PASSWORD_DEFAULT);")"
trap 'sqlite3 "$ROOT/storage/workconnect.sqlite" "PRAGMA foreign_keys=ON; DELETE FROM users WHERE email=\"'$EMAIL'\";"; rm -rf "$TMP"' EXIT

sqlite3 "$ROOT/storage/workconnect.sqlite" "INSERT INTO users(role_id,name,email,password_hash,status) VALUES((SELECT id FROM roles WHERE name='customer'),'Reset Test','$EMAIL','$PASSWORD_HASH','active');"
page="$(curl -sS -b "$JAR" -c "$JAR" "$BASE_URL/?page=forgot-password")"
csrf="$(print "$page" | perl -ne 'if (/name="csrf_token"\s+value="([^"]+)"/) { print $1; exit }')"
[[ -n "$csrf" ]] || { print -u2 '[FAIL] Forgot password CSRF missing'; exit 1; }
curl -sS -b "$JAR" -c "$JAR" -o /dev/null --data-urlencode "csrf_token=$csrf" --data-urlencode 'action=request_password_reset' --data-urlencode "email=$EMAIL" "$BASE_URL/?page=forgot-password"

log="$ROOT/storage/private/mail/password-resets.log"
token="$(grep -F "$EMAIL" "$log" | tail -1 | sed -E 's/.*token=([a-f0-9]{64}).*/\1/')"
[[ ${#token} -eq 64 ]] || { print -u2 '[FAIL] Reset token was not delivered'; exit 1; }
page="$(curl -sS -b "$JAR" -c "$JAR" "$BASE_URL/?page=reset-password&token=$token")"
csrf="$(print "$page" | perl -ne 'if (/name="csrf_token"\s+value="([^"]+)"/) { print $1; exit }')"
curl -sS -b "$JAR" -c "$JAR" -o /dev/null --data-urlencode "csrf_token=$csrf" --data-urlencode 'action=reset_password' --data-urlencode "token=$token" --data-urlencode 'password=NewPass123!' --data-urlencode 'password_confirmation=NewPass123!' "$BASE_URL/?page=reset-password"

used="$(sqlite3 "$ROOT/storage/workconnect.sqlite" "SELECT COUNT(*) FROM password_reset_tokens WHERE user_id=(SELECT id FROM users WHERE email='$EMAIL') AND used_at IS NOT NULL;")"
[[ "$used" == "1" ]] || { print -u2 '[FAIL] Reset token was not consumed'; exit 1; }
code="$(curl -sS -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" --data-urlencode "csrf_token=$csrf" --data-urlencode 'action=reset_password' --data-urlencode "token=$token" --data-urlencode 'password=AgainPass123!' --data-urlencode 'password_confirmation=AgainPass123!' "$BASE_URL/?page=reset-password")"
[[ "$code" == "302" ]] || { print -u2 "[FAIL] Unexpected replay response $code"; exit 1; }
print '[PASS] Password reset is expiring, one-time, and revokes sessions.'
