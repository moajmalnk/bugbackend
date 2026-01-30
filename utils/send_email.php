<?php


use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Make sure PHPMailer is properly included (loaded on first use)
if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

function sendBugNotification($to, $subject, $body, $attachments = []) {
    // Log function call
    error_log("Sending bug notification to: " . (is_array($to) ? implode(',', $to) : $to));
    
    try {
        $mail = new PHPMailer(true);
        
        // GMAIL SMTP CONFIGURATION
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'codo.bugricer@gmail.com';
        $mail->Password = 'gwgh vtlm fzkx rdkj';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        // Recipients
        $mail->setFrom('codo.bugricer@gmail.com', 'BugRicer');
        
        // Add recipients
        $mail->addAddress('noreply@codoacademy.com', 'BugRicer'); // Main recipient (not shown to others)
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

function formatBugCreatedEmailBody($bugId, $bugTitle, $projectName, $reportedByName, $priority, $description, $expectedResult, $actualResult, $bugUrl) {
    $priorityBadge = strtoupper(($priority !== null && trim($priority) !== '') ? $priority : 'medium');
    $desc = htmlspecialchars(($description !== null && $description !== '') ? (strlen($description) > 500 ? substr($description, 0, 500) . '...' : $description) : '');
    $exp = htmlspecialchars(($expectedResult !== null && $expectedResult !== '') ? (strlen($expectedResult) > 300 ? substr($expectedResult, 0, 300) . '...' : $expectedResult) : '');
    $act = htmlspecialchars(($actualResult !== null && $actualResult !== '') ? (strlen($actualResult) > 300 ? substr($actualResult, 0, 300) . '...' : $actualResult) : '');
    $project = htmlspecialchars(($projectName !== null && trim($projectName) !== '') ? $projectName : 'N/A');
    $reporter = htmlspecialchars(($reportedByName !== null && trim($reportedByName) !== '') ? $reportedByName : 'BugRicer');
    return '<div style="font-family: Segoe UI, Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f7f6; padding: 20px;">'
      . '<div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">'
      . '<div style="background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%); color: #ffffff; padding: 20px; text-align: center;">'
      . '<h1 style="margin: 0; font-size: 22px;">New Bug Reported</h1>'
      . '<p style="margin: 8px 0 0 0; font-size: 14px; opacity: 0.95;">BugRicer Notification</p></div>'
      . '<div style="padding: 24px;">'
      . '<h2 style="margin: 0 0 16px 0; color: #1e293b; font-size: 18px;">' . htmlspecialchars($bugTitle) . '</h2>'
      . '<table style="width: 100%; border-collapse: collapse; margin-bottom: 16px;">'
      . '<tr><td style="padding: 8px 0; color: #64748b; width: 140px;">Project</td><td style="padding: 8px 0;">' . $project . '</td></tr>'
      . '<tr><td style="padding: 8px 0; color: #64748b;">Priority</td><td><span style="background: #fef3c7; color: #92400e; padding: 4px 10px; border-radius: 4px; font-weight: 600;">' . $priorityBadge . '</span></td></tr>'
      . '<tr><td style="padding: 8px 0; color: #64748b;">Reported by</td><td>' . $reporter . '</td></tr></table>'
      . ($desc ? '<p style="margin: 0 0 12px 0;"><strong>Description:</strong></p><p style="margin: 0 0 16px 0; background: #f8fafc; padding: 12px; border-radius: 4px; font-size: 14px;">' . $desc . '</p>' : '')
      . ($exp ? '<p style="margin: 0 0 8px 0;"><strong>Expected Result:</strong></p><p style="margin: 0 0 16px 0; background: #ecfdf5; padding: 12px; border-radius: 4px; font-size: 14px;">' . $exp . '</p>' : '')
      . ($act ? '<p style="margin: 0 0 8px 0;"><strong>Actual Result:</strong></p><p style="margin: 0 0 16px 0; background: #fef2f2; padding: 12px; border-radius: 4px; font-size: 14px;">' . $act . '</p>' : '')
      . '<div style="margin-top: 24px; text-align: center;">'
      . '<a href="' . htmlspecialchars($bugUrl) . '" style="background: #dc2626; color: #fff !important; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: 600; display: inline-block;">View Bug #' . htmlspecialchars($bugId) . '</a>'
      . '</div></div>'
      . '<div style="background: #f8fafc; color: #64748b; padding: 16px; text-align: center; font-size: 12px;">'
      . '&copy; ' . date('Y') . ' BugRicer. Automated notification.</div></div></div>';
}


/**
 * Send new bug notification email to developers and admins
 * @param array $emails Array of email addresses
 * @return bool Success if at least one email sent
 */
function sendBugCreatedEmail($emails, $bugId, $bugTitle, $projectName, $reportedByName, $priority, $description, $expectedResult, $actualResult, $bugUrl) {
    if (empty($emails)) {
        error_log("📧 sendBugCreatedEmail: No email addresses provided");
        return false;
    }
    $emails = array_unique(array_filter(array_map('trim', $emails)));
    if (empty($emails)) return false;
    $subject = "🐛 New Bug: " . substr($bugTitle, 0, 60) . (strlen($bugTitle) > 60 ? '...' : '');
    $body = formatBugCreatedEmailBody($bugId, $bugTitle, $projectName, $reportedByName, $priority, $description, $expectedResult, $actualResult, $bugUrl);
    error_log("📧 Sending bug created email to " . count($emails) . " recipients");
    return sendBugNotification($emails, $subject, $body, []);
}

function sendWelcomeEmail($to, $subject, $body) {
    // Log function call
    error_log("Sending welcome email to: $to");
    
    try {
        $mail = new PHPMailer(true);
        
        // GMAIL SMTP CONFIGURATION
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'codo.bugricer@gmail.com';
        $mail->Password = 'gwgh vtlm fzkx rdkj';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        // Recipients
        $mail->setFrom('codo.bugricer@gmail.com', 'BugRicer');
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
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'codo.bugricer@gmail.com';
        $mail->Password = 'gwgh vtlm fzkx rdkj';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->setFrom('codo.bugricer@gmail.com', 'BugRicer');
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