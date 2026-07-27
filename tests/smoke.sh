#!/bin/zsh
set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1/WorkConnect}"
SEED_DEMO="${SEED_DEMO:-0}"
TMP_DIR="$(mktemp -d)"
COOKIE_JAR="$TMP_DIR/cookies.txt"
ROOT="$(cd -- "$(dirname -- "$0")/.." && pwd)"
SMOKE_EMAIL=""

cleanup() {
  if [[ -n "$SMOKE_EMAIL" ]]; then
    sqlite3 "$ROOT/storage/workconnect.sqlite" "PRAGMA foreign_keys=ON; DELETE FROM sessions WHERE user_id IN (SELECT id FROM users WHERE email='$SMOKE_EMAIL'); DELETE FROM security_logs WHERE user_id IN (SELECT id FROM users WHERE email='$SMOKE_EMAIL'); DELETE FROM users WHERE email='$SMOKE_EMAIL';"
  fi
  rm -rf "$TMP_DIR"
}
trap cleanup EXIT

response_code() {
  local response="$1"
  print -r -- "${response%%$'\n'*}"
}

request() {
  local method="$1"
  local url="$2"
  local data="${3:-}"
  local extra_headers="${4:-}"
  local body_file="$TMP_DIR/body.$$"
  local header_file="$TMP_DIR/headers.$$"
  local code
  local -a curl_args

  curl_args=(-sS -o "$body_file" -D "$header_file" -b "$COOKIE_JAR" -c "$COOKIE_JAR" -X "$method")
  if [[ -n "$extra_headers" ]]; then
    curl_args+=(-H "$extra_headers")
  fi

  if [[ -n "$data" ]]; then
    code="$(
      curl "${curl_args[@]}" --data-binary "$data" -w '%{http_code}' "$url"
    )"
  else
    code="$(
      curl "${curl_args[@]}" -w '%{http_code}' "$url"
    )"
  fi

  printf '%s\n' "$code"
  cat "$body_file"
  printf '\n---HEADERS---\n'
  cat "$header_file"
}

extract_csrf() {
  perl -ne 'if (!$found && /name="csrf_token"\s+value="([^"]+)"/) { print $1; $found=1 }'
}

assert_contains() {
  local haystack="$1"
  local needle="$2"
  local label="$3"
  if [[ "$haystack" != *"$needle"* ]]; then
    echo "[FAIL] $label"
    exit 1
  fi
}

echo "[INFO] Testing WorkConnect at $BASE_URL"

home_tmp="$(request GET "$BASE_URL/")"
home_code="$(response_code "$home_tmp")"
home_body="$(printf '%s' "$home_tmp" | sed '1d;/^---HEADERS---$/,$d')"
if [[ "$home_code" != "200" ]]; then
  echo "[FAIL] Home page returned HTTP $home_code"
  exit 1
fi
assert_contains "$home_body" "WorkConnect" "Home page branding missing"
echo "[PASS] Home page loads."

login_tmp="$(request GET "$BASE_URL/?page=login")"
login_code="$(response_code "$login_tmp")"
login_body="$(printf '%s' "$login_tmp" | sed '1d;/^---HEADERS---$/,$d')"
if [[ "$login_code" != "200" ]]; then
  echo "[FAIL] Login page returned HTTP $login_code"
  exit 1
fi
assert_contains "$login_body" "Sign in to WorkConnect" "Login page content unexpected"
echo "[PASS] Login page loads."

login_csrf="$(printf '%s' "$login_body" | extract_csrf)"
if [[ -z "$login_csrf" ]]; then
  echo "[FAIL] Login CSRF token not found"
  exit 1
fi

if [[ "$login_body" == *"Demo access"* ]]; then
  login_email="customer%40workconnect.test"
  login_password="Demo1234%21"
