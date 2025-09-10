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
            <img src=\"https://fonts.gstatic.com/s/e/notoemoji/16.0/1f41e/32.png\" alt=\"Bug Ricer Logo\" style=\"width: 30px; height: 30px; margin-right: 10px; vertical-align: middle;\">
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
          <p style=\"margin: 0;\">This is an automated notification from Bug Ricer. Please do not reply to this email.</p>
          <p style=\"margin: 5px 0 0 0;\">&copy; " . date('Y') . " Bug Ricer. All rights reserved.</p>
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
        $mail = new PHPMailer(true);
        
        // HOSTINGER SMTP CONFIGURATION (from send_email.php)
        $mail->isSMTP();
        $mail->Host = 'smtp.hostinger.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'bugs@moajmalnk.in';
        $mail->Password = 'Codo@8848';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = 465;
        
        // Recipients
        $mail->setFrom('bugs@moajmalnk.in', 'BugRicer');
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
        error_log("Password reset email sent successfully to: $to");
        return true;
        
    } catch (Exception $e) {
        error_log("Password reset email error: " . $e->getMessage());
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
            <img src=\"https://fonts.gstatic.com/s/e/notoemoji/16.0/1f41e/32.png\" alt=\"Bug Ricer Logo\" style=\"width: 30px; height: 30px; margin-right: 10px; vertical-align: middle;\">
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
          <p style=\"margin: 0;\">This is an automated notification from Bug Ricer. Please do not reply to this email.</p>
          <p style=\"margin: 5px 0 0 0;\">&copy; " . date('Y') . " Bug Ricer. All rights reserved.</p>
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
?>
