<?php
/**
 * WhatsApp utility functions for BugRicer
 */

// Set timezone to IST
date_default_timezone_set('Asia/Kolkata');

// WhatsApp API configuration - BugRicer Notify API
// Prefer env vars if set (e.g. in .env), fallback to defaults
$envDir = dirname(__DIR__);
$envFile = $envDir . DIRECTORY_SEPARATOR . '.env';
if (file_exists($envFile) && is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (preg_match('/^WHATSAPP_API_KEY=(.+)$/', $line, $m)) {
            define('WHATSAPP_API_KEY', trim($m[1], " \t\n\r\0\x0B\"'"));
        }
        if (preg_match('/^WHATSAPP_ADMIN_NUMBERS=(.+)$/', $line, $m)) {
            define('WHATSAPP_ADMIN_NUMBERS', trim($m[1], " \t\n\r\0\x0B\"'"));
        }
    }
}
if (!defined('WHATSAPP_API_URL')) define('WHATSAPP_API_URL', 'https://notifyapi.bugricer.com/wapp/api/send');
if (!defined('WHATSAPP_API_KEY')) define('WHATSAPP_API_KEY', 'dfedcb5f0d514809f40f26b078eba6b8');
if (!defined('WHATSAPP_ADMIN_NUMBERS')) define('WHATSAPP_ADMIN_NUMBERS', '919497792540,918848676627');

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
    } elseif (strlen($digits) == 10 && substr($digits, 0, 1) !== '0') {
        // Likely Indian number (10 digits) - API needs international format without +
        $formats[] = '91' . $digits; // India country code (primary)
        $formats[] = $digits; // Try as-is
        error_log("📱 Possible Indian number (10 digits). Formats to try: " . implode(', ', $formats));
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
        // API requires POST (per BugRicer Notify API docs). Use international format WITHOUT + sign.
        $mobile = preg_replace('/\D/', '', $mobile); // Strip + and any non-digits
        if (empty($mobile)) {
            error_log("📱 Invalid/empty phone number");
            return ['success' => false, 'response' => 'Invalid phone', 'httpCode' => 0, 'error' => 'invalid_phone'];
        }
        $url = WHATSAPP_API_URL . '?apikey=' . urlencode(WHATSAPP_API_KEY) . 
               '&number=' . urlencode($mobile) . 
               '&msg=' . urlencode($message);
        
        error_log("📱 Trying format: $mobile");
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        
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
 * Format task text into WhatsApp bullet lines (truncated).
 */
function br_format_whatsapp_task_block($text, $limit = 400): string {
    $text = trim((string)$text);
    if ($text === '') {
        return '';
    }
    $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text) ?: []), function ($line) {
        return $line !== '';
    }));
    if (empty($lines)) {
        return '';
    }
    $out = '';
    foreach ($lines as $line) {
        $clean = ltrim($line, "•\t -");
        $out .= '• ' . $clean . "\n";
    }
    $out = rtrim($out);
    if (strlen($out) > $limit) {
        $out = substr($out, 0, $limit - 3) . '...';
    }
    return $out;
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
    $date = $submissionData['submission_date'] ?? date('Y-m-d');
    $dateLabel = date('D, M j, Y', strtotime($date));

    $checkInTime = $submissionData['check_in_time'] ?? null;
    $startTime = $submissionData['start_time'] ?? null;
    $checkOutTime = $submissionData['check_out_time'] ?? date('Y-m-d H:i:s');

    $checkInLabel = '—';
    if (!empty($checkInTime)) {
        $checkInLabel = br_format_whatsapp_time($checkInTime);
    } elseif (!empty($startTime)) {
        $ts = strtotime((string)$startTime);
        if ($ts === false && preg_match('/^\d{1,2}:\d{2}/', (string)$startTime)) {
            $ts = strtotime($date . ' ' . $startTime);
        }
        $checkInLabel = $ts ? date('h:i A', $ts) : '—';
    }

    $checkOutLabel = br_format_whatsapp_time($checkOutTime);
    $hoursRaw = (float)($submissionData['hours_today'] ?? 0);
    $otRaw = (float)($submissionData['overtime_hours'] ?? 0);
    $regularRaw = max(0, min($hoursRaw, 8));
    $hoursLabel = br_format_whatsapp_hours($hoursRaw);
    $otLabel = br_format_whatsapp_hours($otRaw);
    $regularLabel = br_format_whatsapp_hours($regularRaw);
    $breakMinutes = (int)($submissionData['total_break_minutes'] ?? 0);

    $plannedProjects = $submissionData['planned_projects'] ?? null;
    $plannedWork = trim((string)($submissionData['planned_work'] ?? ''));
    $plannedWorkStatus = $submissionData['planned_work_status'] ?? null;
    $plannedWorkNotes = trim((string)($submissionData['planned_work_notes'] ?? ''));

    $completedTasks = trim((string)($submissionData['completed_tasks'] ?? ''));
    $pendingTasks = trim((string)($submissionData['pending_tasks'] ?? ''));
    $ongoingTasks = trim((string)($submissionData['ongoing_tasks'] ?? ''));
    $upcomingTasks = trim((string)($submissionData['notes'] ?? ''));

    $countItems = static function ($txt) {
        if ($txt === '') {
            return 0;
        }
        $lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $txt) ?: []), function ($x) {
            return $x !== '';
        });
        return count($lines);
    };

    $completedCount = $countItems($completedTasks);
    $pendingCount = $countItems($pendingTasks);
    $ongoingCount = $countItems($ongoingTasks);
    $upcomingCount = $countItems($upcomingTasks);

    $isUpdate = !empty($submissionData['is_update']);
    $actionText = $isUpdate ? 'Updated' : 'Submitted';

    $message = "🧾 *WORK UPDATE*\n";
    $message .= "━━━━━━━━━━━━━━━━━━━━\n\n";
    $message .= "*" . $userName . "* · " . $actionText . "\n";
    if (!empty($userEmail)) {
        $message .= "📧 " . $userEmail . "\n";
    }

    $message .= "\n📅 *Work day* — " . $dateLabel . "\n";
    $message .= "🕘 Check-in: *" . $checkInLabel . "*\n";
    $message .= "🕕 Check-out: *" . $checkOutLabel . "*\n";
    $message .= "⏱ Hours worked: *" . $hoursLabel . "*\n";

    if ($otRaw > 0) {
        $message .= "📊 Regular: *" . $regularLabel . "*\n";
        $message .= "⚡ Overtime (OT): *" . $otLabel . "*\n";
    } else {
        $message .= "⚡ Overtime (OT): *0*\n";
    }

    if ($breakMinutes > 0) {
        $message .= "☕ Breaks: *" . $breakMinutes . " min*\n";
    }

    $projectNames = [];
    if (!empty($plannedProjects) && is_array($plannedProjects)) {
        if (isset($submissionData['_db_conn']) && $submissionData['_db_conn']) {
            try {
                $conn = $submissionData['_db_conn'];
                $ids = array_values(array_filter(array_map(function ($p) {
                    return is_array($p) ? ($p['id'] ?? null) : $p;
                }, $plannedProjects)));
                if (!empty($ids)) {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $projectStmt = $conn->prepare("SELECT id, name FROM projects WHERE id IN ($placeholders)");
                    $projectStmt->execute($ids);
                    $projectRows = $projectStmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($projectRows as $row) {
                        $projectNames[] = $row['name'];
                    }
                }
            } catch (Exception $e) {
                error_log('⚠️ Could not fetch project names: ' . $e->getMessage());
            }
        }
        if (empty($projectNames)) {
            $projectNames = br_normalize_planned_project_names($plannedProjects);
        }
    }

    if (!empty($projectNames) || $plannedWork !== '' || $plannedWorkNotes !== '' || (!empty($plannedWorkStatus) && $plannedWorkStatus !== 'not_started')) {
        $message .= "\n━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "📂 *Plan & Projects*\n";
        if (!empty($projectNames)) {
            foreach ($projectNames as $name) {
                $message .= '• ' . $name . "\n";
            }
        }
        if ($plannedWork !== '') {
            $preview = strlen($plannedWork) > 300 ? substr($plannedWork, 0, 297) . '...' : $plannedWork;
            $message .= "\n*Work focus*\n" . $preview . "\n";
        }
        if (!empty($plannedWorkStatus) && $plannedWorkStatus !== 'not_started') {
            $statusLabels = [
                'not_started' => 'Not Started',
                'in_progress' => 'In Progress',
                'completed' => 'Completed',
                'on_hold' => 'On Hold',
                'blocked' => 'Blocked',
                'cancelled' => 'Cancelled',
            ];
            $statusLabel = $statusLabels[$plannedWorkStatus] ?? ucfirst(str_replace('_', ' ', (string)$plannedWorkStatus));
            $message .= "\n📊 Plan status: *" . $statusLabel . "*\n";
        }
        if ($plannedWorkNotes !== '') {
            $notesPreview = strlen($plannedWorkNotes) > 300 ? substr($plannedWorkNotes, 0, 297) . '...' : $plannedWorkNotes;
            $message .= "\n*Notes*\n" . $notesPreview . "\n";
        }
    }

    if ($completedCount + $pendingCount + $ongoingCount + $upcomingCount > 0) {
        $message .= "\n━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "✅ *Tasks*\n";

        if ($completedCount > 0) {
            $message .= "\n*Completed (" . $completedCount . ")*\n";
            $message .= br_format_whatsapp_task_block($completedTasks) . "\n";
        }
        if ($pendingCount > 0) {
            $message .= "\n*Pending (" . $pendingCount . ")*\n";
            $message .= br_format_whatsapp_task_block($pendingTasks) . "\n";
        }
        if ($ongoingCount > 0) {
            $message .= "\n*Ongoing (" . $ongoingCount . ")*\n";
            $message .= br_format_whatsapp_task_block($ongoingTasks) . "\n";
        }
        if ($upcomingCount > 0) {
            $message .= "\n*Upcoming (" . $upcomingCount . ")*\n";
            $message .= br_format_whatsapp_task_block($upcomingTasks) . "\n";
        }
    }

    $totalDays = (int)($submissionData['total_working_days'] ?? 0);
    $totalHours = (float)($submissionData['total_hours_cumulative'] ?? 0);
    if ($totalDays > 0 || $totalHours > 0) {
        $message .= "\n━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "📈 *Period totals*\n";
        if ($totalDays > 0) {
            $message .= "Working days: *" . $totalDays . "*\n";
        }
        if ($totalHours > 0) {
            $message .= "Hours completed: *" . br_format_whatsapp_hours($totalHours) . "*\n";
        }
    }

    $message .= "\n━━━━━━━━━━━━━━━━━━━━\n";
    $message .= "_Submitted " . date('M j, Y · h:i A') . "_\n";
    $message .= "🐞 _BugRicer · Automated Attendance_";

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
                  WHERE pm.project_id = ? AND u.role = 'developer' AND u.account_active = 1";
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
        $query = "SELECT id FROM users WHERE role = 'admin' AND account_active = 1";
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
        $stmt = $conn->prepare("SELECT id, phone FROM users WHERE id IN ($placeholders) AND account_active = 1 AND phone IS NOT NULL AND phone != ''");
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
        $stmt = $conn->prepare("SELECT id, phone, role FROM users WHERE id IN ($placeholders) AND account_active = 1 AND phone IS NOT NULL AND phone != ''");
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
    
    return $baseUrl . "/" . $rolePath . "/my-tasks?tab=shared-tasks&task=" . $taskId;
}

