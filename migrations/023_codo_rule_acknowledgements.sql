-- Per-user acknowledgements for Common CODO rules (developers & testers).
CREATE TABLE IF NOT EXISTS `codo_rule_acknowledgements` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rule_id` INT UNSIGNED NOT NULL,
  `user_id` VARCHAR(36) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'acknowledged',
  `acknowledged_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_codo_ack_rule_user` (`rule_id`, `user_id`),
  KEY `idx_codo_ack_user` (`user_id`),
  KEY `idx_codo_ack_rule` (`rule_id`),
  KEY `idx_codo_ack_status` (`status`),
  CONSTRAINT `fk_codo_ack_rule`
    FOREIGN KEY (`rule_id`) REFERENCES `codo_common_rules` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
