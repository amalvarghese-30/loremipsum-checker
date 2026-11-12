<?php
/**
 * Export Service
 *
 * Handles data aggregation and formatting for CSV and JSON scan reports.
 * Decoupled from HTTP output for testability and architectural cleanliness.
 */

declare(strict_types=1);

namespace LoremChecker\Export;

use PDO;
use LoremChecker\Repository\ProjectRepository;
use LoremChecker\Repository\ScanRepository;

class ExportService
{
    private PDO $db;
    private ScanRepository $scanRepo;
    private ProjectRepository $projectRepo;

    public function __construct(PDO $db)
    {
        $this->db          = $db;
        $this->scanRepo    = new ScanRepository($db);
        $this->projectRepo = new ProjectRepository($db);
    }

    /**
     * Retrieve structured audit data for the specified scan (or latest scan of project).
     *
     * @param int|null $scanId
     * @param int|null $projectId
     * @return array
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function getExportData(?int $scanId, ?int $projectId = null): array
    {
        if (!$scanId && !$projectId) {
            throw new \InvalidArgumentException('Either scan_id or project_id must be provided.');
        }

        if ($scanId) {
            $scan = $this->scanRepo->getById($scanId);
            if (!$scan) {
                throw new \RuntimeException('Scan record not found.');
            }
            $project = $this->projectRepo->getById((int)$scan['project_id']);
            if (!$project) {
                $project = ['id' => $scan['project_id'], 'project_name' => 'Scan_' . $scanId, 'website_url' => ''];
            }
        } else {
            $project = $this->projectRepo->getById((int)$projectId);
            if (!$project) {
                throw new \RuntimeException('Project not found.');
            }
            $scan = $this->scanRepo->getLatestByProjectId((int)$projectId);
            if (!$scan) {
                throw new \RuntimeException('No scan records found for this project.');
            }
            $scanId = (int)$scan['id'];
        }

        $safeName = preg_replace('/[^a-zA-Z0-9]/', '_', $project['project_name'] ?: 'scan_' . $scanId);

        // Fetch canonical page results and joined findings for this exact scan
        $stmt = $this->db->prepare('
            SELECT pr.id as page_result_id,
                   pr.requested_url,
                   pr.final_url,
                   pr.http_status,
                   pr.processing_status,
                   pr.audit_status,
                   pr.finding_count,
                   pr.error_type,
                   pr.error_message,
                   pr.page_title,
                   pr.completed_at,
                   f.id as finding_id,
                   f.resource_id,
                   COALESCE(sr.resource_url, f.resource_url) as resource_url,
                   COALESCE(f.resource_type, "html") as resource_type,
                   f.detection_category,
                   f.severity,
                   f.confidence,
                   f.matched_text,
                   COALESCE(f.occurrences, 1) as occurrences,
                   f.surrounding_context,
                   f.html_element,
                   f.css_selector
            FROM page_results pr
            INNER JOIN (
                SELECT MAX(id) as canonical_id
                FROM page_results
                WHERE scan_id = ?
                GROUP BY CASE WHEN discovered_url_id IS NOT NULL THEN discovered_url_id ELSE id END
            ) cp ON pr.id = cp.canonical_id
            LEFT JOIN findings f ON pr.id = f.page_result_id
            LEFT JOIN scan_resources sr ON f.resource_id = sr.id
            WHERE pr.scan_id = ?
            ORDER BY pr.id ASC, f.id ASC
        ');
        $stmt->execute([$scanId, $scanId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Authoritative metrics calculated directly from canonical rows
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

        $totFindStmt = $this->db->prepare('
            SELECT COUNT(*)
            FROM findings f
            LEFT JOIN (
                SELECT MAX(id) as canonical_id
                FROM page_results
                WHERE scan_id = ?
                GROUP BY CASE WHEN discovered_url_id IS NOT NULL THEN discovered_url_id ELSE id END
            ) cp ON f.page_result_id = cp.canonical_id
            WHERE f.scan_id = ?
              AND (f.resource_id IS NOT NULL OR cp.canonical_id IS NOT NULL)
        ');
        $totFindStmt->execute([$scanId, $scanId]);
        $totalFindings = (int)$totFindStmt->fetchColumn();

        $metrics = [
            'discovered'        => (int)$scan['discovered_count'],
            'processed'         => (int)$scan['processed_count'],
            'clean'             => (int)$scan['clean_count'],
            'affected_pages'    => (int)$scan['affected_page_count'],
            'failed'            => (int)$scan['failed_count'],
            'total_findings'    => $totalFindings,
            'lorem_findings'    => (int)($loremData['lorem_findings'] ?? 0),
            'lorem_pages'       => (int)($loremData['lorem_pages'] ?? 0),
            'lorem_occurrences' => (int)($loremData['lorem_occurrences'] ?? 0),
        ];

        return [
            'scan'      => $scan,
            'project'   => $project,
            'rows'      => $rows,
            'metrics'   => $metrics,
            'safe_name' => $safeName,
        ];
    }

    /**
     * Generate CSV content with UTF-8 BOM conforming to Phase 8 contract.
     */
    public function generateCsv(array $exportData): string
    {
        $out = fopen('php://memory', 'r+');
        if ($out === false) {
            throw new \RuntimeException('Unable to open memory stream for CSV export.');
        }

        fputcsv($out, [
            'Scan ID',
            'Project',
            'Page URL',
            'Resource URL',
            'Source Type',
            'Resource Type',
            'Processing Status',
            'Audit Status',
            'Detection Category',
            'Severity',
            'Confidence',
            'Matched Text',
            'Occurrences',
            'Surrounding Context',
            'HTML Element',
            'CSS Selector',
            'HTTP Status',
            'Checked At'
        ]);

        $scanId      = (int)($exportData['scan']['id'] ?? 0);
        $projectName = (string)($exportData['project']['project_name'] ?? '');

        foreach ($exportData['rows'] as $r) {
            // Determine source type strictly by relationship identity
            $isResource = !empty($r['resource_id']);
            $sourceType = $isResource ? 'resource' : 'page';

            $category   = $r['detection_category'] ?: ($r['processing_status'] === 'clean' ? 'none' : 'n/a');
            $matched    = $r['matched_text'] ?: '';
            $occ        = $r['finding_id'] ? (int)($r['occurrences'] ?? 1) : 0;
            $details    = $r['error_message'] ?: ($r['surrounding_context'] ?: ($r['processing_status'] === 'clean' ? 'Clean - No issues found' : ''));

            fputcsv($out, [
                $scanId,
                $projectName,
                $r['requested_url'],
                $r['resource_url'] ?? '',
                $sourceType,
                $r['resource_type'] ?? 'html',
                $r['processing_status'],
                $r['audit_status'] ?? '',
                $category,
                $r['severity'] ?? '',
                $r['confidence'] ?? '',
                $matched,
                $occ,
                $details,
                $r['html_element'] ?? '',
                $r['css_selector'] ?? '',
                $r['http_status'] ?? '',
                $r['completed_at'] ?? '',
            ]);
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv !== false ? $csv : '';
    }

    /**
     * Generate structured JSON content with authoritative metrics.
     */
    public function generateJson(array $exportData): string
    {
        $scan    = $exportData['scan'];
        $project = $exportData['project'];
        $metrics = $exportData['metrics'] ?? [];

        $payload = [
            'project_name' => $project['project_name'],
            'website_url'  => $project['website_url'],
            'scan_id'      => (int)$scan['id'],
            'status'       => $scan['status'],
            'scanned_at'   => $scan['completed_at'] ?? $scan['created_at'],
            'metrics'      => $metrics,
            'results'      => $exportData['rows'],
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * Convenience helper to build CSV export directly from params array ['scan_id' => X] or ['project_id' => Y].
     */
    public function buildCsvExport(array $params): string
    {
        $scanId    = isset($params['scan_id']) ? (int)$params['scan_id'] : null;
        $projectId = isset($params['project_id']) ? (int)$params['project_id'] : null;
        $data = $this->getExportData($scanId, $projectId);
        return $this->generateCsv($data);
    }

    /**
     * Convenience helper to build JSON export directly from params array ['scan_id' => X] or ['project_id' => Y].
     */
    public function buildJsonExport(array $params): string
    {
        $scanId    = isset($params['scan_id']) ? (int)$params['scan_id'] : null;
        $projectId = isset($params['project_id']) ? (int)$params['project_id'] : null;
        $data = $this->getExportData($scanId, $projectId);
        return $this->generateJson($data);
    }
}
