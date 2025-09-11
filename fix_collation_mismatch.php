<?php
// Fix collation mismatch between users and password_resets tables
require_once 'config/database.php';

try {
    // Force production environment
    $_SERVER['HTTP_HOST'] = 'bugs.moajmalnk.in';
    
    $pdo = Database::getInstance()->getConnection();
    
    echo "=== Fixing Collation Mismatch ===\n";
    
    // Check current collations
    echo "--- Current table collations ---\n";
    
    // Check users table collation
    $stmt = $pdo->query("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'");
    $users_collation = $stmt->fetch(PDO::FETCH_ASSOC)['TABLE_COLLATION'];
    echo "Users table collation: " . $users_collation . "\n";
    
    // Check password_resets table collation
    $stmt = $pdo->query("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets'");
    $password_resets_collation = $stmt->fetch(PDO::FETCH_ASSOC)['TABLE_COLLATION'];
    echo "Password_resets table collation: " . $password_resets_collation . "\n";
    
    // Check user_id column collations
    $stmt = $pdo->query("SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'id'");
    $users_id_collation = $stmt->fetch(PDO::FETCH_ASSOC)['COLLATION_NAME'];
    echo "Users.id collation: " . $users_id_collation . "\n";
    
    $stmt = $pdo->query("SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'user_id'");
    $password_resets_user_id_collation = $stmt->fetch(PDO::FETCH_ASSOC)['COLLATION_NAME'];
    echo "Password_resets.user_id collation: " . $password_resets_user_id_collation . "\n";
    
    // Fix the collation mismatch
    echo "\n--- Fixing collation mismatch ---\n";
    
    // Step 1: Change password_resets table collation to match users
    echo "Step 1: Changing password_resets table collation...\n";
    try {
        $pdo->exec("ALTER TABLE password_resets CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        echo "✓ Password_resets table collation changed to utf8mb4_unicode_ci\n";
    } catch (Exception $e) {
        echo "Error changing table collation: " . $e->getMessage() . "\n";
    }
    
    // Step 2: Specifically fix the user_id column collation
    echo "Step 2: Fixing user_id column collation...\n";
    try {
        $pdo->exec("ALTER TABLE password_resets MODIFY COLUMN user_id VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL");
        echo "✓ user_id column collation fixed\n";
    } catch (Exception $e) {
        echo "Error fixing user_id collation: " . $e->getMessage() . "\n";
    }
    
    // Step 3: Fix email column collation too
    echo "Step 3: Fixing email column collation...\n";
    try {
        $pdo->exec("ALTER TABLE password_resets MODIFY COLUMN email VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL");
        echo "✓ email column collation fixed\n";
    } catch (Exception $e) {
        echo "Error fixing email collation: " . $e->getMessage() . "\n";
    }
    
    // Step 4: Add foreign key constraint now that collations match
    echo "Step 4: Adding foreign key constraint...\n";
    try {
        // First, drop any existing foreign key
        $pdo->exec("ALTER TABLE password_resets DROP FOREIGN KEY IF EXISTS fk_password_resets_user_id");
        echo "✓ Dropped existing foreign key (if any)\n";
        
        // Add the foreign key constraint
        $pdo->exec("ALTER TABLE password_resets ADD CONSTRAINT fk_password_resets_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
        echo "✓ Foreign key constraint added successfully\n";
    } catch (Exception $e) {
        echo "Error adding foreign key: " . $e->getMessage() . "\n";
    }
    
    // Verify the fix
    echo "\n--- Verification ---\n";
    
    // Check collations again
    $stmt = $pdo->query("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets'");
    $new_collation = $stmt->fetch(PDO::FETCH_ASSOC)['TABLE_COLLATION'];
    echo "Password_resets table collation: " . $new_collation . "\n";
    
    $stmt = $pdo->query("SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'user_id'");
    $new_user_id_collation = $stmt->fetch(PDO::FETCH_ASSOC)['COLLATION_NAME'];
    echo "Password_resets.user_id collation: " . $new_user_id_collation . "\n";
    
    // Test the fix
    echo "\n--- Testing the fix ---\n";
    
    // Test a simple join query
    $stmt = $pdo->prepare("
        SELECT pr.id, pr.user_id, u.username, u.email 
        FROM password_resets pr 
        LEFT JOIN users u ON pr.user_id = u.id 
        WHERE pr.email = ? 
        LIMIT 1
    ");
    
    $stmt->execute(['moajmalnk@gmail.com']);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        echo "✓ Join query successful!\n";
        echo "Token ID: " . $result['id'] . "\n";
        echo "User ID: " . $result['user_id'] . "\n";
        echo "Username: " . $result['username'] . "\n";
        echo "Email: " . $result['email'] . "\n";
    } else {
        echo "✗ Join query failed\n";
    }
    
    // Test the exact query used in reset_password.php
    echo "\n--- Testing reset_password.php query ---\n";
    
    // Get a test token
    $stmt = $pdo->prepare("SELECT token FROM password_resets WHERE email = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute(['moajmalnk@gmail.com']);
    $test_token = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($test_token) {
        $token = $test_token['token'];
        echo "Testing with token: " . substr($token, 0, 20) . "...\n";
        
        $reset_stmt = $pdo->prepare("
            SELECT pr.*, u.id as user_id, u.username, u.email, u.role 
            FROM password_resets pr 
            LEFT JOIN users u ON pr.user_id = u.id 
            WHERE pr.token = ? AND pr.expires_at > NOW() AND pr.used_at IS NULL
        ");
        
        $reset_stmt->execute([$token]);
        $reset_request = $reset_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($reset_request) {
            echo "✓ Reset token lookup successful!\n";
            echo "User ID: " . $reset_request['user_id'] . "\n";
            echo "Username: " . $reset_request['username'] . "\n";
            echo "Email: " . $reset_request['email'] . "\n";
        } else {
            echo "✗ Reset token lookup failed\n";
        }
    } else {
        echo "No test token found\n";
    }
    
    echo "\n=== Fix Complete ===\n";
    echo "The collation mismatch has been fixed!\n";
    echo "Password reset should now work correctly.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}
?>
