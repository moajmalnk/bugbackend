<?php
header('Content-Type: application/json');

require_once 'UserController.php';
require_once __DIR__ . '/../../utils/user_avatar.php';

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/**
 * Why: Admin Edit User sends multipart when a profile photo is attached.
 * Store under uploads/profile_pictures and return a safe relative path.
 *
 * @param array<string, mixed> $file
 */
function br_store_admin_profile_photo(array $file, string $userId): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new Exception('No profile photo uploaded', 400);
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new Exception('Profile photo upload failed', 400);
    }
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        throw new Exception('Use JPG, PNG, or WebP for profile photo', 400);
    }
    if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new Exception('Profile photo max size is 5MB', 400);
    }
    $uploadDir = __DIR__ . '/../../uploads/profile_pictures/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new Exception('Failed to prepare upload directory', 500);
    }
    $safeUser = preg_replace('/[^a-zA-Z0-9_-]/', '', $userId) ?: 'user';
    $filename = $safeUser . '_' . time() . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    $dest = rtrim($uploadDir, '/') . '/' . $filename;
    if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
        throw new Exception('Failed to store profile photo', 500);
    }
    return 'uploads/profile_pictures/' . $filename;
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

    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    $isMultipart = stripos($contentType, 'multipart/form-data') !== false;

    if ($isMultipart) {
        $data = $_POST;
        if (!isset($data['id']) || $data['id'] === '') {
            throw new Exception('Invalid request data', 400);
        }
        if (
            isset($_FILES['profile_photo'])
            && is_array($_FILES['profile_photo'])
            && ($_FILES['profile_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
        ) {
            $data['avatar'] = br_store_admin_profile_photo(
                $_FILES['profile_photo'],
                (string) $data['id']
            );
        }
    } else {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['id'])) {
            throw new Exception('Invalid request data', 400);
        }
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
        || array_key_exists('onboarding_mode', $data)
        || array_key_exists('is_super_admin', $data);
    $isOtherUser = (string) $data['id'] !== (string) $actor->user_id;
    $touchesAvatar = array_key_exists('avatar', $data);
    if (
        !$isAdmin
        && ($touchesPrivileges || $isOtherUser || $touchesAvatar)
        && !PermissionManager::getInstance()->hasPermissionOrAdmin(
            (string) $actor->user_id,
            'USERS_EDIT',
            $actor->role ?? null
        )
    ) {
        throw new Exception('USERS_EDIT permission required', 403);
    }

    // Non-admins may only update their own account without privilege fields (JSON path).
    if (!$isAdmin && $touchesAvatar && $isOtherUser) {
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
