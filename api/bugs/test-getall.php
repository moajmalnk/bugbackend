<?php
header('Content-Type: application/json');

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    echo json_encode([
        "success" => true,
        "message" => "Test endpoint reached",
        "step" => "1",
        "request_method" => $_SERVER['REQUEST_METHOD'],
        "request_uri" => $_SERVER['REQUEST_URI'] ?? 'unknown'
    ]);
    exit;
    
    // Step 2: Test BaseAPI require
    require_once __DIR__ . '/../BaseAPI.php';
    echo json_encode([
        "success" => true,
        "message" => "BaseAPI loaded",
        "step" => "2"
    ]);
    exit;
    
    // Step 3: Test BugController require
    require_once __DIR__ . '/BugController.php';
    echo json_encode([
        "success" => true,
        "message" => "BugController loaded",
        "step" => "3"
    ]);
    exit;
    
    // Step 4: Test ProjectMemberController require
    require_once __DIR__ . '/../projects/ProjectMemberController.php';
    echo json_encode([
        "success" => true,
        "message" => "ProjectMemberController loaded",
        "step" => "4"
    ]);
    exit;
    
    // Step 5: Test BaseAPI instantiation
    $api = new BaseAPI();
    echo json_encode([
        "success" => true,
        "message" => "BaseAPI instantiated",
        "step" => "5"
    ]);
    exit;
    
    // Step 6: Test token validation
    $decoded = $api->validateToken();
    echo json_encode([
        "success" => true,
        "message" => "Token validated",
        "step" => "6",
        "user_id" => $decoded->user_id ?? 'unknown',
        "role" => $decoded->role ?? 'unknown'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Error: " . $e->getMessage(),
        "trace" => $e->getTraceAsString()
    ]);
} catch (Error $e) {
    echo json_encode([
        "success" => false,
        "message" => "Fatal Error: " . $e->getMessage(),
        "file" => $e->getFile(),
        "line" => $e->getLine()
    ]);
}
?> 