CREATE TABLE IF NOT EXISTS `page_resource_relations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scan_id` BIGINT UNSIGNED NOT NULL,
  `page_result_id` BIGINT UNSIGNED NOT NULL,
  `resource_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_page_resource` (`page_result_id`, `resource_id`),
  INDEX `idx_scan_page` (`scan_id`, `page_result_id`),
  INDEX `idx_resource` (`resource_id`),
  CONSTRAINT `fk_prr_scan` FOREIGN KEY (`scan_id`) REFERENCES `scans`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_prr_page` FOREIGN KEY (`page_result_id`) REFERENCES `page_results`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_prr_resource` FOREIGN KEY (`resource_id`) REFERENCES `scan_resources`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
