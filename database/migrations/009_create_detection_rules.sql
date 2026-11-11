CREATE TABLE IF NOT EXISTS `detection_rules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rule_name` VARCHAR(255) NOT NULL,
  `pattern` VARCHAR(1000) NOT NULL,
  `detection_category` VARCHAR(100) NOT NULL DEFAULT 'lorem_ipsum',
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `case_sensitive` TINYINT(1) NOT NULL DEFAULT 0,
  `severity` ENUM('high','medium','low') NOT NULL DEFAULT 'high',
  `confidence` ENUM('high','medium','low') NOT NULL DEFAULT 'high',
  `source_types` VARCHAR(500) NOT NULL DEFAULT 'html,javascript,css,json',
  `is_builtin` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_enabled` (`enabled`),
  INDEX `idx_category` (`detection_category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
