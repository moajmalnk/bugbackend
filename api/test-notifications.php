<?php
/**
 * Test endpoint for WhatsApp and Email notifications (Admin only)
 * Helps diagnose why notifications might not be received.
 * Usage: POST with Authorization: Bearer <admin_token>
 * Optional body: { "email": "test@example.com", "phone": "919497792540" }
 */
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/BaseAPI.php';

header('Content-Type: application/json');

$api = new BaseAPI();
$decoded = $api->validateToken();
if (!$decoded || $decoded->role !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

$conn = $api->getConnection();
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$testEmail = $body['email'] ?? ($decoded->email ?? null);
if (!$testEmail && isset($decoded->user_id)) {
    $u = $conn->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
    $u->execute([$decoded->user_id]);
    $ur = $u->fetch(PDO::FETCH_ASSOC);
    if ($ur && !empty($ur['email'])) $testEmail = $ur['email'];
}
$testPhone = $body['phone'] ?? '919497792540'; // Default to admin number

$results = ['whatsapp' => null, 'email' => null];

// Test WhatsApp
try {
    require_once __DIR__ . '/../utils/whatsapp.php';
    $msg = "✅ BugRicer Notify Test\n\nThis is a test message from BugRicer. If you received this, WhatsApp notifications are working.";
    $waResult = sendWhatsAppMessage($testPhone, $msg);
    $results['whatsapp'] = [
        'success' => $waResult,
        'phone_tested' => $testPhone,
        'message' => $waResult ? 'Message sent successfully' : 'Failed to send (check API key, phone format 91XXXXXXXXXX)',
    ];
} catch (Exception $e) {
    $results['whatsapp'] = ['success' => false, 'error' => $e->getMessage()];
}

// Test Email
if ($testEmail) {
    try {
        require_once __DIR__ . '/../utils/send_email.php';
        $subject = 'BugRicer - Test Notification';
        $bodyHtml = '<p>This is a test email from BugRicer. If you received this, email notifications are working.</p>';
        $emailResult = sendBugNotification([$testEmail], $subject, $bodyHtml, []);
        $results['email'] = [
            'success' => $emailResult,
            'email_tested' => $testEmail,
            'message' => $emailResult ? 'Email sent successfully (check inbox/spam)' : 'Failed to send (check SMTP, logs)',
        ];
    } catch (Exception $e) {
        $results['email'] = ['success' => false, 'error' => $e->getMessage()];
    }
} else {
    $results['email'] = ['success' => false, 'message' => 'No email provided to test'];
}

// Also verify global settings
$stmt = $conn->prepare("SELECT value FROM settings WHERE key_name = 'email_notifications_enabled' LIMIT 1");
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$results['email_notifications_enabled'] = ($row['value'] ?? '1') === '1';

echo json_encode([
    'success' => true,
    'results' => $results,
    'hint' => 'If WhatsApp/email failed: 1) Check PHP error_log 2) Verify API key in .env 3) For email: Gmail app password may have expired',
]);
