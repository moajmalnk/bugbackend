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
?>

