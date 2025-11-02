-- Voice Notes Table
CREATE TABLE IF NOT EXISTS voice_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(20) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    duration INT NOT NULL DEFAULT 0,
    sent_by VARCHAR(36) NOT NULL,
    status ENUM('sent', 'delivered', 'read', 'failed') DEFAULT 'sent',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_phone (phone),
    INDEX idx_sent_by (sent_by),
    INDEX idx_created_at (created_at),
    INDEX idx_status (status),
    FOREIGN KEY (sent_by) REFERENCES users(id) ON DELETE CASCADE
);
-- Voice Notes History Table (for tracking delivery status)
CREATE TABLE IF NOT EXISTS voice_note_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    voice_note_id INT NOT NULL,
    status ENUM('sent', 'delivered', 'read', 'failed') NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    details JSON,
    INDEX idx_voice_note_id (voice_note_id),
    INDEX idx_status (status),
    INDEX idx_timestamp (timestamp),
    FOREIGN KEY (voice_note_id) REFERENCES voice_notes(id) ON DELETE CASCADE
);