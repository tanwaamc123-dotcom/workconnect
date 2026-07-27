<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/config.php';
require dirname(__DIR__) . '/includes/database.php';
require dirname(__DIR__) . '/includes/helpers.php';
require dirname(__DIR__) . '/includes/platform.php';
$errors = [];
if (!app_is_production()) $errors[] = 'APP_ENV must be production.';
if (!str_starts_with(app_base_url(), 'https://')) $errors[] = 'APP_URL must use HTTPS.';
if (preg_match('/^https:\/\/(localhost|127\.0\.0\.1|\[::1\])(?::\d+)?(?:\/|$)/i', app_base_url())) {
    $errors[] = 'APP_URL must use the public production hostname.';
}
if (app_encryption_key() === '') $errors[] = 'APP_ENCRYPTION_KEY must be a base64-encoded 32-byte key.';
if (!str_starts_with(stripe_secret_key(), 'sk_live_')) $errors[] = 'A live Stripe secret key is required.';
if (!str_starts_with(stripe_publishable_key(), 'pk_live_')) $errors[] = 'A live Stripe publishable key is required.';
if (!str_starts_with(stripe_webhook_secret(), 'whsec_')) $errors[] = 'A valid STRIPE_WEBHOOK_SECRET is required.';
if (strtolower((string) env_value('MAIL_TRANSPORT', 'log')) !== 'resend') $errors[] = 'MAIL_TRANSPORT must be resend.';
if (trim((string) env_value('RESEND_API_KEY', '')) === '') $errors[] = 'RESEND_API_KEY is required.';
if (!filter_var((string) env_value('MAIL_FROM', ''), FILTER_VALIDATE_EMAIL)) $errors[] = 'MAIL_FROM must be a valid verified sender address.';
if (storage_driver() !== 's3') $errors[] = 'STORAGE_DRIVER must be s3 for durable production uploads.';
if (!object_storage_is_configured()) $errors[] = 'S3_ENDPOINT, S3_BUCKET, S3_ACCESS_KEY, and S3_SECRET_KEY are required.';
if ((string) env_value('BACKUP_OFFSITE_REQUIRED', '0') !== '1') $errors[] = 'BACKUP_OFFSITE_REQUIRED must be 1 for production.';
$storageEndpoint = trim((string) env_value('S3_ENDPOINT', ''));
if ($storageEndpoint !== '' && !str_starts_with(strtolower($storageEndpoint), 'https://')) {
    $errors[] = 'S3_ENDPOINT must use HTTPS.';
}
$timezoneName = trim((string) env_value('APP_TIMEZONE', 'Asia/Bangkok'));
try {
    new DateTimeZone($timezoneName);
} catch (Throwable $error) {
    $errors[] = 'APP_TIMEZONE is invalid.';
}
foreach (['curl', 'fileinfo', 'mbstring', 'openssl', 'pdo_pgsql'] as $extension) {
    if (!extension_loaded($extension)) {
        $errors[] = "The $extension PHP extension is required.";
    }
}
$databaseUrl = trim((string) env_value('DATABASE_URL', ''));
if ($databaseUrl === '') {
    $errors[] = 'DATABASE_URL must point to PostgreSQL in production.';
} else {
    $databaseParts = parse_url($databaseUrl);
    $databaseOptions = [];
    if (is_array($databaseParts)) {
        parse_str((string) ($databaseParts['query'] ?? ''), $databaseOptions);
    }
    $sslMode = strtolower((string) ($databaseOptions['sslmode'] ?? env_value('DB_SSLMODE', 'require')));
    if (!in_array($sslMode, ['require', 'verify-ca', 'verify-full'], true)) {
        $errors[] = 'PostgreSQL TLS must use sslmode=require, verify-ca, or verify-full.';
    }
    try {
        $connection = db();
        if (database_driver($connection) !== 'pgsql') {
            $errors[] = 'The production database must use PostgreSQL.';
        }
        $connection->query('SELECT 1')->fetchColumn();
        $schemaVersion = (int) scalar("SELECT COALESCE(meta_value,'0') FROM schema_meta WHERE meta_key='schema_version'");
        if ($schemaVersion < 3) $errors[] = 'Database schema version 3 is required.';
        if ((int) scalar('SELECT COALESCE(SUM(amount_satang),0) FROM ledger_entries') !== 0) $errors[] = 'The financial ledger is not balanced.';
        if ((int) scalar("SELECT COUNT(*) FROM users JOIN roles ON roles.id=users.role_id WHERE roles.name='admin' AND users.status='active' AND users.admin_mfa_enabled<>1") > 0) {
            $errors[] = 'Every active admin must enable MFA.';
        }
        if (system_setting('demo_mode', '0') === '1') $errors[] = 'Demo mode must be disabled.';
        if (system_setting('maintenance_mode', '0') === '1') $errors[] = 'Maintenance mode is still enabled.';
    } catch (Throwable $error) {
        $errors[] = 'PostgreSQL check failed: ' . $error->getMessage();
    }
}
if ($errors) {
    foreach ($errors as $error) fwrite(STDERR, "[FAIL] $error\n");
    exit(1);
}
echo "Production configuration is ready.\n";
