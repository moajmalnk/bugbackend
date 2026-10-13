<?php
/**
 * Why: Deactivation already signs the person out. The same moment must drop
 * live delivery channels, or mail, WhatsApp, push, Google, and an installed
 * PWA can keep reaching an account that can no longer sign in.
 */

function br_inactive_table_column_exists(PDO $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = $conn->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        return $cache[$key] = (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function br_inactive_table_exists(PDO $conn, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    try {
        $stmt = $conn->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return $cache[$table] = (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

/**
 * Drop push, the installed PWA subscription, Google, and every saved session.
 * Mail and WhatsApp stay blocked by the recipient checks while account_active is 0.
 */
function br_revoke_channels_for_inactive_user(PDO $conn, string $userId): void
{
    if ($userId === '') {
        return;
    }

    try {
        if (br_inactive_table_column_exists($conn, 'users', 'push_notifications_enabled')) {
            $conn->prepare('UPDATE users SET push_notifications_enabled = 0 WHERE id = ?')->execute([$userId]);
        }
        if (br_inactive_table_column_exists($conn, 'users', 'fcm_token')) {
            $conn->prepare('UPDATE users SET fcm_token = NULL WHERE id = ?')->execute([$userId]);
        }

        if (br_inactive_table_exists($conn, 'user_fcm_tokens')) {
            $sets = [];
            if (br_inactive_table_column_exists($conn, 'user_fcm_tokens', 'is_active')) {
                $sets[] = 'is_active = 0';
            }
            if (br_inactive_table_column_exists($conn, 'user_fcm_tokens', 'pwa_installed')) {
                $sets[] = 'pwa_installed = 0';
            }
            if ($sets !== []) {
                $conn->prepare(
                    'UPDATE user_fcm_tokens SET ' . implode(', ', $sets) . ' WHERE user_id = ?'
                )->execute([$userId]);
            } else {
                $conn->prepare('DELETE FROM user_fcm_tokens WHERE user_id = ?')->execute([$userId]);
            }
        }

        // Same epoch bump as logout-all-devices, so an installed PWA drops its saved JWT.
        if (br_inactive_table_column_exists($conn, 'users', 'auth_token_epoch')) {
            $conn->prepare(
                'UPDATE users SET auth_token_epoch = auth_token_epoch + 1 WHERE id = ?'
            )->execute([$userId]);
        }

        if (br_inactive_table_column_exists($conn, 'users', 'google_connect_required')) {
            $conn->prepare('UPDATE users SET google_connect_required = 0 WHERE id = ?')->execute([$userId]);
        }

        $removedTokens = br_disconnect_google_for_inactive_user($conn, $userId);
        error_log(json_encode([
            'event' => 'user.deactivate.channels',
            'user_id' => $userId,
            'google_tokens_removed' => $removedTokens,
        ]));
    } catch (Throwable $e) {
        error_log('br_revoke_channels_for_inactive_user: ' . $e->getMessage());
    }
}

/**
 * Why: Turning the account back on should allow push again. Google and the old
 * PWA subscription stay revoked until the person signs in and connects them.
 */
function br_restore_push_after_reactivation(PDO $conn, string $userId): void
{
    if ($userId === '' || !br_inactive_table_column_exists($conn, 'users', 'push_notifications_enabled')) {
        return;
    }
    try {
        $conn->prepare('UPDATE users SET push_notifications_enabled = 1 WHERE id = ?')->execute([$userId]);
    } catch (Throwable $e) {
        error_log('br_restore_push_after_reactivation: ' . $e->getMessage());
    }
}

function br_disconnect_google_for_inactive_user(PDO $conn, string $userId): int
{
    if (!br_inactive_table_exists($conn, 'google_tokens')) {
        return 0;
    }
    try {
        require_once __DIR__ . '/../api/oauth/GoogleAuthService.php';
        $authService = new GoogleAuthService();
        $client = $authService->getClientForUser($userId);
        $client->revokeToken();
    } catch (Throwable $e) {
        error_log('inactive google revoke: ' . $e->getMessage());
    }
    $stmt = $conn->prepare('DELETE FROM google_tokens WHERE bugricer_user_id = ?');
    $stmt->execute([$userId]);
    return $stmt->rowCount();
}

function br_email_blocked_for_inactive_account(string $email): bool
{
    $email = trim($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    try {
        require_once __DIR__ . '/../config/database.php';
        $conn = Database::getInstance()->getConnection();
        if (!$conn || !br_inactive_table_column_exists($conn, 'users', 'account_active')) {
            return false;
        }
        $stmt = $conn->prepare(
            'SELECT account_active FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1'
        );
        $stmt->execute([$email]);
        $active = $stmt->fetchColumn();
        return $active !== false && (int) $active !== 1;
    } catch (Throwable $e) {
        error_log('br_email_blocked_for_inactive_account: ' . $e->getMessage());
        return false;
    }
}

function br_phone_blocked_for_inactive_account(string $phone): bool
{
    $digits = preg_replace('/\D/', '', $phone);
    if ($digits === null || $digits === '') {
        return false;
    }
    $candidates = [$digits];
    if (strlen($digits) === 12 && strncmp($digits, '91', 2) === 0) {
        $candidates[] = substr($digits, 2);
    } elseif (strlen($digits) === 10) {
        $candidates[] = '91' . $digits;
    }
    $candidates = array_values(array_unique($candidates));

    try {
        require_once __DIR__ . '/../config/database.php';
        $conn = Database::getInstance()->getConnection();
        if (!$conn || !br_inactive_table_column_exists($conn, 'users', 'account_active')) {
            return false;
        }
        $placeholders = implode(',', array_fill(0, count($candidates), '?'));
        $normalized = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone,''), '+', ''), ' ', ''), '-', ''), '(', ''), ')', '')";
        $stmt = $conn->prepare(
            "SELECT account_active FROM users WHERE {$normalized} IN ($placeholders) LIMIT 1"
        );
        $stmt->execute($candidates);
        $active = $stmt->fetchColumn();
        return $active !== false && (int) $active !== 1;
    } catch (Throwable $e) {
        error_log('br_phone_blocked_for_inactive_account: ' . $e->getMessage());
        return false;
    }
}
