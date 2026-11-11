<?php
/**
 * Scan Controller
 *
 * Handles starting, pausing, resuming, cancelling scans,
 * polling progress, and retrieving audit logs.
 */

namespace LoremChecker\Api;

use LoremChecker\Discovery\UrlNormalizer;
use LoremChecker\Repository\LogRepository;
use LoremChecker\Repository\ProjectRepository;
use LoremChecker\Repository\ScanRepository;
use LoremChecker\Repository\UrlRepository;
use PDO;

class ScanController
{
    private PDO               $db;
    private array             $config;
    private ProjectRepository $projectRepo;
    private ScanRepository    $scanRepo;
    private UrlRepository     $urlRepo;
    private LogRepository     $logRepo;

    public function __construct(PDO $db, array $config)
    {
        $this->db          = $db;
        $this->config      = $config;
        $this->projectRepo = new ProjectRepository($db);
        $this->scanRepo    = new ScanRepository($db);
        $this->urlRepo     = new UrlRepository($db);
        $this->logRepo     = new LogRepository($db);
    }

    /**
     * Start a new audit scan.
     */
    public function start(): void
    {
        $rawUrl = RequestValidator::validateWebsiteUrl($_POST['website_url'] ?? null);
        $projectName = trim($_POST['project_name'] ?? '');

        if ($projectName === '') {
            $host = parse_url($rawUrl, PHP_URL_HOST) ?: 'Website';
            $projectName = $host . ' Audit';
        }

        $normalizer = new UrlNormalizer();
        $normalizedUrl = $normalizer->normalize($rawUrl) ?: $rawUrl;

        // 1. Find or create project
        $projectId = $this->projectRepo->findOrCreate($projectName, $rawUrl, $normalizedUrl);

        // 2. Prepare scan settings snapshot from POST overrides
        $scanSettings = [
            'use_sitemap'  => ($_POST['use_sitemap'] ?? 'auto') === 'auto',
            'max_urls'     => isset($_POST['max_urls']) ? (int)$_POST['max_urls'] : null,
            'max_depth'    => isset($_POST['max_depth']) ? (int)$_POST['max_depth'] : null,
            'concurrency'  => isset($_POST['concurrency']) ? (int)$_POST['concurrency'] : null,
        ];

        // 3. Create scan record
        $scanId = $this->scanRepo->createScan($projectId, $scanSettings);
        $this->logRepo->log($scanId, $projectId, 'INFO', "Scan created for {$rawUrl} ({$projectName})", 'api');

        // 4. Launch background worker
        $workerStarted = $this->spawnWorker($scanId);

        Response::success([
            'project_id'     => (string)$projectId,
            'scan_id'        => $scanId,
            'status'         => 'initializing',
            'message'        => 'Scan initialized and worker dispatched',
            'worker_started' => $workerStarted,
        ]);
    }

    /**
     * Poll scan status and recent logs (called every 3s by frontend).
     */
    public function status(): void
    {
        $projectId = RequestValidator::validateId($_GET['project_id'] ?? $_GET['id'] ?? null, 'project_id');
        $project = $this->projectRepo->getById($projectId);

        if (!$project) {
            Response::error('Project not found.', 'NOT_FOUND', 404);
        }

        // Fetch latest scan for this project
        $scan = $this->scanRepo->getLatestByProjectId($projectId);

        if (!$scan) {
            Response::success([
                'project' => [
                    'id'           => (string)$project['id'],
                    'project_name' => $project['project_name'],
                    'website_url'  => $project['website_url'],
                    'status'       => 'initializing',
                    'progress'     => [
                        'total_urls'  => 0,
                        'processed'   => 0,
                        'clean'       => 0,
                        'lorem_found' => 0,
                        'errors'      => 0,
                        'percentage'  => 0,
                    ],
                    'results'      => [],
                ],
                'logs' => [],
            ]);
            return;
        }

        $scanId = (int)$scan['id'];
        $totalUrls = (int)$scan['discovered_count'];
        $processed = (int)$scan['processed_count'];
        $percentage = $totalUrls > 0 ? round(($processed / $totalUrls) * 100, 2) : 0;

        // Fetch recent logs
        $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 25;
        $dbLogs = $this->logRepo->getLogs($scanId, $limit);
        $formattedLogs = [];
        foreach ($dbLogs as $l) {
            $formattedLogs[] = [
                'timestamp' => $l['created_at'],
                'level'     => $l['log_level'],
                'message'   => $l['message'],
            ];
        }

        Response::success([
            'project' => [
                'id'           => (string)$project['id'],
                'scan_id'      => $scanId,
                'project_name' => $project['project_name'],
                'website_url'  => $project['website_url'],
                'status'       => $scan['status'],
                'created_at'   => $scan['created_at'],
                'updated_at'   => $scan['updated_at'],
                'progress'     => [
                    'total_urls'  => $totalUrls,
                    'processed'   => $processed,
                    'clean'       => (int)$scan['clean_count'],
                    'lorem_found' => (int)$scan['affected_page_count'],
                    'errors'      => (int)$scan['failed_count'],
                    'percentage'  => $percentage,
                ],
                'results'      => [], // Full results fetched via export or results endpoint
            ],
            'logs' => $formattedLogs,
        ]);
    }

