<?php
/**
 * Shared Google Sign-In helpers.
 *
 * Why: the popup endpoint (google-login.php) and the same-tab redirect flow
 * (google-login-redirect.php + google-exchange.php) must verify ID tokens and
 * provision users identically, otherwise the two entry points drift apart.
 */

require_once __DIR__ . '/../../config/composer_autoload.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/environment.php';
require_once __DIR__ . '/../../config/utils.php';
require_once __DIR__ . '/../../config/fcm_config.php';
require_once __DIR__ . '/../../utils/user_avatar.php';

/** One-time login codes live this long between the Google redirect and the frontend exchange. */
const BR_GOOGLE_LOGIN_CODE_TTL_SECONDS = 60;

class GoogleLoginException extends Exception {
    /** @var string machine-readable reason, safe to put in a redirect URL */
    public $reason;
    /** @var int HTTP status for JSON callers */
    public $status;

    public function __construct(string $reason, string $message, int $status) {
        parent::__construct($message);
        $this->reason = $reason;
        $this->status = $status;
    }
}

/**
 * Verify a Google ID token and return its payload.
 *
 * @throws GoogleLoginException
 */
function br_google_verify_id_token(string $idToken): array {
    $clientId = Environment::getGoogleClientId();
    $clientSecret = Environment::getGoogleClientSecret();
    if (empty($clientId) || empty($clientSecret)) {
        throw new GoogleLoginException(
            'config',
            'Google OAuth configuration not properly set. Please check environment variables.',
            500
        );
    }

    $googleClient = new Google\Client();
    $googleClient->setClientId($clientId);
    $googleClient->setClientSecret($clientSecret);

    try {
        $payload = $googleClient->verifyIdToken($idToken);
    } catch (Throwable $e) {
        // Malformed or expired JWTs throw instead of returning false.
        throw new GoogleLoginException('invalid_token', 'Invalid ID token', 401);
    }
    if (!$payload) {
        throw new GoogleLoginException('invalid_token', 'Invalid ID token', 401);
    }
    if (empty($payload['email_verified'])) {
        throw new GoogleLoginException('email_unverified', 'Email not verified by Google', 401);
    }
    return $payload;
}

/**
 * Resolve the BugRicer user for a verified Google payload and check it may log in.
 *
 * @throws GoogleLoginException
 */
function br_google_resolve_user(PDO $conn, array $payload): array {
    $user = br_google_find_or_create_user(
        $conn,
        $payload['sub'],
        $payload['email'],
        $payload['name'] ?? '',
        $payload['picture'] ?? ''
    );
    if (!$user) {
        throw new GoogleLoginException('provision_failed', 'Failed to create or retrieve user', 500);
    }
    if (!Utils::userRowIsAllowedLogin($user)) {
        throw new GoogleLoginException('account_revoked', 'This account is no longer active', 403);
    }
    return $user;
}

/**
 * Build the success payload shared by every Google login entry point.
 * Updates last_login_at and issues a fresh JWT.
 */
function br_google_build_auth_response(PDO $conn, array $user): array {
    br_google_update_last_login($conn, $user['id']);
    $token = Utils::generateJWT($user['id'], $user['username'], $user['role']);

    unset($user['password']);
    $user = br_user_with_resolved_avatar($user);

    return [
        'success' => true,
        'message' => 'Authentication successful',
        'token' => $token,
        'user' => FcmConfig::appendEpochToPayload($user),
        'fcm_token_epoch' => FcmConfig::getTokenEpoch(),
    ];
}

