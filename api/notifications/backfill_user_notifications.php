<?php
/**
 * Backfill User Notifications
 * 
 * This script links existing notifications to users based on:
 * 1. Project membership (for project-specific notifications)
 * 2. User roles (for role-based notifications)
 * 3. All admins (for admin notifications)
 * 
 * Run this ONCE to migrate existing notifications to the user_notifications table
 * 
 * Usage:
 * - Via browser: https://bugbackend.bugricer.com/api/notifications/backfill_user_notifications.php
 * - Via CLI: php backfill_user_notifications.php
 */

require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../BaseAPI.php';

header('Content-Type: application/json');

// Optional: Add admin authentication for production
/*
require_once __DIR__ . '/../BaseAPI.php';
try {
    $api = new BaseAPI();
    $userData = $api->validateToken();
    if (!$userData || $userData->role !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin access required']);
        exit();
    }
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit();
}
*/

try {
    $pdo = Database::getInstance()->getConnection();
    
    if (!$pdo) {
        throw new Exception('Database connection failed');
    }
    
    $pdo->beginTransaction();
    
    $stats = [
        'total_notifications' => 0,
        'notifications_processed' => 0,
        'user_notifications_created' => 0,
        'errors' => []
    ];
    
    // Get total count
    $totalCount = $pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
    $stats['total_notifications'] = (int)$totalCount;
    
    echo "Starting backfill for {$totalCount} notifications...\n";
    
    // Get all notifications
    $notifications = $pdo->query("
        SELECT 
            id, 
            type, 
            project_id, 
            bug_id,
            entity_type,
            entity_id,
            created_by
        FROM notifications 
        ORDER BY created_at ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $stats['notifications_processed'] = count($notifications);
    
    // Get all admins
    $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
    
    // Get all users by role for quick lookup
    $developers = $pdo->query("SELECT id FROM users WHERE role = 'developer'")->fetchAll(PDO::FETCH_COLUMN);
    $testers = $pdo->query("SELECT id FROM users WHERE role = 'tester'")->fetchAll(PDO::FETCH_COLUMN);
    
    // Prepare insert statement
    $insertStmt = $pdo->prepare("
        INSERT IGNORE INTO user_notifications (notification_id, user_id, `read`, created_at)
        VALUES (?, ?, 0, NOW())
    ");
    
    foreach ($notifications as $notification) {
        $notificationId = $notification['id'];
        $type = $notification['type'];
        $projectId = $notification['project_id'];
        $createdBy = $notification['created_by'] ?? 'system';
        
        $usersToNotify = [];
        
        // Determine which users should receive this notification
        switch ($type) {
            case 'bug_created':
            case 'new_bug':
                // Notify developers and admins
                $usersToNotify = array_merge($developers, $admins);
                
                // If project-specific, only notify project members
                if ($projectId) {
                    $projectMembers = $pdo->prepare("
                        SELECT DISTINCT pm.user_id 
                        FROM project_members pm
                        JOIN users u ON u.id = pm.user_id
                        WHERE pm.project_id = ? 
                        AND u.role IN ('developer', 'admin')
                    ");
                    $projectMembers->execute([$projectId]);
                    $projectDevIds = $projectMembers->fetchAll(PDO::FETCH_COLUMN);
                    
                    // Merge with admins (admins always get notified)
                    $usersToNotify = array_unique(array_merge($projectDevIds, $admins));
                }
                break;
                
            case 'bug_fixed':
            case 'status_change':
                // Notify testers and admins
                $usersToNotify = array_merge($testers, $admins);
                
                // If project-specific, only notify project members
                if ($projectId) {
                    $projectMembers = $pdo->prepare("
                        SELECT DISTINCT pm.user_id 
                        FROM project_members pm
                        JOIN users u ON u.id = pm.user_id
                        WHERE pm.project_id = ? 
                        AND u.role IN ('tester', 'admin')
                    ");
                    $projectMembers->execute([$projectId]);
                    $projectTesterIds = $projectMembers->fetchAll(PDO::FETCH_COLUMN);
                    
                    $usersToNotify = array_unique(array_merge($projectTesterIds, $admins));
                }
                break;
                
            case 'update_created':
            case 'new_update':
            case 'task_created':
            case 'meet_created':
            case 'doc_created':
            case 'project_created':
                // Notify all project members and admins
                if ($projectId) {
                    $projectMembers = $pdo->prepare("
                        SELECT DISTINCT pm.user_id 
                        FROM project_members pm
                        WHERE pm.project_id = ?
                    ");
                    $projectMembers->execute([$projectId]);
                    $projectMemberIds = $projectMembers->fetchAll(PDO::FETCH_COLUMN);
                    
                    $usersToNotify = array_unique(array_merge($projectMemberIds, $admins));
                } else {
                    // If no project, notify all admins
                    $usersToNotify = $admins;
                }
                break;
                
            default:
                // For any other type, notify all admins
                $usersToNotify = $admins;
                break;
        }
        
        // Ensure we always notify at least admins
        if (empty($usersToNotify)) {
            $usersToNotify = $admins;
        }
        
        // Remove duplicates and ensure user exists
        $usersToNotify = array_unique(array_filter($usersToNotify));
        
        // Create user_notifications entries
        foreach ($usersToNotify as $userId) {
            try {
                $insertStmt->execute([$notificationId, $userId]);
                if ($insertStmt->rowCount() > 0) {
                    $stats['user_notifications_created']++;
                }
            } catch (PDOException $e) {
                // Ignore duplicate key errors (already exists)
                if ($e->getCode() != 23000) {
                    $stats['errors'][] = "Notification {$notificationId} for user {$userId}: " . $e->getMessage();
                }
            }
        }
        
        // Progress indicator
        if ($stats['notifications_processed'] % 100 == 0) {
            echo "Processed {$stats['notifications_processed']} notifications...\n";
        }
    }
    
    $pdo->commit();
    
    // Get final counts
    $finalUserNotificationCount = $pdo->query("SELECT COUNT(*) FROM user_notifications")->fetchColumn();
    $finalUnreadCount = $pdo->query("SELECT COUNT(*) FROM user_notifications WHERE `read` = 0")->fetchColumn();
    
    $stats['final_user_notifications_count'] = (int)$finalUserNotificationCount;
    $stats['final_unread_count'] = (int)$finalUnreadCount;
    $stats['success'] = true;
    $stats['message'] = 'Backfill completed successfully';
    
    echo json_encode($stats, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Backfill failed: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
    error_log("Backfill error: " . $e->getMessage());
}

