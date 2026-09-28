<?php
/**
 * BugRicer Platform Backup API
 *
 * Builds a database archive (.sql.gz) and a files archive (.zip) on the server,
 * then emails the recipient signed, expiring download links — multi-GB archives
 * cannot travel as SMTP attachments.
 */

// Handle CORS FIRST - before any output
require_once __DIR__ . '/../../config/cors.php';

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../../config/composer_autoload.php';
require_once __DIR__ . '/backup_helpers.php';

class BackupController {
    /** Rows per INSERT statement; keeps each statement under default max_allowed_packet on restore. */
    private const INSERT_BATCH_ROWS = 500;

    /** Already-compressed formats are stored as-is in the ZIP; recompressing them only burns CPU. */
    private const STORE_ONLY_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'mp4', 'mov', 'webm', 'mkv', 'avi',
        'mp3', 'm4a', 'aac', 'ogg', 'opus', 'wav', 'zip', 'gz', 'tgz', 'rar', '7z', 'pdf',
        'docx', 'xlsx', 'pptx', 'woff', 'woff2',
    ];

    protected $conn;
    protected $utils;
    protected $database;
    private $storageDir;
    private $jobId = null;
    private $jobStartedAt = null;
    private $downloadBase = null;
    private $lastStage = null;
    private $lastPercent = -1;
    private $lastProgressWrite = 0.0;

    public function __construct() {
        if (!class_exists('Database')) {
            require_once __DIR__ . '/../../config/database.php';
        }
        if (!class_exists('Utils')) {
            require_once __DIR__ . '/../../config/utils.php';
        }
        if (!function_exists('sendEmail')) {
            require_once __DIR__ . '/../../utils/email.php';
        }
        if (!class_exists('PermissionManager')) {
            require_once __DIR__ . '/../PermissionManager.php';
        }

        $this->database = Database::getInstance();
        $this->conn = $this->database->getConnection();
        $this->utils = new Utils();

        if (!$this->conn) {
            throw new \RuntimeException('Database connection failed');
        }

        $this->storageDir = backup_storage_dir();
    }

    public function handleRequest() {
        try {
            header('Content-Type: application/json');
            header('Cache-Control: no-store');

            $token = $this->getBearerToken();
            if (!$token) {
                $this->sendErrorResponse(401, "No token provided");
            }

            $tokenData = $this->utils->validateJWT($token);
            if (!$tokenData) {
                $this->sendErrorResponse(401, "Invalid token");
            }

            $userId = $tokenData->user_id ?? null;

            $permissionManager = PermissionManager::getInstance();
            $legacyRole = $tokenData->role ?? null;
            if (!$permissionManager->hasPermissionOrAdmin($userId, 'BACKUP_MANAGE', $legacyRole)
                && !$permissionManager->hasPermissionOrAdmin($userId, 'SETTINGS_EDIT', $legacyRole)) {
                $this->sendErrorResponse(403, "You do not have permission to create backups");
            }

            $data = $this->getRequestData();
            $email = trim((string) ($data['email'] ?? ''));
            $includeDatabase = array_key_exists('include_database', $data) ? (bool) $data['include_database'] : true;
            $includeUploads = array_key_exists('include_uploads', $data) ? (bool) $data['include_uploads'] : true;
            $includeConfig = array_key_exists('include_config', $data) ? (bool) $data['include_config'] : true;
            $deliveryMethod = 'email_link';

            if ($email === '' || strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->sendErrorResponse(400, "Valid email address is required");
            }

            if (!$includeDatabase && !$includeUploads && !$includeConfig) {
                $this->sendErrorResponse(400, "Select at least one backup component");
            }

            backup_ensure_jobs_table($this->conn);
            backup_reap_stale_jobs($this->conn);

            if ($this->hasActiveJob()) {
                $this->sendErrorResponse(409, "A backup is already being prepared. Please wait for it to finish.");
            }

            $this->downloadBase = backup_detect_download_base();
            $this->jobId = $this->createJobRecord(
                $userId,
                $email,
                $deliveryMethod,
                $includeDatabase,
                $includeUploads,
                $includeConfig
            );

            http_response_code(200);
            ob_start();
            echo json_encode([
                'success' => true,
                'message' => 'Backup started. A secure download link will be emailed when it is ready.',
                'data' => [
                    'email' => $email,
                    'status' => 'processing',
                    'job_id' => $this->jobId,
                    'include_database' => $includeDatabase,
                    'include_uploads' => $includeUploads,
                    'include_config' => $includeConfig,
                    'delivery_method' => $deliveryMethod,
                ]
            ]);

            // Close the HTTP response before the (long) backup runs, otherwise the
            // gateway waits for the whole job and returns 504.
            header('Content-Length: ' . ob_get_length());
            header('Connection: close');
            while (ob_get_level()) {
                ob_end_flush();
            }
            flush();

            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                // Hostinger runs LiteSpeed (lsphp), which has no fastcgi_finish_request
                litespeed_finish_request();
            }

            // Prefer a detached CLI worker; if spawn fails or the worker dies
            // immediately, finish the job inline (response is already closed).
            ignore_user_abort(true);
            set_time_limit(0);

            if ($this->jobId) {
                $spawn = $this->spawnBackgroundJob($this->jobId);
                $shouldRunInline = !$spawn['ok'];

                if ($spawn['ok'] && $spawn['pid'] > 0) {
                    usleep(900000); // ~0.9s — let worker start or crash
                    if ($this->isJobStillProcessing($this->jobId) && !$this->isProcessAlive($spawn['pid'])) {
                        error_log("⚠️ Backup worker pid {$spawn['pid']} exited early for job {$this->jobId}; running inline");
                        $shouldRunInline = true;
                    }
                }

                if ($shouldRunInline && $this->isJobStillProcessing($this->jobId)) {
                    error_log("▶️ Running backup inline for job {$this->jobId}");
                    $this->createBackup($email, [
                        'include_database' => $includeDatabase,
                        'include_uploads' => $includeUploads,
                        'include_config' => $includeConfig,
                    ]);
                }
            }

            exit(0);

        } catch (Throwable $e) {
            error_log("Backup API error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            $this->sendErrorResponse(500, "Backup failed: " . $e->getMessage());
        }
    }

    private function getBearerToken() {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if ($headers && isset($headers['Authorization'])
                && preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
                return $matches[1];
            }
        }
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (isset($_SERVER[$key]) && preg_match('/Bearer\s+(.*)$/i', $_SERVER[$key], $matches)) {
                return $matches[1];
            }
        }
        return null;
    }

    private function getRequestData() {
        $contentType = isset($_SERVER["CONTENT_TYPE"]) ? trim($_SERVER["CONTENT_TYPE"]) : '';

        if (stripos($contentType, 'application/json') !== false) {
            $content = file_get_contents("php://input");
            if ($content === false || empty(trim($content))) {
                return [];
            }
            $data = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException("Invalid JSON: " . json_last_error_msg());
            }
            return is_array($data) ? $data : [];
        }

        return $_POST;
    }

    private function sendErrorResponse($statusCode, $message) {
        http_response_code($statusCode);
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode([
            'success' => false,
            'message' => $message
        ]);
        exit();
    }

    private function hasActiveJob(): bool
    {
        if (!backup_table_exists($this->conn, 'backup_jobs')) {
            return false;
        }
        $stmt = $this->conn->query("SELECT COUNT(*) FROM backup_jobs WHERE status IN ('queued', 'processing')");
        return (int) ($stmt ? $stmt->fetchColumn() : 0) > 0;
    }

    private function createJobRecord(
        $userId,
        $email,
        $deliveryMethod,
        $includeDatabase,
        $includeUploads,
        $includeConfig
    ) {
        if (!backup_table_exists($this->conn, 'backup_jobs')) {
            return null;
        }

        $stmt = $this->conn->prepare(
            'INSERT INTO backup_jobs
            (user_id, email, status, delivery_method, include_database, include_uploads, include_config, started_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $userId,
            $email,
            'processing',
            $deliveryMethod,
            $includeDatabase ? 1 : 0,
            $includeUploads ? 1 : 0,
            $includeConfig ? 1 : 0,
        ]);
        $jobId = (int) $this->conn->lastInsertId();

        if (backup_job_has_artifacts($this->conn)) {
            $this->conn->prepare(
                "UPDATE backup_jobs SET stage = 'queued', progress_percent = 2, download_base = ? WHERE id = ?"
            )->execute([$this->downloadBase, $jobId]);
        }

        return $jobId;
    }

    private function updateJobStatus($status, array $extra = [])
    {
        if (!$this->jobId || !backup_table_exists($this->conn, 'backup_jobs')) {
            return;
        }

        $fields = ['status = ?'];
        $values = [$status];

        $allowed = ['backup_name', 'file_size_bytes', 'table_count', 'duration_seconds', 'error_message', 'mail_status', 'mail_error'];
        if (backup_job_has_artifacts($this->conn)) {
            $allowed = array_merge($allowed, ['stage', 'progress_percent', 'artifacts', 'expires_at']);
        }
        foreach ($allowed as $key) {
            if (array_key_exists($key, $extra)) {
                $fields[] = "$key = ?";
                $values[] = $extra[$key];
            }
        }

        if ($status === 'completed' || $status === 'failed') {
            $fields[] = 'completed_at = NOW()';
        }

        $values[] = $this->jobId;
        $stmt = $this->conn->prepare('UPDATE backup_jobs SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($values);
    }

    /**
     * Why: The console polls these columns to show real progress instead of a
     * timer guess. Throttled to one write per ~2s per stage to keep DB load low.
     */
    private function setProgress(string $stage, float $percent): void
    {
        if (!$this->jobId || !backup_job_has_artifacts($this->conn)) {
            return;
        }
        $percent = (int) max(0, min(100, round($percent)));
        $now = microtime(true);
        if ($stage === $this->lastStage) {
            if ($percent <= $this->lastPercent) {
                return;
            }
            if ($percent < 100 && ($now - $this->lastProgressWrite) < 2.0) {
                return;
            }
        }

        try {
            $this->conn->prepare('UPDATE backup_jobs SET stage = ?, progress_percent = ? WHERE id = ?')
                ->execute([$stage, $percent, $this->jobId]);
            $this->lastStage = $stage;
            $this->lastPercent = $percent;
            $this->lastProgressWrite = $now;
        } catch (Throwable $e) {
            error_log('Backup progress update failed: ' . $e->getMessage());
        }
    }

    /**
     * CLI worker entry: load job row and run backup.
     */
    public function processJob(int $jobId): void
    {
        backup_ensure_jobs_table($this->conn);

        if (!backup_table_exists($this->conn, 'backup_jobs')) {
            throw new \RuntimeException('backup_jobs table is not available');
        }

        $extraCols = backup_job_has_artifacts($this->conn) ? ', download_base' : '';
        $stmt = $this->conn->prepare(
            "SELECT id, email, include_database, include_uploads, include_config, status{$extraCols}
             FROM backup_jobs WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$job) {
            throw new \RuntimeException("Backup job not found: $jobId");
        }

        if ($job['status'] !== 'processing' && $job['status'] !== 'queued') {
            error_log("Backup job $jobId is {$job['status']}; skipping");
            return;
        }

        $this->jobId = (int) $job['id'];
        $this->downloadBase = $job['download_base'] ?? null;
        $this->createBackup($job['email'], [
            'include_database' => (bool) $job['include_database'],
            'include_uploads' => (bool) $job['include_uploads'],
            'include_config' => (bool) $job['include_config'],
        ]);
    }

    private function spawnBackgroundJob(int $jobId): array
    {
        $worker = __DIR__ . '/run_backup_job.php';
        if (!is_file($worker) || !function_exists('exec')) {
            return ['ok' => false, 'pid' => 0];
        }

        $logDir = realpath(__DIR__ . '/../..') . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $logFile = $logDir . '/backup_worker_' . $jobId . '.log';

        // Prefer known CLI binaries — the web SAPI's PHP_BINARY is often lsphp/httpd, not a CLI.
        $candidates = [
            '/usr/bin/php',
            '/usr/local/bin/php',
            '/opt/alt/php82/usr/bin/php',
            '/opt/alt/php83/usr/bin/php',
            '/Applications/XAMPP/xamppfiles/bin/php',
            '/opt/lampp/bin/php',
        ];
        if (defined('PHP_BINARY') && PHP_BINARY && is_file(PHP_BINARY) && stripos(basename(PHP_BINARY), 'lsphp') === false) {
            array_unshift($candidates, PHP_BINARY);
        }

        $phpBinary = null;
        foreach ($candidates as $candidate) {
            if (@is_file($candidate) && @is_executable($candidate)) {
                $phpBinary = $candidate;
                break;
            }
        }
        if (!$phpBinary) {
            error_log('No PHP CLI binary found for backup worker');
            return ['ok' => false, 'pid' => 0];
        }

        $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($worker) . ' ' . (int) $jobId;
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            pclose(popen('start /B ' . $cmd, 'r'));
            return ['ok' => true, 'pid' => 1];
        }

        $output = [];
        $exitCode = 0;
        @exec($cmd . ' >> ' . escapeshellarg($logFile) . ' 2>&1 & echo $!', $output, $exitCode);
        $pid = isset($output[0]) ? (int) $output[0] : 0;
        if ($exitCode !== 0 || $pid <= 0) {
            error_log("❌ Failed to spawn backup worker for job $jobId (exit=$exitCode, php=$phpBinary)");
            return ['ok' => false, 'pid' => 0];
        }
        error_log("🚀 Spawned backup worker for job $jobId with pid $pid via $phpBinary");
        return ['ok' => true, 'pid' => $pid];
    }

    private function isJobStillProcessing(int $jobId): bool
    {
        try {
            $stmt = $this->conn->prepare("SELECT status FROM backup_jobs WHERE id = ? LIMIT 1");
            $stmt->execute([$jobId]);
            return in_array($stmt->fetchColumn(), ['queued', 'processing'], true);
        } catch (Throwable $e) {
            error_log('isJobStillProcessing failed: ' . $e->getMessage());
            return true;
        }
    }

    private function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return true;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }
        $out = [];
        @exec('ps -p ' . (int) $pid . ' -o pid=', $out);
        return !empty($out);
    }

    /**
     * Progress bands per stage, sized by which archives this job builds.
     *
     * @return array{database: array{0: float, 1: float}, files: array{0: float, 1: float}}
     */
    private function progressRanges(bool $hasDatabase, bool $hasFiles): array
    {
        if ($hasDatabase && $hasFiles) {
            return ['database' => [5, 30], 'files' => [30, 90]];
        }
        return ['database' => [5, 90], 'files' => [5, 90]];
    }

    private function createBackup($email, array $options = []) {
        $includeDatabase = (bool) ($options['include_database'] ?? true);
        $includeUploads = (bool) ($options['include_uploads'] ?? true);
        $includeConfig = (bool) ($options['include_config'] ?? true);
        $includeFiles = $includeUploads || $includeConfig;
        $this->jobStartedAt = microtime(true);

        $backupName = 'bugricer_backup_' . date('Y-m-d_H-i-s') . '_job' . (int) $this->jobId;
        $dbPath = $this->storageDir . DIRECTORY_SEPARATOR . $backupName . '_database.sql.gz';
        $filesPath = $this->storageDir . DIRECTORY_SEPARATOR . $backupName . '_files.zip';
        $artifacts = [];
        $tableCount = 0;

        try {
            error_log("🔄 Starting backup job {$this->jobId} for: $email");
            backup_purge_expired_artifacts($this->conn);

            if (!is_dir($this->storageDir) || !is_writable($this->storageDir)) {
                throw new \RuntimeException("Backup storage is not writable: {$this->storageDir}");
            }

            $ranges = $this->progressRanges($includeDatabase, $includeFiles);

            if ($includeDatabase) {
                $this->setProgress('database', $ranges['database'][0]);
                $tableCount = $this->createDatabaseArchive($dbPath, $ranges['database']);
                $artifacts[] = $this->describeArtifact('database', $dbPath);
            }

            if ($includeFiles) {
                $this->setProgress('files', $ranges['files'][0]);
                $this->createFilesArchive($filesPath, $options, $tableCount, $ranges['files']);
                $artifacts[] = $this->describeArtifact('files', $filesPath);
            }

            $this->setProgress('finalizing', 92);
            $totalSize = array_sum(array_map(static fn($a) => (int) $a['size_bytes'], $artifacts));
            $expiresAt = time() + BACKUP_RETENTION_DAYS * 86400;
            $duration = (int) max(1, round(microtime(true) - $this->jobStartedAt));

            $this->updateJobStatus('processing', [
                'backup_name' => $backupName,
                'file_size_bytes' => $totalSize,
                'table_count' => $tableCount,
                'artifacts' => json_encode($artifacts, JSON_UNESCAPED_SLASHES),
                'expires_at' => date('Y-m-d H:i:s', $expiresAt),
            ]);

            // The archives are safe on disk now; a mail failure must not discard them.
            $this->setProgress('emailing', 95);
            $mailStatus = 'sent';
            $mailError = null;
            try {
                if (!$this->sendBackupReadyEmail($email, $backupName, $artifacts, $expiresAt, $tableCount, $duration)) {
                    $mailStatus = 'failed';
                    $mailError = 'Email could not be sent. Check SMTP settings; the backup is still downloadable from the console.';
                }
            } catch (Throwable $mailException) {
                $mailStatus = 'failed';
                $mailError = mb_substr($mailException->getMessage(), 0, 1000);
            }

            $this->updateJobStatus('completed', [
                'duration_seconds' => $duration,
                'mail_status' => $mailStatus,
                'mail_error' => $mailError,
                'stage' => 'completed',
                'progress_percent' => 100,
            ]);

            error_log("✅ Backup job {$this->jobId} completed (" . backup_format_bytes($totalSize) . ", {$duration}s, mail: $mailStatus)");

        } catch (Throwable $e) {
            error_log("❌ Backup job {$this->jobId} failed: " . $e->getMessage());

            foreach ([$dbPath, $filesPath] as $partial) {
                if (is_file($partial)) {
                    @unlink($partial);
                }
            }

            $duration = $this->jobStartedAt
                ? (int) max(1, round(microtime(true) - $this->jobStartedAt))
                : null;

            $mailStatus = 'failed';
            $mailError = null;
            try {
                if ($this->sendErrorEmail($email, $e->getMessage())) {
                    $mailStatus = 'error_sent';
                }
            } catch (Throwable $emailError) {
                $mailError = $emailError->getMessage();
            }

            try {
                $this->updateJobStatus('failed', [
                    'error_message' => mb_substr($e->getMessage(), 0, 1000),
                    'duration_seconds' => $duration,
                    'mail_status' => $mailStatus,
                    'mail_error' => $mailError,
                    'stage' => 'failed',
                    'artifacts' => '[]',
                ]);
            } catch (Throwable $statusError) {
                error_log('Failed to mark backup job failed: ' . $statusError->getMessage());
            }
        }
    }

    private function describeArtifact(string $type, string $path): array
    {
        clearstatcache(true, $path);
        if (!is_file($path) || filesize($path) === 0) {
            throw new \RuntimeException(backup_artifact_label($type) . ' archive was not created');
        }
        return [
            'type' => $type,
            'file' => basename($path),
            'size_bytes' => (int) filesize($path),
            'sha256' => hash_file('sha256', $path),
        ];
    }

    /**
     * Streams every table straight into a gzip file (no temp copy, no full-table
     * buffering) using batched multi-row INSERTs that restore cleanly via phpMyAdmin.
     *
     * @param array{0: float, 1: float} $range progress band for this stage
     * @return int number of tables exported
     */
    private function createDatabaseArchive(string $gzPath, array $range): int
    {
        $conn = $this->conn;
        $stmt = null;
        $gz = gzopen($gzPath, 'wb6');
        if (!$gz) {
            throw new \RuntimeException("Failed to open database archive for writing: $gzPath");
        }

        try {
            $dbName = (string) $conn->query('SELECT DATABASE()')->fetchColumn();
            $sessionTz = (string) $conn->query('SELECT @@session.time_zone')->fetchColumn();

            $tables = [];
            $views = [];
            foreach ($conn->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
                if (strtoupper((string) ($row[1] ?? '')) === 'VIEW') {
                    $views[] = $row[0];
                } else {
                    $tables[] = $row[0];
                }
            }
            if (!$tables) {
                throw new \RuntimeException('No tables found in database');
            }

            gzwrite($gz, "-- BugRicer Database Backup\n");
            gzwrite($gz, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
            gzwrite($gz, "-- Database: {$dbName}\n");
            gzwrite($gz, "-- Tables: " . count($tables) . "\n\n");
            gzwrite($gz, "SET NAMES utf8mb4;\n");
            gzwrite($gz, "SET FOREIGN_KEY_CHECKS = 0;\n");
            gzwrite($gz, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n");
            gzwrite($gz, "SET time_zone = " . $conn->quote($sessionTz !== '' ? $sessionTz : '+05:30') . ";\n");
            gzwrite($gz, "START TRANSACTION;\n\n");

            $total = count($tables);
            foreach ($tables as $index => $table) {
                $quotedTable = '`' . str_replace('`', '``', $table) . '`';

                $createTable = $conn->query("SHOW CREATE TABLE {$quotedTable}")->fetch(PDO::FETCH_ASSOC);
                gzwrite($gz, "\n-- Table structure for {$quotedTable}\n");
                gzwrite($gz, "DROP TABLE IF EXISTS {$quotedTable};\n");
                gzwrite($gz, ($createTable['Create Table'] ?? '') . ";\n\n");

                $conn->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                $stmt = $conn->query("SELECT * FROM {$quotedTable}");
                $batch = [];
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    $values = [];
                    foreach ($row as $value) {
                        $values[] = $value === null ? 'NULL' : $conn->quote((string) $value);
                    }
                    $batch[] = '(' . implode(',', $values) . ')';
                    if (count($batch) >= self::INSERT_BATCH_ROWS) {
                        gzwrite($gz, "INSERT INTO {$quotedTable} VALUES\n" . implode(",\n", $batch) . ";\n");
                        $batch = [];
                    }
                }
                if ($batch) {
                    gzwrite($gz, "INSERT INTO {$quotedTable} VALUES\n" . implode(",\n", $batch) . ";\n");
                }
                $stmt->closeCursor();
                $stmt = null;
                $conn->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);

                $this->setProgress('database', $range[0] + ($range[1] - $range[0]) * (($index + 1) / $total));
            }

            foreach ($views as $view) {
                $quotedView = '`' . str_replace('`', '``', $view) . '`';
                $createView = $conn->query("SHOW CREATE VIEW {$quotedView}")->fetch(PDO::FETCH_ASSOC);
                if (!empty($createView['Create View'])) {
                    gzwrite($gz, "\nDROP VIEW IF EXISTS {$quotedView};\n" . $createView['Create View'] . ";\n");
                }
            }

            gzwrite($gz, "\nSET FOREIGN_KEY_CHECKS = 1;\nCOMMIT;\n");
            gzclose($gz);
            $gz = null;

            return $total;
        } finally {
            if ($stmt instanceof PDOStatement) {
                $stmt->closeCursor();
            }
            $conn->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            if ($gz) {
                gzclose($gz);
            }
        }
    }

    /**
     * Adds uploads/config directly from their source folders (no temporary copy of
     * GBs of media) and stores already-compressed files without recompression.
     *
     * @param array{0: float, 1: float} $range progress band for this stage
     */
    private function createFilesArchive(string $zipPath, array $options, int $tableCount, array $range): void
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('PHP zip extension is not available on this server');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new \RuntimeException("Cannot create files archive (ZipArchive error $opened)");
        }

        $fileCount = 0;
        if (!empty($options['include_uploads'])) {
            $uploadsDir = backup_resolve_uploads_path();
            if (is_dir($uploadsDir)) {
                $fileCount += $this->addDirectoryToZip($zip, $uploadsDir, 'uploads');
            } else {
                $zip->addEmptyDir('uploads');
            }
        }
        if (!empty($options['include_config'])) {
            $configDir = realpath(__DIR__ . '/../../config');
            if ($configDir && is_dir($configDir)) {
                $fileCount += $this->addDirectoryToZip($zip, $configDir, 'config');
            }
        }

        $zip->addFromString('README.md', $this->buildReadme($options));
        $zip->addFromString('manifest.json', $this->buildManifest($options, $tableCount, $fileCount));

        if (method_exists($zip, 'registerProgressCallback')) {
            $zip->registerProgressCallback(0.02, function ($ratio) use ($range) {
                $this->setProgress('files', $range[0] + ($range[1] - $range[0]) * (float) $ratio);
            });
        }

        if (!$zip->close()) {
            throw new \RuntimeException('Failed to write files archive: ' . $zip->getStatusString());
        }
    }

    private function addDirectoryToZip(ZipArchive $zip, string $sourceDir, string $prefix): int
    {
        $sourceDir = rtrim($sourceDir, DIRECTORY_SEPARATOR);
        $storageReal = realpath($this->storageDir) ?: $this->storageDir;
        $count = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if (strpos($path, $storageReal) === 0 || $item->getFilename() === '.DS_Store') {
                continue;
            }
            $relative = $prefix . '/' . str_replace('\\', '/', substr($path, strlen($sourceDir) + 1));

            if ($item->isDir()) {
                $zip->addEmptyDir($relative);
                continue;
            }
            if (!$item->isFile() || !$item->isReadable()) {
                continue;
            }

            $zip->addFile($path, $relative);
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($ext, self::STORE_ONLY_EXTENSIONS, true)) {
                $zip->setCompressionName($relative, ZipArchive::CM_STORE);
            }
            $count++;
        }

        return $count;
    }

    private function buildReadme(array $options): string
    {
        $readme = "# BugRicer Platform Backup - Restoration Guide\n\n";
        $readme .= "**Backup Created:** " . date('Y-m-d H:i:s') . " (Asia/Kolkata)\n\n";
        $readme .= "A BugRicer backup is delivered as two separate downloads:\n\n";
        if (!empty($options['include_database'])) {
            $readme .= "- `*_database.sql.gz` - gzip-compressed SQL dump of every table\n";
        }
        $readme .= "- `*_files.zip` - this archive";
        $parts = [];
        if (!empty($options['include_uploads'])) $parts[] = '`uploads/`';
        if (!empty($options['include_config'])) $parts[] = '`config/`';
        $readme .= $parts ? ' (' . implode(', ', $parts) . ', manifest.json)' : '';
        $readme .= "\n\n## 1. Restore the database\n\n";
        $readme .= "```bash\ngunzip -c bugricer_backup_*_database.sql.gz | mysql -u USER -p DATABASE_NAME\n```\n\n";
        $readme .= "phpMyAdmin: select the database, open **Import**, and upload the `.sql.gz` file directly.\n\n";
        $readme .= "## 2. Restore files\n\n";
        $readme .= "Extract `uploads/` into `backend/uploads/`, then:\n\n";
        $readme .= "```bash\nchmod -R 755 backend/uploads/\n```\n\n";
        $readme .= "## 3. Verify\n\n";
        $readme .= "1. Compare each download's SHA-256 with the checksum in the backup email.\n";
        $readme .= "2. Check database credentials in `backend/config/database.php`.\n";
        $readme .= "3. Open the app and confirm attachments load.\n\n";
        $readme .= "> This archive contains sensitive data. Store it encrypted and rotate credentials after restore.\n";
        return $readme;
    }

    private function buildManifest(array $options, int $tableCount, int $fileCount): string
    {
        return (string) json_encode([
            'product' => 'BugRicer',
            'backup_version' => '3.0',
            'job_id' => $this->jobId,
            'created_at' => date('c'),
            'components' => [
                'database' => (bool) ($options['include_database'] ?? false),
                'uploads' => (bool) ($options['include_uploads'] ?? false),
                'config' => (bool) ($options['include_config'] ?? false),
            ],
            'table_count' => $tableCount,
            'file_count' => $fileCount,
            'delivery' => 'signed_download_link',
            'retention_days' => BACKUP_RETENTION_DAYS,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function sendBackupReadyEmail(
        string $email,
        string $backupName,
        array $artifacts,
        int $expiresAt,
        int $tableCount,
        int $duration
    ): bool {
        $base = $this->downloadBase ?: 'https://bugbackend.bugricer.com/api/backup';
        $expiresLabel = date('d M Y, h:i A', $expiresAt);
        $createdLabel = date('d M Y, h:i A');
        $totalSize = backup_format_bytes(array_sum(array_map(static fn($a) => (int) $a['size_bytes'], $artifacts)));
        $e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $cardsHtml = '';
        $textLines = [];
        foreach ($artifacts as $artifact) {
            $type = (string) $artifact['type'];
            $url = backup_download_url($base, (int) $this->jobId, $type, $expiresAt);
            $label = backup_artifact_label($type);
            $size = backup_format_bytes((int) $artifact['size_bytes']);
            $accent = $type === 'database' ? '#2563eb' : '#7c3aed';
            $detail = $type === 'database'
                ? ($tableCount . ' tables · gzip SQL dump')
                : 'Uploads, config, README & manifest';

            $cardsHtml .= "
              <div style=\"border:1px solid #e2e8f0;border-radius:12px;padding:16px;margin:0 0 12px 0;\">
                <p style=\"margin:0;font-size:15px;font-weight:600;color:#0f172a;\">{$e($label)}</p>
                <p style=\"margin:4px 0 0 0;font-size:13px;color:#475569;\">{$e($size)} · {$e($detail)}</p>
                <p style=\"margin:4px 0 12px 0;font-size:11px;color:#94a3b8;word-break:break-all;\">{$e($artifact['file'])}<br>SHA-256: {$e($artifact['sha256'] ?? '')}</p>
                <a href=\"{$e($url)}\" style=\"display:inline-block;background:{$accent};color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:10px 18px;border-radius:12px;\">Download {$e(strtolower($label))}</a>
              </div>";

            $textLines[] = "{$label} ({$size})\n{$url}\nSHA-256: " . ($artifact['sha256'] ?? '');
        }

        $html = "
        <div style=\"font-family:'Segoe UI',Arial,sans-serif;background:#f1f5f9;padding:24px;color:#0f172a;\">
          <div style=\"max-width:600px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0;\">
            <div style=\"background:linear-gradient(90deg,#2563eb,#059669);padding:24px;color:#ffffff;\">
              <p style=\"margin:0;font-size:13px;opacity:.85;\">BugBackup Pro</p>
              <h1 style=\"margin:4px 0 0 0;font-size:22px;\">Your backup is ready to download</h1>
            </div>
            <div style=\"padding:24px;\">
              <p style=\"margin:0 0 16px 0;font-size:14px;color:#334155;\">
                Backup <strong>#{$e($this->jobId)}</strong> finished on {$e($createdLabel)} in {$e($duration)}s. Total size: <strong>{$e($totalSize)}</strong>.
              </p>
              {$cardsHtml}
              <div style=\"margin-top:16px;padding:12px 14px;background:#fef3c7;border-radius:12px;font-size:13px;color:#92400e;\">
                These links are private and expire on <strong>{$e($expiresLabel)}</strong>. After that the archives are deleted from the server.
                You can also download them anytime before expiry from BugRicer &rarr; BugBackup &rarr; Backup History.
              </div>
              <p style=\"margin:16px 0 0 0;font-size:12px;color:#64748b;\">Restore guide: import the .sql.gz in phpMyAdmin, then extract uploads/ from the files ZIP into backend/uploads/. Full steps are in README.md inside the files archive.</p>
            </div>
            <div style=\"background:#f8fafc;padding:16px;text-align:center;font-size:12px;color:#64748b;\">
              Automated message from BugRicer · Do not forward — anyone with these links can download your data.
            </div>
          </div>
        </div>";

        $text = "Your BugRicer backup #{$this->jobId} is ready ({$totalSize}).\n\n"
            . implode("\n\n", $textLines)
            . "\n\nLinks expire on {$expiresLabel}. Do not forward this email.\n";

        return (bool) sendEmail($email, "BugRicer backup ready — {$backupName}", $html, $text);
    }

    private function sendErrorEmail($email, $errorMessage): bool {
        $safe = htmlspecialchars((string) $errorMessage, ENT_QUOTES, 'UTF-8');
        $html = "
        <div style=\"font-family:'Segoe UI',Arial,sans-serif;padding:24px;color:#0f172a;\">
          <h2 style=\"color:#dc2626;margin:0 0 8px 0;\">Backup failed</h2>
          <p style=\"margin:0 0 12px 0;\">Backup #" . (int) $this->jobId . " could not be completed:</p>
          <p style=\"background:#fee2e2;padding:12px;border-radius:12px;margin:0 0 12px 0;\">{$safe}</p>
          <p style=\"margin:0;\">Please start a new backup from the BugBackup console.</p>
        </div>";
        return (bool) sendEmail($email, 'BugRicer backup failed', $html, "Backup failed: {$errorMessage}");
    }
}

// Handle HTTP request only (skip when included by CLI worker)
if (php_sapi_name() !== 'cli') {
    try {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');

        $controller = new BackupController();
        $controller->handleRequest();
    } catch (Throwable $e) {
        error_log("Backup API fatal error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo json_encode([
            'success' => false,
            'message' => 'Backup failed: ' . $e->getMessage(),
        ]);
    }
}
