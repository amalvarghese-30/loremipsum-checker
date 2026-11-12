<?php
/**
 * Results & Findings Controller
 *
 * Provides paginated querying for page results, individual findings,
 * page details with linked assets, and cross-scan comparative analysis.
 */

namespace LoremChecker\Api;

use LoremChecker\Repository\FindingRepository;
use LoremChecker\Repository\PageResultRepository;
use LoremChecker\Repository\ScanRepository;
use PDO;

class ResultsController
{
    private PDO                  $db;
    private ScanRepository       $scanRepo;
    private PageResultRepository $pageResRepo;
    private FindingRepository    $findingRepo;

    public function __construct(PDO $db)
    {
        $this->db          = $db;
        $this->scanRepo    = new ScanRepository($db);
        $this->pageResRepo = new PageResultRepository($db);
        $this->findingRepo = new FindingRepository($db);
    }

    public function pageResults(): void
    {
        $scanId = RequestValidator::validateId($_GET['scan_id'] ?? null, 'scan_id');
        $pagination = RequestValidator::sanitizePagination($_GET);

        $filters = [
            'status'          => !empty($_GET['status']) ? trim($_GET['status']) : null,
            'audit_status'    => !empty($_GET['audit_status']) ? trim($_GET['audit_status']) : null,
            'has_lorem_ipsum' => isset($_GET['has_lorem']) ? (bool)$_GET['has_lorem'] : null,
            'has_findings'    => isset($_GET['has_findings']) ? (bool)$_GET['has_findings'] : null,
            'category'        => !empty($_GET['category']) ? trim($_GET['category']) : null,
            'search'          => !empty($_GET['search']) ? trim($_GET['search']) : null,
            'sort_by'         => RequestValidator::sanitizeSortField($_GET['sort_by'] ?? null, ['id', 'duration_ms', 'finding_count', 'http_status'], 'id'),
            'sort_dir'        => $_GET['sort_dir'] ?? 'DESC',
        ];

        $results = $this->pageResRepo->listPaginated($scanId, $pagination['page'], $pagination['limit'], $filters);
        $total   = $this->pageResRepo->countFiltered($scanId, $filters);

        Response::success([
            'results'    => $results,
            'pagination' => [
                'page'        => $pagination['page'],
                'limit'       => $pagination['limit'],
                'total_items' => $total,
                'total_pages' => ceil($total / $pagination['limit']),
            ],
        ]);
    }

    public function listPages(): void
    {
        $this->pageResults();
    }

    public function findings(): void
    {
        $scanId = RequestValidator::validateId($_GET['scan_id'] ?? null, 'scan_id');
        $pagination = RequestValidator::sanitizePagination($_GET);

        $filters = [
            'category'      => !empty($_GET['category']) ? trim($_GET['category']) : null,
            'severity'      => !empty($_GET['severity']) ? trim($_GET['severity']) : null,
            'confidence'    => !empty($_GET['confidence']) ? trim($_GET['confidence']) : null,
            'resource_type' => !empty($_GET['resource_type']) ? trim($_GET['resource_type']) : null,
            'search'        => !empty($_GET['search']) ? trim($_GET['search']) : null,
            'sort_by'       => RequestValidator::sanitizeSortField($_GET['sort_by'] ?? null, ['id', 'severity', 'confidence', 'created_at'], 'id'),
            'sort_dir'      => $_GET['sort_dir'] ?? 'DESC',
        ];

        $findings = $this->findingRepo->listPaginated($scanId, $pagination['page'], $pagination['limit'], $filters);
        $total    = $this->findingRepo->countFiltered($scanId, $filters);

        Response::success([
            'findings'   => $findings,
            'pagination' => [
                'page'        => $pagination['page'],
                'limit'       => $pagination['limit'],
                'total_items' => $total,
                'total_pages' => ceil($total / $pagination['limit']),
            ],
        ]);
    }

    public function listFindings(): void
    {
        $this->findings();
    }

    public function compare(): void
    {
        $prevScanId = RequestValidator::validateId($_GET['previous_scan_id'] ?? null, 'previous_scan_id');
        $currScanId = RequestValidator::validateId($_GET['current_scan_id'] ?? null, 'current_scan_id');

        $comparison = $this->findingRepo->compareScans($prevScanId, $currScanId);
        Response::success(['comparison' => $comparison]);
    }

