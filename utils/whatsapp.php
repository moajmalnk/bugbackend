<?php
/**
 * WhatsApp utility functions for BugRicer
 */

// WhatsApp API configuration
define('WHATSAPP_API_URL', 'http://148.251.129.118/wapp/api/send');
define('WHATSAPP_API_KEY', 'ff7a6e6fcca94f7f9a4cfa444b494188');
define('WHATSAPP_ADMIN_NUMBERS', '8848676627');

/**
 * Normalize phone number for WhatsApp API
 * Removes + signs, spaces, and other non-digit characters
 * Also tries to identify and format Qatar numbers correctly
 * 
 * @param string $phone Phone number to normalize
 * @return array Array of possible formats to try
 */
function getPhoneFormatsForWhatsApp($phone) {
    // Remove all non-digit characters (+, spaces, dashes, etc.)
    $digits = preg_replace('/\D/', '', $phone);
    error_log("📱 Phone normalization: '$phone' -> digits only: '$digits'");
    
    $formats = [];
    
    // Check if it's a Qatar number (starts with 974)
    if (strlen($digits) == 11 && substr($digits, 0, 3) == '974') {
        // Qatar number: 97450372450
        // Try different formats that WhatsApp API might accept
        $formats[] = $digits; // 97450372450 (full with country code - primary)
        $localNumber = substr($digits, 3); // 50372450
        $formats[] = '0' . $localNumber; // 050372450 (with leading 0, local format)
        $formats[] = $localNumber; // 50372450 (local number without country code)
        error_log("📱 Qatar number detected (11 digits). Formats to try: " . implode(', ', $formats));
    } elseif (strlen($digits) == 8) {
        // Might be local Qatar number
        $formats[] = '974' . $digits; // Add country code (primary)
        $formats[] = '0' . $digits; // Add leading zero
        $formats[] = $digits; // Try as-is
        error_log("📱 Possible local Qatar number (8 digits). Formats to try: " . implode(', ', $formats));
    } else {
        // For other numbers, just use the cleaned digits
        $formats[] = $digits;
    }
    
    // Remove duplicates and return
    return array_values(array_unique($formats));
}

/**
 * Send WhatsApp message using the API (single attempt)
 * 
 * @param string $mobile Phone number in exact format
 * @param string $message The message to send
 * @return array ['success' => bool, 'response' => string, 'httpCode' => int]
 */
function sendWhatsAppMessageSingle($mobile, $message) {
    try {
        $url = WHATSAPP_API_URL . '?apikey=' . urlencode(WHATSAPP_API_KEY) . 
               '&mobile=' . urlencode($mobile) . 
               '&msg=' . urlencode($message);
        
        error_log("📱 Trying format: $mobile");
        
        // Use cURL to send the request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            error_log("❌ WhatsApp API cURL error for $mobile: $curlError");
            return ['success' => false, 'response' => $curlError, 'httpCode' => 0, 'error' => 'curl_error'];
        }
        
        error_log("📱 WhatsApp API Response for $mobile (HTTP $httpCode): " . substr($response, 0, 500));
        
        // Consider success if HTTP code is 200
        $isSuccess = false;
        if ($httpCode == 200) {
            // Check response content for success indicators
            $responseLower = strtolower($response);
            if (strpos($responseLower, 'success') !== false || 
                strpos($responseLower, 'sent') !== false ||
                strpos($responseLower, 'ok') !== false ||
                empty(trim($response))) {
                $isSuccess = true;
            }
        }
        
        return [
            'success' => $isSuccess, 
            'response' => $response, 
            'httpCode' => $httpCode,
            'format' => $mobile
        ];
        
    } catch (Exception $e) {
        error_log("❌ WhatsApp exception for $mobile: " . $e->getMessage());
        return ['success' => false, 'response' => $e->getMessage(), 'httpCode' => 0, 'error' => 'exception'];
    }
}

/**
 * Send WhatsApp message using the API
 * Tries multiple formats for Qatar numbers if needed
 * 
 * @param string $mobile Phone number
 * @param string $message The message to send
 * @return bool Success status
 */
