<?php
/**
 * CLI worker: processes a backup_jobs row by ID.
 * Usage: php run_backup_job.php <job_id>
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

date_default_timezone_set('Asia/Kolkata');
error_reporting(E_ALL);
ini_set('display_errors', '0');
set_time_limit(0);

$jobId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($jobId < 1) {
    fwrite(STDERR, "Usage: php run_backup_job.php <job_id>\n");
    exit(1);
}

require_once __DIR__ . '/../../config/composer_autoload.php';
require_once __DIR__ . '/backup_helpers.php';

// BackupController lives in create.php; load class without HTTP handler
require_once __DIR__ . '/create.php';

try {
    $controller = new BackupController();
    $controller->processJob($jobId);
    exit(0);
} catch (Throwable $e) {
    error_log("Backup worker fatal (job $jobId): " . $e->getMessage());
    exit(1);
}
