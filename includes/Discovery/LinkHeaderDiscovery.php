<?php
/**
 * HTTP Link Header Sitemap Discovery
 *
 * Inspects HTTP response headers (e.g., from the homepage)
 * for sitemap declarations:
 *   Link: <https://example.com/sitemap.xml>; rel="sitemap"
 */

namespace LoremChecker\Discovery;

use LoremChecker\HttpClient;

class LinkHeaderDiscovery
{
    private HttpClient    $http;
    private UrlNormalizer $normalizer;
    private ?callable     $logFn;

    public function __construct(HttpClient $http, UrlNormalizer $normalizer, ?callable $logFn = null)
    {
        $this->http       = $http;
        $this->normalizer = $normalizer;
        $this->logFn      = $logFn;
    }

    /**
     * Inspect Link headers on the base URL to discover sitemap URLs.
     *
     * @param string $websiteUrl Homepage or base URL
     * @return array<string> List of sitemap URLs found in Link headers
     */
    public function discoverSitemapUrls(string $websiteUrl): array
    {
        $this->log('INFO', "Checking HTTP Link headers for {$websiteUrl}", 'link_header');

        $response = $this->http->fetch($websiteUrl, [
            'timeout'     => 15,
            'max_retries' => 1,
            'apply_delay' => false,
        ]);

        if (empty($response['headers']['link'])) {
            return [];
        }

        $linkHeader = $response['headers']['link'];
        $sitemaps = [];

        // Parse Link headers: <url>; rel="sitemap", <url>; rel="..."
        $links = explode(',', $linkHeader);
        foreach ($links as $link) {
            if (preg_match('/<([^>]+)>\s*;\s*rel=["\']?sitemap["\']?/i', $link, $matches)) {
                $rawUrl = trim($matches[1]);
                $resolved = $this->normalizer->resolveRelative($rawUrl, $websiteUrl);
                if ($resolved) {
                    $normalized = $this->normalizer->normalize($resolved, $websiteUrl);
                    if ($normalized) {
                        $sitemaps[] = $normalized;
                    }
                }
            }
        }

        $sitemaps = array_values(array_unique($sitemaps));
        if (!empty($sitemaps)) {
            $this->log('INFO', "Found " . count($sitemaps) . " sitemap(s) in HTTP Link headers", 'link_header');
        }

        return $sitemaps;
    }

    private function log(string $level, string $message, string $eventType = 'link_header'): void
    {
        if ($this->logFn !== null) {
            ($this->logFn)($level, $message, $eventType);
        }
    }
}
