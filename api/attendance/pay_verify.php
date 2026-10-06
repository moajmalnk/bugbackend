<?php
/**
 * Pay Verify router.
 *
 * GET  ?action=month|user-month|rates|pending
 * POST ?action=employee-week|admin-week|employee-month|admin-month|rate|adjustment|delete-adjustment|seed-rates|seed-sept-adjustments
 */
require_once __DIR__ . '/PayVerifyController.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$c = new PayVerifyController();
$action = strtolower(trim((string)($_GET['action'] ?? $_POST['action'] ?? '')));
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    if ($action === '' || $action === 'month') {
        $c->listMonth();
        exit();
    }
    if ($action === 'user-month' || $action === 'user') {
        $c->getUserMonth();
        exit();
    }
    if ($action === 'rates') {
        $c->listRates();
        exit();
    }
    if ($action === 'pending') {
        $c->pendingCounts();
        exit();
    }
}

if ($method === 'POST') {
    if ($action === 'employee-week') {
        $c->employeeVerifyWeek();
        exit();
    }
    if ($action === 'admin-week') {
        $c->adminVerifyWeek();
        exit();
    }
    if ($action === 'employee-month') {
        $c->employeeVerifyMonth();
        exit();
    }
    if ($action === 'admin-month') {
        $c->adminLockMonth();
        exit();
    }
    if ($action === 'rate') {
        $c->setRate();
        exit();
    }
    if ($action === 'adjustment') {
        $c->addAdjustment();
        exit();
    }
    if ($action === 'delete-adjustment') {
        $c->deleteAdjustment();
        exit();
    }
    if ($action === 'seed-rates') {
        $c->seedRates();
        exit();
    }
    if ($action === 'seed-sept-adjustments') {
        $c->seedSeptemberAdjustments();
        exit();
    }
}

http_response_code(405);
header('Content-Type: application/json');
echo json_encode(['success' => false, 'message' => 'Method or action not allowed']);
