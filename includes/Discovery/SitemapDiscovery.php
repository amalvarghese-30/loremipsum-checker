<?php
/**
 * Sitemap Discovery
 *
 * Discovers URLs from XML sitemaps:
 * - Checks common sitemap locations relative to the website origin
 * - Parses sitemap indexes recursively
 * - Handles namespaces, gzip, large files
 * - Does NOT stop if robots.txt Disallow — that's irrelevant here
 */

namespace LoremChecker\Discovery;

use LoremChecker\HttpClient;

class SitemapDiscovery
{
    private HttpClient    $http;
    private UrlNormalizer $normalizer;
    private array         $config;
    /** @var callable|null */
    private $logFn;

    // Max sitemaps to follow per scan to prevent runaway recursion
    private const MAX_SITEMAPS = 100;

    public function __construct(HttpClient $http, UrlNormalizer $normalizer, array $config = [], ?callable $logFn = null)
    {
        $this->http       = $http;
        $this->normalizer = $normalizer;
        $this->config     = $config;
        $this->logFn      = $logFn;
    }

    /**
     * Discover URLs from XML sitemaps.
     *
     * @param string $websiteUrl    Website homepage URL
     * @param array  $extraSitemaps Additional sitemap URLs (from robots.txt, Link headers, etc.)
     * @return array URL records
     */
    public function discover(string $websiteUrl, array $extraSitemaps = []): array
    {
        $origin = $this->extractOrigin($websiteUrl);
        if (!$origin) {
            return [];
        }

        // Build the full list of sitemap locations to check
        $commonPaths = $this->config['discovery']['common_sitemap_paths'] ?? [
            '/sitemap.xml', '/sitemap_index.xml', '/sitemap-index.xml',
            '/wp-sitemap.xml', '/sitemap1.xml',
        ];

        $locations = [];
        foreach ($commonPaths as $path) {
            $locations[] = $origin . $path;
        }
        // Add extra sitemaps discovered via robots.txt or Link headers
        foreach ($extraSitemaps as $url) {
            $locations[] = $url;
        }
        $locations = array_unique($locations);

        $visitedSitemaps = [];
        $allUrls         = [];

        foreach ($locations as $sitemapUrl) {
            if (count($visitedSitemaps) >= self::MAX_SITEMAPS) {
                $this->log('WARNING', 'Reached max sitemap limit (' . self::MAX_SITEMAPS . ')', 'sitemap');
                break;
            }

            $urls = $this->fetchAndParseSitemap($sitemapUrl, $visitedSitemaps, 0);
            $allUrls = array_merge($allUrls, $urls);
        }

        return $allUrls;
    }

    // ── Private implementation ────────────────────────────────────────────────

    private function fetchAndParseSitemap(string $sitemapUrl, array &$visited, int $depth): array
    {
        if (in_array($sitemapUrl, $visited, true)) {
            return [];
        }
        $visited[] = $sitemapUrl;

        if (count($visited) > self::MAX_SITEMAPS) {
            return [];
        }

        $response = $this->http->fetch($sitemapUrl, [
            'timeout'     => $this->config['crawler']['sitemap_timeout'] ?? 30,
            'max_retries' => 2,
            'apply_delay' => false,
        ]);

        if ($response['error_type'] !== null || $response['http_status'] >= 400) {
            return [];
        }

        $content = $response['content'];
        if (empty($content)) {
            return [];
        }

        // Handle gzip-compressed sitemaps
        if (str_ends_with(strtolower(parse_url($sitemapUrl, PHP_URL_PATH) ?? ''), '.gz')) {
            if (function_exists('gzdecode')) {
                $decoded = @gzdecode($content);
                if ($decoded !== false) {
                    $content = $decoded;
                }
            }
        }

        // Validate it's XML-like before parsing
        $trimmed = ltrim($content);
        if (!str_starts_with($trimmed, '<?xml') && !str_starts_with($trimmed, '<urlset') && !str_starts_with($trimmed, '<sitemapindex')) {
            return [];
        }

        return $this->parseSitemapXml($content, $sitemapUrl, $visited, $depth);
    }

