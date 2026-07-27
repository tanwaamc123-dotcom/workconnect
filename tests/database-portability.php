<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/config.php';
require dirname(__DIR__) . '/includes/database.php';

$source = 'SELECT SUM(status="completed"), strftime("%Y-%m", paid_at), date("now","-7 days") FROM orders WHERE status IN ("pending","review")';
$expected = "SELECT SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END), TO_CHAR(paid_at, 'YYYY-MM'), CURRENT_DATE - INTERVAL '7 days' FROM orders WHERE status IN ('pending','review')";
$actual = database_portable_sql($source, 'pgsql');
if ($actual !== $expected) {
    fwrite(STDERR, "[FAIL] PostgreSQL SQL translation mismatch.\nExpected: $expected\nActual:   $actual\n");
    exit(1);
}

putenv('DATABASE_URL=postgresql://sample%40user:p%40ss@example.com:5432/workconnect?sslmode=verify-full');
$_ENV['DATABASE_URL'] = 'postgresql://sample%40user:p%40ss@example.com:5432/workconnect?sslmode=verify-full';
[$dsn, $username, $password] = database_connection_config();
if ($dsn !== 'pgsql:host=example.com;port=5432;dbname=workconnect;sslmode=verify-full' || $username !== 'sample@user' || $password !== 'p@ss') {
    fwrite(STDERR, "[FAIL] PostgreSQL DATABASE_URL parsing failed.\n");
    exit(1);
}

$_ENV['APP_ENV'] = 'production';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
if (!request_is_https()) {
    fwrite(STDERR, "[FAIL] Production proxy HTTPS detection failed.\n");
    exit(1);
}

echo "[PASS] PostgreSQL URL parsing and SQL compatibility helpers work.\n";
