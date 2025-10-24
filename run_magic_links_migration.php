<?php
/**
 * Magic Links Migration Script
 * Run this script to create the magic_links table for passwordless authentication
 */

require_once __DIR__ . '/config/database.php';

try {
    $db = getDBConnection();
    
    // Read the SQL schema file
    $sql_file = __DIR__ . '/sql/magic_links_schema.sql';
    
    if (!file_exists($sql_file)) {
        throw new Exception("SQL schema file not found: $sql_file");
    }
    
    $sql = file_get_contents($sql_file);
    
    // Split SQL into individual statements
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    $success_count = 0;
    $error_count = 0;
    
    foreach ($statements as $statement) {
        if (empty($statement)) continue;
        
        try {
            $result = $db->query($statement);
            if ($result) {
                $success_count++;
                echo "✅ Executed: " . substr($statement, 0, 50) . "...\n";
            } else {
                $error_count++;
                echo "❌ Failed: " . substr($statement, 0, 50) . "...\n";
                echo "Error: " . $db->error . "\n";
            }
        } catch (Exception $e) {
            $error_count++;
            echo "❌ Error executing statement: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n📊 Migration Summary:\n";
    echo "✅ Successful statements: $success_count\n";
    echo "❌ Failed statements: $error_count\n";
    
    if ($error_count === 0) {
        echo "\n🎉 Magic Links migration completed successfully!\n";
        echo "✨ Passwordless authentication is now ready to use.\n";
    } else {
        echo "\n⚠️  Migration completed with some errors. Please check the output above.\n";
    }
    
} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>
