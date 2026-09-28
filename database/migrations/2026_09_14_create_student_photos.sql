-- ============================================================
-- Centralized Student Photo Library (2026-09-14)
-- ============================================================
-- Creates the student_photos table: ONE centralized photo repository
-- for all students across all departments (COLLEGE, JUNIOR_HIGH, SENIOR_HIGH).
--
-- Each photo explicitly tracks:
--   student_id       -> id_cards.id the photo belongs to
--   source           -> CREATE_ID | PHOTO_PROCESSING
--   type             -> ORIGINAL | PROCESSED | ARCHIVED | THUMBNAIL
--   parent_photo_id  -> self-reference for derived versions
--   file_path        -> storage reference (relative)
--   mime_type        -> image/png | image/jpeg | image/webp
--   file_size        -> bytes
--   width / height   -> pixel dimensions
--   background_info  -> JSON: { mode: transparent|white|custom, color: #HEX }
--   processing_status-> queued|processing|completed|needs_review|failed
--   quality_score    -> 0-100 automated quality assessment
--   is_active        -> 1 = active, 0 = archived
--   created_by       -> users.id (NULL for public portal uploads)
--
-- Security: photo ownership is ALWAYS verified server-side by
-- joining to id_cards.student_id. Never trust a client-supplied
-- student-photo association.
-- ============================================================

USE lake_shore_id_system;

CREATE TABLE IF NOT EXISTS student_photos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_id INT UNSIGNED NOT NULL COMMENT 'FK to id_cards.id — the student this photo belongs to',
  source ENUM('CREATE_ID','PHOTO_PROCESSING') NOT NULL DEFAULT 'CREATE_ID' COMMENT 'How the photo entered the system',
  type ENUM('ORIGINAL','PROCESSED','ARCHIVED','THUMBNAIL') NOT NULL DEFAULT 'ORIGINAL' COMMENT 'Photo version type',
  parent_photo_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'Self-FK: the photo this was derived from',
  file_path VARCHAR(500) NOT NULL COMMENT 'Relative storage path (e.g. students/{card_id}/photos/original/abc.png)',
  mime_type VARCHAR(32) NOT NULL DEFAULT 'image/png',
  file_size INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'File size in bytes',
  width INT UNSIGNED NOT NULL DEFAULT 0,
  height INT UNSIGNED NOT NULL DEFAULT 0,
  background_info JSON NULL COMMENT '{ mode: transparent|white|custom, color: #HEX }',
  processing_status ENUM('completed','queued','processing','needs_review','failed') NOT NULL DEFAULT 'completed',
  quality_score TINYINT UNSIGNED NULL DEFAULT NULL COMMENT '0-100 automated quality score',
  is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=active selectable, 0=archived',
  crop_data JSON NULL COMMENT '{ x, y, w, h } crop region for ID composition',
  created_by INT UNSIGNED NULL DEFAULT NULL COMMENT 'users.id of uploader (NULL for public portal)',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Indexes for common query patterns
  KEY idx_sp_student (student_id),
  KEY idx_sp_source (source),
  KEY idx_sp_type (type),
  KEY idx_sp_active (is_active),
  KEY idx_sp_parent (parent_photo_id),
  KEY idx_sp_processing (processing_status),
  KEY idx_sp_created (created_at),
  KEY idx_sp_created_by (created_by),

  -- Composite: active photos for a student (most common query)
  KEY idx_sp_student_active (student_id, is_active, created_at),

  -- Composite: find the preferred active photo for ID generation
  KEY idx_sp_preferred (student_id, source, type, is_active, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Link print history to the exact photo used (2026-09-14)
-- ============================================================
-- Records which specific photo_id was used when an ID was generated/printed.
-- Allows answering "Which exact photo was used for this ID?"

ALTER TABLE print_history
  ADD COLUMN IF NOT EXISTS photo_id INT UNSIGNED NULL DEFAULT NULL AFTER card_id,
  ADD KEY IF NOT EXISTS idx_ph_photo (photo_id);

-- ============================================================
-- id_cards: add preferred_photo_id for explicit photo selection
-- ============================================================
-- When a staff member explicitly selects a photo for an ID, this records it.
-- NULL means "use the system default (most recent active processed photo)".

ALTER TABLE id_cards
  ADD COLUMN IF NOT EXISTS preferred_photo_id INT UNSIGNED NULL DEFAULT NULL AFTER photo_path,
  ADD KEY IF NOT EXISTS idx_card_preferred_photo (preferred_photo_id);
