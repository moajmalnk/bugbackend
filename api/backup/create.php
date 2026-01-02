<?php
/**
 * BugRicer Platform Backup API
 * Creates a complete backup of the platform including database and files
 */

// Handle CORS FIRST - before any output
require_once __DIR__ . '/../../config/cors.php';

date_default_timezone_set('Asia/Kolkata');

// Load dependencies (will be loaded in constructor if needed)
require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class BackupController {
    private $backupDir;
    private $tempDir;
    protected $conn;
    protected $utils;
    protected $database;
    
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
            
            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->sendErrorResponse(400, "Valid email address is required");
            }
            
            // Send response IMMEDIATELY - don't wait for backup to start
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Backup process started. You will receive an email when it\'s ready.',
                'data' => [
                    'email' => $email,
                    'status' => 'processing'
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
            
            // Run backup in background (after response sent)
            // Use ignore_user_abort to continue even if client disconnects
            ignore_user_abort(true);
            set_time_limit(0); // No time limit for background process
            
            try {
                $this->createBackup($email);
            } catch (Throwable $backupError) {
                error_log("Backup process error: " . $backupError->getMessage());
                error_log("Backup stack trace: " . $backupError->getTraceAsString());
                // Try to send error email
                try {
                    $this->sendErrorEmail($email, $backupError->getMessage());
                } catch (Exception $emailError) {
                    error_log("Failed to send error email: " . $emailError->getMessage());
                }
            }
            
            // Exit after backup starts (don't wait for completion)
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
    
    private function createBackup($email) {
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
            
            if (!is_dir($dbDir)) {
                if (!@mkdir($dbDir, 0755, true)) {
                    $error = error_get_last();
                    throw new Exception("Failed to create database backup directory: $dbDir. Error: " . ($error['message'] ?? 'Unknown error'));
                }
            }
            
            if (!is_dir($uploadsDir)) {
                if (!@mkdir($uploadsDir, 0755, true)) {
                    $error = error_get_last();
                    throw new Exception("Failed to create uploads backup directory: $uploadsDir. Error: " . ($error['message'] ?? 'Unknown error'));
                }
            }
            
            // 1. Create database backup
            error_log("📊 Creating database backup...");
            $dbBackupFile = $this->createDatabaseBackup($backupPath . '/database');
            
            // 2. Create files backup
            error_log("📁 Creating files backup...");
            $filesBackupFile = $this->createFilesBackup($backupPath . '/uploads');
            
            // 3. Create README with restoration instructions
            error_log("📝 Creating restoration instructions...");
            $this->createReadme($backupPath);
            
            // 4. Create ZIP archive
            error_log("📦 Creating ZIP archive...");
            $zipFile = $this->createZipArchive($backupPath, $backupName);
            
            // 5. Send email with attachment
            error_log("📧 Sending backup email to: $email");
            $this->sendBackupEmail($email, $zipFile, $backupName);
            
            // 6. Cleanup
            $this->cleanup($backupPath, $zipFile);
            
            error_log("✅ Backup completed successfully for: $email");
            
        } catch (Exception $e) {
            error_log("❌ Backup failed: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            
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
        $uploadsDir = __DIR__ . '/../../uploads';
        
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
    
    private function createReadme($backupPath) {
        $readme = "# BugRicer Platform Backup - Restoration Guide\n\n";
        $readme .= "**Backup Created:** " . date('Y-m-d H:i:s') . "\n\n";
        $readme .= "## Contents\n\n";
        $readme .= "This backup contains:\n";
        $readme .= "- `database/` - Complete SQL dump of all database tables\n";
        $readme .= "- `uploads/` - All uploaded files, images, and attachments\n\n";
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
    
    private function sendBackupEmail($email, $zipFile, $backupName) {
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
                  <li>Complete database SQL dump</li>
                  <li>All uploaded files and attachments</li>
                  <li>Restoration instructions (README.md)</li>
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
- Complete database SQL dump
- All uploaded files and attachments
- Restoration instructions (README.md)

Backup Name: {$backupName}.zip
Created: " . date('Y-m-d H:i:s') . "

Important: This backup contains sensitive data. Please store it securely.

The backup file is attached to this email. Extract it and follow the README.md instructions for restoration.

Best regards,
The BugRicer Team

© " . date('Y') . " BugRicer. All rights reserved.
        ";
        
        // Send email with attachment
        try {
            $mail = new PHPMailer(true);
            
            // GMAIL SMTP CONFIGURATION
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'codo.bugricer@gmail.com';
            $mail->Password = 'ieka afeu uhds qkam';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
            
            // Recipients
            $mail->setFrom('codo.bugricer@gmail.com', 'BugRicer');
            $mail->addAddress($email);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html_body;
            $mail->AltBody = $text_body;
            
            // Add attachment
            if (!file_exists($zipFile)) {
                throw new Exception("Backup ZIP file not found: $zipFile");
            }
            
            $mail->addAttachment($zipFile, $backupName . '.zip');
            
            // Add debug output
            $mail->Debugoutput = function($str, $level) {
                error_log("PHPMailer debug: $str");
            };
            
            $mail->send();
            error_log("✅ Backup email sent successfully to: $email");
            
        } catch (Exception $e) {
            error_log("❌ Failed to send backup email: " . $e->getMessage());
            if (isset($mail)) {
                error_log("PHPMailer ErrorInfo: " . $mail->ErrorInfo);
            }
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

// Handle request
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


