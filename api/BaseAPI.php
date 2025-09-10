<?php
date_default_timezone_set('UTC');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/utils.php';
require_once __DIR__ . '/../config/cors.php';

class BaseAPI {
    protected $conn;
    protected $utils;
    protected $database;
    
    public function __construct() {
        
        // Ensure logs directory exists
        // $logDir = __DIR__ . '/../../logs';
        // if (!is_dir($logDir)) {
        //     mkdir($logDir, 0777, true);
        // }
                
        // Set content type for JSON responses
        header('Content-Type: application/json');
        
        try {
            // Use singleton database instance for better connection management
            $this->database = Database::getInstance();
            $this->conn = $this->database->getConnection();
            
            if (!$this->conn) {
                throw new Exception("Database connection failed");
            }
            
            $this->utils = new Utils();
        } catch (Exception $e) {
            error_log("Database connection error: " . $e->getMessage());
            $this->sendJsonResponse(500, "Database connection failed: " . $e->getMessage());
        }
    }
    
    public function getConnection() {
        return $this->conn;
    }
    
    public function getDatabase() {
        return $this->database;
    }
    
    // Optimized query methods with caching
    public function fetchCached($query, $params = [], $cacheKey = null, $cacheTimeout = null) {
        return $this->database->fetchCached($query, $params, $cacheKey, $cacheTimeout);
    }
    
    public function fetchSingleCached($query, $params = [], $cacheKey = null, $cacheTimeout = null) {
        return $this->database->fetchSingleCached($query, $params, $cacheKey, $cacheTimeout);
    }
    
    // Prepared statement with caching
    public function prepare($query) {
        return $this->database->prepare($query);
    }
    
    // Cache management methods
    public function setCache($key, $value, $timeout = null) {
        Database::setCache($key, $value, $timeout);
    }
    
    public function getCache($key) {
        return Database::getCache($key);
    }
    
    public function clearCache($pattern = null) {
        Database::clearCache($pattern);
    }
    
    public function getRequestData() {
        try {
            $contentType = isset($_SERVER["CONTENT_TYPE"]) ? trim($_SERVER["CONTENT_TYPE"]) : '';
            
            if (stripos($contentType, 'application/json') !== false) {
                $content = file_get_contents("php://input");
                if ($content === false) {
                    $this->sendJsonResponse(400, "Failed to read request body");
                }
                
                $data = json_decode($content, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->sendJsonResponse(400, "Invalid JSON: " . json_last_error_msg());
                }
                
                return $data;
            }
            
            return $_POST;
        } catch (Exception $e) {
            $this->sendJsonResponse(400, "Failed to parse request data");
        }
    }

    public function sendJsonResponse($status_code, $message, $data = null, $success = null) {
        if (headers_sent()) {
            return;
        }
        
        http_response_code($status_code);
        
        $response = [
            "success" => $success !== null ? $success : ($status_code >= 200 && $status_code < 300),
            "message" => $message
        ];
        
        if ($data !== null) {
            $response["data"] = $data;
        }
        
        echo json_encode($response);
        exit();
    }

    public function validateToken() {
        // Cache token validation for 5 minutes
        $token = $this->getBearerToken();
        
        if (!$token) {
            throw new Exception('No token provided');
        }

        // Support admin impersonation via header or query param; include in cache key
        $impersonateId = $this->getImpersonateUserId();
        $cacheKey = 'token_validation_' . md5($token . '|' . ($impersonateId ?? 'none'));
        $cachedResult = $this->getCache($cacheKey);
        
        if ($cachedResult !== null) {
            return $cachedResult;
        }

        try {
            $result = $this->utils->validateJWT($token);

            // If admin and impersonation requested, switch identity after validating token
            if ($result && $impersonateId) {
                try {
                    if (isset($result->role) && $result->role === 'admin') {
                        error_log("🔑 Admin impersonation attempt - Target user ID: " . $impersonateId);
                        // Verify the target user exists and fetch username
                        $stmt = $this->conn->prepare("SELECT id, username FROM users WHERE id = ? LIMIT 1");
                        $stmt->execute([$impersonateId]);
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($row && isset($row['id'])) {
                            $originalUserId = $result->user_id;
                            $result->user_id = $row['id'];
                            $result->username = $row['username'] ?? ($result->username ?? null);
                            $result->impersonated = true;
                            error_log("✅ Impersonation successful - Original: $originalUserId, Now: " . $result->user_id . " (" . $result->username . ")");
                        } else {
                            error_log("❌ Impersonation failed - Target user not found: " . $impersonateId);
                        }
                    } else {
                        error_log("❌ Impersonation denied - User role is not admin: " . ($result->role ?? 'unknown'));
                    }
                } catch (Exception $e) {
                    error_log("❌ Impersonation error: " . $e->getMessage());
                }
            } else {
                error_log("🔍 No impersonation - Result: " . ($result ? 'valid' : 'null') . ", ImpersonateId: " . ($impersonateId ?? 'null'));
            }
        
            // Cache valid tokens for 5 minutes (keyed by token + impersonation)
            if ($result) {
                $this->setCache($cacheKey, $result, 300);
            }
        
            return $result;
        } catch (Exception $e) {
            $this->sendJsonResponse(500, "Token validation failed: " . $e->getMessage());
        }
    }

