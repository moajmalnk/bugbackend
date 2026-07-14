<?php
/**
 * Email utility functions for BugRicer
 */

require_once __DIR__ . '/work_period.php';

// Set timezone to IST
date_default_timezone_set('Asia/Kolkata');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Make sure PHPMailer is properly included
require_once __DIR__ . '/../config/composer_autoload.php';
require_once __DIR__ . '/../config/environment.php';

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
    try {
        error_log("📧 sendEmail called - To: $to, Subject: $subject");

        $smtpUser = Environment::get('SMTP_USER');
        $smtpPass = Environment::get('SMTP_PASS');
        if ($smtpUser === null || $smtpUser === '' || $smtpPass === null || $smtpPass === '') {
            error_log('❌ SMTP not configured: set SMTP_USER and SMTP_PASS in backend/.env (see .env.example)');
            return false;
        }

        $smtpHost = Environment::get('SMTP_HOST', 'smtp.gmail.com');
        $smtpPort = (int) Environment::get('SMTP_PORT', '587');
        if ($smtpPort < 1 || $smtpPort > 65535) {
            $smtpPort = 587;
        }
        $fromEmail = Environment::get('SMTP_FROM_EMAIL', $smtpUser);
        $fromName = Environment::get('SMTP_FROM_NAME', 'BugRicer');

        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUser;
        $mail->Password = $smtpPass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $smtpPort;

        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = 'base64';

        $mail->setFrom($fromEmail, $fromName);
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

/**
 * Send HTML email with a file attachment using configured SMTP (.env).
 */
function sendEmailWithAttachment(
    $to,
    $subject,
    $html_body,
    $attachmentPath,
    $attachmentName = null,
    $text_body = ''
) {
    try {
        if (!is_file($attachmentPath)) {
            error_log("❌ sendEmailWithAttachment: file not found: $attachmentPath");
            return false;
        }

        $smtpUser = Environment::get('SMTP_USER');
        $smtpPass = Environment::get('SMTP_PASS');
        if ($smtpUser === null || $smtpUser === '' || $smtpPass === null || $smtpPass === '') {
            error_log('❌ SMTP not configured: set SMTP_USER and SMTP_PASS in backend/.env');
            return false;
        }

        $smtpHost = Environment::get('SMTP_HOST', 'smtp.gmail.com');
        $smtpPort = (int) Environment::get('SMTP_PORT', '587');
        if ($smtpPort < 1 || $smtpPort > 65535) {
            $smtpPort = 587;
        }
        $fromEmail = Environment::get('SMTP_FROM_EMAIL', $smtpUser);
        $fromName = Environment::get('SMTP_FROM_NAME', 'BugRicer');

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUser;
        $mail->Password = $smtpPass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $smtpPort;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = 'base64';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html_body;
        if ($text_body !== '') {
            $mail->AltBody = $text_body;
        }
        $mail->addAttachment(
            $attachmentPath,
            $attachmentName ?: basename($attachmentPath)
        );
        $mail->Debugoutput = function ($str) {
            error_log("PHPMailer debug: $str");
        };
        $mail->send();
        error_log("✅ Email with attachment sent to: $to (Subject: $subject)");
        return true;
    } catch (Exception $e) {
        error_log("❌ sendEmailWithAttachment error for $to: " . $e->getMessage());
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
    // Determine if this is an update or new submission
    $isUpdate = isset($submissionData['is_update']) && $submissionData['is_update'];
    $actionText = $isUpdate ? 'Updated' : 'Submitted';
    $subject = "Daily Work Update $actionText - BugRicer";
    
    error_log("📧 sendDailyWorkUpdateEmailToAdmins - Action: $actionText, User: $userName ($userEmail)");
    
    // Format date
    $date = $submissionData['submission_date'] ?? date('Y-m-d');
    $dateFormatted = date('D, M j, Y', strtotime($date));
    
    // Format check-in / check-out
    $checkInTime = $submissionData['check_in_time'] ?? null;
    $startTime = $submissionData['start_time'] ?? null;
    $checkOutTime = $submissionData['check_out_time'] ?? date('Y-m-d H:i:s');
    $timeFormatted = '—';
    if ($checkInTime) {
        $timeFormatted = date('h:i A', strtotime($checkInTime));
    } elseif ($startTime) {
        $ts = strtotime((string)$startTime);
        if ($ts === false && preg_match('/^\d{1,2}:\d{2}/', (string)$startTime)) {
            $ts = strtotime($date . ' ' . $startTime);
        }
        $timeFormatted = $ts ? date('h:i A', $ts) : '—';
    }
    $checkOutFormatted = date('h:i A', strtotime($checkOutTime));
    
    // Hours worked
    $hours = number_format((float)($submissionData['hours_today'] ?? 0), 2);
    $overtimeHours = number_format((float)($submissionData['overtime_hours'] ?? 0), 2);
    $regularHours = min((float)($submissionData['hours_today'] ?? 0), 8);
    $breakMinutes = (int)($submissionData['total_break_minutes'] ?? 0);
    
    // Total working days and cumulative hours
    $totalWorkingDays = $submissionData['total_working_days'] ?? 0;
    $totalHoursCompleted = number_format((float)($submissionData['total_hours_cumulative'] ?? 0), 2);
    $periodLabel = br_calendar_month_period_label($date);
    
    // Planned projects and work
    $plannedProjects = $submissionData['planned_projects'] ?? null;
    $plannedWork = trim($submissionData['planned_work'] ?? '');
    
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
          <h3 style=\"margin-top: 0; color: #1e293b; font-size: 20px; font-weight: 700; margin-bottom: 20px;\">🧾 CODO Daily Work Update — $userName</h3>
          
          <div style=\"margin-bottom: 15px; padding: 12px; background-color: #f0f9ff; border-left: 4px solid #0ea5e9; border-radius: 4px;\">
            <p style=\"margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #0c4a6e;\"><strong>Attendance — $dateFormatted</strong></p>
            <p style=\"margin: 0; font-size: 14px; color: #0c4a6e;\"><strong>Check-in:</strong> $timeFormatted</p>
            <p style=\"margin: 0; font-size: 14px; color: #0c4a6e;\"><strong>Check-out:</strong> $checkOutFormatted</p>
            <p style=\"margin: 0; font-size: 14px; color: #0c4a6e;\"><strong>Hours worked:</strong> $hours</p>";
    
    if ($overtimeHours > 0) {
        $html_body .= "<p style=\"margin: 0; font-size: 14px; color: #0c4a6e;\"><strong>Regular:</strong> $regularHours · <strong>OT:</strong> $overtimeHours</p>";
    } else {
        $html_body .= "<p style=\"margin: 0; font-size: 14px; color: #0c4a6e;\"><strong>Overtime (OT):</strong> 0</p>";
    }
    if ($breakMinutes > 0) {
        $html_body .= "<p style=\"margin: 0; font-size: 14px; color: #0c4a6e;\"><strong>Breaks:</strong> {$breakMinutes} min</p>";
    }
    
    $html_body .= "
          </div>
          
          <div style=\"margin-bottom: 15px; padding: 12px; background-color: #f0f9ff; border-left: 4px solid #0ea5e9; border-radius: 4px;\">
            <p style=\"margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #0c4a6e;\"><strong>📊 Total Working Days ($periodLabel):</strong></p>
            <p style=\"margin: 0; font-size: 14px; color: #0c4a6e;\">$totalWorkingDays Days</p>
          </div>
          
          <div style=\"margin-bottom: 15px; padding: 12px; background-color: #f0f9ff; border-left: 4px solid #0ea5e9; border-radius: 4px;\">
            <p style=\"margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #0c4a6e;\"><strong>🧮 Total Hours Completed:</strong></p>
            <p style=\"margin: 0; font-size: 14px; color: #0c4a6e;\">$totalHoursCompleted hours</p>
          </div>";
    
    // Add Planning Details section if available
    $hasPlanningDetails = false;
    $planningDetailsHtml = "";
    
    // Get project names for HTML
    $projectNames = [];
    if (!empty($plannedProjects) && is_array($plannedProjects)) {
        if (isset($submissionData['_db_conn']) && $submissionData['_db_conn']) {
            try {
                $conn = $submissionData['_db_conn'];
                $placeholders = str_repeat('?,', count($plannedProjects) - 1) . '?';
                $projectStmt = $conn->prepare("SELECT id, name FROM projects WHERE id IN ($placeholders)");
                $projectStmt->execute($plannedProjects);
                $projectRows = $projectStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($projectRows as $row) {
                    $projectNames[] = $row['name'];
                }
            } catch (Exception $e) {
                error_log("⚠️ Could not fetch project names for email HTML: " . $e->getMessage());
                $projectNames = $plannedProjects; // Fallback to IDs
            }
        } else {
            $projectNames = $plannedProjects; // Fallback to IDs
        }
    }
    
    if (!empty($projectNames) || !empty($plannedWork)) {
        $hasPlanningDetails = true;
        $planningDetailsHtml .= "
          <div style=\"margin-bottom: 20px; padding: 16px; background-color: #f8fafc; border: 2px solid #e2e8f0; border-radius: 8px;\">
            <h4 style=\"margin: 0 0 12px 0; font-size: 16px; font-weight: 700; color: #1e293b;\">📋 Planning Details:</h4>";
        
        if (!empty($projectNames)) {
            $planningDetailsHtml .= "
            <div style=\"margin-bottom: 12px;\">
              <p style=\"margin: 0 0 4px 0; font-size: 14px; font-weight: 600; color: #475569;\">📁 Projects:</p>
              <p style=\"margin: 0; font-size: 14px; color: #64748b; line-height: 1.6;\">" . htmlspecialchars(implode(', ', $projectNames)) . "</p>
            </div>";
        }
        
        if (!empty($plannedWork)) {
            $planningDetailsHtml .= "
            <div style=\"margin-bottom: 12px;\">
              <p style=\"margin: 0 0 4px 0; font-size: 14px; font-weight: 600; color: #475569;\">📝 Planned Work:</p>
              <p style=\"margin: 0; font-size: 14px; color: #64748b; white-space: pre-line; line-height: 1.6;\">" . htmlspecialchars($plannedWork) . "</p>
            </div>";
        }
        
        $planningDetailsHtml .= "
          </div>";
    }
    
    if ($hasPlanningDetails) {
        $html_body .= $planningDetailsHtml;
    }
    
    if ($completedCount > 0) {
        $html_body .= "
          <div style=\"margin-bottom: 15px; padding: 12px; background-color: #f0fdf4; border-left: 4px solid #22c55e; border-radius: 4px;\">
            <p style=\"margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #166534;\"><strong>✅ Completed ($completedCount):</strong></p>
            <p style=\"margin: 0; font-size: 14px; color: #166534; white-space: pre-line;\">" . htmlspecialchars($completedTasks) . "</p>
          </div>";
    }
    
    if ($pendingCount > 0) {
        $html_body .= "
          <div style=\"margin-bottom: 15px; padding: 12px; background-color: #fefce8; border-left: 4px solid #eab308; border-radius: 4px;\">
            <p style=\"margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #854d0e;\"><strong>⌛ Pending ($pendingCount):</strong></p>
            <p style=\"margin: 0; font-size: 14px; color: #854d0e; white-space: pre-line;\">" . htmlspecialchars($pendingTasks) . "</p>
          </div>";
    }
    
    if ($ongoingCount > 0) {
        $html_body .= "
          <div style=\"margin-bottom: 15px; padding: 12px; background-color: #eff6ff; border-left: 4px solid #3b82f6; border-radius: 4px;\">
            <p style=\"margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #1e40af;\"><strong>🔄 Ongoing ($ongoingCount):</strong></p>
            <p style=\"margin: 0; font-size: 14px; color: #1e40af; white-space: pre-line;\">" . htmlspecialchars($ongoingTasks) . "</p>
          </div>";
    }
    
    if ($upcomingCount > 0) {
        $html_body .= "
          <div style=\"margin-bottom: 15px; padding: 12px; background-color: #faf5ff; border-left: 4px solid #a855f7; border-radius: 4px;\">
            <p style=\"margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #6b21a8;\"><strong>🔥 Upcoming ($upcomingCount):</strong></p>
            <p style=\"margin: 0; font-size: 14px; color: #6b21a8; white-space: pre-line;\">" . htmlspecialchars($upcomingTasks) . "</p>
          </div>";
    }
    
    // Add Planned Work Status if available
    $plannedWorkStatus = $submissionData['planned_work_status'] ?? null;
    if (!empty($plannedWorkStatus) && $plannedWorkStatus !== 'not_started') {
        $statusLabels = [
            'not_started' => 'Not Started',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            'on_hold' => 'On Hold',
            'blocked' => 'Blocked',
            'cancelled' => 'Cancelled'
        ];
        $statusLabel = $statusLabels[$plannedWorkStatus] ?? ucfirst(str_replace('_', ' ', $plannedWorkStatus));
        $html_body .= "
          <div style=\"margin-bottom: 15px; padding: 12px; background-color: #eef2ff; border-left: 4px solid #6366f1; border-radius: 4px;\">
            <p style=\"margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #4338ca;\"><strong>📊 Planned Work Status:</strong></p>
            <p style=\"margin: 0; font-size: 14px; color: #4338ca;\">$statusLabel</p>
          </div>";
    }
    
    // Add Work Notes if available
    $plannedWorkNotes = trim($submissionData['planned_work_notes'] ?? '');
    if (!empty($plannedWorkNotes)) {
        $workNotesCount = $countItems($plannedWorkNotes);
        $html_body .= "
          <div style=\"margin-bottom: 15px; padding: 12px; background-color: #f0fdfa; border-left: 4px solid #14b8a6; border-radius: 4px;\">
            <p style=\"margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #0f766e;\"><strong>📝 Work Notes" . ($workNotesCount > 0 ? " ($workNotesCount)" : "") . ":</strong></p>
            <p style=\"margin: 0; font-size: 14px; color: #0f766e; white-space: pre-line;\">" . htmlspecialchars($plannedWorkNotes) . "</p>
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
    
    // Build planned projects text
    $plannedProjectsText = "";
    if (!empty($plannedProjects) && is_array($plannedProjects)) {
        $projectNames = [];
        if (isset($submissionData['_db_conn']) && $submissionData['_db_conn']) {
            try {
                $conn = $submissionData['_db_conn'];
                $placeholders = str_repeat('?,', count($plannedProjects) - 1) . '?';
                $projectStmt = $conn->prepare("SELECT id, name FROM projects WHERE id IN ($placeholders)");
                $projectStmt->execute($plannedProjects);
                $projectRows = $projectStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($projectRows as $row) {
                    $projectNames[] = $row['name'];
                }
            } catch (Exception $e) {
                error_log("⚠️ Could not fetch project names for email text: " . $e->getMessage());
                $projectNames = $plannedProjects; // Fallback to IDs
            }
        } else {
            $projectNames = $plannedProjects; // Fallback to IDs
        }
        
        if (!empty($projectNames)) {
            $plannedProjectsText = "Projects: " . implode(', ', $projectNames) . "\n";
        }
    }
    
    $plannedWorkText = !empty($plannedWork) ? "Planned Work:\n" . $plannedWork . "\n\n" : "";
    
    // Add Planned Work Status if available
    $plannedWorkStatus = $submissionData['planned_work_status'] ?? null;
    $plannedWorkStatusText = "";
    if (!empty($plannedWorkStatus) && $plannedWorkStatus !== 'not_started') {
        $statusLabels = [
            'not_started' => 'Not Started',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            'on_hold' => 'On Hold',
            'blocked' => 'Blocked',
            'cancelled' => 'Cancelled'
        ];
        $statusLabel = $statusLabels[$plannedWorkStatus] ?? ucfirst(str_replace('_', ' ', $plannedWorkStatus));
        $plannedWorkStatusText = "📊 Planned Work Status: $statusLabel\n\n";
    }
    
    // Add Work Notes if available
    $plannedWorkNotes = trim($submissionData['planned_work_notes'] ?? '');
    $workNotesText = "";
    if (!empty($plannedWorkNotes)) {
        $workNotesCount = $countItems($plannedWorkNotes);
        $workNotesText = "📝 Work Notes" . ($workNotesCount > 0 ? " ($workNotesCount)" : "") . ":\n" . $plannedWorkNotes . "\n\n";
    }
    
    $planningSection = (!empty($plannedProjectsText) || !empty($plannedWorkText) || !empty($plannedWorkStatusText) || !empty($workNotesText)) ? "📋 Planning Details:\n" . $plannedProjectsText . $plannedWorkText . $plannedWorkStatusText . $workNotesText . "\n" : "";
    
    $text_body = "
🧾 CODO Daily Work Update — $userName

📅 Date: $dateFormatted
🕘 Check-in Time: $timeFormatted
⏱ Today's Working Hours: $hours Hours" . ($overtimeHours > 0 ? "
📊 Regular Hours: $regularHours Hours
⏰ Overtime Hours: $overtimeHours Hours" : "") . "
📊 Total Working Days ($periodLabel): $totalWorkingDays Days
🧮 Total Hours Completed: $totalHoursCompleted hours

" . $planningSection . ($completedCount > 0 ? "✅ Completed ($completedCount):\n" . $completedTasks . "\n\n" : "") . 
($pendingCount > 0 ? "⌛ Pending ($pendingCount):\n" . $pendingTasks . "\n\n" : "") .
($ongoingCount > 0 ? "🔄 Ongoing ($ongoingCount):\n" . $ongoingTasks . "\n\n" : "") .
($upcomingCount > 0 ? "🔥 Upcoming ($upcomingCount):\n" . $upcomingTasks . "\n\n" : "") . 
($workNotesText ? $workNotesText : "") .
($plannedWorkStatusText ? $plannedWorkStatusText : "") .
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

function sendProjectMemberAddedEmail($email, $username, $projectName, $projectRole, $addedByName, $projectLink) {
    $subject = "Added to Project - BugRicer";
    
    $html_body = "
    <div style=\"font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f7f6; padding: 20px;\">
      <div style=\"max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">
        
        <!-- Header -->
        <div style=\"background-color: #2563eb; color: #ffffff; padding: 20px; text-align: center;\">
           <h1 style=\"margin: 0; font-size: 24px; display: flex; align-items: center; justify-content: center;\">
            <img src=\"https://fonts.gstatic.com/s/e/notoemoji/16.0/1f41e/32.png\" alt=\"BugRicer Logo\" style=\"width: 30px; height: 30px; margin-right: 10px; vertical-align: middle;\">
            BugRicer Notification
          </h1>
          <p style=\"margin: 5px 0 0 0; font-size: 16px;\">Added to Project</p>
        </div>
        
        <!-- Body -->
        <div style=\"padding: 20px; border-bottom: 1px solid #e2e8f0;\">
          <h3 style=\"margin-top: 0; color: #1e293b; font-size: 18px;\">Hello $username,</h3>
          <p style=\"white-space: pre-line; margin-bottom: 15px; font-size: 14px;\">Great news! You've been added to a project on BugRicer. You can now collaborate with your team, track bugs, and manage tasks.</p>
          
          <div style=\"margin-bottom: 15px; padding: 12px; background-color: #f0f9ff; border-left: 4px solid #0ea5e9; border-radius: 4px;\">
            <p style=\"margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #0c4a6e;\"><strong>📋 Project Details:</strong></p>
            <p style=\"margin: 0; font-size: 14px; color: #0c4a6e;\"><strong>Project:</strong> " . htmlspecialchars($projectName) . "</p>
            <p style=\"margin: 0; font-size: 14px; color: #0c4a6e;\"><strong>Your Role:</strong> " . htmlspecialchars(ucfirst($projectRole)) . "</p>
            <p style=\"margin: 0; font-size: 14px; color: #0c4a6e;\"><strong>Added by:</strong> " . htmlspecialchars($addedByName) . "</p>
          </div>
          
          <div style=\"margin: 20px 0; text-align: center;\">
            <a href=\"" . htmlspecialchars($projectLink) . "\" style=\"background-color: #2563eb; color: #ffffff; padding: 12px 25px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: 500; font-size: 16px;\">View Project</a>
          </div>
          
          <div style=\"margin-bottom: 15px; padding: 12px; background-color: #f0fdf4; border-left: 4px solid #22c55e; border-radius: 4px;\">
            <p style=\"margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #166534;\"><strong>🎯 What You Can Do:</strong></p>
            <p style=\"margin: 0; font-size: 14px; color: #166534;\">• View and manage project bugs<br/>• Access shared tasks and updates<br/>• Collaborate with team members<br/>• Track project progress</p>
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
Added to Project - BugRicer

Hello $username,

Great news! You've been added to a project on BugRicer. You can now collaborate with your team, track bugs, and manage tasks.

Project Details:
Project: " . htmlspecialchars($projectName) . "
Your Role: " . htmlspecialchars(ucfirst($projectRole)) . "
Added by: " . htmlspecialchars($addedByName) . "

View Project: " . htmlspecialchars($projectLink) . "

What You Can Do:
• View and manage project bugs
• Access shared tasks and updates
• Collaborate with team members
• Track project progress

If you have any questions or need assistance, please don't hesitate to contact our support team.

Best regards,
The BugRicer Team

© " . date('Y') . " BugRicer. All rights reserved.
    ";
    
    return sendEmail($email, $subject, $html_body, $text_body);
}

function sendCheckInNotificationEmail($adminEmail, $username, $checkInTime, $date, $plannedProjects = null, $plannedWork = null, $yesterdaySummary = null) {
    $subject = "Check-in — " . $username;

    $dateFormatted = date('D, M j, Y', strtotime($date));
    $timeFormatted = date('h:i A', strtotime($checkInTime));

    $projectsList = 'None specified';
    if (!empty($plannedProjects)) {
        if (is_array($plannedProjects)) {
            $projectsList = implode(', ', array_filter(array_map(function ($p) {
                return is_array($p) ? trim((string)($p['name'] ?? $p['id'] ?? '')) : trim((string)$p);
            }, $plannedProjects)));
            if ($projectsList === '') {
                $projectsList = 'None specified';
            }
        } else {
            $projectsList = (string)$plannedProjects;
        }
    }

    $workText = !empty($plannedWork) ? trim((string)$plannedWork) : 'No planned work specified';

    $formatTime = static function ($value) {
        if (empty($value)) {
            return '—';
        }
        $ts = strtotime((string)$value);
        return $ts ? date('h:i A', $ts) : '—';
    };
    $formatHours = static function ($hours) {
        $h = round((float)$hours, 2);
        if (abs($h - (int)$h) < 0.001) {
            return (string)(int)$h;
        }
        return rtrim(rtrim(number_format($h, 2, '.', ''), '0'), '.');
    };

    $yesterdayHtml = '';
    $yesterdayText = '';
    if (is_array($yesterdaySummary) && !empty($yesterdaySummary['has_record'])) {
        $yDate = $yesterdaySummary['date'] ?? null;
        $yDateLabel = $yDate ? date('D, M j, Y', strtotime((string)$yDate)) : 'Previous day';
        $yIn = $formatTime($yesterdaySummary['check_in_time'] ?? null);
        $yOut = $formatTime($yesterdaySummary['check_out_time'] ?? null);
        $yHours = $formatHours($yesterdaySummary['hours_today'] ?? 0);
        $yOt = $formatHours($yesterdaySummary['overtime_hours'] ?? 0);

        $yesterdayHtml = "
          <div style=\"margin-bottom: 15px; padding: 12px; background-color: #eef2ff; border-left: 4px solid #6366f1; border-radius: 4px;\">
            <p style=\"margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #3730a3;\"><strong>Yesterday's Summary — " . htmlspecialchars($yDateLabel) . "</strong></p>
            <p style=\"margin: 0; font-size: 14px; color: #3730a3;\"><strong>Check-in:</strong> " . htmlspecialchars($yIn) . "</p>
            <p style=\"margin: 0; font-size: 14px; color: #3730a3;\"><strong>Check-out:</strong> " . htmlspecialchars($yOut) . "</p>
            <p style=\"margin: 0; font-size: 14px; color: #3730a3;\"><strong>Hours worked:</strong> " . htmlspecialchars($yHours) . "</p>
            <p style=\"margin: 0; font-size: 14px; color: #3730a3;\"><strong>Overtime (OT):</strong> " . htmlspecialchars($yOt) . "</p>
          </div>";

        $yesterdayText = "
Yesterday's Summary — " . $yDateLabel . "
Check-in: " . $yIn . "
Check-out: " . $yOut . "
Hours worked: " . $yHours . "
Overtime (OT): " . $yOt . "
";
    } else {
        $yesterdayHtml = "
          <div style=\"margin-bottom: 15px; padding: 12px; background-color: #f8fafc; border-left: 4px solid #94a3b8; border-radius: 4px;\">
            <p style=\"margin: 0; font-size: 14px; color: #475569;\"><strong>Yesterday's Summary:</strong> No attendance record found.</p>
          </div>";
        $yesterdayText = "
Yesterday's Summary: No attendance record found.
";
    }

    $html_body = "
    <div style=\"font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f7f6; padding: 20px;\">
      <div style=\"max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">

        <div style=\"background-color: #059669; color: #ffffff; padding: 20px; text-align: center;\">
           <h1 style=\"margin: 0; font-size: 22px;\">Check-in</h1>
          <p style=\"margin: 6px 0 0 0; font-size: 14px; opacity: 0.95;\">Attendance alert</p>
        </div>

        <div style=\"padding: 20px; border-bottom: 1px solid #e2e8f0;\">
          <p style=\"margin: 0 0 16px 0; font-size: 14px;\"><strong>" . htmlspecialchars($username) . "</strong> has checked in for the work day.</p>

          <div style=\"margin-bottom: 15px; padding: 12px; background-color: #f0fdf4; border-left: 4px solid #10b981; border-radius: 4px;\">
            <p style=\"margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #166534;\"><strong>Today — " . htmlspecialchars($dateFormatted) . "</strong></p>
            <p style=\"margin: 0; font-size: 14px; color: #166534;\"><strong>Check-in:</strong> " . htmlspecialchars($timeFormatted) . "</p>
          </div>

          <div style=\"margin-bottom: 15px; padding: 12px; background-color: #f0f9ff; border-left: 4px solid #0ea5e9; border-radius: 4px;\">
            <p style=\"margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #0c4a6e;\"><strong>Today's Plan</strong></p>
            <p style=\"margin: 0; font-size: 14px; color: #0c4a6e;\"><strong>Projects:</strong> " . htmlspecialchars($projectsList) . "</p>
            <p style=\"margin: 8px 0 0 0; font-size: 14px; color: #0c4a6e; white-space: pre-wrap;\"><strong>Work focus:</strong> " . htmlspecialchars($workText) . "</p>
          </div>

          " . $yesterdayHtml . "

          <p style=\"font-size: 14px; margin-bottom: 0;\">Best regards,<br>The BugRicer Team</p>
        </div>

        <div style=\"background-color: #f8fafc; color: #64748b; padding: 20px; text-align: center; font-size: 12px;\">
          <p style=\"margin: 0;\">Automated attendance notification from BugRicer.</p>
          <p style=\"margin: 5px 0 0 0;\">&copy; " . date('Y') . " BugRicer. All rights reserved.</p>
        </div>

      </div>
    </div>
    ";

    $text_body = "
Check-in — BugRicer

" . $username . " has checked in for the work day.

Today — " . $dateFormatted . "
Check-in: " . $timeFormatted . "

Today's Plan
Projects: " . $projectsList . "
Work focus: " . $workText . "
" . $yesterdayText . "
Best regards,
The BugRicer Team

© " . date('Y') . " BugRicer. All rights reserved.
    ";

    return sendEmail($adminEmail, $subject, $html_body, $text_body);
}

/**
 * Email admins when a CODO compliance rule is marked verified.
 */
function sendComplianceVerifiedEmail(
    $adminEmail,
    $verifierName,
    $projectName,
    $phaseLabel,
    $ruleTitle,
    $ruleKey = null,
    $complianceUrl = null
) {
    $subject = 'Compliance verified — ' . $projectName;
    $ruleDisplay = trim((string) $ruleTitle) !== '' ? trim((string) $ruleTitle) : (string) $ruleKey;
    $url = $complianceUrl ?: 'https://bugs.bugricer.com';

    $html_body = "
    <div style=\"font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f7f6; padding: 20px;\">
      <div style=\"max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">
        <div style=\"background-color: #2563eb; color: #ffffff; padding: 20px; text-align: center;\">
          <h1 style=\"margin: 0; font-size: 22px;\">Compliance Verified</h1>
          <p style=\"margin: 6px 0 0 0; font-size: 14px; opacity: 0.95;\">CODO verification alert</p>
        </div>
        <div style=\"padding: 24px;\">
          <p style=\"font-size: 15px; margin-top: 0;\">
            <strong>" . htmlspecialchars((string) $verifierName) . "</strong> marked a compliance rule as verified.
          </p>
          <div style=\"margin: 18px 0; padding: 14px; background-color: #eff6ff; border-left: 4px solid #2563eb; border-radius: 4px;\">
            <p style=\"margin: 0; font-size: 14px; color: #1e3a8a;\"><strong>Project:</strong> " . htmlspecialchars((string) $projectName) . "</p>
            <p style=\"margin: 6px 0 0 0; font-size: 14px; color: #1e3a8a;\"><strong>Phase:</strong> " . htmlspecialchars((string) $phaseLabel) . "</p>
            <p style=\"margin: 6px 0 0 0; font-size: 14px; color: #1e3a8a;\"><strong>Rule:</strong> " . htmlspecialchars((string) $ruleDisplay) . "</p>
          </div>
          <p style=\"text-align: center; margin: 24px 0 8px 0;\">
            <a href=\"" . htmlspecialchars((string) $url) . "\"
               style=\"display: inline-block; background-color: #2563eb; color: #ffffff; text-decoration: none; padding: 12px 22px; border-radius: 8px; font-weight: 600;\">
              Open Compliance
            </a>
          </p>
          <p style=\"font-size: 14px; margin-bottom: 0;\">Best regards,<br>The BugRicer Team</p>
        </div>
        <div style=\"background-color: #f8fafc; color: #64748b; padding: 20px; text-align: center; font-size: 12px;\">
          <p style=\"margin: 0;\">Automated compliance notification from BugRicer.</p>
          <p style=\"margin: 5px 0 0 0;\">&copy; " . date('Y') . " BugRicer. All rights reserved.</p>
        </div>
      </div>
    </div>
    ";

    $text_body = "
Compliance Verified — BugRicer

" . $verifierName . " marked a compliance rule as verified.

Project: " . $projectName . "
Phase: " . $phaseLabel . "
Rule: " . $ruleDisplay . "

Open: " . $url . "

Best regards,
The BugRicer Team
";

    return sendEmail($adminEmail, $subject, $html_body, $text_body);
}

/**
 * Email project members/admins about upcoming, due, or overdue project timeline milestones.
 */
function sendProjectDeadlineReminderEmail(
    $email,
    $username,
    $projectName,
    $milestoneLabel,
    $milestoneDate,
    $reminderOffset,
    $projectUrl = null
) {
    $offset = (int) $reminderOffset;
    $safeName = htmlspecialchars((string) ($username ?: 'there'));
    $safeProject = htmlspecialchars((string) $projectName);
    $safeMilestone = htmlspecialchars((string) $milestoneLabel);
    $dateTs = strtotime((string) $milestoneDate);
    $dateLabel = $dateTs ? date('l, F j, Y', $dateTs) : htmlspecialchars((string) $milestoneDate);
    $url = $projectUrl ?: 'https://bugs.bugricer.com';

    if ($offset > 0) {
        $headline = $offset === 1 ? 'Milestone tomorrow' : "Milestone in {$offset} days";
        $urgency = $offset === 1
            ? 'This milestone is tomorrow — please confirm the team is on track.'
            : "This milestone is coming up in {$offset} days.";
        $accent = '#2563eb';
        $bg = '#eff6ff';
        $text = '#1e3a8a';
    } elseif ($offset === 0) {
        $headline = 'Milestone due today';
        $urgency = 'This milestone is due today. Please review progress and next steps.';
        $accent = '#d97706';
        $bg = '#fffbeb';
        $text = '#92400e';
    } else {
        $overdueDays = abs($offset);
        $headline = 'Milestone overdue';
        $urgency = "This milestone is {$overdueDays} day" . ($overdueDays === 1 ? '' : 's') . ' overdue.';
        $accent = '#dc2626';
        $bg = '#fef2f2';
        $text = '#991b1b';
    }

    $subject = $headline . ' — ' . $projectName;

    $html_body = "
    <div style=\"font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f7f6; padding: 20px;\">
      <div style=\"max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">
        <div style=\"background-color: {$accent}; color: #ffffff; padding: 20px; text-align: center;\">
          <h1 style=\"margin: 0; font-size: 22px;\">" . htmlspecialchars($headline) . "</h1>
          <p style=\"margin: 6px 0 0 0; font-size: 14px; opacity: 0.95;\">Project timeline reminder</p>
        </div>
        <div style=\"padding: 24px;\">
          <p style=\"font-size: 15px; margin-top: 0;\">Hello {$safeName},</p>
          <p style=\"font-size: 14px;\">{$urgency}</p>
          <div style=\"margin: 18px 0; padding: 14px; background-color: {$bg}; border-left: 4px solid {$accent}; border-radius: 4px;\">
            <p style=\"margin: 0; font-size: 14px; color: {$text};\"><strong>Project:</strong> {$safeProject}</p>
            <p style=\"margin: 6px 0 0 0; font-size: 14px; color: {$text};\"><strong>Milestone:</strong> {$safeMilestone}</p>
            <p style=\"margin: 6px 0 0 0; font-size: 14px; color: {$text};\"><strong>Date:</strong> {$dateLabel}</p>
          </div>
          <p style=\"text-align: center; margin: 24px 0 8px 0;\">
            <a href=\"" . htmlspecialchars((string) $url) . "\"
               style=\"display: inline-block; background-color: {$accent}; color: #ffffff; text-decoration: none; padding: 12px 22px; border-radius: 8px; font-weight: 600;\">
              Open Project
            </a>
          </p>
          <p style=\"font-size: 14px; margin-bottom: 0;\">Best regards,<br>The BugRicer Team</p>
        </div>
        <div style=\"background-color: #f8fafc; color: #64748b; padding: 20px; text-align: center; font-size: 12px;\">
          <p style=\"margin: 0;\">This is an automated reminder from BugRicer. Please do not reply to this email.</p>
          <p style=\"margin: 5px 0 0 0;\">&copy; " . date('Y') . " BugRicer. All rights reserved.</p>
        </div>
      </div>
    </div>
    ";

    $text_body = "
{$headline} — BugRicer

Hello " . ($username ?: 'there') . ",

{$urgency}

Project: {$projectName}
Milestone: {$milestoneLabel}
Date: {$dateLabel}

Open project: {$url}

Best regards,
The BugRicer Team
";

    return sendEmail($email, $subject, $html_body, $text_body);
}

/**
 * Email admins when a user marks a Common CODO rule status.
 */
function sendCodoRuleStatusEmail(
    $adminEmail,
    $username,
    $ruleTitle,
    $ruleKey,
    $phase,
    $status,
    $codoUrl = null
) {
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

    if ($statusKey === 'doubt') {
        $accent = '#d97706';
        $bg = '#fffbeb';
        $text = '#92400e';
    } elseif ($statusKey === 'not_required') {
        $accent = '#64748b';
        $bg = '#f8fafc';
        $text = '#334155';
    } else {
        $accent = '#16a34a';
        $bg = '#f0fdf4';
        $text = '#166534';
    }

    $subject = "CODO {$statusLabel} — {$titleDisplay}";
    $safeUser = htmlspecialchars((string) $username);
    $safeTitle = htmlspecialchars((string) $titleDisplay);
    $safeKey = htmlspecialchars((string) $ruleKey);
    $safePhase = htmlspecialchars((string) $phaseLabel);
    $safeStatus = htmlspecialchars((string) $statusLabel);

    $html_body = "
    <div style=\"font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f7f6; padding: 20px;\">
      <div style=\"max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">
        <div style=\"background-color: {$accent}; color: #ffffff; padding: 20px; text-align: center;\">
          <h1 style=\"margin: 0; font-size: 22px;\">CODO Rule Response</h1>
          <p style=\"margin: 6px 0 0 0; font-size: 14px; opacity: 0.95;\">{$safeStatus}</p>
        </div>
        <div style=\"padding: 24px;\">
          <p style=\"font-size: 15px; margin-top: 0;\">
            <strong>{$safeUser}</strong> marked a Common CODO rule as <strong>{$safeStatus}</strong>.
          </p>
          <div style=\"margin: 18px 0; padding: 14px; background-color: {$bg}; border-left: 4px solid {$accent}; border-radius: 4px;\">
            <p style=\"margin: 0; font-size: 14px; color: {$text};\"><strong>Rule:</strong> {$safeTitle}</p>
            <p style=\"margin: 6px 0 0 0; font-size: 14px; color: {$text};\"><strong>Key:</strong> {$safeKey}</p>
            <p style=\"margin: 6px 0 0 0; font-size: 14px; color: {$text};\"><strong>Phase:</strong> {$safePhase}</p>
            <p style=\"margin: 6px 0 0 0; font-size: 14px; color: {$text};\"><strong>Status:</strong> {$safeStatus}</p>
          </div>
          <p style=\"text-align: center; margin: 24px 0 8px 0;\">
            <a href=\"" . htmlspecialchars((string) $url) . "\"
               style=\"display: inline-block; background-color: {$accent}; color: #ffffff; text-decoration: none; padding: 12px 22px; border-radius: 8px; font-weight: 600;\">
              Open Common CODO
            </a>
          </p>
          <p style=\"font-size: 14px; margin-bottom: 0;\">Best regards,<br>The BugRicer Team</p>
        </div>
        <div style=\"background-color: #f8fafc; color: #64748b; padding: 20px; text-align: center; font-size: 12px;\">
          <p style=\"margin: 0;\">Automated CODO notification from BugRicer.</p>
          <p style=\"margin: 5px 0 0 0;\">&copy; " . date('Y') . " BugRicer. All rights reserved.</p>
        </div>
      </div>
    </div>
    ";

    $text_body = "
CODO Rule Response — BugRicer

{$username} marked a Common CODO rule as {$statusLabel}.

Rule: {$titleDisplay}
Key: {$ruleKey}
Phase: {$phaseLabel}
Status: {$statusLabel}

Open: {$url}

Best regards,
The BugRicer Team
";

    return sendEmail($adminEmail, $subject, $html_body, $text_body);
}
?>
