<?php

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;

class FirebaseMessagingService {
    /** @var PDO */
    private $conn;
    /** @var \Kreait\Firebase\Contract\Messaging */
    private $messaging;

    public function __construct(PDO $conn) {
        $this->conn = $conn;
        try {
            $this->ensureFcmTokenTable();
        } catch (Throwable $e) {
            // Table may already exist with a slightly different definition — continue sending
            error_log('FirebaseMessagingService: ensureFcmTokenTable skipped: ' . $e->getMessage());
        }
        $this->messaging = $this->buildMessagingClient();
    }

    private function buildMessagingClient() {
        require_once __DIR__ . '/../config/composer_autoload.php';

        $serviceAccount = $this->resolveServiceAccountCredentials();
        $factory = new Factory();

        if (is_string($serviceAccount)) {
            $factory = $factory->withServiceAccount($serviceAccount);
        } else {
            $factory = $factory->withServiceAccount($serviceAccount);
        }

        return $factory->createMessaging();
    }

    private function resolveServiceAccountCredentials() {
        $base64Json = $this->getEnvValue('FIREBASE_SERVICE_ACCOUNT_BASE64');
        if ($base64Json) {
            $decoded = base64_decode($base64Json, true);
            if ($decoded === false) {
                throw new RuntimeException('Invalid FIREBASE_SERVICE_ACCOUNT_BASE64 value.');
            }


            $serviceAccount = json_decode($decoded, true);
            if (!is_array($serviceAccount) || empty($serviceAccount['project_id'])) {
                throw new RuntimeException('Decoded FIREBASE_SERVICE_ACCOUNT_BASE64 is not valid service account JSON.');
            }

            return $serviceAccount;
        }

        $path = $this->getEnvValue('FIREBASE_SERVICE_ACCOUNT_PATH');
        if ($path && file_exists($path)) {
            return $path;
        }

        // Default location works on local and Hostinger without hardcoding absolute paths
        $defaultPath = __DIR__ . '/../config/firebase-service-account.json';
        if (file_exists($defaultPath)) {
            return $defaultPath;
        }

        throw new RuntimeException('Missing Firebase credentials. Set FIREBASE_SERVICE_ACCOUNT_BASE64 or FIREBASE_SERVICE_ACCOUNT_PATH, or place firebase-service-account.json in backend/config/.');
    }

