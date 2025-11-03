<?php
/**
 * Fix Missing Notifications
 * 
 * This script finds notifications that exist in the notifications table
 * but are NOT linked to any users in user_notifications table
 * and links them appropriately
 * 
 * Run this to fix any missing notification assignments
 */

require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

try {
    $pdo = Database::getInstance()->getConnection();
    
    if (!$pdo) {
        throw new Exception('Database connection failed');
    }
    
    $pdo->beginTransaction();
    
    // Find notifications that are not linked to any users
    $orphanedNotifications = $pdo->query("
        SELECT n.id, n.type, n.title, n.project_id
        FROM notifications n
        WHERE NOT EXISTS (
            SELECT 1 FROM user_notifications un 
            WHERE un.notification_id = n.id
        )
        ORDER BY n.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $stats = [
        'orphaned_notifications' => count($orphanedNotifications),
        'notifications_fixed' => 0,
        'user_notifications_created' => 0,
        'errors' => []
    ];
    
    if (empty($orphanedNotifications)) {
        echo json_encode([
            'success' => true,
            'message' => 'No orphaned notifications found. All notifications are properly linked.',
            'stats' => $stats
        ], JSON_PRETTY_PRINT);
        exit();
    }
    
    // Get all admins
    $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
    $developers = $pdo->query("SELECT id FROM users WHERE role = 'developer'")->fetchAll(PDO::FETCH_COLUMN);
    $testers = $pdo->query("SELECT id FROM users WHERE role = 'tester'")->fetchAll(PDO::FETCH_COLUMN);
    
    $insertStmt = $pdo->prepare("
        INSERT IGNORE INTO user_notifications (notification_id, user_id, `read`, created_at)
        VALUES (?, ?, 0, NOW())
    ");
    
    foreach ($orphanedNotifications as $notification) {
        $notificationId = $notification['id'];
        $type = $notification['type'];
        $projectId = $notification['project_id'] ?? null;
        
        $usersToNotify = [];
        
        // Determine recipients based on notification type
        switch ($type) {
            case 'bug_created':
            case 'new_bug':
                $usersToNotify = array_merge($developers, $admins);
                break;
                
            case 'bug_fixed':
            case 'status_change':
                $usersToNotify = array_merge($testers, $admins);
                break;
                
            case 'update_created':
            case 'new_update':
                // For updates, notify all project members + admins
                // If project_id exists and we can find project members
                if ($projectId) {
                    try {
                        $projectMembers = $pdo->prepare("
                            SELECT DISTINCT pm.user_id 
                            FROM project_members pm
                            WHERE pm.project_id = ?
                        ");
                        $projectMembers->execute([$projectId]);
                        $memberIds = $projectMembers->fetchAll(PDO::FETCH_COLUMN);
                        $usersToNotify = array_unique(array_merge($memberIds, $admins));
                    } catch (Exception $e) {
                        // If project lookup fails, just use admins
                        $usersToNotify = $admins;
                    }
                } else {
                    $usersToNotify = $admins;
                }
                break;
                
            case 'task_created':
            case 'meet_created':
            case 'doc_created':
            case 'project_created':
                // For other project-related notifications
                if ($projectId) {
                    try {
                        $projectMembers = $pdo->prepare("
                            SELECT DISTINCT pm.user_id 
                            FROM project_members pm
                            WHERE pm.project_id = ?
                        ");
                        $projectMembers->execute([$projectId]);
                        $memberIds = $projectMembers->fetchAll(PDO::FETCH_COLUMN);
                        $usersToNotify = array_unique(array_merge($memberIds, $admins));
                    } catch (Exception $e) {
                        $usersToNotify = $admins;
                    }
                } else {
                    $usersToNotify = $admins;
                }
                break;
                
            default:
                // Fallback: notify all admins
                $usersToNotify = $admins;
                break;
        }
        
        // Ensure we always notify at least admins
        if (empty($usersToNotify)) {
            $usersToNotify = $admins;
        }
        
        $usersToNotify = array_unique(array_filter($usersToNotify));
        
        // Link notification to users
        foreach ($usersToNotify as $userId) {
            try {
                $insertStmt->execute([$notificationId, $userId]);
                if ($insertStmt->rowCount() > 0) {
                    $stats['user_notifications_created']++;
                }
            } catch (PDOException $e) {
                if ($e->getCode() != 23000) {
                    $stats['errors'][] = "Notification {$notificationId} for user {$userId}: " . $e->getMessage();
                }
            }
        }
        
        $stats['notifications_fixed']++;
    }
    
    $pdo->commit();
    
    // Get final counts
    $totalNotifications = $pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
    $totalUserNotifications = $pdo->query("SELECT COUNT(*) FROM user_notifications")->fetchColumn();
    $unlinkedCount = $pdo->query("
        SELECT COUNT(*) 
        FROM notifications n
        WHERE NOT EXISTS (
            SELECT 1 FROM user_notifications un WHERE un.notification_id = n.id
        )
    ")->fetchColumn();
    
    $stats['final_total_notifications'] = (int)$totalNotifications;
    $stats['final_user_notifications'] = (int)$totalUserNotifications;
    $stats['remaining_unlinked'] = (int)$unlinkedCount;
    $stats['success'] = true;
    
    echo json_encode([
        'success' => true,
        'message' => 'Fixed missing notification assignments',
        'stats' => $stats
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Fix failed: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
    error_log("Fix missing notifications error: " . $e->getMessage());
}

