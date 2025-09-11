<?php
// Test script to verify password reset functionality
require_once 'config/database.php';

try {
    $pdo = Database::getInstance()->getConnection();
    
    // Test password hashing and verification
    $test_password = 'NewPassword123!';
    $hashed_password = password_hash($test_password, PASSWORD_DEFAULT);
    
    echo "=== Password Reset Test ===\n";
    echo "Test password: " . $test_password . "\n";
    echo "Hashed password: " . $hashed_password . "\n";
    echo "Hash length: " . strlen($hashed_password) . "\n";
    
    // Test verification
    $verify_result = password_verify($test_password, $hashed_password);
    echo "Verification result: " . ($verify_result ? 'SUCCESS' : 'FAILED') . "\n";
    
    // Test with wrong password
    $wrong_password = 'WrongPassword123!';
    $wrong_verify = password_verify($wrong_password, $hashed_password);
    echo "Wrong password verification: " . ($wrong_verify ? 'SUCCESS (ERROR!)' : 'FAILED (CORRECT)') . "\n";
    
    // Test with old password format (if it exists)
    $old_password = 'Codo@8848';
    $old_verify = password_verify($old_password, $hashed_password);
    echo "Old password verification: " . ($old_verify ? 'SUCCESS (ERROR!)' : 'FAILED (CORRECT)') . "\n";
    
    echo "\n=== Test Complete ===\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