function br_google_find_or_create_user(PDO $conn, $googleSub, $email, $name, $picture) {
    try {
        $stmt = $conn->prepare("SELECT * FROM users WHERE google_sub = ? LIMIT 1");
        $stmt->execute([$googleSub]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            if ($user['profile_picture_url'] !== $picture) {
                $updateStmt = $conn->prepare(
                    "UPDATE users SET profile_picture_url = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?"
                );
                $updateStmt->execute([$picture, $user['id']]);
                $user['profile_picture_url'] = $picture;
            }
            return $user;
        }

        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $updateStmt = $conn->prepare(
                "UPDATE users SET google_sub = ?, profile_picture_url = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?"
            );
            $updateStmt->execute([$googleSub, $picture, $user['id']]);
            $user['google_sub'] = $googleSub;
            $user['profile_picture_url'] = $picture;
            return $user;
        }

        $userId = Utils::generateUUID();
        $username = br_google_generate_username($conn, $name, $email);

        $stmt = $conn->prepare(
            "INSERT INTO users (id, username, email, password, role, google_sub, profile_picture_url, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );

        // Google users never use this password; it only satisfies the NOT NULL column.
        $randomPassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $defaultRole = 'tester';

        $stmt->execute([$userId, $username, $email, $randomPassword, $defaultRole, $googleSub, $picture]);

        try {
            require_once __DIR__ . '/../NotificationManager.php';
            NotificationManager::getInstance()->notifyUserRegistered($userId, $username, $userId);
        } catch (Throwable $e) {
            error_log("Failed to send Google signup notification: " . $e->getMessage());
        }

        return [
            'id' => $userId,
            'username' => $username,
            'email' => $email,
            'role' => $defaultRole,
            'google_sub' => $googleSub,
            'profile_picture_url' => $picture,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    } catch (Exception $e) {
        error_log("Error finding or creating user: " . $e->getMessage());
        return null;
    }
}

function br_google_generate_username(PDO $conn, $name, $email) {
    $isFree = function (string $candidate) use ($conn): bool {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $stmt->execute([$candidate]);
        return (int) $stmt->fetchColumn() === 0;
    };

    if (!empty($name)) {
        $base = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $name));
        if (strlen($base) >= 3 && $isFree($base)) {
            return $base;
        }
    }

    $emailPrefix = preg_replace('/[^a-zA-Z0-9]/', '', strtolower(explode('@', $email)[0]));
    if (strlen($emailPrefix) >= 3 && $isFree($emailPrefix)) {
        return $emailPrefix;
    }

    $timestamp = substr((string) time(), -6);
    $username = 'user' . $timestamp;
    $counter = 1;
    while (!$isFree($username)) {
        $username = 'user' . $timestamp . $counter;
        $counter++;
    }
    return $username;
}

function br_google_update_last_login(PDO $conn, $userId) {
    try {
        $stmt = $conn->prepare(
            "UPDATE users SET last_login_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?"
        );
        $stmt->execute([$userId]);
    } catch (Exception $e) {
        error_log("Error updating last login: " . $e->getMessage());
    }
}

/**
 * Mirrors migration 117 so the redirect flow works even before the migration is applied.
 */
function br_google_ensure_login_codes_table(PDO $conn): void {
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS `google_login_codes` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `code_hash` CHAR(64) NOT NULL,
            `user_id` VARCHAR(36) NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `used_at` DATETIME NULL DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_google_login_codes_hash` (`code_hash`),
            KEY `idx_google_login_codes_expires` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/**
 * Issue a single-use code that the frontend trades for a JWT.
 *
 * Why: the JWT must never travel in a redirect URL (history, logs, Referer);
 * only the SHA-256 hash of the short-lived code is stored.
 */
function br_google_issue_login_code(PDO $conn, string $userId): string {
    br_google_ensure_login_codes_table($conn);

    $conn->prepare("DELETE FROM google_login_codes WHERE expires_at < (NOW() - INTERVAL 1 DAY)")->execute();

    $code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $stmt = $conn->prepare(
        "INSERT INTO google_login_codes (code_hash, user_id, expires_at)
         VALUES (?, ?, NOW() + INTERVAL " . (int) BR_GOOGLE_LOGIN_CODE_TTL_SECONDS . " SECOND)"
    );
    $stmt->execute([hash('sha256', $code), $userId]);
    return $code;
}

/**
 * Atomically consume a login code. Returns the user ID, or null when the code
 * is unknown, expired or already used (a replay never yields a second JWT).
 */
function br_google_consume_login_code(PDO $conn, string $code): ?string {
    br_google_ensure_login_codes_table($conn);
    $hash = hash('sha256', $code);

    $update = $conn->prepare(
        "UPDATE google_login_codes SET used_at = NOW()
         WHERE code_hash = ? AND used_at IS NULL AND expires_at > NOW()"
    );
    $update->execute([$hash]);
    if ($update->rowCount() !== 1) {
        return null;
    }

    $select = $conn->prepare("SELECT user_id FROM google_login_codes WHERE code_hash = ? LIMIT 1");
    $select->execute([$hash]);
    $userId = $select->fetchColumn();
    return $userId !== false ? (string) $userId : null;
}
