-- Add detailed counter breakdown and limits to scans table
ALTER TABLE `scans`
  ADD COLUMN `lorem_finding_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `total_findings`,
  ADD COLUMN `audit_finding_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `lorem_finding_count`,
  ADD COLUMN `resource_finding_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `audit_finding_count`,
  ADD COLUMN `url_limit_reached` TINYINT(1) NOT NULL DEFAULT 0 AFTER `resource_finding_count`,
  ADD COLUMN `excluded_by_limit_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `url_limit_reached`;
