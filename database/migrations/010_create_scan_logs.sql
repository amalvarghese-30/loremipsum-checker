CREATE TABLE IF NOT EXISTS `scan_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scan_id` BIGINT UNSIGNED NOT NULL,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `event_type` VARCHAR(100) NOT NULL DEFAULT 'general',
  `log_level` ENUM('DEBUG','INFO','WARNING','ERROR','SUCCESS') NOT NULL DEFAULT 'INFO',
  `message` TEXT NOT NULL,
  `url` TEXT NULL,
  `discovery_source` VARCHAR(100) NULL,
  `metadata_json` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_scan_id` (`scan_id`),
  INDEX `idx_project_id` (`project_id`),
  INDEX `idx_log_level` (`log_level`),
  INDEX `idx_created_at` (`created_at`),
  CONSTRAINT `fk_sl_scan` FOREIGN KEY (`scan_id`) REFERENCES `scans`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
