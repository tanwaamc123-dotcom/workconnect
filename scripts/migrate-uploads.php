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
if (storage_driver() !== 's3' || !object_storage_is_configured()) {
    fwrite(STDERR, "[FAIL] Configure STORAGE_DRIVER=s3 and all S3_* values first.\n");
    exit(1);
}

$apply = in_array('--apply', $argv, true);
$locations = [
    ['users', 'avatar'],
    ['users', 'id_card_front'],
    ['users', 'id_card_back'],
    ['services', 'thumbnail'],
    ['messages', 'attachment'],
    ['wallet_transactions', 'slip_path'],
    ['order_deliveries', 'attachment'],
    ['dispute_evidence', 'attachment'],
];
$migrated = 0;
$missing = 0;

foreach ($locations as [$table, $column]) {
    $rows = fetch_all(
        "SELECT DISTINCT $column AS path FROM $table
         WHERE $column LIKE 'storage/private/uploads/%' OR $column LIKE 'assets/uploads/%'"
    );
    foreach ($rows as $row) {
        $path = (string) $row['path'];
        $local = upload_local_path($path);
        if ($local === null || !is_file($local)) {
            fwrite(STDERR, "[WARN] Missing local file: $path\n");
            $missing++;
            continue;
        }
        $key = 'uploads/migrated/' . hash('sha256', $path) . '-' . basename($path);
        $reference = 'object:' . $key;
        echo sprintf("[%s] %s.%s %s -> %s\n", $apply ? 'MOVE' : 'PLAN', $table, $column, $path, $reference);
        if (!$apply) {
            continue;
        }
        $body = file_get_contents($local);
        if (!is_string($body)) {
            throw new RuntimeException('Unable to read ' . $path);
        }
        object_storage_put($key, $body, mime_content_type($local) ?: 'application/octet-stream');
        db()->prepare("UPDATE $table SET $column=? WHERE $column=?")->execute([$reference, $path]);
        $migrated++;
    }
}

echo sprintf("[SUMMARY] mode=%s migrated=%d missing=%d\n", $apply ? 'apply' : 'dry-run', $migrated, $missing);
if (!$apply) {
    echo "Run again with --apply after reviewing this plan.\n";
}