    public function dashboardStats(): void
    {
        $totalProjects = (int)$this->db->query('SELECT COUNT(*) FROM projects WHERE deleted_at IS NULL')->fetchColumn();
        $totalAudits   = (int)$this->db->query('SELECT COUNT(*) FROM scans')->fetchColumn();

        $scanAggregates = $this->db->query('
            SELECT 
                COALESCE(SUM(discovered_count), 0) as total_discovered,
                COALESCE(SUM(processed_count), 0) as total_audited,
                COALESCE(SUM(clean_count), 0) as total_clean,
                COALESCE(SUM(affected_page_count), 0) as total_affected,
                COALESCE(SUM(failed_count), 0) as total_failed,
                COALESCE(SUM(total_findings), 0) as total_findings
            FROM scans
        ')->fetch();

        $categoriesStmt = $this->db->query('
            SELECT detection_category, COUNT(*) as count
            FROM findings
            GROUP BY detection_category
            ORDER BY count DESC
        ');
        $categories = $categoriesStmt ? $categoriesStmt->fetchAll() : [];

        $recentStmt = $this->db->query('
            SELECT s.id, s.project_id, s.scan_number, s.status, s.discovered_count,
                   s.processed_count, s.clean_count, s.affected_page_count, s.failed_count,
                   s.total_findings, s.started_at, s.completed_at, s.created_at,
                   p.project_name, p.website_url
            FROM scans s
            JOIN projects p ON s.project_id = p.id
            WHERE p.deleted_at IS NULL
            ORDER BY s.id DESC
            LIMIT 6
        ');
        $recentScans = $recentStmt ? $recentStmt->fetchAll() : [];

        Response::success([
            'stats' => [
                'total_projects'   => $totalProjects,
                'total_audits'     => $totalAudits,
                'total_discovered' => (int)($scanAggregates['total_discovered'] ?? 0),
                'total_audited'    => (int)($scanAggregates['total_audited'] ?? 0),
                'total_clean'      => (int)($scanAggregates['total_clean'] ?? 0),
                'total_affected'   => (int)($scanAggregates['total_affected'] ?? 0),
                'total_failed'     => (int)($scanAggregates['total_failed'] ?? 0),
                'total_findings'   => (int)($scanAggregates['total_findings'] ?? 0),
            ],
            'categories'   => $categories,
            'recent_scans' => $recentScans,
        ]);
    }

    public function scanSummary(): void
    {
        $scanId = RequestValidator::validateId($_GET['scan_id'] ?? null, 'scan_id');
        $scan = $this->scanRepo->getById($scanId);
        if (!$scan) {
            Response::error('Scan not found.', 'NOT_FOUND', 404);
        }

        // Fetch all CANONICAL page results for this scan
        $pagesStmt = $this->db->prepare('
            SELECT pr.id, pr.discovered_url_id, pr.processing_status, pr.http_status,
                   COALESCE(pf.page_finding_count, 0) as actual_page_finding_count
            FROM page_results pr
            INNER JOIN (
                SELECT MAX(id) as max_id
                FROM page_results
                WHERE scan_id = ?
                GROUP BY CASE WHEN discovered_url_id IS NOT NULL THEN discovered_url_id ELSE id END
            ) latest ON pr.id = latest.max_id
            LEFT JOIN (
                SELECT page_result_id,
                       COUNT(CASE WHEN resource_id IS NULL THEN 1 END) as page_finding_count
                FROM findings
                WHERE scan_id = ?
                GROUP BY page_result_id
            ) pf ON pr.id = pf.page_result_id
            WHERE pr.scan_id = ?
        ');
        $pagesStmt->execute([$scanId, $scanId, $scanId]);
        $canonicalPages = $pagesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $cleanPages        = 0;
        $affectedPages     = 0;
        $errorPages        = 0;
        $unclassifiedPages = 0;

        foreach ($canonicalPages as $p) {
            $status     = (string)($p['processing_status'] ?? '');
            $httpStatus = (int)($p['http_status'] ?? 0);
            $fCount     = (int)($p['actual_page_finding_count'] ?? 0);

            $isTerminalFailure = in_array($status, [
                'failed', 'cloudflare_blocked', 'bot_blocked', 'rate_limited',
                'timeout', 'ssl_error', 'not_found', 'error'
            ], true) || ($httpStatus >= 400);

            if ($isTerminalFailure) {
                $errorPages++;
                continue;
            }

            if ($fCount > 0 || $status === 'findings_detected') {
                $affectedPages++;
                continue;
            }

            if ($status === 'clean' && $fCount === 0) {
                $cleanPages++;
                continue;
            }

            $unclassifiedPages++;
        }

        $processedPages = $cleanPages + $affectedPages + $errorPages;

        $health = [
            'clean_pages'        => $cleanPages,
            'affected_pages'     => $affectedPages,
            'error_pages'        => $errorPages,
            'unclassified_pages' => $unclassifiedPages,
            'total_pages'        => $processedPages,
        ];

        // Authoritative page-level findings breakdown by category
        $catStmt = $this->db->prepare('
            SELECT f.detection_category, COUNT(*) as count, COALESCE(SUM(COALESCE(f.occurrences, 1)), COUNT(*)) as occurrences
            FROM findings f
            INNER JOIN (
                SELECT MAX(id) as canonical_id
                FROM page_results
                WHERE scan_id = ?
                GROUP BY CASE WHEN discovered_url_id IS NOT NULL THEN discovered_url_id ELSE id END
            ) cp ON f.page_result_id = cp.canonical_id
            WHERE f.scan_id = ? AND f.resource_id IS NULL
            GROUP BY f.detection_category
            ORDER BY count DESC
        ');
        $catStmt->execute([$scanId, $scanId]);
        $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $catMap = [];
        foreach ($categories as $c) {
            $catMap[$c['detection_category']] = [
                'count'       => (int)$c['count'],
                'occurrences' => (int)$c['occurrences']
            ];
        }

        // Authoritative page-level Lorem metrics
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

        $pageLoremFindings    = (int)($loremData['lorem_findings'] ?? 0);
        $pageLoremOccurrences = (int)($loremData['lorem_occurrences'] ?? 0);
        $pageLoremPages       = (int)($loremData['lorem_pages'] ?? 0);

        // Resource-level Lorem metrics
        $resLoremStmt = $this->db->prepare('
            SELECT 
                COUNT(*) as res_findings,
                COALESCE(SUM(COALESCE(occurrences, 1)), 0) as res_occurrences
            FROM findings
            WHERE scan_id = ?
              AND detection_category = "lorem_ipsum"
              AND resource_id IS NOT NULL
        ');
        $resLoremStmt->execute([$scanId]);
        $resLoremData = $resLoremStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $resourceLoremFindings    = (int)($resLoremData['res_findings'] ?? 0);
        $resourceLoremOccurrences = (int)($resLoremData['res_occurrences'] ?? 0);

        // Always guarantee lorem_ipsum key exists in category_stats with authoritative values
        $catMap['lorem_ipsum'] = [
            'count'       => $pageLoremFindings,
            'occurrences' => $pageLoremOccurrences,
            'pages'       => $pageLoremPages,
        ];
        $health['lorem_pages'] = $pageLoremPages;

        $sevStmt = $this->db->prepare('
            SELECT severity, COUNT(*) as count
            FROM findings
            WHERE scan_id = ?
            GROUP BY severity
        ');
        $sevStmt->execute([$scanId]);
        $severities = $sevStmt->fetchAll() ?: [];

        // Fetch associated project
        $projectStmt = $this->db->prepare('SELECT id, project_name, website_url FROM projects WHERE id = ?');
        $projectStmt->execute([(int)$scan['project_id']]);
        $project = $projectStmt->fetch() ?: [];

        $scan['clean_count']            = $cleanPages;
        $scan['affected_page_count']    = $affectedPages;
        $scan['failed_count']           = $errorPages;
        $scan['processed_count']        = $processedPages;
        $scan['lorem_finding_count']    = $pageLoremFindings;
        $scan['lorem_occurrence_count'] = $pageLoremOccurrences;
        $scan['lorem_page_count']       = $pageLoremPages;

        Response::success([
            'scan'           => $scan,
            'project'        => $project,
            'health'         => $health,
            'pages'          => [
                'discovered'   => (int)($scan['discovered_count'] ?? 0),
                'processed'    => $processedPages,
                'clean'        => $cleanPages,
                'affected'     => $affectedPages,
                'failed'       => $errorPages,
                'unclassified' => $unclassifiedPages,
            ],
            'lorem'          => [
                'pages'                => $pageLoremPages,
                'findings'             => $pageLoremFindings,
                'occurrences'          => $pageLoremOccurrences,
                'resource_findings'    => $resourceLoremFindings,
                'resource_occurrences' => $resourceLoremOccurrences,
            ],
            'categories'     => $categories,
            'category_stats' => $catMap,
            'severities'     => $severities,
            'settings'       => $scan['settings'] ?? [],
        ]);
    }

    public function pageDetail(): void
    {
        $pageResultId = RequestValidator::validateId($_GET['page_result_id'] ?? $_GET['id'] ?? null, 'page_result_id');
        $page = $this->pageResRepo->getById($pageResultId);
        if (!$page) {
            Response::error('Page result not found.', 'NOT_FOUND', 404);
        }

        $findStmt = $this->db->prepare('SELECT * FROM findings WHERE page_result_id = ? ORDER BY id ASC');
        $findStmt->execute([$pageResultId]);
        $findings = $findStmt->fetchAll();

        $discInfo = null;
        if (!empty($page['discovered_url_id'])) {
            $uStmt = $this->db->prepare('SELECT discovery_source, depth, retry_count, status FROM discovered_urls WHERE id = ?');
            $uStmt->execute([$page['discovered_url_id']]);
            $discInfo = $uStmt->fetch() ?: null;
        }

        Response::success([
            'page'       => $page,
            'discovery'  => $discInfo,
            'findings'   => $findings,
        ]);
    }
}
