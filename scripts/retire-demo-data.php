<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/config.php';
require dirname(__DIR__) . '/includes/database.php';
require dirname(__DIR__) . '/includes/helpers.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['service-owner-email:', 'yes']);
$email = strtolower(trim((string) ($options['service-owner-email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !array_key_exists('yes', $options)) {
    fwrite(STDERR, "Usage: php scripts/retire-demo-data.php --service-owner-email='seller@example.com' --yes\n");
    exit(1);
}

$pdo = db();
$owner = $pdo->prepare(
    "SELECT users.id FROM users JOIN roles ON roles.id=users.role_id
     WHERE users.email=? AND roles.name='seller' AND users.status='active' AND users.is_demo=0"
);
$owner->execute([$email]);
$serviceOwnerId = (int) $owner->fetchColumn();
if ($serviceOwnerId < 1) {
    fwrite(STDERR, "[FAIL] $email must be an active non-demo seller before services can be retained.\n");
    exit(1);
}

try {
    $summary = retire_demo_data($pdo, $serviceOwnerId);
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

echo '[PASS] Demo mode is disabled. Retained ' . $summary['services_retained'] . ' services under ' . $summary['service_owner_email'] . '.' . PHP_EOL;
echo '[PASS] Removed ' . $summary['users_removed'] . ' demo users, ' . $summary['orders_removed'] . ' orders, ' . $summary['messages_removed'] . ' messages, and ' . $summary['payments_removed'] . ' payments.' . PHP_EOL;
