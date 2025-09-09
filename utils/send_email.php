<?php


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
        $mail->Username = 'bugs@moajmalnk.in';
        $mail->Password = 'Codo@8848';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = 465;
        
        // Recipients
        $mail->setFrom('bugs@moajmalnk.in', 'Bug Ricer');
        
        // Add recipients
        $mail->addAddress('noreply@codoacademy.com', 'Bug Ricer'); // Main recipient (not shown to others)
        if (is_array($to)) {
            foreach ($to as $recipient) {
                $mail->addBCC($recipient);
            }
        } else {
            $mail->addBCC($to);
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
        // $mail->SMTPDebug = 2; // Set to 2 for verbose debugging
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

function sendWelcomeEmail($to, $subject, $body) {
    // Log function call
    error_log("Sending welcome email to: $to");
    
    try {
        $mail = new PHPMailer(true);
        
        // HOSTINGER CONFIGURATION - WORKING
        $mail->isSMTP();
        $mail->Host = 'smtp.hostinger.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'bugs@moajmalnk.in';
        $mail->Password = 'Codo@8848';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = 465;
        
        // Recipients
        $mail->setFrom('bugs@moajmalnk.in', 'Bug Ricer');
        $mail->addAddress($to); // Send directly to the new user
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        
        $mail->Debugoutput = function($str, $level) {
            error_log("PHPMailer debug: $str");
        };
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Welcome mail error: " . $e->getMessage());
        return false;
    }
}

function sendOtpEmail($to, $otp) {
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.hostinger.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'bugs@moajmalnk.in';
        $mail->Password = 'Codo@8848';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = 465;
        $mail->setFrom('bugs@moajmalnk.in', 'Bug Ricer');
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = 'Your BugRicer OTP';
        $mail->Body = "<b>Your OTP is: $otp</b><br>This OTP is valid for 5 minutes.<br><br>🐞 _Sent from BugRicer_";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("OTP mail error: " . $mail->ErrorInfo);
        return false;
    }
}