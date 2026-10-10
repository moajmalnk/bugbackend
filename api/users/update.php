<?php
header('Content-Type: application/json');

require_once 'UserController.php';

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed', 405);
    }

    $controller = new UserController();

    // Validate token
    $actor = $controller->validateToken();
    if (!$actor || !isset($actor->user_id)) {
        throw new Exception('Authentication failed', 401);
    }

    // Get request data
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || !isset($data['id'])) {
        throw new Exception('Invalid request data', 400);
    }

    // Why: Editing another account or changing any role / tester type / CODO standards access /
    // onboarding mode must be an admin action; otherwise any login could escalate its own privileges
    // or switch off its own onboarding.
    $isAdmin = strtolower((string) ($actor->role ?? '')) === 'admin';
    $touchesPrivileges = array_key_exists('role', $data)
        || array_key_exists('role_id', $data)
        || array_key_exists('tester_type', $data)
        || array_key_exists('codo_rules_mode', $data)
        || array_key_exists('cursor_tips_mode', $data)
        || array_key_exists('onboarding_mode', $data);
    $isOtherUser = (string) $data['id'] !== (string) $actor->user_id;
    if (
        !$isAdmin
        && ($touchesPrivileges || $isOtherUser)
        && !PermissionManager::getInstance()->hasPermissionOrAdmin(
            (string) $actor->user_id,
            'USERS_EDIT',
            $actor->role ?? null
        )
    ) {
        throw new Exception('USERS_EDIT permission required', 403);
    }

    // Update user
    $controller->updateUser($data['id'], $data);
} catch (Exception $e) {
    error_log("Error in update.php: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage() ?: 'An unexpected error occurred'
    ]);
}