/**
 * Generate role-based bug URL
 * 
 * @param string $role User role (admin, developer, tester, user)
 * @param int|string $bugId Bug ID
 * @return string Role-based bug URL
 */
function generateRoleBasedBugUrl($role, $bugId) {
    $baseUrl = getFrontendBaseUrl();
    
    // Normalize role to lowercase
    $role = strtolower($role ?? 'user');
    
    // Map roles to URL paths
    $rolePath = 'user'; // Default fallback
    if (in_array($role, ['admin', 'developer', 'tester', 'user'])) {
        $rolePath = $role;
    }
    
    return $baseUrl . "/" . $rolePath . "/bugs/" . $bugId;
}

/**
 * Format new bug reported message for WhatsApp (for admins / broadcast)
 */
function formatNewBugReportedForWhatsApp($bugTitle, $priority, $projectName = null, $reportedByName = null, $bugLink = null, $description = null, $expectedResult = null, $actualResult = null, $bugLevel = null, $alreadyRaised = null) {
    $message = "🐛 *New Bug Reported*\n";
    $message .= "━━━━━━━━━━━━━━━━━━━━\n\n";
    $message .= "📌 *Title:* " . $bugTitle . "\n";
    $message .= "🎯 *Priority:* " . ucfirst(strtolower($priority ?: 'Medium')) . "\n";
    if ($projectName) $message .= "📁 *Project:* " . $projectName . "\n";
    if ($reportedByName) $message .= "👤 *Reported by:* " . $reportedByName . "\n";
    require_once __DIR__ . '/bug_meta.php';
    $message = appendBugMetaToWhatsAppMessage($message, $bugLevel, $alreadyRaised);
    if ($description && trim($description)) {
        $descText = strlen($description) > 300 ? substr(trim($description), 0, 297) . '...' : trim($description);
        $message .= "\n📝 *Description:*\n" . $descText . "\n";
    }
    if ($expectedResult && trim($expectedResult)) {
        $expText = strlen($expectedResult) > 300 ? substr(trim($expectedResult), 0, 297) . '...' : trim($expectedResult);
        $message .= "\n✅ *Expected Result:*\n" . $expText . "\n";
    }
    if ($actualResult && trim($actualResult)) {
        $actText = strlen($actualResult) > 300 ? substr(trim($actualResult), 0, 297) . '...' : trim($actualResult);
        $message .= "\n❌ *Actual Result:*\n" . $actText . "\n";
    }
    $message .= "\n━━━━━━━━━━━━━━━━━━━━\n";
    if ($bugLink) $message .= "🔗 View Bug:\n" . $bugLink . "\n";
    $message .= "\n🐞 _BugRicer Automated Notification_";
    return $message;
}

