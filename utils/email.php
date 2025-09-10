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
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Password Reset - BugRicer</title>
        <style>
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f8fafc; }
            .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); }
            .header { background: linear-gradient(135deg, #3b82f6, #6366f1); padding: 40px 30px; text-align: center; }
            .logo { width: 60px; height: 60px; background: white; border-radius: 12px; margin: 0 auto 20px; display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: bold; color: #3b82f6; }
            .header h1 { color: white; margin: 0; font-size: 28px; font-weight: 700; }
            .content { padding: 40px 30px; }
            .content h2 { color: #1f2937; margin: 0 0 20px; font-size: 24px; font-weight: 600; }
            .content p { color: #6b7280; margin: 0 0 20px; font-size: 16px; }
            .button { display: inline-block; background: linear-gradient(135deg, #3b82f6, #6366f1); color: white; text-decoration: none; padding: 16px 32px; border-radius: 8px; font-weight: 600; font-size: 16px; margin: 20px 0; }
            .button:hover { background: linear-gradient(135deg, #2563eb, #4f46e5); }
            .code { background: #f3f4f6; padding: 20px; border-radius: 8px; text-align: center; margin: 20px 0; font-family: 'Courier New', monospace; font-size: 14px; word-break: break-all; color: #374151; }
            .footer { background: #f9fafb; padding: 30px; text-align: center; border-top: 1px solid #e5e7eb; }
            .footer p { color: #9ca3af; font-size: 14px; margin: 0; }
            .warning { background: #fef3c7; border: 1px solid #f59e0b; border-radius: 8px; padding: 16px; margin: 20px 0; }
            .warning p { color: #92400e; margin: 0; font-size: 14px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <div class='logo'>🐛</div>
                <h1>BugRicer</h1>
            </div>
            <div class='content'>
                <h2>Password Reset Request</h2>
                <p>Hello <strong>$username</strong>,</p>
                <p>We received a request to reset your password for your BugRicer account. If you made this request, click the button below to reset your password:</p>
                
                <div style='text-align: center;'>
                    <a href='$reset_link' class='button'>Reset My Password</a>
                </div>
                
                <div class='warning'>
                    <p><strong>Security Notice:</strong> This link will expire in 1 hour for your security. If you didn't request this password reset, please ignore this email.</p>
                </div>
                
                <p>If the button doesn't work, you can copy and paste this link into your browser:</p>
                <div class='code'>$reset_link</div>
                
                <p>If you have any questions or need assistance, please contact our support team.</p>
                
                <p>Best regards,<br>The BugRicer Team</p>
            </div>
            <div class='footer'>
                <p>© 2025 BugRicer. All rights reserved.</p>
                <p>This is an automated message, please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>
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
    // Check if we're in development mode (localhost)
    $is_development = (
        strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false ||
        strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false
    );
    
    // For testing purposes, you can force email sending by setting FORCE_EMAIL_SEND=true
    $force_send = isset($_GET['force_send']) && $_GET['force_send'] === 'true';
    
    if ($is_development && !$force_send) {
        // In development mode, log the email instead of sending
        error_log("=== PASSWORD RESET EMAIL ===");
        error_log("To: $to");
        error_log("Subject: $subject");
        error_log("HTML Body: " . substr($html_body, 0, 500) . "...");
        error_log("Text Body: " . substr($text_body, 0, 200) . "...");
        error_log("=== END EMAIL ===");
        return true;
    }
    
    // In production, use PHPMailer with SMTP
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
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Welcome to BugRicer</title>
        <style>
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f8fafc; }
            .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); }
            .header { background: linear-gradient(135deg, #3b82f6, #6366f1); padding: 40px 30px; text-align: center; }
            .logo { width: 60px; height: 60px; background: white; border-radius: 12px; margin: 0 auto 20px; display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: bold; color: #3b82f6; }
            .header h1 { color: white; margin: 0; font-size: 28px; font-weight: 700; }
            .content { padding: 40px 30px; }
            .content h2 { color: #1f2937; margin: 0 0 20px; font-size: 24px; font-weight: 600; }
            .content p { color: #6b7280; margin: 0 0 20px; font-size: 16px; }
            .button { display: inline-block; background: linear-gradient(135deg, #3b82f6, #6366f1); color: white; text-decoration: none; padding: 16px 32px; border-radius: 8px; font-weight: 600; font-size: 16px; margin: 20px 0; }
            .footer { background: #f9fafb; padding: 30px; text-align: center; border-top: 1px solid #e5e7eb; }
            .footer p { color: #9ca3af; font-size: 14px; margin: 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <div class='logo'>🐛</div>
                <h1>BugRicer</h1>
            </div>
            <div class='content'>
                <h2>Welcome to BugRicer!</h2>
                <p>Hello <strong>$username</strong>,</p>
                <p>Welcome to BugRicer! Your account has been successfully created and you're ready to start tracking bugs and managing your projects.</p>
                <p>You can now log in to your account and start exploring all the features we have to offer.</p>
                <p>If you have any questions or need assistance, please don't hesitate to contact our support team.</p>
                <p>Best regards,<br>The BugRicer Team</p>
            </div>
            <div class='footer'>
                <p>© 2025 BugRicer. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
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
