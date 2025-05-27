<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/utils.php';

class BaseAPI {
    protected $conn;
    protected $utils;
    
    public function __construct() {
        // Enable error logging
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        ini_set('error_log', __DIR__ . '/../../logs/php_errors.log');
        
        // Set JSON content type
        header('Content-Type: application/json');
        
        // Handle CORS preflight
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit();
        }
        
        try {
            // Connect to database
            $database = new Database();
            $this->conn = $database->getConnection();
            
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
        $token = $this->getBearerToken();
        
        if (!$token) {
            throw new Exception('No token provided');
        }

        return $this->utils->validateJWT($token);
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
        // Ensure proper content type is set
        header('Content-Type: application/json');
        
        // Handle CORS
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
        
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

    protected function sendCorsHeaders() {
        // Let individual API endpoints handle CORS
    }
}
?>