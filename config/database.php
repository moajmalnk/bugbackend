<?php
class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    public $conn;
    private static $instance = null;
    private static $connectionPool = [];
    private static $cache = [];
    private static $cacheTimeout = 300; // 5 minutes

    private function __construct() {
        // More reliable environment detection
        $isLocal = $this->isLocalEnvironment();
        
        if ($isLocal) {
            // Local database configuration
            $this->host = "localhost";
            $this->db_name = "u262074081_bugfixer_db";
            $this->username = "root";
            $this->password = "";
        } else {
            // Production database configuration - Common Hostinger patterns
            
            // Try the most common configuration first
            $this->host = "localhost";
            $this->db_name = "u262074081_bugfixer";
            $this->username = "u262074081_bugfixer";
            
            // Common password alternatives for this hosting setup
            $possiblePasswords = [
                "CodoMail@8848",           // Original
                "CodoMail@88",             // Shortened version
                "codomail@8848",           // Lowercase
                "CodoMail8848",            // Without @
                "u262074081_bugfixer",     // Sometimes same as username
            ];
            
            // Use the first password by default
            $this->password = $possiblePasswords[0];
            
            error_log("Production environment detected");
            error_log("Database host: " . $this->host);
            error_log("Database name: " . $this->db_name);
            error_log("Username: " . $this->username);
            error_log("Will try multiple password variations if needed");
        }
        
        // Log environment detection
        error_log("Environment detected: " . ($isLocal ? "Local" : "Production"));
        error_log("Database host: " . $this->host);
        error_log("Database name: " . $this->db_name);
    }
    
    // Singleton pattern for database connection
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }
    
    // Cache management
    public static function setCache($key, $value, $timeout = null) {
        $timeout = $timeout ?? self::$cacheTimeout;
        self::$cache[$key] = [
            'data' => $value,
            'expires' => time() + $timeout
        ];
    }
    
    public static function getCache($key) {
        if (isset(self::$cache[$key])) {
            if (self::$cache[$key]['expires'] > time()) {
                return self::$cache[$key]['data'];
            } else {
                unset(self::$cache[$key]);
            }
        }
        return null;
    }
    
    public static function clearCache($pattern = null) {
        if ($pattern === null) {
            self::$cache = [];
        } else {
            foreach (self::$cache as $key => $value) {
                if (strpos($key, $pattern) !== false) {
                    unset(self::$cache[$key]);
                }
            }
        }
    }
    
    private function isLocalEnvironment() {
        // Multiple checks for local environment
        $localHosts = ['localhost', '127.0.0.1', '::1'];
        $httpHost = $_SERVER['HTTP_HOST'] ?? '';
        $serverName = $_SERVER['SERVER_NAME'] ?? '';
        
        // Check if running on localhost
        foreach ($localHosts as $localHost) {
            if (strpos($httpHost, $localHost) !== false || strpos($serverName, $localHost) !== false) {
                return true;
            }
        }
        
        // Check for common local development ports
        if (preg_match('/:(8080|8000|3000|4000|5000)$/', $httpHost)) {
            return true;
        }
        
        // Check if XAMPP/WAMP environment
        if (isset($_SERVER['SERVER_SOFTWARE']) && 
            (stripos($_SERVER['SERVER_SOFTWARE'], 'apache') !== false && 
             (stripos($_SERVER['DOCUMENT_ROOT'], 'xampp') !== false || 
              stripos($_SERVER['DOCUMENT_ROOT'], 'wamp') !== false))) {
            return true;
        }
        
        // Check if running from command line (for our test script)
        if (php_sapi_name() === 'cli') {
            return true;
        }
        
        return false;
    }

    public function getConnection() {
        // Check if we already have a working connection
        if ($this->conn && $this->testConnection($this->conn)) {
            return $this->conn;
        }

        $this->conn = null;
        $connectionKey = $this->host . ':' . $this->db_name . ':' . $this->username;

        // Check connection pool first
        if (isset(self::$connectionPool[$connectionKey]) && 
            $this->testConnection(self::$connectionPool[$connectionKey])) {
            $this->conn = self::$connectionPool[$connectionKey];
            return $this->conn;
        }

        // For local environment, try simple connection first
        if ($this->isLocalEnvironment()) {
            try {
                $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4";
                error_log("Attempting local connection to: " . $dsn);
                
                $this->conn = new PDO($dsn, $this->username, $this->password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_TIMEOUT => 10,
                    PDO::ATTR_PERSISTENT => true, // Use persistent connections
                    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO', NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
                ]);
                
                // Test the connection
                $this->conn->query("SELECT 1");
                error_log("Local database connection successful!");
                
                // Store in connection pool
                self::$connectionPool[$connectionKey] = $this->conn;
                $this->conn->exec("SET time_zone = '+00:00'");
                return $this->conn;
                
            } catch(PDOException $e) {
                error_log("Local connection failed: " . $e->getMessage());
                error_log("Trying to create database...");
                
                // Try to create the database if it doesn't exist
                try {
                    $createDbConn = new PDO("mysql:host=" . $this->host . ";charset=utf8mb4", $this->username, $this->password);
                    $createDbConn->exec("CREATE DATABASE IF NOT EXISTS `" . $this->db_name . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    $createDbConn = null;
                    
                    // Try connecting again
                    $this->conn = new PDO($dsn, $this->username, $this->password, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                        PDO::ATTR_TIMEOUT => 10,
                        PDO::ATTR_PERSISTENT => true,
                        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
                    ]);
                    
                    error_log("Database created and connected successfully!");
                    self::$connectionPool[$connectionKey] = $this->conn;
                    $this->conn->exec("SET time_zone = '+00:00'");
                    return $this->conn;
                    
                } catch(PDOException $createE) {
                    error_log("Failed to create database: " . $createE->getMessage());
                }
            }
        } else {
            // Production connection logic (multiple password attempts)
            $passwordsToTry = [
                "CodoMail@8848",
                "CodoMail@88", 
                "codomail@8848",
                "CodoMail8848",
                "u262074081_bugfixer"
            ];

            foreach ($passwordsToTry as $password) {
                try {
                    $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8";
                    error_log("Attempting production connection with password variant...");
                    
                    $this->conn = new PDO($dsn, $this->username, $password, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                        PDO::ATTR_TIMEOUT => 10,
                        PDO::ATTR_PERSISTENT => true,
                        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
                    ]);
                    
                    // Test the connection
                    $this->conn->query("SELECT 1");
                    error_log("Production database connection successful!");
                    
                    // Store in connection pool
                    self::$connectionPool[$connectionKey] = $this->conn;
                    $this->conn->exec("SET time_zone = '+00:00'");
                    return $this->conn;
                    
                } catch(PDOException $e) {
                    error_log("Production password attempt failed: " . $e->getMessage());
                    continue;
                }
            }
            
            // If localhost failed, try alternative host
            foreach ($passwordsToTry as $password) {
                try {
                    error_log("Trying alternative host: auth-db1555.hstgr.io");
                    $altDsn = "mysql:host=auth-db1555.hstgr.io;dbname=" . $this->db_name . ";charset=utf8";
                    
                    $this->conn = new PDO($altDsn, $this->username, $password, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                        PDO::ATTR_TIMEOUT => 10,
                        PDO::ATTR_PERSISTENT => true
                    ]);
                    
                    $this->conn->query("SELECT 1");
                    error_log("Alternative host connection successful!");
                    self::$connectionPool[$connectionKey] = $this->conn;
                    $this->conn->exec("SET time_zone = '+00:00'");
                    return $this->conn;
                    
                } catch(PDOException $altE) {
                    error_log("Alternative host attempt failed: " . $altE->getMessage());
                    continue;
                }
            }
        }
        
        // All attempts failed
        error_log("All database connection attempts failed");
        
        // Only send JSON response if not running from CLI
        if (php_sapi_name() !== 'cli') {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode([
                "success" => false,
                "message" => "Database connection failed - please check credentials in hosting panel",
                "error" => "All connection attempts failed",
                "suggestion" => "Verify database credentials in your hosting control panel"
            ]);
            exit();
        } else {
            throw new Exception("Database connection failed");
        }
    }
    
    private function testConnection($conn) {
        try {
            $conn->query("SELECT 1");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    // Prepared statement caching
    private static $preparedStatements = [];
    
    public function prepare($query) {
        $hash = md5($query);
        if (!isset(self::$preparedStatements[$hash])) {
            self::$preparedStatements[$hash] = $this->conn->prepare($query);
        }
        return self::$preparedStatements[$hash];
    }
    
    // Optimized query methods
    public function fetchCached($query, $params = [], $cacheKey = null, $cacheTimeout = null) {
        if ($cacheKey) {
            $cached = self::getCache($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }
        
        $stmt = $this->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($cacheKey) {
            self::setCache($cacheKey, $result, $cacheTimeout);
        }
        
        return $result;
    }
    
    public function fetchSingleCached($query, $params = [], $cacheKey = null, $cacheTimeout = null) {
        if ($cacheKey) {
            $cached = self::getCache($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }
        
        $stmt = $this->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($cacheKey) {
            self::setCache($cacheKey, $result, $cacheTimeout);
        }
        
        return $result;
    }
}
?> 
