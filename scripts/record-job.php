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

$options = getopt('', ['job:', 'status:', 'detail::']);
$job = trim((string) ($options['job'] ?? ''));
$status = trim((string) ($options['status'] ?? ''));
$detail = trim((string) ($options['detail'] ?? ''));

try {
    record_job_run($job, $status, $detail);
    echo "[PASS] Recorded $job job run.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "[FAIL] Unable to record job run.\n");
    exit(1);
}
