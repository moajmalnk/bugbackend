CREATE TABLE IF NOT EXISTS starred_messages (
    id VARCHAR(36) NOT NULL PRIMARY KEY,
    message_id VARCHAR(36) NOT NULL,
    user_id VARCHAR(36) NOT NULL,
    group_id VARCHAR(36) NULL,
    starred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_message_starred (message_id, user_id),
    KEY idx_starred_messages_user (user_id),
    KEY idx_starred_messages_message (message_id),
    KEY idx_starred_messages_group (group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE starred_messages
ADD COLUMN IF NOT EXISTS group_id VARCHAR(36) NULL AFTER user_id;

ALTER TABLE starred_messages
ADD COLUMN IF NOT EXISTS starred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER group_id;

UPDATE starred_messages sm
JOIN chat_messages cm ON cm.id = sm.message_id
SET sm.group_id = cm.group_id
WHERE sm.group_id IS NULL OR sm.group_id = '';
