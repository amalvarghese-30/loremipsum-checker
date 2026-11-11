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
                // All pending URLs are done!
                break;
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
                    $this->handlePageCompleted($scanId, $projectId, $websiteUrl, $urlMap[$url], $response);
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
            // Check if retryable
            $retryableCodes = $this->config['fetching']['retryable_http_codes'] ?? [408, 429, 500, 502, 503, 504];
            $maxRetries     = (int)($this->config['fetching']['max_retries'] ?? 3);
            $currentRetries = (int)($urlRecord['retry_count'] ?? 0);

            if (($errorType === 'timeout' || in_array($httpStatus, $retryableCodes, true)) && $currentRetries < $maxRetries) {
                $this->urlRepo->incrementRetry($urlId, $errorMsg ?: "HTTP {$httpStatus}");
                $this->logRepo->log($scanId, $projectId, 'WARNING', "Retrying ({$currentRetries}/{$maxRetries}) on {$url}: {$errorMsg}", 'fetch', $url);
                return;
            }

            // Permanent failure or exhausted retries
            $pStatus = 'failed';
            if ($errorType === 'cloudflare_blocked') {
                $pStatus = 'cloudflare_blocked';
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
            } catch (\Exception $e) {
                $this->db->rollBack();
            }

            $this->logRepo->log($scanId, $projectId, 'ERROR', "Failed [{$httpStatus}]: {$url} - {$errorMsg}", 'fetch', $url);
            return;
        }

        // 2. Successful fetch: extract content nodes and external assets
        $extracted = $this->extractor->extract($content, $finalUrl);
        $findings  = $this->detector->scanHtmlNodes($extracted['nodes'], $finalUrl);

        // Run optional audit modules (missing title, empty page, heading structure, alt text)
        $auditFindings = $this->auditModules->auditPage($content, $finalUrl, $extracted);
        $allFindings   = array_merge($findings, $auditFindings);

        $hasLorem     = !empty($findings);
        $findingCount = count($allFindings);
        $pageStatus   = $hasLorem ? 'findings_detected' : 'clean';

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
                'finding_count'     => $findingCount,
                'duration_ms'       => $durationMs,
                'retry_count'       => (int)($urlRecord['retry_count'] ?? 0),
                'error_type'        => null,
                'error_message'     => null,
                'page_title'        => $extracted['page_title'],
                'word_count'        => $extracted['word_count'],
                'has_lorem_ipsum'   => $hasLorem ? 1 : 0,
            ]);

            if (!empty($allFindings) && $pageResultId) {
                $this->findingRepo->insertBatch($scanId, $pageResultId, null, $allFindings);
            }

            $this->scanRepo->recordPageResult($scanId, $pageStatus, $findingCount);
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logRepo->log($scanId, $projectId, 'ERROR', "Database write error on {$url}: " . $e->getMessage(), 'db');
            return;
        }

        // 4. Log progress
        if ($hasLorem) {
            $this->logRepo->log($scanId, $projectId, 'WARNING', "Placeholder content found on {$url} ({$findingCount} finding(s))", 'detection', $url);
        } else {
            $this->logRepo->log($scanId, $projectId, 'INFO', "Clean: {$url}", 'scan', $url);
        }

        // 5. Inspect discovered external assets (JS, CSS) if enabled
        if (!empty($extracted['resources']) && ($this->config['detection']['scan_javascript'] || $this->config['detection']['scan_css'])) {
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

            if ($type === 'javascript') {
                $resFindings = $this->detector->scanJavaScript($resBody, $resolved, $pageUrl);
            } elseif ($type === 'css') {
                $resFindings = $this->detector->scanCss($resBody, $resolved, $pageUrl);
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
                $this->logRepo->log($scanId, $projectId, 'WARNING', "Placeholder found in external {$type}: {$resolved}", 'detection', $resolved);
            }
        }
    }
}
