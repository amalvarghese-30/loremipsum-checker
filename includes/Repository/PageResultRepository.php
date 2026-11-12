<?php
/**
 * Page Result Repository
 *
 * Persists and queries individual webpage processing outcomes.
 */

namespace LoremChecker\Repository;

use LoremChecker\QueryHelper;
use PDO;

class PageResultRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Persist page processing outcome and return the record ID and any previous metrics
     * to support transactional counter idempotency across retries.
     */
    public function saveResultWithMetrics(array $data): array
    {
        $scanId          = (int)$data['scan_id'];
        $discoveredUrlId = (int)$data['discovered_url_id'];

        // Check for existing result for this URL within the scan
        $checkStmt = $this->db->prepare('SELECT * FROM page_results WHERE scan_id = ? AND discovered_url_id = ? LIMIT 1');
        $checkStmt->execute([$scanId, $discoveredUrlId]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $pageResId = (int)$existing['id'];

            // Capture prior metrics before removing old findings
            $findStmt = $this->db->prepare('
                SELECT 
                    COUNT(*) as total_findings,
                    COUNT(CASE WHEN detection_category = "lorem_ipsum" AND resource_id IS NULL THEN 1 END) as lorem_findings,
                    COALESCE(SUM(CASE WHEN detection_category = "lorem_ipsum" AND resource_id IS NULL THEN COALESCE(occurrences, 1) ELSE 0 END), 0) as lorem_occurrences,
                    COUNT(CASE WHEN detection_category != "lorem_ipsum" AND resource_id IS NULL THEN 1 END) as audit_findings
                FROM findings
                WHERE page_result_id = ?
            ');
            $findStmt->execute([$pageResId]);
            $fRow = $findStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $isFailed = in_array($existing['processing_status'], ['failed', 'cloudflare_blocked', 'bot_blocked', 'rate_limited', 'timeout', 'ssl_error', 'not_found', 'error'], true) || ((int)($existing['http_status'] ?? 0) >= 400);
            $isClean = ($existing['processing_status'] === 'clean') && !$isFailed;
            $isAffected = ($existing['processing_status'] === 'findings_detected' || (int)($existing['finding_count'] ?? 0) > 0) && !$isFailed;

            $oldMetrics = [
                'is_clean'          => $isClean ? 1 : 0,
                'is_affected'       => $isAffected ? 1 : 0,
                'is_failed'         => $isFailed ? 1 : 0,
                'has_lorem'         => !empty($existing['has_lorem_ipsum']) ? 1 : 0,
                'lorem_findings'    => (int)($fRow['lorem_findings'] ?? 0),
                'lorem_occurrences' => (int)($fRow['lorem_occurrences'] ?? 0),
                'audit_findings'    => (int)($fRow['audit_findings'] ?? 0),
                'total_findings'    => (int)($fRow['total_findings'] ?? 0),
            ];

            // Remove previous findings for this page_result before updating to prevent duplicates
            $delFindings = $this->db->prepare('DELETE FROM findings WHERE page_result_id = ?');
            $delFindings->execute([$pageResId]);

            $upd = QueryHelper::prepare($this->db, '
                UPDATE page_results SET
                    requested_url     = ?,
                    final_url         = ?,
                    http_status       = ?,
                    content_type      = ?,
                    processing_status = ?,
                    audit_status      = ?,
                    finding_count     = ?,
                    duration_ms       = ?,
                    retry_count       = ?,
                    error_type        = ?,
                    error_message     = ?,
                    page_title        = ?,
                    word_count        = ?,
                    has_lorem_ipsum   = ?,
                    content_hash      = ?,
                    rendered_with_js  = ?,
                    completed_at      = NOW()
                WHERE id = ?
            ');

            $upd->execute([
                $data['requested_url'],
                $data['final_url'] ?? null,
                $data['http_status'] ?? null,
                $data['content_type'] ?? null,
                $data['processing_status'] ?? 'clean',
                $data['audit_status'] ?? 'clean',
                (int)($data['finding_count'] ?? 0),
                $data['duration_ms'] ?? null,
                (int)($data['retry_count'] ?? 0),
                $data['error_type'] ?? null,
                $data['error_message'] ?? null,
                $data['page_title'] ?? null,
                $data['word_count'] ?? null,
                !empty($data['has_lorem_ipsum']) ? 1 : 0,
                $data['content_hash'] ?? null,
                !empty($data['rendered_with_js']) ? 1 : 0,
                $pageResId
            ]);

            return [
                'id'          => $pageResId,
                'is_new'      => false,
                'old_metrics' => $oldMetrics,
            ];
        }

        $stmt = QueryHelper::prepare($this->db, '
            INSERT INTO page_results (
                scan_id, discovered_url_id, requested_url, final_url,
                http_status, content_type, processing_status, audit_status,
                finding_count, duration_ms, retry_count, error_type, error_message,
                page_title, word_count, has_lorem_ipsum, content_hash,
                rendered_with_js, started_at, completed_at
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, NOW(), NOW()
            )
        ');

        $stmt->execute([
            $scanId,
            $discoveredUrlId,
            $data['requested_url'],
            $data['final_url'] ?? null,
            $data['http_status'] ?? null,
            $data['content_type'] ?? null,
            $data['processing_status'] ?? 'clean',
            $data['audit_status'] ?? 'clean',
            (int)($data['finding_count'] ?? 0),
            $data['duration_ms'] ?? null,
            (int)($data['retry_count'] ?? 0),
            $data['error_type'] ?? null,
            $data['error_message'] ?? null,
            $data['page_title'] ?? null,
            $data['word_count'] ?? null,
            !empty($data['has_lorem_ipsum']) ? 1 : 0,
            $data['content_hash'] ?? null,
            !empty($data['rendered_with_js']) ? 1 : 0
        ]);

        return [
            'id'          => (int)$this->db->lastInsertId(),
            'is_new'      => true,
            'old_metrics' => null,
        ];
    }

    public function saveResult(array $data): int
    {
        $res = $this->saveResultWithMetrics($data);
        return $res['id'];
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM page_results WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listPaginated(int $scanId, int $page = 1, int $pageSize = 50, array $filters = []): array
    {
        $offset = ($page - 1) * $pageSize;
        $sql = 'SELECT * FROM page_results WHERE scan_id = ?';
        $params = [$scanId];

        if (!empty($filters['status'])) {
            $sql .= ' AND processing_status = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['audit_status'])) {
            $sql .= ' AND audit_status = ?';
            $params[] = $filters['audit_status'];
        }

        if (isset($filters['has_lorem_ipsum'])) {
            $sql .= ' AND has_lorem_ipsum = ?';
            $params[] = $filters['has_lorem_ipsum'] ? 1 : 0;
        }

        if (isset($filters['has_findings'])) {
            if ($filters['has_findings']) {
                $sql .= ' AND finding_count > 0';
            } else {
                $sql .= ' AND finding_count = 0';
            }
        }

        if (!empty($filters['category'])) {
            $cat = trim($filters['category']);
            if (strtolower($cat) === 'seo') {
                $sql .= ' AND id IN (SELECT page_result_id FROM findings WHERE scan_id = ? AND detection_category IN ("missing_title", "missing_meta_desc", "missing_h1", "missing_canonical", "heading_structure"))';
                $params[] = $scanId;
            } elseif (str_contains($cat, ',')) {
                $parts = array_filter(array_map('trim', explode(',', $cat)));
                $ph = implode(',', array_fill(0, count($parts), '?'));
                $sql .= " AND id IN (SELECT page_result_id FROM findings WHERE scan_id = ? AND detection_category IN ({$ph}))";
                $params[] = $scanId;
                foreach ($parts as $p) { $params[] = $p; }
            } else {
                $sql .= ' AND id IN (SELECT page_result_id FROM findings WHERE scan_id = ? AND detection_category = ?)';
                $params[] = $scanId;
                $params[] = $cat;
            }
        }

        if (!empty($filters['search'])) {
            $sql .= ' AND (requested_url LIKE ? OR page_title LIKE ?)';
            $searchWild = '%' . $filters['search'] . '%';
            $params[] = $searchWild;
            $params[] = $searchWild;
        }

        $sortField = 'id';
        if (!empty($filters['sort_by']) && in_array($filters['sort_by'], ['id', 'duration_ms', 'finding_count', 'http_status'])) {
            $sortField = $filters['sort_by'];
        }
        $sortDir = (!empty($filters['sort_dir']) && strtolower($filters['sort_dir']) === 'asc') ? 'ASC' : 'DESC';

        $sql .= " ORDER BY {$sortField} {$sortDir} LIMIT ? OFFSET ?";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $i => $val) {
            $stmt->bindValue($i + 1, $val);
        }
        $stmt->bindValue(count($params) + 1, $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countFiltered(int $scanId, array $filters = []): int
    {
        $sql = 'SELECT COUNT(*) FROM page_results WHERE scan_id = ?';
        $params = [$scanId];

        if (!empty($filters['status'])) {
            $sql .= ' AND processing_status = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['audit_status'])) {
            $sql .= ' AND audit_status = ?';
            $params[] = $filters['audit_status'];
        }

        if (isset($filters['has_lorem_ipsum'])) {
            $sql .= ' AND has_lorem_ipsum = ?';
            $params[] = $filters['has_lorem_ipsum'] ? 1 : 0;
        }

        if (isset($filters['has_findings'])) {
            if ($filters['has_findings']) {
                $sql .= ' AND finding_count > 0';
            } else {
                $sql .= ' AND finding_count = 0';
            }
        }

        if (!empty($filters['category'])) {
            $cat = trim($filters['category']);
            if (strtolower($cat) === 'seo') {
                $sql .= ' AND id IN (SELECT page_result_id FROM findings WHERE scan_id = ? AND detection_category IN ("missing_title", "missing_meta_desc", "missing_h1", "missing_canonical", "heading_structure"))';
                $params[] = $scanId;
            } elseif (str_contains($cat, ',')) {
                $parts = array_filter(array_map('trim', explode(',', $cat)));
                $ph = implode(',', array_fill(0, count($parts), '?'));
                $sql .= " AND id IN (SELECT page_result_id FROM findings WHERE scan_id = ? AND detection_category IN ({$ph}))";
                $params[] = $scanId;
                foreach ($parts as $p) { $params[] = $p; }
            } else {
                $sql .= ' AND id IN (SELECT page_result_id FROM findings WHERE scan_id = ? AND detection_category = ?)';
                $params[] = $scanId;
                $params[] = $cat;
            }
        }

        if (!empty($filters['search'])) {
            $sql .= ' AND (requested_url LIKE ? OR page_title LIKE ?)';
            $searchWild = '%' . $filters['search'] . '%';
            $params[] = $searchWild;
            $params[] = $searchWild;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
}