/**
 * Format bug assignment message for WhatsApp
 * 
 * @param string $bugTitle Bug title
 * @param string $priority Bug priority
 * @param string|null $projectName Project name (optional)
 * @param string|null $assignedByName Name of person who assigned
 * @param string|null $bugLink Link to bug
 * @param string|null $description Bug description (optional)
 * @param string|null $expectedResult Expected result (optional)
 * @param string|null $actualResult Actual result (optional)
 * @return string Formatted WhatsApp message
 */
function formatBugAssignmentForWhatsApp($bugTitle, $priority, $projectName = null, $assignedByName = null, $bugLink = null, $description = null, $expectedResult = null, $actualResult = null, $bugLevel = null, $alreadyRaised = null) {
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

    require_once __DIR__ . '/bug_meta.php';
    $message = appendBugMetaToWhatsAppMessage($message, $bugLevel, $alreadyRaised);
    
    // Add description if provided
    if ($description && !empty(trim($description))) {
        $message .= "\n📝 *Description:*\n";
        // Truncate if too long (WhatsApp has limits)
        $descText = trim($description);
        if (strlen($descText) > 300) {
            $descText = substr($descText, 0, 297) . '...';
        }
        $message .= $descText . "\n";
    }
    
    // Add expected result if provided
    if ($expectedResult && !empty(trim($expectedResult))) {
        $message .= "\n✅ *Expected Result:*\n";
        $expText = trim($expectedResult);
        if (strlen($expText) > 300) {
            $expText = substr($expText, 0, 297) . '...';
        }
        $message .= $expText . "\n";
    }
    
    // Add actual result if provided
    if ($actualResult && !empty(trim($actualResult))) {
        $message .= "\n❌ *Actual Result:*\n";
        $actText = trim($actualResult);
        if (strlen($actText) > 300) {
            $actText = substr($actText, 0, 297) . '...';
        }
        $message .= $actText . "\n";
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
 * @param string|null $description Bug description (optional)
 * @param string|null $expectedResult Expected result (optional)
 * @param string|null $actualResult Actual result (optional)
 * @return bool Success status (true if at least one message sent)
 */
function sendBugAssignmentWhatsApp($conn, $assignedUserIds, $bugId, $bugTitle, $priority = 'medium', $projectName = null, $assignedById = null, $description = null, $expectedResult = null, $actualResult = null, $bugLevel = null, $alreadyRaised = null) {
    try {
        error_log("📱 sendBugAssignmentWhatsApp called for bug: $bugId");
        
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
        
        error_log("📱 Formatted bug assignment WhatsApp message for " . count($usersWithPhones) . " users");
        
        // Send to each assigned user with personalized role-based URL
        $results = [];
        foreach ($usersWithPhones as $userId => $userData) {
            $phoneNumber = trim($userData['phone']);
            $userRole = $userData['role'] ?? 'user';
            
            if (empty($phoneNumber)) {
                continue;
            }
            
            // Generate role-based bug URL for this user
            $bugLink = generateRoleBasedBugUrl($userRole, $bugId);
            
            // Format personalized message with role-based URL and bug details
            $message = formatBugAssignmentForWhatsApp($bugTitle, $priority, $projectName, $assignedByName, $bugLink, $description, $expectedResult, $actualResult, $bugLevel, $alreadyRaised);
            
            error_log("📱 Sending bug assignment WhatsApp to user $userId (role: $userRole): $phoneNumber");
            error_log("📱 Role-based URL: $bugLink");
            
            $result = sendWhatsAppMessage($phoneNumber, $message);
            $results[$userId] = $result;
            
            if ($result) {
                error_log("✅ Successfully sent bug assignment WhatsApp to user $userId");
            } else {
                error_log("❌ Failed to send bug assignment WhatsApp to user $userId");
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
        error_log("⚠️ Exception in sendBugAssignmentWhatsApp: " . $e->getMessage());
        error_log("⚠️ Exception trace: " . $e->getTraceAsString());
        return false;
    }
}

/**
 * Send new bug notification to configured admin numbers via BugRicer Notify API
 * Ensures admins always receive WhatsApp notifications even if not in project
 *
 * @param string $bugId Bug ID
 * @param string $bugTitle Bug title
 * @param string $priority Bug priority
 * @param string|null $projectName Project name
 * @param string|null $reportedByName Reporter name
 * @param string|null $description Bug description
 * @param string|null $expectedResult Expected result
 * @param string|null $actualResult Actual result
 * @return bool True if at least one message sent
 */
function sendNewBugToAdminNumbers($bugId, $bugTitle, $priority = 'medium', $projectName = null, $reportedByName = null, $description = null, $expectedResult = null, $actualResult = null, $bugLevel = null, $alreadyRaised = null) {
    $adminNumbers = explode(',', WHATSAPP_ADMIN_NUMBERS);
    $adminNumbers = array_map('trim', array_filter($adminNumbers));
    if (empty($adminNumbers)) {
        error_log("📱 sendNewBugToAdminNumbers: No admin numbers configured");
        return false;
    }
    $bugLink = getFrontendBaseUrl() . '/bugs/' . $bugId;
    $message = formatNewBugReportedForWhatsApp(
        $bugTitle,
        $priority,
        $projectName,
        $reportedByName ?: 'BugRicer',
        $bugLink,
        $description,
        $expectedResult,
        $actualResult,
        $bugLevel,
        $alreadyRaised
    );
    $sent = 0;
    foreach ($adminNumbers as $phone) {
        if (empty($phone)) continue;
        $result = sendWhatsAppMessage($phone, $message);
        if ($result) $sent++;
        if (count($adminNumbers) > 1) usleep(400000);
    }
    error_log("📱 sendNewBugToAdminNumbers: Sent to $sent/" . count($adminNumbers) . " admin numbers");
    return $sent > 0;
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

/**
 * Generate role-based update URL
 * 
 * @param string $role User role (admin, developer, tester, user)
 * @param int|string $updateId Update ID
 * @return string Role-based update URL
 */
function generateRoleBasedUpdateUrl($role, $updateId) {
    $baseUrl = getFrontendBaseUrl();
    
    // Normalize role to lowercase
    $role = strtolower($role ?? 'user');
    
    // Map roles to URL paths
    $rolePath = 'user'; // Default fallback
    if (in_array($role, ['admin', 'developer', 'tester', 'user'])) {
        $rolePath = $role;
    }
    
    return $baseUrl . "/" . $rolePath . "/updates/" . $updateId;
}

/**
 * Format update notification message for WhatsApp
 * 
 * @param string $updateTitle Update title
 * @param string $updateType Update type (feature/updation/maintenance)
 * @param string|null $projectName Project name (optional)
 * @param string|null $createdByName Name of person who created
 * @param string|null $updateLink Link to update
 * @return string Formatted WhatsApp message
 */
function formatUpdateForWhatsApp($updateTitle, $updateType, $projectName = null, $createdByName = null, $updateLink = null) {
    $message = "📢 *New Update Posted*\n";
    $message .= "━━━━━━━━━━━━━━━━━━━━\n\n";
    $message .= "📌 *Title:* " . $updateTitle . "\n";
    
    // Format update type with emoji
    $typeEmoji = "🏷️";
    $typeLabel = ucfirst(strtolower($updateType ?: 'Update'));
    $message .= $typeEmoji . " *Type:* " . $typeLabel . "\n";
    
    if ($projectName) {
        $message .= "📁 *Project:* " . $projectName . "\n";
    }
    
    if ($createdByName) {
        $message .= "👤 *Created by:* " . $createdByName . "\n";
    }
    
    $message .= "\n━━━━━━━━━━━━━━━━━━━━\n";
    
    if ($updateLink) {
        $message .= "🔗 View Update:\n" . $updateLink . "\n";
    }
    
    $message .= "\n🐞 _BugRicer Automated Notification_";
    
    return $message;
}

/**
 * Send update creation WhatsApp notification to developers and admins
 * 
 * @param PDO $conn Database connection
 * @param string $updateId Update ID
 * @param string $updateTitle Update title
 * @param string $updateType Update type
 * @param string $projectId Project ID
 * @param string|null $createdById User ID who created the update
 * @return bool Success status (true if at least one message sent)
 */
function sendUpdateCreationWhatsApp($conn, $updateId, $updateTitle, $updateType, $projectId, $createdById = null) {
    try {
        error_log("📱 sendUpdateCreationWhatsApp called for update: $updateId");
        
        // Get project developers and admins (same logic as NotificationManager)
        $developers = getProjectDevelopers($conn, $projectId);
        $admins = getAllAdmins($conn);
        
        // Combine and remove duplicates, exclude creator
        $userIds = array_unique(array_merge($developers, $admins));
        if ($createdById) {
            $userIds = array_filter($userIds, function($userId) use ($createdById) {
                return (string)$userId !== (string)$createdById;
            });
        }
        
        // Fallback to admins if no users
        if (empty($userIds)) {
            $allAdmins = getAllAdmins($conn);
            $userIds = array_filter($allAdmins, function($userId) use ($createdById) {
                return $createdById ? (string)$userId !== (string)$createdById : true;
            });
            if (empty($userIds) && $createdById) {
                $userIds = [$createdById]; // Notify creator as fallback
            }
        }
        
        if (empty($userIds)) {
            error_log("⚠️ No users to notify for update creation");
            return false;
        }
        
        // Get phone numbers and roles for users
        $usersWithPhones = getUserPhoneNumbersWithRoles($conn, array_values($userIds));
        
        if (empty($usersWithPhones)) {
            error_log("⚠️ No phone numbers found for users");
            return false;
        }
        
        // Get creator name if provided
        $createdByName = null;
        if ($createdById) {
            try {
                $stmt = $conn->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$createdById]);
                $creator = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($creator) {
                    $createdByName = $creator['username'];
                }
            } catch (Exception $e) {
                error_log("⚠️ Could not get creator name: " . $e->getMessage());
            }
        }
        
        // Get project name
        $projectName = getProjectName($conn, $projectId);
        
        error_log("📱 Sending WhatsApp notifications for update creation to " . count($usersWithPhones) . " users");
        
        // Send to each user with personalized role-based URL
        $results = [];
        foreach ($usersWithPhones as $userId => $userData) {
            $phoneNumber = trim($userData['phone']);
            $userRole = $userData['role'] ?? 'user';
            
            if (empty($phoneNumber)) {
                continue;
            }
            
            // Generate role-based update URL for this user
            $updateLink = generateRoleBasedUpdateUrl($userRole, $updateId);
            
            // Format personalized message with role-based URL
            $message = formatUpdateForWhatsApp($updateTitle, $updateType, $projectName, $createdByName, $updateLink);
            
            error_log("📱 Sending update creation WhatsApp to user $userId (role: $userRole): $phoneNumber");
            error_log("📱 Role-based URL: $updateLink");
            
            $result = sendWhatsAppMessage($phoneNumber, $message);
            $results[$userId] = $result;
            
            if ($result) {
                error_log("✅ Successfully sent update creation WhatsApp to user $userId");
            } else {
                error_log("❌ Failed to send update creation WhatsApp to user $userId");
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
        error_log("⚠️ Exception in sendUpdateCreationWhatsApp: " . $e->getMessage());
        error_log("⚠️ Exception trace: " . $e->getTraceAsString());
        return false;
    }
}

/**
 * Format welcome message for WhatsApp
 * 
 * @param string $username User's username
 * @param string|null $loginLink Login link (optional)
 * @param string|null $email User's email (optional)
 * @param string|null $password User's password (optional, for new accounts)
 * @param string|null $role User's role (optional)
 * @return string Formatted WhatsApp message
 */
function formatWelcomeForWhatsApp($username, $loginLink = null, $email = null, $password = null, $role = null) {
    $message = "🎉 *Welcome to BugRicer!*\n";
    $message .= "━━━━━━━━━━━━━━━━━━━━\n\n";
    $message .= "👋 Hello *$username*,\n\n";
    $message .= "Welcome to BugRicer! Your account has been successfully created and you're ready to start tracking bugs and managing your projects.\n\n";
    $message .= "You can now log in to your account and start exploring all the features we have to offer.\n\n";
    
    // Add login credentials if provided
    if ($email || $password || $role) {
        $message .= "━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "🔑 *Login Details:*\n\n";
        
        if ($email) {
            $message .= "📧 *Email:* $email\n";
        }
        
        if ($password) {
            $message .= "🔒 *Password:* $password\n";
        }
        
        if ($role) {
            $message .= "👤 *Role:* " . ucfirst($role) . "\n";
        }
        
        $message .= "\n";
    }
    
    $message .= "━━━━━━━━━━━━━━━━━━━━\n";
    $message .= "🎯 *What's Next?*\n\n";
    $message .= "✅ Create your first project\n";
    $message .= "🐛 Start reporting bugs\n";
    $message .= "👥 Collaborate with your team\n";
    $message .= "📊 Track progress and updates\n\n";
    
    if ($loginLink) {
        $message .= "━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "🔗 *Login Link:*\n";
        $message .= "$loginLink\n\n";
        $message .= "💡 *Note:* You'll be redirected to your role-specific dashboard after login.\n";
    }
    
    $message .= "\n━━━━━━━━━━━━━━━━━━━━\n";
    $message .= "💬 If you have any questions or need assistance, please don't hesitate to contact our support team.\n\n";
    $message .= "Best regards,\n";
    $message .= "The BugRicer Team\n\n";
    $message .= "━━━━━━━━━━━━━━━━━━━━\n";
    $message .= "🐞 _BugRicer Automated Notification_";
    
    return $message;
}

/**
 * Send welcome WhatsApp notification to new user
 * 
 * @param string $phoneNumber User's phone number
 * @param string $username User's username
 * @param string|null $loginLink Login link (optional)
 * @param string|null $email User's email (optional)
 * @param string|null $password User's password (optional, for new accounts)
 * @param string|null $role User's role (optional)
 * @return bool Success status
 */
function sendWelcomeWhatsApp($phoneNumber, $username, $loginLink = null, $email = null, $password = null, $role = null) {
    try {
        error_log("📱 sendWelcomeWhatsApp called for user: $username ($phoneNumber)");
        
        if (empty(trim($phoneNumber))) {
            error_log("⚠️ No phone number provided for welcome WhatsApp");
            return false;
        }
        
        // Format welcome message
        $message = formatWelcomeForWhatsApp($username, $loginLink, $email, $password, $role);
        
        error_log("📱 Formatted welcome WhatsApp message length: " . strlen($message) . " characters");
        
        // Send WhatsApp message
        $result = sendWhatsAppMessage($phoneNumber, $message);
        
        if ($result) {
            error_log("✅ Successfully sent welcome WhatsApp to $username");
        } else {
            error_log("❌ Failed to send welcome WhatsApp to $username");
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("⚠️ Exception in sendWelcomeWhatsApp: " . $e->getMessage());
        error_log("⚠️ Exception trace: " . $e->getTraceAsString());
        return false;
    }
}

/**
 * Generate role-based project URL
 * 
 * @param string $role User role (admin, developer, tester, user)
 * @param int|string $projectId Project ID
 * @return string Role-based project URL
 */
function generateRoleBasedProjectUrl($role, $projectId) {
    $baseUrl = getFrontendBaseUrl();
    
    // Normalize role to lowercase
    $role = strtolower($role ?? 'user');
    
    // Map roles to URL paths
    $rolePath = 'user'; // Default fallback
    if (in_array($role, ['admin', 'developer', 'tester', 'user'])) {
        $rolePath = $role;
    }
    
    return $baseUrl . "/" . $rolePath . "/projects/" . $projectId;
}

/**
 * Format project member added message for WhatsApp
 * 
 * @param string $projectName Project name
 * @param string $projectRole Member's role in the project
 * @param string|null $addedByName Name of person who added them
 * @param string|null $projectLink Link to project
 * @return string Formatted WhatsApp message
 */
function formatProjectMemberAddedForWhatsApp($projectName, $projectRole, $addedByName = null, $projectLink = null) {
    $message = "🎉 *Added to Project!*\n";
    $message .= "━━━━━━━━━━━━━━━━━━━━\n\n";
    $message .= "Hello! Great news! You've been added to a project on BugRicer.\n\n";
    $message .= "🏢 *Project:* " . $projectName . "\n";
    $message .= "👤 *Your Role:* " . ucfirst(strtolower($projectRole ?: 'Member')) . "\n";
    
    if ($addedByName) {
        $message .= "✍️ *Added by:* " . $addedByName . "\n";
    }
    
    $message .= "\n━━━━━━━━━━━━━━━━━━━━\n";
    $message .= "🎯 *What You Can Do:*\n\n";
    $message .= "✅ View and manage project bugs\n";
    $message .= "📋 Access shared tasks and updates\n";
    $message .= "👥 Collaborate with team members\n";
    $message .= "📊 Track project progress\n";
    
    $message .= "\n━━━━━━━━━━━━━━━━━━━━\n";
    
    if ($projectLink) {
        $message .= "🔗 *View Project:*\n";
        $message .= "$projectLink\n\n";
    }
    
    $message .= "━━━━━━━━━━━━━━━━━━━━\n";
    $message .= "💬 If you have any questions, please contact our support team.\n\n";
    $message .= "Best regards,\n";
    $message .= "The BugRicer Team\n\n";
    $message .= "🐞 _BugRicer Automated Notification_";
    
    return $message;
}

/**
 * Send project member added WhatsApp notification to the newly added member
 * 
 * @param PDO $conn Database connection
 * @param string $userId User ID of the newly added member
 * @param string $projectId Project ID
 * @param string $projectRole Member's role in the project
 * @param string|null $addedById User ID who added the member
 * @return bool Success status
 */
function sendProjectMemberAddedWhatsApp($conn, $userId, $projectId, $projectRole, $addedById = null) {
    try {
        error_log("📱 sendProjectMemberAddedWhatsApp called for user: $userId, project: $projectId");
        
        // Get user details (phone and role) — skip deactivated accounts
        $userStmt = $conn->prepare("SELECT username, phone, role FROM users WHERE id = ? AND account_active = 1 LIMIT 1");
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || empty($user['phone'])) {
            error_log("⚠️ User $userId is inactive or has no phone number for WhatsApp notification");
            return false;
        }
        
        $phoneNumber = trim($user['phone']);
        $userRole = $user['role'] ?? 'user';
        $username = $user['username'] ?? 'User';
        
        // Get project name
        $projectName = getProjectName($conn, $projectId);
        if (!$projectName) {
            error_log("⚠️ Could not get project name for project: $projectId");
            $projectName = 'Project';
        }
        
        // Get admin name who added the member
        $addedByName = null;
        if ($addedById) {
            try {
                $adminStmt = $conn->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
                $adminStmt->execute([$addedById]);
                $admin = $adminStmt->fetch(PDO::FETCH_ASSOC);
                if ($admin) {
                    $addedByName = $admin['username'];
                }
            } catch (Exception $e) {
                error_log("⚠️ Could not get admin name: " . $e->getMessage());
            }
        }
        
        // Generate role-based project URL
        $projectLink = generateRoleBasedProjectUrl($userRole, $projectId);
        
        // Format message
        $message = formatProjectMemberAddedForWhatsApp($projectName, $projectRole, $addedByName, $projectLink);
        
        error_log("📱 Sending project member added WhatsApp to user $username (role: $userRole): $phoneNumber");
        error_log("📱 Role-based project URL: $projectLink");
        
        // Send WhatsApp message
        $result = sendWhatsAppMessage($phoneNumber, $message);
        
        if ($result) {
            error_log("✅ Successfully sent project member added WhatsApp to user $username");
        } else {
            error_log("❌ Failed to send project member added WhatsApp to user $username");
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("⚠️ Exception in sendProjectMemberAddedWhatsApp: " . $e->getMessage());
        error_log("⚠️ Exception trace: " . $e->getTraceAsString());
        return false;
    }
}

/**
 * Format meeting invitation message for WhatsApp
 * 
 * @param string $meetingTitle Meeting title
 * @param string $meetingCode Meeting code
 * @param string|null $meetingUri Meeting URI/link
 * @param string|null $creatorName Name of meeting creator
 * @param string|null $startTime Meeting start time (formatted)
 * @return string Formatted WhatsApp message
 */
function formatMeetingInvitationForWhatsApp($meetingTitle, $meetingCode, $meetingUri = null, $creatorName = null, $startTime = null) {
    $message = "📹 *Meeting Invitation*\n";
    $message .= "━━━━━━━━━━━━━━━━━━━━\n\n";
    $message .= "You've been invited to join a meeting on BugMeet!\n\n";
    $message .= "📌 *Meeting:* " . $meetingTitle . "\n";
    $message .= "🔢 *Code:* " . $meetingCode . "\n";
    
    if ($creatorName) {
        $message .= "👤 *Created by:* " . $creatorName . "\n";
    }
    
    if ($startTime) {
        $message .= "⏰ *Time:* " . $startTime . "\n";
    }
    
    $message .= "\n━━━━━━━━━━━━━━━━━━━━\n";
    
    if ($meetingUri) {
        $message .= "🔗 *Join Meeting:*\n";
        $message .= "$meetingUri\n\n";
    } else {
        $message .= "🔗 *Join Meeting:*\n";
        $message .= "https://meet.google.com/" . strtolower($meetingCode) . "\n\n";
    }
    
    $message .= "━━━━━━━━━━━━━━━━━━━━\n";
    $message .= "💡 You can join using the link above or enter the meeting code manually.\n\n";
    $message .= "Best regards,\n";
    $message .= "The BugRicer Team\n\n";
    $message .= "🐞 _BugRicer Automated Notification_";
    
    return $message;
}

/**
 * Send meeting invitation WhatsApp notifications to participants
 * 
 * @param PDO $conn Database connection
 * @param array $participantEmails Array of participant email addresses
 * @param string $meetingTitle Meeting title
 * @param string $meetingCode Meeting code
 * @param string|null $meetingUri Meeting URI/link
 * @param string|null $creatorId User ID of meeting creator
 * @param string|null $startTime Meeting start time (formatted)
 * @return array Array of results with email => success status
 */
function sendMeetingInvitationWhatsApp($conn, $participantEmails, $meetingTitle, $meetingCode, $meetingUri = null, $creatorId = null, $startTime = null) {
    try {
        error_log("📱 sendMeetingInvitationWhatsApp called for meeting: $meetingTitle");
        
        if (empty($participantEmails)) {
            error_log("⚠️ No participant emails provided, skipping WhatsApp notification");
            return [];
        }
        
        // Remove duplicates
        $participantEmails = array_values(array_unique(array_filter($participantEmails)));
        
        // Get creator name if provided
        $creatorName = null;
        if ($creatorId) {
            try {
                $creatorStmt = $conn->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
                $creatorStmt->execute([$creatorId]);
                $creator = $creatorStmt->fetch(PDO::FETCH_ASSOC);
                if ($creator) {
                    $creatorName = $creator['username'];
                }
            } catch (Exception $e) {
                error_log("⚠️ Could not get creator name: " . $e->getMessage());
            }
        }
        
        // Get phone numbers for participant emails (active accounts only)
        $placeholders = str_repeat('?,', count($participantEmails) - 1) . '?';
        $phoneStmt = $conn->prepare("SELECT email, phone FROM users WHERE email IN ($placeholders) AND account_active = 1 AND phone IS NOT NULL AND phone != ''");
        $phoneStmt->execute($participantEmails);
        $usersWithPhones = $phoneStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($usersWithPhones)) {
            error_log("⚠️ No phone numbers found for participant emails");
            return [];
        }
        
        error_log("📱 Sending meeting invitation WhatsApp to " . count($usersWithPhones) . " participants");
        
        // Send to each participant
        $results = [];
        foreach ($usersWithPhones as $user) {
            $email = $user['email'];
            $phoneNumber = trim($user['phone']);
            
            if (empty($phoneNumber)) {
                continue;
            }
            
            // Format message
            $message = formatMeetingInvitationForWhatsApp($meetingTitle, $meetingCode, $meetingUri, $creatorName, $startTime);
            
            error_log("📱 Sending meeting invitation WhatsApp to: $email ($phoneNumber)");
            
            $result = sendWhatsAppMessage($phoneNumber, $message);
            $results[$email] = $result;
            
            if ($result) {
                error_log("✅ Successfully sent meeting invitation WhatsApp to: $email");
            } else {
                error_log("❌ Failed to send meeting invitation WhatsApp to: $email");
            }
            
            // Add delay between messages
            if (count($usersWithPhones) > 1) {
                usleep(500000); // 0.5 second delay
            }
        }
        
        return $results;
        
    } catch (Exception $e) {
        error_log("⚠️ Exception in sendMeetingInvitationWhatsApp: " . $e->getMessage());
        error_log("⚠️ Exception trace: " . $e->getTraceAsString());
        return [];
    }
}

/**
 * Format a clock time for WhatsApp (h:i A), or em dash when missing.
 */
function br_format_whatsapp_time($value): string {
    if ($value === null) {
        return '—';
    }
    $raw = trim((string)$value);
    if ($raw === '' || $raw === '0000-00-00 00:00:00' || $raw === '0000-00-00') {
        return '—';
    }
    // JS ISO → parseable
    $raw = str_replace('T', ' ', $raw);
    $raw = preg_replace('/\.\d+Z?$/', '', $raw) ?? $raw;
    $raw = preg_replace('/Z$/', '', $raw) ?? $raw;
    $ts = strtotime($raw);
    if ($ts === false || $ts <= 0) {
        return '—';
    }
    return date('h:i A', $ts);
}

/**
 * Format decimal hours for WhatsApp (e.g. 8, 8.5).
 */
function br_format_whatsapp_hours($hours): string {
    $h = round((float)$hours, 2);
    if (abs($h - (int)$h) < 0.001) {
        return (string)(int)$h;
    }
    return rtrim(rtrim(number_format($h, 2, '.', ''), '0'), '.');
}

/**
 * Normalize planned project names for WhatsApp bullets.
 *
 * @param mixed $plannedProjects
 * @return string[]
 */
function br_normalize_planned_project_names($plannedProjects): array {
    if (empty($plannedProjects)) {
        return [];
    }
    if (!is_array($plannedProjects)) {
        $name = trim((string)$plannedProjects);
        return $name !== '' ? [$name] : [];
    }

    $names = [];
    foreach ($plannedProjects as $project) {
        if (is_array($project)) {
            $name = trim((string)($project['name'] ?? $project['id'] ?? ''));
        } else {
            $name = trim((string)$project);
        }
        if ($name !== '') {
            $names[] = $name;
        }
    }
    return $names;
}

/**
 * Format check-in notification message for WhatsApp
 *
 * @param string $username User's username
 * @param string $checkInTime Check-in time (datetime format)
 * @param string $date Check-in date
 * @param array|null $plannedProjects Array of planned project names or IDs
 * @param string|null $plannedWork Planned work description
 * @param array|null $yesterdaySummary Optional yesterday attendance:
 *        date, check_in_time, check_out_time, hours_today, overtime_hours, has_record
 * @return string Formatted WhatsApp message
 */
function formatCheckInNotificationForWhatsApp(
    $username,
    $checkInTime,
    $date,
    $plannedProjects = null,
    $plannedWork = null,
    $yesterdaySummary = null
) {
    $todayLabel = date('D, M j, Y', strtotime($date));
    $checkInLabel = br_format_whatsapp_time($checkInTime);
    $projectNames = br_normalize_planned_project_names($plannedProjects);

    $message = "✅ *CHECK-IN*\n";
    $message .= "━━━━━━━━━━━━━━━━━━━━\n\n";
    $message .= "*" . $username . "* has checked in for the work day.\n\n";

    $message .= "📅 *Today* — " . $todayLabel . "\n";
    $message .= "🕘 Check-in: *" . $checkInLabel . "*\n";

    if (!empty($projectNames) || (!empty($plannedWork) && trim((string)$plannedWork) !== '')) {
        $message .= "\n━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "📂 *Today's Plan*\n";

        if (!empty($projectNames)) {
            $message .= "*Projects*\n";
            foreach ($projectNames as $name) {
                $message .= "• " . $name . "\n";
            }
        }

        if (!empty($plannedWork) && trim((string)$plannedWork) !== '') {
            $workText = trim((string)$plannedWork);
            if (strlen($workText) > 500) {
                $workText = substr($workText, 0, 497) . '...';
            }
            if (!empty($projectNames)) {
                $message .= "\n";
            }
            $message .= "*Work focus*\n";
            $message .= $workText . "\n";
        }
    }

    $message .= "\n━━━━━━━━━━━━━━━━━━━━\n";
    $message .= "📊 *Yesterday's Summary*";

    if (is_array($yesterdaySummary) && !empty($yesterdaySummary['has_record'])) {
        $yDate = $yesterdaySummary['date'] ?? null;
        $yDateLabel = $yDate ? date('D, M j, Y', strtotime((string)$yDate)) : 'Previous day';
        $yIn = br_format_whatsapp_time($yesterdaySummary['check_in_time'] ?? null);
        $yOut = br_format_whatsapp_time($yesterdaySummary['check_out_time'] ?? null);
        $yHours = br_format_whatsapp_hours($yesterdaySummary['hours_today'] ?? 0);
        $yOt = br_format_whatsapp_hours($yesterdaySummary['overtime_hours'] ?? 0);

        $message .= " — " . $yDateLabel . "\n";
        $message .= "🕘 In: *" . $yIn . "*\n";
        $message .= "🕕 Out: *" . $yOut . "*\n";
        $message .= "⏱ Hours worked: *" . $yHours . "*\n";
        $message .= "⚡ Overtime (OT): *" . $yOt . "*\n";
    } else {
        $message .= "\n";
        $message .= "_No attendance record for yesterday._\n";
    }

    $message .= "\n━━━━━━━━━━━━━━━━━━━━\n";
    $message .= "🐞 _BugRicer · Automated Attendance_";

    return $message;
}

/**
 * Send check-in notification WhatsApp to admin
 *
 * @param string $adminPhone Admin phone number
 * @param string $username User's username
 * @param string $checkInTime Check-in time (datetime format)
 * @param string $date Check-in date
 * @param array|null $plannedProjects Array of planned project names or IDs
 * @param string|null $plannedWork Planned work description
 * @param array|null $yesterdaySummary Optional yesterday attendance summary
 * @return bool Success status
 */
function sendCheckInNotificationWhatsApp(
    $adminPhone,
    $username,
    $checkInTime,
    $date,
    $plannedProjects = null,
    $plannedWork = null,
    $yesterdaySummary = null
) {
    try {
        error_log("📱 sendCheckInNotificationWhatsApp called for user: $username");

        if (empty(trim($adminPhone))) {
            error_log("⚠️ Admin phone number is empty, skipping WhatsApp notification");
            return false;
        }

        $message = formatCheckInNotificationForWhatsApp(
            $username,
            $checkInTime,
            $date,
            $plannedProjects,
            $plannedWork,
            $yesterdaySummary
        );

        error_log("📱 Sending check-in notification WhatsApp to admin: $adminPhone");

        $result = sendWhatsAppMessage($adminPhone, $message);

        if ($result) {
            error_log("✅ Successfully sent check-in notification WhatsApp to admin");
        } else {
            error_log("❌ Failed to send check-in notification WhatsApp to admin");
        }

        return $result;
    } catch (Exception $e) {
        error_log("⚠️ Exception in sendCheckInNotificationWhatsApp: " . $e->getMessage());
        error_log("⚠️ Exception trace: " . $e->getTraceAsString());
        return false;
    }
}

/**
 * Send Common CODO rule status WhatsApp notification to configured admin numbers.
 */
function sendCodoRuleStatusWhatsAppToAdmins(
    $username,
    $ruleTitle,
    $ruleKey,
    $phase,
    $status,
    $codoUrl = null
) {
    try {
        $statusLabels = [
            'acknowledged' => 'Acknowledged',
            'doubt' => 'Doubt',
            'not_required' => 'Not Required',
        ];
        $statusKey = strtolower(trim((string) $status));
        $statusLabel = $statusLabels[$statusKey] ?? ucwords(str_replace('_', ' ', $statusKey));
        $phaseLabel = ucfirst(strtolower(trim((string) $phase))) ?: 'Team';
        $titleDisplay = trim((string) $ruleTitle) !== '' ? trim((string) $ruleTitle) : (string) $ruleKey;
        $url = $codoUrl ?: 'https://bugs.bugricer.com/admin/common-codo';

        $message = "📋 *CODO Rule Response*\n\n";
        $message .= "*" . trim((string) $username) . "* marked a rule as *{$statusLabel}*\n\n";
        $message .= "• Rule: {$titleDisplay}\n";
        if (trim((string) $ruleKey) !== '') {
            $message .= "• Key: {$ruleKey}\n";
        }
        $message .= "• Phase: {$phaseLabel}\n";
        $message .= "• Status: {$statusLabel}\n";
        $message .= "\n🔗 Open: {$url}\n";
        $message .= "\n━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "_BugRicer · Common CODO_";

        $phoneNumbers = explode(',', WHATSAPP_ADMIN_NUMBERS);
        $results = [];
        foreach ($phoneNumbers as $phoneNumber) {
            $phoneNumber = trim($phoneNumber);
            if ($phoneNumber === '') {
                continue;
            }
            $ok = sendWhatsAppMessage($phoneNumber, $message);
            $results[$phoneNumber] = $ok;
            if (count($phoneNumbers) > 1) {
                usleep(400000);
            }
        }
        return in_array(true, $results, true);
    } catch (Exception $e) {
        error_log('⚠️ Exception in sendCodoRuleStatusWhatsAppToAdmins: ' . $e->getMessage());
        return false;
    }
}
?>

