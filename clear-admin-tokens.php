<?php
/**
 * Clear Google tokens for admin user to force re-authorization
 */

require_once 'config/database.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    
    // Get admin user ID
    $stmt = $pdo->prepare('SELECT id, username FROM users WHERE username = ? AND role = ?');
    $stmt->execute(['moajmalnk', 'admin']);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$admin) {
        echo "Admin user not found\n";
        exit;
    }
    
    echo "Found admin user: {$admin['username']} (ID: {$admin['id']})\n";
    
    // Clear existing Google tokens for admin
    $stmt = $pdo->prepare('DELETE FROM google_tokens WHERE bugricer_user_id = ?');
    $result = $stmt->execute([$admin['id']]);
    
    if ($result) {
        echo "✅ Cleared existing Google tokens for admin user\n";
        echo "Admin user will now need to re-authorize with Google.\n";
        echo "Re-auth URL: https://bugbackend.bugricer.com/api/oauth/production-reauth.php?user_id={$admin['id']}\n";
    } else {
        echo "❌ Failed to clear tokens\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