    protected function getImpersonateUserId() {
        // Check common header names and query params
        // Header: X-Impersonate-User or X-User-Id
        $headerId = null;
        if (isset($_SERVER['HTTP_X_IMPERSONATE_USER'])) {
            $headerId = trim($_SERVER['HTTP_X_IMPERSONATE_USER']);
        } elseif (isset($_SERVER['HTTP_X_USER_ID'])) {
            $headerId = trim($_SERVER['HTTP_X_USER_ID']);
        }
        
        // Also check for headers with different casing
        foreach ($_SERVER as $key => $value) {
            if (strtoupper($key) === 'HTTP_X_IMPERSONATE_USER') {
                $headerId = trim($value);
                break;
            } elseif (strtoupper($key) === 'HTTP_X_USER_ID') {
                $headerId = trim($value);
                break;
            }
        }
        
        // Query param: impersonate or act_as
        $queryId = null;
        if (isset($_GET['impersonate'])) {
            $queryId = trim($_GET['impersonate']);
        } elseif (isset($_GET['act_as'])) {
            $queryId = trim($_GET['act_as']);
        }
        
        // Check POST body for impersonation data
        $bodyId = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $data = $this->getRequestData();
                if (isset($data['impersonate_user_id'])) {
                    $bodyId = trim($data['impersonate_user_id']);
                }
            } catch (Exception $e) {
                // Ignore body parsing errors
            }
        }
        
        
        return $headerId ?: $queryId ?: $bodyId;
    }
    
    protected function getBearerToken() {
        $headers = $this->getAuthorizationHeader();
        
        if (!empty($headers)) {
            if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
                return $matches[1];
            }
        }
        return null;
    }
    
    protected function getAuthorizationHeader() {
        $headers = null;
        
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
        } elseif (isset($_SERVER['Authorization'])) {
            $headers = trim($_SERVER["Authorization"]);
        } elseif (function_exists('apache_request_headers')) {
            $requestHeaders = apache_request_headers();
            if (isset($requestHeaders['Authorization'])) {
                $headers = trim($requestHeaders['Authorization']);
            }
        }
        
        return $headers;
    }

    protected function handleRequest($callback) {
        // Start output buffering to catch any unwanted output
        ob_start();
        
        try {
            // Execute the callback (controller method)
            $callback();
        } catch (Exception $e) {
            // Clean buffer and send error response
            ob_end_clean();
            
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }
    
    // Batch query execution for reducing multiple DB calls
    public function executeBatch($queries) {
        $results = [];
        $this->conn->beginTransaction();
        
        try {
            foreach ($queries as $key => $query) {
                $stmt = $this->prepare($query['sql']);
                $stmt->execute($query['params'] ?? []);
                
                if (isset($query['fetch']) && $query['fetch']) {
                    if ($query['fetch'] === 'all') {
                        $results[$key] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    } else {
                        $results[$key] = $stmt->fetch(PDO::FETCH_ASSOC);
                    }
                } else {
                    $results[$key] = $stmt->rowCount();
                }
            }
            
            $this->conn->commit();
            return $results;
        } catch (Exception $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }
}
?>