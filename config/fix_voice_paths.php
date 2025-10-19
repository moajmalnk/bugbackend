<?php
require_once __DIR__ . '/database.php';

try {
    $conn = Database::getInstance()->getConnection();
    
    // Fix voice message paths
    $stmt = $conn->prepare("
        UPDATE chat_messages 
        SET voice_file_path = REPLACE(voice_file_path, 'voice_messages', 'voice_notes')
        WHERE voice_file_path LIKE '%voice_messages%'
    ");
    
    $stmt->execute();
    $affected = $stmt->rowCount();
    
    echo "✅ Fixed $affected voice message paths\n";
    echo "Changed 'voice_messages' to 'voice_notes'\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>

