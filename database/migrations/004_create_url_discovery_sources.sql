CREATE TABLE IF NOT EXISTS `url_discovery_sources` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `discovered_url_id` BIGINT UNSIGNED NOT NULL,
  `scan_id` BIGINT UNSIGNED NOT NULL,
  `source_type` VARCHAR(100) NOT NULL,
  `source_url` TEXT NULL,
  `crawl_depth` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `discovered_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_discovered_url_id` (`discovered_url_id`),
  INDEX `idx_scan_id` (`scan_id`),
  CONSTRAINT `fk_uds_discovered_url` FOREIGN KEY (`discovered_url_id`) REFERENCES `discovered_urls`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
