<?php
/**
 * Scan Scheduler
 *
 * Coordinates concurrent batch processing of URLs:
 * - Controlled concurrency via cURL Multi
 * - Real-time incremental persistence of page results and findings
 * - Per-page asset inspection (external JS/CSS/JSON)
 * - Safe interruption on pause/cancel requests
 */

namespace LoremChecker\Worker;

use LoremChecker\Detection\AuditModules;
use LoremChecker\Detection\DetectionEngine;
use LoremChecker\Detection\HtmlContentExtractor;
use LoremChecker\Discovery\UrlNormalizer;
use LoremChecker\HttpClient;
use LoremChecker\Repository\FindingRepository;
use LoremChecker\Repository\LogRepository;
use LoremChecker\Repository\PageResultRepository;
use LoremChecker\Repository\ResourceRepository;
use LoremChecker\Repository\ScanRepository;
use LoremChecker\Repository\UrlRepository;
use PDO;

class ScanScheduler
{
    private PDO                  $db;
    private HttpClient           $http;
    private UrlNormalizer        $normalizer;
    private HtmlContentExtractor $extractor;
    private DetectionEngine      $detector;
    private AuditModules         $auditModules;
    private ScanRepository       $scanRepo;
    private UrlRepository        $urlRepo;
    private PageResultRepository $pageResRepo;
    private ResourceRepository   $resourceRepo;
    private FindingRepository    $findingRepo;
    private LogRepository        $logRepo;
    private array                $config;

    public function __construct(
        PDO $db,
        HttpClient $http,
        UrlNormalizer $normalizer,
        HtmlContentExtractor $extractor,
        DetectionEngine $detector,
        AuditModules $auditModules,
        ScanRepository $scanRepo,
        UrlRepository $urlRepo,
        PageResultRepository $pageResRepo,
        ResourceRepository $resourceRepo,
        FindingRepository $findingRepo,
        LogRepository $logRepo,
        array $config = []
    ) {
        $this->db           = $db;
        $this->http         = $http;
        $this->normalizer   = $normalizer;
        $this->extractor    = $extractor;
        $this->detector     = $detector;
        $this->auditModules = $auditModules;
        $this->scanRepo     = $scanRepo;
        $this->urlRepo      = $urlRepo;
        $this->pageResRepo  = $pageResRepo;
        $this->resourceRepo = $resourceRepo;
        $this->findingRepo  = $findingRepo;
        $this->logRepo      = $logRepo;
        $this->config       = $config;
    }

