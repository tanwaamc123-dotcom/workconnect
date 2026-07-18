#!/bin/zsh
set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1/WorkConnect}"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

login_and_check() {
  local role="$1" email="$2" password="$3" routes="$4"
  local jar="$TMP_DIR/$role.cookies" body csrf code
  body="$(curl -sS -b "$jar" -c "$jar" "$BASE_URL/?page=login")"
  csrf="$(print "$body" | perl -ne 'if (/name="csrf_token"\s+value="([^"]+)"/) { print $1; exit }')"
  [[ -n "$csrf" ]] || { print -u2 "[FAIL] $role login CSRF missing"; exit 1; }
  curl -sS -b "$jar" -c "$jar" -o /dev/null --data-urlencode "csrf_token=$csrf" \
    --data-urlencode 'action=login' --data-urlencode "email=$email" --data-urlencode "password=$password" "$BASE_URL/?page=login"
  for route in ${(z)routes}; do
    code="$(curl -sS -b "$jar" -c "$jar" -o "$TMP_DIR/body" -w '%{http_code}' "$BASE_URL/?page=$route")"
    [[ "$code" == "200" ]] || { print -u2 "[FAIL] $role/$route returned HTTP $code"; exit 1; }
    grep -q 'Fatal error\|Uncaught exception\|Warning:' "$TMP_DIR/body" && { print -u2 "[FAIL] PHP error rendered on $role/$route"; exit 1; }
  done
  print "[PASS] $role routes load."
}

login_and_check customer customer@workconnect.test 'Demo1234!' 'dashboard marketplace orders saved-services messages notifications profile settings topup about-workspace'
login_and_check seller seller@workconnect.test 'Demo1234!' 'seller-dashboard seller-services seller-add-service seller-orders seller-messages seller-earnings seller-analytics seller-profile seller-settings marketplace messages notifications settings'
login_and_check admin admin@workconnect.test 'Demo1234!' 'admin-users admin-services admin-orders admin-messages admin-control admin-approvals admin-moderation admin-categories admin-coupons admin-logs admin-broadcast admin-reports admin-finance admin-settings marketplace messages notifications settings'

print '[OK] Authenticated route tests passed.'
