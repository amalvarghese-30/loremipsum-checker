-- Migration 017: Add lorem_page_count and lorem_occurrence_count to scans
ALTER TABLE `scans` ADD COLUMN `lorem_page_count` INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE `scans` ADD COLUMN `lorem_occurrence_count` INT UNSIGNED NOT NULL DEFAULT 0;
