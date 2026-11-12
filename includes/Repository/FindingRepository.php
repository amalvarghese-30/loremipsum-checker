<?php
/**
 * Finding Repository
 *
 * Persists and queries individual placeholder/Lorem Ipsum matches.
 * Implements cross-scan comparison using finding fingerprints.
 */

namespace LoremChecker\Repository;

use LoremChecker\QueryHelper;
use PDO;

class FindingRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    private ?bool $hasOccurrencesColumn = null;

    private function checkOccurrencesColumn(): bool
    {
        if ($this->hasOccurrencesColumn !== null) {
            return $this->hasOccurrencesColumn;
        }

        try {
            $isSqlite = QueryHelper::isSqlite($this->db);
            if ($isSqlite) {
                $cols = $this->db->query("PRAGMA table_info(findings)")->fetchAll();
                $found = false;
                foreach ($cols as $col) {
                    if (($col['name'] ?? '') === 'occurrences') {
                        $found = true;
                        break;
                    }
                }
                $this->hasOccurrencesColumn = $found;
            } else {
                $stmt = $this->db->query("SHOW COLUMNS FROM findings LIKE 'occurrences'");
                $this->hasOccurrencesColumn = ($stmt && $stmt->rowCount() > 0);
            }
        } catch (\Throwable $e) {
            $this->hasOccurrencesColumn = false;
        }

        return $this->hasOccurrencesColumn;
    }

    /**
     * Batch insert findings for a page or resource.
     */
    public function insertBatch(int $scanId, int $pageResultId, ?int $resourceId, array $findings): int
    {
        if (empty($findings)) {
            return 0;
        }

        $hasOcc = $this->checkOccurrencesColumn();

        if ($hasOcc) {
            $stmt = QueryHelper::prepare($this->db, '
                INSERT INTO findings (
                    scan_id, page_result_id, resource_id, page_url, resource_url,
                    resource_type, matched_text, occurrences, surrounding_context, html_element,
                    css_selector, section_name, line_number, character_position,
                    is_visible, detection_rule_id, detection_category, severity,
                    confidence, finding_fingerprint, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, NOW()
                )
            ');
        } else {
            $stmt = QueryHelper::prepare($this->db, '
                INSERT INTO findings (
                    scan_id, page_result_id, resource_id, page_url, resource_url,
                    resource_type, matched_text, surrounding_context, html_element,
                    css_selector, section_name, line_number, character_position,
                    is_visible, detection_rule_id, detection_category, severity,
                    confidence, finding_fingerprint, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, NOW()
                )
            ');
        }

        $inserted = 0;
        foreach ($findings as $f) {
            $fingerprint = $f['finding_fingerprint'] ?? hash(
                'sha256',
                ($f['page_url'] ?? '') . '|' .
                ($f['resource_type'] ?? 'html') . '|' .
                ($f['detection_category'] ?? 'lorem_ipsum') . '|' .
                ($f['matched_text'] ?? '') . '|' .
                ($f['character_position'] ?? 0)
            );

            $occurrences = max(1, (int)($f['occurrences'] ?? 1));

            if ($hasOcc) {
                $stmt->execute([
                    $scanId,
                    $pageResultId,
                    $resourceId,
                    $f['page_url'],
                    $f['resource_url'] ?? null,
                    $f['resource_type'] ?? 'html',
                    $f['matched_text'],
                    $occurrences,
                    $f['surrounding_context'] ?? null,
                    $f['html_element'] ?? null,
                    $f['css_selector'] ?? null,
                    $f['section_name'] ?? null,
                    $f['line_number'] ?? null,
                    $f['character_position'] ?? null,
                    isset($f['is_visible']) ? ($f['is_visible'] ? 1 : 0) : 1,
                    $f['detection_rule_id'] ?? null,
                    $f['detection_category'] ?? 'lorem_ipsum',
                    $f['severity'] ?? 'high',
                    $f['confidence'] ?? 'high',
                    $fingerprint
                ]);
            } else {
                $stmt->execute([
                    $scanId,
                    $pageResultId,
                    $resourceId,
                    $f['page_url'],
                    $f['resource_url'] ?? null,
                    $f['resource_type'] ?? 'html',
                    $f['matched_text'],
                    $f['surrounding_context'] ?? null,
                    $f['html_element'] ?? null,
                    $f['css_selector'] ?? null,
                    $f['section_name'] ?? null,
                    $f['line_number'] ?? null,
                    $f['character_position'] ?? null,
                    isset($f['is_visible']) ? ($f['is_visible'] ? 1 : 0) : 1,
                    $f['detection_rule_id'] ?? null,
                    $f['detection_category'] ?? 'lorem_ipsum',
                    $f['severity'] ?? 'high',
                    $f['confidence'] ?? 'high',
                    $fingerprint
                ]);
            }
            $inserted++;
        }

        return $inserted;
    }

    /**
     * Delete previous findings for a specific scan resource to maintain idempotency.
     */
    public function deleteByResourceId(int $scanId, int $resourceId): int
    {
        $stmt = $this->db->prepare('DELETE FROM findings WHERE scan_id = ? AND resource_id = ?');
        $stmt->execute([$scanId, $resourceId]);
        return $stmt->rowCount();
    }

    public function listPaginated(int $scanId, int $page = 1, int $pageSize = 50, array $filters = []): array
    {
        $offset = ($page - 1) * $pageSize;
        $sql = 'SELECT * FROM findings WHERE scan_id = ?';
        $params = [$scanId];

        if (!empty($filters['category'])) {
            $cat = trim($filters['category']);
            if (strtolower($cat) === 'seo') {
                $sql .= ' AND detection_category IN ("missing_title", "missing_meta_desc", "missing_h1", "missing_canonical", "heading_structure")';
            } elseif (str_contains($cat, ',')) {
                $parts = array_filter(array_map('trim', explode(',', $cat)));
                $ph = implode(',', array_fill(0, count($parts), '?'));
                $sql .= " AND detection_category IN ({$ph})";
                foreach ($parts as $p) { $params[] = $p; }
            } else {
                $sql .= ' AND detection_category = ?';
                $params[] = $cat;
            }
        }
        if (!empty($filters['severity'])) {
            $sql .= ' AND severity = ?';
            $params[] = $filters['severity'];
        }
        if (!empty($filters['confidence'])) {
            $sql .= ' AND confidence = ?';
            $params[] = $filters['confidence'];
        }
        if (!empty($filters['resource_type'])) {
            $sql .= ' AND resource_type = ?';
            $params[] = $filters['resource_type'];
        }
        if (!empty($filters['search'])) {
            $sql .= ' AND (matched_text LIKE ? OR page_url LIKE ?)';
            $searchWild = '%' . $filters['search'] . '%';
            $params[] = $searchWild;
            $params[] = $searchWild;
        }

        $sortField = 'id';
        if (!empty($filters['sort_by']) && in_array($filters['sort_by'], ['id', 'severity', 'confidence', 'created_at'])) {
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
        $sql = 'SELECT COUNT(*) FROM findings WHERE scan_id = ?';
        $params = [$scanId];

        if (!empty($filters['category'])) {
            $cat = trim($filters['category']);
            if (strtolower($cat) === 'seo') {
                $sql .= ' AND detection_category IN ("missing_title", "missing_meta_desc", "missing_h1", "missing_canonical", "heading_structure")';
            } elseif (str_contains($cat, ',')) {
                $parts = array_filter(array_map('trim', explode(',', $cat)));
                $ph = implode(',', array_fill(0, count($parts), '?'));
                $sql .= " AND detection_category IN ({$ph})";
                foreach ($parts as $p) { $params[] = $p; }
            } else {
                $sql .= ' AND detection_category = ?';
                $params[] = $cat;
            }
        }
        if (!empty($filters['severity'])) {
            $sql .= ' AND severity = ?';
            $params[] = $filters['severity'];
        }
        if (!empty($filters['confidence'])) {
            $sql .= ' AND confidence = ?';
            $params[] = $filters['confidence'];
        }
        if (!empty($filters['resource_type'])) {
            $sql .= ' AND resource_type = ?';
            $params[] = $filters['resource_type'];
        }
        if (!empty($filters['search'])) {
            $sql .= ' AND (matched_text LIKE ? OR page_url LIKE ?)';
            $searchWild = '%' . $filters['search'] . '%';
            $params[] = $searchWild;
            $params[] = $searchWild;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Cross-scan comparison.
     * Compares previous scan findings to current scan findings.
     * Respects the rule: A finding is only 'resolved' if the page was successfully
     * scanned in current scan and the finding is no longer detected.
     */
    public function compareScans(int $previousScanId, int $currentScanId): array
    {
        // 1. All findings in previous scan
        $prevStmt = $this->db->prepare('
            SELECT f.*, pr.requested_url as page_req_url
            FROM findings f
            JOIN page_results pr ON f.page_result_id = pr.id
            WHERE f.scan_id = ?
        ');
        $prevStmt->execute([$previousScanId]);
        $prevFindings = $prevStmt->fetchAll();

        // 2. All findings in current scan
        $currStmt = $this->db->prepare('
            SELECT f.*, pr.requested_url as page_req_url
            FROM findings f
            JOIN page_results pr ON f.page_result_id = pr.id
            WHERE f.scan_id = ?
        ');
        $currStmt->execute([$currentScanId]);
        $currFindings = $currStmt->fetchAll();

        // 3. Pages successfully scanned in current scan
        $cleanPagesStmt = $this->db->prepare('
            SELECT requested_url
            FROM page_results
            WHERE scan_id = ? AND processing_status IN ("clean", "findings_detected")
        ');
        $cleanPagesStmt->execute([$currentScanId]);
        $scannedUrls = array_flip($cleanPagesStmt->fetchAll(PDO::FETCH_COLUMN));

        $prevByFingerprint = [];
        foreach ($prevFindings as $pf) {
            $prevByFingerprint[$pf['finding_fingerprint']] = $pf;
        }

        $currByFingerprint = [];
        foreach ($currFindings as $cf) {
            $currByFingerprint[$cf['finding_fingerprint']] = $cf;
        }

        $newFindings        = [];
        $persistingFindings = [];
        $resolvedFindings   = [];
        $unverifiedFindings = []; // Old finding on a page that failed or was not scanned

        foreach ($currByFingerprint as $fp => $cf) {
            if (isset($prevByFingerprint[$fp])) {
                $persistingFindings[] = $cf;
            } else {
                $newFindings[] = $cf;
            }
        }

        foreach ($prevByFingerprint as $fp => $pf) {
            if (!isset($currByFingerprint[$fp])) {
                $pageUrl = $pf['page_url'];
                // Only considered resolved if page was successfully scanned in current scan!
                if (isset($scannedUrls[$pageUrl])) {
                    $resolvedFindings[] = $pf;
                } else {
                    $unverifiedFindings[] = $pf;
                }
            }
        }

        return [
            'previous_scan_id' => $previousScanId,
            'current_scan_id'  => $currentScanId,
            'new_count'        => count($newFindings),
            'persisting_count' => count($persistingFindings),
            'resolved_count'   => count($resolvedFindings),
            'unverified_count' => count($unverifiedFindings),
            'new'              => $newFindings,
            'persisting'       => $persistingFindings,
            'resolved'         => $resolvedFindings,
            'unverified'       => $unverifiedFindings,
        ];
    }
}
