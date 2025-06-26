<?php
/**
 * Advanced Database Optimization Configuration
 * For Bug Tracking System - bugs.moajmalnk.in
 */

class DatabaseOptimization {
    // Redis configuration for advanced caching
    private static $redis = null;
    private static $redisEnabled = false;
    
    // Query performance monitoring
    private static $queryStats = [];
    private static $slowQueryThreshold = 0.1; // 100ms
    
    // Connection pooling configuration
    private static $maxConnections = 20;
    private static $connectionTimeout = 30;
    private static $idleTimeout = 300;
    
    // Cache configuration
    private static $cacheConfig = [
        'user_data' => 3600,        // 1 hour
        'project_data' => 1800,     // 30 minutes
        'bug_lists' => 300,         // 5 minutes
        'activity_feeds' => 600,    // 10 minutes
        'statistics' => 900,        // 15 minutes
        'settings' => 7200,         // 2 hours
    ];
    
    /**
     * Initialize Redis for advanced caching
     */
    public static function initRedis() {
        try {
            if (extension_loaded('redis')) {
                self::$redis = new Redis();
                self::$redis->connect('127.0.0.1', 6379, 1);
                self::$redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_IGBINARY);
                self::$redisEnabled = true;
                error_log("Redis cache initialized successfully");
            }
        } catch (Exception $e) {
            error_log("Redis initialization failed: " . $e->getMessage());
            self::$redisEnabled = false;
        }
    }
    
    /**
     * Advanced caching with Redis fallback
     */
    public static function getCache($key, $default = null) {
        // Try Redis first
        if (self::$redisEnabled) {
            try {
                $value = self::$redis->get($key);
                if ($value !== false) {
                    self::recordCacheHit($key);
                    return $value;
                }
            } catch (Exception $e) {
                error_log("Redis get error: " . $e->getMessage());
            }
        }
        
        // Fallback to memory cache
        return Database::getCache($key) ?? $default;
    }
    
    /**
     * Set cache with intelligent TTL
     */
    public static function setCache($key, $value, $ttl = null) {
        // Determine TTL based on key pattern
        if ($ttl === null) {
            $ttl = self::getCacheTTL($key);
        }
        
        // Try Redis first
        if (self::$redisEnabled) {
            try {
                self::$redis->setex($key, $ttl, $value);
                return true;
            } catch (Exception $e) {
                error_log("Redis set error: " . $e->getMessage());
            }
        }
        
        // Fallback to memory cache
        Database::setCache($key, $value, $ttl);
        return true;
    }
    
    /**
     * Intelligent cache TTL based on data type
     */
    private static function getCacheTTL($key) {
        foreach (self::$cacheConfig as $pattern => $ttl) {
            if (strpos($key, $pattern) !== false) {
                return $ttl;
            }
        }
        return 300; // Default 5 minutes
    }
    
    /**
     * Batch cache operations for better performance
     */
    public static function getMultipleCache($keys) {
        $results = [];
        
        if (self::$redisEnabled) {
            try {
                $values = self::$redis->mget($keys);
                foreach ($keys as $i => $key) {
                    $results[$key] = $values[$i] !== false ? $values[$i] : null;
                }
            } catch (Exception $e) {
                error_log("Redis mget error: " . $e->getMessage());
            }
        }
        
        // Fallback for missing keys
        foreach ($keys as $key) {
            if (!isset($results[$key]) || $results[$key] === null) {
                $results[$key] = Database::getCache($key);
            }
        }
        
        return $results;
    }
    
    /**
     * Optimized query execution with monitoring
     */
    public static function executeOptimizedQuery($pdo, $query, $params = [], $cacheKey = null) {
        $startTime = microtime(true);
        
        try {
            // Check cache first
            if ($cacheKey) {
                $cached = self::getCache($cacheKey);
                if ($cached !== null) {
                    return $cached;
                }
            }
            
            // Execute query
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Cache result
            if ($cacheKey) {
                self::setCache($cacheKey, $result);
            }
            
            // Record performance
            $executionTime = microtime(true) - $startTime;
            self::recordQueryPerformance($query, $executionTime);
            
            return $result;
            
        } catch (Exception $e) {
            self::recordQueryError($query, $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Record query performance for optimization
     */
    private static function recordQueryPerformance($query, $executionTime) {
        $queryHash = md5($query);
        
        if (!isset(self::$queryStats[$queryHash])) {
            self::$queryStats[$queryHash] = [
                'count' => 0,
                'total_time' => 0,
                'avg_time' => 0,
                'max_time' => 0,
                'min_time' => PHP_FLOAT_MAX,
                'slow_queries' => 0
            ];
        }
        
        $stats = &self::$queryStats[$queryHash];
        $stats['count']++;
        $stats['total_time'] += $executionTime;
        $stats['avg_time'] = $stats['total_time'] / $stats['count'];
        $stats['max_time'] = max($stats['max_time'], $executionTime);
        $stats['min_time'] = min($stats['min_time'], $executionTime);
        
        if ($executionTime > self::$slowQueryThreshold) {
            $stats['slow_queries']++;
            error_log("Slow query detected: {$executionTime}s - " . substr($query, 0, 100));
        }
    }
    
    /**
     * Record query errors for debugging
     */
    private static function recordQueryError($query, $error) {
        error_log("Query error: " . $error . " - Query: " . substr($query, 0, 200));
    }
    
    /**
     * Record cache hit/miss statistics
     */
    private static function recordCacheHit($key) {
        // Implementation for cache hit tracking
    }
    
    /**
     * Get performance statistics
     */
    public static function getPerformanceStats() {
        return [
            'query_stats' => self::$queryStats,
            'redis_enabled' => self::$redisEnabled,
            'cache_config' => self::$cacheConfig,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];
    }
    
    /**
     * Clear all caches
     */
    public static function clearAllCaches() {
        if (self::$redisEnabled) {
            try {
                self::$redis->flushDB();
            } catch (Exception $e) {
                error_log("Redis flush error: " . $e->getMessage());
            }
        }
        
        Database::clearCache();
        self::$queryStats = [];
    }
    
    /**
     * Optimize database connection settings
     */
    public static function getOptimizedPDOOptions() {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => true,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            PDO::MYSQL_ATTR_INIT_COMMAND => "
                SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO';
                SET SESSION query_cache_type = ON;
                SET SESSION optimizer_switch = 'index_merge=on,index_merge_union=on,index_merge_sort_union=on,index_merge_intersection=on';
                SET SESSION join_buffer_size = 1048576;
                SET SESSION sort_buffer_size = 2097152;
                SET SESSION read_buffer_size = 1048576;
            "
        ];
    }
}

// Initialize Redis on script load
DatabaseOptimization::initRedis();
?> 