function sendWhatsAppMessage($mobile, $message) {
    // Get all possible formats for this phone number
    $formats = getPhoneFormatsForWhatsApp($mobile);
    
    error_log("📱 sendWhatsAppMessage called - Original: $mobile, Will try " . count($formats) . " format(s)");
    
    // Try each format until one succeeds
    foreach ($formats as $format) {
        $result = sendWhatsAppMessageSingle($format, $message);
        
        if ($result['success']) {
            error_log("✅ WhatsApp message sent successfully using format: $format");
            return true;
        } else {
            error_log("❌ Format '$format' failed (HTTP {$result['httpCode']}): " . substr($result['response'], 0, 200));
        }
        
        // Small delay between attempts
        if (count($formats) > 1) {
            usleep(300000); // 0.3 second delay
        }
    }
    
    // If we get here, all formats failed
    error_log("❌ All formats failed for phone number: $mobile");
    return false;
}

/**
 * Format work update data into WhatsApp-friendly message
 * 
 * @param string $userName User's name
 * @param string $userEmail User's email
 * @param array $submissionData Submission data
 * @return string Formatted WhatsApp message
 */
function formatWorkUpdateForWhatsApp($userName, $userEmail, $submissionData) {
    // Format date
    $date = $submissionData['submission_date'] ?? date('Y-m-d');
    $dateFormatted = date('j/n/Y l', strtotime($date));
    
    // Format start time
    $startTime = $submissionData['start_time'] ?? null;
    $startTimeFormatted = $startTime ? date('h:i A', strtotime($startTime)) : '----';
    
    // Hours worked
    $hours = number_format((float)($submissionData['hours_today'] ?? 0), 2);
    $overtimeHours = number_format((float)($submissionData['overtime_hours'] ?? 0), 2);
    $regularHours = min((float)($submissionData['hours_today'] ?? 0), 8);
    
    // Tasks
    $completedTasks = trim($submissionData['completed_tasks'] ?? '');
    $pendingTasks = trim($submissionData['pending_tasks'] ?? '');
    $ongoingTasks = trim($submissionData['ongoing_tasks'] ?? '');
    $upcomingTasks = trim($submissionData['notes'] ?? '');
    
    // Count items
    $countItems = function($txt) {
        if (empty($txt)) return 0;
        $lines = array_filter(array_map('trim', explode("\n", $txt)), function($x){ return $x !== ''; });
        return count($lines);
    };
    
    $completedCount = $countItems($completedTasks);
    $pendingCount = $countItems($pendingTasks);
    $ongoingCount = $countItems($ongoingTasks);
    $upcomingCount = $countItems($upcomingTasks);
    
    $isUpdate = isset($submissionData['is_update']) && $submissionData['is_update'];
    $actionText = $isUpdate ? 'Updated' : 'Submitted';
    
    // Build WhatsApp message (concise format)
    $message = "🧾 *BugRicer Daily Work Update*\n";
    $message .= "━━━━━━━━━━━━━━━━━━━━\n\n";
    $message .= "📌 *Status:* $actionText\n";
    $message .= "👤 *User:* $userName\n";
    if ($userEmail) {
        $message .= "📧 $userEmail\n";
    }
    $message .= "\n";
    $message .= "📅 *Date:* $dateFormatted\n";
    $message .= "🕘 *Start Time:* $startTimeFormatted\n";
    $message .= "⏱ *Working Hours:* $hours Hours\n";
    
    if ($overtimeHours > 0) {
        $message .= "📊 *Regular:* $regularHours Hours\n";
        $message .= "⏰ *Overtime:* $overtimeHours Hours\n";
    }
    
    $message .= "\n━━━━━━━━━━━━━━━━━━━━\n";
    $message .= "*Tasks Summary:*\n";
    
    if ($completedCount > 0) {
        $message .= "\n✅ *Completed ($completedCount):*\n";
        // Truncate if too long (WhatsApp has limits)
        $completedPreview = $completedTasks;
        if (strlen($completedPreview) > 200) {
            $completedPreview = substr($completedPreview, 0, 197) . '...';
        }
        $message .= $completedPreview . "\n";
    }
    
    if ($pendingCount > 0) {
        $message .= "\n⌛ *Pending ($pendingCount):*\n";
        $pendingPreview = $pendingTasks;
        if (strlen($pendingPreview) > 200) {
            $pendingPreview = substr($pendingPreview, 0, 197) . '...';
        }
        $message .= $pendingPreview . "\n";
    }
    
    if ($ongoingCount > 0) {
        $message .= "\n🔄 *Ongoing ($ongoingCount):*\n";
        $ongoingPreview = $ongoingTasks;
        if (strlen($ongoingPreview) > 200) {
            $ongoingPreview = substr($ongoingPreview, 0, 197) . '...';
        }
        $message .= $ongoingPreview . "\n";
    }
    
    if ($upcomingCount > 0) {
        $message .= "\n🔥 *Upcoming ($upcomingCount):*\n";
        $upcomingPreview = $upcomingTasks;
        if (strlen($upcomingPreview) > 200) {
            $upcomingPreview = substr($upcomingPreview, 0, 197) . '...';
        }
        $message .= $upcomingPreview . "\n";
    }
    
    $message .= "\n━━━━━━━━━━━━━━━━━━━━\n";
    $message .= "⏰ Submitted: " . date('d/m/Y H:i:s') . "\n";
    $message .= "\n🐞 _BugRicer Automated Notification_";
    
    return $message;
}

