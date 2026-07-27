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

$limit = max(1, min(100, (int) ($argv[1] ?? 25)));
try {
    $result = process_outbox_batch($limit);
    $status = $result['failed'] > 0 ? 'failed' : 'ok';
    $detail = sprintf('sent=%d failed=%d selected=%d', $result['sent'], $result['failed'], $result['selected']);
    record_job_run('outbox', $status, $detail);
    echo '[OUTBOX] ' . $detail . PHP_EOL;
    exit($status === 'ok' ? 0 : 1);
} catch (Throwable $error) {
    app_log('error', 'Outbox job crashed.', ['type' => $error::class]);
    try {
        record_job_run('outbox', 'failed', $error->getMessage());
    } catch (Throwable) {
        // The original failure is still reported to the scheduler below.
    }
    fwrite(STDERR, "[FAIL] Outbox job failed.\n");
    exit(1);
}
