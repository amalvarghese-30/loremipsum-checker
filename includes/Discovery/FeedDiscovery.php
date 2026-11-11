<?php
/**
 * RSS and Atom Feed Discovery
 *
 * Discovers content URLs by parsing RSS and Atom feeds:
 * 1. Checks HTML autodiscovery <link rel="alternate" ...> tags on homepage
 * 2. Checks common feed paths (/feed, /rss, /atom.xml, etc.)
 * 3. Parses RSS <item><link> and Atom <entry><link href="..."> elements
 */

namespace LoremChecker\Discovery;

use DOMDocument;
use DOMXPath;
use LoremChecker\HttpClient;
use SimpleXMLElement;

class FeedDiscovery
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
     * Discover page URLs from RSS and Atom feeds.
     *
     * @param string $websiteUrl Homepage URL
     * @return array Standardized URL records
     */
    public function discover(string $websiteUrl): array
    {
        $feedEndpoints = [];

        // 1. Fetch homepage to discover <link rel="alternate"> feed hints
        $homeResponse = $this->http->fetch($websiteUrl, [
            'timeout'     => 15,
            'max_retries' => 1,
            'apply_delay' => false,
        ]);

        if ($homeResponse['error_type'] === null && !empty($homeResponse['content'])) {
            $feedEndpoints = array_merge($feedEndpoints, $this->extractFeedLinksFromHtml($homeResponse['content'], $websiteUrl));
        }

        // 2. Add common feed endpoints
        $origin = $this->extractOrigin($websiteUrl);
        if ($origin) {
            $commonPaths = $this->config['discovery']['common_feed_paths'] ?? [
                '/feed', '/feed/', '/feed.xml', '/rss', '/rss.xml', '/rss/', '/atom.xml'
            ];
            foreach ($commonPaths as $path) {
                $feedEndpoints[] = $origin . $path;
            }
        }

        $feedEndpoints = array_values(array_unique($feedEndpoints));
        $records = [];
        $visitedFeeds = [];

        foreach ($feedEndpoints as $feedUrl) {
            if (isset($visitedFeeds[$feedUrl])) {
                continue;
            }
            $visitedFeeds[$feedUrl] = true;

            $feedRecords = $this->parseFeed($feedUrl, $websiteUrl);
            $records = array_merge($records, $feedRecords);
        }

        return $records;
    }

    private function extractFeedLinksFromHtml(string $html, string $baseUrl): array
    {
        $feeds = [];
        if (empty($html)) {
            return $feeds;
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//link[@rel="alternate" and (@type="application/rss+xml" or @type="application/atom+xml")]');

        foreach ($nodes as $node) {
            $href = trim($node->getAttribute('href'));
            if ($href !== '') {
                $resolved = $this->normalizer->resolveRelative($href, $baseUrl);
                if ($resolved) {
                    $feeds[] = $resolved;
                }
            }
        }

        return $feeds;
    }

    private function parseFeed(string $feedUrl, string $websiteUrl): array
    {
        $response = $this->http->fetch($feedUrl, [
            'timeout'     => 15,
            'max_retries' => 1,
            'apply_delay' => false,
        ]);

        if ($response['error_type'] !== null || $response['http_status'] !== 200 || empty($response['content'])) {
            return [];
        }

        $content = trim($response['content']);
        if (!str_starts_with($content, '<?xml') && !str_contains($content, '<rss') && !str_contains($content, '<feed')) {
            return [];
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($xml === false) {
            return [];
        }

        $records = [];

        // Check RSS: <rss><channel><item><link>
        if (isset($xml->channel->item)) {
            $this->log('INFO', "Parsing RSS feed: {$feedUrl}", 'feed');
            foreach ($xml->channel->item as $item) {
                $link = trim((string)($item->link ?? ''));
                if (!empty($link)) {
                    $record = $this->buildRecord($link, $websiteUrl, $feedUrl, 'rss_feed');
                    if ($record) {
                        $records[] = $record;
                    }
                }
            }
        }

        // Check Atom: <feed><entry><link href="...">
        if ($xml->getName() === 'feed' || isset($xml->entry)) {
            $this->log('INFO', "Parsing Atom feed: {$feedUrl}", 'feed');
            foreach ($xml->entry as $entry) {
                $link = '';
                if (isset($entry->link)) {
                    // Could have attributes
                    $attrs = $entry->link->attributes();
                    if (isset($attrs['href'])) {
                        $link = trim((string)$attrs['href']);
                    } else {
                        $link = trim((string)$entry->link);
                    }
                }
                if (!empty($link)) {
                    $record = $this->buildRecord($link, $websiteUrl, $feedUrl, 'atom_feed');
                    if ($record) {
                        $records[] = $record;
                    }
                }
            }
        }

        return $records;
    }

    private function buildRecord(string $rawUrl, string $websiteUrl, string $feedUrl, string $source): ?array
    {
        $resolved = $this->normalizer->resolveRelative($rawUrl, $websiteUrl);
        if (!$resolved) {
            return null;
        }

        // Keep within scope
        if (!$this->normalizer->isSameScope($resolved, $websiteUrl, 'host')) {
            return null;
        }

        $normalized = $this->normalizer->normalize($resolved, $websiteUrl);
        if (!$normalized) {
            return null;
        }

        return [
            'url'            => $resolved,
            'normalized_url' => $normalized,
            'url_hash'       => $this->normalizer->getUrlHash($normalized),
            'source'         => $source,
            'depth'          => 1,
            'metadata'       => ['feed_url' => $feedUrl],
        ];
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

    private function log(string $level, string $message, string $eventType = 'feed'): void
    {
        if ($this->logFn !== null) {
            ($this->logFn)($level, $message, $eventType);
        }
    }
}