/**
 * Send daily work update WhatsApp notification to admins
 * 
 * @param string $userName User's name
 * @param string $userEmail User's email
 * @param array $submissionData Submission data
 * @return array Results for each phone number
 */
function sendDailyWorkUpdateWhatsAppToAdmins($userName, $userEmail, $submissionData) {
    try {
        error_log("📱 sendDailyWorkUpdateWhatsAppToAdmins called");
        error_log("📱 User: $userName ($userEmail)");
        
        // Format the message
        $message = formatWorkUpdateForWhatsApp($userName, $userEmail, $submissionData);
        
        error_log("📱 Formatted WhatsApp message length: " . strlen($message) . " characters");
        error_log("📱 WhatsApp message preview: " . substr($message, 0, 200) . "...");
        
        // Split phone numbers and send individually
        $phoneNumbers = explode(',', WHATSAPP_ADMIN_NUMBERS);
        $results = [];
        
        foreach ($phoneNumbers as $phoneNumber) {
            $phoneNumber = trim($phoneNumber);
            if (empty($phoneNumber)) {
                error_log("⚠️ Skipping empty phone number");
                continue;
            }
            
            error_log("📱 Processing phone number: '$phoneNumber'");
            
            $result = sendWhatsAppMessage($phoneNumber, $message);
            $results[$phoneNumber] = $result;
            
            if ($result) {
                error_log("✅ Successfully sent daily work update WhatsApp to: $phoneNumber");
            } else {
                error_log("❌ Failed to send daily work update WhatsApp to: $phoneNumber (tried multiple formats)");
            }
            
            // Add a small delay between messages to avoid rate limiting
            if (count($phoneNumbers) > 1) {
                usleep(500000); // 0.5 second delay
            }
        }
        
        // Return true if at least one message was sent successfully
        $success = in_array(true, $results);
        
        if ($success) {
            error_log("✅ At least one WhatsApp message sent successfully to admins");
        } else {
            error_log("❌ Failed to send WhatsApp messages to all admin numbers");
        }
        
        return $success;
        
    } catch (Exception $e) {
        // Don't fail the submission if WhatsApp fails
        error_log("⚠️ Exception in sendDailyWorkUpdateWhatsAppToAdmins: " . $e->getMessage());
        error_log("⚠️ Exception trace: " . $e->getTraceAsString());
        return false;
    }
}

/**
 * Get project developers for a project
 * 
 * @param PDO $conn Database connection
 * @param string $projectId Project ID
 * @return array Array of developer user IDs
 */
function getProjectDevelopers($conn, $projectId) {
    if (empty($projectId)) {
        return [];
    }
    
    try {
        $query = "SELECT pm.user_id 
                  FROM project_members pm
                  JOIN users u ON pm.user_id = u.id
                  WHERE pm.project_id = ? AND u.role = 'developer'";
        $stmt = $conn->prepare($query);
        $stmt->execute([$projectId]);
        $developers = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return array_map('strval', $developers); // Convert to strings
    } catch (Exception $e) {
        error_log("❌ Error getting project developers: " . $e->getMessage());
        return [];
    }
}

/**
 * Get all admin user IDs
 * 
 * @param PDO $conn Database connection
 * @return array Array of admin user IDs
 */
function getAllAdmins($conn) {
    try {
        $query = "SELECT id FROM users WHERE role = 'admin'";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $admins = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return array_map('strval', $admins); // Convert to strings
    } catch (Exception $e) {
        error_log("❌ Error getting all admins: " . $e->getMessage());
        return [];
    }
}

/**
 * Get project name by ID
 * 
 * @param PDO $conn Database connection
 * @param string $projectId Project ID
 * @return string|null Project name
 */
