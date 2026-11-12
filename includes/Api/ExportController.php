<?php
/**
 * Export Controller
 *
 * Generates CSV and JSON downloads of scan audit results.
 */

namespace LoremChecker\Api;

use LoremChecker\Repository\FindingRepository;
use LoremChecker\Repository\PageResultRepository;
use LoremChecker\Repository\ProjectRepository;
use LoremChecker\Repository\ScanRepository;
use PDO;

class ExportController
{
    private PDO                  $db;
    private ProjectRepository    $projectRepo;
    private ScanRepository       $scanRepo;
    private PageResultRepository $pageResRepo;
    private FindingRepository    $findingRepo;

    public function __construct(PDO $db)
    {
        $this->db          = $db;
        $this->projectRepo = new ProjectRepository($db);
        $this->scanRepo    = new ScanRepository($db);
        $this->pageResRepo = new PageResultRepository($db);
        $this->findingRepo = new FindingRepository($db);
    }

    public function export(): void
    {
        $scanId    = isset($_GET['scan_id']) && is_numeric($_GET['scan_id']) ? (int)$_GET['scan_id'] : null;
        $projectId = isset($_GET['project_id']) && is_numeric($_GET['project_id']) ? (int)$_GET['project_id'] : null;
        $format    = strtolower($_GET['format'] ?? 'csv');

        if (!$scanId && !$projectId) {
            Response::error('Either scan_id or project_id must be provided.', 'VALIDATION_ERROR', 400);
        }

        if ($scanId) {
            $scan = $this->scanRepo->getById($scanId);
            if (!$scan) {
                Response::error('Scan record not found.', 'NOT_FOUND', 404);
            }
            $project = $this->projectRepo->getById((int)$scan['project_id']);
            if (!$project) {
                $project = ['project_name' => 'Scan_' . $scanId, 'website_url' => ''];
            }
        } else {
            $project = $this->projectRepo->getById($projectId);
            if (!$project) {
                Response::error('Project not found.', 'NOT_FOUND', 404);
            }
            $scan = $this->scanRepo->getLatestByProjectId($projectId);
            if (!$scan) {
                Response::error('No scan records found for this project.', 'NOT_FOUND', 404);
            }
            $scanId = (int)$scan['id'];
        }

        $safeName = preg_replace('/[^a-zA-Z0-9]/', '_', $project['project_name'] ?: 'scan_' . $scanId);

        // Fetch all results and joined findings for this scan
        $stmt = $this->db->prepare('
            SELECT pr.requested_url, pr.final_url, pr.http_status, pr.processing_status, pr.audit_status,
                   pr.finding_count, pr.error_type, pr.error_message, pr.page_title, pr.completed_at,
                   f.id as finding_id, f.detection_category, f.severity, f.confidence, f.matched_text,
                   COALESCE(f.occurrences, 1) as occurrences, f.surrounding_context, f.html_element, f.css_selector
            FROM page_results pr
            LEFT JOIN findings f ON pr.id = f.page_result_id
            WHERE pr.scan_id = ?
            ORDER BY pr.id ASC, f.id ASC
        ');
        $stmt->execute([$scanId]);
        $rows = $stmt->fetchAll();

        if ($format === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $safeName . '_scan_' . $scanId . '_results.json"');
            echo json_encode([
                'project_name' => $project['project_name'],
                'website_url'  => $project['website_url'],
                'scan_id'      => $scanId,
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
                'results'      => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit(0);
        }

        // CSV export (default)
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $safeName . '_scan_' . $scanId . '_results.csv"');

        $out = fopen('php://output', 'w');
        // UTF-8 BOM for Excel compatibility
        fputs($out, "\xEF\xBB\xBF");

        fputcsv($out, [
            'URL',
            'Processing Status',
            'Audit Status',
            'Category',
            'Matched Text',
            'Occurrences',
            'Context / Details',
            'HTTP Status',
            'Checked At'
        ]);

        foreach ($rows as $r) {
            $category = $r['detection_category'] ?: ($r['processing_status'] === 'clean' ? 'none' : 'n/a');
            $matched  = $r['matched_text'] ?: '';
            $details  = $r['error_message'] ?: ($r['surrounding_context'] ?: ($r['processing_status'] === 'clean' ? 'Clean - No issues found' : ''));
            $occ      = $r['finding_id'] ? (int)($r['occurrences'] ?? 1) : 0;

            fputcsv($out, [
                $r['requested_url'],
                $r['processing_status'],
                $r['audit_status'] ?? '',
                $category,
                $matched,
                $occ,
                $details,
                $r['http_status'] ?? '',
                $r['completed_at'] ?? '',
            ]);
        }

        fclose($out);
        exit(0);
    }
}