    /**
     * Run the scheduler loop until all pending URLs for this scan are processed,
     * or until paused/cancelled.
     *
     * @return string Final state: 'completed' | 'paused' | 'cancelled'
     */
    public function run(int $scanId, int $projectId, string $websiteUrl): string
    {
        $concurrency = (int)($this->config['fetching']['concurrency'] ?? 5);
        $batchSize   = max(10, $concurrency * 2);

        while (true) {
            // 1. Check scan state before fetching next batch
            $scan = $this->scanRepo->getById($scanId);
            if (!$scan) {
                return 'cancelled';
            }

            if ($scan['status'] === 'pause_requested' || $scan['status'] === 'paused') {
                $this->scanRepo->updateStatus($scanId, 'paused');
                $this->logRepo->log($scanId, $projectId, 'WARNING', 'Scan paused as requested', 'scheduler');
                return 'paused';
            }

            if ($scan['status'] === 'cancel_requested' || $scan['status'] === 'cancelled') {
                $this->scanRepo->updateStatus($scanId, 'cancelled');
                $this->logRepo->log($scanId, $projectId, 'WARNING', 'Scan cancelled as requested', 'scheduler');
                return 'cancelled';
            }

            // Update heartbeat
            $this->scanRepo->updateHeartbeat($scanId, getmypid());

            // 2. Fetch next batch of pending URLs
            $batch = $this->urlRepo->fetchPendingBatch($scanId, $batchSize);
            if (empty($batch)) {
                // Safeguard against premature completion (e.g. Scan 5 issue):
                // Verify whether any unfinished URLs remain in pending, queued, processing, or retry_wait.
                $counts     = $this->urlRepo->getCountsByStatus($scanId);
                $pending    = ($counts['pending'] ?? 0) + ($counts['queued'] ?? 0);
                $processing = $counts['processing'] ?? 0;
                $retryWait  = $counts['retry_wait'] ?? 0;
                $unfinished = $pending + $processing + $retryWait;

                if ($unfinished === 0) {
                    // All discovered URLs have reached terminal states (completed, failed, etc.)
                    break;
                }

                // If only retry_wait URLs remain, wait for them to mature rather than completing
                if ($retryWait > 0 && $pending === 0 && $processing === 0) {
                    $this->logRepo->log(
                        $scanId,
                        $projectId,
                        'INFO',
                        "Waiting for {$retryWait} retry_wait URL(s) to become eligible for next attempt...",
                        'scheduler'
                    );
                    sleep(2);
                    continue;
                }

                // If dangling processing URLs exist with no active work, reset them to pending
                if ($processing > 0 && $pending === 0 && $retryWait === 0) {
                    $reset = $this->urlRepo->resetProcessingToPending($scanId);
                    if ($reset > 0) {
                        $this->logRepo->log(
                            $scanId,
                            $projectId,
                            'WARNING',
                            "Recovered {$reset} dangling processing URL(s) back to pending",
                            'recovery'
                        );
                        continue;
                    }
                }

                sleep(1);
                continue;
            }

            // Mark batch as 'processing'
            $urlMap = []; // url_string => urlRecord
            $urlStrings = [];
            foreach ($batch as $record) {
                $this->urlRepo->markStatus((int)$record['id'], 'processing');
                $urlMap[$record['original_url']] = $record;
                $urlStrings[] = $record['original_url'];
            }

            // 3. Process concurrently via HttpClient fetchMulti
            $this->http->fetchMulti(
                $urlStrings,
                function (string $url, array $response) use ($scanId, $projectId, $websiteUrl, $urlMap) {
                    try {
                        $this->handlePageCompleted($scanId, $projectId, $websiteUrl, $urlMap[$url], $response);
                    } catch (\Throwable $e) {
                        $this->logRepo->log(
                            $scanId,
                            $projectId,
                            'ERROR',
                            "Unexpected error processing page [{$url}]: " . $e->getMessage() . "\n" . $e->getTraceAsString(),
                            'error',
                            $url
                        );
                        try {
                            $uId = (int)$urlMap[$url]['id'];
                            $this->urlRepo->markStatus($uId, 'failed', $url, 'Processing exception: ' . $e->getMessage());
                            $this->pageResRepo->saveResult([
                                'scan_id'           => $scanId,
                                'discovered_url_id' => $uId,
                                'requested_url'     => $url,
                                'final_url'         => $url,
                                'http_status'       => null,
                                'content_type'      => 'unknown',
                                'processing_status' => 'failed',
                                'audit_status'      => 'clean',
                                'finding_count'     => 0,
                                'duration_ms'       => 0,
                                'retry_count'       => (int)($urlMap[$url]['retry_count'] ?? 0),
                                'error_type'        => 'worker_error',
                                'error_message'     => $e->getMessage(),
                                'has_lorem_ipsum'   => 0,
                            ]);
                            $this->scanRepo->recordPageResult($scanId, 'failed', 0);
                        } catch (\Throwable $recEx) {}
                    }
                },
                [
                    'concurrency' => $concurrency,
                    'timeout'     => $this->config['crawler']['request_timeout'] ?? 30,
                ]
            );
        }

        return 'completed';
    }

