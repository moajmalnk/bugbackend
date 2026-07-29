-- Bug types: admin-managed lookup + many-to-many on bugs (multi-select).

CREATE TABLE IF NOT EXISTS bug_types (
  id VARCHAR(36) NOT NULL,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(64) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bug_types_slug (slug),
  KEY idx_bug_types_active_sort (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bug_bug_types (
  bug_id VARCHAR(36) NOT NULL,
  bug_type_id VARCHAR(36) NOT NULL,
  PRIMARY KEY (bug_id, bug_type_id),
  KEY idx_bug_bug_types_type (bug_type_id),
  CONSTRAINT fk_bbt_bug FOREIGN KEY (bug_id) REFERENCES bugs(id) ON DELETE CASCADE,
  CONSTRAINT fk_bbt_type FOREIGN KEY (bug_type_id) REFERENCES bug_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO bug_types (id, name, slug, is_active, sort_order)
VALUES
  ('bt-ui-issue-000000000000000001', 'UI Issue', 'ui_issue', 1, 10),
  ('bt-functional-issue-0000000002', 'Functional Issue', 'functional_issue', 1, 20),
  ('bt-logical-issue-000000000003', 'Logical Issue', 'logical_issue', 1, 30)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  is_active = VALUES(is_active),
  sort_order = VALUES(sort_order);
