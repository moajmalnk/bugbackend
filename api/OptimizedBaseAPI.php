<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/utils.php';
require_once __DIR__ . '/../config/cors.php';

class OptimizedBaseAPI {
    protected $conn;
    protected $utils;
    protected $database;
    
    // Advanced caching layer with Redis fallback
    private static $memoryCache = [];
    private static $cacheStats = ['hits' => 0, 'misses' => 0];
    
    // Connection pooling
    private static $connectionPool = [];
    private static $maxConnections = 10;
    
    // Query optimization
    private static $queryCache = [];
    private static $preparedStatements = [];
    
    public function __construct() {
        $logDir = __DIR__ . '/../../logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        
        header('Content-Type: application/json');
        
        try {
            $this->database = Database::getInstance();
            $this->conn = $this->getOptimizedConnection();
            
            if (!$this->conn) {
                throw new Exception("Database connection failed");
            }
            
            $this->utils = new Utils();
        } catch (Exception $e) {
            error_log("Database connection error: " . $e->getMessage());
            $this->sendJsonResponse(500, "Database connection failed: " . $e->getMessage());
        }
    }
    
    /**
     * Optimized connection management with pooling
     */
    private function getOptimizedConnection() {
        $connectionKey = 'default';
        
        // Check if we have available connections in pool
        if (isset(self::$connectionPool[$connectionKey]) && 
            count(self::$connectionPool[$connectionKey]) > 0) {
            $conn = array_pop(self::$connectionPool[$connectionKey]);
            if ($this->isConnectionAlive($conn)) {
                return $conn;
            }
        }
        
        // Create new connection with optimized settings
        $conn = $this->database->getConnection();
        if ($conn) {
            // Set optimized connection settings
            $conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $conn->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            $conn->setAttribute(PDO::ATTR_PERSISTENT, true);
            $conn->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
            $conn->exec("SET SESSION query_cache_type = ON");
            $conn->exec("SET SESSION optimizer_switch = 'index_merge=on,index_merge_union=on'");
        }
        
        return $conn;
    }
    
