<?php
// CORS Configuration
function handleCORS() {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    // Allowed origins - more comprehensive for local development and production
    $allowedOrigins = [
        'http://localhost:8080',
        'http://localhost:3000',
        'http://localhost:5173',
        'http://localhost',
        'http://127.0.0.1:8080',
        'http://127.0.0.1:3000',
        'http://127.0.0.1:5173',
        'http://127.0.0.1',
        'https://bugs.moajmalnk.in',
        'https://bugricer.com',
        'https://www.bugricer.com',
        'https://bugs.bugricer.com',
        'https://bugbackend.bugricer.com',
        'https://bugbackend.moajmalnk.in',
        'https://bugracers.vercel.app',
    ];
    
    $allowOrigin = null;

    // Check if origin is allowed
    if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
        $allowOrigin = $origin;
    } elseif ($origin !== '' && preg_match('/^https?:\/\/(localhost|127\.0\.0\.1)(:[0-9]+)?$/', $origin)) {
        // For development, allow any localhost with any port
        $allowOrigin = $origin;
    }

    if ($allowOrigin !== null) {
        header("Access-Control-Allow-Origin: $allowOrigin");
        header("Vary: Origin");
        header("Access-Control-Allow-Credentials: true");
    } else {
        // No credentials with wildcard — safe fallback for non-browser clients
        header("Access-Control-Allow-Origin: *");
    }
    
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, X-Impersonate-User, X-User-Id");
    header("Access-Control-Max-Age: 3600");
    
    // Handle preflight OPTIONS request
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit();
    }
}

// Auto-call CORS handler
handleCORS();