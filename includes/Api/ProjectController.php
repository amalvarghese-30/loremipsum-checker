<?php
/**
 * Project Controller
 *
 * Handles project listing, creation, details, and deletion.
 * Provides backwards-compatible responses so index.html works seamlessly.
 */

namespace LoremChecker\Api;

use LoremChecker\Discovery\UrlNormalizer;
use LoremChecker\Repository\ProjectRepository;
use LoremChecker\Repository\ScanRepository;
use PDO;

class ProjectController
{
    private PDO               $db;
    private ProjectRepository $projectRepo;
    private ScanRepository    $scanRepo;

    public function __construct(PDO $db)
    {
        $this->db          = $db;
        $this->projectRepo = new ProjectRepository($db);
        $this->scanRepo    = new ScanRepository($db);
    }

    public function list(): void
    {
        $rows = $this->projectRepo->listProjects(false);
        $projects = [];

        foreach ($rows as $r) {
            $totalUrls = (int)($r['discovered_count'] ?? 0);
            $processed = (int)($r['processed_count'] ?? 0);
            $percentage = $totalUrls > 0 ? round(($processed / $totalUrls) * 100, 2) : 0;

            $latestScanId = !empty($r['latest_scan_id']) ? (int)$r['latest_scan_id'] : null;

            if ($latestScanId) {
                // Unconditional authoritative page-level Lorem metrics from persisted findings
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
                $loremStmt->execute([$latestScanId, $latestScanId]);
                $lData = $loremStmt->fetch(PDO::FETCH_ASSOC) ?: [];

                $loremFindings    = (int)($lData['lorem_findings'] ?? 0);
                $loremOccurrences = (int)($lData['lorem_occurrences'] ?? 0);
                $loremPages       = (int)($lData['lorem_pages'] ?? 0);

                // Authoritative total findings
                $totStmt = $this->db->prepare('
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
                $totStmt->execute([$latestScanId, $latestScanId]);
                $totalFindings = (int)$totStmt->fetchColumn();

                $affectedPages = (int)($r['affected_page_count'] ?? 0);
                if ($totalFindings === 0) {
                    $affectedPages = 0;
                }
            } else {
                $loremFindings    = 0;
                $loremPages       = 0;
                $loremOccurrences = 0;
                $totalFindings    = 0;
                $affectedPages    = 0;
            }

            // Maintain exact structure expected by index.html loadProjects()
            $projects[] = [
                'id'           => (string)$r['id'],
                'project_name' => $r['project_name'],
                'website_url'  => $r['website_url'],
                'status'       => $r['latest_scan_status'] ?? 'initializing',
                'created_at'   => $r['created_at'],
                'updated_at'   => $r['updated_at'],
                'progress'     => [
                    'total_urls'        => $totalUrls,
                    'processed'         => $processed,
                    'clean'             => (int)($r['clean_count'] ?? 0),
                    'lorem_found'       => $loremFindings,
                    'lorem_pages'       => $loremPages,
                    'lorem_occurrences' => $loremOccurrences,
                    'affected_pages'    => $affectedPages,
                    'total_findings'    => $totalFindings,
                    'errors'            => (int)($r['failed_count'] ?? 0),
                    'percentage'        => $percentage,
                ],
                'latest_scan_id' => $r['latest_scan_id'] ? (int)$r['latest_scan_id'] : null,
            ];
        }

        Response::success(['projects' => $projects]);
    }

    public function getDetails(): void
    {
        $id = RequestValidator::validateId($_GET['project_id'] ?? $_GET['id'] ?? null, 'project_id');
        $project = $this->projectRepo->getById($id);

        if (!$project) {
            Response::error('Project not found.', 'NOT_FOUND', 404);
        }

        $scans = $this->scanRepo->listByProjectId($id);
        $project['scans'] = $scans;

        Response::success(['project' => $project]);
    }

    public function delete(): void
    {
        $id = RequestValidator::validateId($_POST['project_id'] ?? $_GET['project_id'] ?? null, 'project_id');
        $project = $this->projectRepo->getById($id);

        if (!$project) {
            Response::error('Project not found.', 'NOT_FOUND', 404);
        }

        // Cancel any active scans for this project and terminate their workers
        $activeScans = $this->scanRepo->listByProjectId($id);
        foreach ($activeScans as $s) {
            if (in_array($s['status'], ['initializing', 'discovering', 'scanning', 'pause_requested'])) {
                $this->scanRepo->updateStatus((int)$s['id'], 'cancelled');
                if (!empty($s['worker_pid'])) {
                    $pid = (int)$s['worker_pid'];
                    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                        @exec("taskkill /F /PID {$pid} 2>nul");
                    } else {
                        @exec("kill -TERM {$pid} 2>/dev/null");
                    }
                }
            }
        }

        $this->projectRepo->softDelete($id);
        Response::success(['message' => 'Project deleted successfully']);
    }
}
