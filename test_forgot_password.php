<?php
/**
 * Test script for forgot password API
 * This helps verify the API is working correctly
 */

echo "<h2>BugRicer Forgot Password API Test</h2>\n";

// Test the API endpoint
$api_url = 'http://localhost/BugRicer/backend/api/auth/forgot_password.php';
$test_email = 'test@example.com';

echo "<h3>Testing API Endpoint: $api_url</h3>\n";

// Test data
$data = [
    'email' => $test_email
];

// Initialize cURL
$ch = curl_init();

curl_setopt($ch, CURLOPT_URL, $api_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_VERBOSE, true);

// Execute the request
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

curl_close($ch);

echo "<h4>Response Details:</h4>\n";
echo "<p><strong>HTTP Code:</strong> $http_code</p>\n";

if ($error) {
    echo "<p style='color: red;'><strong>cURL Error:</strong> $error</p>\n";
} else {
    echo "<p style='color: green;'><strong>cURL:</strong> Success</p>\n";
}

echo "<p><strong>Response Body:</strong></p>\n";
echo "<pre style='background: #f5f5f5; padding: 10px; border-radius: 5px;'>";
echo htmlspecialchars($response);
echo "</pre>\n";

// Try to decode JSON response
$json_response = json_decode($response, true);
if ($json_response) {
    echo "<h4>Parsed Response:</h4>\n";
    echo "<pre style='background: #e8f5e8; padding: 10px; border-radius: 5px;'>";
    print_r($json_response);
    echo "</pre>\n";
} else {
    echo "<p style='color: orange;'><strong>Warning:</strong> Response is not valid JSON</p>\n";
}

// Test CORS headers
echo "<h3>CORS Headers Test</h3>\n";
echo "<p>Testing if CORS headers are properly set...</p>\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $api_url);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'OPTIONS');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Origin: http://localhost:8080',
    'Access-Control-Request-Method: POST',
    'Access-Control-Request-Headers: Content-Type'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_NOBODY, true);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headers = curl_getinfo($ch, CURLINFO_HEADER_OUT);

curl_close($ch);

echo "<p><strong>OPTIONS Request HTTP Code:</strong> $http_code</p>\n";
echo "<p><strong>Response Headers:</strong></p>\n";
echo "<pre style='background: #f0f8ff; padding: 10px; border-radius: 5px;'>";
echo htmlspecialchars($response);
echo "</pre>\n";

// Check for CORS headers
if (strpos($response, 'Access-Control-Allow-Origin') !== false) {
    echo "<p style='color: green;'>✅ CORS headers found</p>\n";
} else {
    echo "<p style='color: red;'>❌ CORS headers missing</p>\n";
}

echo "<h3>Next Steps</h3>\n";
echo "<ol>\n";
echo "<li>If you see CORS headers and a successful response, the API is working</li>\n";
echo "<li>If not, check the server error logs</li>\n";
echo "<li>Make sure the database tables are created</li>\n";
echo "<li>Verify the file permissions are correct</li>\n";
echo "</ol>\n";
?>