else
  register_tmp="$(request GET "$BASE_URL/?page=register")"
  register_code="$(response_code "$register_tmp")"
  register_body="$(printf '%s' "$register_tmp" | sed '1d;/^---HEADERS---$/,$d')"
  if [[ "$register_code" != "200" ]]; then
    echo "[FAIL] Register page returned HTTP $register_code"
    exit 1
  fi
  register_csrf="$(printf '%s' "$register_body" | extract_csrf)"
  if [[ -z "$register_csrf" ]]; then
    echo "[FAIL] Register CSRF token not found"
    exit 1
  fi
  smoke_email="smoke.$$.test@example.com"
  smoke_password="SmokePass123!"
  SMOKE_EMAIL="$smoke_email"
  register_post_tmp="$(request POST "$BASE_URL/?page=register" "csrf_token=$register_csrf&action=register&name=Smoke%20Tester&email=$smoke_email&password=$smoke_password&password_confirmation=$smoke_password&role=customer" "Content-Type: application/x-www-form-urlencoded")"
  register_post_code="$(response_code "$register_post_tmp")"
  if [[ "$register_post_code" != "302" && "$register_post_code" != "200" ]]; then
    echo "[FAIL] Register request returned HTTP $register_post_code"
    exit 1
  fi
  echo "[PASS] Customer registration flow works."
  login_email="$smoke_email"
  login_password="$smoke_password"
  logout_page_tmp="$(request GET "$BASE_URL/?page=dashboard")"
  logout_body="$(printf '%s' "$logout_page_tmp" | sed '1d;/^---HEADERS---$/,$d')"
  logout_csrf="$(printf '%s' "$logout_body" | extract_csrf)"
  if [[ -n "$logout_csrf" ]]; then
    request POST "$BASE_URL/?page=dashboard" "csrf_token=$logout_csrf&action=logout" "Content-Type: application/x-www-form-urlencoded" >/dev/null
  fi
  login_tmp="$(request GET "$BASE_URL/?page=login")"
  login_body="$(printf '%s' "$login_tmp" | sed '1d;/^---HEADERS---$/,$d')"
  login_csrf="$(printf '%s' "$login_body" | extract_csrf)"
fi

login_post_tmp="$(request POST "$BASE_URL/?page=login" "csrf_token=$login_csrf&action=login&email=$login_email&password=$login_password&remember=1" "Content-Type: application/x-www-form-urlencoded")"
login_post_code="$(response_code "$login_post_tmp")"
login_post_headers="$(printf '%s' "$login_post_tmp" | awk '/^---HEADERS---$/{flag=1;next}flag')"
if [[ "$login_post_code" != "302" && "$login_post_code" != "200" ]]; then
  echo "[FAIL] Login request returned HTTP $login_post_code"
  exit 1
fi
if [[ "$login_post_headers" != *"Location: ?page=dashboard"* ]]; then
  echo "[FAIL] Login did not redirect to dashboard"
  exit 1
fi
echo "[PASS] Login flow works."

dashboard_tmp="$(request GET "$BASE_URL/?page=dashboard")"
dashboard_code="$(response_code "$dashboard_tmp")"
dashboard_body="$(printf '%s' "$dashboard_tmp" | sed '1d;/^---HEADERS---$/,$d')"
if [[ "$dashboard_code" != "200" ]]; then
  echo "[FAIL] Dashboard page returned HTTP $dashboard_code"
  exit 1
fi
assert_contains "$dashboard_body" "Dashboard" "Dashboard content unexpected"
echo "[PASS] Dashboard page loads."

settings_tmp="$(request GET "$BASE_URL/?page=settings")"
settings_code="$(response_code "$settings_tmp")"
settings_body="$(printf '%s' "$settings_tmp" | sed '1d;/^---HEADERS---$/,$d')"
if [[ "$settings_code" != "200" ]]; then
  echo "[FAIL] Settings page returned HTTP $settings_code"
  exit 1
fi
assert_contains "$settings_body" "Settings" "Settings content unexpected"
echo "[PASS] Settings page loads."

messages_tmp="$(request GET "$BASE_URL/?page=messages")"
messages_code="$(response_code "$messages_tmp")"
if [[ "$messages_code" != "200" ]]; then
  echo "[FAIL] Messages page returned HTTP $messages_code"
  exit 1
fi
echo "[PASS] Messages page loads."

echo "[OK] Smoke tests passed."
