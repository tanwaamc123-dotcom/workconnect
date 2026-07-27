#!/bin/zsh
set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1/WorkConnect}"
TMP="$(mktemp -d)"
ROOT="$(cd -- "$(dirname -- "$0")/.." && pwd)"
DB="$ROOT/storage/workconnect.sqlite"
CUSTOMER_THEME="$(sqlite3 "$DB" "SELECT theme FROM users WHERE email='customer@workconnect.test';")"
SELLER_THEME="$(sqlite3 "$DB" "SELECT theme FROM users WHERE email='seller@workconnect.test';")"
ADMIN_THEME="$(sqlite3 "$DB" "SELECT theme FROM users WHERE email='admin@workconnect.test';")"

cleanup() {
  sqlite3 "$DB" "UPDATE users SET theme='$CUSTOMER_THEME' WHERE email='customer@workconnect.test'; UPDATE users SET theme='$SELLER_THEME' WHERE email='seller@workconnect.test'; UPDATE users SET theme='$ADMIN_THEME' WHERE email='admin@workconnect.test';"
  rm -rf "$TMP"
}
trap cleanup EXIT
sqlite3 "$DB" "DELETE FROM rate_limits WHERE rate_key IS NOT NULL;"

check_role() {
  local role="$1" email="$2" home="$3" pages="$4"
  local jar="$TMP/$role.jar" login csrf body settings
  login="$(curl -sS -b "$jar" -c "$jar" "$BASE_URL/?page=login")"
  csrf="$(print "$login" | perl -ne 'if (/name="csrf_token"\s+value="([^"]+)"/) { print $1; exit }')"
  curl -sS -b "$jar" -c "$jar" -o /dev/null --data-urlencode "csrf_token=$csrf" --data-urlencode action=login --data-urlencode "email=$email" --data-urlencode 'password=Demo1234!' "$BASE_URL/?page=login"
  settings="$(curl -sS -b "$jar" -c "$jar" "$BASE_URL/?page=settings")"
  csrf="$(print "$settings" | perl -ne 'if (/name="csrf_token"\s+value="([^"]+)"/) { print $1; exit }')"
  curl -sS -b "$jar" -c "$jar" -o /dev/null --data-urlencode "csrf_token=$csrf" \
    --data-urlencode action=update_preferences --data-urlencode theme=dark --data-urlencode language=en \
    --data-urlencode text_scale=medium --data-urlencode ui_scale=comfortable \
    --data-urlencode email_notifications=1 "$BASE_URL/?page=settings"
  for page in ${(z)pages}; do
    body="$(curl -sS -b "$jar" -c "$jar" "$BASE_URL/?page=$page")"
    grep -q 'workspace-page theme-dark' <<< "$body" || { print -u2 "[FAIL] $role/$page did not render dark theme"; exit 1; }
  done
  print "[PASS] $role dark mode routes render."
}

check_role customer customer@workconnect.test dashboard 'dashboard marketplace orders saved-services messages settings topup notifications disputes'
check_role seller seller@workconnect.test seller-dashboard 'seller-dashboard seller-services seller-orders seller-messages seller-earnings seller-payouts seller-analytics seller-settings disputes'
check_role admin admin@workconnect.test admin-users 'admin-users admin-control admin-orders admin-messages admin-disputes admin-payouts admin-finance admin-settings admin-security'
print '[OK] Dark mode route tests passed.'
