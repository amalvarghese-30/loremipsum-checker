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
            'has_lorem_ipsum' => isset($_GET['has_lorem']) ? (bool)$_GET['has_lorem'] : null,
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

    public function compare(): void
    {
        $prevScanId = RequestValidator::validateId($_GET['previous_scan_id'] ?? null, 'previous_scan_id');
        $currScanId = RequestValidator::validateId($_GET['current_scan_id'] ?? null, 'current_scan_id');

        $comparison = $this->findingRepo->compareScans($prevScanId, $currScanId);
        Response::success(['comparison' => $comparison]);
    }
}
