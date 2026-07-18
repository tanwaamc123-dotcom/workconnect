#!/bin/zsh
set -e

BASE_URL="${BASE_URL:-http://127.0.0.1/WorkConnect}"
SEED_DEMO="${SEED_DEMO:-1}"

cd /Applications/XAMPP/xamppfiles/htdocs/WorkConnect
BASE_URL="$BASE_URL" SEED_DEMO="$SEED_DEMO" zsh tests/smoke.sh
