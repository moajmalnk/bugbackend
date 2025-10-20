<?php
/**
 * Environment Configuration
 * Loads environment variables from .env file or system environment
 */

class Environment {
    private static $config = [];
    
    public static function load($envFile = null) {
        if ($envFile === null) {
            $envFile = __DIR__ . '/../.env';
        }
        
        // Load from .env file if it exists
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '#') === 0) continue; // Skip comments
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    self::$config[$key] = $value;
                }
            }
        }
        
        // Override with system environment variables if they exist
        $envVars = [
            'GOOGLE_CLIENT_ID',
            'GOOGLE_CLIENT_SECRET', 
            'GOOGLE_REDIRECT_URI',
            'DB_HOST',
            'DB_NAME',
            'DB_USER',
            'DB_PASS'
        ];
        
        foreach ($envVars as $var) {
            $systemValue = getenv($var);
            if ($systemValue !== false) {
                self::$config[$var] = $systemValue;
            }
        }
    }
    
    public static function get($key, $default = null) {
        return isset(self::$config[$key]) ? self::$config[$key] : $default;
    }
    
    public static function getGoogleClientId() {
        // Get from environment variable or .env file only
        // No hardcoded fallback for security reasons
        return self::get('GOOGLE_CLIENT_ID');
    }
    
    public static function getGoogleClientSecret() {
        // Get from environment variable or .env file only
        // No hardcoded fallback for security reasons
        return self::get('GOOGLE_CLIENT_SECRET');
    }
    
    public static function getGoogleRedirectUri() {
        // Auto-detect production vs local
        $defaultUri = 'http://localhost/BugRicer/backend/api/oauth/callback';
        
        // Check if we're on production domain
        if (isset($_SERVER['HTTP_HOST'])) {
            $host = $_SERVER['HTTP_HOST'];
            if (strpos($host, 'bugricer.com') !== false) {
                $defaultUri = 'https://bugbackend.bugricer.com/api/oauth/callback';
            }
        }
        
        return self::get('GOOGLE_REDIRECT_URI', $defaultUri);
    }
    
    public static function isProduction() {
        if (isset($_SERVER['HTTP_HOST'])) {
            $host = $_SERVER['HTTP_HOST'];
            return strpos($host, 'bugricer.com') !== false;
        }
        return false;
    }
}

// Auto-load environment on include
Environment::load();
