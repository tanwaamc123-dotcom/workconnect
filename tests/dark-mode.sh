#!/bin/zsh
set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1/WorkConnect}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
sqlite3 "${0:A:h:h}/storage/workconnect.sqlite" "DELETE FROM rate_limits WHERE rate_key IS NOT NULL;"

check_role() {
  local role="$1" email="$2" home="$3" pages="$4"
  local jar="$TMP/$role.jar" login csrf body
  login="$(curl -sS -b "$jar" -c "$jar" "$BASE_URL/?page=login")"
  csrf="$(print "$login" | perl -ne 'if (/name="csrf_token"\s+value="([^"]+)"/) { print $1; exit }')"
  curl -sS -b "$jar" -c "$jar" -o /dev/null --data-urlencode "csrf_token=$csrf" --data-urlencode action=login --data-urlencode "email=$email" --data-urlencode 'password=Demo1234!' "$BASE_URL/?page=login"
  for page in ${(z)pages}; do
    body="$(curl -sS -b "$jar" -c "$jar" "$BASE_URL/?page=$page")"
    grep -q 'workspace-page theme-dark' <<< "$body" || { print -u2 "[FAIL] $role/$page did not render dark theme"; exit 1; }
  done
  print "[PASS] $role dark mode routes render."
}

check_role customer customer@workconnect.test dashboard 'dashboard marketplace orders messages settings topup notifications'
check_role seller seller@workconnect.test seller-dashboard 'seller-dashboard seller-services seller-orders seller-messages seller-earnings seller-analytics seller-settings'
check_role admin admin@workconnect.test admin-users 'admin-users admin-control admin-orders admin-messages admin-finance admin-settings'
print '[OK] Dark mode route tests passed.'
