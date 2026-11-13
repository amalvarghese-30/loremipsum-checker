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

        // Check for existing canonical result for this URL within the scan deterministically
        $checkStmt = $this->db->prepare('SELECT * FROM page_results WHERE scan_id = ? AND discovered_url_id = ? ORDER BY id DESC LIMIT 1');
        $checkStmt->execute([$scanId, $discoveredUrlId]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing && empty($data['force_insert'])) {
            $pageResId = (int)$existing['id'];

            // Capture prior PAGE-LEVEL metrics only (resource findings are tracked independently)
            $findStmt = $this->db->prepare('
                SELECT 
                    COUNT(CASE WHEN resource_id IS NULL THEN 1 END) as total_page_findings,
                    COUNT(CASE WHEN detection_category = "lorem_ipsum" AND resource_id IS NULL THEN 1 END) as lorem_findings,
                    COALESCE(SUM(CASE WHEN detection_category = "lorem_ipsum" AND resource_id IS NULL THEN COALESCE(occurrences, 1) ELSE 0 END), 0) as lorem_occurrences,
                    COUNT(CASE WHEN detection_category != "lorem_ipsum" AND resource_id IS NULL THEN 1 END) as audit_findings
                FROM findings
                WHERE page_result_id = ?
            ');
            $findStmt->execute([$pageResId]);
            $fRow = $findStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $hasDbFindings = ($fRow['total_page_findings'] ?? 0) > 0;
            $pageFindingCount = $hasDbFindings 
                ? (int)$fRow['total_page_findings'] 
                : (int)($existing['finding_count'] ?? 0);
            $loremFindings = $hasDbFindings
                ? (int)($fRow['lorem_findings'] ?? 0)
                : (!empty($existing['has_lorem_ipsum']) ? $pageFindingCount : 0);
            $loremOccurrences = $hasDbFindings
                ? (int)($fRow['lorem_occurrences'] ?? 0)
                : (!empty($existing['has_lorem_ipsum']) ? $pageFindingCount : 0);
            $auditFindings = $hasDbFindings
                ? (int)($fRow['audit_findings'] ?? 0)
                : (empty($existing['has_lorem_ipsum']) ? $pageFindingCount : 0);

            $isFailed = in_array($existing['processing_status'], ['failed', 'cloudflare_blocked', 'bot_blocked', 'rate_limited', 'timeout', 'ssl_error', 'not_found', 'error'], true) || ((int)($existing['http_status'] ?? 0) >= 400);
            $isAffected = ($existing['processing_status'] === 'findings_detected' || $pageFindingCount > 0) && !$isFailed;
            $isClean = ($existing['processing_status'] === 'clean') && !$isFailed && ($pageFindingCount === 0);

            $oldMetrics = [
                'is_clean'          => $isClean ? 1 : 0,
                'is_affected'       => $isAffected ? 1 : 0,
                'is_failed'         => $isFailed ? 1 : 0,
                'has_lorem'         => !empty($existing['has_lorem_ipsum']) ? 1 : 0,
                'lorem_findings'    => $loremFindings,
                'lorem_occurrences' => $loremOccurrences,
                'audit_findings'    => $auditFindings,
                'total_findings'    => $pageFindingCount,
            ];

            // Remove previous PAGE-LEVEL findings only; preserve external resource findings (resource_id IS NOT NULL)
            $delFindings = $this->db->prepare('DELETE FROM findings WHERE page_result_id = ? AND resource_id IS NULL');
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
        $sql = '
            SELECT pr.* 
            FROM page_results pr
            INNER JOIN (
                SELECT MAX(id) as max_id
                FROM page_results
                WHERE scan_id = ?
                GROUP BY CASE WHEN discovered_url_id IS NOT NULL THEN discovered_url_id ELSE id END
            ) cp ON pr.id = cp.max_id
            WHERE pr.scan_id = ?
        ';
        $params = [$scanId, $scanId];

        if (!empty($filters['is_error'])) {
            $sql .= ' AND (pr.http_status >= 400 OR pr.processing_status IN ("failed", "cloudflare_blocked", "bot_blocked", "rate_limited", "timeout", "ssl_error", "dns_error", "connection_error", "not_found", "error"))';
        } elseif (!empty($filters['status'])) {
            $st = trim($filters['status']);
            if ($st === 'clean') {
                $sql .= ' AND pr.processing_status = "clean" AND (pr.http_status IS NULL OR pr.http_status < 400) AND pr.finding_count = 0';
            } elseif ($st === 'failed' || $st === 'error') {
                $sql .= ' AND (pr.http_status >= 400 OR pr.processing_status IN ("failed", "cloudflare_blocked", "bot_blocked", "rate_limited", "timeout", "ssl_error", "dns_error", "connection_error", "not_found", "error"))';
            } else {
                $sql .= ' AND pr.processing_status = ?';
                $params[] = $st;
            }
        }

        if (!empty($filters['audit_status'])) {
            $ast = trim((string)$filters['audit_status']);
            if ($ast === 'affected' || $ast === 'issues' || $ast === 'audit_issues') {
                $sql .= ' AND (pr.audit_status IN ("affected", "audit_issues", "lorem_detected") OR pr.finding_count > 0 OR pr.processing_status = "findings_detected") AND (pr.http_status IS NULL OR pr.http_status < 400)';
            } elseif ($ast === 'clean') {
                $sql .= ' AND pr.audit_status = "clean" AND pr.finding_count = 0 AND (pr.http_status IS NULL OR pr.http_status < 400) AND pr.processing_status = "clean"';
            } elseif ($ast === 'failed' || $ast === 'error') {
                $sql .= ' AND (pr.http_status >= 400 OR pr.processing_status IN ("failed", "cloudflare_blocked", "bot_blocked", "rate_limited", "timeout", "ssl_error", "dns_error", "connection_error", "not_found", "error"))';
            } else {
                $sql .= ' AND pr.audit_status = ?';
                $params[] = $ast;
            }
        }

        if (isset($filters['has_lorem_ipsum'])) {
            $sql .= ' AND pr.has_lorem_ipsum = ?';
            $params[] = $filters['has_lorem_ipsum'] ? 1 : 0;
        }

        if (isset($filters['has_findings'])) {
            if ($filters['has_findings']) {
                $sql .= ' AND (pr.finding_count > 0 OR pr.processing_status = "findings_detected") AND (pr.http_status IS NULL OR pr.http_status < 400)';
            } else {
                $sql .= ' AND pr.finding_count = 0';
            }
        }

        if (!empty($filters['category'])) {
            $cat = trim($filters['category']);
            if (in_array(strtolower($cat), ['seo', 'seo_issues'], true)) {
                $sql .= ' AND pr.id IN (SELECT page_result_id FROM findings WHERE scan_id = ? AND detection_category IN ("missing_title", "missing_meta_desc", "missing_h1", "multiple_h1", "missing_canonical", "heading_structure") AND resource_id IS NULL)';
                $params[] = $scanId;
            } elseif (in_array(strtolower($cat), ['a11y', 'accessibility', 'a11y_issues'], true)) {
                $sql .= ' AND pr.id IN (SELECT page_result_id FROM findings WHERE scan_id = ? AND detection_category IN ("missing_image_alt", "missing_lang") AND resource_id IS NULL)';
                $params[] = $scanId;
            } elseif (in_array(strtolower($cat), ['empty_page', 'missing_content'], true)) {
                $sql .= ' AND pr.id IN (SELECT page_result_id FROM findings WHERE scan_id = ? AND detection_category IN ("empty_page", "incomplete_sections") AND resource_id IS NULL)';
                $params[] = $scanId;
            } elseif (in_array(strtolower($cat), ['broken_link', 'broken_links', 'resource_issues'], true)) {
                $sql .= ' AND (pr.id IN (SELECT page_result_id FROM findings WHERE scan_id = ? AND detection_category IN ("broken_link", "failed_resource", "asset_error")) OR pr.id IN (SELECT page_result_id FROM findings WHERE scan_id = ? AND resource_id IS NOT NULL))';
                $params[] = $scanId;
                $params[] = $scanId;
            } elseif (str_contains($cat, ',')) {
                $parts = array_filter(array_map('trim', explode(',', $cat)));
                $ph = implode(',', array_fill(0, count($parts), '?'));
                $sql .= " AND pr.id IN (SELECT page_result_id FROM findings WHERE scan_id = ? AND detection_category IN ({$ph}))";
                $params[] = $scanId;
                foreach ($parts as $p) { $params[] = $p; }
            } else {
                $sql .= ' AND pr.id IN (SELECT page_result_id FROM findings WHERE scan_id = ? AND detection_category = ?)';
                $params[] = $scanId;
                $params[] = $cat;
            }
        }

        if (!empty($filters['page_url'])) {
            $sql .= ' AND (pr.requested_url = ? OR pr.final_url = ?)';
            $params[] = $filters['page_url'];
            $params[] = $filters['page_url'];
        }

        if (!empty($filters['search'])) {
            $sql .= ' AND (pr.requested_url LIKE ? OR pr.page_title LIKE ?)';
            $searchWild = '%' . $filters['search'] . '%';
            $params[] = $searchWild;
            $params[] = $searchWild;
        }

        $sortField = 'pr.id';
        if (!empty($filters['sort_by']) && in_array($filters['sort_by'], ['id', 'duration_ms', 'finding_count', 'http_status'])) {
            $sortField = 'pr.' . $filters['sort_by'];
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

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (empty($rows)) {
            return [];
        }

        // Batch query actual finding breakdown for returned pages
        $pageIds = array_column($rows, 'id');
        $idPlaceholders = implode(',', array_fill(0, count($pageIds), '?'));
        
        $catLabels = [
            'lorem_ipsum'         => 'Lorem Ipsum',
            'placeholder'         => 'Demo Content',
            'missing_title'       => 'Missing Title',
            'missing_meta_desc'   => 'Missing Meta Desc',
            'missing_h1'          => 'Missing H1',
            'multiple_h1'         => 'Multiple H1',
            'missing_canonical'   => 'Missing Canonical',
            'heading_structure'   => 'Heading Hierarchy',
            'missing_image_alt'   => 'Missing Image Alt',
            'missing_lang'        => 'Missing Lang',
            'empty_page'          => 'Empty Content',
            'incomplete_sections' => 'Empty Section',
            'broken_link'         => 'Broken Link',
            'failed_resource'     => 'Failed Resource',
            'duplicate_content'   => 'Duplicate Content',
        ];

        $sevWeights = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];

        $findStmt = $this->db->prepare("
            SELECT page_result_id, detection_category, COUNT(*) as cnt, MAX(severity) as max_sev
            FROM findings
            WHERE scan_id = ? AND page_result_id IN ({$idPlaceholders})
            GROUP BY page_result_id, detection_category
        ");
        $findParams = array_merge([$scanId], $pageIds);
        $findStmt->execute($findParams);
        $findingsData = $findStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $issuesByPage = [];
        $maxSevByPage = [];
        foreach ($findingsData as $fRow) {
            $pId = (int)$fRow['page_result_id'];
            $cKey = (string)$fRow['detection_category'];
            $cnt = (int)$fRow['cnt'];
            $sev = (string)($fRow['max_sev'] ?: 'medium');

            $baseLabel = $catLabels[$cKey] ?? ucwords(str_replace('_', ' ', $cKey));
            $unitLabel = ($cKey === 'missing_image_alt') ? ($cnt > 1 ? "{$cnt} images" : '1 image') : (string)$cnt;
            $chipText = "{$baseLabel} ({$unitLabel})";

            $issuesByPage[$pId][] = [
                'category' => $cKey,
                'count'    => $cnt,
                'label'    => $chipText,
                'severity' => $sev,
            ];

            $currWeight = $sevWeights[strtolower($maxSevByPage[$pId] ?? '')] ?? 0;
            $newWeight  = $sevWeights[strtolower($sev)] ?? 0;
            if ($newWeight >= $currWeight) {
                $maxSevByPage[$pId] = $sev;
            }
        }

        foreach ($rows as &$r) {
            $pid = (int)$r['id'];
            $r['issues'] = $issuesByPage[$pid] ?? [];
            $r['max_severity'] = $maxSevByPage[$pid] ?? 'low';
            $r['is_error'] = in_array($r['processing_status'], ['failed', 'cloudflare_blocked', 'bot_blocked', 'rate_limited', 'timeout', 'ssl_error', 'not_found', 'error'], true) || ((int)($r['http_status'] ?? 0) >= 400);
        }
        unset($r);

        return $rows;
    }

    public function countFiltered(int $scanId, array $filters = []): int
    {
        $sql = '
            SELECT COUNT(*) 
            FROM page_results pr
            INNER JOIN (
                SELECT MAX(id) as max_id
                FROM page_results
                WHERE scan_id = ?
                GROUP BY CASE WHEN discovered_url_id IS NOT NULL THEN discovered_url_id ELSE id END
            ) cp ON pr.id = cp.max_id
            WHERE pr.scan_id = ?
        ';
        $params = [$scanId, $scanId];

        if (!empty($filters['is_error'])) {
            $sql .= ' AND (pr.http_status >= 400 OR pr.processing_status IN ("failed", "cloudflare_blocked", "bot_blocked", "rate_limited", "timeout", "ssl_error", "dns_error", "connection_error", "not_found", "error"))';
        } elseif (!empty($filters['status'])) {
            $st = trim($filters['status']);
            if ($st === 'clean') {
                $sql .= ' AND pr.processing_status = "clean" AND (pr.http_status IS NULL OR pr.http_status < 400) AND pr.finding_count = 0';
            } elseif ($st === 'failed' || $st === 'error') {
                $sql .= ' AND (pr.http_status >= 400 OR pr.processing_status IN ("failed", "cloudflare_blocked", "bot_blocked", "rate_limited", "timeout", "ssl_error", "dns_error", "connection_error", "not_found", "error"))';
            } else {
                $sql .= ' AND pr.processing_status = ?';
                $params[] = $st;
            }
        }

        if (!empty($filters['audit_status'])) {
            $ast = trim((string)$filters['audit_status']);
            if ($ast === 'affected' || $ast === 'issues' || $ast === 'audit_issues') {
                $sql .= ' AND (pr.audit_status IN ("affected", "audit_issues", "lorem_detected") OR pr.finding_count > 0 OR pr.processing_status = "findings_detected") AND (pr.http_status IS NULL OR pr.http_status < 400)';
            } elseif ($ast === 'clean') {
                $sql .= ' AND pr.audit_status = "clean" AND pr.finding_count = 0 AND (pr.http_status IS NULL OR pr.http_status < 400) AND pr.processing_status = "clean"';
            } elseif ($ast === 'failed' || $ast === 'error') {
                $sql .= ' AND (pr.http_status >= 400 OR pr.processing_status IN ("failed", "cloudflare_blocked", "bot_blocked", "rate_limited", "timeout", "ssl_error", "dns_error", "connection_error", "not_found", "error"))';
            } else {
                $sql .= ' AND pr.audit_status = ?';
                $params[] = $ast;
            }
        }

        if (isset($filters['has_lorem_ipsum'])) {
            $sql .= ' AND pr.has_lorem_ipsum = ?';
            $params[] = $filters['has_lorem_ipsum'] ? 1 : 0;
        }

        if (isset($filters['has_findings'])) {
            if ($filters['has_findings']) {
                $sql .= ' AND (pr.finding_count > 0 OR pr.processing_status = "findings_detected") AND (pr.http_status IS NULL OR pr.http_status < 400)';
            } else {
                $sql .= ' AND pr.finding_count = 0';
            }
        }

        if (!empty($filters['category'])) {
            $cat = trim($filters['category']);
            if (in_array(strtolower($cat), ['seo', 'seo_issues'], true)) {
                $sql .= ' AND pr.id IN (SELECT page_result_id FROM findings WHERE scan_id = ? AND detection_category IN ("missing_title", "missing_meta_desc", "missing_h1", "multiple_h1", "missing_canonical", "heading_structure") AND resource_id IS NULL)';
                $params[] = $scanId;
            } elseif (in_array(strtolower($cat), ['a11y', 'accessibility', 'a11y_issues'], true)) {
                $sql .= ' AND pr.id IN (SELECT page_result_id FROM findings WHERE scan_id = ? AND detection_category IN ("missing_image_alt", "missing_lang") AND resource_id IS NULL)';
                $params[] = $scanId;
            } elseif (in_array(strtolower($cat), ['empty_page', 'missing_content'], true)) {
                $sql .= ' AND pr.id IN (SELECT page_result_id FROM findings WHERE scan_id = ? AND detection_category IN ("empty_page", "incomplete_sections") AND resource_id IS NULL)';
                $params[] = $scanId;
            } elseif (in_array(strtolower($cat), ['broken_link', 'broken_links', 'resource_issues'], true)) {
                $sql .= ' AND (pr.id IN (SELECT page_result_id FROM findings WHERE scan_id = ? AND detection_category IN ("broken_link", "failed_resource", "asset_error")) OR pr.id IN (SELECT page_result_id FROM findings WHERE scan_id = ? AND resource_id IS NOT NULL))';
                $params[] = $scanId;
                $params[] = $scanId;
            } elseif (str_contains($cat, ',')) {
                $parts = array_filter(array_map('trim', explode(',', $cat)));
                $ph = implode(',', array_fill(0, count($parts), '?'));
                $sql .= " AND pr.id IN (SELECT page_result_id FROM findings WHERE scan_id = ? AND detection_category IN ({$ph}))";
                $params[] = $scanId;
                foreach ($parts as $p) { $params[] = $p; }
            } else {
                $sql .= ' AND pr.id IN (SELECT page_result_id FROM findings WHERE scan_id = ? AND detection_category = ?)';
                $params[] = $scanId;
                $params[] = $cat;
            }
        }

        if (!empty($filters['page_url'])) {
            $sql .= ' AND (pr.requested_url = ? OR pr.final_url = ?)';
            $params[] = $filters['page_url'];
            $params[] = $filters['page_url'];
        }

        if (!empty($filters['search'])) {
            $sql .= ' AND (pr.requested_url LIKE ? OR pr.page_title LIKE ?)';
            $searchWild = '%' . $filters['search'] . '%';
            $params[] = $searchWild;
            $params[] = $searchWild;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
}
