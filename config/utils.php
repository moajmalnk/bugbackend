<?php
require_once __DIR__ . '/../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Utils {
    private static $jwt_secret = "your_jwt_secret_key_here";
    
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
        $expiration = $issued_at + (60 * 60 * 24); // 24 hours
        
        $payload = array(
            "iat" => $issued_at,
            "exp" => $expiration,
            "user_id" => $user_id,
            "username" => $username,
            "role" => $role
        );
        
        return JWT::encode($payload, self::$jwt_secret, 'HS256');
    }
    
    public static function validateJWT($token) {
        try {
            return JWT::decode($token, new Key(self::$jwt_secret, 'HS256'));
        } catch(Exception $e) {
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
}
?>