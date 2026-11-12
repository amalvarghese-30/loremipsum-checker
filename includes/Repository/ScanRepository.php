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
        int $loremOccurrenceCount = 0,
        ?array $oldMetrics = null
    ): bool {
        $isFailed   = in_array($status, ['failed', 'cloudflare_blocked', 'bot_blocked', 'rate_limited', 'timeout', 'ssl_error', 'not_found', 'error'], true) ? 1 : 0;
        $isClean    = ($status === 'clean' && !$isFailed) ? 1 : 0;
        $isAffected = ($status === 'findings_detected' || $findingCount > 0) && !$isFailed ? 1 : 0;

        // Auto-derive lorem page & occurrence counts if omitted
        if ($loremPageCount === 0 && $loremCount > 0) {
            $loremPageCount = 1;
        }
        if ($loremOccurrenceCount === 0 && $loremCount > 0) {
            $loremOccurrenceCount = $loremCount;
        }

        // Calculate signed deltas for runtime idempotency
        if ($oldMetrics === null) {
            $deltaProcessed         = 1;
            $deltaClean             = $isClean;
            $deltaAffected          = $isAffected;
            $deltaFailed            = $isFailed;
            $deltaTotalFindings     = $findingCount;
            $deltaLoremFindings     = $loremCount;
            $deltaAuditFindings     = $auditCount;
            $deltaResourceFindings  = $resourceCount;
            $deltaLoremPage         = $loremPageCount;
            $deltaLoremOccurrences  = $loremOccurrenceCount;
        } else {
            $deltaProcessed         = 0; // Existing page result updated, not newly created
            $deltaClean             = $isClean - (int)($oldMetrics['is_clean'] ?? 0);
            $deltaAffected          = $isAffected - (int)($oldMetrics['is_affected'] ?? 0);
            $deltaFailed            = $isFailed - (int)($oldMetrics['is_failed'] ?? 0);
            $deltaTotalFindings     = $findingCount - (int)($oldMetrics['total_findings'] ?? 0);
            $deltaLoremFindings     = $loremCount - (int)($oldMetrics['lorem_findings'] ?? 0);
            $deltaAuditFindings     = $auditCount - (int)($oldMetrics['audit_findings'] ?? 0);
            $deltaResourceFindings  = $resourceCount;
            $deltaLoremPage         = $loremPageCount - (int)($oldMetrics['has_lorem'] ?? 0);
            $deltaLoremOccurrences  = $loremOccurrenceCount - (int)($oldMetrics['lorem_occurrences'] ?? 0);
        }

        if ($this->checkLoremPageCountColumn()) {
            $stmt = QueryHelper::prepare($this->db, '
                UPDATE scans
                SET processed_count = CASE WHEN processed_count + ? < 0 THEN 0 ELSE processed_count + ? END,
                    clean_count = CASE WHEN clean_count + ? < 0 THEN 0 ELSE clean_count + ? END,
                    affected_page_count = CASE WHEN affected_page_count + ? < 0 THEN 0 ELSE affected_page_count + ? END,
                    failed_count = CASE WHEN failed_count + ? < 0 THEN 0 ELSE failed_count + ? END,
                    total_findings = CASE WHEN total_findings + ? < 0 THEN 0 ELSE total_findings + ? END,
                    lorem_finding_count = CASE WHEN lorem_finding_count + ? < 0 THEN 0 ELSE lorem_finding_count + ? END,
                    audit_finding_count = CASE WHEN audit_finding_count + ? < 0 THEN 0 ELSE audit_finding_count + ? END,
                    resource_finding_count = CASE WHEN resource_finding_count + ? < 0 THEN 0 ELSE resource_finding_count + ? END,
                    lorem_page_count = CASE WHEN lorem_page_count + ? < 0 THEN 0 ELSE lorem_page_count + ? END,
                    lorem_occurrence_count = CASE WHEN lorem_occurrence_count + ? < 0 THEN 0 ELSE lorem_occurrence_count + ? END,
                    last_heartbeat_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ');
            return $stmt->execute([
                $deltaProcessed, $deltaProcessed,
                $deltaClean, $deltaClean,
                $deltaAffected, $deltaAffected,
                $deltaFailed, $deltaFailed,
                $deltaTotalFindings, $deltaTotalFindings,
                $deltaLoremFindings, $deltaLoremFindings,
                $deltaAuditFindings, $deltaAuditFindings,
                $deltaResourceFindings, $deltaResourceFindings,
                $deltaLoremPage, $deltaLoremPage,
                $deltaLoremOccurrences, $deltaLoremOccurrences,
                $scanId
            ]);
        }

        $stmt = QueryHelper::prepare($this->db, '
            UPDATE scans
            SET processed_count = CASE WHEN processed_count + ? < 0 THEN 0 ELSE processed_count + ? END,
                clean_count = CASE WHEN clean_count + ? < 0 THEN 0 ELSE clean_count + ? END,
                affected_page_count = CASE WHEN affected_page_count + ? < 0 THEN 0 ELSE affected_page_count + ? END,
                failed_count = CASE WHEN failed_count + ? < 0 THEN 0 ELSE failed_count + ? END,
                total_findings = CASE WHEN total_findings + ? < 0 THEN 0 ELSE total_findings + ? END,
                lorem_finding_count = CASE WHEN lorem_finding_count + ? < 0 THEN 0 ELSE lorem_finding_count + ? END,
                audit_finding_count = CASE WHEN audit_finding_count + ? < 0 THEN 0 ELSE audit_finding_count + ? END,
                resource_finding_count = CASE WHEN resource_finding_count + ? < 0 THEN 0 ELSE resource_finding_count + ? END,
                last_heartbeat_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ');
        return $stmt->execute([
            $deltaProcessed, $deltaProcessed,
            $deltaClean, $deltaClean,
            $deltaAffected, $deltaAffected,
            $deltaFailed, $deltaFailed,
            $deltaTotalFindings, $deltaTotalFindings,
            $deltaLoremFindings, $deltaLoremFindings,
            $deltaAuditFindings, $deltaAuditFindings,
            $deltaResourceFindings, $deltaResourceFindings,
            $scanId
        ]);
    }

    public function recordResourceFindings(int $scanId, int $newFindingCount, int $oldFindingCount = 0): bool
    {
        $delta = $newFindingCount - $oldFindingCount;
        if ($delta === 0) {
            return true;
        }
        $stmt = QueryHelper::prepare($this->db, '
            UPDATE scans
            SET total_findings = CASE WHEN total_findings + ? < 0 THEN 0 ELSE total_findings + ? END,
                resource_finding_count = CASE WHEN resource_finding_count + ? < 0 THEN 0 ELSE resource_finding_count + ? END,
                last_heartbeat_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ');
        return $stmt->execute([$delta, $delta, $delta, $delta, $scanId]);
    }

    /**
     * Reconcile aggregate scan counters directly from page_results and findings tables.
     * Guarantees 100% data integrity between source-of-truth rows and scans summary table.
     * Supports dry-run analysis and transaction-safe application.
     */
    public function reconcileCounters(int $scanId, bool $apply = true): array
    {
        $hasLoremCol = $this->checkLoremPageCountColumn();

        // 1. Total Discovered URLs for this scan
        $discStmt = $this->db->prepare('SELECT COUNT(*) FROM discovered_urls WHERE scan_id = ?');
        $discStmt->execute([$scanId]);
        $totalDiscovered = (int)$discStmt->fetchColumn();

        // 2. Fetch distinct latest page results by discovered_url_id to prevent duplicate counting
        $dupStmt = $this->db->prepare('
            SELECT discovered_url_id, COUNT(*) as cnt
            FROM page_results
            WHERE scan_id = ? AND discovered_url_id IS NOT NULL
            GROUP BY discovered_url_id
            HAVING cnt > 1
        ');
        $dupStmt->execute([$scanId]);
        $dupRows = $dupStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $duplicatePagesCount = count($dupRows);

        // Fetch all canonical page results with actual page-level finding counts
        $pagesStmt = $this->db->prepare('
            SELECT pr.id, pr.discovered_url_id, pr.processing_status, pr.http_status, pr.finding_count, pr.has_lorem_ipsum,
                   COALESCE(pf.page_finding_count, 0) as actual_page_finding_count,
                   COALESCE(pf.lorem_finding_count, 0) as actual_lorem_finding_count
            FROM page_results pr
            INNER JOIN (
                SELECT MAX(id) as max_id
                FROM page_results
                WHERE scan_id = ?
                GROUP BY CASE WHEN discovered_url_id IS NOT NULL THEN discovered_url_id ELSE id END
            ) latest ON pr.id = latest.max_id
            LEFT JOIN (
                SELECT page_result_id,
                       COUNT(CASE WHEN resource_id IS NULL THEN 1 END) as page_finding_count,
                       COUNT(CASE WHEN detection_category = "lorem_ipsum" AND resource_id IS NULL THEN 1 END) as lorem_finding_count
                FROM findings
                WHERE scan_id = ?
                GROUP BY page_result_id
            ) pf ON pr.id = pf.page_result_id
            WHERE pr.scan_id = ?
        ');
        $pagesStmt->execute([$scanId, $scanId, $scanId]);
        $canonicalPages = $pagesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $canonicalPagesCount = count($canonicalPages);

        $cleanCount                     = 0;
        $affectedCount                  = 0;
        $failedCount                    = 0;
        $unclassifiedCount              = 0;
        $inconsistentCleanDiscrepancies = 0;

        foreach ($canonicalPages as $p) {
            $status       = (string)($p['processing_status'] ?? '');
            $httpStatus   = (int)($p['http_status'] ?? 0);
            $actualFCount = (int)($p['actual_page_finding_count'] ?? 0);

            // 1) Terminal Fetch Failure
            $isTerminalFailure = in_array($status, [
                'failed', 'cloudflare_blocked', 'bot_blocked', 'rate_limited',
                'timeout', 'ssl_error', 'not_found', 'error'
            ], true) || ($httpStatus >= 400);

            if ($isTerminalFailure) {
                $failedCount++;
                continue;
            }

            // Flag discrepancy: marked 'clean' in status but has actual page findings
            if ($status === 'clean' && $actualFCount > 0) {
                $inconsistentCleanDiscrepancies++;
                $affectedCount++;
                continue;
            }

            // 2) Successfully fetched with page findings (affected)
            if ($actualFCount > 0 || $status === 'findings_detected') {
                $affectedCount++;
                continue;
            }

            // 3) Successfully fetched clean page with zero findings
            if ($status === 'clean' && $actualFCount === 0) {
                $cleanCount++;
                continue;
            }

            // 4) Unclassified / nonterminal status (pending, queued, processing, retry_wait, unknown)
            $unclassifiedCount++;
        }

        // Independently calculated terminal processed count
        $processedCount = $cleanCount + $affectedCount + $failedCount;

        // Strict independent invariant verification:
        // Must equal canonical pages, and there must be 0 unclassified/nonterminal pages
        $invariantPassed = ($canonicalPagesCount === ($cleanCount + $affectedCount + $failedCount)) && ($unclassifiedCount === 0);

        // 3. Authoritative findings breakdown joined to canonical page results
        $loremStmt = $this->db->prepare('
            SELECT 
                COUNT(*) as lorem_findings,
                COALESCE(SUM(COALESCE(f.occurrences, 1)), 0) as lorem_occurrences,
                COUNT(DISTINCT f.page_result_id) as lorem_pages
            FROM findings f
            INNER JOIN (
                SELECT MAX(id) as canonical_id
                FROM page_results
                WHERE scan_id = ?
                GROUP BY CASE WHEN discovered_url_id IS NOT NULL THEN discovered_url_id ELSE id END
            ) cp ON f.page_result_id = cp.canonical_id
            WHERE f.scan_id = ?
              AND f.detection_category = "lorem_ipsum"
              AND f.resource_id IS NULL
        ');
        $loremStmt->execute([$scanId, $scanId]);
        $loremData = $loremStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $loremFindings    = (int)($loremData['lorem_findings'] ?? 0);
        $loremOccurrences = (int)($loremData['lorem_occurrences'] ?? 0);
        $loremPages       = (int)($loremData['lorem_pages'] ?? 0);

        // Resource findings (linked to scan_resources via canonical resource_id)
        $resFindStmt = $this->db->prepare('SELECT COUNT(*) FROM findings WHERE scan_id = ? AND resource_id IS NOT NULL');
        $resFindStmt->execute([$scanId]);
        $resourceFindings = (int)$resFindStmt->fetchColumn();

        // Non-Lorem Page Audit findings (page-level only, canonical)
        $auditFindStmt = $this->db->prepare('
            SELECT COUNT(*)
            FROM findings f
            INNER JOIN (
                SELECT MAX(id) as canonical_id
                FROM page_results
                WHERE scan_id = ?
                GROUP BY CASE WHEN discovered_url_id IS NOT NULL THEN discovered_url_id ELSE id END
            ) cp ON f.page_result_id = cp.canonical_id
            WHERE f.scan_id = ?
              AND f.detection_category != "lorem_ipsum"
              AND f.resource_id IS NULL
        ');
        $auditFindStmt->execute([$scanId, $scanId]);
        $auditFindings = (int)$auditFindStmt->fetchColumn();

        // Total findings across canonical page findings + resource findings
        $totalFindings = $loremFindings + $auditFindings + $resourceFindings;

        // Check discrepancies between page_results.has_lorem_ipsum and actual page-level findings
        $discStmt = $this->db->prepare('
            SELECT COUNT(*) FROM page_results pr
            WHERE pr.scan_id = ?
              AND (
                  (pr.has_lorem_ipsum = 1 AND NOT EXISTS (
                      SELECT 1 FROM findings f WHERE f.page_result_id = pr.id AND f.detection_category = "lorem_ipsum" AND f.resource_id IS NULL
                  ))
                  OR
                  (pr.has_lorem_ipsum = 0 AND EXISTS (
                      SELECT 1 FROM findings f WHERE f.page_result_id = pr.id AND f.detection_category = "lorem_ipsum" AND f.resource_id IS NULL
                  ))
              )
        ');
        $discStmt->execute([$scanId]);
        $loremFlagDiscrepancyCount = (int)$discStmt->fetchColumn();

        // If apply requested, persist corrections inside a transaction
        if ($apply) {
            $inTx = $this->db->inTransaction();
            if (!$inTx) {
                $this->db->beginTransaction();
            }
            try {
                // Synchronize page_results.has_lorem_ipsum with authoritative findings
                $this->db->prepare('
                    UPDATE page_results SET has_lorem_ipsum = 1
                    WHERE scan_id = ?
                      AND id IN (
                          SELECT DISTINCT page_result_id
                          FROM findings
                          WHERE scan_id = ? AND detection_category = "lorem_ipsum" AND resource_id IS NULL
                      )
                ')->execute([$scanId, $scanId]);

                $this->db->prepare('
                    UPDATE page_results SET has_lorem_ipsum = 0
                    WHERE scan_id = ?
                      AND id NOT IN (
                          SELECT DISTINCT page_result_id
                          FROM findings
                          WHERE scan_id = ? AND detection_category = "lorem_ipsum" AND resource_id IS NULL
                      )
                ')->execute([$scanId, $scanId]);

                if ($hasLoremCol) {
                    $upd = QueryHelper::prepare($this->db, '
                        UPDATE scans
                        SET discovered_count = ?,
                            processed_count = ?,
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
                        $totalDiscovered,
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
                        SET discovered_count = ?,
                            processed_count = ?,
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
                        $totalDiscovered,
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

                if (!$inTx) {
                    $this->db->commit();
                }
            } catch (\Throwable $e) {
                if (!$inTx && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $e;
            }
        }

        return [
            'scan_id'                      => $scanId,
            'discovered_count'             => $totalDiscovered,
            'processed_count'              => $processedCount,
            'clean_count'                  => $cleanCount,
            'affected_page_count'          => $affectedCount,
            'failed_count'                 => $failedCount,
            'lorem_page_count'             => $loremPages,
            'lorem_finding_count'          => $loremFindings,
            'lorem_occurrence_count'       => $loremOccurrences,
            'audit_finding_count'          => $auditFindings,
            'resource_finding_count'       => $resourceFindings,
            'total_findings'               => $totalFindings,
            'applied'                      => $apply,
            'invariant_passed'             => $invariantPassed,
            'diagnostics'                  => [
                'canonical_pages_count'        => $canonicalPagesCount,
                'duplicate_pages_count'        => $duplicatePagesCount,
                'duplicate_rows'               => $dupRows,
                'unclassified_pages_count'     => $unclassifiedCount,
                'inconsistent_clean_count'     => $inconsistentCleanDiscrepancies,
                'lorem_flag_discrepancy_count' => $loremFlagDiscrepancyCount,
                'invariant_passed'             => $invariantPassed,
            ],
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
