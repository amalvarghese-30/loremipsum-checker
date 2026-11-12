-- Add phrase occurrence count column to findings
ALTER TABLE `findings`
  ADD COLUMN `occurrences` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `matched_text`;
