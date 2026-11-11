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
        $projectId = RequestValidator::validateId($_GET['project_id'] ?? null, 'project_id');
        $format    = strtolower($_GET['format'] ?? 'csv');

        $project = $this->projectRepo->getById($projectId);
        if (!$project) {
            Response::error('Project not found.', 'NOT_FOUND', 404);
        }

        $scan = $this->scanRepo->getLatestByProjectId($projectId);
        if (!$scan) {
            Response::error('No scan records found for this project.', 'NOT_FOUND', 404);
        }

        $scanId   = (int)$scan['id'];
        $safeName = preg_replace('/[^a-zA-Z0-9]/', '_', $project['project_name']);

        // Fetch all results for this scan
        $stmt = $this->db->prepare('
            SELECT pr.*, f.matched_text, f.surrounding_context
            FROM page_results pr
            LEFT JOIN findings f ON pr.id = f.page_result_id
            WHERE pr.scan_id = ?
            ORDER BY pr.id ASC
        ');
        $stmt->execute([$scanId]);
        $rows = $stmt->fetchAll();

        if ($format === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $safeName . '_results.json"');
            echo json_encode([
                'project_name' => $project['project_name'],
                'website_url'  => $project['website_url'],
                'scan_id'      => $scanId,
                'status'       => $scan['status'],
                'scanned_at'   => $scan['completed_at'] ?? $scan['created_at'],
                'results'      => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit(0);
        }

        // CSV export (default)
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $safeName . '_results.csv"');

        $out = fopen('php://output', 'w');
        // UTF-8 BOM for Excel compatibility
        fputs($out, "\xEF\xBB\xBF");

        fputcsv($out, ['URL', 'Status', 'Details', 'Occurrences', 'Checked At']);

        foreach ($rows as $r) {
            $details = $r['error_message'] ?: ($r['matched_text'] ?: ($r['processing_status'] === 'clean' ? 'Clean - No placeholder text found' : ''));
            fputcsv($out, [
                $r['requested_url'],
                $r['processing_status'],
                $details,
                $r['finding_count'] ?? 0,
                $r['completed_at'] ?? $r['started_at'] ?? '',
            ]);
        }

        fclose($out);
        exit(0);
    }
}
