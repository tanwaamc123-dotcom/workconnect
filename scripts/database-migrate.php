<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/config.php';
require dirname(__DIR__) . '/includes/database.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$importSqlite = in_array('--import-sqlite', $argv, true);
[$dsn, $username, $password] = database_connection_config();
if (!str_starts_with($dsn, 'pgsql:')) {
    fwrite(STDERR, "[FAIL] Set DATABASE_URL to the target PostgreSQL database first.\n");
    exit(1);
}

$target = new PDO($dsn, $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$schemaPath = dirname(__DIR__) . '/database/postgresql-schema.sql';
$schema = file_get_contents($schemaPath);
if ($schema === false) {
    throw new RuntimeException('Unable to read the PostgreSQL schema.');
}
$target->exec($schema);
echo "[PASS] PostgreSQL schema version 1 is ready.\n";

if (!$importSqlite) {
    echo "Run again with --import-sqlite to transfer the current SQLite data.\n";
    exit(0);
}

$sourcePath = dirname(__DIR__) . '/storage/workconnect.sqlite';
if (!is_file($sourcePath)) {
    throw new RuntimeException('SQLite source database was not found.');
}
$source = new PDO('sqlite:' . $sourcePath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$source->exec('PRAGMA foreign_keys = ON');
if ($source->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
    throw new RuntimeException('SQLite integrity check failed. The import was cancelled.');
}
if ((int) $target->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
    throw new RuntimeException('Target PostgreSQL already contains users. Import into an empty target to avoid overwriting data.');
}

$tables = [
    'roles',
    'users',
    'sessions',
    'security_logs',
    'rate_limits',
    'password_reset_tokens',
    'categories',
    'services',
    'order_status',
    'coupons',
    'orders',
    'messages',
    'payments',
    'wallet_transactions',
    'notifications',
    'reviews',
    'favorites',
    'system_settings',
    'newsletter_subscribers',
    'schema_meta',
    'payment_requests',
];
$serialTables = [
    'roles',
    'users',
    'sessions',
    'security_logs',
    'password_reset_tokens',
    'categories',
    'services',
    'order_status',
    'coupons',
    'orders',
    'messages',
    'payments',
    'wallet_transactions',
    'notifications',
    'reviews',
    'favorites',
    'newsletter_subscribers',
    'payment_requests',
];

$target->beginTransaction();
try {
    // The schema seeds reference rows for a fresh app; the SQLite import is authoritative.
    $target->exec('DELETE FROM system_settings');
    $target->exec('DELETE FROM order_status');
    $target->exec('DELETE FROM categories');
    $target->exec('DELETE FROM roles');

    foreach ($tables as $table) {
        $sourceColumns = array_column($source->query("PRAGMA table_info($table)")->fetchAll(), 'name');
        $targetColumnStmt = $target->prepare(
            "SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name=? ORDER BY ordinal_position"
        );
        $targetColumnStmt->execute([$table]);
        $columns = array_values(array_intersect(array_column($targetColumnStmt->fetchAll(), 'column_name'), $sourceColumns));
        if ($columns === []) {
            throw new RuntimeException("No compatible columns found for $table.");
        }

        $rows = $source->query('SELECT ' . implode(',', $columns) . " FROM $table")->fetchAll();
        if ($rows !== []) {
            $placeholders = implode(',', array_fill(0, count($columns), '?'));
            $conflict = $table === 'schema_meta'
                ? ' ON CONFLICT (meta_key) DO UPDATE SET meta_value=EXCLUDED.meta_value'
                : '';
            $insert = $target->prepare(
                "INSERT INTO $table (" . implode(',', $columns) . ") VALUES ($placeholders)$conflict"
            );
            foreach ($rows as $row) {
                $insert->execute(array_values($row));
            }
        }
        echo sprintf("[PASS] %-24s %d rows\n", $table, count($rows));
    }

    foreach ($serialTables as $table) {
        $target->exec(
            "SELECT setval(pg_get_serial_sequence('$table','id'), COALESCE((SELECT MAX(id) FROM $table), 1), (SELECT COUNT(*) > 0 FROM $table))"
        );
    }
    $target->prepare(
        "INSERT INTO schema_meta (meta_key,meta_value) VALUES ('schema_version','1')
         ON CONFLICT (meta_key) DO UPDATE SET meta_value=EXCLUDED.meta_value"
    )->execute();
    $target->commit();
} catch (Throwable $error) {
    if ($target->inTransaction()) {
        $target->rollBack();
    }
    throw $error;
}

foreach ($tables as $table) {
    $sourceCount = (int) $source->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    $targetCount = (int) $target->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    if ($table !== 'schema_meta' && $sourceCount !== $targetCount) {
        throw new RuntimeException("Verification failed for $table: SQLite=$sourceCount PostgreSQL=$targetCount");
    }
}

echo "[PASS] SQLite data was transferred and row counts were verified.\n";
