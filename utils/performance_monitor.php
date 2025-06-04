<?php
/**
 * Performance Monitoring Utility
 * This class helps track database performance and cache effectiveness
 */

require_once __DIR__ . '/../config/database.php';

class PerformanceMonitor {
    private static $queryTimes = [];
    private static $cacheStats = ['hits' => 0, 'misses' => 0];
    private static $startTime;
    
    public static function startTimer($label = 'default') {
        self::$queryTimes[$label] = microtime(true);
    }
    
    public static function endTimer($label = 'default') {
        if (isset(self::$queryTimes[$label])) {
            $endTime = microtime(true);
            $executionTime = ($endTime - self::$queryTimes[$label]) * 1000; // Convert to milliseconds
            error_log("Query [{$label}] executed in: {$executionTime}ms");
            return $executionTime;
        }
        return 0;
    }
    
    public static function recordCacheHit() {
        self::$cacheStats['hits']++;
    }
    
    public static function recordCacheMiss() {
        self::$cacheStats['misses']++;
    }
    
    public static function getCacheStats() {
        $total = self::$cacheStats['hits'] + self::$cacheStats['misses'];
        $hitRate = $total > 0 ? (self::$cacheStats['hits'] / $total) * 100 : 0;
        
        return [
            'hits' => self::$cacheStats['hits'],
            'misses' => self::$cacheStats['misses'],
            'hit_rate' => round($hitRate, 2) . '%'
        ];
    }
    
    public static function logSlowQueries($threshold = 100) { // milliseconds
        foreach (self::$queryTimes as $label => $time) {
            $currentTime = microtime(true);
            $executionTime = ($currentTime - $time) * 1000;
            
            if ($executionTime > $threshold) {
                error_log("SLOW QUERY DETECTED [{$label}]: {$executionTime}ms");
            }
        }
    }
    
    public static function getMemoryUsage() {
        return [
            'current' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'current_formatted' => self::formatBytes(memory_get_usage(true)),
            'peak_formatted' => self::formatBytes(memory_get_peak_usage(true))
        ];
    }
    
    public static function getDatabaseStats($conn) {
        try {
            $stats = [];
            
            // Get connection info
            $stmt = $conn->query("SHOW STATUS LIKE 'Connections'");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['total_connections'] = $result['Value'] ?? 0;
            
            // Get query cache stats
            $stmt = $conn->query("SHOW STATUS LIKE 'Qcache%'");
            $cacheStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cacheStats as $stat) {
                $stats['query_cache'][$stat['Variable_name']] = $stat['Value'];
            }
            
            // Get slow query count
            $stmt = $conn->query("SHOW STATUS LIKE 'Slow_queries'");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['slow_queries'] = $result['Value'] ?? 0;
            
            return $stats;
        } catch (Exception $e) {
            error_log("Error getting database stats: " . $e->getMessage());
            return [];
        }
    }
    
    private static function formatBytes($size, $precision = 2) {
        $base = log($size, 1024);
        $suffixes = array('B', 'KB', 'MB', 'GB', 'TB');
        return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)];
    }
    
    public static function generateReport($conn = null) {
        $report = [
            'timestamp' => date('Y-m-d H:i:s'),
            'cache_stats' => self::getCacheStats(),
            'memory_usage' => self::getMemoryUsage(),
            'query_times' => self::$queryTimes
        ];
        
        if ($conn) {
            $report['database_stats'] = self::getDatabaseStats($conn);
        }
        
        return $report;
    }
    
    public static function logReport($conn = null) {
        $report = self::generateReport($conn);
        error_log("PERFORMANCE REPORT: " . json_encode($report, JSON_PRETTY_PRINT));
    }
}

// Enhanced Database class with performance monitoring
class MonitoredDatabase extends Database {
    public function fetchCached($query, $params = [], $cacheKey = null, $cacheTimeout = null) {
        if ($cacheKey) {
            $cached = self::getCache($cacheKey);
            if ($cached !== null) {
                PerformanceMonitor::recordCacheHit();
                return $cached;
            }
            PerformanceMonitor::recordCacheMiss();
        }
        
        PerformanceMonitor::startTimer('query_' . md5($query));
        
        $stmt = $this->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        PerformanceMonitor::endTimer('query_' . md5($query));
        
        if ($cacheKey) {
            self::setCache($cacheKey, $result, $cacheTimeout);
        }
        
        return $result;
    }
    
    public function fetchSingleCached($query, $params = [], $cacheKey = null, $cacheTimeout = null) {
        if ($cacheKey) {
            $cached = self::getCache($cacheKey);
            if ($cached !== null) {
                PerformanceMonitor::recordCacheHit();
                return $cached;
            }
            PerformanceMonitor::recordCacheMiss();
        }
        
        PerformanceMonitor::startTimer('query_' . md5($query));
        
        $stmt = $this->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        PerformanceMonitor::endTimer('query_' . md5($query));
        
        if ($cacheKey) {
            self::setCache($cacheKey, $result, $cacheTimeout);
        }
        
        return $result;
    }
}
?> 