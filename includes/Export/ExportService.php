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

        // Fetch all results and joined findings for this scan using canonical resource_id
        $stmt = $this->db->prepare('
            SELECT pr.requested_url, pr.final_url, pr.http_status, pr.processing_status, pr.audit_status,
                   pr.finding_count, pr.error_type, pr.error_message, pr.page_title, pr.completed_at,
                   f.id as finding_id, f.resource_id, f.detection_category, f.severity, f.confidence,
                   f.matched_text, COALESCE(f.occurrences, 1) as occurrences,
                   f.surrounding_context, f.html_element, f.css_selector, f.resource_type
            FROM page_results pr
            LEFT JOIN findings f ON pr.id = f.page_result_id
            WHERE pr.scan_id = ?
            ORDER BY pr.id ASC, f.id ASC
        ');
        $stmt->execute([$scanId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'scan'      => $scan,
            'project'   => $project,
            'rows'      => $rows,
            'safe_name' => $safeName,
        ];
    }

    /**
     * Generate CSV content with UTF-8 BOM.
     */
    public function generateCsv(array $exportData): string
    {
        $out = fopen('php://memory', 'r+');
        if ($out === false) {
            throw new \RuntimeException('Unable to open memory stream for CSV export.');
        }

        // UTF-8 BOM for Excel compatibility
        fputs($out, "\xEF\xBB\xBF");

        fputcsv($out, [
            'URL',
            'Processing Status',
            'Audit Status',
            'Source Type',
            'Category',
            'Matched Text',
            'Occurrences',
            'Context / Details',
            'HTTP Status',
            'Checked At'
        ]);

        foreach ($exportData['rows'] as $r) {
            $isResource = !empty($r['resource_id']) || ($r['resource_type'] !== 'html' && !empty($r['resource_type']));
            $sourceType = $isResource ? 'resource' : 'page';
            $category   = $r['detection_category'] ?: ($r['processing_status'] === 'clean' ? 'none' : 'n/a');
            $matched    = $r['matched_text'] ?: '';
            $details    = $r['error_message'] ?: ($r['surrounding_context'] ?: ($r['processing_status'] === 'clean' ? 'Clean - No issues found' : ''));
            $occ        = $r['finding_id'] ? (int)($r['occurrences'] ?? 1) : 0;

            fputcsv($out, [
                $r['requested_url'],
                $r['processing_status'],
                $r['audit_status'] ?? '',
                $sourceType,
                $category,
                $matched,
                $occ,
                $details,
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
     * Generate structured JSON content.
     */
    public function generateJson(array $exportData): string
    {
        $scan    = $exportData['scan'];
        $project = $exportData['project'];

        $payload = [
            'project_name' => $project['project_name'],
            'website_url'  => $project['website_url'],
            'scan_id'      => (int)$scan['id'],
            'status'       => $scan['status'],
            'scanned_at'   => $scan['completed_at'] ?? $scan['created_at'],
            'metrics'      => [
                'discovered'        => (int)$scan['discovered_count'],
                'processed'         => (int)$scan['processed_count'],
                'clean'             => (int)$scan['clean_count'],
                'affected_pages'    => (int)$scan['affected_page_count'],
                'failed'            => (int)$scan['failed_count'],
                'total_findings'    => (int)$scan['total_findings'],
                'lorem_findings'    => (int)($scan['lorem_finding_count'] ?? 0),
                'lorem_pages'       => (int)($scan['lorem_page_count'] ?? 0),
                'lorem_occurrences' => (int)($scan['lorem_occurrence_count'] ?? 0),
            ],
            'results'      => $exportData['rows'],
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
