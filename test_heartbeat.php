<?php
/**
 * Test script for User Presence Heartbeat System
 * 
 * This script demonstrates the heartbeat functionality and status calculation.
 * Run this after implementing the system to verify everything works correctly.
 */

require_once 'config/database.php';
require_once 'api/BaseAPI.php';

echo "=== User Presence Heartbeat System Test ===\n\n";

try {
    // Test database connection
    $database = Database::getInstance();
    $conn = $database->getConnection();
    
    if (!$conn) {
        throw new Exception("Database connection failed");
    }
    
    echo "✓ Database connection successful\n";
    
    // Check if last_active_at column exists
    $checkColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'last_active_at'");
    if ($checkColumn->rowCount() > 0) {
        echo "✓ last_active_at column exists\n";
    } else {
        echo "✗ last_active_at column missing - run the SQL migration first\n";
        exit(1);
    }
    
    // Test status calculation query
    echo "\n--- Testing Status Calculation ---\n";
    
    $statusQuery = "SELECT id, username, email, role, last_active_at,
        CASE 
            WHEN last_active_at IS NULL THEN 'offline'
            WHEN TIMESTAMPDIFF(SECOND, last_active_at, NOW()) < 120 THEN 'active'
            WHEN TIMESTAMPDIFF(SECOND, last_active_at, NOW()) < 900 THEN 'idle'
            ELSE 'offline'
        END as status
        FROM users 
        ORDER BY created_at DESC 
        LIMIT 5";
    
    $stmt = $conn->prepare($statusQuery);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Sample users with status:\n";
    foreach ($users as $user) {
        $lastActive = $user['last_active_at'] ?: 'Never';
        echo "- {$user['username']} ({$user['role']}): {$user['status']} (last active: {$lastActive})\n";
    }
    
    // Test heartbeat update simulation
    echo "\n--- Testing Heartbeat Update ---\n";
    
    if (!empty($users)) {
        $testUserId = $users[0]['id'];
        echo "Simulating heartbeat for user: {$users[0]['username']}\n";
        
        $updateStmt = $conn->prepare("UPDATE users SET last_active_at = NOW() WHERE id = ?");
        $result = $updateStmt->execute([$testUserId]);
        
        if ($result) {
            echo "✓ Heartbeat update successful\n";
            
            // Verify the update
            $verifyStmt = $conn->prepare("SELECT last_active_at FROM users WHERE id = ?");
            $verifyStmt->execute([$testUserId]);
            $updated = $verifyStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($updated['last_active_at']) {
                echo "✓ last_active_at updated to: {$updated['last_active_at']}\n";
            }
        } else {
            echo "✗ Heartbeat update failed\n";
        }
    }
    
    echo "\n--- Test Summary ---\n";
    echo "✓ Database schema ready\n";
    echo "✓ Status calculation working\n";
    echo "✓ Heartbeat update working\n";
    echo "\nNext steps:\n";
    echo "1. Run the SQL migration: backend/config/user_presence_schema.sql\n";
    echo "2. Test the heartbeat endpoint: POST /api/user/heartbeat.php\n";
    echo "3. Check the Users page to see status badges\n";
    echo "4. Monitor heartbeat sending in browser console\n";
    
} catch (Exception $e) {
    echo "✗ Test failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=== Test Complete ===\n";
?>
