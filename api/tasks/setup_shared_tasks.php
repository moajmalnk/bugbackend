<?php
/**
 * Setup script for shared tasks tables
 * Run this once to create the necessary database tables
 */

require_once __DIR__ . '/../../config/database.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    
    // Read and execute the SQL schema
    $sql = file_get_contents(__DIR__ . '/../../config/shared_tasks_schema.sql');
    
    // Split by semicolon and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            $pdo->exec($statement);
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Shared tasks tables created successfully'
    ]);
    
} catch (PDOException $e) {
    error_log("Database error in setup_shared_tasks.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to create tables: ' . $e->getMessage()
    ]);
}

