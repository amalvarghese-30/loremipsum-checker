<?php
/**
 * WordPress REST API Discovery
 *
 * Checks if the target site runs WordPress and exposes the REST API:
 * - /wp-json/wp/v2/pages
 * - /wp-json/wp/v2/posts
 * Reads response headers for total pages (X-WP-TotalPages) and paginates.
 */

namespace LoremChecker\Discovery;

use LoremChecker\HttpClient;

class WordPressDiscovery
{
    private HttpClient    $http;
    private UrlNormalizer $normalizer;
    private ?callable     $logFn;

    // Cap maximum API pages to prevent endless pagination on huge blogs
    private const MAX_PAGES_PER_TYPE = 5;
    private const PER_PAGE = 100;

    public function __construct(HttpClient $http, UrlNormalizer $normalizer, ?callable $logFn = null)
    {
        $this->http       = $http;
        $this->normalizer = $normalizer;
        $this->logFn      = $logFn;
    }

    /**
     * Discover URLs via WordPress REST API.
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

        // 1. Probe for WP REST API root
        $rootCheck = $this->http->fetch($origin . '/wp-json/wp/v2/', [
            'timeout'     => 10,
            'max_retries' => 1,
            'apply_delay' => false,
        ]);

        if ($rootCheck['error_type'] !== null || $rootCheck['http_status'] !== 200 || empty($rootCheck['content'])) {
            // Not a WordPress site or REST API is disabled
            return [];
        }

        $rootJson = @json_decode($rootCheck['content'], true);
        if (!is_array($rootJson) || !isset($rootJson['routes'])) {
            return [];
        }

        $this->log('INFO', "WordPress REST API detected on {$origin}", 'wordpress_api');

        $records = [];
        $endpoints = ['pages', 'posts'];

        foreach ($endpoints as $type) {
            $typeRecords = $this->fetchWpType($origin, $websiteUrl, $type);
            $records = array_merge($records, $typeRecords);
        }

        return $records;
    }

    private function fetchWpType(string $origin, string $websiteUrl, string $type): array
    {
        $records = [];
        $page = 1;

        while ($page <= self::MAX_PAGES_PER_TYPE) {
            $apiUrl = sprintf(
                '%s/wp-json/wp/v2/%s?per_page=%d&page=%d&_fields=link,modified',
                $origin,
                $type,
                self::PER_PAGE,
                $page
            );

            $response = $this->http->fetch($apiUrl, [
                'timeout'     => 15,
                'max_retries' => 1,
                'apply_delay' => false,
            ]);

            if ($response['error_type'] !== null || $response['http_status'] !== 200 || empty($response['content'])) {
                break;
            }

            $items = @json_decode($response['content'], true);
            if (!is_array($items) || empty($items)) {
                break;
            }

            foreach ($items as $item) {
                if (empty($item['link'])) {
                    continue;
                }
                $link = trim($item['link']);
                $resolved = $this->normalizer->resolveRelative($link, $websiteUrl);
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
                    'source'         => 'wordpress_api',
                    'depth'          => 1,
                    'metadata'       => [
                        'wp_type'  => $type,
                        'modified' => $item['modified'] ?? null,
                    ],
                ];
            }

            // Check total pages header
            $totalPages = (int)($response['headers']['x-wp-totalpages'] ?? 1);
            if ($page >= $totalPages) {
                break;
            }

            $page++;
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

    private function log(string $level, string $message, string $eventType = 'wordpress_api'): void
    {
        if ($this->logFn !== null) {
            ($this->logFn)($level, $message, $eventType);
        }
    }
}
