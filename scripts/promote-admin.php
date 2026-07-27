<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/config.php';
require dirname(__DIR__) . '/includes/database.php';
require dirname(__DIR__) . '/includes/helpers.php';
require dirname(__DIR__) . '/includes/auth.php';
require dirname(__DIR__) . '/includes/platform.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['email:', 'admin-role::', 'yes']);
$email = strtolower(trim((string) ($options['email'] ?? '')));
$adminRole = trim((string) ($options['admin-role'] ?? 'owner'));
$allowedRoles = ['owner', 'finance', 'support', 'moderator', 'analyst'];

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($adminRole, $allowedRoles, true) || !array_key_exists('yes', $options)) {
    fwrite(STDERR, "Usage: php scripts/promote-admin.php --email='you@example.com' --admin-role=owner --yes\n");
    exit(1);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $user = $pdo->prepare('SELECT id,status,is_demo FROM users WHERE email=?');
    $user->execute([$email]);
    $account = $user->fetch();
    if (!$account || (string) $account['status'] !== 'active' || (int) $account['is_demo'] === 1) {
        throw new RuntimeException('The email must belong to an active, non-demo account registered through WorkConnect.');
    }

    $statement = $pdo->prepare(
        "UPDATE users SET role_id=(SELECT id FROM roles WHERE name='admin'),admin_role=?,updated_at=CURRENT_TIMESTAMP
         WHERE id=?"
    );
    $statement->execute([$adminRole, (int) $account['id']]);
    audit_event((int) $account['id'], 'admin_promoted_cli', 'user', (int) $account['id'], ['admin_role' => $adminRole]);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

echo "[PASS] $email is now an $adminRole administrator. Sign out, sign in again, then enable MFA at ?page=admin-security#mfa.\n";
