<?php
/**
 * Scan Worker Orchestrator
 *
 * Drives an entire audit scan lifecycle from command line:
 * 1. Validates scan existence and acquires worker lease
 * 2. Runs DiscoveryManager to find URLs across all sources
 * 3. Batch-inserts discovered URLs into MySQL
 * 4. Executes ScanScheduler to process pages concurrently
 * 5. Handles pause, cancel, and failure transitions cleanly
 */

namespace LoremChecker\Worker;

use Exception;
use LoremChecker\Detection\AuditModules;
use LoremChecker\Detection\DetectionEngine;
use LoremChecker\Detection\HtmlContentExtractor;
use LoremChecker\Discovery\DiscoveryManager;
use LoremChecker\Discovery\UrlNormalizer;
use LoremChecker\HttpClient;
use LoremChecker\Repository\FindingRepository;
use LoremChecker\Repository\LogRepository;
use LoremChecker\Repository\PageResultRepository;
use LoremChecker\Repository\ResourceRepository;
use LoremChecker\Repository\RuleRepository;
use LoremChecker\Repository\ScanRepository;
use LoremChecker\Repository\UrlRepository;
use PDO;

class ScanWorker
{
    private PDO                  $db;
    private array                $config;
    private ScanRepository       $scanRepo;
    private UrlRepository        $urlRepo;
    private PageResultRepository $pageResRepo;
    private ResourceRepository   $resourceRepo;
    private FindingRepository    $findingRepo;
    private LogRepository        $logRepo;
    private RuleRepository       $ruleRepo;

    public function __construct(PDO $db, array $config)
    {
        $this->db           = $db;
        $this->config       = $config;
        $this->scanRepo     = new ScanRepository($db);
        $this->urlRepo      = new UrlRepository($db);
        $this->pageResRepo  = new PageResultRepository($db);
        $this->resourceRepo = new ResourceRepository($db);
        $this->findingRepo  = new FindingRepository($db);
        $this->logRepo      = new LogRepository($db);
        $this->ruleRepo     = new RuleRepository($db);
    }

    /**
     * Execute scan by scan ID.
     */
    public function execute(int $scanId): void
    {
        $scan = $this->scanRepo->getById($scanId);
        if (!$scan) {
            echo "Scan ID {$scanId} not found in database.\n";
            exit(1);
        }

        $projectId  = (int)$scan['project_id'];
        $websiteUrl = $scan['website_url'];
        $pid        = getmypid();

        // 1. Worker locking: prevent duplicate concurrent workers for the same scan
        if (in_array($scan['status'], ['scanning', 'discovering']) && !empty($scan['last_heartbeat_at'])) {
            $timeSinceHeartbeat = time() - strtotime($scan['last_heartbeat_at']);
            $staleThreshold = (int)($this->config['worker']['stale_threshold'] ?? 120);

            if ($timeSinceHeartbeat < $staleThreshold && $scan['worker_pid'] && $scan['worker_pid'] !== $pid) {
                $this->logRepo->log($scanId, $projectId, 'WARNING', "Worker conflict: Scan {$scanId} is already being processed by PID {$scan['worker_pid']}", 'worker');
                echo "Scan {$scanId} is already running under PID {$scan['worker_pid']}.\n";
                exit(0);
            }
        }

        // Initialize logging helper
        $logFn = function (string $level, string $message, string $eventType = 'general') use ($scanId, $projectId) {
            $this->logRepo->log($scanId, $projectId, $level, $message, $eventType);
            echo "[" . date('Y-m-d H:i:s') . "] [{$level}] {$message}\n";
        };

        $this->scanRepo->updateHeartbeat($scanId, $pid);
        $logFn('INFO', "Worker started under PID {$pid} for scan {$scanId} ({$websiteUrl})", 'worker');

        try {
            $normalizer = new UrlNormalizer();
            $http       = new HttpClient($this->config['fetching'] ?? []);

            // ── PHASE 1: URL DISCOVERY ──────────────────────────────────────────
            // If the scan was paused and resumed, discovery may already be done
            $urlCounts = $this->urlRepo->getCountsByStatus($scanId);
            $totalUrls = array_sum($urlCounts);

            if ($totalUrls === 0) {
                $this->scanRepo->updateStatus($scanId, 'discovering');
                $logFn('INFO', 'Starting URL discovery...', 'discovery');

                $discManager = new DiscoveryManager($http, $normalizer, $this->config, $logFn);
                $discovered  = $discManager->discover($websiteUrl);

                if (empty($discovered)) {
                    throw new Exception("Discovery engine could not find any accessible URLs for {$websiteUrl}");
                }

                $insertedCount = $this->urlRepo->insertBatch($scanId, $discovered);
                $this->scanRepo->setDiscoveredCount($scanId, $insertedCount);
                $logFn('SUCCESS', "Saved {$insertedCount} unique discovered URL(s) to database queue", 'discovery');
            } else {
                $logFn('INFO', "Resuming scan with {$totalUrls} existing URL(s) in queue", 'resume');
            }

            // ── PHASE 2: CONCURRENT SCANNING & DETECTION ────────────────────────
            $this->scanRepo->updateStatus($scanId, 'scanning');
            $logFn('INFO', 'Starting concurrent content inspection...', 'scanning');

            // Load detection rules (DB + config defaults)
            $dbRules = $this->ruleRepo->getActiveRules();
            $rules   = !empty($dbRules) ? $dbRules : ($this->config['detection']['default_rules'] ?? []);

            $extractor    = new HtmlContentExtractor();
            $detector     = new DetectionEngine($rules, (int)($this->config['detection']['context_chars'] ?? 150));
            $auditModules = new AuditModules();

            $scheduler = new ScanScheduler(
                $this->db,
                $http,
                $normalizer,
                $extractor,
                $detector,
                $auditModules,
                $this->scanRepo,
                $this->urlRepo,
                $this->pageResRepo,
                $this->resourceRepo,
                $this->findingRepo,
                $this->logRepo,
                $this->config
            );

            $finalState = $scheduler->run($scanId, $projectId, $websiteUrl);

            // ── PHASE 3: FINALIZE ────────────────────────────────────────────────
            if ($finalState === 'completed') {
                $this->scanRepo->completeScan($scanId);
                $summary = $this->scanRepo->getById($scanId);
                $logFn('SUCCESS', sprintf(
                    'Scan completed. Discovered: %d, Processed: %d, Clean: %d, Affected: %d, Failed: %d, Total Findings: %d',
                    $summary['discovered_count'],
                    $summary['processed_count'],
                    $summary['clean_count'],
                    $summary['affected_page_count'],
                    $summary['failed_count'],
                    $summary['total_findings']
                ), 'completion');
            } elseif ($finalState === 'paused') {
                $logFn('WARNING', 'Worker stopped cleanly on pause request.', 'worker');
            } elseif ($finalState === 'cancelled') {
                $logFn('WARNING', 'Worker stopped cleanly on cancellation request.', 'worker');
            }

        } catch (Exception $e) {
            $logFn('ERROR', 'Worker fatal exception: ' . $e->getMessage(), 'error');
            $this->scanRepo->failScan($scanId, $e->getMessage());
            exit(1);
        }
    }
}
