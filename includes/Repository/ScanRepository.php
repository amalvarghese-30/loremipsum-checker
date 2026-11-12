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

    private ?bool $hasLoremPageCountColumn = null;

    private function checkLoremPageCountColumn(): bool
    {
        if ($this->hasLoremPageCountColumn !== null) {
            return $this->hasLoremPageCountColumn;
        }

        try {
            $isSqlite = QueryHelper::isSqlite($this->db);
            if ($isSqlite) {
                $cols = $this->db->query("PRAGMA table_info(scans)")->fetchAll();
                $found = false;
                foreach ($cols as $col) {
                    if (($col['name'] ?? '') === 'lorem_page_count') {
                        $found = true;
                        break;
                    }
                }
                $this->hasLoremPageCountColumn = $found;
            } else {
                $stmt = $this->db->query("SHOW COLUMNS FROM scans LIKE 'lorem_page_count'");
                $this->hasLoremPageCountColumn = ($stmt && $stmt->rowCount() > 0);
            }
        } catch (\Throwable $e) {
            $this->hasLoremPageCountColumn = false;
        }

        return $this->hasLoremPageCountColumn;
    }

    public function recordPageResult(
        int $scanId,
        string $status,
        int $findingCount = 0,
        int $loremCount = 0,
        int $auditCount = 0,
        int $resourceCount = 0,
        int $loremPageCount = 0,
        int $loremOccurrenceCount = 0
    ): bool {
        $isClean    = ($status === 'clean') ? 1 : 0;
        $isAffected = ($status === 'findings_detected') ? 1 : 0;
        $isFailed   = in_array($status, ['failed', 'cloudflare_blocked', 'bot_blocked', 'rate_limited', 'timeout', 'ssl_error', 'not_found', 'error'], true) ? 1 : 0;

        // Auto-derive lorem page & occurrence counts if omitted
        if ($loremPageCount === 0 && $loremCount > 0) {
            $loremPageCount = 1;
        }
        if ($loremOccurrenceCount === 0 && $loremCount > 0) {
            $loremOccurrenceCount = $loremCount;
        }

        if ($this->checkLoremPageCountColumn()) {
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
                    lorem_page_count = lorem_page_count + ?,
                    lorem_occurrence_count = lorem_occurrence_count + ?,
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
                $loremPageCount,
                $loremOccurrenceCount,
                $scanId
            ]);
        }

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

    public function recordResourceFindings(int $scanId, int $findingCount): bool
    {
        if ($findingCount <= 0) {
            return true;
        }
        $stmt = QueryHelper::prepare($this->db, '
            UPDATE scans
            SET total_findings = total_findings + ?,
                resource_finding_count = resource_finding_count + ?,
                last_heartbeat_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ');
        return $stmt->execute([$findingCount, $findingCount, $scanId]);
    }

    /**
     * Reconcile aggregate scan counters directly from page_results and findings tables.
     * Guarantees 100% data integrity between source-of-truth rows and scans summary table.
     */
    public function reconcileCounters(int $scanId): array
    {
        $hasLoremCol = $this->checkLoremPageCountColumn();

        // 1. Calculate page-level counts from page_results
        $pageStmt = $this->db->prepare('
            SELECT 
                COUNT(*) as total_pages,
                COUNT(CASE WHEN processing_status = "clean" THEN 1 END) as clean_pages,
                COUNT(CASE WHEN processing_status = "findings_detected" THEN 1 END) as affected_pages,
                COUNT(CASE WHEN processing_status IN ("failed", "cloudflare_blocked", "bot_blocked", "rate_limited", "timeout", "ssl_error", "not_found", "error") OR http_status >= 400 THEN 1 END) as failed_pages,
                COUNT(CASE WHEN has_lorem_ipsum = 1 THEN 1 END) as lorem_pages
            FROM page_results
            WHERE scan_id = ?
        ');
        $pageStmt->execute([$scanId]);
        $pageStats = $pageStmt->fetch() ?: [
            'total_pages' => 0, 'clean_pages' => 0, 'affected_pages' => 0, 'failed_pages' => 0, 'lorem_pages' => 0
        ];

        // 2. Calculate finding-level counts from findings
        $findStmt = $this->db->prepare('
            SELECT 
                COUNT(*) as total_findings,
                COUNT(CASE WHEN detection_category = "lorem_ipsum" THEN 1 END) as lorem_findings,
                COALESCE(SUM(CASE WHEN detection_category = "lorem_ipsum" THEN COALESCE(occurrences, 1) ELSE 0 END), 0) as lorem_occurrences,
                COUNT(CASE WHEN detection_category != "lorem_ipsum" AND (resource_type = "html" OR resource_type IS NULL) THEN 1 END) as audit_findings,
                COUNT(CASE WHEN resource_type IN ("css", "javascript", "json", "asset", "external_script") THEN 1 END) as resource_findings
            FROM findings
            WHERE scan_id = ?
        ');
        $findStmt->execute([$scanId]);
        $findStats = $findStmt->fetch() ?: [
            'total_findings' => 0, 'lorem_findings' => 0, 'lorem_occurrences' => 0, 'audit_findings' => 0, 'resource_findings' => 0
        ];

        $cleanCount    = (int)($pageStats['clean_pages'] ?? 0);
        $affectedCount = (int)($pageStats['affected_pages'] ?? 0);
        $failedCount   = (int)($pageStats['failed_pages'] ?? 0);
        $processedCount = (int)($pageStats['total_pages'] ?? 0);
        $loremPages    = (int)($pageStats['lorem_pages'] ?? 0);

        $totalFindings     = (int)($findStats['total_findings'] ?? 0);
        $loremFindings     = (int)($findStats['lorem_findings'] ?? 0);
        $loremOccurrences  = (int)($findStats['lorem_occurrences'] ?? 0);
        $auditFindings     = (int)($findStats['audit_findings'] ?? 0);
        $resourceFindings  = (int)($findStats['resource_findings'] ?? 0);

        if ($hasLoremCol) {
            $upd = QueryHelper::prepare($this->db, '
                UPDATE scans
                SET processed_count = ?,
                    clean_count = ?,
                    affected_page_count = ?,
                    failed_count = ?,
                    total_findings = ?,
                    lorem_finding_count = ?,
                    audit_finding_count = ?,
                    resource_finding_count = ?,
                    lorem_page_count = ?,
                    lorem_occurrence_count = ?,
                    updated_at = NOW()
                WHERE id = ?
            ');
            $upd->execute([
                $processedCount,
                $cleanCount,
                $affectedCount,
                $failedCount,
                $totalFindings,
                $loremFindings,
                $auditFindings,
                $resourceFindings,
                $loremPages,
                $loremOccurrences,
                $scanId
            ]);
        } else {
            $upd = QueryHelper::prepare($this->db, '
                UPDATE scans
                SET processed_count = ?,
                    clean_count = ?,
                    affected_page_count = ?,
                    failed_count = ?,
                    total_findings = ?,
                    lorem_finding_count = ?,
                    audit_finding_count = ?,
                    resource_finding_count = ?,
                    updated_at = NOW()
                WHERE id = ?
            ');
            $upd->execute([
                $processedCount,
                $cleanCount,
                $affectedCount,
                $failedCount,
                $totalFindings,
                $loremFindings,
                $auditFindings,
                $resourceFindings,
                $scanId
            ]);
        }

        return [
            'processed_count'        => $processedCount,
            'clean_count'            => $cleanCount,
            'affected_page_count'    => $affectedCount,
            'failed_count'           => $failedCount,
            'lorem_page_count'       => $loremPages,
            'total_findings'         => $totalFindings,
            'lorem_finding_count'    => $loremFindings,
            'lorem_occurrence_count' => $loremOccurrences,
            'audit_finding_count'    => $auditFindings,
            'resource_finding_count' => $resourceFindings,
        ];
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
