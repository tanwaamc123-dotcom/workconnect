#!/bin/zsh
set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1/WorkConnect}"
ROOT="$(cd -- "$(dirname -- "$0")/.." && pwd)"
DB="$ROOT/storage/workconnect.sqlite"
TMP="$(mktemp -d)"
STAMP="$$"
CUSTOMER="lifecycle-c-$STAMP@example.test"
SELLER="lifecycle-s-$STAMP@example.test"
trap 'sqlite3 "$DB" "PRAGMA foreign_keys=ON; DELETE FROM ledger_transactions WHERE order_id IN (SELECT id FROM orders WHERE customer_id IN (SELECT id FROM users WHERE email='\''$CUSTOMER'\'')) OR user_id IN (SELECT id FROM users WHERE email IN ('\''$CUSTOMER'\'','\''$SELLER'\'')); DELETE FROM notifications WHERE user_id IN (SELECT id FROM users WHERE email IN ('\''$CUSTOMER'\'','\''$SELLER'\'')); DELETE FROM reviews WHERE customer_id IN (SELECT id FROM users WHERE email='\''$CUSTOMER'\''); DELETE FROM messages WHERE sender_id IN (SELECT id FROM users WHERE email IN ('\''$CUSTOMER'\'','\''$SELLER'\'')) OR receiver_id IN (SELECT id FROM users WHERE email IN ('\''$CUSTOMER'\'','\''$SELLER'\'')); DELETE FROM wallet_transactions WHERE user_id IN (SELECT id FROM users WHERE email='\''$CUSTOMER'\''); DELETE FROM orders WHERE customer_id IN (SELECT id FROM users WHERE email='\''$CUSTOMER'\''); DELETE FROM services WHERE seller_id IN (SELECT id FROM users WHERE email='\''$SELLER'\''); DELETE FROM users WHERE email IN ('\''$CUSTOMER'\'','\''$SELLER'\'');"; rm -rf "$TMP"' EXIT

HASH="$(php -r "echo password_hash('FlowPass123!', PASSWORD_DEFAULT);")"
sqlite3 "$DB" "BEGIN; INSERT INTO users(role_id,name,email,password_hash,status,wallet_balance,wallet_balance_satang) VALUES((SELECT id FROM roles WHERE name='customer'),'Flow Customer','$CUSTOMER','$HASH','active',5000,500000); INSERT INTO users(role_id,name,email,password_hash,status) VALUES((SELECT id FROM roles WHERE name='seller'),'Flow Seller','$SELLER','$HASH','active'); INSERT INTO services(seller_id,category_id,title,description,price,price_satang,delivery_days,features,thumbnail,status) VALUES((SELECT id FROM users WHERE email='$SELLER'),(SELECT MIN(id) FROM categories),'Lifecycle Test Service','Temporary integration test service description.',500,50000,3,'Test feature','website','active'); INSERT INTO ledger_transactions(reference,transaction_type,user_id) VALUES('TEST-OPEN-$STAMP','opening_wallet',(SELECT id FROM users WHERE email='$CUSTOMER')); INSERT INTO ledger_entries(transaction_id,account_code,owner_type,owner_id,amount_satang) VALUES((SELECT id FROM ledger_transactions WHERE reference='TEST-OPEN-$STAMP'),'customer_wallet','user',(SELECT id FROM users WHERE email='$CUSTOMER'),500000),((SELECT id FROM ledger_transactions WHERE reference='TEST-OPEN-$STAMP'),'opening_equity','platform',0,-500000); COMMIT;"
SERVICE="$(sqlite3 "$DB" "SELECT id FROM services WHERE seller_id=(SELECT id FROM users WHERE email='$SELLER');")"

