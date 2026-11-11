<?php
/**
 * Robots.txt Sitemap Discovery
 *
 * Fetches robots.txt and extracts all "Sitemap:" directives.
 * IMPORTANT: This module is used SOLELY to discover sitemaps.
 * It does NOT skip pages based on Disallow directives, fulfilling
 * the core requirement that all scoped URLs are audited.
 */

namespace LoremChecker\Discovery;

use LoremChecker\HttpClient;

class RobotsDiscovery
{
    private HttpClient    $http;
    private UrlNormalizer $normalizer;
    /** @var callable|null */
    private $logFn;

    public function __construct(HttpClient $http, UrlNormalizer $normalizer, ?callable $logFn = null)
    {
        $this->http       = $http;
        $this->normalizer = $normalizer;
        $this->logFn      = $logFn;
    }

    /**
     * Fetch robots.txt and return all discovered sitemap URLs.
     *
     * @param string $websiteUrl Homepage or base URL
     * @return array<string> List of absolute sitemap URLs
     */
    public function discoverSitemapUrls(string $websiteUrl): array
    {
        $origin = $this->extractOrigin($websiteUrl);
        if (!$origin) {
            return [];
        }

        $robotsUrl = $origin . '/robots.txt';
        $this->log('INFO', "Checking robots.txt at {$robotsUrl}", 'robots_txt');

        $response = $this->http->fetch($robotsUrl, [
            'timeout'     => 15,
            'max_retries' => 1,
            'apply_delay' => false,
        ]);

        if ($response['error_type'] !== null || $response['http_status'] !== 200) {
            $this->log('INFO', "No accessible robots.txt found (HTTP {$response['http_status']})", 'robots_txt');
            return [];
        }

        $content = $response['content'];
        if (empty($content)) {
            return [];
        }

        $sitemaps = [];
        $lines = preg_split('/\r\n|\r|\n/', $content);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            // Case-insensitive match for "Sitemap:" directive
            if (preg_match('/^sitemap\s*:\s*(.+)$/i', $line, $matches)) {
                $rawUrl = trim($matches[1]);
                // Resolve relative URLs if someone provided /sitemap.xml
                $resolved = $this->normalizer->resolveRelative($rawUrl, $origin);
                if ($resolved && filter_var($resolved, FILTER_VALIDATE_URL)) {
                    $normalized = $this->normalizer->normalize($resolved, $origin);
                    if ($normalized) {
                        $sitemaps[] = $normalized;
                    }
                }
            }
        }

        $sitemaps = array_values(array_unique($sitemaps));
        if (!empty($sitemaps)) {
            $this->log('INFO', "Found " . count($sitemaps) . " sitemap(s) declared in robots.txt", 'robots_txt');
        }

        return $sitemaps;
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

    private function log(string $level, string $message, string $eventType = 'robots_txt'): void
    {
        if ($this->logFn !== null) {
            ($this->logFn)($level, $message, $eventType);
        }
    }
}
