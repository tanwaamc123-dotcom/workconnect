#!/bin/zsh
set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1/WorkConnect}"
TMP_DIR="$(mktemp -d)"
COOKIE_JAR="$TMP_DIR/cookies.txt"
trap 'rm -rf "$TMP_DIR"' EXIT

fail() { print -u2 "[FAIL] $1"; exit 1; }
pass() { print "[PASS] $1"; }

headers="$(curl -sSI "$BASE_URL/")"
for header in content-security-policy x-content-type-options x-frame-options referrer-policy permissions-policy; do
  print "$headers" | grep -qi "^$header:" || fail "Missing $header header"
done
pass "Security headers are present."

for target in .env .gitignore storage/workconnect.sqlite database/postgresql-schema.sql Dockerfile render.yaml dev-router.php; do
  code="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/$target")"
  [[ "$code" == "403" || "$code" == "404" ]] || fail "$target is publicly accessible (HTTP $code)"
done
pass "Sensitive project files are blocked."

register="$(curl -sS -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/?page=register")"
print "$register" | grep -q 'value="admin"' && fail "Public admin option is still visible"
csrf="$(print "$register" | perl -ne 'if (/name="csrf_token"\s+value="([^"]+)"/) { print $1; exit }')"
[[ -n "$csrf" ]] || fail "Registration CSRF token missing"

email="security-audit-$$@example.test"
curl -sS -b "$COOKIE_JAR" -c "$COOKIE_JAR" -o /dev/null \
  --data-urlencode "csrf_token=$csrf" --data-urlencode 'action=register' \
  --data-urlencode 'name=Security Audit' --data-urlencode "email=$email" \
  --data-urlencode 'password=AuditPass123!' --data-urlencode 'password_confirmation=AuditPass123!' \
  --data-urlencode 'role=admin' "$BASE_URL/?page=register"

role="$(sqlite3 storage/workconnect.sqlite "SELECT roles.name FROM users JOIN roles ON roles.id=users.role_id WHERE users.email='$email';")"
[[ "$role" == "customer" ]] || fail "Forged admin registration created role: $role"
sqlite3 storage/workconnect.sqlite "PRAGMA foreign_keys=ON; DELETE FROM users WHERE email='$email';"
pass "Forged admin registration is downgraded safely."

code="$(curl -sS -o /dev/null -w '%{http_code}' -X POST --data 'action=subscribe&email=a@example.test' "$BASE_URL/")"
[[ "$code" == "403" ]] || fail "CSRF rejection returned HTTP $code"
pass "CSRF protection rejects unsigned POST requests."

print "[OK] Security tests passed."
