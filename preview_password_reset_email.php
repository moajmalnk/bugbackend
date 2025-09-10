<?php
/**
 * Preview password reset email
 */

require_once 'utils/email.php';

echo "<h2>Password Reset Email Preview</h2>\n";

// Sample data for preview
$email = 'moajmalnk@gmail.com';
$username = 'moajmalnk';
$reset_token = 'sample_token_1234567890abcdef1234567890abcdef1234567890abcdef1234567890ab';
$reset_link = "https://yourdomain.com/reset-password?token=" . $reset_token;

echo "<p><strong>This is what the password reset email would look like:</strong></p>\n";

// Generate the email content
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

// Display the email preview
echo "<div style='border: 2px solid #ccc; margin: 20px 0; padding: 0; max-width: 600px;'>\n";
echo $html_body;
echo "</div>\n";

echo "<h3>Email Details:</h3>\n";
echo "<ul>\n";
echo "<li><strong>To:</strong> $email</li>\n";
echo "<li><strong>Subject:</strong> $subject</li>\n";
echo "<li><strong>Reset Token:</strong> $reset_token</li>\n";
echo "<li><strong>Reset Link:</strong> $reset_link</li>\n";
echo "<li><strong>Expires:</strong> 1 hour from request time</li>\n";
echo "</ul>\n";

echo "<h3>Development Mode Notes:</h3>\n";
echo "<div style='background: #f0f9ff; border: 1px solid #0ea5e9; padding: 15px; border-radius: 8px; margin: 20px 0;'>\n";
echo "<p><strong>In development mode:</strong></p>\n";
echo "<ul>\n";
echo "<li>✅ Emails are generated and logged to the error log</li>\n";
echo "<li>✅ No actual emails are sent (prevents spam during development)</li>\n";
echo "<li>✅ You can view the email content in the logs</li>\n";
echo "<li>✅ The password reset tokens are stored in the database</li>\n";
echo "</ul>\n";
echo "<p><strong>For production:</strong> Configure SMTP settings to send real emails.</p>\n";
echo "</div>\n";

echo "<hr>\n";
echo "<p><a href='view_email_log.php'>View Email Logs</a> | <a href='test_forgot_password.php'>Test API</a> | <a href='test_database_setup.php'>Database Test</a></p>\n";
?>