    private function getEnvValue($key) {
        if (class_exists('Environment')) {
            $value = Environment::get($key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }
        $systemValue = getenv($key);
        return $systemValue === false ? null : $systemValue;
    }

    private function ensureFcmTokenTable() {
        $this->conn->exec("
            CREATE TABLE IF NOT EXISTS user_fcm_tokens (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
                token TEXT NOT NULL,
                token_hash CHAR(64) NOT NULL,
                device_type ENUM('android', 'ios', 'desktop') NOT NULL DEFAULT 'desktop',
                platform VARCHAR(120) DEFAULT NULL,
                user_agent VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_used TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_user_fcm_tokens_hash (token_hash),
                KEY idx_user_fcm_tokens_user_id (user_id),
                CONSTRAINT fk_user_fcm_tokens_user_id
                    FOREIGN KEY (user_id) REFERENCES users(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }

    public function sendToAllUsers($title, $body, array $data = []) {
        $tokenRows = $this->conn
            ->query("
                SELECT DISTINCT t.token
                FROM user_fcm_tokens t
                INNER JOIN users u ON u.id = t.user_id
                WHERE u.account_active = 1
                  AND t.token IS NOT NULL
                  AND TRIM(t.token) <> ''
            ")
            ->fetchAll(PDO::FETCH_COLUMN);

        $legacyRows = $this->conn
            ->query("
                SELECT DISTINCT fcm_token
                FROM users
                WHERE account_active = 1
                  AND fcm_token IS NOT NULL
                  AND TRIM(fcm_token) <> ''
            ")
            ->fetchAll(PDO::FETCH_COLUMN);

        $tokens = array_values(array_unique(array_filter(array_merge($tokenRows ?: [], $legacyRows ?: []))));
        return $this->sendToTokens($tokens, $title, $body, $data);
    }

    /**
     * Send push to specific users (same recipients as in-app notifications).
     *
     * @param array $userIds
     * @param string $title
     * @param string $body
     * @param array $data
     * @return array
     */
    public function sendToUsers(array $userIds, $title, $body, array $data = []) {
        $userIds = array_values(array_unique(array_filter(array_map('strval', $userIds))));
        if (empty($userIds)) {
            return [
                'success' => false,
                'message' => 'No user IDs provided',
                'sent_count' => 0,
                'failure_count' => 0,
                'invalid_tokens_removed' => 0,
            ];
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));

        $stmt = $this->conn->prepare(
            "SELECT DISTINCT t.token
             FROM user_fcm_tokens t
             INNER JOIN users u ON u.id = t.user_id
             WHERE u.account_active = 1
               AND t.user_id IN ($placeholders)
               AND t.token IS NOT NULL AND TRIM(t.token) <> ''"
        );
        $stmt->execute($userIds);
        $tokenRows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $legacyStmt = $this->conn->prepare(
            "SELECT DISTINCT fcm_token FROM users
             WHERE id IN ($placeholders)
               AND account_active = 1
               AND fcm_token IS NOT NULL AND TRIM(fcm_token) <> ''"
        );
        $legacyStmt->execute($userIds);
        $legacyRows = $legacyStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $tokens = array_values(array_unique(array_filter(array_merge($tokenRows, $legacyRows))));
        if (empty($tokens)) {
            error_log('FirebaseMessagingService::sendToUsers - No FCM tokens for user IDs: ' . json_encode($userIds));
            return [
                'success' => false,
                'message' => 'No FCM tokens found',
                'sent_count' => 0,
                'failure_count' => 0,
                'invalid_tokens_removed' => 0,
            ];
        }

        return $this->sendToTokens($tokens, $title, $body, $data);
    }

    private function sendToTokens(array $tokens, $title, $body, array $data = []) {
        $tokens = array_values(array_unique(array_filter($tokens)));
        if (empty($tokens)) {
            return [
                'success' => false,
                'message' => 'No FCM tokens found',
                'sent_count' => 0,
                'failure_count' => 0,
                'invalid_tokens_removed' => 0,
            ];
        }

        // Data-only payload so the service worker can render rich notifications
        // (image + action buttons). A `notification` block would let the browser
        // show a plain system notification and skip our SW UI.
        $messageData = [
            'title' => (string) $title,
            'body' => (string) $body,
        ];
        foreach ($data as $key => $value) {
            $messageData[(string) $key] = (string) $value;
        }
        if (empty($messageData['title'])) {
            $messageData['title'] = (string) $title;
        }
        if (empty($messageData['body'])) {
            $messageData['body'] = (string) $body;
        }

        $message = CloudMessage::new()->withData($messageData);

        // FCM multicast limit is 500 tokens per request
        $sentCount = 0;
        $failureCount = 0;
        $invalidTokens = [];

        foreach (array_chunk($tokens, 500) as $chunk) {
            $report = $this->messaging->sendMulticast($message, $chunk);
            $sentCount += $report->successes()->count();
            $failureCount += $report->failures()->count();
            $invalidTokens = array_merge(
                $invalidTokens,
                $report->invalidTokens(),
                $report->unknownTokens()
            );
        }

        $invalidTokens = array_values(array_unique($invalidTokens));
        $deletedCount = $this->deleteInvalidTokens($invalidTokens);

        error_log(sprintf(
            'FCM multicast report: sent=%d, failed=%d, invalid_removed=%d',
            $sentCount,
            $failureCount,
            $deletedCount
        ));

        return [
            'success' => $sentCount > 0,
            'sent_count' => $sentCount,
            'failure_count' => $failureCount,
            'invalid_tokens_removed' => $deletedCount,
            'invalid_tokens' => $invalidTokens,
        ];
    }

    private function deleteInvalidTokens(array $tokens) {
        if (empty($tokens)) {
            return 0;
        }

        $deleteStmt = $this->conn->prepare("DELETE FROM user_fcm_tokens WHERE token_hash = ?");
        $updateLegacyStmt = $this->conn->prepare("UPDATE users SET fcm_token = NULL WHERE fcm_token = ?");
        $deletedCount = 0;

        foreach ($tokens as $token) {
            $deleteStmt->execute([hash('sha256', $token)]);
            $deletedCount += $deleteStmt->rowCount();
            $updateLegacyStmt->execute([$token]);
        }

        return $deletedCount;
    }
}
