-- Add audit status and content hash columns to page_results
ALTER TABLE `page_results`
  ADD COLUMN `audit_status` ENUM('clean','audit_issues','findings_detected','failed') NOT NULL DEFAULT 'clean' AFTER `processing_status`,
  ADD COLUMN `content_hash` CHAR(64) NULL AFTER `has_lorem_ipsum`,
  ADD COLUMN `rendered_with_js` TINYINT(1) NOT NULL DEFAULT 0 AFTER `content_hash`;

CREATE INDEX `idx_page_audit_status` ON `page_results` (`scan_id`, `audit_status`);
CREATE INDEX `idx_page_content_hash` ON `page_results` (`scan_id`, `content_hash`);
