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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO user_fcm_tokens (user_id, token, token_hash, device_type, platform, user_agent, created_at, last_used)
SELECT
    u.id,
    u.fcm_token,
    SHA2(u.fcm_token, 256),
    'desktop',
    'migration',
    'legacy-users-table',
    NOW(),
    NOW()
FROM users u
WHERE u.fcm_token IS NOT NULL
  AND TRIM(u.fcm_token) <> ''
ON DUPLICATE KEY UPDATE
    user_id = VALUES(user_id),
    token = VALUES(token),
    device_type = VALUES(device_type),
    platform = VALUES(platform),
    user_agent = VALUES(user_agent),
    last_used = NOW();
