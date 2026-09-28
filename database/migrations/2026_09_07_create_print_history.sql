-- ============================================================
-- Print History (2026-09-07)
--   Every print job on the Smart ID 51 is recorded here:
--     card_id    -> id_cards.id (the student ID record that was printed)
--     user_id    -> users.id   (staff/admin who printed it; NULL = imported)
--     print_type : 'original' (first print) or 'reprint' (any later print)
--     reason     : required for reprints (Damaged / Lost / Incorrect
--                  Information / Other); "New Student" for first prints
--   id_cards.printed_at / print_count stay in sync (legacy consumers).
-- Run once:  mysql -u root < 2026_09_07_create_print_history.sql
-- ============================================================
USE lake_shore_id_system;

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

-- Backfill: IDs already printed before this feature existed get one
-- Original history row each (idempotent — skips cards that already have one).
INSERT INTO print_history(card_id,user_id,print_type,reason,printed_at)
SELECT c.id, NULL, 'original', 'Imported from previous print tracking', c.printed_at
FROM id_cards c
WHERE c.print_count > 0 AND c.printed_at IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM print_history p WHERE p.card_id = c.id);