    private function handlePageCompleted(int $scanId, int $projectId, string $websiteUrl, array $urlRecord, array $response): void
    {
        $urlId       = (int)$urlRecord['id'];
        $url         = $urlRecord['original_url'];
        $finalUrl    = $response['final_url'] ?? $url;
        $httpStatus  = $response['http_status'];
        $errorType   = $response['error_type'];
        $errorMsg    = $response['error_message'];
        $contentType = $response['content_type'];
        $content     = $response['content'] ?? '';
        $durationMs  = $response['duration_ms'] ?? 0;

        // 1. Evaluate technical failures
        if ($errorType !== null || ($httpStatus !== null && $httpStatus >= 400)) {
            // Check if retryable using improved HttpClient classification & response metadata
            $curlErrno      = (int)($response['curl_errno'] ?? 0);
            $isRetryable    = !empty($response['is_retryable']) || HttpClient::isRetryable($errorType, $httpStatus, $curlErrno);
            $maxRetries     = (int)($this->config['fetching']['max_retries'] ?? 3);
            $currentRetries = (int)($urlRecord['retry_count'] ?? 0);

            if ($isRetryable && $currentRetries < $maxRetries) {
                $maxDelay = (int)($this->config['fetching']['max_retry_delay'] ?? 60);
                $raDelay  = HttpClient::parseRetryAfter($response['headers']['retry-after'] ?? null, $maxDelay);

                if ($raDelay > 0) {
                    $delaySec = $raDelay;
                } else {
                    $baseDelay = (int)($this->config['fetching']['retry_base_delay'] ?? 2);
                    $delaySec  = min($maxDelay, (int)(pow(2, $currentRetries) * $baseDelay + rand(0, 2)));
                }

                $this->urlRepo->scheduleRetry($urlId, $errorMsg ?: "HTTP {$httpStatus}", $delaySec);
                $this->logRepo->log(
                    $scanId,
                    $projectId,
                    'WARNING',
                    "Scheduled retry (" . ($currentRetries + 1) . "/{$maxRetries}) in {$delaySec}s on {$url}: {$errorMsg}",
                    'fetch',
                    $url
                );
                return;
            }

            // Permanent failure or exhausted retries
            $pStatus = 'failed';
            if ($errorType === 'cloudflare_blocked') {
                $pStatus = 'cloudflare_blocked';
            } elseif ($httpStatus === 429 || $errorType === 'rate_limited') {
                $pStatus = 'rate_limited';
            } elseif ($httpStatus === 404) {
                $pStatus = 'not_found';
            } elseif ($errorType === 'timeout') {
                $pStatus = 'timeout';
            } elseif ($errorType === 'ssl_error') {
                $pStatus = 'ssl_error';
            }

            $this->db->beginTransaction();
            try {
                $this->urlRepo->markStatus($urlId, 'failed', $finalUrl, $errorMsg);
                $this->pageResRepo->saveResult([
                    'scan_id'           => $scanId,
                    'discovered_url_id' => $urlId,
                    'requested_url'     => $url,
                    'final_url'         => $finalUrl,
                    'http_status'       => $httpStatus,
                    'content_type'      => $contentType,
                    'processing_status' => $pStatus,
                    'finding_count'     => 0,
                    'duration_ms'       => $durationMs,
                    'retry_count'       => $currentRetries,
                    'error_type'        => $errorType ?: 'http_error',
                    'error_message'     => $errorMsg ?: "HTTP {$httpStatus}",
                    'has_lorem_ipsum'   => 0,
                ]);
                $this->scanRepo->recordPageResult($scanId, $pStatus, 0);
                $this->db->commit();
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                $this->logRepo->log(
                    $scanId,
                    $projectId,
                    'ERROR',
                    "Database write error on failed page [ID: {$urlId}, URL: {$url}]: " . $e->getMessage(),
                    'db',
                    $url
                );

                // RECOVERY: URL must not remain in 'processing' state!
                try {
                    $this->urlRepo->markStatus($urlId, 'failed', $finalUrl, "DB write error: " . $e->getMessage());
                } catch (\Throwable $recoveryEx) {
                    throw new \RuntimeException(
                        "Critical DB failure: could not mark URL {$urlId} failed after transaction rollback: " . $e->getMessage(),
                        0,
                        $e
                    );
                }
            }

            $this->logRepo->log($scanId, $projectId, 'ERROR', "Failed [{$httpStatus}]: {$url} - {$errorMsg}", 'fetch', $url);
            return;
        }

        // 2. Successful fetch: extract content nodes and external assets
        // Check if JS rendering is requested via Playwright
        $renderedWithJs = false;
        if (!empty($this->config['playwright']['enabled'])) {
            $rendered = $this->renderWithPlaywright($finalUrl);
            if ($rendered !== null && !empty($rendered['html'])) {
                $content = $rendered['html'];
                $renderedWithJs = true;
                $this->logRepo->log($scanId, $projectId, 'INFO', "Rendered with Playwright: {$finalUrl}", 'render', $finalUrl);
            }
        }

        $extracted = $this->extractor->extract($content, $finalUrl);
        $findings  = $this->detector->scanHtmlNodes($extracted['nodes'], $finalUrl);

        // Run optional audit modules (missing title, empty page, heading structure, alt text, broken links)
        $auditFindings = $this->auditModules->auditPage($content, $finalUrl, $extracted);
        if (!empty($extracted['links'])) {
            $linkFindings = $this->auditModules->auditLinks($extracted['links'], $finalUrl, $this->http);
            $auditFindings = array_merge($auditFindings, $linkFindings);
        }

        $allFindings   = array_merge($findings, $auditFindings);

        $hasLorem     = !empty($findings);
        $hasAudit     = !empty($auditFindings);
        $findingCount = count($allFindings);

        $loremCount   = count($findings);
        $auditCount   = count($auditFindings);

        // Result classification: If ANY finding is detected, status is findings_detected (Needs Attention)
        $hasFindings  = ($findingCount > 0);
        $pageStatus   = $hasFindings ? 'findings_detected' : 'clean';
        $auditStatus  = $hasAudit ? ($hasLorem ? 'findings_detected' : 'audit_issues') : ($hasLorem ? 'lorem_only' : 'clean');

        // Compute content hash for duplicate page detection
        $contentHash  = $this->auditModules->computeContentHash($extracted['full_text'] ?? '');

        // 3. Persist page result and findings incrementally
        $pageResultId = null;
        $this->db->beginTransaction();
        try {
            $this->urlRepo->markStatus($urlId, 'completed', $finalUrl);
            $pageResultId = $this->pageResRepo->saveResult([
                'scan_id'           => $scanId,
                'discovered_url_id' => $urlId,
                'requested_url'     => $url,
                'final_url'         => $finalUrl,
                'http_status'       => $httpStatus,
                'content_type'      => $contentType,
                'processing_status' => $pageStatus,
                'audit_status'      => $auditStatus,
                'finding_count'     => $findingCount,
                'duration_ms'       => $durationMs,
                'retry_count'       => (int)($urlRecord['retry_count'] ?? 0),
                'error_type'        => null,
                'error_message'     => null,
                'page_title'        => $extracted['page_title'] ?? null,
                'word_count'        => $extracted['word_count'] ?? null,
                'has_lorem_ipsum'   => $hasLorem ? 1 : 0,
                'content_hash'      => $contentHash,
                'rendered_with_js'  => $renderedWithJs ? 1 : 0,
            ]);

            if (!empty($allFindings) && $pageResultId) {
                $this->findingRepo->insertBatch($scanId, $pageResultId, null, $allFindings);
            }

            $this->scanRepo->recordPageResult(
                $scanId,
                $pageStatus,
                $findingCount,
                $loremCount,
                $auditCount,
                0 // Resource findings recorded separately in inspectDiscoveredResources
            );
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logRepo->log(
                $scanId,
                $projectId,
                'ERROR',
                "Database write error on page [ID: {$urlId}, URL: {$url}]: " . $e->getMessage(),
                'db',
                $url
            );

            // RECOVERY: URL must not remain trapped in 'processing' status!
            // If retry attempts remain, schedule retry; otherwise mark failed.
            $maxRetries     = (int)($this->config['fetching']['max_retries'] ?? 3);
            $currentRetries = (int)($urlRecord['retry_count'] ?? 0);

            try {
                if ($currentRetries < $maxRetries) {
                    $this->urlRepo->scheduleRetry($urlId, "DB write error: " . $e->getMessage(), 5);
                } else {
                    $this->urlRepo->markStatus($urlId, 'failed', $finalUrl, "DB write error: " . $e->getMessage());
                }
            } catch (\Throwable $recoveryEx) {
                throw new \RuntimeException(
                    "Critical DB failure: could not transition URL {$urlId} out of processing after transaction rollback: " . $e->getMessage(),
                    0,
                    $e
                );
            }
            return;
        }

        // 4. Log progress
        if ($hasLorem) {
            $this->logRepo->log($scanId, $projectId, 'WARNING', "Placeholder content found on {$url} ({$findingCount} finding(s))", 'detection', $url);
        } else {
            $this->logRepo->log($scanId, $projectId, 'INFO', "Clean: {$url}", 'scan', $url);
        }

        // 5. Inspect discovered external assets (JS, CSS, JSON) if enabled
        if (!empty($extracted['resources']) && (
            !empty($this->config['detection']['scan_javascript']) ||
            !empty($this->config['detection']['scan_css']) ||
            !empty($this->config['detection']['scan_json'])
        )) {
            $this->inspectDiscoveredResources($scanId, $projectId, $pageResultId, $finalUrl, $websiteUrl, $extracted['resources']);
        }
    }

