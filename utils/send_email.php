<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Make sure PHPMailer is properly included
require_once __DIR__ . '/../vendor/autoload.php'; // Adjust path as needed

function sendBugNotification($to, $subject, $body, $attachments = []) {
    // Log function call
    error_log("Sending bug notification to: " . (is_array($to) ? implode(',', $to) : $to));
    
    try {
        $mail = new PHPMailer(true);
        
        // HOSTINGER CONFIGURATION - WORKING
        $mail->isSMTP();
        $mail->Host = 'smtp.hostinger.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'bug@codoacademy.com';
        $mail->Password = 'Codo@8848';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = 465;
        
        // Recipients
        $mail->setFrom('bug@codoacademy.com', 'Bug Ricer');
        
        // Add recipients
        if (is_array($to)) {
            foreach ($to as $recipient) {
                $mail->addAddress($recipient);
            }
        } else {
            $mail->addAddress($to);
        }
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        
        // Add attachments
        if (!empty($attachments)) {
            foreach ($attachments as $attachment) {
                // Ensure the path is correct and file exists
                $fullPath = realpath($attachment); // Get the absolute path
                if ($fullPath && file_exists($fullPath)) {
                    $mail->addAttachment($fullPath, basename($fullPath)); // Attach using full path and original filename
                } else {
                    error_log("Attachment file not found or path invalid: " . $attachment);
                }
            }
        }
        
        // Debug settings
        $mail->SMTPDebug = 2; // Set to 2 for verbose debugging
        $mail->Debugoutput = function($str, $level) {
            error_log("PHPMailer debug: $str");
        };
        
        // Send email
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mail error: " . $e->getMessage());
        return false;
    }
}

$to = 'moajmalnk@gmail.com';
// sendBugNotification($to, 'New Bug Assigned', '<b>A new bug has been assigned to you.</b>');