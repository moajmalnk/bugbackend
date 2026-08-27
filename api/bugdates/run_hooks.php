<?php
/**
 * Why: Daily BugDates auto-hooks (creative drafts / shared tasks) for today's occurrences.
 * CLI: php run_hooks.php
 * HTTP: ?token=BUGDATES_HOOK_SECRET or header X_CRON_TOKEN
 */
require_once __DIR__ . '/BugDatesController.php';

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }

    $secret = getenv('BUGDATES_HOOK_SECRET') ?: (getenv('DEADLINE_REMINDER_SECRET') ?: '');
    $token = $_GET['token'] ?? ($_SERVER['HTTP_X_CRON_TOKEN'] ?? '');
    if ($secret === '' || !hash_equals((string)$secret, (string)$token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit();
    }
}

$forDate = null;
if ($isCli) {
    global $argv;
    $forDate = $argv[1] ?? null;
} else {
    $forDate = $_GET['date'] ?? null;
}

$c = new BugDatesController();
$c->runHooks($forDate);
