<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/config.php';
require dirname(__DIR__) . '/includes/database.php';
$errors = [];
if (!app_is_production()) $errors[] = 'APP_ENV must be production.';
if (!str_starts_with(app_base_url(), 'https://')) $errors[] = 'APP_URL must use HTTPS.';
if (app_encryption_key() === '') $errors[] = 'APP_ENCRYPTION_KEY must be a base64-encoded 32-byte key.';
if (!stripe_is_configured()) $errors[] = 'A valid production Stripe key is required.';
if (stripe_webhook_secret() === '') $errors[] = 'STRIPE_WEBHOOK_SECRET is required.';
if (strtolower((string) env_value('MAIL_TRANSPORT', 'log')) !== 'mail') $errors[] = 'MAIL_TRANSPORT must be mail.';
$databaseUrl = trim((string) env_value('DATABASE_URL', ''));
if ($databaseUrl === '') {
    $errors[] = 'DATABASE_URL must point to PostgreSQL in production.';
} elseif (!extension_loaded('pdo_pgsql')) {
    $errors[] = 'The pdo_pgsql PHP extension is required.';
} else {
    try {
        $connection = db();
        if (database_driver($connection) !== 'pgsql') {
            $errors[] = 'The production database must use PostgreSQL.';
        }
        $connection->query('SELECT 1')->fetchColumn();
    } catch (Throwable $error) {
        $errors[] = 'PostgreSQL check failed: ' . $error->getMessage();
    }
}
if ($errors) {
    foreach ($errors as $error) fwrite(STDERR, "[FAIL] $error\n");
    exit(1);
}
echo "Production configuration is ready.\n";