    /**
     * Check if connection is still alive
     */
    private function isConnectionAlive($conn) {
        try {
            $conn->query("SELECT 1");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Advanced caching with multiple layers
     */
    public function fetchCachedAdvanced($query, $params = [], $cacheKey = null, $cacheTimeout = 300) {
        $cacheKey = $cacheKey ?: $this->generateCacheKey($query, $params);
        
        // Level 1: Memory cache (fastest)
        if (isset(self::$memoryCache[$cacheKey])) {
            $cached = self::$memoryCache[$cacheKey];
            if ($cached['expires'] > time()) {
                self::$cacheStats['hits']++;
                return $cached['data'];
            } else {
                unset(self::$memoryCache[$cacheKey]);
            }
        }
        
        // Level 2: Database cache
        $cachedResult = $this->database->getCache($cacheKey);
        if ($cachedResult !== null) {
            // Store in memory cache for faster subsequent access
            self::$memoryCache[$cacheKey] = [
                'data' => $cachedResult,
                'expires' => time() + min($cacheTimeout, 300) // Max 5 min in memory
            ];
            self::$cacheStats['hits']++;
            return $cachedResult;
        }
        
        // Cache miss - execute query
        self::$cacheStats['misses']++;
        $stmt = $this->prepareOptimized($query);
        $stmt->execute($params);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Store in both cache layers
        $this->database->setCache($cacheKey, $result, $cacheTimeout);
        self::$memoryCache[$cacheKey] = [
            'data' => $result,
            'expires' => time() + min($cacheTimeout, 300)
        ];
        
        return $result;
    }
    
    /**
     * Optimized prepared statement with caching
     */
    public function prepareOptimized($query) {
        $queryHash = md5($query);
        
        if (isset(self::$preparedStatements[$queryHash])) {
            return self::$preparedStatements[$queryHash];
        }
        
        $stmt = $this->conn->prepare($query);
        
        // Cache prepared statements (limit to 100 to avoid memory issues)
        if (count(self::$preparedStatements) < 100) {
            self::$preparedStatements[$queryHash] = $stmt;
        }
        
        return $stmt;
    }
    
    /**
     * Batch operations for reducing database round trips
     */
    public function executeBatchOptimized($operations) {
        $results = [];
        $this->conn->beginTransaction();
        
        try {
            // Group operations by type for better performance
            $selects = [];
            $inserts = [];
            $updates = [];
            $deletes = [];
            
            foreach ($operations as $key => $operation) {
                $sql = strtoupper(trim($operation['sql']));
                if (strpos($sql, 'SELECT') === 0) {
                    $selects[$key] = $operation;
                } elseif (strpos($sql, 'INSERT') === 0) {
                    $inserts[$key] = $operation;
                } elseif (strpos($sql, 'UPDATE') === 0) {
                    $updates[$key] = $operation;
                } elseif (strpos($sql, 'DELETE') === 0) {
                    $deletes[$key] = $operation;
                }
            }
            
            // Execute selects first (can be cached)
            foreach ($selects as $key => $operation) {
                $cacheKey = isset($operation['cache_key']) ? $operation['cache_key'] : null;
                $cacheTimeout = isset($operation['cache_timeout']) ? $operation['cache_timeout'] : 300;
                
                if ($cacheKey) {
                    $results[$key] = $this->fetchCachedAdvanced(
                        $operation['sql'], 
                        $operation['params'] ?? [], 
                        $cacheKey, 
                        $cacheTimeout
                    );
                } else {
                    $stmt = $this->prepareOptimized($operation['sql']);
                    $stmt->execute($operation['params'] ?? []);
                    $results[$key] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }
            
            // Execute modifications in order
            foreach (array_merge($inserts, $updates, $deletes) as $key => $operation) {
                $stmt = $this->prepareOptimized($operation['sql']);
                $stmt->execute($operation['params'] ?? []);
                
                if (isset($operation['fetch']) && $operation['fetch']) {
                    if ($operation['fetch'] === 'all') {
                        $results[$key] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    } else {
                        $results[$key] = $stmt->fetch(PDO::FETCH_ASSOC);
                    }
                } else {
                    $results[$key] = $stmt->rowCount();
                }
                
                // Invalidate related caches
                if (isset($operation['invalidate_cache'])) {
                    $this->invalidateCache($operation['invalidate_cache']);
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
     * Smart cache invalidation
     */
    public function invalidateCache($patterns) {
        if (!is_array($patterns)) {
            $patterns = [$patterns];
        }
        
        foreach ($patterns as $pattern) {
            // Clear from memory cache
            foreach (self::$memoryCache as $key => $value) {
                if (strpos($key, $pattern) !== false) {
                    unset(self::$memoryCache[$key]);
                }
            }
            
            // Clear from database cache
            $this->database->clearCache($pattern);
        }
    }
    
    /**
     * Generate intelligent cache key
     */
    private function generateCacheKey($query, $params) {
        $normalizedQuery = preg_replace('/\s+/', ' ', trim($query));
        return 'query_' . md5($normalizedQuery . serialize($params));
    }
    
    /**
     * Connection pooling - return connection to pool
     */
    public function returnConnection($conn) {
        $connectionKey = 'default';
        
        if (!isset(self::$connectionPool[$connectionKey])) {
            self::$connectionPool[$connectionKey] = [];
        }
        
        if (count(self::$connectionPool[$connectionKey]) < self::$maxConnections) {
            self::$connectionPool[$connectionKey][] = $conn;
        }
    }
    
    /**
     * Get cache statistics
     */
    public function getCacheStats() {
        $total = self::$cacheStats['hits'] + self::$cacheStats['misses'];
        $hitRatio = $total > 0 ? (self::$cacheStats['hits'] / $total) * 100 : 0;
        
        return [
            'hits' => self::$cacheStats['hits'],
            'misses' => self::$cacheStats['misses'],
            'hit_ratio' => round($hitRatio, 2) . '%',
            'memory_cache_size' => count(self::$memoryCache),
            'prepared_statements_cached' => count(self::$preparedStatements)
        ];
    }
    
    /**
     * Optimized bulk insert
     */
    public function bulkInsert($table, $data, $updateOnDuplicate = false) {
        if (empty($data)) {
            return 0;
        }
        
        $columns = array_keys($data[0]);
        $placeholders = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $allPlaceholders = implode(',', array_fill(0, count($data), $placeholders));
        
        $sql = "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES $allPlaceholders";
        
        if ($updateOnDuplicate) {
            $updateClause = [];
            foreach ($columns as $column) {
                $updateClause[] = "`$column` = VALUES(`$column`)";
            }
            $sql .= " ON DUPLICATE KEY UPDATE " . implode(', ', $updateClause);
        }
        
        $params = [];
        foreach ($data as $row) {
            foreach ($columns as $column) {
                $params[] = $row[$column];
            }
        }
        
        $stmt = $this->prepareOptimized($sql);
        $stmt->execute($params);
        
        return $stmt->rowCount();
    }
    
    // Original methods with optimizations
    public function getConnection() {
        return $this->conn;
    }
    
    public function getDatabase() {
        return $this->database;
    }
    
    public function fetchCached($query, $params = [], $cacheKey = null, $cacheTimeout = null) {
        return $this->fetchCachedAdvanced($query, $params, $cacheKey, $cacheTimeout ?? 300);
    }
    
    public function fetchSingleCached($query, $params = [], $cacheKey = null, $cacheTimeout = null) {
        $result = $this->fetchCachedAdvanced($query, $params, $cacheKey, $cacheTimeout ?? 300);
        return $result ? $result[0] : null;
    }
    
    public function prepare($query) {
        return $this->prepareOptimized($query);
    }
    
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
        
        // Add cache stats in debug mode
        if (defined('DEBUG') && DEBUG) {
            $response["cache_stats"] = $this->getCacheStats();
        }
        
        echo json_encode($response);
        exit();
    }

    public function validateToken() {
        $token = $this->getBearerToken();
        
        if (!$token) {
            throw new Exception('No token provided');
        }

        $cacheKey = 'token_validation_' . md5($token);
        $cachedResult = $this->getCache($cacheKey);
        
        if ($cachedResult !== null) {
            return $cachedResult;
        }

        $result = $this->utils->validateJWT($token);
        
        if ($result) {
            $this->setCache($cacheKey, $result, 300);
        }
        
        return $result;
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
        ob_start();
        
        try {
            $callback();
        } catch (Exception $e) {
            ob_end_clean();
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }
    
    // Compatibility method
    public function executeBatch($queries) {
        return $this->executeBatchOptimized($queries);
    }
    
    /**
     * Cleanup method to clear memory caches periodically
     */
    public static function cleanup() {
        $now = time();
        foreach (self::$memoryCache as $key => $data) {
            if ($data['expires'] <= $now) {
                unset(self::$memoryCache[$key]);
            }
        }
        
        // Reset stats if they get too high
        if (self::$cacheStats['hits'] + self::$cacheStats['misses'] > 10000) {
            self::$cacheStats = ['hits' => 0, 'misses' => 0];
        }
    }
}
?> 