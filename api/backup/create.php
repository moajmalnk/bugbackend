<?php
/**
 * BugRicer Platform Backup API
 * Creates a complete backup of the platform including database and files
 */

// Handle CORS FIRST - before any output
require_once __DIR__ . '/../../config/cors.php';

date_default_timezone_set('Asia/Kolkata');

// Load dependencies (will be loaded in constructor if needed)
require_once __DIR__ . '/../../config/composer_autoload.php';
require_once __DIR__ . '/backup_helpers.php';

use PHPMailer\PHPMailer\Exception;

class BackupController {
    private $backupDir;
    private $tempDir;
    protected $conn;
    protected $utils;
    protected $database;
    private $jobId = null;
    private $jobStartedAt = null;
    
    public function __construct() {
        // Initialize database and utilities
        try {
            // Check if already required (avoid duplicate requires)
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
                throw new Exception("Database connection failed");
            }
        } catch (Throwable $e) {
            error_log("BackupController initialization error: " . $e->getMessage());
            error_log("BackupController initialization stack: " . $e->getTraceAsString());
            // Don't exit here - let handleRequest handle the error response
            throw $e;
        }
        
        // Create backup directory if it doesn't exist
        // Use realpath to resolve relative paths properly
        // __DIR__ is /path/to/BugRicer/backend/api/backup
        // So ../.. gets us to /path/to/BugRicer/backend
        $backendPath = realpath(__DIR__ . '/../..') ?: dirname(dirname(__DIR__));
        $this->backupDir = $backendPath . DIRECTORY_SEPARATOR . 'backups';
        
        // Use a more reliable temp directory location
        // Try project directory first, fallback to system temp
        $projectTempDir = $backendPath . DIRECTORY_SEPARATOR . 'temp_backups';
        $projectBaseDir = $backendPath;
        
        // Try to use project temp directory first
        if (is_dir($projectTempDir) || (is_dir($projectBaseDir) && is_writable($projectBaseDir))) {
            $this->tempDir = $projectTempDir;
        } else {
            // Fallback to system temp with unique subdirectory
            $systemTemp = sys_get_temp_dir();
            $this->tempDir = $systemTemp . DIRECTORY_SEPARATOR . 'bugricer_backup_' . time();
        }
        
        // Ensure backup directory exists (don't throw exception, just log)
        if (!is_dir($this->backupDir)) {
            if (!@mkdir($this->backupDir, 0755, true)) {
                error_log("Warning: Failed to create backup directory: " . $this->backupDir);
                // Try alternative location in system temp
                $this->backupDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bugricer_backups';
                if (!is_dir($this->backupDir)) {
                    @mkdir($this->backupDir, 0755, true);
                }
            }
        }
        
