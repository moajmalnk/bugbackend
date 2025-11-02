<?php
/**
 * Simple Magic Links Migration Script
 * This version avoids foreign key constraints to prevent production issues
 */

require_once __DIR__ . '/config/database.php';

try {
    $db = getDBConnection();
    
    echo "🚀 Starting Magic Links Migration...\n\n";
    
    // Step 1: Create the magic_links table
    echo "📋 Creating magic_links table...\n";
    $create_table_sql = "
    CREATE TABLE IF NOT EXISTS magic_links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        email VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_token (token),
        INDEX idx_user_id (user_id),
        INDEX idx_expires_at (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    if ($db->query($create_table_sql)) {
        echo "✅ magic_links table created successfully\n";
    } else {
        echo "❌ Failed to create magic_links table: " . $db->error . "\n";
        exit(1);
    }
    
    // Step 2: Add cleanup index
    echo "📋 Adding cleanup index...\n";
    $cleanup_index_sql = "CREATE INDEX IF NOT EXISTS idx_cleanup ON magic_links (expires_at, used_at)";
    
    if ($db->query($cleanup_index_sql)) {
        echo "✅ Cleanup index added successfully\n";
    } else {
        echo "⚠️  Cleanup index already exists or failed: " . $db->error . "\n";
    }
    
    // Step 3: Add table comment
    echo "📋 Adding table comment...\n";
    $comment_sql = "ALTER TABLE magic_links COMMENT = 'Stores magic link tokens for passwordless email authentication'";
    
    if ($db->query($comment_sql)) {
        echo "✅ Table comment added successfully\n";
    } else {
        echo "⚠️  Failed to add table comment: " . $db->error . "\n";
    }
    
    // Step 4: Verify table structure
    echo "📋 Verifying table structure...\n";
    $result = $db->query("DESCRIBE magic_links");
    if ($result) {
        echo "✅ Table structure verified:\n";
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            echo "   - {$row['Field']}: {$row['Type']}\n";
        }
    } else {
        echo "❌ Failed to verify table structure\n";
    }
    
    echo "\n🎉 Magic Links migration completed successfully!\n";
    echo "✨ Passwordless authentication is now ready to use.\n";
    echo "\n📝 Note: Foreign key constraints are handled at the application level\n";
    echo "   for better compatibility across different database configurations.\n";
    
} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>