function getProjectName($conn, $projectId) {
    if (empty($projectId)) {
        return null;
    }
    
    try {
        $stmt = $conn->prepare("SELECT name FROM projects WHERE id = ? LIMIT 1");
        $stmt->execute([$projectId]);
        $project = $stmt->fetch(PDO::FETCH_ASSOC);
        return $project ? $project['name'] : null;
    } catch (Exception $e) {
        error_log("❌ Error getting project name: " . $e->getMessage());
        return null;
    }
}

/**
 * Get frontend base URL for generating shareable links
 * 
 * @return string Frontend base URL
 */
function getFrontendBaseUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    // Determine if we're in development or production
    $isLocal = false;
    $localHosts = ['localhost', '127.0.0.1', '::1'];
    foreach ($localHosts as $localHost) {
        if (strpos($host, $localHost) !== false) {
            $isLocal = true;
            break;
        }
    }
    
    if ($isLocal) {
        // Development - use localhost
        return 'http://localhost:8080';
    } else {
        // Production - use the bug tracker domain
        return 'https://bugs.bugricer.com';
    }
}

/**
 * Get phone numbers for user IDs from database
 * 
 * @param PDO $conn Database connection
 * @param array $userIds Array of user IDs
 * @return array Associative array of userId => phone (only users with phones)
 */
function getUserPhoneNumbers($conn, $userIds) {
    if (empty($userIds)) {
        return [];
    }
    
    // Remove duplicates and empty values
    $userIds = array_values(array_unique(array_filter($userIds)));
    
    if (empty($userIds)) {
        return [];
    }
    
    try {
        // Create placeholders for IN clause
        $placeholders = str_repeat('?,', count($userIds) - 1) . '?';
        $stmt = $conn->prepare("SELECT id, phone FROM users WHERE id IN ($placeholders) AND phone IS NOT NULL AND phone != ''");
        $stmt->execute($userIds);
        
        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($row['phone'])) {
                $result[$row['id']] = $row['phone'];
            }
        }
        
        error_log("📱 Retrieved " . count($result) . " phone numbers for " . count($userIds) . " users");
        return $result;
    } catch (Exception $e) {
        error_log("❌ Error getting user phone numbers: " . $e->getMessage());
        return [];
    }
}

/**
 * Get user phone numbers and roles from database
 * 
 * @param PDO $conn Database connection
 * @param array $userIds Array of user IDs
 * @return array Associative array of userId => ['phone' => string, 'role' => string] (only users with phones)
 */
function getUserPhoneNumbersWithRoles($conn, $userIds) {
    if (empty($userIds)) {
        return [];
    }
    
    // Remove duplicates and empty values
    $userIds = array_values(array_unique(array_filter($userIds)));
    
    if (empty($userIds)) {
        return [];
    }
    
    try {
        // Create placeholders for IN clause
        $placeholders = str_repeat('?,', count($userIds) - 1) . '?';
        $stmt = $conn->prepare("SELECT id, phone, role FROM users WHERE id IN ($placeholders) AND phone IS NOT NULL AND phone != ''");
        $stmt->execute($userIds);
        
        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($row['phone'])) {
                $result[$row['id']] = [
                    'phone' => $row['phone'],
                    'role' => $row['role'] ?? 'user'
                ];
            }
        }
        
        error_log("📱 Retrieved " . count($result) . " users with phone numbers and roles for " . count($userIds) . " users");
        return $result;
    } catch (Exception $e) {
        error_log("❌ Error getting user phone numbers with roles: " . $e->getMessage());
        return [];
    }
}

/**
 * Generate role-based task URL
 * 
 * @param string $role User role (admin, developer, tester, user)
 * @param int|string $taskId Task ID
 * @return string Role-based task URL
 */
function generateRoleBasedTaskUrl($role, $taskId) {
    $baseUrl = getFrontendBaseUrl();
    
    // Normalize role to lowercase
    $role = strtolower($role ?? 'user');
    
    // Map roles to URL paths
    $rolePath = 'user'; // Default fallback
    if (in_array($role, ['admin', 'developer', 'tester', 'user'])) {
        $rolePath = $role;
    }
    
    return $baseUrl . "/" . $rolePath . "/my-tasks?tab=shared-tasks";
}

