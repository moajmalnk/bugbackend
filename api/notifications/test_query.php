<?php
/**
 * Test Query - Direct Database Query Test
 * This helps diagnose why notifications aren't showing
 * 
 * Usage: https://bugbackend.bugricer.com/api/notifications/test_query.php?token=YOUR_TOKEN
 */

require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../BaseAPI.php';

header('Content-Type: application/json');

try {
    $api = new BaseAPI();
    $userData = $api->validateToken();
    
    if (!$userData || !isset($userData->user_id)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    $userId = (string)$userData->user_id;
    $conn = $api->getConnection();
    
    $results = [
        'user_id' => $userId,
        'user_id_type' => gettype($userId),
        'tests' => []
    ];
    
    // Test 1: Count user_notifications with direct match
    $test1 = $conn->prepare("SELECT COUNT(*) as count FROM user_notifications WHERE user_id = ?");
    $test1->execute([$userId]);
    $count1 = $test1->fetch(PDO::FETCH_ASSOC)['count'];
    $results['tests']['direct_user_id_match'] = (int)$count1;
    
    // Test 2: Count with CAST
    $test2 = $conn->prepare("SELECT COUNT(*) as count FROM user_notifications WHERE CAST(user_id AS CHAR) = CAST(? AS CHAR)");
    $test2->execute([$userId]);
    $count2 = $test2->fetch(PDO::FETCH_ASSOC)['count'];
    $results['tests']['cast_user_id_match'] = (int)$count2;
    
    // Test 3: Direct JOIN query
    $test3 = $conn->prepare("
        SELECT COUNT(*) as count
        FROM user_notifications un
        JOIN notifications n ON un.notification_id = n.id
        WHERE un.user_id = ?
    ");
    $test3->execute([$userId]);
    $count3 = $test3->fetch(PDO::FETCH_ASSOC)['count'];
    $results['tests']['join_query_count'] = (int)$count3;
    
    // Test 4: Get actual notifications with JOIN
    $test4 = $conn->prepare("
        SELECT 
            n.id,
            n.type,
            n.title,
            n.message,
            un.user_id,
            un.read
        FROM user_notifications un
        JOIN notifications n ON un.notification_id = n.id
        WHERE un.user_id = ?
        ORDER BY n.created_at DESC
        LIMIT 5
    ");
    $test4->execute([$userId]);
    $notifications4 = $test4->fetchAll(PDO::FETCH_ASSOC);
    $results['tests']['join_query_results'] = $notifications4;
    $results['tests']['join_query_results_count'] = count($notifications4);
    
    // Test 5: Sample user_ids from database
    $test5 = $conn->query("SELECT DISTINCT user_id FROM user_notifications LIMIT 10");
    $sampleUserIds = $test5->fetchAll(PDO::FETCH_COLUMN);
    $results['tests']['sample_user_ids_in_db'] = $sampleUserIds;
    
    // Test 6: Check if user_id exists in users table
    $test6 = $conn->prepare("SELECT id, username, role FROM users WHERE id = ?");
    $test6->execute([$userId]);
    $userInfo = $test6->fetch(PDO::FETCH_ASSOC);
    $results['tests']['user_exists'] = $userInfo ? true : false;
    $results['tests']['user_info'] = $userInfo;
    
    // Test 7: Try to find user_id with LIKE
    $test7 = $conn->prepare("SELECT DISTINCT user_id FROM user_notifications WHERE user_id LIKE ? LIMIT 5");
    $test7->execute(["%$userId%"]);
    $likeMatches = $test7->fetchAll(PDO::FETCH_COLUMN);
    $results['tests']['like_pattern_matches'] = $likeMatches;
    
    $results['success'] = true;
    echo json_encode($results, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
}

