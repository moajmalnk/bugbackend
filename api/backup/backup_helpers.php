<?php

function backup_table_exists(PDO $conn, string $table): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function backup_ensure_jobs_table(PDO $conn): void
{
    if (!backup_table_exists($conn, 'backup_jobs')) {
        $migration = realpath(__DIR__ . '/../../migrations/015_backup_jobs.sql');
        if ($migration && is_readable($migration)) {
            $sql = file_get_contents($migration);
            if ($sql) {
                $conn->exec($sql);
            }
        }
    }

    backup_ensure_mail_status_columns($conn);
}

function backup_ensure_mail_status_columns(PDO $conn): void
{
    if (!backup_table_exists($conn, 'backup_jobs')) {
        return;
    }

    $stmt = $conn->query("SHOW COLUMNS FROM backup_jobs LIKE 'mail_status'");
    if ($stmt && $stmt->fetch()) {
        return;
    }

    $migration = realpath(__DIR__ . '/../../migrations/016_backup_jobs_mail_status.sql');
    if (!$migration || !is_readable($migration)) {
        return;
    }

    $sql = file_get_contents($migration);
    if ($sql) {
        $conn->exec($sql);
    }
}

function backup_format_bytes(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $power = (int) floor(log($bytes, 1024));
    $power = min($power, count($units) - 1);
    $value = $bytes / (1024 ** $power);

    return round($value, $power > 0 ? 2 : 0) . ' ' . $units[$power];
}

function backup_directory_size(string $path): int
{
    if (!is_dir($path)) {
        return 0;
    }

    $size = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $size += (int) $file->getSize();
        }
    }

    return $size;
}

function backup_count_tables(PDO $conn): int
{
    $stmt = $conn->query('SHOW TABLES');
    return $stmt ? count($stmt->fetchAll(PDO::FETCH_COLUMN)) : 0;
}

function backup_estimate_database_size(PDO $conn): int
{
    $stmt = $conn->query(
        'SELECT COALESCE(SUM(data_length + index_length), 0)
         FROM information_schema.tables
         WHERE table_schema = DATABASE()'
    );

    return (int) ($stmt ? $stmt->fetchColumn() : 0);
}

function backup_resolve_uploads_path(): string
{
    $backendPath = realpath(__DIR__ . '/../..') ?: dirname(dirname(__DIR__));
    $candidates = [
        $backendPath . DIRECTORY_SEPARATOR . 'uploads',
        dirname($backendPath) . DIRECTORY_SEPARATOR . 'uploads',
        $backendPath . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads',
    ];

    foreach ($candidates as $path) {
        if (is_dir($path)) {
            return $path;
        }
    }

    return $backendPath . DIRECTORY_SEPARATOR . 'uploads';
}

function backup_require_settings_permission(BaseAPI $api): object
{
    $decoded = $api->validateToken();
    if (!$decoded || !isset($decoded->user_id)) {
        $api->sendJsonResponse(401, 'Invalid token');
    }

    $permissionManager = PermissionManager::getInstance();
    if (!$permissionManager->hasPermission($decoded->user_id, 'SETTINGS_EDIT')) {
        $api->sendJsonResponse(403, 'You do not have permission to access backups');
    }

    return $decoded;
}

function backup_job_has_mail_status(PDO $conn): bool
{
    if (!backup_table_exists($conn, 'backup_jobs')) {
        return false;
    }

    $stmt = $conn->query("SHOW COLUMNS FROM backup_jobs LIKE 'mail_status'");
    return (bool) ($stmt && $stmt->fetch());
}

/**
 * Mark abandoned processing jobs as failed (worker never finished).
 * Default threshold: 45 minutes.
 */
function backup_reap_stale_jobs(PDO $conn, int $maxAgeMinutes = 45): int
{
    if (!backup_table_exists($conn, 'backup_jobs')) {
        return 0;
    }

    backup_ensure_jobs_table($conn);
    $hasMail = backup_job_has_mail_status($conn);
    $message = 'Backup timed out — the worker did not finish. Please start a new backup.';

    if ($hasMail) {
        $stmt = $conn->prepare(
            "UPDATE backup_jobs
             SET status = 'failed',
                 error_message = ?,
                 mail_status = 'failed',
                 mail_error = ?,
                 completed_at = NOW()
             WHERE status IN ('queued', 'processing')
               AND created_at < (NOW() - INTERVAL ? MINUTE)"
        );
        $stmt->execute([$message, $message, $maxAgeMinutes]);
    } else {
        $stmt = $conn->prepare(
            "UPDATE backup_jobs
             SET status = 'failed',
                 error_message = ?,
                 completed_at = NOW()
             WHERE status IN ('queued', 'processing')
               AND created_at < (NOW() - INTERVAL ? MINUTE)"
        );
        $stmt->execute([$message, $maxAgeMinutes]);
    }

    return (int) $stmt->rowCount();
}

/**
 * Rough ETA in seconds from estimated archive bytes.
 */
function backup_estimate_eta_seconds(int $totalBytes): int
{
    // ~800 KB/s effective for dump+zip+mail, with a floor/ceiling for UX.
    $seconds = (int) ceil($totalBytes / (800 * 1024));
    return max(90, min(900, $seconds > 0 ? $seconds : 180));
}

function backup_format_eta(int $seconds): string
{
    if ($seconds < 60) {
        return 'about 1 minute';
    }
    $minutes = (int) ceil($seconds / 60);
    if ($minutes === 1) {
        return 'about 1 minute';
    }
    return "about {$minutes} minutes";
}
