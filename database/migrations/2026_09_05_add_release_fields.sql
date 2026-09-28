-- ============================================================
-- Released ID Status (2026-09-05)
-- Lifecycle: created -> done -> edited / printed -> released
-- Existing installs: run this file once against the database.
-- ============================================================
ALTER TABLE id_cards
  MODIFY `status` ENUM('created','done','edited','printed','released') NOT NULL DEFAULT 'created',
  ADD COLUMN `released_by` INT UNSIGNED NULL DEFAULT NULL AFTER `print_count`,
  ADD COLUMN `released_at` DATETIME NULL DEFAULT NULL AFTER `released_by`,
  ADD COLUMN `release_notes` TEXT NULL AFTER `released_at`,
  ADD COLUMN `student_received` TINYINT(1) NOT NULL DEFAULT 0 AFTER `release_notes`;
