<?php
/**
 * Scan Repository
 *
 * Manages audit scan executions, statuses, heartbeats, and aggregate counters.
 */

namespace LoremChecker\Repository;

use LoremChecker\QueryHelper;
use PDO;

class ScanRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function createScan(int $projectId, array $settings = []): int
    {
        // Get next scan number for this project
        $numStmt = $this->db->prepare('SELECT COALESCE(MAX(scan_number), 0) + 1 FROM scans WHERE project_id = ?');
        $numStmt->execute([$projectId]);
        $scanNumber = (int)$numStmt->fetchColumn();

        $stmt = QueryHelper::prepare($this->db, '
            INSERT INTO scans (
                project_id, scan_number, status, started_at, last_heartbeat_at,
                discovered_count, processed_count, clean_count, affected_page_count,
                failed_count, total_findings, created_at, updated_at
            ) VALUES (
                ?, ?, "queued", NOW(), NOW(),
                0, 0, 0, 0, 0, 0, NOW(), NOW()
            )
        ');
        $stmt->execute([$projectId, $scanNumber]);
        $scanId = (int)$this->db->lastInsertId();

        // Save scan settings snapshot
        if (!empty($settings)) {
            $setStmt = QueryHelper::prepare($this->db, '
                INSERT INTO scan_settings (scan_id, settings_json, created_at)
                VALUES (?, ?, NOW())
            ');
            $setStmt->execute([$scanId, json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]);
        }

        return $scanId;
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare('
            SELECT s.*, p.project_name, p.website_url, p.normalized_website_url,
                   ss.settings_json
            FROM scans s
            JOIN projects p ON s.project_id = p.id
            LEFT JOIN scan_settings ss ON s.id = ss.scan_id
            WHERE s.id = ?
        ');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row && !empty($row['settings_json'])) {
            $decoded = json_decode($row['settings_json'], true);
            $row['settings'] = is_array($decoded) ? $decoded : [];
        } else if ($row) {
            $row['settings'] = [];
        }
        return $row ?: null;
    }

    public function getSettings(int $scanId): array
    {
        $stmt = $this->db->prepare('SELECT settings_json FROM scan_settings WHERE scan_id = ? LIMIT 1');
        $stmt->execute([$scanId]);
        $json = $stmt->fetchColumn();
        if ($json) {
            $decoded = json_decode($json, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    public function getLatestByProjectId(int $projectId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM scans WHERE project_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$projectId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listByProjectId(int $projectId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM scans WHERE project_id = ? ORDER BY id DESC');
        $stmt->execute([$projectId]);
        return $stmt->fetchAll();
    }

    public function updateStatus(int $scanId, string $status): bool
    {
        $stmt = QueryHelper::prepare($this->db, 'UPDATE scans SET status = ?, updated_at = NOW() WHERE id = ?');
        return $stmt->execute([$status, $scanId]);
    }

    public function updateHeartbeat(int $scanId, ?int $pid = null): bool
    {
        if ($pid !== null) {
            $stmt = QueryHelper::prepare($this->db, 'UPDATE scans SET last_heartbeat_at = NOW(), worker_pid = ?, updated_at = NOW() WHERE id = ?');
            return $stmt->execute([$pid, $scanId]);
        }
        $stmt = QueryHelper::prepare($this->db, 'UPDATE scans SET last_heartbeat_at = NOW(), updated_at = NOW() WHERE id = ?');
        return $stmt->execute([$scanId]);
    }

    public function setDiscoveredCount(int $scanId, int $count, bool $limitReached = false, int $excludedCount = 0): bool
    {
        $stmt = QueryHelper::prepare($this->db, '
            UPDATE scans
            SET discovered_count = ?,
                url_limit_reached = ?,
                excluded_by_limit_count = ?,
                updated_at = NOW()
            WHERE id = ?
        ');
        return $stmt->execute([$count, $limitReached ? 1 : 0, $excludedCount, $scanId]);
    }

    public function recordPageResult(
        int $scanId,
        string $status,
        int $findingCount = 0,
        int $loremCount = 0,
        int $auditCount = 0,
        int $resourceCount = 0
    ): bool {
        $isClean    = ($status === 'clean') ? 1 : 0;
        $isAffected = ($status === 'findings_detected') ? 1 : 0;
        $isFailed   = in_array($status, ['failed', 'cloudflare_blocked', 'bot_blocked'], true) ? 1 : 0;

        $stmt = QueryHelper::prepare($this->db, '
            UPDATE scans
            SET processed_count = processed_count + 1,
                clean_count = clean_count + ?,
                affected_page_count = affected_page_count + ?,
                failed_count = failed_count + ?,
                total_findings = total_findings + ?,
                lorem_finding_count = lorem_finding_count + ?,
                audit_finding_count = audit_finding_count + ?,
                resource_finding_count = resource_finding_count + ?,
                last_heartbeat_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ');
        return $stmt->execute([
            $isClean,
            $isAffected,
            $isFailed,
            $findingCount,
            $loremCount,
            $auditCount,
            $resourceCount,
            $scanId
        ]);
    }

    public function completeScan(int $scanId, ?int $elapsedSeconds = null): bool
    {
        // Enforce database-level completion guard: cannot complete if any URL remains
        // in pending, queued, processing, or retry_wait states.
        if (QueryHelper::isSqlite($this->db)) {
            $stmt = $this->db->prepare('
                UPDATE scans
                SET status = "completed",
                    completed_at = datetime("now"),
                    elapsed_time = COALESCE(?, CAST((strftime("%s", "now") - strftime("%s", started_at)) AS INTEGER)),
                    last_heartbeat_at = datetime("now"),
                    updated_at = datetime("now")
                WHERE id = ?
                  AND NOT EXISTS (
                      SELECT 1 FROM discovered_urls
                      WHERE scan_id = ?
                        AND status IN (\'pending\', \'queued\', \'processing\', \'retry_wait\')
                  )
            ');
            $stmt->execute([$elapsedSeconds, $scanId, $scanId]);
        } else {
            $stmt = $this->db->prepare('
                UPDATE scans
                SET status = "completed",
                    completed_at = NOW(),
                    elapsed_time = COALESCE(?, TIMESTAMPDIFF(SECOND, started_at, NOW())),
                    last_heartbeat_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
                  AND NOT EXISTS (
                      SELECT 1 FROM discovered_urls
                      WHERE scan_id = ?
                        AND status IN (\'pending\', \'queued\', \'processing\', \'retry_wait\')
                  )
            ');
            $stmt->execute([$elapsedSeconds, $scanId, $scanId]);
        }
        return $stmt->rowCount() > 0;
    }

    public function failScan(int $scanId, string $errorMessage): bool
    {
        $stmt = QueryHelper::prepare($this->db, '
            UPDATE scans
            SET status = "failed",
                error_message = ?,
                completed_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ');
        return $stmt->execute([$errorMessage, $scanId]);
    }

    public function getStaleScans(int $thresholdSeconds = 120): array
    {
        if (QueryHelper::isSqlite($this->db)) {
            $stmt = $this->db->prepare('
                SELECT * FROM scans
                WHERE status IN ("initializing", "discovering", "scanning")
                  AND last_heartbeat_at < datetime("now", "-" || ? || " seconds")
            ');
        } else {
            $stmt = $this->db->prepare('
                SELECT * FROM scans
                WHERE status IN ("initializing", "discovering", "scanning")
                  AND last_heartbeat_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
            ');
        }
        $stmt->execute([$thresholdSeconds]);
        return $stmt->fetchAll();
    }
}