    public function pause(): void
    {
        $projectId = RequestValidator::validateId($_POST['project_id'] ?? $_GET['project_id'] ?? null, 'project_id');
        $scan = $this->scanRepo->getLatestByProjectId($projectId);

        if (!$scan) {
            Response::error('No scan found for this project.', 'NOT_FOUND', 404);
        }

        $scanId = (int)$scan['id'];
        if (in_array($scan['status'], ['completed', 'failed', 'paused', 'cancelled'])) {
            Response::error('Scan cannot be paused in its current state (' . $scan['status'] . ').');
        }

        $this->scanRepo->updateStatus($scanId, 'pause_requested');
        $this->logRepo->log($scanId, $projectId, 'WARNING', 'Pause requested by user', 'api');

        Response::success(['message' => 'Pause signal issued to worker']);
    }

    public function resume(): void
    {
        $projectId = RequestValidator::validateId($_POST['project_id'] ?? $_GET['project_id'] ?? null, 'project_id');
        $scan = $this->scanRepo->getLatestByProjectId($projectId);

        if (!$scan) {
            Response::error('No scan found for this project.', 'NOT_FOUND', 404);
        }

        $scanId = (int)$scan['id'];
        if ($scan['status'] !== 'paused' && $scan['status'] !== 'interrupted') {
            Response::error('Scan is not currently paused or interrupted.');
        }

        $this->scanRepo->updateStatus($scanId, 'scanning');
        $this->logRepo->log($scanId, $projectId, 'SUCCESS', 'Scan resumed by user', 'api');

        // Restart worker
        $started = $this->spawnWorker($scanId);

        Response::success(['message' => 'Scan resumed', 'worker_started' => $started]);
    }

    public function cancel(): void
    {
        $projectId = RequestValidator::validateId($_POST['project_id'] ?? $_GET['project_id'] ?? null, 'project_id');
        $scan = $this->scanRepo->getLatestByProjectId($projectId);

        if (!$scan) {
            Response::error('No scan found for this project.', 'NOT_FOUND', 404);
        }

        $scanId = (int)$scan['id'];
        $this->scanRepo->updateStatus($scanId, 'cancel_requested');
        $this->logRepo->log($scanId, $projectId, 'WARNING', 'Cancellation requested by user', 'api');

        Response::success(['message' => 'Cancellation signal issued to worker']);
    }

    public function logs(): void
    {
        $projectId = RequestValidator::validateId($_GET['project_id'] ?? null, 'project_id');
        $scan = $this->scanRepo->getLatestByProjectId($projectId);

        if (!$scan) {
            Response::success(['logs' => []]);
            return;
        }

        $limit = isset($_GET['limit']) ? min(200, max(1, (int)$_GET['limit'])) : 50;
        $dbLogs = $this->logRepo->getLogs((int)$scan['id'], $limit);

        $formatted = [];
        foreach ($dbLogs as $l) {
            $formatted[] = [
                'timestamp' => $l['created_at'],
                'level'     => $l['log_level'],
                'message'   => $l['message'],
            ];
        }

        Response::success(['logs' => $formatted]);
    }

    private function spawnWorker(int $scanId): bool
    {
        $phpBinary = $this->config['worker']['php_binary'] ?? (getenv('PHP_CLI_BINARY') ?: PHP_BINARY);
        $workerScript = $this->config['worker']['script'] ?? (dirname(__DIR__, 2) . '/worker.php');

        if (!file_exists($workerScript)) {
            return false;
        }

        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        if ($isWindows) {
            // Windows background execution
            $cmd = sprintf('start /B "" %s %s %d', escapeshellarg($phpBinary), escapeshellarg($workerScript), $scanId);
            @pclose(@popen($cmd, 'r'));
            return true;
        }

        // Linux/Unix background execution with nohup
        $logFile = dirname(__DIR__, 2) . "/data/logs/scan_{$scanId}_worker.log";
        $cmd = sprintf(
            'nohup %s %s %d > %s 2>&1 &',
            escapeshellcmd($phpBinary),
            escapeshellarg($workerScript),
            $scanId,
            escapeshellarg($logFile)
        );

        @exec($cmd, $out, $ret);
        return $ret === 0;
    }
}
