<?php
// CORS Configuration
function handleCORS() {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    // Allowed origins - more comprehensive for local development
    $allowedOrigins = [
        'http://localhost:8080',
        'http://localhost:3000',
        'http://localhost:5173',
        'http://localhost',
        'http://127.0.0.1:8080',
        'http://127.0.0.1',
        'https://bugs.moajmalnk.in',
        'https://bugbackend.moajmalnk.in',
        'https://bugracers.vercel.app'
    ];
    
    // Check if origin is allowed
    if (in_array($origin, $allowedOrigins)) {
        header("Access-Control-Allow-Origin: $origin");
    } else {
        // For development, allow any localhost with any port
        if (preg_match('/^https?:\/\/(localhost|127\.0\.0\.1)(:[0-9]+)?$/', $origin)) {
            header("Access-Control-Allow-Origin: $origin");
        } else {
            // Fallback for development
            header("Access-Control-Allow-Origin: *");
        }
    }
    
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, X-Impersonate-User, X-User-Id");
    header("Access-Control-Allow-Credentials: false");
    header("Access-Control-Max-Age: 3600");
    
    // Handle preflight OPTIONS request
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

// Auto-call CORS handler
handleCORS();
?> 