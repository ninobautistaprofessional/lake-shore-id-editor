-- Migration: Template-Aware AI Student Photo Processing
-- Date: 2026-09-08
--
-- Adds template-controlled photo processing:
--   ORIGINAL    -> the uploaded photo is kept as-is; the template photo area
--                  crops/resizes it to fit during ID generation (backward
--                  compatible = the pre-existing behaviour).
--   TRANSPARENT -> the student is segmented, the background removed and a
--                  centred transparent PNG cutout is generated client-side
--                  (mediapipe selfie segmenter) then uploaded to the server.
--
-- id_cards gains bookkeeping columns so the ORIGINAL photo is always kept
-- (reprocessable later) and the photo crop is available for re-render.
--
-- Run with:  mysql -u root lake_shore_id_system < 2026_09_08_photo_processing.sql

USE lake_shore_id_system;

ALTER TABLE id_templates
  ADD COLUMN IF NOT EXISTS photo_processing_mode ENUM('ORIGINAL','TRANSPARENT') NOT NULL DEFAULT 'ORIGINAL' AFTER fields_json;

ALTER TABLE id_cards
  ADD COLUMN IF NOT EXISTS original_photo_path VARCHAR(255) NOT NULL DEFAULT '' AFTER photo_path,
  ADD COLUMN IF NOT EXISTS photo_processing_status VARCHAR(32) NOT NULL DEFAULT 'original' AFTER original_photo_path,
  ADD COLUMN IF NOT EXISTS photo_processed_at DATETIME NULL DEFAULT NULL AFTER photo_processing_status,
  ADD COLUMN IF NOT EXISTS photo_crop VARCHAR(255) NOT NULL DEFAULT '' AFTER photo_processed_at;