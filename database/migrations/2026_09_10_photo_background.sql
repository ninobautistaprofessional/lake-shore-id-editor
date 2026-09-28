-- Migration: Photo Background Options
-- Date: 2026-09-10
--
-- Adds background mode and color options for processed student photos:
--   background_mode: 'transparent' | 'white' | 'custom'
--   background_color: HEX color value (e.g., '#003366')
--
-- Run with:  mysql -u root lake_shore_id_system < 2026_09_10_photo_background.sql

USE lake_shore_id_system;

ALTER TABLE id_cards
  ADD COLUMN IF NOT EXISTS background_mode ENUM('transparent','white','custom') NOT NULL DEFAULT 'transparent' AFTER photo_crop,
  ADD COLUMN IF NOT EXISTS background_color VARCHAR(16) NOT NULL DEFAULT '#FFFFFF' AFTER background_mode;
