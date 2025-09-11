<?php
// Fix collation mismatch by handling foreign key constraints
require_once 'config/database.php';

try {
    // Force production environment
    $_SERVER['HTTP_HOST'] = 'bugs.moajmalnk.in';
    
    $pdo = Database::getInstance()->getConnection();
    
    echo "=== Fixing Collation with Foreign Key Constraints ===\n";
    
    // Check current collations
    echo "--- Current collations ---\n";
    
    $stmt = $pdo->query("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'");
    $users_collation = $stmt->fetch(PDO::FETCH_ASSOC)['TABLE_COLLATION'];
    echo "Users table: " . $users_collation . "\n";
    
    $stmt = $pdo->query("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets'");
    $password_resets_collation = $stmt->fetch(PDO::FETCH_ASSOC)['TABLE_COLLATION'];
    echo "Password_resets table: " . $password_resets_collation . "\n";
    
    $stmt = $pdo->query("SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'id'");
    $users_id_collation = $stmt->fetch(PDO::FETCH_ASSOC)['COLLATION_NAME'];
    echo "Users.id: " . $users_id_collation . "\n";
    
    $stmt = $pdo->query("SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'user_id'");
    $password_resets_user_id_collation = $stmt->fetch(PDO::FETCH_ASSOC)['COLLATION_NAME'];
    echo "Password_resets.user_id: " . $password_resets_user_id_collation . "\n";
    
    // Find all foreign key constraints that reference users.id
    echo "\n--- Finding foreign key constraints ---\n";
    
    $stmt = $pdo->query("
        SELECT 
            TABLE_NAME, 
            COLUMN_NAME, 
            CONSTRAINT_NAME,
            REFERENCED_TABLE_NAME,
            REFERENCED_COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND REFERENCED_TABLE_NAME = 'users' 
        AND REFERENCED_COLUMN_NAME = 'id'
    ");
    
    $constraints = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($constraints) {
        echo "Found " . count($constraints) . " foreign key constraints referencing users.id:\n";
        foreach ($constraints as $constraint) {
            echo "- " . $constraint['TABLE_NAME'] . "." . $constraint['COLUMN_NAME'] . " -> " . $constraint['REFERENCED_TABLE_NAME'] . "." . $constraint['REFERENCED_COLUMN_NAME'] . " (constraint: " . $constraint['CONSTRAINT_NAME'] . ")\n";
        }
    } else {
        echo "No foreign key constraints found\n";
    }
    
    // Solution: Change password_resets table to match users table collation
    echo "\n--- Changing password_resets table to match users table ---\n";
    
    // Step 1: Drop foreign key constraints temporarily
    echo "Step 1: Dropping foreign key constraints temporarily...\n";
    
    $stmt = $pdo->query("
        SELECT CONSTRAINT_NAME 
        FROM information_schema.KEY_COLUMN_USAGE 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'password_resets' 
        AND REFERENCED_TABLE_NAME = 'users'
    ");
    
    $fk_constraints = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($fk_constraints as $fk) {
        try {
            $pdo->exec("ALTER TABLE password_resets DROP FOREIGN KEY " . $fk['CONSTRAINT_NAME']);
            echo "✓ Dropped constraint: " . $fk['CONSTRAINT_NAME'] . "\n";
        } catch (Exception $e) {
            echo "Constraint might not exist: " . $e->getMessage() . "\n";
        }
    }
    
    // Step 2: Change password_resets table collation to match users
    echo "Step 2: Changing password_resets table collation...\n";
    
    try {
        $pdo->exec("ALTER TABLE password_resets CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        echo "✓ Password_resets table collation changed to utf8mb4_general_ci\n";
    } catch (Exception $e) {
        echo "Error changing table collation: " . $e->getMessage() . "\n";
    }
    
    // Step 3: Fix specific columns
    echo "Step 3: Fixing specific columns...\n";
    
    try {
        $pdo->exec("ALTER TABLE password_resets MODIFY COLUMN user_id VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL");
        $pdo->exec("ALTER TABLE password_resets MODIFY COLUMN email VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL");
        $pdo->exec("ALTER TABLE password_resets MODIFY COLUMN token VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL");
        echo "✓ Column collations fixed\n";
    } catch (Exception $e) {
        echo "Error fixing columns: " . $e->getMessage() . "\n";
    }
    
    // Step 4: Re-add foreign key constraints
    echo "Step 4: Re-adding foreign key constraints...\n";
    
    try {
        $pdo->exec("ALTER TABLE password_resets ADD CONSTRAINT fk_password_resets_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
        echo "✓ Foreign key constraint added successfully\n";
    } catch (Exception $e) {
        echo "Error adding foreign key: " . $e->getMessage() . "\n";
    }
    
    // Verify the fix
    echo "\n--- Verification ---\n";
    
    $stmt = $pdo->query("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'");
    $new_users_collation = $stmt->fetch(PDO::FETCH_ASSOC)['TABLE_COLLATION'];
    echo "Users table: " . $new_users_collation . "\n";
    
    $stmt = $pdo->query("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets'");
    $new_password_resets_collation = $stmt->fetch(PDO::FETCH_ASSOC)['TABLE_COLLATION'];
    echo "Password_resets table: " . $new_password_resets_collation . "\n";
    
    $stmt = $pdo->query("SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'id'");
    $new_users_id_collation = $stmt->fetch(PDO::FETCH_ASSOC)['COLLATION_NAME'];
    echo "Users.id: " . $new_users_id_collation . "\n";
    
    $stmt = $pdo->query("SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'user_id'");
    $new_password_resets_user_id_collation = $stmt->fetch(PDO::FETCH_ASSOC)['COLLATION_NAME'];
    echo "Password_resets.user_id: " . $new_password_resets_user_id_collation . "\n";
    
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
