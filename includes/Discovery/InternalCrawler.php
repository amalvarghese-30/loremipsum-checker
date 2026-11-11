<?php
/**
 * Internal Link Crawler (BFS)
 *
 * Implements Breadth-First Search link crawling:
 * - Scope checking: restricts exploration strictly to within-scope URLs
 * - Tracks visited URLs and crawl depth
 * - Discovers pagination links (including rel="next")
 * - Configurable URL limit and maximum depth
 * - Robust DOMDocument HTML parsing
 */

namespace LoremChecker\Discovery;

use DOMDocument;
use DOMXPath;
use LoremChecker\HttpClient;

class InternalCrawler
{
    private HttpClient    $http;
    private UrlNormalizer $normalizer;
    private array         $config;
    private ?callable     $logFn;

    public function __construct(HttpClient $http, UrlNormalizer $normalizer, array $config = [], ?callable $logFn = null)
    {
        $this->http       = $http;
        $this->normalizer = $normalizer;
        $this->config     = $config;
        $this->logFn      = $logFn;
    }

    /**
     * Crawl the website starting from the seed URL.
     *
     * @param string $startUrl Seed URL
     * @param int    $maxUrls  Max URLs to discover
     * @param int    $maxDepth Max crawl depth
     * @return array Standardized URL records
     */
    public function crawl(string $startUrl, int $maxUrls = 500, int $maxDepth = 3): array
    {
        $normalizedStart = $this->normalizer->normalize($startUrl);
        if (!$normalizedStart) {
            return [];
        }

        $records = [];
        $visited = []; // normalized_hash => true
        // Queue holds [url, depth, sourceUrl]
        $queue   = [[
            'url'       => $startUrl,
            'norm'      => $normalizedStart,
            'depth'     => 0,
            'sourceUrl' => 'seed',
        ]];

        $startHash = $this->normalizer->getUrlHash($normalizedStart);
        $visited[$startHash] = true;

        $records[] = [
            'url'            => $startUrl,
            'normalized_url' => $normalizedStart,
            'url_hash'       => $startHash,
            'source'         => 'seed',
            'depth'          => 0,
            'metadata'       => [],
        ];

        while (!empty($queue) && count($records) < $maxUrls) {
            $item = array_shift($queue);
            $currentUrl   = $item['url'];
            $currentDepth = $item['depth'];

            if ($currentDepth >= $maxDepth) {
                continue;
            }

            $response = $this->http->fetch($currentUrl, [
                'timeout'     => $this->config['crawler']['request_timeout'] ?? 30,
                'max_retries' => 1,
                'apply_delay' => true,
            ]);

            if ($response['error_type'] !== null || $response['http_status'] !== 200 || empty($response['content'])) {
                continue;
            }

            // Only extract links from HTML documents
            if ($response['content_type'] !== 'html' && !str_contains($response['content'], '<html')) {
                continue;
            }

            $extractedLinks = $this->extractLinks($response['content'], $currentUrl, $startUrl);

            foreach ($extractedLinks as $linkInfo) {
                if (count($records) >= $maxUrls) {
                    break 2;
                }

                $linkUrl    = $linkInfo['url'];
                $sourceType = $linkInfo['is_pagination'] ? 'pagination' : 'internal_crawler';
                $normalized = $this->normalizer->normalize($linkUrl, $currentUrl);

                if (!$normalized) {
                    continue;
                }

                $hash = $this->normalizer->getUrlHash($normalized);
                if (isset($visited[$hash])) {
                    continue;
                }

                $visited[$hash] = true;
                $newDepth = $currentDepth + 1;

                $records[] = [
                    'url'            => $linkUrl,
                    'normalized_url' => $normalized,
                    'url_hash'       => $hash,
                    'source'         => $sourceType,
                    'depth'          => $newDepth,
                    'metadata'       => ['found_on' => $currentUrl],
                ];

                if ($newDepth < $maxDepth) {
                    $queue[] = [
                        'url'       => $linkUrl,
                        'norm'      => $normalized,
                        'depth'     => $newDepth,
                        'sourceUrl' => $currentUrl,
                    ];
                }
            }

            if (count($records) % 25 === 0) {
                $this->log('INFO', 'Crawl progress: ' . count($records) . ' URLs discovered...', 'crawler');
            }
        }

        return $records;
    }

    private function extractLinks(string $html, string $currentUrl, string $websiteUrl): array
    {
        $links = [];
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // 1. Pagination <link rel="next">
        $nextNodes = $xpath->query('//link[@rel="next"]');
        foreach ($nextNodes as $node) {
            $href = trim($node->getAttribute('href'));
            if ($href !== '') {
                $resolved = $this->normalizer->resolveRelative($href, $currentUrl);
                if ($resolved && $this->normalizer->isSameScope($resolved, $websiteUrl, 'host')) {
                    $links[] = ['url' => $resolved, 'is_pagination' => true];
                }
            }
        }

        // 2. All <a> anchors
        $anchors = $dom->getElementsByTagName('a');
        foreach ($anchors as $anchor) {
            $href = trim($anchor->getAttribute('href'));
            if ($href === '' || $href[0] === '#' || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:')) {
                continue;
            }

            // Exclude static asset extensions from crawling as pages (they are scanned as resources)
            if (preg_match('/\.(jpg|jpeg|png|gif|webp|svg|ico|pdf|zip|tar|gz|mp4|mp3|exe|docx|xlsx)$/i', $href)) {
                continue;
            }

            $rel = strtolower($anchor->getAttribute('rel'));
            $isPagination = ($rel === 'next' || str_contains(strtolower($anchor->getAttribute('class')), 'next') || str_contains(strtolower($anchor->getAttribute('class')), 'pagination'));

            $resolved = $this->normalizer->resolveRelative($href, $currentUrl);
            if ($resolved && $this->normalizer->isSameScope($resolved, $websiteUrl, 'host')) {
                $links[] = ['url' => $resolved, 'is_pagination' => $isPagination];
            }
        }

        return $links;
    }

    private function log(string $level, string $message, string $eventType = 'crawler'): void
    {
        if ($this->logFn !== null) {
            ($this->logFn)($level, $message, $eventType);
        }
    }
}
