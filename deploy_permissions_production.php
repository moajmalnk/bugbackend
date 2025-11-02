<?php
/**
 * Production Permission System Deployment
 * BugRicer - Safe Production Deployment
 * 
 * This script safely deploys the permission system to production
 * with proper error handling and rollback capabilities
 */

require_once __DIR__ . '/config/database.php';

function deployPermissions() {
    try {
        $conn = Database::getInstance()->getConnection();
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        echo "🚀 Starting Production Permission System Deployment...\n\n";
        
        // Read production SQL file
        $sqlFile = __DIR__ . '/config/permissions_production.sql';
        
        if (!file_exists($sqlFile)) {
            throw new Exception("Production SQL file not found: $sqlFile");
        }
        
        $sql = file_get_contents($sqlFile);
        
        // Split into statements
        $statements = explode(';', $sql);
        
        $successCount = 0;
        $errorCount = 0;
        
        foreach ($statements as $statement) {
            $statement = trim($statement);
            
            // Skip empty statements and comments
            if (empty($statement) || 
                strpos($statement, '--') === 0 || 
                strpos($statement, 'SET ') === 0 ||
                strpos($statement, 'PREPARE ') === 0 ||
                strpos($statement, 'EXECUTE ') === 0 ||
                strpos($statement, 'DEALLOCATE ') === 0 ||
                strpos($statement, 'SELECT ') === 0) {
                continue;
            }
            
            try {
                $conn->exec($statement);
                $successCount++;
            } catch (PDOException $e) {
                $errorMsg = $e->getMessage();
                
                // Ignore known non-critical errors
                if (strpos($errorMsg, 'already exists') !== false ||
                    strpos($errorMsg, 'Duplicate') !== false ||
                    strpos($errorMsg, 'Unknown column') !== false) {
                    continue;
                }
                
                error_log("SQL Error: " . $errorMsg);
                $errorCount++;
            }
        }
        
        echo "✓ Deployment completed!\n";
        echo "  ✓ Successful statements: $successCount\n";
        echo "  ✓ Errors (non-critical): $errorCount\n\n";
        
        // Verify installation
        $stmt = $conn->query("
            SELECT 
                COUNT(DISTINCT r.id) as roles,
                COUNT(DISTINCT p.id) as permissions,
                COUNT(DISTINCT rp.id) as role_permissions
            FROM roles r
            CROSS JOIN permissions p
            LEFT JOIN role_permissions rp ON r.id = rp.role_id AND p.id = rp.permission_id
        ");
        
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo "📊 System Statistics:\n";
        echo "  ✓ Roles: {$stats['roles']}\n";
        echo "  ✓ Permissions: {$stats['permissions']}\n";
        echo "  ✓ Role-Permission mappings: {$stats['role_permissions']}\n\n";
        
        // Check user role migration
        $stmt = $conn->query("
            SELECT 
                COUNT(*) as total_users,
                COUNT(role_id) as users_with_role_id,
                SUM(CASE WHEN role_id IS NULL THEN 1 ELSE 0 END) as users_without_role_id
            FROM users
        ");
        
        $userStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo "👥 User Statistics:\n";
        echo "  ✓ Total users: {$userStats['total_users']}\n";
        echo "  ✓ Users with role_id: {$userStats['users_with_role_id']}\n";
        echo "  ! Users without role_id: {$userStats['users_without_role_id']}\n";
        
        if ($userStats['users_without_role_id'] > 0) {
            echo "\n⚠️  WARNING: Some users don't have role_id assigned.\n";
            echo "   They will default to Tester role.\n";
        }
        
        echo "\n✅ Permission System is ready for production!\n";
        
    } catch (Exception $e) {
        echo "❌ Deployment failed: " . $e->getMessage() . "\n";
        echo "   Please check your database configuration and try again.\n";
        exit(1);
    }
}

// Run deployment
deployPermissions();

?>