login() {
  local email="$1" jar="$2" page csrf
  page="$(curl -sS -b "$jar" -c "$jar" "$BASE_URL/?page=login")"
  csrf="$(print "$page" | perl -ne 'if (/name="csrf_token"\s+value="([^"]+)"/) { print $1; exit }')"
  curl -sS -b "$jar" -c "$jar" -o /dev/null --data-urlencode "csrf_token=$csrf" --data-urlencode action=login --data-urlencode "email=$email" --data-urlencode 'password=FlowPass123!' "$BASE_URL/?page=login"
}

csrf_for() {
  curl -sS -b "$1" -c "$1" "$BASE_URL/?page=$2" | perl -ne 'if (!$found && /name="csrf_token"\s+value="([^"]+)"/) { print $1; $found=1 }'
}

post() {
  local jar="$1" page="$2" data="$3" csrf
  csrf="$(csrf_for "$jar" "$page")"
  curl -sS -b "$jar" -c "$jar" -o /dev/null -H 'Content-Type: application/x-www-form-urlencoded' --data "csrf_token=$csrf&$data" "$BASE_URL/?page=$page"
}

CJ="$TMP/customer"; SJ="$TMP/seller"
login "$CUSTOMER" "$CJ"; login "$SELLER" "$SJ"
post "$CJ" dashboard "action=place_order&service_id=$SERVICE&requirements=Please%20complete%20this%20temporary%20integration%20test%20project.&payment_method=wallet"
ORDER1="$(sqlite3 "$DB" "SELECT id FROM orders WHERE customer_id=(SELECT id FROM users WHERE email='$CUSTOMER') ORDER BY id DESC LIMIT 1;")"
post "$SJ" seller-orders "action=update_order&order_id=$ORDER1&status=in_progress"
post "$SJ" seller-orders "action=update_order&order_id=$ORDER1&status=completed"
[[ "$(sqlite3 "$DB" "SELECT status FROM orders WHERE id=$ORDER1;")" == 'in_progress' ]] || { print -u2 '[FAIL] Seller was able to complete and release their own order'; exit 1; }
post "$SJ" seller-orders "action=submit_delivery&order_id=$ORDER1&delivery_message=Completed%20temporary%20integration%20test%20delivery."
[[ "$(sqlite3 "$DB" "SELECT status||(SELECT COUNT(*) FROM order_deliveries WHERE order_id=$ORDER1) FROM orders WHERE id=$ORDER1;")" == 'review1' ]] || { print -u2 '[FAIL] Delivery workflow did not move the order to review'; exit 1; }
post "$CJ" orders "action=update_order&order_id=$ORDER1&status=completed"
post "$CJ" orders "action=submit_review&order_id=$ORDER1&rating=5&comment=Excellent%20temporary%20integration%20test%20result."
[[ "$(sqlite3 "$DB" "SELECT status||(SELECT COUNT(*) FROM reviews WHERE order_id=$ORDER1) FROM orders WHERE id=$ORDER1;")" == 'completed1' ]] || { print -u2 '[FAIL] Completion/review flow failed'; exit 1; }

post "$CJ" dashboard "action=place_order&service_id=$SERVICE&requirements=Please%20create%20a%20second%20temporary%20test%20order%20now.&payment_method=wallet"
ORDER2="$(sqlite3 "$DB" "SELECT id FROM orders WHERE customer_id=(SELECT id FROM users WHERE email='$CUSTOMER') ORDER BY id DESC LIMIT 1;")"
post "$CJ" orders "action=update_order&order_id=$ORDER2&status=cancelled&reason=Customer%20cancelled%20the%20temporary%20test."
RESULT="$(sqlite3 "$DB" "SELECT orders.status||payments.status||(SELECT wallet_balance FROM users WHERE email='$CUSTOMER')||(SELECT COUNT(*) FROM wallet_transactions WHERE reference='REFUND-'||orders.order_number) FROM orders JOIN payments ON payments.order_id=orders.id WHERE orders.id=$ORDER2;")"
[[ "$RESULT" == 'cancelledrefunded4500.01' || "$RESULT" == 'cancelledrefunded45001' ]] || { print -u2 "[FAIL] Cancel/refund flow failed: $RESULT"; exit 1; }
print '[PASS] Delivery, customer-only acceptance, review, cancellation, refund, and seller authorization work.'
