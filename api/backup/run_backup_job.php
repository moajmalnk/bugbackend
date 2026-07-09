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
require_once __DIR__ . '/../../config/database.php';

// BackupController lives in create.php; load class without HTTP handler
require_once __DIR__ . '/create.php';

try {
    $controller = new BackupController();
    $controller->processJob($jobId);
    exit(0);
} catch (Throwable $e) {
    error_log("Backup worker fatal (job $jobId): " . $e->getMessage());
    try {
        $database = Database::getInstance();
        $conn = $database->getConnection();
        if ($conn && backup_table_exists($conn, 'backup_jobs')) {
            backup_ensure_jobs_table($conn);
            $stmt = $conn->prepare(
                "UPDATE backup_jobs
                 SET status = 'failed',
                     error_message = ?,
                     mail_status = 'failed',
                     mail_error = ?,
                     completed_at = NOW()
                 WHERE id = ? AND status = 'processing'"
            );
            $message = mb_substr($e->getMessage(), 0, 1000);
            $stmt->execute([$message, $message, $jobId]);
        }
    } catch (Throwable $updateError) {
        error_log("Backup worker status update failed (job $jobId): " . $updateError->getMessage());
    }
    exit(1);
}