    private function inspectDiscoveredResources(
        int $scanId,
        int $projectId,
        int $pageResultId,
        string $pageUrl,
        string $websiteUrl,
        array $resources
    ): void {
        foreach ($resources as $res) {
            $rawUrl = $res['url'];
            $type   = $res['type'];

            $resolved = $this->normalizer->resolveRelative($rawUrl, $pageUrl);
            if (!$resolved) {
                continue;
            }

            // Only scan internal/same-scope resources or assets to avoid scanning huge 3rd-party CDNs
            if (!$this->normalizer->isSameScope($resolved, $websiteUrl, 'host')) {
                continue;
            }

            $norm = $this->normalizer->normalize($resolved, $pageUrl);
            if (!$norm) {
                continue;
            }

            $reg = $this->resourceRepo->findOrCreate($scanId, $pageResultId, $resolved, $norm, $type);
            $resourceId = $reg['id'];

            // If already fetched in this scan, skip re-fetching
            if (!$reg['is_new'] && $reg['status'] === 'completed') {
                continue;
            }

            $resFetch = $this->http->fetch($resolved, [
                'timeout'     => $this->config['crawler']['resource_timeout'] ?? 20,
                'max_retries' => 1,
                'apply_delay' => false,
            ]);

            if ($resFetch['error_type'] !== null || $resFetch['http_status'] !== 200 || empty($resFetch['content'])) {
                $this->resourceRepo->markFailed($resourceId, $resFetch['error_message'] ?: "HTTP {$resFetch['http_status']}");
                continue;
            }

            $resBody = $resFetch['content'];
            $resFindings = [];

            if ($type === 'javascript' && !empty($this->config['detection']['scan_javascript'])) {
                $resFindings = $this->detector->scanJavaScript($resBody, $resolved, $pageUrl);
            } elseif ($type === 'css' && !empty($this->config['detection']['scan_css'])) {
                $resFindings = $this->detector->scanCss($resBody, $resolved, $pageUrl);
            } elseif ($type === 'json' && !empty($this->config['detection']['scan_json'])) {
                $resFindings = $this->detector->scanJson($resBody, $resolved, $pageUrl);
            }

            $this->resourceRepo->markCompleted($resourceId, [
                'content_type'  => $resFetch['content_type'],
                'http_status'   => $resFetch['http_status'],
                'response_size' => $resFetch['response_size'],
                'duration_ms'   => $resFetch['duration_ms'],
                'finding_count' => count($resFindings),
            ]);

            if (!empty($resFindings)) {
                $this->findingRepo->insertBatch($scanId, $pageResultId, $resourceId, $resFindings);
                $this->scanRepo->recordPageResult($scanId, 'clean', count($resFindings), 0, 0, count($resFindings));
                $this->logRepo->log($scanId, $projectId, 'WARNING', "Placeholder found in external {$type}: {$resolved}", 'detection', $resolved);
            }
        }
    }

    private function renderWithPlaywright(string $url): ?array
    {
        $script = $this->config['playwright']['script'] ?? (dirname(__DIR__, 2) . '/playwright/render-page.js');
        if (!file_exists($script)) {
            return null;
        }

        $nodeBinary = getenv('NODE_BINARY') ?: 'node';
        $timeout = (int)($this->config['playwright']['timeout'] ?? 30000);

        $inputJson = json_encode([
            'url'     => $url,
            'timeout' => $timeout,
        ]);

        $cmd = sprintf('%s %s %s', escapeshellcmd($nodeBinary), escapeshellarg($script), escapeshellarg($inputJson));

        $output = [];
        $returnCode = 0;
        @exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 || empty($output)) {
            return null;
        }

        $result = @json_decode(implode("\n", $output), true);
        if (is_array($result) && !empty($result['success'])) {
            return $result;
        }

        return null;
    }
}
