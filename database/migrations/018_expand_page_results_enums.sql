-- Migration 018: Align MySQL ENUM definitions with ScanScheduler runtime statuses
ALTER TABLE `page_results`
  MODIFY COLUMN `processing_status` ENUM(
    'clean',
    'findings_detected',
    'failed',
    'needs_review',
    'cloudflare_blocked',
    'bot_blocked',
    'rate_limited',
    'not_found',
    'timeout',
    'ssl_error',
    'dns_error',
    'connection_error',
    'error'
  ) NOT NULL DEFAULT 'clean',
  MODIFY COLUMN `audit_status` ENUM(
    'clean',
    'audit_issues',
    'findings_detected',
    'lorem_only',
    'failed'
  ) NOT NULL DEFAULT 'clean';
