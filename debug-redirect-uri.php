<?php
/**
 * Debug script to check what redirect URI is being generated
 * Run this on production to see the actual redirect URI
 */

require_once __DIR__ . '/config/environment.php';

echo "<h2>BugDocs Redirect URI Debug</h2>\n";
echo "<pre>\n";

// Check environment variables
echo "=== Environment Variables ===\n";
echo "GOOGLE_CLIENT_ID: " . (Environment::getGoogleClientId() ?: 'NOT SET') . "\n";
echo "GOOGLE_CLIENT_SECRET: " . (Environment::getGoogleClientSecret() ? 'SET' : 'NOT SET') . "\n";
echo "GOOGLE_REDIRECT_URI: " . (Environment::getGoogleRedirectUri() ?: 'NOT SET') . "\n\n";

// Check server variables
echo "=== Server Variables ===\n";
echo "HTTP_HOST: " . ($_SERVER['HTTP_HOST'] ?? 'NOT SET') . "\n";
echo "SERVER_NAME: " . ($_SERVER['SERVER_NAME'] ?? 'NOT SET') . "\n";
echo "REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'NOT SET') . "\n";
echo "HTTPS: " . (isset($_SERVER['HTTPS']) ? $_SERVER['HTTPS'] : 'NOT SET') . "\n\n";

// Simulate the redirect URI logic
echo "=== Redirect URI Logic ===\n";
$envRedirectUri = Environment::getGoogleRedirectUri();
if (!empty($envRedirectUri)) {
    echo "Using environment variable: " . $envRedirectUri . "\n";
} else {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
        $redirectUri = 'http://localhost/BugRicer/backend/api/oauth/callback';
        echo "Using localhost fallback: " . $redirectUri . "\n";
    } else {
        $redirectUri = 'https://' . $host . '/api/oauth/callback';
        echo "Using production fallback: " . $redirectUri . "\n";
    }
}

echo "\n=== Expected in Google Cloud Console ===\n";
echo "Make sure this URI is added to your Google Cloud Console:\n";
echo "https://bugbackend.bugricer.com/api/oauth/callback\n";

echo "</pre>\n";
?>
