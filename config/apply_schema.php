<?php
require_once 'database.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    $sql = file_get_contents(__DIR__ . '/google_docs_schema_fixed.sql');
    
    // Split by semicolon and execute each statement
    $statements = explode(';', $sql);
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement)) {
            $pdo->exec($statement);
            echo "Executed: " . substr($statement, 0, 50) . "...\n";
        }
    }
    echo "Schema update completed!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
