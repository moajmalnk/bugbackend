<?php
/**
 * Advanced Caching Strategy for Bug Tracking System
 * Implements multi-layer caching with Redis, memory, and intelligent invalidation
 */

class CacheStrategy {
    // Cache layers
    private static $redis = null;
    private static $memoryCache = [];
    private static $cacheStats = [
        'redis_hits' => 0,
        'redis_misses' => 0,
        'memory_hits' => 0,
        'memory_misses' => 0,
        'total_requests' => 0
    ];
    
    // Cache configuration
    private static $config = [
        'redis' => [
            'host' => '127.0.0.1',
            'port' => 6379,
            'timeout' => 1,
            'retry_interval' => 100,
            'read_timeout' => 0,
        ],
        'memory' => [
            'max_size' => 1000, // Maximum number of items in memory cache
            'cleanup_interval' => 300, // Cleanup every 5 minutes
        ],
        'ttl' => [
            'user_data' => 3600,        // 1 hour
            'project_data' => 1800,     // 30 minutes
            'bug_lists' => 300,         // 5 minutes
            'bug_details' => 1800,      // 30 minutes
            'activity_feeds' => 600,    // 10 minutes
            'statistics' => 900,        // 15 minutes
            'settings' => 7200,         // 2 hours
            'attachments' => 3600,      // 1 hour
            'search_results' => 180,    // 3 minutes
            'api_responses' => 60,      // 1 minute
        ]
    ];
    
    // Cache invalidation patterns
    private static $invalidationPatterns = [
        'user_updated' => ['user_*', 'user_stats_*'],
        'project_updated' => ['project_*', 'project_bugs_*', 'project_stats_*'],
        'bug_created' => ['bugs_list_*', 'bug_stats_*', 'user_bugs_*'],
        'bug_updated' => ['bug_detail_*', 'bugs_list_*', 'bug_stats_*'],
        'bug_deleted' => ['bug_*', 'bugs_list_*', 'bug_stats_*'],
        'activity_logged' => ['activity_*', 'user_activity_*'],
        'settings_changed' => ['settings_*', 'config_*'],
    ];
    
