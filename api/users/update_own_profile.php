<?php
/**
 * Self-service profile update (username, phone, profile photo).
 * Why: Admins/testers skip onboarding — they still need Edit profile on /profile.
 */
require_once __DIR__ . '/../../config/cors.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../utils/user_avatar.php';

try {
    $api = new BaseAPI();
    $decoded = $api->validateToken();
    $userId = (string) ($decoded->user_id ?? '');

    if ($userId === '') {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $conn = $api->getConnection();
    $cols = [];
    $colRes = $conn->query('SHOW COLUMNS FROM users');
    if ($colRes) {
        while ($row = $colRes->fetch(PDO::FETCH_ASSOC)) {
            $cols[] = $row['Field'];
        }
    }

    $currentStmt = $conn->prepare('SELECT id, username, phone FROM users WHERE id = ? LIMIT 1');
    $currentStmt->execute([$userId]);
    $currentUser = $currentStmt->fetch(PDO::FETCH_ASSOC);
    if (!$currentUser) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit;
    }

    $currentUsername = trim((string) ($currentUser['username'] ?? ''));
    $currentPhone = trim((string) ($currentUser['phone'] ?? ''));

    $username = isset($_POST['username']) ? trim((string) $_POST['username']) : null;
    $phoneRaw = isset($_POST['phone']) ? trim((string) $_POST['phone']) : null;

    $fields = [];
    $params = [];

    if ($username !== null && $username !== '') {
        if (strlen($username) < 3 || strlen($username) > 50) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Username must be 3–50 characters.']);
            exit;
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Username can only contain letters, numbers, and underscores.',
            ]);
            exit;
        }
        // Why: Saving without changing username must not collide with the user's own row.
        if (strcasecmp($username, $currentUsername) !== 0) {
            $dup = $conn->prepare(
                'SELECT id FROM users WHERE username = ? AND CAST(id AS CHAR) <> CAST(? AS CHAR) LIMIT 1'
            );
            $dup->execute([$username, $userId]);
            if ($dup->fetch(PDO::FETCH_ASSOC)) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'Username is already taken.']);
                exit;
            }
            $fields[] = 'username = ?';
            $params[] = $username;
        }
    }

    if ($phoneRaw !== null) {
        $digits = preg_replace('/\D/', '', $phoneRaw) ?? '';
        if ($digits !== '' && strlen($digits) > 15) {
            $digits = substr($digits, 0, 15);
        }
        $phone = $digits === '' ? null : ('+' . ltrim($digits, '+'));
        $normalizedCurrentPhone = preg_replace('/\D/', '', $currentPhone) ?? '';
        $normalizedNewPhone = preg_replace('/\D/', '', (string) $phone) ?? '';

        if ($phone !== null && $normalizedNewPhone !== $normalizedCurrentPhone) {
            $dup = $conn->prepare(
                'SELECT id FROM users WHERE phone = ? AND CAST(id AS CHAR) <> CAST(? AS CHAR) LIMIT 1'
            );
            $dup->execute([$phone, $userId]);
            if ($dup->fetch(PDO::FETCH_ASSOC)) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'Phone number already exists for another user.']);
                exit;
            }
        }
        if (in_array('phone', $cols, true) && $normalizedNewPhone !== $normalizedCurrentPhone) {
            $fields[] = 'phone = ?';
            $params[] = $phone;
        }
    }

    $avatarPath = null;
    if (isset($_FILES['profile_photo']) && is_array($_FILES['profile_photo'])) {
        $file = $_FILES['profile_photo'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Profile photo upload failed.']);
                exit;
            }
            $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Use JPG, PNG, or WebP for profile photo.']);
                exit;
            }
            if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Profile photo max size is 5MB.']);
                exit;
            }
            $uploadDir = __DIR__ . '/../../uploads/profile_pictures/';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to prepare upload directory.']);
                exit;
            }
            $safeUser = preg_replace('/[^a-zA-Z0-9_-]/', '', $userId) ?: 'user';
            $filename = $safeUser . '_' . time() . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
            $dest = rtrim($uploadDir, '/') . '/' . $filename;
            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to store profile photo.']);
                exit;
            }
            $avatarPath = 'uploads/profile_pictures/' . $filename;
        }
    }

    if ($avatarPath !== null) {
        $writeCols = br_user_avatar_write_cols($cols);
        if (count($writeCols) === 0) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Avatar column missing — run migration 064_users_avatar.sql']);
            exit;
        }
        foreach ($writeCols as $col) {
            $fields[] = "`{$col}` = ?";
            $params[] = $avatarPath;
        }
    }

    if (empty($fields)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No changes to save.']);
        exit;
    }

    $params[] = $userId;
    $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?';
    $stmt = $conn->prepare($sql);
    if (!$stmt->execute($params)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update profile.']);
        exit;
    }

    $select = ['id', 'username', 'email', 'phone', 'role', 'role_id', 'created_at', 'updated_at'];
    if (in_array('joining_date', $cols, true)) {
        $select[] = 'joining_date';
    }
    $select = br_user_avatar_select_cols($select, $cols);
    $fetch = $conn->prepare('SELECT ' . implode(', ', $select) . ' FROM users WHERE id = ? LIMIT 1');
    $fetch->execute([$userId]);
    $user = $fetch->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit;
    }

    $user = br_user_with_resolved_avatar($user);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Profile updated successfully.',
        'data' => $user,
    ]);
} catch (Exception $e) {
    error_log('update_own_profile: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update profile.']);
}
