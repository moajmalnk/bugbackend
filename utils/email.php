<?php
/**
 * Email utility functions for BugRicer
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Make sure PHPMailer is properly included
require_once __DIR__ . '/../vendor/autoload.php';

function sendPasswordResetEmail($email, $username, $reset_link) {
    $subject = "Password Reset Request - BugRicer";
    
    $html_body = "
    <div style=\"font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f7f6; padding: 20px;\">
      <div style=\"max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">
        
        <!-- Header -->
        <div style=\"background-color: #dc2626; color: #ffffff; padding: 20px; text-align: center;\">
           <h1 style=\"margin: 0; font-size: 24px; display: flex; align-items: center; justify-content: center;\">
            <img src=\"https://fonts.gstatic.com/s/e/notoemoji/16.0/1f41e/32.png\" alt=\"BugRicer Logo\" style=\"width: 30px; height: 30px; margin-right: 10px; vertical-align: middle;\">
            BugRicer Alert
          </h1>
          <p style=\"margin: 5px 0 0 0; font-size: 16px;\">Password Reset Request</p>
        </div>
        
        <!-- Body -->
        <div style=\"padding: 20px; border-bottom: 1px solid #e2e8f0;\">
          <h3 style=\"margin-top: 0; color: #1e293b; font-size: 18px;\">Hello $username,</h3>
          <p style=\"white-space: pre-line; margin-bottom: 15px; font-size: 14px;\">We received a request to reset your password for your BugRicer account. If you made this request, click the button below to reset your password:</p>
          
          <div style=\"margin: 20px 0; text-align: center;\">
            <a href=\"$reset_link\" style=\"background-color: #2563eb; color: #ffffff; padding: 12px 25px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: 500; font-size: 16px;\">Reset My Password</a>
          </div>
          
          <div style=\"margin-bottom: 15px; padding: 12px; background-color: #fef3c7; border-left: 4px solid #f59e0b; border-radius: 4px;\">
            <p style=\"margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #92400e;\"><strong>⚠️ Security Notice:</strong></p>
            <p style=\"margin: 0; font-size: 14px; color: #92400e;\">This link will expire in 1 hour for your security. If you didn't request this password reset, please ignore this email.</p>
          </div>
          
          <p style=\"font-size: 14px; margin-bottom: 10px;\">If the button doesn't work, you can copy and paste this link into your browser:</p>
          <div style=\"background-color: #f3f4f6; padding: 12px; border-radius: 4px; text-align: center; margin: 15px 0; font-family: 'Courier New', monospace; font-size: 12px; word-break: break-all; color: #374151;\">$reset_link</div>
          
          <p style=\"font-size: 14px; margin-bottom: 0;\">If you have any questions or need assistance, please contact our support team.</p>
          <p style=\"font-size: 14px; margin-bottom: 0;\">Best regards,<br>The BugRicer Team</p>
        </div>
        
        <!-- Footer -->
        <div style=\"background-color: #f8fafc; color: #64748b; padding: 20px; text-align: center; font-size: 12px;\">
          <p style=\"margin: 0;\">This is an automated notification from BugRicer. Please do not reply to this email.</p>
          <p style=\"margin: 5px 0 0 0;\">&copy; " . date('Y') . " BugRicer. All rights reserved.</p>
        </div>
        
      </div>
    </div>
    ";
    
    $text_body = "
Password Reset Request - BugRicer

Hello $username,

We received a request to reset your password for your BugRicer account.

To reset your password, please visit the following link:
$reset_link

This link will expire in 1 hour for your security.

If you didn't request this password reset, please ignore this email.

Best regards,
The BugRicer Team

© 2025 BugRicer. All rights reserved.
    ";
    
    return sendEmail($email, $subject, $html_body, $text_body);
}

function sendEmail($to, $subject, $html_body, $text_body = '') {
    // Use PHPMailer with SMTP for all environments
    try {
        error_log("📧 sendEmail called - To: $to, Subject: $subject");
        
        $mail = new PHPMailer(true);
        
        // GMAIL SMTP CONFIGURATION
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'codo.bugricer@gmail.com';
        $mail->Password = 'ieka afeu uhds qkam';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        // Recipients
        $mail->setFrom('codo.bugricer@gmail.com', 'BugRicer');
        $mail->addAddress($to);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html_body;
        
        // Add text body if provided
        if (!empty($text_body)) {
            $mail->AltBody = $text_body;
        }
        
        // Debug settings
        $mail->Debugoutput = function($str, $level) {
            error_log("PHPMailer debug: $str");
        };
        
        // Send email
        $mail->send();
        error_log("✅ Email sent successfully to: $to (Subject: $subject)");
        return true;
        
    } catch (Exception $e) {
        error_log("❌ Email error for $to: " . $e->getMessage());
        error_log("PHPMailer ErrorInfo: " . (isset($mail) ? $mail->ErrorInfo : 'N/A'));
        return false;
    }
}

function sendWelcomeEmail($email, $username) {
    $subject = "Welcome to BugRicer!";
    
    $html_body = "
    <div style=\"font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f7f6; padding: 20px;\">
      <div style=\"max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">
        
        <!-- Header -->
        <div style=\"background-color: #65a30d; color: #ffffff; padding: 20px; text-align: center;\">
           <h1 style=\"margin: 0; font-size: 24px; display: flex; align-items: center; justify-content: center;\">
            <img src=\"https://fonts.gstatic.com/s/e/notoemoji/16.0/1f41e/32.png\" alt=\"BugRicer Logo\" style=\"width: 30px; height: 30px; margin-right: 10px; vertical-align: middle;\">
            BugRicer Welcome
          </h1>
          <p style=\"margin: 5px 0 0 0; font-size: 16px;\">Account Created Successfully</p>
        </div>
        
        <!-- Body -->
        <div style=\"padding: 20px; border-bottom: 1px solid #e2e8f0;\">
          <h3 style=\"margin-top: 0; color: #1e293b; font-size: 18px;\">Hello $username,</h3>
          <p style=\"white-space: pre-line; margin-bottom: 15px; font-size: 14px;\">Welcome to BugRicer! Your account has been successfully created and you're ready to start tracking bugs and managing your projects.</p>
          
          <p style=\"white-space: pre-line; margin-bottom: 15px; font-size: 14px;\">You can now log in to your account and start exploring all the features we have to offer.</p>
          
          <div style=\"margin-bottom: 15px; padding: 12px; background-color: #f0f9ff; border-left: 4px solid #0ea5e9; border-radius: 4px;\">
            <p style=\"margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #0c4a6e;\"><strong>🎉 What's Next?</strong></p>
            <p style=\"margin: 0; font-size: 14px; color: #0c4a6e;\">• Create your first project<br/>• Start reporting bugs<br/>• Collaborate with your team<br/>• Track progress and updates</p>
          </div>
          
          <p style=\"font-size: 14px; margin-bottom: 0;\">If you have any questions or need assistance, please don't hesitate to contact our support team.</p>
          <p style=\"font-size: 14px; margin-bottom: 0;\">Best regards,<br>The BugRicer Team</p>
        </div>
        
        <!-- Footer -->
        <div style=\"background-color: #f8fafc; color: #64748b; padding: 20px; text-align: center; font-size: 12px;\">
          <p style=\"margin: 0;\">This is an automated notification from BugRicer. Please do not reply to this email.</p>
          <p style=\"margin: 5px 0 0 0;\">&copy; " . date('Y') . " BugRicer. All rights reserved.</p>
        </div>
        
      </div>
    </div>
    ";
    
    $text_body = "
Welcome to BugRicer!

Hello $username,

Welcome to BugRicer! Your account has been successfully created and you're ready to start tracking bugs and managing your projects.

You can now log in to your account and start exploring all the features we have to offer.

If you have any questions or need assistance, please don't hesitate to contact our support team.

Best regards,
The BugRicer Team

© 2025 BugRicer. All rights reserved.
    ";
    
    return sendEmail($email, $subject, $html_body, $text_body);
}

function sendMagicLinkEmail($email, $username, $magic_link) {
    $subject = "Your Magic Link - BugRicer";
    
    $html_body = "
    <div style=\"font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f7f6; padding: 20px;\">
      <div style=\"max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">
        
        <!-- Header -->
        <div style=\"background-color: #7c3aed; color: #ffffff; padding: 20px; text-align: center;\">
           <h1 style=\"margin: 0; font-size: 24px; display: flex; align-items: center; justify-content: center;\">
            <img src=\"https://fonts.gstatic.com/s/e/notoemoji/16.0/1f41e/32.png\" alt=\"BugRicer Logo\" style=\"width: 30px; height: 30px; margin-right: 10px; vertical-align: middle;\">
            BugRicer Magic Link
          </h1>
          <p style=\"margin: 5px 0 0 0; font-size: 16px;\">Passwordless Sign-In</p>
        </div>
        
        <!-- Body -->
        <div style=\"padding: 20px; border-bottom: 1px solid #e2e8f0;\">
          <h3 style=\"margin-top: 0; color: #1e293b; font-size: 18px;\">Hello $username,</h3>
          <p style=\"white-space: pre-line; margin-bottom: 15px; font-size: 14px;\">You requested a magic link to sign in to your BugRicer account. Click the button below to sign in instantly without a password:</p>
          
          <div style=\"margin: 20px 0; text-align: center;\">
            <a href=\"$magic_link\" style=\"background-color: #7c3aed; color: #ffffff; padding: 12px 25px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: 500; font-size: 16px;\">✨ Sign In with Magic Link</a>
          </div>
          
          <div style=\"margin-bottom: 15px; padding: 12px; background-color: #fef3c7; border-left: 4px solid #f59e0b; border-radius: 4px;\">
            <p style=\"margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #92400e;\"><strong>⏰ Security Notice:</strong></p>
            <p style=\"margin: 0; font-size: 14px; color: #92400e;\">This magic link will expire in 15 minutes for your security. If you didn't request this link, please ignore this email.</p>
          </div>
          
          <p style=\"font-size: 14px; margin-bottom: 10px;\">If the button doesn't work, you can copy and paste this link into your browser:</p>
          <div style=\"background-color: #f3f4f6; padding: 12px; border-radius: 4px; text-align: center; margin: 15px 0; font-family: 'Courier New', monospace; font-size: 12px; word-break: break-all; color: #374151;\">$magic_link</div>
          
          <p style=\"font-size: 14px; margin-bottom: 0;\">If you have any questions or need assistance, please contact our support team.</p>
          <p style=\"font-size: 14px; margin-bottom: 0;\">Best regards,<br>The BugRicer Team</p>
        </div>
        
        <!-- Footer -->
        <div style=\"background-color: #f8fafc; color: #64748b; padding: 20px; text-align: center; font-size: 12px;\">
          <p style=\"margin: 0;\">This is an automated notification from BugRicer. Please do not reply to this email.</p>
          <p style=\"margin: 5px 0 0 0;\">&copy; " . date('Y') . " BugRicer. All rights reserved.</p>
        </div>
        
      </div>
    </div>
    ";
    
    $text_body = "
Magic Link Sign-In - BugRicer

Hello $username,

You requested a magic link to sign in to your BugRicer account.

To sign in instantly, please visit the following link:
$magic_link

This link will expire in 15 minutes for your security.

If you didn't request this magic link, please ignore this email.

Best regards,
The BugRicer Team

© 2025 BugRicer. All rights reserved.
    ";
    
    return sendEmail($email, $subject, $html_body, $text_body);
}

function sendDailyWorkUpdateEmailToAdmins($adminEmails, $userName, $userEmail, $submissionData) {
    $subject = "Daily Work Update Submitted - BugRicer";
    
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
    
    $html_body = "
    <div style=\"font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f7f6; padding: 20px;\">
      <div style=\"max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">
        
        <!-- Header -->
        <div style=\"background-color: #2563eb; color: #ffffff; padding: 20px; text-align: center;\">
           <h1 style=\"margin: 0; font-size: 24px; display: flex; align-items: center; justify-content: center;\">
            <img src=\"https://fonts.gstatic.com/s/e/notoemoji/16.0/1f41e/32.png\" alt=\"BugRicer Logo\" style=\"width: 30px; height: 30px; margin-right: 10px; vertical-align: middle;\">
            BugRicer Daily Update
          </h1>
          <p style=\"margin: 5px 0 0 0; font-size: 16px;\">Daily Work Update $actionText</p>
        </div>
        
        <!-- Body -->
        <div style=\"padding: 20px; border-bottom: 1px solid #e2e8f0;\">
          <h3 style=\"margin-top: 0; color: #1e293b; font-size: 18px;\">New Daily Work Update</h3>
          <p style=\"white-space: pre-line; margin-bottom: 15px; font-size: 14px;\"><strong>User:</strong> $userName ($userEmail)</p>
          
          <div style=\"margin-bottom: 15px; padding: 12px; background-color: #f0f9ff; border-left: 4px solid #0ea5e9; border-radius: 4px;\">
            <p style=\"margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #0c4a6e;\"><strong>📅 Date:</strong></p>
            <p style=\"margin: 0; font-size: 14px; color: #0c4a6e;\">$dateFormatted</p>
          </div>
          
          <div style=\"margin-bottom: 15px; padding: 12px; background-color: #f0f9ff; border-left: 4px solid #0ea5e9; border-radius: 4px;\">
            <p style=\"margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #0c4a6e;\"><strong>🕘 Start Time:</strong></p>
            <p style=\"margin: 0; font-size: 14px; color: #0c4a6e;\">$startTimeFormatted</p>
          </div>
          
          <div style=\"margin-bottom: 15px; padding: 12px; background-color: #f0f9ff; border-left: 4px solid #0ea5e9; border-radius: 4px;\">
            <p style=\"margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #0c4a6e;\"><strong>⏱ Working Hours:</strong></p>
            <p style=\"margin: 0; font-size: 14px; color: #0c4a6e;\">$hours Hours";
    
    if ($overtimeHours > 0) {
        $html_body .= "<br/><strong>Regular Hours:</strong> $regularHours Hours<br/><strong>Overtime Hours:</strong> $overtimeHours Hours";
    }
    
    $html_body .= "</p>
          </div>";
    
    if ($completedCount > 0) {
        $html_body .= "
          <div style=\"margin-bottom: 15px; padding: 12px; background-color: #f0fdf4; border-left: 4px solid #22c55e; border-radius: 4px;\">
            <p style=\"margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #166534;\"><strong>✅ Completed Tasks ($completedCount):</strong></p>
            <p style=\"margin: 0; font-size: 14px; color: #166534; white-space: pre-line;\">" . htmlspecialchars($completedTasks) . "</p>
          </div>";
    }
    
    if ($pendingCount > 0) {
        $html_body .= "
          <div style=\"margin-bottom: 15px; padding: 12px; background-color: #fefce8; border-left: 4px solid #eab308; border-radius: 4px;\">
            <p style=\"margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #854d0e;\"><strong>⌛ Pending Tasks ($pendingCount):</strong></p>
            <p style=\"margin: 0; font-size: 14px; color: #854d0e; white-space: pre-line;\">" . htmlspecialchars($pendingTasks) . "</p>
          </div>";
    }
    
    if ($ongoingCount > 0) {
        $html_body .= "
          <div style=\"margin-bottom: 15px; padding: 12px; background-color: #eff6ff; border-left: 4px solid #3b82f6; border-radius: 4px;\">
            <p style=\"margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #1e40af;\"><strong>🔄 Ongoing Tasks ($ongoingCount):</strong></p>
            <p style=\"margin: 0; font-size: 14px; color: #1e40af; white-space: pre-line;\">" . htmlspecialchars($ongoingTasks) . "</p>
          </div>";
    }
    
    if ($upcomingCount > 0) {
        $html_body .= "
          <div style=\"margin-bottom: 15px; padding: 12px; background-color: #faf5ff; border-left: 4px solid #a855f7; border-radius: 4px;\">
            <p style=\"margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #6b21a8;\"><strong>🔥 Upcoming Tasks ($upcomingCount):</strong></p>
            <p style=\"margin: 0; font-size: 14px; color: #6b21a8; white-space: pre-line;\">" . htmlspecialchars($upcomingTasks) . "</p>
          </div>";
    }
    
    $html_body .= "
          <p style=\"font-size: 14px; margin-bottom: 0; margin-top: 18px;\"><strong>Submitted On:</strong> <span style=\"font-weight: normal;\">" . date('Y-m-d H:i:s') . "</span></p>
        </div>
        
        <!-- Footer -->
        <div style=\"background-color: #f8fafc; color: #64748b; padding: 20px; text-align: center; font-size: 12px;\">
          <p style=\"margin: 0;\">This is an automated notification from BugRicer. Please do not reply to this email.</p>
          <p style=\"margin: 5px 0 0 0;\">&copy; " . date('Y') . " BugRicer. All rights reserved.</p>
        </div>
        
      </div>
    </div>
    ";
    
    $text_body = "
Daily Work Update $actionText - BugRicer

User: $userName ($userEmail)
Date: $dateFormatted
Start Time: $startTimeFormatted
Working Hours: $hours Hours" . ($overtimeHours > 0 ? "
Regular Hours: $regularHours Hours
Overtime Hours: $overtimeHours Hours" : "") . "

" . ($completedCount > 0 ? "✅ Completed Tasks ($completedCount):\n" . $completedTasks . "\n\n" : "") . 
($pendingCount > 0 ? "⌛ Pending Tasks ($pendingCount):\n" . $pendingTasks . "\n\n" : "") .
($ongoingCount > 0 ? "🔄 Ongoing Tasks ($ongoingCount):\n" . $ongoingTasks . "\n\n" : "") .
($upcomingCount > 0 ? "🔥 Upcoming Tasks ($upcomingCount):\n" . $upcomingTasks . "\n\n" : "") . 
"Submitted On: " . date('Y-m-d H:i:s') . "

© " . date('Y') . " BugRicer. All rights reserved.
    ";
    
    // Send to all admin emails
    error_log("📧 sendDailyWorkUpdateEmailToAdmins called with " . count($adminEmails) . " admin emails");
    
    $results = [];
    foreach ($adminEmails as $adminEmail) {
        if (empty(trim($adminEmail))) {
            error_log("⚠️ Skipping empty admin email");
            continue;
        }
        error_log("📧 Attempting to send daily work update email to: $adminEmail");
        $result = sendEmail($adminEmail, $subject, $html_body, $text_body);
        $results[$adminEmail] = $result;
        if ($result) {
            error_log("✅ Successfully sent daily work update email to: $adminEmail");
        } else {
            error_log("❌ Failed to send daily work update email to: $adminEmail");
        }
    }
    
    error_log("📧 Email sending complete. Results: " . json_encode($results));
    return $results;
}
?>
