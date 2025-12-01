<?php
/**
 * WhatsApp utility functions for BugRicer
 */

// WhatsApp API configuration
define('WHATSAPP_API_URL', 'http://148.251.129.118/wapp/api/send');
define('WHATSAPP_API_KEY', 'ff7a6e6fcca94f7f9a4cfa444b494188');
define('WHATSAPP_ADMIN_NUMBERS', '8848676627,97450372450');

/**
 * Send WhatsApp message using the API
 * 
 * @param string $mobile Comma-separated phone numbers
 * @param string $message The message to send
 * @return bool Success status
 */
function sendWhatsAppMessage($mobile, $message) {
    try {
        error_log("📱 sendWhatsAppMessage called - Mobile: $mobile");
        
        $url = WHATSAPP_API_URL . '?apikey=' . urlencode(WHATSAPP_API_KEY) . 
               '&mobile=' . urlencode($mobile) . 
               '&msg=' . urlencode($message);
        
        error_log("📱 WhatsApp API URL: $url");
        
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
            error_log("❌ WhatsApp API cURL error: $curlError");
            return false;
        }
        
        error_log("📱 WhatsApp API Response (HTTP $httpCode): " . substr($response, 0, 200));
        
        // Consider success if HTTP code is 200
        if ($httpCode == 200) {
            error_log("✅ WhatsApp message sent successfully");
            return true;
        } else {
            error_log("❌ WhatsApp API returned HTTP code: $httpCode");
            return false;
        }
        
    } catch (Exception $e) {
        error_log("❌ WhatsApp error: " . $e->getMessage());
        return false;
    }
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
 * @return bool Success status
 */
function sendDailyWorkUpdateWhatsAppToAdmins($userName, $userEmail, $submissionData) {
    try {
        error_log("📱 sendDailyWorkUpdateWhatsAppToAdmins called");
        error_log("📱 User: $userName ($userEmail)");
        
        // Format the message
        $message = formatWorkUpdateForWhatsApp($userName, $userEmail, $submissionData);
        
        error_log("📱 Formatted WhatsApp message length: " . strlen($message) . " characters");
        error_log("📱 WhatsApp message preview: " . substr($message, 0, 200) . "...");
        
        // Send to admin numbers (comma-separated)
        $mobile = WHATSAPP_ADMIN_NUMBERS;
        
        error_log("📱 Attempting to send WhatsApp message to: $mobile");
        
        $result = sendWhatsAppMessage($mobile, $message);
        
        if ($result) {
            error_log("✅ Successfully sent daily work update WhatsApp to admins");
        } else {
            error_log("❌ Failed to send daily work update WhatsApp to admins");
        }
        
        return $result;
        
    } catch (Exception $e) {
        // Don't fail the submission if WhatsApp fails
        error_log("⚠️ Exception in sendDailyWorkUpdateWhatsAppToAdmins: " . $e->getMessage());
        error_log("⚠️ Exception trace: " . $e->getTraceAsString());
        return false;
    }
}
?>