/**
 * Format bug assignment message for WhatsApp
 * 
 * @param string $bugTitle Bug title
 * @param string $priority Bug priority
 * @param string $projectName Project name (optional)
 * @param string $assignedByName Name of person who assigned
 * @param string $bugLink Link to bug
 * @return string Formatted WhatsApp message
 */
function formatBugAssignmentForWhatsApp($bugTitle, $priority, $projectName = null, $assignedByName = null, $bugLink = null) {
    $message = "🐛 *New Bug Assigned to You*\n";
    $message .= "━━━━━━━━━━━━━━━━━━━━\n\n";
    $message .= "📌 *Title:* " . $bugTitle . "\n";
    $message .= "🎯 *Priority:* " . ucfirst(strtolower($priority ?: 'Medium')) . "\n";
    
    if ($projectName) {
        $message .= "📁 *Project:* " . $projectName . "\n";
    }
    
    if ($assignedByName) {
        $message .= "👤 *Assigned by:* " . $assignedByName . "\n";
    }
    
    $message .= "\n━━━━━━━━━━━━━━━━━━━━\n";
    
    if ($bugLink) {
        $message .= "🔗 View Bug:\n" . $bugLink . "\n";
    }
    
    $message .= "\n🐞 _BugRicer Automated Notification_";
    
    return $message;
}

/**
 * Format shared task assignment message for WhatsApp
 * 
 * @param string $taskTitle Task title
 * @param string $priority Task priority
 * @param string $dueDate Due date (optional)
 * @param string $assignedByName Name of person who assigned
 * @param string $taskLink Link to task
 * @return string Formatted WhatsApp message
 */
function formatTaskAssignmentForWhatsApp($taskTitle, $priority, $dueDate = null, $assignedByName = null, $taskLink = null) {
    $message = "📋 *New Task Assigned to You*\n";
    $message .= "━━━━━━━━━━━━━━━━━━━━\n\n";
    $message .= "📌 *Title:* " . $taskTitle . "\n";
    $message .= "🎯 *Priority:* " . ucfirst(strtolower($priority ?: 'Medium')) . "\n";
    
    if ($dueDate) {
        $formattedDate = date('d/m/Y', strtotime($dueDate));
        $message .= "📅 *Due Date:* " . $formattedDate . "\n";
    } else {
        $message .= "📅 *Due Date:* Not set\n";
    }
    
    if ($assignedByName) {
        $message .= "👤 *Assigned by:* " . $assignedByName . "\n";
    }
    
    $message .= "\n━━━━━━━━━━━━━━━━━━━━\n";
    
    if ($taskLink) {
        $message .= "🔗 View Task:\n" . $taskLink . "\n";
    }
    
    $message .= "\n🐞 _BugRicer Automated Notification_";
    
    return $message;
}

/**
 * Send bug assignment WhatsApp notification to assigned users
 * 
 * @param PDO $conn Database connection
 * @param array $assignedUserIds Array of user IDs assigned to the bug
 * @param string $bugId Bug ID
 * @param string $bugTitle Bug title
 * @param string $priority Bug priority
 * @param string|null $projectName Project name
 * @param string|null $assignedById User ID who assigned the bug
 * @return bool Success status (true if at least one message sent)
 */
function sendBugAssignmentWhatsApp($conn, $assignedUserIds, $bugId, $bugTitle, $priority = 'medium', $projectName = null, $assignedById = null) {
    try {
        error_log("📱 sendBugAssignmentWhatsApp called for bug: $bugId");
        
        if (empty($assignedUserIds)) {
            error_log("⚠️ No assigned users provided, skipping WhatsApp notification");
            return false;
        }
        
        // Get phone numbers for assigned users
        $phoneNumbers = getUserPhoneNumbers($conn, $assignedUserIds);
        
        if (empty($phoneNumbers)) {
            error_log("⚠️ No phone numbers found for assigned users");
            return false;
        }
        
        // Get assigner name if provided
        $assignedByName = null;
        if ($assignedById) {
            try {
                $stmt = $conn->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$assignedById]);
                $assigner = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($assigner) {
                    $assignedByName = $assigner['username'];
                }
            } catch (Exception $e) {
                error_log("⚠️ Could not get assigner name: " . $e->getMessage());
            }
        }
        
        // Generate bug link
        $baseUrl = getFrontendBaseUrl();
        $bugLink = $baseUrl . "/bugs/" . $bugId;
        
        // Format message
        $message = formatBugAssignmentForWhatsApp($bugTitle, $priority, $projectName, $assignedByName, $bugLink);
        
        error_log("📱 Formatted bug assignment WhatsApp message length: " . strlen($message) . " characters");
        
        // Send to each assigned user
        $results = [];
        foreach ($phoneNumbers as $userId => $phoneNumber) {
            $phoneNumber = trim($phoneNumber);
            if (empty($phoneNumber)) {
                continue;
            }
            
            error_log("📱 Sending bug assignment WhatsApp to user $userId: $phoneNumber");
            
            $result = sendWhatsAppMessage($phoneNumber, $message);
            $results[$userId] = $result;
            
            if ($result) {
                error_log("✅ Successfully sent bug assignment WhatsApp to user $userId");
            } else {
                error_log("❌ Failed to send bug assignment WhatsApp to user $userId");
            }
            
            // Add delay between messages
            if (count($phoneNumbers) > 1) {
                usleep(500000); // 0.5 second delay
            }
        }
        
        // Return true if at least one message was sent successfully
        $success = in_array(true, $results);
        return $success;
        
    } catch (Exception $e) {
        error_log("⚠️ Exception in sendBugAssignmentWhatsApp: " . $e->getMessage());
        error_log("⚠️ Exception trace: " . $e->getTraceAsString());
        return false;
    }
}

