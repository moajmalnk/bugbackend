-- Response status for CODO rule acknowledgements: acknowledged | doubt | not_required
ALTER TABLE `codo_rule_acknowledgements`
  ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'acknowledged'
    AFTER `user_id`;

ALTER TABLE `codo_rule_acknowledgements`
  ADD KEY `idx_codo_ack_status` (`status`);
