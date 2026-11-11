<?php
/**
 * Discovery Manager
 *
 * Orchestrates all enabled URL discovery methods.
 * ALL enabled methods run to completion and contribute to the combined URL list.
 * Finding one sitemap does NOT stop other discovery methods.
 */

namespace LoremChecker\Discovery;

use LoremChecker\HttpClient;

class DiscoveryManager
{
    private HttpClient    $http;
    private UrlNormalizer $normalizer;
    private array         $config;
    /** @var callable|null */
    private $logFn;

    // Discovered URL record format:
    // ['url' => string, 'normalized_url' => string, 'url_hash' => string,
    //  'source' => string, 'depth' => int, 'metadata' => array]

    public function __construct(HttpClient $http, UrlNormalizer $normalizer, array $config = [], ?callable $logFn = null)
    {
        $this->http       = $http;
        $this->normalizer = $normalizer;
        $this->config     = $config;
        $this->logFn      = $logFn;
    }

    /**
     * Run all enabled discovery methods against a website URL.
     *
     * @param string $websiteUrl  The website's homepage URL
     * @param array  $settings    Per-scan settings (may override config)
     * @return array              Merged, deduplicated URL records
     */
    public function discover(string $websiteUrl, array $settings = []): array
    {
        $cfg      = array_merge($this->config, $settings);
        $discCfg  = $cfg['discovery'] ?? [];
        $allUrls  = [];  // normalized_url_hash => record

        $this->log('INFO', "Starting URL discovery for: {$websiteUrl}", 'discovery');

        // 1. robots.txt — discover additional sitemap declarations
        $robotsSitemaps = [];
        if ($discCfg['robots_sitemap'] ?? true) {
            $this->log('INFO', 'Running robots.txt sitemap discovery', 'discovery');
            $robotsDisc    = new RobotsDiscovery($this->http, $this->normalizer, $this->logFn);
            $robotsSitemaps = $robotsDisc->discoverSitemapUrls($websiteUrl);
            if ($robotsSitemaps) {
                $this->log('INFO', count($robotsSitemaps) . ' sitemap(s) found via robots.txt', 'discovery');
            }
        }

        // 2. HTTP Link header — discover sitemaps via Link: <url>; rel="sitemap"
        $linkHeaderSitemaps = [];
        if ($discCfg['link_header'] ?? true) {
            $linkDisc          = new LinkHeaderDiscovery($this->http, $this->normalizer, $this->logFn);
            $linkHeaderSitemaps = $linkDisc->discoverSitemapUrls($websiteUrl);
        }

        // 3. XML Sitemap discovery (common paths + robots sitemaps + link header sitemaps)
        if ($discCfg['sitemap'] ?? true) {
            $this->log('INFO', 'Running XML sitemap discovery', 'discovery');
            $sitemapDisc = new SitemapDiscovery($this->http, $this->normalizer, $cfg, $this->logFn);

            $extraSitemaps = array_unique(array_merge($robotsSitemaps, $linkHeaderSitemaps));
            $sitemapUrls   = $sitemapDisc->discover($websiteUrl, $extraSitemaps);

            $this->log('INFO', count($sitemapUrls) . ' URL(s) from XML sitemaps', 'discovery');
            $this->mergeUrls($allUrls, $sitemapUrls, $websiteUrl);
        }

        // 4. HTML metadata / link tag sitemap hints
        // (also discovers RSS/Atom autodiscovery links)
        $feedUrlsFromMeta = [];
        if ($discCfg['rss_atom'] ?? true) {
            $this->log('INFO', 'Running RSS/Atom feed discovery', 'discovery');
            $feedDisc      = new FeedDiscovery($this->http, $this->normalizer, $cfg, $this->logFn);
            $feedUrls      = $feedDisc->discover($websiteUrl);
            $feedUrlsFromMeta = $feedUrls;

            $this->log('INFO', count($feedUrls) . ' URL(s) from feeds', 'discovery');
            $this->mergeUrls($allUrls, $feedUrls, $websiteUrl);
        }

        // 5. HTML sitemap pages
        if ($discCfg['html_sitemap'] ?? true) {
            $this->log('INFO', 'Running HTML sitemap discovery', 'discovery');
            $htmlSitemapDisc = new HtmlSitemapDiscovery($this->http, $this->normalizer, $cfg, $this->logFn);
            $htmlSitemapUrls = $htmlSitemapDisc->discover($websiteUrl);

            $this->log('INFO', count($htmlSitemapUrls) . ' URL(s) from HTML sitemaps', 'discovery');
            $this->mergeUrls($allUrls, $htmlSitemapUrls, $websiteUrl);
        }

        // 6. WordPress REST API
        if ($discCfg['wordpress_api'] ?? true) {
            $this->log('INFO', 'Running WordPress API discovery', 'discovery');
            $wpDisc  = new WordPressDiscovery($this->http, $this->normalizer, $this->logFn);
            $wpUrls  = $wpDisc->discover($websiteUrl);

            if (!empty($wpUrls)) {
                $this->log('INFO', count($wpUrls) . ' URL(s) from WordPress API', 'discovery');
                $this->mergeUrls($allUrls, $wpUrls, $websiteUrl);
            }
        }

        // 7. Internal link crawling
        if ($discCfg['internal_crawler'] ?? true) {
            $maxUrls  = (int)($cfg['crawler']['default_max_urls'] ?? 500);
            $maxDepth = (int)($cfg['crawler']['default_max_depth'] ?? 3);

            $this->log('INFO', "Running internal crawler (max={$maxUrls}, depth={$maxDepth})", 'discovery');
            $crawler    = new InternalCrawler($this->http, $this->normalizer, $cfg, $this->logFn);
            $crawlUrls  = $crawler->crawl($websiteUrl, $maxUrls, $maxDepth);

            $this->log('INFO', count($crawlUrls) . ' URL(s) from internal crawler', 'discovery');
            $this->mergeUrls($allUrls, $crawlUrls, $websiteUrl);
        }

        $total = count($allUrls);
        $this->log('SUCCESS', "Discovery complete. Total unique URLs: {$total}", 'discovery');

        return array_values($allUrls);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Merge new URL records into the master list, deduplicating by URL hash.
     * If a URL was already found by another method, its first discovery source wins
     * but additional sources are tracked in metadata.
     */
    private function mergeUrls(array &$master, array $newUrls, string $websiteUrl): void
    {
        foreach ($newUrls as $record) {
            if (empty($record['url'])) {
                continue;
            }

            $url        = $record['url'];
            $normalized = $record['normalized_url'] ?? $this->normalizer->normalize($url, $websiteUrl);

            if ($normalized === null) {
                continue;
            }

            $hash = $this->normalizer->getUrlHash($normalized);

            if (!isset($master[$hash])) {
                $master[$hash] = [
                    'url'            => $url,
                    'normalized_url' => $normalized,
                    'url_hash'       => $hash,
                    'source'         => $record['source'] ?? 'unknown',
                    'depth'          => (int)($record['depth'] ?? 0),
                    'metadata'       => $record['metadata'] ?? [],
                    'extra_sources'  => [],
                ];
            } else {
                // Track additional discovery sources without replacing the primary
                $master[$hash]['extra_sources'][] = $record['source'] ?? 'unknown';
            }
        }
    }

    private function log(string $level, string $message, string $eventType = 'general'): void
    {
        if ($this->logFn !== null) {
            ($this->logFn)($level, $message, $eventType);
        }
    }
}
