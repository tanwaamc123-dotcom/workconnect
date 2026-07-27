<?php

declare(strict_types=1);

// Removes every user-owned record while retaining the marketplace catalogue.
require dirname(__DIR__) . '/includes/config.php';
require dirname(__DIR__) . '/includes/database.php';
require dirname(__DIR__) . '/includes/helpers.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['yes']);
if (!array_key_exists('yes', $options)) {
    fwrite(STDERR, "Usage: php scripts/reset-accounts-retain-services.php --yes\n");
    exit(1);
}

$pdo = db();
$catalogEmail = 'catalog@system.invalid';
$uploads = [];

try {
    $pdo->beginTransaction();

    // Retain product imagery, but collect all account and order attachments for removal.
    foreach ([
        'SELECT avatar AS path FROM users WHERE avatar<>\'\'',
        'SELECT id_card_front AS path FROM users WHERE id_card_front<>\'\' UNION SELECT id_card_back AS path FROM users WHERE id_card_back<>\'\'',
        'SELECT attachment AS path FROM messages WHERE attachment<>\'\'',
        'SELECT attachment AS path FROM order_deliveries WHERE attachment<>\'\'',
        'SELECT attachment AS path FROM dispute_evidence WHERE attachment<>\'\'',
        'SELECT slip_path AS path FROM wallet_transactions WHERE slip_path<>\'\'',
    ] as $query) {
        foreach ($pdo->query($query)->fetchAll(PDO::FETCH_COLUMN) as $path) {
            $uploads[] = (string) $path;
        }
    }

    $sellerRole = $pdo->prepare('SELECT id FROM roles WHERE name=?');
    $sellerRole->execute(['seller']);
    $sellerRoleId = (int) $sellerRole->fetchColumn();
    if ($sellerRoleId < 1) {
        throw new RuntimeException('The seller role is missing.');
    }

    $catalog = $pdo->prepare('SELECT id FROM users WHERE email=?');
    $catalog->execute([$catalogEmail]);
    $catalogId = (int) $catalog->fetchColumn();
    if ($catalogId < 1) {
        $createCatalog = $pdo->prepare(
            'INSERT INTO users (role_id,name,email,password_hash,status,is_demo,email_notifications) VALUES (?,?,?,?,?,0,0)'
        );
        // The account is intentionally inaccessible; it only preserves service ownership until reassignment.
        $createCatalog->execute([$sellerRoleId, 'WorkConnect Catalog', $catalogEmail, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), 'active']);
        $catalogId = (int) $pdo->lastInsertId();
    }

    // Active status is required for public service listings; the generated password remains unknown.
    $pdo->prepare('UPDATE users SET role_id=?,status=?,is_demo=0,email_notifications=0 WHERE id=?')
        ->execute([$sellerRoleId, 'active', $catalogId]);

    $servicesRetained = (int) $pdo->query('SELECT COUNT(*) FROM services')->fetchColumn();
    $pdo->prepare('UPDATE services SET seller_id=?,is_demo=0,updated_at=CURRENT_TIMESTAMP')->execute([$catalogId]);

    // Delete dependent records first so the account deletion remains referentially safe on SQLite and PostgreSQL.
    foreach ([
        'DELETE FROM dispute_evidence',
        'DELETE FROM disputes',
        'DELETE FROM order_deliveries',
        'DELETE FROM reviews',
        'DELETE FROM messages',
        'DELETE FROM coupon_redemptions',
        'DELETE FROM order_events',
        'DELETE FROM payments',
        'DELETE FROM orders',
        'DELETE FROM payment_requests',
        'DELETE FROM wallet_transactions',
        'DELETE FROM payouts',
        'DELETE FROM favorites',
        'DELETE FROM account_requests',
        'DELETE FROM password_reset_tokens',
        'DELETE FROM sessions',
        'DELETE FROM notifications',
        'DELETE FROM security_logs',
        'DELETE FROM ledger_entries',
        'DELETE FROM ledger_transactions',
        'DELETE FROM outbox_messages',
        'DELETE FROM payment_provider_events',
        'DELETE FROM newsletter_subscribers',
        'DELETE FROM rate_limits',
    ] as $query) {
        $pdo->exec($query);
    }

    $removeUsers = $pdo->prepare('DELETE FROM users WHERE id<>?');
    $removeUsers->execute([$catalogId]);
    $usersRemoved = $removeUsers->rowCount();

    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

foreach (array_unique($uploads) as $path) {
    delete_stored_upload($path);
}

$remainingUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$remainingServices = (int) $pdo->query('SELECT COUNT(*) FROM services')->fetchColumn();
$remainingOrders = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
if ($remainingUsers !== 1 || $remainingServices !== $servicesRetained || $remainingOrders !== 0) {
    fwrite(STDERR, '[FAIL] Reset verification failed.' . PHP_EOL);
    exit(1);
}

echo '[PASS] Removed ' . $usersRemoved . ' user accounts and their associated data.' . PHP_EOL;
echo '[PASS] Retained ' . $remainingServices . ' services under the inaccessible system catalogue account.' . PHP_EOL;
echo '[PASS] Remaining accounts: ' . $remainingUsers . '; remaining orders: ' . $remainingOrders . '.' . PHP_EOL;
