<?php
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/utils.php';
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/PermissionManager.php';

class BaseAPI {
    protected $conn;
    protected $utils;
    protected $database;
    /** @var bool|null Lazy: whether users.account_active exists */
    protected $usersHasAccountActiveColumn = null;
    
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
                
                // Handle empty content gracefully
                if (empty(trim($content))) {
                    return [];
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

    public function sendJsonResponse($status_code, $message, $data = null, $success = null, $error_code = null) {
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

        if ($error_code !== null) {
            $response["error_code"] = $error_code;
        }
        
        echo json_encode($response);
        exit();
    }

    protected function usersTableHasAccountActiveColumn() {
        if ($this->usersHasAccountActiveColumn !== null) {
            return $this->usersHasAccountActiveColumn;
        }
        try {
            $res = $this->conn->query("SHOW COLUMNS FROM users LIKE 'account_active'");
            $this->usersHasAccountActiveColumn = $res && $res->rowCount() > 0;
        } catch (Exception $e) {
            $this->usersHasAccountActiveColumn = false;
        }
        return $this->usersHasAccountActiveColumn;
    }

    /**
     * Exit with 403 if user is missing or deactivated (when account_active column exists).
     */
    protected function ensureUserAccountAllowed($userId) {
        if (!$userId) {
            $this->sendJsonResponse(403, "Account no longer available.", null, false, 'ACCOUNT_REVOKED');
        }
        if ($this->usersTableHasAccountActiveColumn()) {
            $stmt = $this->conn->prepare("SELECT id FROM users WHERE id = ? AND account_active = 1 LIMIT 1");
        } else {
            $stmt = $this->conn->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
        }
        $stmt->execute([$userId]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->sendJsonResponse(403, "Account no longer available.", null, false, 'ACCOUNT_REVOKED');
        }
    }

    protected function dbTableExists($tableName) {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->execute([$tableName]);
        return (int)$stmt->fetchColumn() > 0;
    }

    protected function dbColumnExists($tableName, $columnName) {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$tableName, $columnName]);
        return (int)$stmt->fetchColumn() > 0;
    }

    protected function userCanAccessChatGroup($groupId, $userId, $userRole = null) {
        if ($userRole === 'admin') {
            return true;
        }

        $stmt = $this->conn->prepare("
            SELECT 1
            FROM chat_groups cg
            JOIN chat_group_members cgm ON cgm.group_id = cg.id
            WHERE cg.id = ?
              AND cg.is_active = 1
              AND cgm.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$groupId, $userId]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    protected function userCanAccessChatMessage($messageId, $userId, $userRole = null) {
        if ($userRole === 'admin') {
            return true;
        }

        $stmt = $this->conn->prepare("
            SELECT 1
            FROM chat_messages cm
            JOIN chat_groups cg ON cg.id = cm.group_id
            JOIN chat_group_members cgm ON cgm.group_id = cm.group_id
            WHERE cm.id = ?
              AND cm.is_deleted = 0
              AND cg.is_active = 1
              AND cgm.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$messageId, $userId]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function validateToken() {
        // Cache token validation for 5 minutes
        $token = $this->getBearerToken();
        
        if (!$token) {
            throw new Exception('No token provided');
        }

        // Support admin impersonation via header or query param; include in cache key
        $impersonateId = $this->getImpersonateUserId();
        
        // First decode the token to check if it's an impersonation token
        $tempResult = $this->utils->validateJWT($token);
        $isImpersonationToken = $tempResult && isset($tempResult->purpose) && $tempResult->purpose === 'dashboard_access' && isset($tempResult->admin_id);
        
        // Debug logging (avoid reading properties on false/null — fatal in PHP 8+)
        $tp = is_object($tempResult) ? ($tempResult->purpose ?? 'none') : 'invalid';
        $ta = is_object($tempResult) ? ($tempResult->admin_id ?? 'none') : 'none';
        error_log("🔍 BaseAPI::validateToken - Token purpose: " . $tp . ", Admin ID: " . $ta . ", Is impersonation: " . ($isImpersonationToken ? 'YES' : 'NO'));
        
        // Don't cache impersonation tokens to avoid conflicts
        if (!$isImpersonationToken) {
            $cacheKey = 'token_validation_' . md5($token . '|' . ($impersonateId ?? 'none'));
            $cachedResult = $this->getCache($cacheKey);
            
            if ($cachedResult !== null) {
                if (isset($cachedResult->user_id)) {
                    $this->ensureUserAccountAllowed($cachedResult->user_id);
                }
                return $cachedResult;
            }
        } else {
            // For impersonation tokens, clear any existing cache for this token
            $cacheKey = 'token_validation_' . md5($token . '|' . ($impersonateId ?? 'none'));
            $this->clearCache($cacheKey);
        }

        try {
            $result = $tempResult;

            // Handle impersonation token (dashboard access token with admin_id)
            if ($result && isset($result->purpose) && $result->purpose === 'dashboard_access' && isset($result->admin_id)) {
                try {
                    error_log("🔍 BaseAPI::validateToken - Processing impersonation token - Original user_id: " . $result->user_id . ", Admin ID: " . $result->admin_id);
                    
                    // This is an impersonation token - the user_id in the token is the impersonated user
                    // Fetch the impersonated user's actual role from database
                    $stmt = $this->conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
                    $stmt->execute([$result->user_id]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row && isset($row['role'])) {
                        $result->admin_role = $result->role ?? 'admin'; // Store original admin role
                        $result->role = $row['role']; // Update to impersonated user's role
                    }
                    $result->impersonated = true;
                    $result->admin_id = $result->admin_id; // Keep admin_id for logging
                    error_log("🔑 Impersonation token detected - Admin: " . $result->admin_id . ", Acting as: " . $result->user_id . " (" . $result->username . ", " . $result->role . ")");
                    error_log("🔍 BaseAPI::validateToken - Final result user_id: " . $result->user_id);
                } catch (Exception $e) {
                    error_log("❌ Impersonation token processing error: " . $e->getMessage());
                }
            }
            // Handle manual impersonation via header/query param (for direct API calls)
            else if ($result && $impersonateId) {
                try {
                    if (isset($result->role) && $result->role === 'admin') {
                        error_log("🔑 Manual admin impersonation attempt - Target user ID: " . $impersonateId);
                        // Verify the target user exists and fetch username and role
                        $stmt = $this->conn->prepare("SELECT id, username, role FROM users WHERE id = ? LIMIT 1");
                        $stmt->execute([$impersonateId]);
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($row && isset($row['id'])) {
                            $originalUserId = $result->user_id;
                            $originalRole = $result->role;
                            $result->user_id = $row['id'];
                            $result->username = $row['username'] ?? ($result->username ?? null);
                            $result->role = $row['role'] ?? $result->role; // Update role to impersonated user's role
                            $result->impersonated = true;
                            $result->admin_id = $originalUserId; // Store original admin ID
                            $result->admin_role = $originalRole; // Store original admin role
                            error_log("✅ Manual impersonation successful - Original: $originalUserId ($originalRole), Now: " . $result->user_id . " (" . $result->username . ", " . $result->role . ")");
                        } else {
                            error_log("❌ Manual impersonation failed - Target user not found: " . $impersonateId);
                        }
                    } else {
                        error_log("❌ Manual impersonation denied - User role is not admin: " . ($result->role ?? 'unknown'));
                    }
                } catch (Exception $e) {
                    error_log("❌ Manual impersonation error: " . $e->getMessage());
                }
            } else {
                $rp = is_object($result) ? ($result->purpose ?? 'none') : 'invalid';
                error_log("🔍 No impersonation - Result: " . (is_object($result) ? 'valid' : 'invalid') . ", ImpersonateId: " . ($impersonateId ?? 'null') . ", Purpose: " . $rp);
            }
        
            if ($result && isset($result->user_id)) {
                $this->ensureUserAccountAllowed($result->user_id);
            }

            // Cache valid tokens for 5 minutes (keyed by token + impersonation)
            // Don't cache impersonation tokens to avoid conflicts (only after account check)
            if ($result && !$isImpersonationToken) {
                $cacheKey = 'token_validation_' . md5($token . '|' . ($impersonateId ?? 'none'));
                $this->setCache($cacheKey, $result, 300);
            }

            return $result;
        } catch (Exception $e) {
            $msg = $e->getMessage();
            $code = (strpos($msg, 'expired') !== false || strpos($msg, 'invalid') !== false || strpos($msg, 'No token') !== false) ? 401 : 500;
            $this->sendJsonResponse($code, "Token validation failed: " . $msg);
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
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers = trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
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
    
    /**
     * Require permission - throws 403 if user doesn't have permission
     * 
     * @param string $permissionKey Permission key (e.g., 'BUGS_CREATE')
     * @param string|null $projectId Optional project ID for project-scoped permissions
     * @return void Throws exception if permission denied
     */
    public function requirePermission($permissionKey, $projectId = null) {
        try {
            // Get current user from token
            $decoded = $this->validateToken();
            if (!$decoded || !isset($decoded->user_id)) {
                throw new Exception('Authentication required');
            }
            
            $userId = $decoded->user_id;
            
            // Check permission
            $pm = PermissionManager::getInstance();
            if (!$pm->hasPermission($userId, $permissionKey, $projectId)) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'message' => 'Access denied. You do not have permission to perform this action.',
                    'required_permission' => $permissionKey
                ]);
                exit();
            }
            
        } catch (Exception $e) {
            error_log("Permission check error: " . $e->getMessage());
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Access denied. Permission check failed.',
                'error' => $e->getMessage()
            ]);
            exit();
        }
    }
}
?>