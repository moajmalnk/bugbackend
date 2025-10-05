<?php
// Complete test for OTP functionality
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing complete OTP flow...\n";

// Test database connection
try {
    require_once __DIR__ . '/config/database.php';
    $pdo = Database::getInstance()->getConnection();
    echo "✓ Database connection successful\n";
    
    // Check if user exists
    $email = 'moajmalnk@gmail.com';
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo "✓ User exists: " . $user['id'] . "\n";
    } else {
        echo "✗ User not found with email: $email\n";
        exit;
    }
    
    // Generate OTP
    $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires_at = date('Y-m-d H:i:s', strtotime('+5 minutes'));
    echo "✓ Generated OTP: $otp\n";
    
    // Store OTP in DB
    $stmt = $pdo->prepare("INSERT INTO user_otps (email, otp, expires_at) VALUES (?, ?, ?)");
    $stmt->execute([$email, $otp, $expires_at]);
    echo "✓ OTP stored in database\n";
    
    // Test local environment detection
    $_SERVER['HTTP_HOST'] = 'localhost';
    $isLocal = strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || 
               strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false;
    echo "✓ Local environment detected: " . ($isLocal ? 'Yes' : 'No') . "\n";
    
    if ($isLocal) {
        echo "✓ Would return OTP for local development: $otp\n";
    } else {
        echo "✓ Would send email in production\n";
    }
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "\nTest completed.\n";
?>
