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

$requirements = [
    'outbox' => 600,
    'reconciliation' => 93600,
    'backup' => 93600,
];
$errors = [];
foreach ($requirements as $job => $maximumAge) {
    $latest = latest_job_run($job);
    if (!$latest || (string) $latest['status'] !== 'ok') {
        $errors[] = "$job has no successful recent run.";
        continue;
    }
    $finishedAt = strtotime((string) $latest['finished_at'] . ' UTC');
    if ($finishedAt === false || time() - $finishedAt > $maximumAge) {
        $errors[] = "$job is stale.";
    }
}
if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, "[FAIL] $error\n");
    }
    exit(1);
}
echo "[PASS] Scheduled jobs are current.\n";