    /**
     * Initialize Redis connection
     */
    public static function initRedis() {
        try {
            if (extension_loaded('redis')) {
                self::$redis = new Redis();
                self::$redis->connect(
                    self::$config['redis']['host'],
                    self::$config['redis']['port'],
                    self::$config['redis']['timeout']
                );
                
                // Set Redis options
                self::$redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_IGBINARY);
                self::$redis->setOption(Redis::OPT_PREFIX, 'bugtracker:');
                self::$redis->setOption(Redis::OPT_READ_TIMEOUT, self::$config['redis']['read_timeout']);
                
                // Test connection
                self::$redis->ping();
                error_log("Redis cache initialized successfully");
                return true;
            }
        } catch (Exception $e) {
            error_log("Redis initialization failed: " . $e->getMessage());
            self::$redis = null;
        }
        return false;
    }
    
    /**
     * Get data from cache with fallback strategy
     */
    public static function get($key, $default = null) {
        self::$cacheStats['total_requests']++;
        
        // Try Redis first
        if (self::$redis) {
            try {
                $value = self::$redis->get($key);
                if ($value !== false) {
                    self::$cacheStats['redis_hits']++;
                    return $value;
                }
                self::$cacheStats['redis_misses']++;
            } catch (Exception $e) {
                error_log("Redis get error: " . $e->getMessage());
                self::$cacheStats['redis_misses']++;
            }
        }
        
        // Fallback to memory cache
        if (isset(self::$memoryCache[$key])) {
            $cached = self::$memoryCache[$key];
            if ($cached['expires'] > time()) {
                self::$cacheStats['memory_hits']++;
                return $cached['data'];
            } else {
                unset(self::$memoryCache[$key]);
            }
        }
        
        self::$cacheStats['memory_misses']++;
        return $default;
    }
    
    /**
     * Set data in cache with intelligent TTL
     */
    public static function set($key, $value, $ttl = null) {
        // Determine TTL based on key pattern
        if ($ttl === null) {
            $ttl = self::getTTL($key);
        }
        
        // Store in Redis first
        if (self::$redis) {
            try {
                self::$redis->setex($key, $ttl, $value);
            } catch (Exception $e) {
                error_log("Redis set error: " . $e->getMessage());
            }
        }
        
        // Store in memory cache as backup
        self::setMemoryCache($key, $value, min($ttl, 300)); // Max 5 min in memory
        
        return true;
    }
    
    /**
     * Get multiple cache keys efficiently
     */
    public static function getMultiple($keys) {
        $results = [];
        
        // Try Redis mget first
        if (self::$redis && !empty($keys)) {
            try {
                $values = self::$redis->mget($keys);
                foreach ($keys as $i => $key) {
                    $results[$key] = $values[$i] !== false ? $values[$i] : null;
                }
            } catch (Exception $e) {
                error_log("Redis mget error: " . $e->getMessage());
            }
        }
        
        // Fill missing values from memory cache
        foreach ($keys as $key) {
            if (!isset($results[$key]) || $results[$key] === null) {
                $results[$key] = self::get($key);
            }
        }
        
        return $results;
    }
    
    /**
     * Set multiple cache keys efficiently
     */
    public static function setMultiple($data, $ttl = null) {
        if (self::$redis) {
            try {
                $pipeline = self::$redis->multi(Redis::PIPELINE);
                foreach ($data as $key => $value) {
                    $keyTtl = $ttl ?? self::getTTL($key);
                    $pipeline->setex($key, $keyTtl, $value);
                }
                $pipeline->exec();
            } catch (Exception $e) {
                error_log("Redis pipeline error: " . $e->getMessage());
            }
        }
        
        // Set in memory cache
        foreach ($data as $key => $value) {
            $keyTtl = $ttl ?? self::getTTL($key);
            self::setMemoryCache($key, $value, min($keyTtl, 300));
        }
        
        return true;
    }
    
    /**
     * Delete cache key
     */
    public static function delete($key) {
        $deleted = false;
        
        // Delete from Redis
        if (self::$redis) {
            try {
                $deleted = self::$redis->del($key) > 0;
            } catch (Exception $e) {
                error_log("Redis delete error: " . $e->getMessage());
            }
        }
        
        // Delete from memory cache
        if (isset(self::$memoryCache[$key])) {
            unset(self::$memoryCache[$key]);
            $deleted = true;
        }
        
        return $deleted;
    }
    
    /**
     * Clear cache by pattern
     */
    public static function clear($pattern = null) {
        $cleared = 0;
        
        // Clear Redis by pattern
        if (self::$redis) {
            try {
                if ($pattern) {
                    $keys = self::$redis->keys($pattern);
                    if (!empty($keys)) {
                        $cleared += self::$redis->del($keys);
                    }
                } else {
                    self::$redis->flushDB();
                    $cleared = -1; // Indicates full flush
                }
            } catch (Exception $e) {
                error_log("Redis clear error: " . $e->getMessage());
            }
        }
        
        // Clear memory cache by pattern
        if ($pattern) {
            foreach (self::$memoryCache as $key => $value) {
                if (strpos($key, $pattern) !== false) {
                    unset(self::$memoryCache[$key]);
                    $cleared++;
                }
            }
        } else {
            self::$memoryCache = [];
            $cleared = -1; // Indicates full flush
        }
        
        return $cleared;
    }
    
    /**
     * Intelligent cache invalidation based on events
     */
    public static function invalidateByEvent($event) {
        if (!isset(self::$invalidationPatterns[$event])) {
            return 0;
        }
        
        $patterns = self::$invalidationPatterns[$event];
        $cleared = 0;
        
        foreach ($patterns as $pattern) {
            $cleared += self::clear($pattern);
        }
        
        error_log("Cache invalidation for event '{$event}': {$cleared} keys cleared");
        return $cleared;
    }
    
    /**
     * Get cache statistics
     */
    public static function getStats() {
        $redisStats = [];
        if (self::$redis) {
            try {
                $redisStats = self::$redis->info();
            } catch (Exception $e) {
                error_log("Redis info error: " . $e->getMessage());
            }
        }
        
        $totalRequests = self::$cacheStats['total_requests'];
        $redisHitRate = $totalRequests > 0 ? 
            (self::$cacheStats['redis_hits'] / $totalRequests) * 100 : 0;
        $memoryHitRate = $totalRequests > 0 ? 
            (self::$cacheStats['memory_hits'] / $totalRequests) * 100 : 0;
        
        return [
            'redis' => [
                'enabled' => self::$redis !== null,
                'hits' => self::$cacheStats['redis_hits'],
                'misses' => self::$cacheStats['redis_misses'],
                'hit_rate' => round($redisHitRate, 2) . '%',
                'info' => $redisStats,
            ],
            'memory' => [
                'hits' => self::$cacheStats['memory_hits'],
                'misses' => self::$cacheStats['memory_misses'],
                'hit_rate' => round($memoryHitRate, 2) . '%',
                'size' => count(self::$memoryCache),
                'max_size' => self::$config['memory']['max_size'],
            ],
            'total_requests' => $totalRequests,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
        ];
    }
    
    /**
     * Cleanup expired memory cache entries
     */
    public static function cleanup() {
        $now = time();
        $cleaned = 0;
        
        foreach (self::$memoryCache as $key => $data) {
            if ($data['expires'] <= $now) {
                unset(self::$memoryCache[$key]);
                $cleaned++;
            }
        }
        
        // Limit memory cache size
        if (count(self::$memoryCache) > self::$config['memory']['max_size']) {
            $excess = count(self::$memoryCache) - self::$config['memory']['max_size'];
            $keys = array_keys(self::$memoryCache);
            for ($i = 0; $i < $excess; $i++) {
                unset(self::$memoryCache[$keys[$i]]);
                $cleaned++;
            }
        }
        
        return $cleaned;
    }
    
    /**
     * Determine TTL based on key pattern
     */
    private static function getTTL($key) {
        foreach (self::$config['ttl'] as $pattern => $ttl) {
            if (strpos($key, $pattern) !== false) {
                return $ttl;
            }
        }
        return 300; // Default 5 minutes
    }
    
    /**
     * Set data in memory cache
     */
    private static function setMemoryCache($key, $value, $ttl) {
        self::$memoryCache[$key] = [
            'data' => $value,
            'expires' => time() + $ttl
        ];
        
        // Cleanup if cache is too large
        if (count(self::$memoryCache) > self::$config['memory']['max_size']) {
            self::cleanup();
        }
    }
    
    /**
     * Cache warming for frequently accessed data
     */
    public static function warmCache($pdo) {
        try {
            // Warm user data cache
            $users = $pdo->query("SELECT id, username, email, role FROM users LIMIT 100")->fetchAll();
            foreach ($users as $user) {
                self::set("user_{$user['id']}", json_encode($user), self::$config['ttl']['user_data']);
            }
            
            // Warm project data cache
            $projects = $pdo->query("SELECT id, name, description, status, created_by FROM projects LIMIT 50")->fetchAll();
            foreach ($projects as $project) {
                self::set("project_{$project['id']}", json_encode($project), self::$config['ttl']['project_data']);
            }
            
            // Warm settings cache
            $settings = $pdo->query("SELECT key_name, value FROM settings")->fetchAll();
            foreach ($settings as $setting) {
                self::set("setting_{$setting['key_name']}", $setting['value'], self::$config['ttl']['settings']);
            }
            
            error_log("Cache warming completed successfully");
            return true;
            
        } catch (Exception $e) {
            error_log("Cache warming failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Health check for cache systems
     */
    public static function healthCheck() {
        $health = [
            'redis' => false,
            'memory' => true,
            'overall' => false
        ];
        
        // Check Redis
        if (self::$redis) {
            try {
                self::$redis->ping();
                $health['redis'] = true;
            } catch (Exception $e) {
                error_log("Redis health check failed: " . $e->getMessage());
            }
        }
        
        // Check memory cache
        $health['memory'] = count(self::$memoryCache) <= self::$config['memory']['max_size'];
        
        // Overall health
        $health['overall'] = $health['redis'] || $health['memory'];
        
        return $health;
    }
}

// Initialize Redis on script load
CacheStrategy::initRedis();

// Setup periodic cleanup
if (!isset($GLOBALS['cache_cleanup_setup'])) {
    $GLOBALS['cache_cleanup_setup'] = true;
    
    // Cleanup every 5 minutes
    register_shutdown_function(function() {
        CacheStrategy::cleanup();
    });
}
?> 