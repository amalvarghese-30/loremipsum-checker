<?php
/**
 * Sitemap Helper Module
 * Handles discovery, recursive parsing, and fallback generation of sitemaps.
 */

/**
 * Discover sitemap from a URL
 */
function discoverSitemap($projectId, $baseUrl) {
    logMessage($projectId, 'INFO', "Starting sitemap discovery for: {$baseUrl}");
    
    $parsedUrl = parse_url($baseUrl);
    $domainUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
    
    $locations = [
        $baseUrl, // Try provided URL first
        $domainUrl . '/sitemap.xml',
        $domainUrl . '/sitemap_index.xml',
        $domainUrl . '/sitemap-index.xml',
        $domainUrl . '/wp-sitemap.xml',
        $domainUrl . '/sitemap1.xml'
    ];
    
    $locations = array_unique($locations);
    
    foreach ($locations as $url) {
        logMessage($projectId, 'INFO', "Checking location: {$url}");
        $content = fetchRawContent($url);
        
        if ($content && (strpos($content, '<urlset') !== false || strpos($content, '<sitemapindex') !== false)) {
            logMessage($projectId, 'SUCCESS', "Valid sitemap found at: {$url}");
            return parseSitemapRecursive($projectId, $content, $url);
        }
    }
    
    return [];
}

/**
 * Recursive sitemap parser supporting indexes
 */
function parseSitemapRecursive($projectId, $xmlContent, $sitemapUrl, &$visitedSitemaps = []) {
    if (in_array($sitemapUrl, $visitedSitemaps)) return [];
    $visitedSitemaps[] = $sitemapUrl;
    
    $urls = [];
    try {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent);
        if (!$xml) return [];
        
        // Handle Sitemap Index
        if (isset($xml->sitemap)) {
            logMessage($projectId, 'INFO', "Parsing sitemap index: {$sitemapUrl}");
            foreach ($xml->sitemap as $s) {
                $childUrl = (string)$s->loc;
                $childContent = fetchRawContent($childUrl);
                if ($childContent) {
                    $childUrls = parseSitemapRecursive($projectId, $childContent, $childUrl, $visitedSitemaps);
                    $urls = array_merge($urls, $childUrls);
                }
            }
        } 
        // Handle Regular Sitemap
        else if (isset($xml->url)) {
            foreach ($xml->url as $u) {
                $urls[] = (string)$u->loc;
            }
        }
    } catch (Exception $e) {
        logMessage($projectId, 'ERROR', "Error parsing XML from {$sitemapUrl}: " . $e->getMessage());
    }
    
    return array_unique($urls);
}

/**
 * Fallback crawler to generate sitemap URLs
 */
function crawlToGenerateSitemap($projectId, $startUrl, $maxUrls = 500) {
    logMessage($projectId, 'INFO', "Starting fallback crawl (Max: {$maxUrls} URLs)");
    
    $parsed = parse_url($startUrl);
    $baseDomain = $parsed['host'];
    $scheme = $parsed['scheme'];
    
    $urls = [];
    $visited = [];
    $queue = [$startUrl];
    
    while (!empty($queue) && count($urls) < $maxUrls) {
        $currentUrl = array_shift($queue);
        if (isset($visited[$currentUrl])) continue;
        $visited[$currentUrl] = true;
        
        logMessage($projectId, 'INFO', "Crawling: {$currentUrl}");
        $html = fetchRawContent($currentUrl);
        
        if ($html) {
            $urls[] = $currentUrl;
            
            // Robust link extraction using DOMDocument
            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $anchors = $dom->getElementsByTagName('a');
            
            foreach ($anchors as $anchor) {
                $link = $anchor->getAttribute('href');
                $absoluteLink = normalizeUrl($link, $currentUrl, $scheme, $baseDomain);
                
                if ($absoluteLink && !isset($visited[$absoluteLink]) && parse_url($absoluteLink, PHP_URL_HOST) === $baseDomain) {
                    $queue[] = $absoluteLink;
                }
            }
        }
        
        if (count($urls) % 10 === 0) {
            logMessage($projectId, 'INFO', "Crawl progress: " . count($urls) . " URLs found...");
        }
    }
    
    return array_unique($urls);
}

/**
 * Save URLs as a standard XML sitemap
 */
function saveAsStandardSitemap($urls, $filePath) {
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>');
    foreach ($urls as $url) {
        $urlNode = $xml->addChild('url');
        $urlNode->addChild('loc', htmlspecialchars($url));
        $urlNode->addChild('lastmod', date('c'));
        $urlNode->addChild('changefreq', 'weekly');
        $urlNode->addChild('priority', '0.5');
    }
    return $xml->asXML($filePath);
}

/**
 * Simple normalize helper
 */
function normalizeUrl($relativeUrl, $baseUrl, $scheme = null, $baseDomain = null) {
    if (empty($relativeUrl) || $relativeUrl === '#' || strpos($relativeUrl, 'javascript:') === 0 || strpos($relativeUrl, 'mailto:') === 0) {
        return null;
    }
    
    // Resolve scheme and domain if not provided
    if (!$scheme || !$baseDomain) {
        $parsed = parse_url($baseUrl);
        $scheme = $parsed['scheme'];
        $baseDomain = $parsed['host'];
    }

    if (strpos($relativeUrl, '//') === 0) {
        return $scheme . ':' . $relativeUrl;
    }
    
    if (strpos($relativeUrl, 'http') === 0) {
        return explode('#', $relativeUrl)[0]; // Remove fragment
    }
    
    if (strpos($relativeUrl, '/') === 0) {
        return $scheme . '://' . $baseDomain . $relativeUrl;
    }
    
    $path = dirname(parse_url($baseUrl, PHP_URL_PATH) ?? '/');
    return $scheme . '://' . $baseDomain . rtrim($path, '/') . '/' . $relativeUrl;
}

/**
 * Basic fetcher for sitemaps/crawl
 */
function fetchRawContent($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    $content = curl_exec($ch);
    curl_close($ch);
    return $content;
}