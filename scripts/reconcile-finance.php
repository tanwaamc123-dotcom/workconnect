<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/config.php';
require dirname(__DIR__) . '/includes/database.php';
require dirname(__DIR__) . '/includes/helpers.php';
require dirname(__DIR__) . '/includes/platform.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

initialize_ledger_opening_balances();
$errors = [];
$warnings = [];

$ledgerSum = (int) scalar('SELECT COALESCE(SUM(amount_satang),0) FROM ledger_entries');
if ($ledgerSum !== 0) {
    $errors[] = "Ledger is out of balance by $ledgerSum satang.";
}

foreach (fetch_all('SELECT id,email,wallet_balance_satang FROM users') as $account) {
    $ledgerBalance = ledger_balance('customer_wallet', 'user', (int) $account['id']);
    if ($ledgerBalance !== (int) $account['wallet_balance_satang']) {
        $errors[] = sprintf(
            'Wallet mismatch user=%d email=%s mirror=%d ledger=%d',
            (int) $account['id'],
            (string) $account['email'],
            (int) $account['wallet_balance_satang'],
            $ledgerBalance
        );
    }
}

foreach (fetch_all(
    "SELECT orders.*,payments.status AS payment_status FROM orders JOIN payments ON payments.order_id=orders.id"
) as $order) {
    $expected = in_array((string) $order['status'], ['pending', 'in_progress', 'review'], true)
        && (string) $order['payment_status'] === 'paid'
        ? value_satang($order, 'total_satang', 'total')
        : 0;
    $actual = ledger_balance('platform_escrow', 'order', (int) $order['id']);
    if ($actual !== $expected) {
        $errors[] = sprintf(
            'Escrow mismatch order=%s expected=%d actual=%d',
            (string) $order['order_number'],
            $expected,
            $actual
        );
    }
}

$moneyDrift = (int) scalar(
    'SELECT COUNT(*) FROM users WHERE wallet_balance_satang<>CAST(ROUND(wallet_balance*100) AS INTEGER)'
) + (int) scalar(
    'SELECT COUNT(*) FROM services WHERE price_satang<>CAST(ROUND(price*100) AS INTEGER)'
) + (int) scalar(
    'SELECT COUNT(*) FROM orders WHERE total_satang<>CAST(ROUND(total*100) AS INTEGER)'
);
if ($moneyDrift > 0) {
    $errors[] = "$moneyDrift legacy decimal mirrors differ from satang values.";
}

$staleProcessing = (int) scalar(
    "SELECT COUNT(*) FROM payment_requests WHERE status='processing' AND processing_started_at<?",
    [gmdate('Y-m-d H:i:s', time() - 600)]
);
if ($staleProcessing > 0) {
    $warnings[] = "$staleProcessing payment requests have been processing for over 10 minutes.";
}

echo sprintf(
    "[SUMMARY] ledger_entries=%d transactions=%d users=%d orders=%d\n",
    (int) scalar('SELECT COUNT(*) FROM ledger_entries'),
    (int) scalar('SELECT COUNT(*) FROM ledger_transactions'),
    (int) scalar('SELECT COUNT(*) FROM users'),
    (int) scalar('SELECT COUNT(*) FROM orders')
);
foreach ($warnings as $warning) {
    echo "[WARN] $warning\n";
}
foreach ($errors as $error) {
    fwrite(STDERR, "[FAIL] $error\n");
}
if ($errors === []) {
    echo "[PASS] Financial mirrors, ledger balance, wallets, and escrow reconcile.\n";
}
$status = $errors === [] ? 'ok' : 'failed';
record_job_run('reconciliation', $status, sprintf('warnings=%d errors=%d', count($warnings), count($errors)));
exit($status === 'ok' ? 0 : 1);
