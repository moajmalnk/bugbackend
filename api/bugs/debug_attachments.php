<?php
/**
 * Debug endpoint to check attachments for a bug
 * Usage: /api/bugs/debug_attachments.php?bug_id=YOUR_BUG_ID
 */

require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../BaseAPI.php';

header('Content-Type: application/json');

$bugId = $_GET['bug_id'] ?? null;

if (!$bugId) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'bug_id parameter is required'
    ]);
    exit;
}

try {
    $api = new BaseAPI();
    $userData = $api->validateToken();
    
    if (!$userData || !isset($userData->user_id)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    $pdo = Database::getInstance()->getConnection();
    
    // Check bug exists
    $bugStmt = $pdo->prepare("SELECT id, title FROM bugs WHERE CAST(id AS CHAR) = CAST(? AS CHAR)");
    $bugStmt->execute([$bugId]);
    $bug = $bugStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$bug) {
        echo json_encode([
            'success' => false,
            'message' => 'Bug not found',
            'bug_id_searched' => $bugId,
            'bug_id_type' => gettype($bugId)
        ]);
        exit;
    }
    
    // Try multiple query methods
    $results = [];
    
    // Method 1: Direct comparison
    $stmt1 = $pdo->prepare("SELECT * FROM bug_attachments WHERE bug_id = ?");
    $stmt1->execute([$bugId]);
    $results['direct_query'] = $stmt1->fetchAll(PDO::FETCH_ASSOC);
    
    // Method 2: CAST comparison
    $stmt2 = $pdo->prepare("SELECT * FROM bug_attachments WHERE CAST(bug_id AS CHAR) = CAST(? AS CHAR)");
    $stmt2->execute([$bugId]);
    $results['cast_query'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    
    // Method 3: BINARY comparison
    $stmt3 = $pdo->prepare("SELECT * FROM bug_attachments WHERE BINARY bug_id = BINARY ?");
    $stmt3->execute([$bugId]);
    $results['binary_query'] = $stmt3->fetchAll(PDO::FETCH_ASSOC);
    
    // Get sample bug_ids from attachments table
    $sampleStmt = $pdo->query("SELECT DISTINCT bug_id FROM bug_attachments LIMIT 20");
    $sampleBugIds = $sampleStmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get total count
    $countStmt = $pdo->prepare("SELECT COUNT(*) as count FROM bug_attachments WHERE CAST(bug_id AS CHAR) = CAST(? AS CHAR)");
    $countStmt->execute([$bugId]);
    $count = $countStmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'bug' => $bug,
        'bug_id_searched' => $bugId,
        'bug_id_type' => gettype($bugId),
        'attachments_count' => (int)$count['count'],
        'query_results' => [
            'direct_query_count' => count($results['direct_query']),
            'cast_query_count' => count($results['cast_query']),
            'binary_query_count' => count($results['binary_query']),
        ],
        'attachments_found' => $results['cast_query'],
        'sample_bug_ids_in_table' => $sampleBugIds,
        'match_found' => in_array($bugId, $sampleBugIds, true)
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}

