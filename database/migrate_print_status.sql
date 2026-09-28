-- ============================================================
-- ID status lifecycle + Smart ID 51 dual-side print tracking
--   status      : created -> done -> edited / printed
--   printed_at  : timestamp of the last print job
--   print_count : how many times the card was sent to the printer
-- Run once:  mysql -u root < migrate_print_status.sql
-- ============================================================
USE lake_shore_id_system;

ALTER TABLE id_cards
  ADD COLUMN IF NOT EXISTS status ENUM('created','done','edited','printed') NOT NULL DEFAULT 'created' AFTER template_id,
  ADD COLUMN IF NOT EXISTS printed_at DATETIME DEFAULT NULL AFTER status,
  ADD COLUMN IF NOT EXISTS print_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER printed_at;
