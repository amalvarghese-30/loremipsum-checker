-- Add retry scheduling and domain tracking columns to discovered_urls
ALTER TABLE `discovered_urls`
  ADD COLUMN `domain` VARCHAR(255) NULL AFTER `url_hash`,
  ADD COLUMN `next_attempt_at` DATETIME NULL AFTER `retry_count`,
  ADD COLUMN `attempt_count` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `retry_count`;

CREATE INDEX `idx_scan_status_next_attempt` ON `discovered_urls` (`scan_id`, `status`, `next_attempt_at`);
CREATE INDEX `idx_scan_domain_status` ON `discovered_urls` (`scan_id`, `domain`, `status`);
