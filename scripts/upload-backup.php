<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/config.php';
require dirname(__DIR__) . '/includes/helpers.php';
require dirname(__DIR__) . '/includes/platform.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$files = array_slice($argv, 1);
if ($files === [] || !object_storage_is_configured()) {
    fwrite(STDERR, "[FAIL] Usage: php scripts/upload-backup.php backup-file [checksum-file]\n");
    exit(1);
}

$backupRoot = realpath(dirname(__DIR__) . '/storage/private/backups');
if ($backupRoot === false) {
    fwrite(STDERR, "[FAIL] Backup directory is unavailable.\n");
    exit(1);
}

try {
    foreach ($files as $file) {
        $path = realpath($file);
        if ($path === false || !is_file($path) || !str_starts_with($path, $backupRoot . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Backup source is invalid.');
        }
        $body = file_get_contents($path);
        if (!is_string($body)) {
            throw new RuntimeException('Backup source could not be read.');
        }
        $name = basename($path);
        $contentType = str_ends_with($name, '.sha256') ? 'text/plain; charset=utf-8' : 'application/octet-stream';
        object_storage_put('backups/' . $name, $body, $contentType);
        echo "[PASS] Uploaded backups/$name\n";
    }
} catch (Throwable $error) {
    app_log('error', 'Off-site backup upload failed.', ['type' => $error::class]);
    fwrite(STDERR, "[FAIL] Off-site backup upload failed.\n");
    exit(1);
}
