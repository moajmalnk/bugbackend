<?php
/**
 * Test script to check what redirect URI is being generated
 * Upload this to production and run it to debug the redirect URI issue
 */

// Simulate the production environment
$_SERVER['HTTP_HOST'] = 'bugbackend.bugricer.com';
$_SERVER['HTTPS'] = 'on';

echo "<h2>BugDocs Redirect URI Test</h2>\n";
echo "<pre>\n";

// Test the redirect URI logic from GoogleOAuthController
function getRedirectUri() {
    // Use environment variable if available, otherwise auto-detect
    $envRedirectUri = getenv('GOOGLE_REDIRECT_URI');
    if (!empty($envRedirectUri)) {
        return $envRedirectUri;
    }
    
    // Fallback: Check if we're in local development or production
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    if (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
        return 'http://localhost/BugRicer/backend/api/oauth/callback';
    } else {
        // Production URL - use the path that matches Google Cloud Console
        return 'https://' . $host . '/api/oauth/callback';
    }
}

echo "=== Server Environment ===\n";
echo "HTTP_HOST: " . ($_SERVER['HTTP_HOST'] ?? 'NOT SET') . "\n";
echo "HTTPS: " . (isset($_SERVER['HTTPS']) ? $_SERVER['HTTPS'] : 'NOT SET') . "\n\n";

echo "=== Environment Variables ===\n";
echo "GOOGLE_REDIRECT_URI: " . (getenv('GOOGLE_REDIRECT_URI') ?: 'NOT SET') . "\n\n";

echo "=== Generated Redirect URI ===\n";
$redirectUri = getRedirectUri();
echo "Generated URI: " . $redirectUri . "\n\n";

echo "=== Expected in Google Cloud Console ===\n";
echo "Should match: https://bugbackend.bugricer.com/api/oauth/callback\n\n";

echo "=== Match Check ===\n";
$expected = 'https://bugbackend.bugricer.com/api/oauth/callback';
if ($redirectUri === $expected) {
    echo "✅ MATCH! Redirect URI is correct.\n";
} else {
    echo "❌ MISMATCH! Redirect URI is wrong.\n";
    echo "Expected: " . $expected . "\n";
    echo "Actual:   " . $redirectUri . "\n";
}

echo "</pre>\n";
?>
