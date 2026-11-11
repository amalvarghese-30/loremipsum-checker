<?php
/**
 * HTML Sitemap Discovery
 *
 * Scans human-facing HTML sitemap pages (e.g. /sitemap/, /sitemap.html, /site-map/)
 * and extracts all in-scope navigational hyperlinks.
 */

namespace LoremChecker\Discovery;

use DOMDocument;
use LoremChecker\HttpClient;

class HtmlSitemapDiscovery
{
    private HttpClient    $http;
    private UrlNormalizer $normalizer;
    private array         $config;
    /** @var callable|null */
    private $logFn;

    public function __construct(HttpClient $http, UrlNormalizer $normalizer, array $config = [], ?callable $logFn = null)
    {
        $this->http       = $http;
        $this->normalizer = $normalizer;
        $this->config     = $config;
        $this->logFn      = $logFn;
    }

    /**
     * Check common HTML sitemap locations and extract links.
     *
     * @param string $websiteUrl Homepage URL
     * @return array Standardized URL records
     */
    public function discover(string $websiteUrl): array
    {
        $origin = $this->extractOrigin($websiteUrl);
        if (!$origin) {
            return [];
        }

        $commonPaths = $this->config['discovery']['common_html_sitemap_paths'] ?? [
            '/sitemap', '/sitemap/', '/sitemap.html', '/site-map', '/site-map/', '/site-map.html'
        ];

        $records = [];
        $visited = [];

        foreach ($commonPaths as $path) {
            $sitemapPageUrl = $origin . $path;
            if (isset($visited[$sitemapPageUrl])) {
                continue;
            }
            $visited[$sitemapPageUrl] = true;

            $response = $this->http->fetch($sitemapPageUrl, [
                'timeout'     => 15,
                'max_retries' => 1,
                'apply_delay' => false,
            ]);

            if ($response['error_type'] !== null || $response['http_status'] !== 200 || empty($response['content'])) {
                continue;
            }

            // Verify content looks like HTML
            if ($response['content_type'] !== 'html' && !str_contains($response['content'], '<html')) {
                continue;
            }

            $this->log('INFO', "Parsing HTML sitemap at {$sitemapPageUrl}", 'html_sitemap');
            $extracted = $this->extractLinksFromHtml($response['content'], $sitemapPageUrl, $websiteUrl);
            $records = array_merge($records, $extracted);
        }

        return $records;
    }

    private function extractLinksFromHtml(string $html, string $pageUrl, string $websiteUrl): array
    {
        $records = [];
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $anchors = $dom->getElementsByTagName('a');
        foreach ($anchors as $anchor) {
            $href = trim($anchor->getAttribute('href'));
            if ($href === '' || $href[0] === '#' || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:')) {
                continue;
            }

            $resolved = $this->normalizer->resolveRelative($href, $pageUrl);
            if (!$resolved || !$this->normalizer->isSameScope($resolved, $websiteUrl, 'host')) {
                continue;
            }

            $normalized = $this->normalizer->normalize($resolved, $websiteUrl);
            if (!$normalized) {
                continue;
            }

            $records[] = [
                'url'            => $resolved,
                'normalized_url' => $normalized,
                'url_hash'       => $this->normalizer->getUrlHash($normalized),
                'source'         => 'html_sitemap',
                'depth'          => 1,
                'metadata'       => ['html_sitemap_url' => $pageUrl],
            ];
        }

        return $records;
    }

    private function extractOrigin(string $url): ?string
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return null;
        }
        $origin = ($parts['scheme'] ?? 'https') . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $scheme = strtolower($parts['scheme'] ?? 'https');
            $port   = (int)$parts['port'];
            if (!($scheme === 'http' && $port === 80) && !($scheme === 'https' && $port === 443)) {
                $origin .= ':' . $port;
            }
        }
        return $origin;
    }

    private function log(string $level, string $message, string $eventType = 'html_sitemap'): void
    {
        if ($this->logFn !== null) {
            ($this->logFn)($level, $message, $eventType);
        }
    }
}
