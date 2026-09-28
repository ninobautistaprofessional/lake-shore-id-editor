CREATE DATABASE IF NOT EXISTS lake_shore_id_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE lake_shore_id_system;

CREATE TABLE IF NOT EXISTS signatories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  position_title VARCHAR(150) DEFAULT '',
  signature_path VARCHAR(255) DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS id_cards (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_name VARCHAR(150) NOT NULL DEFAULT '',
  id_type ENUM('COLLEGE','JUNIOR_HIGH','SENIOR_HIGH') NOT NULL DEFAULT 'COLLEGE',
  course VARCHAR(255) DEFAULT '',
  grade_level VARCHAR(20) DEFAULT '',
  section_name VARCHAR(100) DEFAULT '',
  student_number VARCHAR(100) DEFAULT '',
  student_id_number VARCHAR(100) DEFAULT '',
  lrn VARCHAR(100) DEFAULT '',
  academic_year VARCHAR(50) DEFAULT '',
  school_year VARCHAR(50) DEFAULT '',
  photo_path VARCHAR(255) DEFAULT '',
  original_photo_path VARCHAR(255) DEFAULT '',
  photo_processing_status VARCHAR(32) NOT NULL DEFAULT 'original',
  photo_processed_at DATETIME NULL,
  photo_crop VARCHAR(255) DEFAULT '',
  background_mode ENUM('transparent','white','custom') NOT NULL DEFAULT 'transparent',
  background_color VARCHAR(16) NOT NULL DEFAULT '#FFFFFF',
  address_line1 VARCHAR(255) DEFAULT '',
  address_line2 VARCHAR(255) DEFAULT '',
  emergency_label VARCHAR(255) DEFAULT 'In case of emergency, please notify',
  emergency_contact VARCHAR(150) DEFAULT '',
  emergency_phone VARCHAR(80) DEFAULT '',
  terms_title VARCHAR(150) DEFAULT 'Terms and Conditions',
  term_1 TEXT,
  term_2 TEXT,
  term_3 TEXT,
  institution_name VARCHAR(150) DEFAULT 'Lake Shore Colleges',
  institution_address VARCHAR(255) DEFAULT '',
  mobile_no VARCHAR(150) DEFAULT '',
  telephone_no VARCHAR(150) DEFAULT '',
  email_address VARCHAR(150) DEFAULT '',
  signatory_id INT UNSIGNED NULL,
  template_id INT UNSIGNED NULL,
  template_version INT UNSIGNED NULL,
  status ENUM('created','done','edited','printed','released') NOT NULL DEFAULT 'created',
  printed_at DATETIME NULL,
  print_count INT UNSIGNED NOT NULL DEFAULT 0,
  released_by INT UNSIGNED NULL,
  released_at DATETIME NULL,
  release_notes TEXT NULL,
  student_received TINYINT(1) NOT NULL DEFAULT 0,
  signatory_name VARCHAR(150) DEFAULT '',
  signature_path VARCHAR(255) DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS system_settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  setting_value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(150) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  full_name VARCHAR(150) DEFAULT '',
  role ENUM('admin','staff') NOT NULL DEFAULT 'staff',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS password_resets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pr_token (token_hash)
);

INSERT INTO users (username, password, full_name, role, is_active)
SELECT 'mbautista@lakeshore.edu.ph',
       '$2y$12$Wq1wa4ZgknxLgMsDcn3Cau3evnjr6lkhZ9GTEGIrV6nrK2pN8TdwW',
       'Ma. Bautista',
       'admin',
       1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username='mbautista@lakeshore.edu.ph');

INSERT INTO system_settings (setting_key, setting_value) VALUES
('institution_name','Lake Shore Colleges'),
('institution_address','A. Bonifacio St., Brgy. Canlalay, City of Biñan, Laguna, Philippines'),
('mobile_no','Mobile No.: 0936-958-2431 / 0962-773-7461'),
('telephone_no','Telephone No.: (049) 511-4328'),
('email_address','E-mail Address: lsei@lakeshore.edu.ph')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);

INSERT INTO signatories (full_name, position_title, signature_path)
SELECT 'Annabelle V. Molina','Authorized Signatory','uploads/signatures/annabelle-v-molina.png'
WHERE NOT EXISTS (SELECT 1 FROM signatories WHERE full_name='Annabelle V. Molina');

