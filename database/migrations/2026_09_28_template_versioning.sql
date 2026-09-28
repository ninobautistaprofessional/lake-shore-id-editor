-- ============================================================
-- Template Versioning (2026-09-28)
-- ============================================================
-- Stops an admin template edit from silently changing every ID
-- that was already generated from it.
--
-- MODEL
--   id_templates keeps ONE ROW PER VERSION. All existing columns
--   (front_image, back_image, fields_json, photo_processing_mode)
--   stay exactly as they are, so the existing exact-copy renderer
--   and the Template Designer keep working with no changes.
--
--   version    1, 2, 3, ... within one template family
--   parent_id  self-reference to the family's v1 row. Every version
--              points at the same parent, so UNIQUE(parent_id,version)
--              makes it impossible to write two v2 rows - an existing
--              version can never be overwritten or duplicated.
--
--   id_cards.template_id       exact version row used at generation
--   id_cards.template_version  the version NUMBER pinned at generation
--
--   A reprint/re-generate reads template_id, so it always reuses the
--   version the ID was issued with, even after the department later
--   activates a newer version.
--
-- Only one version is_active per department. The "Set Active" button
-- already enforced that in application code; the generated column +
-- unique index below makes it a database guarantee as well.
--
-- Conventions follow the existing schema: InnoDB, utf8mb4, no physical
-- foreign keys (integrity enforced at application level, matching the
-- audit_logs / student_photos style). Every statement is idempotent so
-- the file can be re-imported safely.
-- ============================================================

USE lake_shore_id_system;

-- 1) Versioning columns on the template table.
ALTER TABLE id_templates
  ADD COLUMN IF NOT EXISTS version INT UNSIGNED NOT NULL DEFAULT 1
    COMMENT 'Version number within the template family (1, 2, 3, ...)',
  ADD COLUMN IF NOT EXISTS parent_id INT UNSIGNED NULL DEFAULT NULL
    COMMENT 'Self-FK: the family root (v1) row. Every version shares it.';

-- 2) The pinned version stored on every generated ID.
ALTER TABLE id_cards
  ADD COLUMN IF NOT EXISTS template_version INT UNSIGNED NULL DEFAULT NULL
    COMMENT 'Template version number pinned when the ID was generated';

-- 3) Existing templates become v1 of their own family.
--    parent_id must point at its own id: a UNIQUE index treats every
--    NULL as distinct, which would otherwise allow duplicate v1 rows.
UPDATE id_templates SET version = 1 WHERE version IS NULL OR version < 1;
UPDATE id_templates SET parent_id = id  WHERE parent_id IS NULL;

-- 4) Pin the version onto IDs that already reference a template, so a
--    reprint of a pre-migration ID still resolves the right version.
UPDATE id_cards c
  JOIN id_templates t ON t.id = c.template_id
   SET c.template_version = t.version
 WHERE c.template_version IS NULL;

-- 5) Immutability guarantee: one row per (family, version).
ALTER TABLE id_templates DROP INDEX IF EXISTS uq_tpl_family_version;
ALTER TABLE id_templates ADD UNIQUE KEY uq_tpl_family_version (parent_id, version);

-- 6) Single-active-version guarantee, one per department.
--    MariaDB/MySQL ignore indexes on VIRTUAL columns, so the marker is
--    declared PERSISTENT (stored); it is derived from is_active and can
--    never drift from it. It yields NULL for inactive rows, and NULLs do
--    not collide in a unique index - so many inactive versions are fine.
ALTER TABLE id_templates
  DROP INDEX IF EXISTS uq_tpl_active_dept,
  DROP COLUMN IF EXISTS active_dept;
ALTER TABLE id_templates
  ADD COLUMN active_dept VARCHAR(20)
    GENERATED ALWAYS AS (IF(is_active = 1, id_type, NULL)) PERSISTENT;
ALTER TABLE id_templates ADD UNIQUE KEY uq_tpl_active_dept (active_dept);

-- 7) Lookup indexes for version history and "which IDs use this version?".
ALTER TABLE id_templates DROP INDEX IF EXISTS idx_tpl_parent;
ALTER TABLE id_templates ADD KEY idx_tpl_parent (parent_id);

ALTER TABLE id_cards DROP INDEX IF EXISTS idx_card_tpl_version;
ALTER TABLE id_cards ADD KEY idx_card_tpl_version (template_id, template_version);

-- ============================================================
-- Same shape, for fresh installations (see database/migrate_templates.sql
-- for the seeded "Original LSC Design" v1 templates).
-- ============================================================
-- CREATE TABLE IF NOT EXISTS id_templates (
--   id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
--   name VARCHAR(150) NOT NULL,
--   version INT UNSIGNED NOT NULL DEFAULT 1,
--   parent_id INT UNSIGNED NULL DEFAULT NULL,
--   id_type ENUM('COLLEGE','JUNIOR_HIGH','SENIOR_HIGH') NOT NULL DEFAULT 'COLLEGE',
--   front_image VARCHAR(255) NOT NULL DEFAULT '',
--   back_image VARCHAR(255) DEFAULT '',
--   fields_json LONGTEXT NULL,
--   photo_processing_mode ENUM('ORIGINAL','TRANSPARENT') NOT NULL DEFAULT 'ORIGINAL',
--   is_active TINYINT(1) NOT NULL DEFAULT 0,
--   is_system TINYINT(1) NOT NULL DEFAULT 0,
--   active_dept VARCHAR(20) GENERATED ALWAYS AS (IF(is_active = 1, id_type, NULL)) PERSISTENT,
--   created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
--   updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
--   UNIQUE KEY uq_tpl_family_version (parent_id, version),
--   UNIQUE KEY uq_tpl_active_dept (active_dept),
--   INDEX idx_tpl_parent (parent_id)
-- );
--
-- ALTER TABLE id_cards
--   ADD COLUMN template_version INT UNSIGNED NULL DEFAULT NULL,
--   ADD KEY idx_card_tpl_version (template_id, template_version);