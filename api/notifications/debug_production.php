<?php
/**
 * Production Debug Script for Notifications
 * This script helps debug notification issues in production
 * 
 * Usage: https://bugbackend.bugricer.com/api/notifications/debug_production.php?token=YOUR_TOKEN
 */

// Handle CORS
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../BaseAPI.php';

header('Content-Type: application/json');

// Get token from query parameter (for easy browser testing)
$token = $_GET['token'] ?? null;

// If no token in query, try to get from Authorization header
if (!$token && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $token = $matches[1];
    }
}

$debug = [
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => PHP_VERSION,
    'token_provided' => !empty($token),
    'checks' => []
];

try {
    // 1. Check database connection
    try {
        $pdo = Database::getInstance()->getConnection();
        if ($pdo) {
            $debug['checks']['database_connection'] = 'OK';
            
            // Test query
            $testQuery = $pdo->query("SELECT 1");
            $debug['checks']['database_query'] = 'OK';
        } else {
            $debug['checks']['database_connection'] = 'FAILED';
        }
    } catch (Exception $e) {
        $debug['checks']['database_connection'] = 'ERROR: ' . $e->getMessage();
    }
    
    // 2. Check if tables exist
    if ($pdo) {
        try {
            $tables = ['notifications', 'user_notifications', 'users'];
            foreach ($tables as $table) {
                $check = $pdo->query("SHOW TABLES LIKE '$table'");
                $exists = $check->rowCount() > 0;
                $debug['checks']['table_' . $table] = $exists ? 'EXISTS' : 'MISSING';
                
                if ($exists) {
                    // Get row count
                    $count = $pdo->query("SELECT COUNT(*) as count FROM $table")->fetch(PDO::FETCH_ASSOC)['count'];
                    $debug['checks']['table_' . $table . '_count'] = (int)$count;
                }
            }
        } catch (Exception $e) {
            $debug['checks']['table_check'] = 'ERROR: ' . $e->getMessage();
        }
        
        // 3. Check notifications.type ENUM
        try {
            $enumCheck = $pdo->query("SHOW COLUMNS FROM notifications WHERE Field = 'type'");
            $typeInfo = $enumCheck->fetch(PDO::FETCH_ASSOC);
            $enum = $typeInfo['Type'] ?? 'NOT_FOUND';
            $debug['checks']['notifications_type_enum'] = $enum;
            
            // Check for required types
            $requiredTypes = ['bug_created', 'bug_fixed', 'update_created'];
            $missingTypes = [];
            foreach ($requiredTypes as $type) {
                if (stripos($enum, $type) === false) {
                    $missingTypes[] = $type;
                }
            }
            if (!empty($missingTypes)) {
                $debug['checks']['missing_enum_types'] = $missingTypes;
            } else {
                $debug['checks']['required_enum_types'] = 'ALL_PRESENT';
            }
        } catch (Exception $e) {
            $debug['checks']['enum_check'] = 'ERROR: ' . $e->getMessage();
        }
        
        // 4. Check created_by column
        try {
            $createdByCheck = $pdo->query("SHOW COLUMNS FROM notifications WHERE Field = 'created_by'");
            $createdByInfo = $createdByCheck->fetch(PDO::FETCH_ASSOC);
            $default = $createdByInfo['Default'] ?? 'NO_DEFAULT';
            $debug['checks']['created_by_default'] = $default;
        } catch (Exception $e) {
            $debug['checks']['created_by_check'] = 'ERROR: ' . $e->getMessage();
        }
    }
    
    // 5. Check authentication
    if ($token) {
        try {
            $api = new BaseAPI();
            $userData = null;
            
            // Manually validate token
            try {
                // BaseAPI validateToken might throw, so wrap it
                $userData = $api->validateToken();
            } catch (Exception $e) {
                $debug['checks']['token_validation'] = 'ERROR: ' . $e->getMessage();
            }
            
            if ($userData && isset($userData->user_id)) {
                $userId = (string)$userData->user_id;
                $debug['checks']['token_validation'] = 'VALID';
                $debug['user'] = [
                    'user_id' => $userId,
                    'username' => $userData->username ?? 'unknown',
                    'role' => $userData->role ?? 'unknown'
                ];
                
                // 6. Check user's notifications
                if ($pdo) {
                    try {
                        $userNotificationCount = $pdo->prepare("SELECT COUNT(*) as count FROM user_notifications WHERE user_id = ?");
                        $userNotificationCount->execute([$userId]);
                        $count = $userNotificationCount->fetch(PDO::FETCH_ASSOC)['count'];
                        $debug['checks']['user_notifications_count'] = (int)$count;
                        
                        // Check unread count
                        $unreadCount = $pdo->prepare("SELECT COUNT(*) as count FROM user_notifications WHERE user_id = ? AND `read` = 0");
                        $unreadCount->execute([$userId]);
                        $unread = $unreadCount->fetch(PDO::FETCH_ASSOC)['count'];
                        $debug['checks']['user_unread_count'] = (int)$unread;
                        
                        // Get sample notifications
                        $sampleQuery = $pdo->prepare("
                            SELECT n.id, n.type, n.title, un.`read`, un.created_at
                            FROM user_notifications un
                            JOIN notifications n ON un.notification_id = n.id
                            WHERE un.user_id = ?
                            ORDER BY n.created_at DESC
                            LIMIT 5
                        ");
                        $sampleQuery->execute([$userId]);
                        $samples = $sampleQuery->fetchAll(PDO::FETCH_ASSOC);
                        $debug['sample_notifications'] = $samples;
                        
                    } catch (Exception $e) {
                        $debug['checks']['user_notifications_check'] = 'ERROR: ' . $e->getMessage();
                    }
                }
            } else {
                $debug['checks']['token_validation'] = 'INVALID_OR_EXPIRED';
            }
        } catch (Exception $e) {
            $debug['checks']['authentication_check'] = 'ERROR: ' . $e->getMessage();
        }
    } else {
        $debug['checks']['token_validation'] = 'NO_TOKEN_PROVIDED';
        $debug['info'] = 'Provide token via ?token=YOUR_TOKEN or Authorization header';
    }
    
    // 7. Check file permissions and paths
    $debug['checks']['file_paths'] = [
        'get_all.php' => file_exists(__DIR__ . '/get_all.php') ? 'EXISTS' : 'MISSING',
        'NotificationManager.php' => file_exists(__DIR__ . '/../NotificationManager.php') ? 'EXISTS' : 'MISSING',
        'BaseAPI.php' => file_exists(__DIR__ . '/../BaseAPI.php') ? 'EXISTS' : 'MISSING',
    ];
    
    // 8. Check CORS headers
    $debug['checks']['cors_headers'] = [
        'origin' => $_SERVER['HTTP_ORIGIN'] ?? 'NOT_SET',
        'access_control_allow_origin' => 'SET_BY_CORS_CONFIG'
    ];
    
    $debug['status'] = 'OK';
    
} catch (Exception $e) {
    $debug['status'] = 'ERROR';
    $debug['error'] = $e->getMessage();
    $debug['trace'] = $e->getTraceAsString();
}

echo json_encode($debug, JSON_PRETTY_PRINT);

