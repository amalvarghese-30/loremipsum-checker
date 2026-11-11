<?php
/**
 * Scan Repository
 *
 * Manages audit scan executions, statuses, heartbeats, and aggregate counters.
 */

namespace LoremChecker\Repository;

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

        $stmt = $this->db->prepare('
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
            $setStmt = $this->db->prepare('
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
            SELECT s.*, p.project_name, p.website_url, p.normalized_website_url
            FROM scans s
            JOIN projects p ON s.project_id = p.id
            WHERE s.id = ?
        ');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
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
        $stmt = $this->db->prepare('UPDATE scans SET status = ?, updated_at = NOW() WHERE id = ?');
        return $stmt->execute([$status, $scanId]);
    }

    public function updateHeartbeat(int $scanId, ?int $pid = null): bool
    {
        if ($pid !== null) {
            $stmt = $this->db->prepare('UPDATE scans SET last_heartbeat_at = NOW(), worker_pid = ?, updated_at = NOW() WHERE id = ?');
            return $stmt->execute([$pid, $scanId]);
        }
        $stmt = $this->db->prepare('UPDATE scans SET last_heartbeat_at = NOW(), updated_at = NOW() WHERE id = ?');
        return $stmt->execute([$scanId]);
    }

    public function setDiscoveredCount(int $scanId, int $count): bool
    {
        $stmt = $this->db->prepare('UPDATE scans SET discovered_count = ?, updated_at = NOW() WHERE id = ?');
        return $stmt->execute([$count, $scanId]);
    }

    public function recordPageResult(int $scanId, string $status, int $findingCount = 0): bool
    {
        $isClean    = $status === 'clean' ? 1 : 0;
        $isAffected = $status === 'findings_detected' ? 1 : 0;
        $isFailed   = in_array($status, ['failed', 'cloudflare_blocked', 'bot_blocked']) ? 1 : 0;

        $stmt = $this->db->prepare('
            UPDATE scans
            SET processed_count = processed_count + 1,
                clean_count = clean_count + ?,
                affected_page_count = affected_page_count + ?,
                failed_count = failed_count + ?,
                total_findings = total_findings + ?,
                last_heartbeat_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ');
        return $stmt->execute([$isClean, $isAffected, $isFailed, $findingCount, $scanId]);
    }

    public function completeScan(int $scanId, ?int $elapsedSeconds = null): bool
    {
        $stmt = $this->db->prepare('
            UPDATE scans
            SET status = "completed",
                completed_at = NOW(),
                elapsed_time = COALESCE(?, TIMESTAMPDIFF(SECOND, started_at, NOW())),
                last_heartbeat_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ');
        return $stmt->execute([$elapsedSeconds, $scanId]);
    }

    public function failScan(int $scanId, string $errorMessage): bool
    {
        $stmt = $this->db->prepare('
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
        $stmt = $this->db->prepare('
            SELECT * FROM scans
            WHERE status IN ("initializing", "discovering", "scanning")
              AND last_heartbeat_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
        ');
        $stmt->execute([$thresholdSeconds]);
        return $stmt->fetchAll();
    }
}