/**
 * Send shared task assignment WhatsApp notification to assigned users
 * 
 * @param PDO $conn Database connection
 * @param array $assignedUserIds Array of user IDs assigned to the task
 * @param int|string $taskId Task ID
 * @param string $taskTitle Task title
 * @param string $priority Task priority
 * @param string|null $dueDate Due date
 * @param string|null $assignedById User ID who assigned/created the task
 * @return bool Success status (true if at least one message sent)
 */
function sendTaskAssignmentWhatsApp($conn, $assignedUserIds, $taskId, $taskTitle, $priority = 'medium', $dueDate = null, $assignedById = null) {
    try {
        error_log("📱 sendTaskAssignmentWhatsApp called for task: $taskId");
        
        if (empty($assignedUserIds)) {
            error_log("⚠️ No assigned users provided, skipping WhatsApp notification");
            return false;
        }
        
        // Get phone numbers and roles for assigned users
        $usersWithPhones = getUserPhoneNumbersWithRoles($conn, $assignedUserIds);
        
        if (empty($usersWithPhones)) {
            error_log("⚠️ No phone numbers found for assigned users");
            return false;
        }
        
        // Get assigner name if provided
        $assignedByName = null;
        if ($assignedById) {
            try {
                $stmt = $conn->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$assignedById]);
                $assigner = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($assigner) {
                    $assignedByName = $assigner['username'];
                }
            } catch (Exception $e) {
                error_log("⚠️ Could not get assigner name: " . $e->getMessage());
            }
        }
        
        error_log("📱 Formatted task assignment WhatsApp message for " . count($usersWithPhones) . " users");
        
        // Send to each assigned user with personalized role-based URL
        $results = [];
        foreach ($usersWithPhones as $userId => $userData) {
            $phoneNumber = trim($userData['phone']);
            $userRole = $userData['role'] ?? 'user';
            
            if (empty($phoneNumber)) {
                continue;
            }
            
            // Generate role-based task URL for this user
            $taskLink = generateRoleBasedTaskUrl($userRole, $taskId);
            
            // Format personalized message with role-based URL
            $message = formatTaskAssignmentForWhatsApp($taskTitle, $priority, $dueDate, $assignedByName, $taskLink);
            
            error_log("📱 Sending task assignment WhatsApp to user $userId (role: $userRole): $phoneNumber");
            error_log("📱 Role-based URL: $taskLink");
            
            $result = sendWhatsAppMessage($phoneNumber, $message);
            $results[$userId] = $result;
            
            if ($result) {
                error_log("✅ Successfully sent task assignment WhatsApp to user $userId");
            } else {
                error_log("❌ Failed to send task assignment WhatsApp to user $userId");
            }
            
            // Add delay between messages
            if (count($usersWithPhones) > 1) {
                usleep(500000); // 0.5 second delay
            }
        }
        
        // Return true if at least one message was sent successfully
        $success = in_array(true, $results);
        return $success;
        
    } catch (Exception $e) {
        error_log("⚠️ Exception in sendTaskAssignmentWhatsApp: " . $e->getMessage());
        error_log("⚠️ Exception trace: " . $e->getTraceAsString());
        return false;
    }
}
?>