    private function parseSitemapXml(string $xmlContent, string $sitemapUrl, array &$visited, int $depth): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($xml === false) {
            $this->log('WARNING', "Failed to parse XML from: {$sitemapUrl}", 'sitemap');
            return [];
        }

        // Register namespaces to handle namespaced sitemaps
        $namespaces = $xml->getNamespaces(true);

        // Detect sitemap index vs regular sitemap
        // Check both namespaced and non-namespaced
        $isSitemapIndex = $xml->getName() === 'sitemapindex'
            || isset($xml->sitemap)
            || $this->hasNodeRecursive($xml, 'sitemap');

        $isUrlSet = $xml->getName() === 'urlset'
            || isset($xml->url)
            || $this->hasNodeRecursive($xml, 'url');

        if ($isSitemapIndex) {
            $this->log('INFO', "Parsing sitemap index: {$sitemapUrl}", 'sitemap');
            return $this->parseSitemapIndex($xml, $visited, $depth);
        }

        if ($isUrlSet) {
            $this->log('INFO', "Parsing sitemap: {$sitemapUrl}", 'sitemap');
            return $this->parseUrlSet($xml, $sitemapUrl);
        }

        // Try registered namespaces
        foreach ($namespaces as $prefix => $ns) {
            $xmlNs = $xml->children($ns);
            if (isset($xmlNs->sitemap)) {
                return $this->parseSitemapIndex($xmlNs, $visited, $depth);
            }
            if (isset($xmlNs->url)) {
                return $this->parseUrlSet($xmlNs, $sitemapUrl);
            }
        }

        return [];
    }

    private function parseSitemapIndex(\SimpleXMLElement $xml, array &$visited, int $depth): array
    {
        $urls = [];
        foreach ($xml->sitemap as $sitemap) {
            $childUrl = trim((string)($sitemap->loc ?? ''));
            if (!empty($childUrl)) {
                $childUrls = $this->fetchAndParseSitemap($childUrl, $visited, $depth + 1);
                $urls = array_merge($urls, $childUrls);
            }
        }
        return $urls;
    }

    private function parseUrlSet(\SimpleXMLElement $xml, string $sitemapUrl): array
    {
        $records = [];
        foreach ($xml->url as $urlNode) {
            $loc = trim((string)($urlNode->loc ?? ''));
            if (empty($loc)) {
                continue;
            }

            $normalized = $this->normalizer->normalize($loc);
            if ($normalized === null) {
                continue;
            }

            $records[] = [
                'url'            => $loc,
                'normalized_url' => $normalized,
                'url_hash'       => $this->normalizer->getUrlHash($normalized),
                'source'         => 'sitemap_xml',
                'depth'          => 0,
                'metadata'       => [
                    'sitemap_url' => $sitemapUrl,
                    'lastmod'     => trim((string)($urlNode->lastmod ?? '')),
                    'changefreq'  => trim((string)($urlNode->changefreq ?? '')),
                    'priority'    => trim((string)($urlNode->priority ?? '')),
                ],
            ];
        }
        return $records;
    }

    private function hasNodeRecursive(\SimpleXMLElement $xml, string $nodeName): bool
    {
        return isset($xml->$nodeName);
    }

    private function extractOrigin(string $url): ?string
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return null;
        }
        $origin  = ($parts['scheme'] ?? 'https') . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $scheme = strtolower($parts['scheme'] ?? 'https');
            $port   = (int)$parts['port'];
            if (!($scheme === 'http' && $port === 80) && !($scheme === 'https' && $port === 443)) {
                $origin .= ':' . $port;
            }
        }
        return $origin;
    }

    private function log(string $level, string $message, string $eventType = 'sitemap'): void
    {
        if ($this->logFn !== null) {
            ($this->logFn)($level, $message, $eventType);
        }
    }
}
