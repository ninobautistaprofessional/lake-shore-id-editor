-- ============================================================
-- Audit Logs — high-priority audit trail for the ID lifecycle
-- Created -> Done -> Edited -> Printed (+ admin/security events)
-- Conventions follow the existing schema: InnoDB, utf8mb4,
-- INT UNSIGNED PKs, TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
-- no foreign keys (integrity enforced at application level,
-- matching password_resets / id_cards style).
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
