CREATE TABLE IF NOT EXISTS `projects` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_name` VARCHAR(255) NOT NULL,
  `website_url` TEXT NOT NULL,
  `normalized_website_url` VARCHAR(2048) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_normalized_url` (`normalized_website_url`(255)),
  INDEX `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
