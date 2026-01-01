<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $api = new BaseAPI();
    
    // Validate token and check admin access
    $decoded = $api->validateToken();
    if ($decoded->role !== 'admin') {
        $api->sendJsonResponse(403, 'Only admins can add project members');
        exit;
    }

    $data = $api->getRequestData();
    $project_id = $data['project_id'] ?? null;
    $user_id = $data['user_id'] ?? null;
    $role = $data['role'] ?? null;

    if (!$project_id || !$user_id || !$role) {
        $api->sendJsonResponse(400, 'Missing required fields: project_id, user_id, role');
        exit;
    }

    // Check if already assigned
    $existing = $api->fetchSingleCached(
        "SELECT * FROM project_members WHERE project_id = ? AND user_id = ?",
        [$project_id, $user_id]
    );
    
    if ($existing) {
        $api->sendJsonResponse(400, 'User already assigned to this project');
        exit;
    }

    // Insert member using prepared statement
    $stmt = $api->prepare("INSERT INTO project_members (project_id, user_id, role, joined_at) VALUES (?, ?, ?, NOW())");
    $result = $stmt->execute([$project_id, $user_id, $role]);
    
    if (!$result) {
        $api->sendJsonResponse(500, 'Failed to add member to project');
        exit;
    }

    // Clear related cache
    $api->clearCache('project_members_' . $project_id);
    $api->clearCache('project_members_list_' . $project_id);
    $api->clearCache('user_projects_' . $user_id);

    // Send response immediately for fast user experience
    $api->sendJsonResponse(200, 'Member added successfully to project');

    // Send notifications asynchronously (non-blocking) after response
    // This prevents blocking the API response and improves user experience
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request(); // Finish request to client immediately
    } else {
        // For non-FastCGI environments, set output buffering
        if (ob_get_level()) {
            ob_end_flush();
        }
        flush();
    }

    // Now send notifications in background (non-blocking)
    try {
        $conn = $api->getConnection();
        
        // Fetch user details (email, username, role)
        $userStmt = $conn->prepare("SELECT username, email, role FROM users WHERE id = ? LIMIT 1");
        $userStmt->execute([$user_id]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        
        // Fetch project details
        $projectStmt = $conn->prepare("SELECT name FROM projects WHERE id = ? LIMIT 1");
        $projectStmt->execute([$project_id]);
        $project = $projectStmt->fetch(PDO::FETCH_ASSOC);
        
        // Fetch admin details (who added the member)
        $adminStmt = $conn->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
        $adminStmt->execute([$decoded->user_id]);
        $admin = $adminStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && $project) {
            $username = $user['username'] ?? 'User';
            $userEmail = $user['email'] ?? null;
            $userRole = $user['role'] ?? 'user';
            $projectName = $project['name'] ?? 'Project';
            $addedByName = $admin['username'] ?? 'Admin';
            
            // Load utility files
            require_once __DIR__ . '/../../utils/whatsapp.php';
            require_once __DIR__ . '/../../utils/email.php';
            
            // Generate role-based project URL using helper function
            $projectLink = generateRoleBasedProjectUrl($userRole, $project_id);
            
            // Send email notification (non-blocking)
            if ($userEmail) {
                try {
                    error_log("📧 Sending project member added email notification to: $userEmail");
                    
                    $emailSent = sendProjectMemberAddedEmail(
                        $userEmail,
                        $username,
                        $projectName,
                        $role,
                        $addedByName,
                        $projectLink
                    );
                    
                    if ($emailSent) {
                        error_log("✅ Successfully sent project member added email to: $userEmail");
                    } else {
                        error_log("❌ Failed to send project member added email to: $userEmail");
                    }
                } catch (Exception $e) {
                    error_log("⚠️ Failed to send project member added email notification: " . $e->getMessage());
                }
            }
            
            // Send WhatsApp notification (non-blocking)
            try {
                error_log("📱 Sending project member added WhatsApp notification to user: $username");
                
                $whatsappSent = sendProjectMemberAddedWhatsApp(
                    $conn,
                    $user_id,
                    $project_id,
                    $role,
                    $decoded->user_id
                );
                
                if ($whatsappSent) {
                    error_log("✅ Successfully sent project member added WhatsApp notification");
                } else {
                    error_log("⚠️ Failed to send project member added WhatsApp notification (user may not have phone number)");
                }
            } catch (Exception $e) {
                error_log("⚠️ Failed to send project member added WhatsApp notification: " . $e->getMessage());
            }
        }
    } catch (Exception $e) {
        // Don't fail member addition if notifications fail
        error_log("⚠️ Error sending project member added notifications: " . $e->getMessage());
    }

} catch (Exception $e) {
    error_log("Error in add_member.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
