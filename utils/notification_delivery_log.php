<?php
/**
 * Log email / WhatsApp delivery outcomes for admin coverage dashboards.
 */

function br_ensure_notification_delivery_log(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS notification_delivery_log (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                channel ENUM('email','whatsapp') NOT NULL,
                status ENUM('sent','failed') NOT NULL,
                user_id VARCHAR(64) NULL DEFAULT NULL,
                recipient VARCHAR(255) NOT NULL,
                subject VARCHAR(255) NULL DEFAULT NULL,
                error_message VARCHAR(500) NULL DEFAULT NULL,
                meta TEXT NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_ndl_channel_status_created (channel, status, created_at),
                KEY idx_ndl_user_created (user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        error_log('br_ensure_notification_delivery_log: ' . $e->getMessage());
    }
}

/**
 * @param 'email'|'whatsapp' $channel
 * @param 'sent'|'failed' $status
 */
function br_log_notification_delivery(
    string $channel,
    string $status,
    string $recipient,
    ?string $errorMessage = null,
    ?string $subject = null,
    ?string $userId = null,
    ?array $meta = null
): void {
    try {
        require_once __DIR__ . '/../config/database.php';
        $conn = Database::getInstance()->getConnection();
        if (!$conn) {
            return;
        }
        br_ensure_notification_delivery_log($conn);

        $channel = $channel === 'whatsapp' ? 'whatsapp' : 'email';
        $status = $status === 'sent' ? 'sent' : 'failed';
        $recipient = trim($recipient);
        if ($recipient === '') {
            return;
        }

        $stmt = $conn->prepare(
            'INSERT INTO notification_delivery_log
                (channel, status, user_id, recipient, subject, error_message, meta)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $channel,
            $status,
            $userId ? (string) $userId : null,
            mb_substr($recipient, 0, 255),
            $subject !== null ? mb_substr($subject, 0, 255) : null,
            $errorMessage !== null ? mb_substr($errorMessage, 0, 500) : null,
            $meta ? br_notification_delivery_meta_json($meta) : null,
        ]);
    } catch (Throwable $e) {
        error_log('br_log_notification_delivery: ' . $e->getMessage());
    }
}

/**
 * Resolve user_id from email or phone when possible.
 */
/**
 * Why: The message body has to survive in meta so a failed WhatsApp row can be
 * resent later. TEXT holds it; a short cap used to drop the body entirely.
 */
function br_notification_delivery_meta_json(array $meta): ?string
{
    $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        return null;
    }
    if (strlen($json) > 60000 && isset($meta['body'])) {
        unset($meta['body']);
        $meta['body_truncated'] = true;
        $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return null;
        }
    }
    return strlen($json) > 60000 ? null : $json;
}

/**
 * Coverage lists need a resend flag without shipping the message body to the browser.
 */
function br_public_delivery_log_row(array $row): array
{
    $meta = json_decode((string) ($row['meta'] ?? ''), true);
    $body = is_array($meta) ? trim((string) ($meta['body'] ?? '')) : '';
    $truncated = is_array($meta) && !empty($meta['body_truncated']);
    unset($row['meta']);
    $row['can_resend'] = ($row['channel'] ?? '') === 'whatsapp'
        && ($row['status'] ?? '') === 'failed'
        && $body !== ''
        && !$truncated;
    return $row;
}

function br_lookup_user_id_by_recipient(PDO $conn, string $channel, string $recipient): ?string
{
    try {
        if ($channel === 'email') {
            $stmt = $conn->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
            $stmt->execute([trim($recipient)]);
            $id = $stmt->fetchColumn();
            return $id ? (string) $id : null;
        }

        $digits = preg_replace('/\D+/', '', $recipient);
        if ($digits === '') {
            return null;
        }
        $stmt = $conn->prepare(
            "SELECT id FROM users
             WHERE REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone,''), '+', ''), ' ', ''), '-', ''), '(', '') LIKE ?
             LIMIT 1"
        );
        $stmt->execute(['%' . $digits]);
        $id = $stmt->fetchColumn();
        return $id ? (string) $id : null;
    } catch (Throwable $e) {
        return null;
    }
}
