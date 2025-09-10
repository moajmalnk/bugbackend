<?php
/**
 * View email logs for password reset emails
 */

echo "<h2>BugRicer Email Log Viewer</h2>\n";

$log_file = '/Applications/XAMPP/xamppfiles/logs/php_error_log';

if (!file_exists($log_file)) {
    echo "<p style='color: red;'>❌ Error log file not found at: $log_file</p>\n";
    exit;
}

// Read the last 200 lines of the log
$lines = file($log_file);
$recent_lines = array_slice($lines, -200);

$in_email_section = false;
$email_content = [];
$current_email = [];

foreach ($recent_lines as $line) {
    $line = trim($line);
    
    if (strpos($line, '=== PASSWORD RESET EMAIL ===') !== false) {
        $in_email_section = true;
        $current_email = [];
        continue;
    }
    
    if (strpos($line, '=== END EMAIL ===') !== false) {
        $in_email_section = false;
        if (!empty($current_email)) {
            $email_content[] = $current_email;
        }
        continue;
    }
    
    if ($in_email_section) {
        $current_email[] = $line;
    }
}

if (empty($email_content)) {
    echo "<p style='color: orange;'>⚠️ No password reset emails found in recent logs.</p>\n";
    echo "<p>Try making a forgot password request first, then refresh this page.</p>\n";
    echo "<p><a href='test_forgot_password.php'>Test Forgot Password API</a></p>\n";
} else {
    echo "<p style='color: green;'>✅ Found " . count($email_content) . " password reset email(s) in recent logs.</p>\n";
    
    foreach ($email_content as $index => $email) {
        echo "<div style='border: 1px solid #ccc; margin: 20px 0; padding: 20px; background: #f9f9f9;'>\n";
        echo "<h3>Email #" . ($index + 1) . "</h3>\n";
        
        foreach ($email as $line) {
            if (strpos($line, 'To:') === 0) {
                echo "<p><strong>To:</strong> " . htmlspecialchars(substr($line, 3)) . "</p>\n";
            } elseif (strpos($line, 'Subject:') === 0) {
                echo "<p><strong>Subject:</strong> " . htmlspecialchars(substr($line, 8)) . "</p>\n";
            } elseif (strpos($line, 'HTML Body:') === 0) {
                echo "<h4>HTML Content:</h4>\n";
                $html_content = substr($line, 10);
                echo "<div style='border: 1px solid #ddd; padding: 10px; background: white; max-height: 400px; overflow-y: auto;'>\n";
                echo htmlspecialchars($html_content);
                echo "</div>\n";
            } elseif (strpos($line, 'Text Body:') === 0) {
                echo "<h4>Text Content:</h4>\n";
                $text_content = substr($line, 10);
                echo "<div style='border: 1px solid #ddd; padding: 10px; background: white; white-space: pre-wrap; font-family: monospace;'>\n";
                echo htmlspecialchars($text_content);
                echo "</div>\n";
            }
        }
        
        echo "</div>\n";
    }
}

echo "<hr>\n";
echo "<p><a href='test_forgot_password.php'>Test Forgot Password API</a> | <a href='test_database_setup.php'>Database Setup Test</a></p>\n";
?>
