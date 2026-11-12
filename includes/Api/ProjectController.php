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

            $loremFindings    = (int)($r['lorem_finding_count'] ?? 0);
            $loremPages       = (int)($r['lorem_page_count'] ?? 0);
            $loremOccurrences = (int)($r['lorem_occurrence_count'] ?? $loremFindings);
            $affectedPages    = (int)($r['affected_page_count'] ?? 0);
            $totalFindings    = (int)($r['total_findings'] ?? 0);

            // Authoritative verification: zero persisted findings must always yield 0 lorem metrics
            $latestScanId = !empty($r['latest_scan_id']) ? (int)$r['latest_scan_id'] : null;
            if ($totalFindings === 0) {
                $loremFindings    = 0;
                $loremPages       = 0;
                $loremOccurrences = 0;
                $affectedPages    = 0;
            } elseif ($latestScanId && $loremFindings > 0) {
                $chk = $this->db->prepare("SELECT COUNT(*) FROM findings WHERE scan_id = ? AND detection_category = 'lorem_ipsum' AND resource_id IS NULL");
                $chk->execute([$latestScanId]);
                if ((int)$chk->fetchColumn() === 0) {
                    $loremFindings    = 0;
                    $loremPages       = 0;
                    $loremOccurrences = 0;
                }
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
