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

        $scanId = (int)$scan['id'];
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

        // Automatic Recovery of Stale Scans across the system
        try {
            $recovery = new ScanRecovery($this->scanRepo, $this->urlRepo, $this->logRepo);
            $recovered = $recovery->recoverStaleScans((int)($this->config['worker']['stale_threshold'] ?? 120));
            if (!empty($recovered)) {
                $logFn('INFO', 'ScanRecovery successfully recovered orphaned scan(s): ' . implode(', ', $recovered), 'recovery');
            }
        } catch (\Throwable $re) {
            // Non-fatal
        }

        $this->scanRepo->updateHeartbeat($scanId, $pid);
        $logFn('INFO', "Worker started under PID {$pid} for scan {$scanId} ({$websiteUrl})", 'worker');

        // Merge scan settings snapshot with application config
        $scanSettings = $this->scanRepo->getSettings($scanId);
        $effectiveConfig = $this->mergeEffectiveConfig($this->config, $scanSettings);

        try {
            $normalizer = new UrlNormalizer();
            $http       = new HttpClient($effectiveConfig['fetching'] ?? []);

            // Reset any orphaned in-flight 'processing' URLs back to 'pending'
            $resetCount = $this->urlRepo->resetProcessingToPending($scanId);
            if ($resetCount > 0) {
                $logFn('INFO', "Recovered {$resetCount} orphaned in-flight URL(s) back to pending", 'recovery');
            }

            // ── PHASE 1: URL DISCOVERY ──────────────────────────────────────────
            // If the scan was paused and resumed, discovery may already be done
            $urlCounts = $this->urlRepo->getCountsByStatus($scanId);
            $totalUrls = array_sum($urlCounts);

            if ($totalUrls === 0) {
                $this->scanRepo->updateStatus($scanId, 'discovering');
                $logFn('INFO', 'Starting URL discovery...', 'discovery');

                $discManager = new DiscoveryManager($http, $normalizer, $effectiveConfig, $logFn);
                $discovered  = $discManager->discover($websiteUrl, $scanSettings);

                if (empty($discovered)) {
                    throw new Exception("Discovery engine could not find any accessible URLs for {$websiteUrl}");
                }

                $maxGlobalUrls = (int)($effectiveConfig['crawler']['default_max_urls'] ?? 500);
                $totalDiscoveredCount = count($discovered);
                $limitReached = $totalDiscoveredCount >= $maxGlobalUrls;
                $excludedCount = max(0, $totalDiscoveredCount - $maxGlobalUrls);

                $insertedCount = $this->urlRepo->insertBatch($scanId, $discovered);
                $this->scanRepo->setDiscoveredCount($scanId, $insertedCount, $limitReached, $excludedCount);
                $logFn('SUCCESS', "Saved {$insertedCount} unique discovered URL(s) to database queue", 'discovery');
            } else {
                $logFn('INFO', "Resuming scan with {$totalUrls} existing URL(s) in queue", 'resume');
            }

            // ── PHASE 2: CONCURRENT SCANNING & DETECTION ────────────────────────
            $this->scanRepo->updateStatus($scanId, 'scanning');
            $logFn('INFO', 'Starting concurrent content inspection...', 'scanning');

            // Load detection rules (DB + config defaults)
            $dbRules = $this->ruleRepo->getActiveRules();
            $rules   = !empty($dbRules) ? $dbRules : ($effectiveConfig['detection']['default_rules'] ?? []);

            $extractor    = new HtmlContentExtractor();
            $detector     = new DetectionEngine($rules, (int)($effectiveConfig['detection']['context_chars'] ?? 150));
            $auditModules = new AuditModules($effectiveConfig['audit_modules'] ?? []);

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
                $effectiveConfig
            );

            $finalState = $scheduler->run($scanId, $projectId, $websiteUrl);

            // ── PHASE 3: FINALIZE ────────────────────────────────────────────────
            if ($finalState === 'completed') {
                $compOk = $this->scanRepo->completeScan($scanId);
                if (!$compOk) {
                    $counts = $this->urlRepo->getCountsByStatus($scanId);
                    $unfinished = ($counts['pending'] ?? 0) + ($counts['queued'] ?? 0) + ($counts['processing'] ?? 0) + ($counts['retry_wait'] ?? 0);
                    $errMsg = "Database guard rejected scan completion: {$unfinished} unfinished URL(s) remain in queue (" . json_encode($counts) . ")";
                    $logFn('ERROR', $errMsg, 'completion');
                    $this->scanRepo->failScan($scanId, $errMsg);
                    exit(1);
                }

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

        } catch (\Throwable $e) {
            $logFn('ERROR', 'Worker fatal exception: ' . $e->getMessage(), 'error');
            $this->scanRepo->failScan($scanId, $e->getMessage());
            exit(1);
        }
    }

    private function mergeEffectiveConfig(array $appConfig, array $scanSettings): array
    {
        $merged = $appConfig;

        if (isset($scanSettings['max_urls'])) {
            $merged['crawler']['default_max_urls'] = (int)$scanSettings['max_urls'];
            $merged['crawler']['max_urls'] = (int)$scanSettings['max_urls'];
        }
        if (isset($scanSettings['max_depth'])) {
            $merged['crawler']['default_max_depth'] = (int)$scanSettings['max_depth'];
            $merged['crawler']['max_depth'] = (int)$scanSettings['max_depth'];
        }
        if (isset($scanSettings['concurrency'])) {
            $merged['fetching']['concurrency'] = (int)$scanSettings['concurrency'];
        }
        if (isset($scanSettings['per_domain_concurrency'])) {
            $merged['fetching']['per_domain_concurrency'] = (int)$scanSettings['per_domain_concurrency'];
        }
        if (isset($scanSettings['request_delay'])) {
            $merged['fetching']['request_delay'] = (int)$scanSettings['request_delay'];
        }
        if (isset($scanSettings['verify_ssl'])) {
            $merged['fetching']['verify_ssl'] = (bool)$scanSettings['verify_ssl'];
        }
        if (isset($scanSettings['request_timeout'])) {
            $merged['crawler']['request_timeout'] = (int)$scanSettings['request_timeout'];
            $merged['fetching']['request_timeout'] = (int)$scanSettings['request_timeout'];
        }
        if (isset($scanSettings['connection_timeout'])) {
            $merged['crawler']['connection_timeout'] = (int)$scanSettings['connection_timeout'];
            $merged['fetching']['connection_timeout'] = (int)$scanSettings['connection_timeout'];
        }
        if (isset($scanSettings['max_retries'])) {
            $merged['fetching']['max_retries'] = (int)$scanSettings['max_retries'];
        }
        if (isset($scanSettings['retry_base_delay'])) {
            $merged['fetching']['retry_base_delay'] = (int)$scanSettings['retry_base_delay'];
        }
        if (isset($scanSettings['max_retry_delay'])) {
            $merged['fetching']['max_retry_delay'] = (int)$scanSettings['max_retry_delay'];
        }
        if (isset($scanSettings['follow_redirects'])) {
            $merged['fetching']['follow_redirects'] = (bool)$scanSettings['follow_redirects'];
        }
        if (isset($scanSettings['max_redirects'])) {
            $merged['fetching']['max_redirects'] = (int)$scanSettings['max_redirects'];
        }
        if (isset($scanSettings['max_response_size'])) {
            $merged['fetching']['max_response_size'] = (int)$scanSettings['max_response_size'];
        }
        if (isset($scanSettings['discovery_methods']) && is_array($scanSettings['discovery_methods'])) {
            $merged['discovery'] = array_merge($merged['discovery'] ?? [], $scanSettings['discovery_methods']);
        }
        if (isset($scanSettings['js_rendering'])) {
            $merged['playwright']['enabled'] = (bool)$scanSettings['js_rendering'];
        }
        if (isset($scanSettings['scan_javascript'])) {
            $merged['detection']['scan_javascript'] = (bool)$scanSettings['scan_javascript'];
        }
        if (isset($scanSettings['scan_css'])) {
            $merged['detection']['scan_css'] = (bool)$scanSettings['scan_css'];
        }
        if (isset($scanSettings['scan_json'])) {
            $merged['detection']['scan_json'] = (bool)$scanSettings['scan_json'];
        }
        if (isset($scanSettings['audit_modules']) && is_array($scanSettings['audit_modules'])) {
            $merged['audit_modules'] = array_merge($merged['audit_modules'] ?? [], $scanSettings['audit_modules']);
        }

        return $merged;
    }
}