-- Existing installations: run these if your MySQL/MariaDB version does not support ADD COLUMN IF NOT EXISTS.
-- ALTER TABLE id_cards ADD COLUMN id_type ENUM('COLLEGE','JUNIOR_HIGH','SENIOR_HIGH') NOT NULL DEFAULT 'COLLEGE';
-- ALTER TABLE id_cards ADD COLUMN course VARCHAR(255) DEFAULT '';
-- ALTER TABLE id_cards ADD COLUMN grade_level VARCHAR(20) DEFAULT '';
-- ALTER TABLE id_cards ADD COLUMN section_name VARCHAR(100) DEFAULT '';
-- ALTER TABLE id_cards ADD COLUMN student_number VARCHAR(100) DEFAULT '';
-- ALTER TABLE id_cards ADD COLUMN student_id_number VARCHAR(100) DEFAULT '';
-- ALTER TABLE id_cards ADD COLUMN lrn VARCHAR(100) DEFAULT '';
-- ALTER TABLE id_cards ADD COLUMN academic_year VARCHAR(50) DEFAULT '';
-- ALTER TABLE id_cards ADD COLUMN school_year VARCHAR(50) DEFAULT '';
-- ALTER TABLE id_cards ADD COLUMN photo_path VARCHAR(255) DEFAULT '';

-- ============================================================
-- Released ID Status (2026-09-05) — see database/migrations/2026_09_05_add_release_fields.sql
-- ============================================================
-- ALTER TABLE id_cards MODIFY status ENUM('created','done','edited','printed','released') NOT NULL DEFAULT 'created';
-- ALTER TABLE id_cards ADD COLUMN released_by INT UNSIGNED NULL AFTER print_count;
-- ALTER TABLE id_cards ADD COLUMN released_at DATETIME NULL AFTER released_by;
-- ALTER TABLE id_cards ADD COLUMN release_notes TEXT NULL AFTER released_at;
-- ALTER TABLE id_cards ADD COLUMN student_received TINYINT(1) NOT NULL DEFAULT 0 AFTER release_notes;

-- ============================================================
-- Audit Logs (2026-09-05) — see database/migrations/2026_09_05_create_audit_logs.sql
-- ============================================================
CREATE TABLE IF NOT EXISTS audit_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(64) NOT NULL,
  entity_type VARCHAR(64) NOT NULL DEFAULT '',
  entity_id INT UNSIGNED NULL,
  old_value LONGTEXT NULL,
  new_value LONGTEXT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_created_at (created_at),
  KEY idx_audit_user_id (user_id),
  KEY idx_audit_action (action),
  KEY idx_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Print History (2026-09-07) — see database/migrations/2026_09_07_create_print_history.sql
-- ============================================================
CREATE TABLE IF NOT EXISTS print_history (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  card_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  print_type ENUM('original','reprint') NOT NULL DEFAULT 'original',
  reason VARCHAR(255) DEFAULT '',
  printed_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ph_card (card_id),
  KEY idx_ph_user (user_id),
  KEY idx_ph_type (print_type),
  KEY idx_ph_printed_at (printed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ID Templates + Template Versioning (2026-09-28)
-- See database/migrate_templates.sql and
-- database/migrations/2026_09_28_template_versioning.sql
-- ============================================================
CREATE TABLE IF NOT EXISTS id_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  parent_id INT UNSIGNED NULL DEFAULT NULL,
  id_type ENUM('COLLEGE','JUNIOR_HIGH','SENIOR_HIGH') NOT NULL DEFAULT 'COLLEGE',
  front_image VARCHAR(255) NOT NULL DEFAULT '',
  back_image VARCHAR(255) DEFAULT '',
  fields_json LONGTEXT NULL,
  photo_processing_mode ENUM('ORIGINAL','TRANSPARENT') NOT NULL DEFAULT 'ORIGINAL',
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  active_dept VARCHAR(20) GENERATED ALWAYS AS (IF(is_active = 1, id_type, NULL)) PERSISTENT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tpl_family_version (parent_id, version),
  UNIQUE KEY uq_tpl_active_dept (active_dept),
  INDEX idx_tpl_parent (parent_id),
  INDEX idx_id_type (id_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The version an ID was generated with is pinned on the card, so a
-- reprint always reuses it even after a newer version is activated.
ALTER TABLE id_cards ADD KEY idx_card_tpl_version (template_id, template_version);

