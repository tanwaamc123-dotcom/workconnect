<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/config.php';
require dirname(__DIR__) . '/includes/database.php';
require dirname(__DIR__) . '/includes/helpers.php';
require dirname(__DIR__) . '/includes/platform.php';

$connection = db();
$table = (string) $connection->query("SELECT name FROM sqlite_master WHERE type='table' AND name='job_runs'")->fetchColumn();
if ($table !== 'job_runs') {
    throw new RuntimeException('The job_runs table was not migrated.');
}
record_job_run('operations-test', 'ok', 'migration regression check');
$latest = latest_job_run('operations-test');
if (!$latest || (string) $latest['status'] !== 'ok') {
    throw new RuntimeException('The scheduled job record could not be written.');
}
echo "[PASS] Scheduled job schema and recording work.\n";
