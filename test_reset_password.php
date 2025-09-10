<?php
/**
 * Test reset password functionality
 */

require_once 'config/cors.php';
require_once 'config/database.php';

echo "<h2>BugRicer Password Reset Test</h2>\n";

try {
    // Get database connection
    $pdo = Database::getInstance()->getConnection();
    
    if (!$pdo) {
        throw new Exception("Database connection failed");
    }
    
    echo "<p style='color: green;'>✅ Database connection successful</p>\n";
    
    // Get token from URL
    $token = $_GET['token'] ?? '';
    
    if (empty($token)) {
        echo "<p style='color: red;'>❌ No token provided. Add ?token=YOUR_TOKEN to the URL</p>\n";
        echo "<p>Example: <a href='?token=test123'>test_reset_password.php?token=test123</a></p>\n";
        exit;
    }
    
    echo "<p><strong>Testing token:</strong> " . htmlspecialchars($token) . "</p>\n";
    
    // Test verify_reset_token.php
    echo "<h3>1. Testing verify_reset_token.php</h3>\n";
    
    $verify_url = "http://localhost/BugRicer/backend/api/auth/verify_reset_token.php";
    $verify_data = json_encode(['token' => $token]);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $verify_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $verify_data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($verify_data)
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $verify_response = curl_exec($ch);
    $verify_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "<p><strong>HTTP Code:</strong> $verify_http_code</p>\n";
    echo "<p><strong>Response:</strong></p>\n";
    echo "<pre style='background: #f5f5f5; padding: 10px; border-radius: 4px;'>" . htmlspecialchars($verify_response) . "</pre>\n";
    
    $verify_result = json_decode($verify_response, true);
    
    if ($verify_result && $verify_result['success']) {
        echo "<p style='color: green;'>✅ Token is valid</p>\n";
        echo "<p><strong>User:</strong> " . htmlspecialchars($verify_result['data']['username'] ?? 'Unknown') . "</p>\n";
        echo "<p><strong>Email:</strong> " . htmlspecialchars($verify_result['data']['email'] ?? 'Unknown') . "</p>\n";
        
        // Test reset_password.php
        echo "<h3>2. Testing reset_password.php</h3>\n";
        
        $reset_url = "http://localhost/BugRicer/backend/api/auth/reset_password.php";
        $reset_data = json_encode([
            'token' => $token,
            'password' => 'NewTestPassword123!',
            'confirm_password' => 'NewTestPassword123!'
        ]);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $reset_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $reset_data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($reset_data)
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $reset_response = curl_exec($ch);
        $reset_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        echo "<p><strong>HTTP Code:</strong> $reset_http_code</p>\n";
        echo "<p><strong>Response:</strong></p>\n";
        echo "<pre style='background: #f5f5f5; padding: 10px; border-radius: 4px;'>" . htmlspecialchars($reset_response) . "</pre>\n";
        
        $reset_result = json_decode($reset_response, true);
        
        if ($reset_result && $reset_result['success']) {
            echo "<p style='color: green;'>✅ Password reset successful!</p>\n";
        } else {
            echo "<p style='color: red;'>❌ Password reset failed: " . htmlspecialchars($reset_result['message'] ?? 'Unknown error') . "</p>\n";
        }
        
    } else {
        echo "<p style='color: red;'>❌ Token is invalid or expired</p>\n";
        echo "<p><strong>Error:</strong> " . htmlspecialchars($verify_result['message'] ?? 'Unknown error') . "</p>\n";
    }
    
    // Show recent password reset tokens
    echo "<h3>3. Recent Password Reset Tokens</h3>\n";
    
    $stmt = $pdo->query("
        SELECT pr.*, u.username, u.email 
        FROM password_resets pr 
        LEFT JOIN users u ON pr.user_id = u.id 
        ORDER BY pr.created_at DESC 
        LIMIT 5
    ");
    $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($tokens) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 10px 0;'>\n";
        echo "<tr style='background: #f0f0f0;'>";
        echo "<th style='padding: 8px;'>Username</th>";
        echo "<th style='padding: 8px;'>Email</th>";
        echo "<th style='padding: 8px;'>Token (first 20 chars)</th>";
        echo "<th style='padding: 8px;'>Expires At</th>";
        echo "<th style='padding: 8px;'>Used At</th>";
        echo "<th style='padding: 8px;'>Created At</th>";
        echo "<th style='padding: 8px;'>Test Link</th>";
        echo "</tr>\n";
        
        foreach ($tokens as $token_data) {
            $is_expired = strtotime($token_data['expires_at']) < time();
            $is_used = !empty($token_data['used_at']);
            $row_style = $is_expired ? 'background: #ffebee;' : ($is_used ? 'background: #e8f5e8;' : '');
            
            echo "<tr style='$row_style'>";
            echo "<td style='padding: 8px;'>" . htmlspecialchars($token_data['username'] ?? 'N/A') . "</td>";
            echo "<td style='padding: 8px;'>" . htmlspecialchars($token_data['email']) . "</td>";
            echo "<td style='padding: 8px; font-family: monospace;'>" . htmlspecialchars(substr($token_data['token'], 0, 20)) . "...</td>";
            echo "<td style='padding: 8px;'>" . htmlspecialchars($token_data['expires_at']) . "</td>";
            echo "<td style='padding: 8px;'>" . htmlspecialchars($token_data['used_at'] ?? 'Not used') . "</td>";
            echo "<td style='padding: 8px;'>" . htmlspecialchars($token_data['created_at']) . "</td>";
            echo "<td style='padding: 8px;'>";
            if (!$is_expired && !$is_used) {
                echo "<a href='?token=" . urlencode($token_data['token']) . "' style='color: #2563eb; text-decoration: none;'>Test This Token</a>";
            } else {
                echo "<span style='color: #999;'>" . ($is_expired ? 'Expired' : 'Used') . "</span>";
            }
            echo "</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
    } else {
        echo "<p style='color: orange;'>⚠️ No password reset tokens found</p>\n";
    }
    
    echo "<h3>4. Frontend Test Links</h3>\n";
    echo "<p>Test the reset password page with your token:</p>\n";
    echo "<p><a href='http://localhost:8080/reset-password?token=" . urlencode($token) . "' target='_blank' style='color: #2563eb; text-decoration: none;'>Open Reset Password Page</a></p>\n";
    echo "<p><a href='https://bugs.moajmalnk.in/reset-password?token=" . urlencode($token) . "' target='_blank' style='color: #2563eb; text-decoration: none;'>Open Production Reset Password Page</a></p>\n";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>\n";
}
?>
