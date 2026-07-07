<?php
require_once __DIR__ . '/../../config/cors.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$headers = function_exists('getallheaders') ? getallheaders() : [];
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (!is_string($auth) || stripos($auth, 'Bearer ') !== 0) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$text = trim((string) ($_GET['text'] ?? ''));
$lang = strtolower(trim((string) ($_GET['lang'] ?? 'ml')));

if ($text === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Text is required']);
    exit();
}

if (!in_array($lang, ['ml', 'en'], true)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unsupported language']);
    exit();
}

if (mb_strlen($text) > 500) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Text too long']);
    exit();
}

$tl = $lang === 'ml' ? 'ml' : 'en';
$ttsUrl = 'https://translate.google.com/translate_tts?ie=UTF-8&client=tw-ob&tl=' . rawurlencode($tl) . '&q=' . rawurlencode($text);

$ch = curl_init($ttsUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    CURLOPT_HTTPHEADER => [
        'Accept: audio/mpeg,audio/*,*/*;q=0.9',
        'Referer: https://translate.google.com/',
    ],
]);

$audio = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($audio === false || $httpCode !== 200 || strlen($audio) < 128) {
    error_log('TTS proxy failed: HTTP ' . $httpCode . ' curl=' . $curlError);
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Could not generate speech audio']);
    exit();
}

header('Content-Type: audio/mpeg');
header('Cache-Control: private, max-age=3600');
header('Content-Length: ' . strlen($audio));
echo $audio;
