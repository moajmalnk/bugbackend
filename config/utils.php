<?php
require_once __DIR__ . '/../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Utils {
    private static function getJwtSecret() {
        // Environment-specific JWT secrets
        $isLocal = self::isLocalEnvironment();
        
        if ($isLocal) {
            return "local_jwt_secret_bugricer_2024";
        } else {
            return "prod_jwt_secret_bugricer_secure_key_2024";
        }
    }
    
    private static function isLocalEnvironment() {
        $localHosts = ['localhost', '127.0.0.1', '::1'];
        $httpHost = $_SERVER['HTTP_HOST'] ?? '';
        $serverName = $_SERVER['SERVER_NAME'] ?? '';
        
        foreach ($localHosts as $localHost) {
            if (strpos($httpHost, $localHost) !== false || strpos($serverName, $localHost) !== false) {
                return true;
            }
        }
        
        if (preg_match('/:(8080|8000|3000|4000|5000)$/', $httpHost)) {
            return true;
        }
        
        if (isset($_SERVER['SERVER_SOFTWARE']) && 
            (stripos($_SERVER['SERVER_SOFTWARE'], 'apache') !== false && 
             (stripos($_SERVER['DOCUMENT_ROOT'], 'xampp') !== false || 
              stripos($_SERVER['DOCUMENT_ROOT'], 'wamp') !== false))) {
            return true;
        }
        
        return false;
    }
    
    public static function generateUUID() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    public static function isValidUUID($uuid) {
        return preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $uuid) === 1;
    }
    
    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_BCRYPT);
    }
    
    public static function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
    
    public static function generateJWT($user_id, $username, $role) {
        $issued_at = time();
        if ($role === 'admin') {
            $expiration = $issued_at + (7 * 24 * 60 * 60); // 7 days
        } else {
            $expiration = $issued_at + (7 * 24 * 60 * 60); // 7 days
        }
        $payload = array(
            "iat" => $issued_at,
            "exp" => $expiration,
            "user_id" => $user_id,
            "username" => $username,
            "role" => $role
        );
        $secret = self::getJwtSecret();
        error_log("Generating JWT for user: " . $username . " in environment: " . (self::isLocalEnvironment() ? "Local" : "Production") . ", role: " . $role . ", exp: " . date('c', $expiration));
        return JWT::encode($payload, $secret, 'HS256');
    }
    
    public static function validateJWT($token) {
        try {
            $secret = self::getJwtSecret();
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
            error_log("JWT validation successful for user_id: " . $decoded->user_id);
            return $decoded;
        } catch(Exception $e) {
            error_log("JWT validation failed: " . $e->getMessage());
            return false;
        }
    }
    
    public static function sendResponse($status_code, $message, $data = null) {
        header('Content-Type: application/json');
        http_response_code($status_code);
        echo json_encode(array(
            "status" => $status_code,
            "message" => $message,
            "data" => $data
        ));
    }
    
    public static function validateRequiredParams($required_fields, $request_data) {
        $missing_fields = array();
        foreach($required_fields as $field) {
            if(!isset($request_data[$field]) || empty(trim($request_data[$field]))) {
                $missing_fields[] = $field;
            }
        }
        return $missing_fields;
    }

    public static function normalizePhone($phone) {
        // Remove all non-digits
        $digits = preg_replace('/\D/', '', $phone);
        // Ensure it starts with country code, e.g., +91
        if (strlen($digits) === 10) {
            return '+91' . $digits;
        } elseif (strlen($digits) === 12 && strpos($digits, '91') === 0) {
            return '+' . $digits;
        } elseif (strlen($digits) === 13 && strpos($digits, '+91') === 0) {
            return $digits;
        }
        // fallback: return as is
        return $phone;
    }
}
?>