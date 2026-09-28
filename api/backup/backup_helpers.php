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
    backup_ensure_artifact_columns($conn);
}

function backup_ensure_artifact_columns(PDO $conn): void
{
    if (!backup_table_exists($conn, 'backup_jobs')) {
        return;
    }

    $stmt = $conn->query("SHOW COLUMNS FROM backup_jobs LIKE 'artifacts'");
    if ($stmt && $stmt->fetch()) {
        return;
    }

    $migration = realpath(__DIR__ . '/../../migrations/111_backup_jobs_download_links.sql');
    if (!$migration || !is_readable($migration)) {
        return;
    }

    $sql = file_get_contents($migration);
    if ($sql) {
        $conn->exec($sql);
    }
}

function backup_job_has_artifacts(PDO $conn): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    if (!backup_table_exists($conn, 'backup_jobs')) {
        return $cache = false;
    }
    $stmt = $conn->query("SHOW COLUMNS FROM backup_jobs LIKE 'artifacts'");
    return $cache = (bool) ($stmt && $stmt->fetch());
}

/** Days a finished backup stays downloadable before its files are purged. */
const BACKUP_RETENTION_DAYS = 7;

/** Lifetime of download links rendered in the admin history table. */
const BACKUP_CONSOLE_LINK_TTL = 7200;

/**
 * Private storage for finished archives. Locked down with .htaccess because the
 * backend folder is the web root on Hostinger.
 */
function backup_storage_dir(): string
{
    $backendPath = realpath(__DIR__ . '/../..') ?: dirname(dirname(__DIR__));
    $dir = $backendPath . DIRECTORY_SEPARATOR . 'backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents(
            $htaccess,
            "# Archives are only served through api/backup/download.php (signed links)\n" .
            "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n" .
            "Options -Indexes\n"
        );
    }
    $index = $dir . DIRECTORY_SEPARATOR . 'index.html';
    if (!is_file($index)) {
        @file_put_contents($index, '');
    }

    return $dir;
}

/**
 * Why: Download links must work from an email (no JWT available), so they are
 * HMAC-signed. Prefers BACKUP_DOWNLOAD_SECRET from .env; otherwise a random key is
 * generated once and kept inside the web-denied backups folder.
 */
function backup_signing_secret(): string
{
    if (!class_exists('Environment')) {
        require_once __DIR__ . '/../../config/environment.php';
    }
    $fromEnv = (string) Environment::get('BACKUP_DOWNLOAD_SECRET', '');
    if (strlen($fromEnv) >= 32) {
        return $fromEnv;
    }

    $file = backup_storage_dir() . DIRECTORY_SEPARATOR . '.signing_key';
    if (is_file($file)) {
        $key = trim((string) @file_get_contents($file));
        if (strlen($key) >= 32) {
            return $key;
        }
    }

    $key = bin2hex(random_bytes(32));
    if (@file_put_contents($file, $key, LOCK_EX) === strlen($key)) {
        @chmod($file, 0640);
        return $key;
    }

    // Unwritable storage: fall back to a stable server-side derivation so links stay
    // valid across requests (a per-request random key would break every link).
    return hash_hmac(
        'sha256',
        'bugricer-backup-links|' . __DIR__,
        (string) Environment::get('SMTP_PASS', '') . (string) Environment::get('ASSETS_VAULT_KEK', '')
    );
}

function backup_sign(int $jobId, string $type, int $expires): string
{
    return hash_hmac('sha256', $jobId . '|' . $type . '|' . $expires, backup_signing_secret());
}

function backup_verify_signature(int $jobId, string $type, int $expires, string $signature): bool
{
    if ($expires < time() || $signature === '') {
        return false;
    }
    return hash_equals(backup_sign($jobId, $type, $expires), $signature);
}

/** Public origin of the backend (e.g. https://bugbackend.bugricer.com), captured per request. */
function backup_detect_download_base(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/api/backup/create.php')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $scriptDir;
}

function backup_download_url(string $base, int $jobId, string $type, int $expires): string
{
    return rtrim($base, '/') . '/download.php?' . http_build_query([
        'job' => $jobId,
        'type' => $type,
        'exp' => $expires,
        'sig' => backup_sign($jobId, $type, $expires),
    ]);
}

/**
 * @return array<int, array{type:string,file:string,size_bytes:int,sha256?:string}>
 */
function backup_decode_artifacts($raw): array
{
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
}

function backup_artifact_label(string $type): string
{
    return $type === 'database' ? 'Database backup' : 'Files backup';
}

/**
 * Deletes archive files of expired jobs so storage stays bounded.
 */
function backup_purge_expired_artifacts(PDO $conn): int
{
    if (!backup_job_has_artifacts($conn)) {
        return 0;
    }

    $stmt = $conn->query(
        "SELECT id, artifacts FROM backup_jobs
         WHERE artifacts IS NOT NULL AND artifacts <> '[]'
           AND expires_at IS NOT NULL AND expires_at < NOW()
         ORDER BY created_at DESC"
    );
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    if (!$rows) {
        return 0;
    }

    $dir = backup_storage_dir();
    $update = $conn->prepare("UPDATE backup_jobs SET artifacts = '[]' WHERE id = ?");
    foreach ($rows as $row) {
        foreach (backup_decode_artifacts($row['artifacts']) as $artifact) {
            $path = $dir . DIRECTORY_SEPARATOR . basename((string) ($artifact['file'] ?? ''));
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $update->execute([(int) $row['id']]);
    }

    return count($rows);
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
    $legacyRole = $decoded->role ?? null;
    if (!$permissionManager->hasPermissionOrAdmin($decoded->user_id, 'BACKUP_MANAGE', $legacyRole)
        && !$permissionManager->hasPermissionOrAdmin($decoded->user_id, 'SETTINGS_EDIT', $legacyRole)) {
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
 * Default threshold: 120 minutes (multi-GB uploads can legitimately take a while).
 */
function backup_reap_stale_jobs(PDO $conn, int $maxAgeMinutes = 120): int
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
    // ~6 MB/s effective: gzip-streamed SQL plus a ZIP that stores already-compressed
    // media without recompressing; no mail attachment upload anymore.
    $seconds = (int) ceil($totalBytes / (6 * 1024 * 1024));
    return max(60, min(1800, $seconds > 0 ? $seconds : 120));
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
