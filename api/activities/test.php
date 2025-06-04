<?php
require_once __DIR__ . '/../BaseAPI.php';

try {
    $api = new BaseAPI();
    
    // Test database connection
    $conn = $api->getConnection();
    if (!$conn) {
        throw new Exception("Database connection failed");
    }
    
    // Check if project_activities table exists
    $tableExists = $api->fetchSingleCached(
        "SHOW TABLES LIKE 'project_activities'",
        [],
        'table_check',
        60
    );
    
    $response = [
        'database_connected' => true,
        'table_exists' => !empty($tableExists),
        'table_name' => $tableExists ? array_values($tableExists)[0] : null
    ];
    
    // If table exists, get table structure
    if (!empty($tableExists)) {
        $structure = $api->fetchCached(
            "DESCRIBE project_activities",
            [],
            'table_structure',
            300
        );
        $response['table_structure'] = $structure;
        
        // Count rows
        $count = $api->fetchSingleCached(
            "SELECT COUNT(*) as count FROM project_activities",
            [],
            'activity_count',
            60
        );
        $response['row_count'] = $count['count'] ?? 0;
        
        // Get sample data
        $sample = $api->fetchCached(
            "SELECT * FROM project_activities ORDER BY created_at DESC LIMIT 3",
            [],
            'sample_activities',
            60
        );
        $response['sample_data'] = $sample;
    }
    
    $api->sendJsonResponse(200, "Test completed", $response);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
?> 