<?php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/users/UserController.php';

$controller = new UserController();
$conn = $controller->getConnection();

// Get all users with a non-null fcm_token
$stmt = $conn->query("SELECT fcm_token FROM users WHERE fcm_token IS NOT NULL");
$tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (!$tokens) {
    echo json_encode(['success' => false, 'error' => 'No tokens found']);
    exit;
}

// Get custom title/body from POST, or use defaults
$input = json_decode(file_get_contents('php://input'), true);
$title = $input['title'] ?? 'Update!';
$body = $input['body'] ?? 'A new update is available for BugRicer.';

$notification = [
    'title' => $title,
    'body' => $body,
];

$data = [
    "registration_ids" => $tokens,
    "notification" => $notification,
];

// Your FCM server key (keep this secret!)
$serverKey = 'crh5Dbx0tZzUxxtShKZpiS:APA91bFQUozthEB9GHW9XHiZbHZl02fLNZCa6JGTlu4SCKxx4MV-j9oXKST3K_flnYpe2SZm2iPqrB9kqIKqmujEHdfd1LFcAx_sbJKx3IiGwS2cpie1iFk';

$headers = [
    'Authorization: key=' . $serverKey,
    'Content-Type: application/json'
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://fcm.googleapis.com/fcm/send");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
$result = curl_exec($ch);
curl_close($ch);

echo $result;
?>