        // Ensure temp directory exists (don't throw exception in constructor)
        // We'll check and create it when actually needed
        if (!is_dir($this->tempDir)) {
            if (!@mkdir($this->tempDir, 0755, true)) {
                error_log("Warning: Failed to create temp directory: " . $this->tempDir);
                // Don't throw exception here - we'll handle it in createBackup
            }
        }
    }
    
    public function handleRequest() {
        try {
            // Set headers manually (not using BaseAPI's automatic header setting)
            header('Content-Type: application/json');
            
            // Validate authentication
            $token = $this->getBearerToken();
            if (!$token) {
                $this->sendErrorResponse(401, "No token provided");
            }
            
            $tokenData = $this->utils->validateJWT($token);
            if (!$tokenData) {
                $this->sendErrorResponse(401, "Invalid token");
            }
            
            $userId = $tokenData->user_id ?? null;
            
            // Check permission (PermissionManager is a singleton)
            $permissionManager = PermissionManager::getInstance();
            
            if (!$permissionManager->hasPermission($userId, 'SETTINGS_EDIT')) {
                $this->sendErrorResponse(403, "You do not have permission to create backups");
            }
            
            // Get request data
            $data = $this->getRequestData();
            $email = $data['email'] ?? null;
            $includeDatabase = array_key_exists('include_database', $data) ? (bool) $data['include_database'] : true;
            $includeUploads = array_key_exists('include_uploads', $data) ? (bool) $data['include_uploads'] : true;
            $includeConfig = array_key_exists('include_config', $data) ? (bool) $data['include_config'] : true;
            $deliveryMethod = $data['delivery_method'] ?? 'email';
            
            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->sendErrorResponse(400, "Valid email address is required");
            }

            if (!$includeDatabase && !$includeUploads && !$includeConfig) {
                $this->sendErrorResponse(400, "Select at least one backup component");
            }
            
            backup_ensure_jobs_table($this->conn);
            $this->jobId = $this->createJobRecord(
                $userId,
                $email,
                $deliveryMethod,
                $includeDatabase,
                $includeUploads,
                $includeConfig
            );
            
            // Send response IMMEDIATELY - don't wait for backup to start
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Backup process started. You will receive an email when it\'s ready.',
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
            
            // Flush all output buffers immediately
            while (ob_get_level()) {
                ob_end_flush();
            }
            flush();
            
            // For FastCGI, finish request immediately to free up connection
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            
            // Run backup in a detached CLI worker (reliable on shared hosting / XAMPP)
            ignore_user_abort(true);
            set_time_limit(0);

            if ($this->jobId) {
                $spawned = $this->spawnBackgroundJob($this->jobId);
                if (!$spawned) {
                    error_log("⚠️ Could not spawn backup worker; running inline for job {$this->jobId}");
                    try {
                        $this->createBackup($email, [
                            'include_database' => $includeDatabase,
                            'include_uploads' => $includeUploads,
                            'include_config' => $includeConfig,
                            'delivery_method' => $deliveryMethod,
                        ]);
                    } catch (Throwable $backupError) {
                        error_log("Backup process error: " . $backupError->getMessage());
                        try {
                            $this->sendErrorEmail($email, $backupError->getMessage());
                        } catch (Exception $emailError) {
                            error_log("Failed to send error email: " . $emailError->getMessage());
                        }
                    }
                }
            }
            
            exit(0);
            
        } catch (Throwable $e) {
            error_log("Backup API error: " . $e->getMessage());
            error_log("Backup API file: " . $e->getFile() . " line: " . $e->getLine());
            error_log("Backup API stack trace: " . $e->getTraceAsString());
            $this->sendErrorResponse(500, "Backup failed: " . $e->getMessage());
        }
    }
    
    private function getBearerToken() {
        // Try getallheaders() first (works in Apache)
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if ($headers && isset($headers['Authorization'])) {
                if (preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
                    return $matches[1];
                }
            }
        }
        
        // Fallback: check $_SERVER for Authorization header
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            if (preg_match('/Bearer\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
                return $matches[1];
            }
        }
        
        // Also check REDIRECT_HTTP_AUTHORIZATION (some server configs)
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            if (preg_match('/Bearer\s+(.*)$/i', $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $matches)) {
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
                throw new Exception("Invalid JSON: " . json_last_error_msg());
            }
            
            return $data;
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

        return (int) $this->conn->lastInsertId();
    }

    private function updateJobStatus($status, array $extra = [])
    {
        if (!$this->jobId || !backup_table_exists($this->conn, 'backup_jobs')) {
            return;
        }

        $fields = ['status = ?'];
        $values = [$status];

        foreach (['backup_name', 'file_size_bytes', 'table_count', 'duration_seconds', 'error_message'] as $key) {
            if (array_key_exists($key, $extra)) {
                $fields[] = "$key = ?";
                $values[] = $extra[$key];
            }
        }

        if ($status === 'completed' || $status === 'failed') {
            $fields[] = 'completed_at = NOW()';
        }

        $values[] = $this->jobId;
        $sql = 'UPDATE backup_jobs SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($values);
    }

    /**
     * CLI worker entry: load job row and run backup.
     */
    public function processJob(int $jobId): void
    {
        backup_ensure_jobs_table($this->conn);

        if (!backup_table_exists($this->conn, 'backup_jobs')) {
            throw new Exception('backup_jobs table is not available');
        }

        $stmt = $this->conn->prepare(
            'SELECT id, email, delivery_method, include_database, include_uploads, include_config, status
             FROM backup_jobs WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$job) {
            throw new Exception("Backup job not found: $jobId");
        }

        if ($job['status'] === 'completed') {
            error_log("Backup job $jobId already completed; skipping");
            return;
        }

        $this->jobId = (int) $job['id'];
        $this->createBackup($job['email'], [
            'include_database' => (bool) $job['include_database'],
            'include_uploads' => (bool) $job['include_uploads'],
            'include_config' => (bool) $job['include_config'],
            'delivery_method' => $job['delivery_method'] ?? 'email',
        ]);
    }

    private function spawnBackgroundJob(int $jobId): bool
    {
        $worker = __DIR__ . '/run_backup_job.php';
        if (!is_file($worker)) {
            error_log("Backup worker script missing: $worker");
            return false;
        }

        $phpBinary = PHP_BINARY;
        if (!$phpBinary || !is_executable($phpBinary)) {
            $candidates = [
                '/Applications/XAMPP/xamppfiles/bin/php',
                '/usr/local/bin/php',
                '/usr/bin/php',
                'php',
            ];
            foreach ($candidates as $candidate) {
                if ($candidate === 'php' || (is_file($candidate) && is_executable($candidate))) {
                    $phpBinary = $candidate;
                    break;
                }
            }
        }

        $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($worker) . ' ' . (int) $jobId;
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            pclose(popen('start /B ' . $cmd, 'r'));
        } else {
            exec($cmd . ' > /dev/null 2>&1 &');
        }

        error_log("🚀 Spawned backup worker for job $jobId");
        return true;
    }
    
    private function createBackup($email, array $options = []) {
        $includeDatabase = $options['include_database'] ?? true;
        $includeUploads = $options['include_uploads'] ?? true;
        $includeConfig = $options['include_config'] ?? true;
        $this->jobStartedAt = microtime(true);

        try {
            error_log("🔄 Starting backup process for: $email");
            
            // Ensure temp directory exists and is writable
            // Try multiple locations if needed
            $backendPath = realpath(__DIR__ . '/../..') ?: dirname(dirname(__DIR__));
            $projectTempDir = $backendPath . DIRECTORY_SEPARATOR . 'temp_backups';
            $systemTempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bugricer_backup_' . time();
            
            // Use realpath to resolve relative paths
            $resolvedProjectTemp = realpath($projectTempDir) ?: $projectTempDir;
            $resolvedThisTemp = realpath($this->tempDir) ?: $this->tempDir;
            
            $tempDirs = [
                $resolvedProjectTemp,
                $resolvedThisTemp,
                $systemTempDir
            ];
            
            // Remove duplicates and empty values
            $tempDirs = array_filter(array_unique($tempDirs));
            
            $workingTempDir = null;
            foreach ($tempDirs as $tempDir) {
                if (empty($tempDir)) continue;
                
                error_log("🔍 Trying temp directory: $tempDir");
                
                // Create directory if it doesn't exist
                if (!is_dir($tempDir)) {
                    $parentDir = dirname($tempDir);
                    // Check if parent directory is writable
                    if (!is_writable($parentDir) && !is_dir($parentDir)) {
                        error_log("⚠️ Parent directory not writable or doesn't exist: $parentDir");
                        continue;
                    }
                    
                    if (@mkdir($tempDir, 0777, true)) {
                        error_log("✅ Created temp directory: $tempDir");
                    } else {
                        $error = error_get_last();
                        error_log("⚠️ Failed to create temp directory: $tempDir. Error: " . ($error['message'] ?? 'Unknown'));
                        continue;
                    }
                }
                
                // Check if writable - try to write a test file
                $testFile = $tempDir . DIRECTORY_SEPARATOR . '.test_write_' . time();
                if (@file_put_contents($testFile, 'test') !== false) {
                    @unlink($testFile);
                    $workingTempDir = $tempDir;
                    error_log("📁 Using temp directory: $workingTempDir");
                    break;
                } else {
                    error_log("⚠️ Temp directory not writable (test write failed): $tempDir");
                }
            }
            
            if (!$workingTempDir) {
                $triedPaths = implode(', ', $tempDirs);
                error_log("❌ All temp directory attempts failed. Tried: $triedPaths");
                throw new Exception("Cannot create or access temporary directory. Tried: " . $triedPaths . ". Please check directory permissions.");
            }
            
            $timestamp = date('Y-m-d_H-i-s');
            $backupName = "bugricer_backup_{$timestamp}";
            $backupPath = $workingTempDir . DIRECTORY_SEPARATOR . $backupName;
            
            // Create backup directory structure
            if (!is_dir($backupPath)) {
                if (!@mkdir($backupPath, 0755, true)) {
                    $error = error_get_last();
                    throw new Exception("Failed to create backup directory: $backupPath. Error: " . ($error['message'] ?? 'Unknown error'));
                }
            }
            
            // Verify the directory was created and is writable
            if (!is_dir($backupPath)) {
                throw new Exception("Backup directory was not created: $backupPath");
            }
            
            if (!is_writable($backupPath)) {
                throw new Exception("Backup directory is not writable: $backupPath");
            }
            
            error_log("📁 Backup path: $backupPath");
            
            $dbDir = $backupPath . DIRECTORY_SEPARATOR . 'database';
            $uploadsDir = $backupPath . DIRECTORY_SEPARATOR . 'uploads';
            $configDir = $backupPath . DIRECTORY_SEPARATOR . 'config';
            
            // 1. Create database backup
            $tableCount = 0;
            if ($includeDatabase) {
                if (!is_dir($dbDir)) {
                    if (!@mkdir($dbDir, 0755, true)) {
                        $error = error_get_last();
                        throw new Exception("Failed to create database backup directory: $dbDir. Error: " . ($error['message'] ?? 'Unknown error'));
                    }
                }
                error_log("📊 Creating database backup...");
                $this->createDatabaseBackup($backupPath . '/database');
                $tableCount = backup_count_tables($this->conn);
            }
            
            // 2. Create files backup
            if ($includeUploads) {
                if (!is_dir($uploadsDir)) {
                    if (!@mkdir($uploadsDir, 0755, true)) {
                        $error = error_get_last();
                        throw new Exception("Failed to create uploads backup directory: $uploadsDir. Error: " . ($error['message'] ?? 'Unknown error'));
                    }
                }
                error_log("📁 Creating files backup...");
                $this->createFilesBackup($backupPath . '/uploads');
            }

            // 3. Create config backup
            if ($includeConfig) {
                if (!is_dir($configDir)) {
                    @mkdir($configDir, 0755, true);
                }
                error_log("⚙️ Creating config backup...");
                $this->createConfigBackup($configDir);
            }
            
            // 4. Create README with restoration instructions
            error_log("📝 Creating restoration instructions...");
            $this->createReadme($backupPath, $options);
            $this->createManifest($backupPath, $options, $tableCount);
            
            // 5. Create ZIP archive
            error_log("📦 Creating ZIP archive...");
            $zipFile = $this->createZipArchive($backupPath, $backupName);
            $fileSize = file_exists($zipFile) ? filesize($zipFile) : 0;
            $duration = (int) max(1, round(microtime(true) - $this->jobStartedAt));
            
            // 6. Send email with attachment
            error_log("📧 Sending backup email to: $email");
            $this->sendBackupEmail($email, $zipFile, $backupName, $options);
            
            // 7. Cleanup
            $this->cleanup($backupPath, $zipFile);

            $this->updateJobStatus('completed', [
                'backup_name' => $backupName,
                'file_size_bytes' => $fileSize,
                'table_count' => $tableCount,
                'duration_seconds' => $duration,
            ]);
            
            error_log("✅ Backup completed successfully for: $email");
            
        } catch (Exception $e) {
            error_log("❌ Backup failed: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());

            $duration = $this->jobStartedAt
                ? (int) max(1, round(microtime(true) - $this->jobStartedAt))
                : null;
            $this->updateJobStatus('failed', [
                'error_message' => $e->getMessage(),
                'duration_seconds' => $duration,
            ]);
            
            // Try to send error notification
            try {
                $this->sendErrorEmail($email, $e->getMessage());
            } catch (Exception $emailError) {
                error_log("Failed to send error email: " . $emailError->getMessage());
            }
        }
    }
    
    private function createDatabaseBackup($outputDir) {
        try {
            $database = Database::getInstance();
            $conn = $database->getConnection();
            
            if (!$conn) {
                throw new Exception("Database connection failed");
            }
            
            // Get database name
            $dbName = $conn->query("SELECT DATABASE()")->fetchColumn();
            
            if (!is_dir($outputDir)) {
                if (!mkdir($outputDir, 0755, true)) {
                    throw new Exception("Failed to create database backup directory: $outputDir");
                }
            }
            
            $timestamp = date('Y-m-d_H-i-s');
            $sqlFile = $outputDir . "/database_backup_{$timestamp}.sql";
            
            // Get all tables
            $tables = $conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            
            if (empty($tables)) {
                throw new Exception("No tables found in database");
            }
            
            // Open file for writing (much faster than building string in memory)
            $fp = fopen($sqlFile, 'w');
            if (!$fp) {
                throw new Exception("Failed to open SQL backup file for writing: $sqlFile");
            }
            
            // Write header
            fwrite($fp, "-- BugRicer Database Backup\n");
            fwrite($fp, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
            fwrite($fp, "-- Database: {$dbName}\n\n");
            fwrite($fp, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n");
            fwrite($fp, "SET time_zone = \"+00:00\";\n\n");
            fwrite($fp, "START TRANSACTION;\n\n");
            
            // Process tables in batches for better performance
            foreach ($tables as $table) {
                error_log("  Exporting table: $table");
                
                // Get table structure
                $createTable = $conn->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
                fwrite($fp, "\n-- Table structure for table `{$table}`\n");
                fwrite($fp, "DROP TABLE IF EXISTS `{$table}`;\n");
                fwrite($fp, $createTable['Create Table'] . ";\n\n");
                
                // Get table data - use unbuffered query for large tables
                $stmt = $conn->query("SELECT * FROM `{$table}`");
                $rowCount = 0;
                $firstRow = true;
                
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if ($firstRow) {
                        fwrite($fp, "-- Dumping data for table `{$table}`\n");
                        fwrite($fp, "INSERT INTO `{$table}` VALUES\n");
                        $firstRow = false;
                    } else {
                        fwrite($fp, ",\n");
                    }
                    
                    // Build row values
                    $rowValues = [];
                    foreach ($row as $value) {
                        if ($value === null) {
                            $rowValues[] = 'NULL';
                        } else {
                            $rowValues[] = $conn->quote($value);
                        }
                    }
                    fwrite($fp, '(' . implode(',', $rowValues) . ')');
                    $rowCount++;
                    
                    // Flush every 1000 rows to prevent memory issues
                    if ($rowCount % 1000 == 0) {
                        fflush($fp);
                    }
                }
                
                if (!$firstRow) {
                    fwrite($fp, ";\n\n");
                }
            }
            
            fwrite($fp, "COMMIT;\n");
            fclose($fp);
            
            error_log("✅ Database backup created: $sqlFile");
            return $sqlFile;
            
        } catch (Exception $e) {
            error_log("❌ Database backup failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    private function createFilesBackup($outputDir) {
        $uploadsDir = backup_resolve_uploads_path();
        
        if (!is_dir($uploadsDir)) {
            error_log("⚠️ Uploads directory not found: $uploadsDir");
            file_put_contents($outputDir . '/.gitkeep', '');
            return null;
        }
        
        // Copy uploads directory recursively
        $this->copyDirectory($uploadsDir, $outputDir);
        
        return $outputDir;
    }
    
    private function copyDirectory($source, $destination) {
        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $destPath = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            
            if ($item->isDir()) {
                if (!is_dir($destPath)) {
                    mkdir($destPath, 0755, true);
                }
            } else {
                copy($item, $destPath);
            }
        }
    }
    
    private function createConfigBackup($outputDir) {
        $configSource = realpath(__DIR__ . '/../../config');
        if (!$configSource || !is_dir($configSource)) {
            file_put_contents($outputDir . '/.gitkeep', '');
            return null;
        }

        $this->copyDirectory($configSource, $outputDir);
        return $outputDir;
    }
    
    private function createReadme($backupPath, array $options = []) {
        $includeDatabase = $options['include_database'] ?? true;
        $includeUploads = $options['include_uploads'] ?? true;
        $includeConfig = $options['include_config'] ?? true;

        $readme = "# BugRicer Platform Backup - Restoration Guide\n\n";
        $readme .= "**Backup Created:** " . date('Y-m-d H:i:s') . "\n\n";
        $readme .= "## Contents\n\n";
        $readme .= "This backup contains:\n";
        if ($includeDatabase) {
            $readme .= "- `database/` - Complete SQL dump of all database tables\n";
        }
        if ($includeUploads) {
            $readme .= "- `uploads/` - All uploaded files, images, and attachments\n";
        }
        if ($includeConfig) {
            $readme .= "- `config/` - Application configuration files\n";
        }
        $readme .= "- `manifest.json` - Backup metadata and component manifest\n\n";
        $readme .= "## Restoration Instructions\n\n";
        $readme .= "### For Backend Developers\n\n";
        $readme .= "#### Step 1: Database Restoration\n\n";
        $readme .= "1. Access your MySQL/MariaDB database (via phpMyAdmin, command line, or your hosting control panel)\n";
        $readme .= "2. Create a new database or use an existing one\n";
        $readme .= "3. Import the SQL file from the `database/` folder:\n\n";
        $readme .= "   **Via Command Line:**\n";
        $readme .= "   ```bash\n";
        $readme .= "   mysql -u your_username -p your_database_name < database/database_backup_*.sql\n";
        $readme .= "   ```\n\n";
        $readme .= "   **Via phpMyAdmin:**\n";
        $readme .= "   - Select your database\n";
        $readme .= "   - Go to 'Import' tab\n";
        $readme .= "   - Choose the SQL file from `database/` folder\n";
        $readme .= "   - Click 'Go'\n\n";
        $readme .= "#### Step 2: Files Restoration\n\n";
        $readme .= "1. Navigate to your BugRicer backend directory\n";
        $readme .= "2. Extract the contents of the `uploads/` folder to:\n";
        $readme .= "   ```\n";
        $readme .= "   backend/uploads/\n";
        $readme .= "   ```\n";
        $readme .= "3. Ensure proper file permissions:\n";
        $readme .= "   ```bash\n";
        $readme .= "   chmod -R 755 backend/uploads/\n";
        $readme .= "   ```\n\n";
        $readme .= "#### Step 3: Verify Restoration\n\n";
        $readme .= "1. Check database connection in `backend/config/database.php`\n";
        $readme .= "2. Verify all tables are imported correctly\n";
        $readme .= "3. Check that uploaded files are accessible\n";
        $readme .= "4. Test the application functionality\n\n";
        $readme .= "## Important Notes\n\n";
        $readme .= "- **Database Credentials:** Update `backend/config/database.php` with your database credentials before importing\n";
        $readme .= "- **File Permissions:** Ensure the web server has read/write access to the uploads directory\n";
        $readme .= "- **Backup Date:** This backup was created on " . date('Y-m-d H:i:s') . "\n";
        $readme .= "- **Version:** Make sure you're restoring to a compatible BugRicer version\n\n";
        $readme .= "## Support\n\n";
        $readme .= "If you encounter any issues during restoration, please contact the BugRicer support team.\n\n";
        $readme .= "---\n";
        $readme .= "*Generated by BugRicer Backup System*\n";
        
        file_put_contents($backupPath . '/README.md', $readme);
    }

    private function createManifest($backupPath, array $options, $tableCount) {
        $manifest = [
            'product' => 'BugRicer',
            'backup_version' => '2.0',
            'created_at' => date('c'),
            'components' => [
                'database' => (bool) ($options['include_database'] ?? true),
                'uploads' => (bool) ($options['include_uploads'] ?? true),
                'config' => (bool) ($options['include_config'] ?? true),
            ],
            'table_count' => $tableCount,
            'delivery_method' => $options['delivery_method'] ?? 'email',
        ];

        file_put_contents(
            $backupPath . '/manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }
    
    private function createZipArchive($backupPath, $backupName) {
        try {
            if (!is_dir($this->backupDir)) {
                if (!mkdir($this->backupDir, 0755, true)) {
                    throw new Exception("Failed to create backup directory: " . $this->backupDir);
                }
            }
            
            $zipFile = $this->backupDir . '/' . $backupName . '.zip';
            
            $zip = new ZipArchive();
            $result = $zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            if ($result !== TRUE) {
                throw new Exception("Cannot create ZIP file: $zipFile (Error code: $result)");
            }
        
        // Add all files recursively
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($backupPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($backupPath) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }
        
            $zip->close();
            
            if (!file_exists($zipFile)) {
                throw new Exception("ZIP file was not created: $zipFile");
            }
            
            error_log("✅ ZIP archive created: $zipFile");
            return $zipFile;
            
        } catch (Exception $e) {
            error_log("❌ ZIP creation failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    private function sendBackupEmail($email, $zipFile, $backupName, array $options = []) {
        $includeDatabase = $options['include_database'] ?? true;
        $includeUploads = $options['include_uploads'] ?? true;
        $includeConfig = $options['include_config'] ?? true;
        $components = [];
        if ($includeDatabase) $components[] = 'Complete database SQL dump';
        if ($includeUploads) $components[] = 'All uploaded files and attachments';
        if ($includeConfig) $components[] = 'Configuration files';
        $components[] = 'Restoration instructions (README.md)';
        $componentsHtml = '';
        foreach ($components as $component) {
            $componentsHtml .= '<li>' . htmlspecialchars($component) . '</li>';
        }
        $componentsText = implode("\n- ", $components);

        $subject = "BugRicer Platform Backup - " . date('Y-m-d H:i:s');
        
        $html_body = "
        <div style=\"font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f7f6; padding: 20px;\">
          <div style=\"max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">
            
            <!-- Header -->
            <div style=\"background-color: #2563eb; color: #ffffff; padding: 20px; text-align: center;\">
              <h1 style=\"margin: 0; font-size: 24px; display: flex; align-items: center; justify-content: center;\">
                <span style=\"font-size: 30px; margin-right: 10px;\">💾</span>
                BugRicer Backup Ready
              </h1>
            </div>
            
            <!-- Body -->
            <div style=\"padding: 20px; border-bottom: 1px solid #e2e8f0;\">
              <h3 style=\"margin-top: 0; color: #1e293b; font-size: 18px;\">Your Backup is Ready!</h3>
              <p style=\"white-space: pre-line; margin-bottom: 15px; font-size: 14px;\">
                Your complete BugRicer platform backup has been successfully created and is attached to this email.
              </p>
              
              <div style=\"margin: 20px 0; padding: 15px; background-color: #f0f9ff; border-left: 4px solid #2563eb; border-radius: 4px;\">
                <p style=\"margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #1e40af;\"><strong>📦 Backup Details:</strong></p>
                <ul style=\"margin: 0; padding-left: 20px; font-size: 14px; color: #1e40af;\">
                  {$componentsHtml}
                </ul>
              </div>
              
              <p style=\"font-size: 14px; margin-bottom: 10px;\"><strong>Backup Name:</strong> {$backupName}.zip</p>
              <p style=\"font-size: 14px; margin-bottom: 10px;\"><strong>Created:</strong> " . date('Y-m-d H:i:s') . "</p>
              
              <div style=\"margin: 20px 0; padding: 12px; background-color: #fef3c7; border-left: 4px solid #f59e0b; border-radius: 4px;\">
                <p style=\"margin: 0; font-size: 14px; color: #92400e;\">
                  <strong>⚠️ Important:</strong> This backup contains sensitive data. Please store it securely and do not share it with unauthorized parties.
                </p>
              </div>
              
              <p style=\"font-size: 14px; margin-bottom: 0;\">The backup file is attached to this email. Extract it and follow the README.md instructions for restoration.</p>
              <p style=\"font-size: 14px; margin-top: 15px; margin-bottom: 0;\">Best regards,<br>The BugRicer Team</p>
            </div>
            
            <!-- Footer -->
            <div style=\"background-color: #f8fafc; color: #64748b; padding: 20px; text-align: center; font-size: 12px;\">
              <p style=\"margin: 0;\">This is an automated notification from BugRicer. Please do not reply to this email.</p>
              <p style=\"margin: 5px 0 0 0;\">&copy; " . date('Y') . " BugRicer. All rights reserved.</p>
            </div>
            
          </div>
        </div>
        ";
        
        $text_body = "
BugRicer Platform Backup Ready

Your complete BugRicer platform backup has been successfully created and is attached to this email.

Backup Details:
- {$componentsText}

Backup Name: {$backupName}.zip
Created: " . date('Y-m-d H:i:s') . "

Important: This backup contains sensitive data. Please store it securely.

The backup file is attached to this email. Extract it and follow the README.md instructions for restoration.

Best regards,
The BugRicer Team

© " . date('Y') . " BugRicer. All rights reserved.
        ";
        
        // Send email with attachment via shared SMTP config
        try {
            if (!function_exists('sendEmailWithAttachment')) {
                require_once __DIR__ . '/../../utils/email.php';
            }

            if (!file_exists($zipFile)) {
                throw new Exception("Backup ZIP file not found: $zipFile");
            }

            $fileSizeMb = round(filesize($zipFile) / (1024 * 1024), 2);
            if ($fileSizeMb > 24) {
                error_log("⚠️ Backup ZIP is {$fileSizeMb}MB — may exceed Gmail attachment limits");
            }

            $sent = sendEmailWithAttachment(
                $email,
                $subject,
                $html_body,
                $zipFile,
                $backupName . '.zip',
                $text_body
            );

            if (!$sent) {
                throw new Exception(
                    'Failed to send backup email. Check SMTP settings in backend/.env and verify the archive is under your provider size limit (~25MB for Gmail).'
                );
            }

            error_log("✅ Backup email sent successfully to: $email");
        } catch (Exception $e) {
            error_log("❌ Failed to send backup email: " . $e->getMessage());
            throw $e;
        }
    }
    
    private function sendErrorEmail($email, $errorMessage) {
        $subject = "BugRicer Backup Failed";
        
        $html_body = "
        <div style=\"font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; padding: 20px;\">
          <h2 style=\"color: #dc2626;\">Backup Failed</h2>
          <p>The backup process encountered an error:</p>
          <p style=\"background-color: #fee2e2; padding: 10px; border-radius: 4px;\">{$errorMessage}</p>
          <p>Please try again or contact support.</p>
        </div>
        ";
        
        sendEmail($email, $subject, $html_body);
    }
    
    private function cleanup($backupPath, $zipFile) {
        // Delete temporary directory
        if (is_dir($backupPath)) {
            $this->deleteDirectory($backupPath);
        }
        
        // Keep ZIP file for 7 days, then delete
        // (You can implement a cron job for this)
    }
    
    private function deleteDirectory($dir) {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}

// Handle HTTP request only (skip when included by CLI worker)
if (php_sapi_name() !== 'cli') {
try {
    // Enable error reporting for debugging (remove in production)
    error_reporting(E_ALL);
    ini_set('display_errors', 0); // Don't display errors, just log them
    
    // Initialize controller (may throw exception)
    try {
        $controller = new BackupController();
    } catch (Throwable $initError) {
        error_log("Failed to initialize BackupController: " . $initError->getMessage());
        throw $initError;
    }
    
    // Handle the request
    $controller->handleRequest();
} catch (Throwable $e) {
    error_log("Backup API fatal error: " . $e->getMessage());
    error_log("Backup API file: " . $e->getFile() . " line: " . $e->getLine());
    error_log("Backup API stack trace: " . $e->getTraceAsString());
    
    // Make sure we send a proper JSON response with CORS
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    
    echo json_encode([
        'success' => false,
        'message' => 'Backup failed: ' . $e->getMessage(),
        'error' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);
}
}


