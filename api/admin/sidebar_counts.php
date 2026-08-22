<?php
/**
 * Lightweight admin sidebar counts (permission-gated).
 * GET /api/admin/sidebar_counts.php
 */

require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/AdminSidebarCountsController.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$controller = new AdminSidebarCountsController();
$controller->get();
