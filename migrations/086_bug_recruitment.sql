-- BugRecruitment: applicant CV vault + hiring pipeline (safe to re-run).

CREATE TABLE IF NOT EXISTS recruitment_applicants (
  id VARCHAR(36) NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(150) NULL,
  phone VARCHAR(15) NULL,
  whatsapp VARCHAR(15) NULL,
  department VARCHAR(100) NULL,
  role_applied VARCHAR(150) NULL,
  experience VARCHAR(255) NULL,
  education VARCHAR(255) NULL,
  status ENUM(
    'applied',
    'hr_screening',
    'staff_interview',
    'final_round',
    'offered',
    'rejected'
  ) NOT NULL DEFAULT 'applied',
  current_ctc DECIMAL(12,2) NULL,
  expected_ctc DECIMAL(12,2) NULL,
  resume_drive_link TEXT NULL,
  notes TEXT NULL,
  created_by VARCHAR(36) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_recruitment_status (status),
  KEY idx_recruitment_department (department),
  KEY idx_recruitment_role (role_applied),
  KEY idx_recruitment_created (created_at),
  KEY idx_recruitment_deleted (deleted_at),
  KEY idx_recruitment_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS recruitment_attachments (
  id VARCHAR(36) NOT NULL,
  applicant_id VARCHAR(36) NOT NULL,
  kind ENUM('resume', 'supporting') NOT NULL DEFAULT 'resume',
  file_path VARCHAR(500) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  file_type VARCHAR(100) NULL,
  file_size INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_recruitment_att_applicant (applicant_id),
  KEY idx_recruitment_att_kind (kind),
  CONSTRAINT fk_recruitment_att_applicant
    FOREIGN KEY (applicant_id) REFERENCES recruitment_applicants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'RECRUITMENT_VIEW', 'View BugRecruitment', 'Recruitment', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (
  SELECT 1 FROM `permissions` WHERE `permission_key` = 'RECRUITMENT_VIEW'
);

INSERT INTO `permissions` (`permission_key`, `permission_name`, `category`, `scope`, `created_at`)
SELECT 'RECRUITMENT_MANAGE', 'Manage BugRecruitment', 'Recruitment', 'global', NOW()
FROM DUAL WHERE NOT EXISTS (
  SELECT 1 FROM `permissions` WHERE `permission_key` = 'RECRUITMENT_MANAGE'
);

INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`)
SELECT 1, p.id, NOW()
FROM `permissions` p
WHERE p.permission_key IN ('RECRUITMENT_VIEW', 'RECRUITMENT_MANAGE')
AND NOT EXISTS (
  SELECT 1 FROM `role_permissions` rp
  WHERE rp.role_id = 1 AND rp.permission_id = p.id
);
