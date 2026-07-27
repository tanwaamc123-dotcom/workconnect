<?php

declare(strict_types=1);

function load_env_file(string $path): void
{
    static $loaded = [];
    if (isset($loaded[$path]) || !is_file($path) || !is_readable($path)) {
        $loaded[$path] = true;
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        $loaded[$path] = true;
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
        $name = trim($name);
        if ($name === '') {
            continue;
        }
        $value = trim($value);
        if (($value[0] ?? '') === '"' && str_ends_with($value, '"')) {
            $value = stripcslashes(substr($value, 1, -1));
        } elseif (($value[0] ?? '') === "'" && str_ends_with($value, "'")) {
            $value = substr($value, 1, -1);
        }
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv($name . '=' . $value);
    }
    $loaded[$path] = true;
}

load_env_file(dirname(__DIR__) . '/.env');
date_default_timezone_set('UTC');

function app_environment(): string
{
    return strtolower((string) env_value('APP_ENV', 'local'));
}

function app_is_production(): bool
{
    return app_environment() === 'production';
}

function request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (!app_is_production()) {
        return false;
    }
    $forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    return $forwardedProto === 'https';
}

function app_encryption_key(): string
{
    $encoded = trim((string) env_value('APP_ENCRYPTION_KEY', ''));
    $decoded = base64_decode($encoded, true);
    return is_string($decoded) && strlen($decoded) === 32 ? $decoded : '';
}

function encrypt_sensitive(string $value): string
{
    if ($value === '') return '';
    $key = app_encryption_key();
    if ($key === '') throw new RuntimeException('APP_ENCRYPTION_KEY is not configured.');
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ciphertext === false) throw new RuntimeException('Unable to encrypt sensitive data.');
    return 'enc:v1:' . base64_encode($iv . $tag . $ciphertext);
}

function decrypt_sensitive(string $value): string
{
    if (!str_starts_with($value, 'enc:v1:')) return $value;
    $key = app_encryption_key();
    $payload = base64_decode(substr($value, 7), true);
    if ($key === '' || !is_string($payload) || strlen($payload) < 29) return '';
    $plain = openssl_decrypt(substr($payload, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($payload, 0, 12), substr($payload, 12, 16));
    return is_string($plain) ? $plain : '';
}

function env_value(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return (string) $value;
}

function app_base_url(): string
{
    $configured = rtrim((string) env_value('APP_URL', ''), '/');
    if ($configured !== '') {
        return $configured;
    }
    $https = request_is_https();
    $scheme = $https ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1'));
    if (!preg_match('/^[a-z0-9.\-:\[\]]+$/i', $host)) {
        $host = '127.0.0.1';
    }
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $basePath = rtrim(dirname($scriptName), '/');
    return $scheme . '://' . $host . ($basePath === '' ? '' : $basePath);
}

function stripe_secret_key(): string
{
    return trim((string) env_value('STRIPE_SECRET_KEY', ''));
}

function stripe_publishable_key(): string
{
    return trim((string) env_value('STRIPE_PUBLISHABLE_KEY', ''));
}

function stripe_webhook_secret(): string
{
    return trim((string) env_value('STRIPE_WEBHOOK_SECRET', ''));
}

function stripe_is_configured(): bool
{
    $key = stripe_secret_key();
    return str_starts_with($key, 'sk_test_') || (app_is_production() && str_starts_with($key, 'sk_live_'));
}

function stripe_webhook_is_configured(): bool
{
    return stripe_webhook_secret() !== '';
}

function stripe_checkout_is_configured(): bool
{
    $publishableKey = stripe_publishable_key();
    $publishableKeyValid = str_starts_with($publishableKey, 'pk_test_')
        || (app_is_production() && str_starts_with($publishableKey, 'pk_live_'));
    return stripe_is_configured() && $publishableKeyValid && str_starts_with(stripe_webhook_secret(), 'whsec_');
}
