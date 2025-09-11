<?php
// Fix password_resets table data type mismatch
require_once 'config/database.php';

try {
    // Force production environment
    $_SERVER['HTTP_HOST'] = 'bugs.moajmalnk.in';
    
    $pdo = Database::getInstance()->getConnection();
    
    echo "=== Fixing Password Resets Table ===\n";
    
    // Check current table structure
    echo "--- Current password_resets table structure ---\n";
    $stmt = $pdo->query("DESCRIBE password_resets");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($columns as $column) {
        echo $column['Field'] . " - " . $column['Type'] . "\n";
    }
    
    // Check current data
    echo "\n--- Current password_resets data ---\n";
    $stmt = $pdo->query("SELECT id, user_id, email, token, created_at FROM password_resets ORDER BY id DESC LIMIT 5");
    $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($tokens as $token) {
        echo "ID: " . $token['id'] . ", User ID: " . $token['user_id'] . " (Type: " . gettype($token['user_id']) . "), Email: " . $token['email'] . "\n";
    }
    
    // Get the correct user ID
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute(['moajmalnk']);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo "User not found\n";
        exit;
    }
    
    echo "\n--- Correct user ID ---\n";
    echo "Username: moajmalnk\n";
    echo "Correct User ID: " . $user['id'] . " (Type: " . gettype($user['id']) . ")\n";
    
    // Fix the password_resets table
    echo "\n--- Fixing password_resets table ---\n";
    
    // Step 1: Add a temporary column with correct data type
    echo "Step 1: Adding temporary user_id_new column...\n";
    try {
        $pdo->exec("ALTER TABLE password_resets ADD COLUMN user_id_new VARCHAR(36) AFTER user_id");
        echo "✓ Temporary column added\n";
    } catch (Exception $e) {
        echo "Column might already exist: " . $e->getMessage() . "\n";
    }
    
    // Step 2: Update the temporary column with correct user IDs
    echo "Step 2: Updating user_id_new with correct user IDs...\n";
    
    // Get all users to map old IDs to new IDs
    $stmt = $pdo->query("SELECT id, username, email FROM users");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $user_map = [];
    foreach ($users as $u) {
        $user_map[$u['email']] = $u['id'];
    }
    
    // Update password_resets with correct user IDs
    $stmt = $pdo->prepare("SELECT id, email FROM password_resets");
    $stmt->execute();
    $resets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $updated_count = 0;
    foreach ($resets as $reset) {
        if (isset($user_map[$reset['email']])) {
            $update_stmt = $pdo->prepare("UPDATE password_resets SET user_id_new = ? WHERE id = ?");
            $update_stmt->execute([$user_map[$reset['email']], $reset['id']]);
            $updated_count++;
        }
    }
    
    echo "✓ Updated " . $updated_count . " records with correct user IDs\n";
    
    // Step 3: Drop the old user_id column
    echo "Step 3: Dropping old user_id column...\n";
    try {
        $pdo->exec("ALTER TABLE password_resets DROP COLUMN user_id");
        echo "✓ Old user_id column dropped\n";
    } catch (Exception $e) {
        echo "Error dropping old column: " . $e->getMessage() . "\n";
    }
    
    // Step 4: Rename the new column
    echo "Step 4: Renaming user_id_new to user_id...\n";
    try {
        $pdo->exec("ALTER TABLE password_resets CHANGE COLUMN user_id_new user_id VARCHAR(36) NOT NULL");
        echo "✓ Column renamed successfully\n";
    } catch (Exception $e) {
        echo "Error renaming column: " . $e->getMessage() . "\n";
    }
    
    // Step 5: Add foreign key constraint
    echo "Step 5: Adding foreign key constraint...\n";
    try {
        $pdo->exec("ALTER TABLE password_resets ADD CONSTRAINT fk_password_resets_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
        echo "✓ Foreign key constraint added\n";
    } catch (Exception $e) {
        echo "Foreign key might already exist: " . $e->getMessage() . "\n";
    }
    
    // Verify the fix
    echo "\n--- Verification ---\n";
    $stmt = $pdo->query("DESCRIBE password_resets");
    $new_columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($new_columns as $column) {
        if ($column['Field'] === 'user_id') {
            echo "✓ user_id column type: " . $column['Type'] . "\n";
            break;
        }
    }
    
    // Test the fix
    echo "\n--- Testing the fix ---\n";
    $stmt = $pdo->prepare("
        SELECT pr.*, u.username, u.email 
        FROM password_resets pr 
        LEFT JOIN users u ON pr.user_id = u.id 
        WHERE pr.email = ? 
        ORDER BY pr.created_at DESC 
        LIMIT 1
    ");
    $stmt->execute(['moajmalnk@gmail.com']);
    $test_token = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($test_token) {
        echo "✓ Test successful!\n";
        echo "Token ID: " . $test_token['id'] . "\n";
        echo "User ID: " . $test_token['user_id'] . "\n";
        echo "Username: " . $test_token['username'] . "\n";
        echo "Email: " . $test_token['email'] . "\n";
    } else {
        echo "✗ Test failed - no matching tokens found\n";
    }
    
    echo "\n=== Fix Complete ===\n";
    echo "The password_resets table has been fixed!\n";
    echo "You can now try the password reset process again.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}
?>
